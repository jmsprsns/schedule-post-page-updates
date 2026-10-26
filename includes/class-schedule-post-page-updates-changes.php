<?php
/**
 * Change sets: what a scheduled change does to a post, applying it with a
 * backup, and undoing it.
 *
 * A change set is array( 'fields' => [...], 'terms' => [...], 'meta' => [...] ):
 * post fields by column name, term IDs by taxonomy, and meta values by key.
 * Meta values are kept as stored in the database (serialized strings), so
 * they go back exactly as they were. A meta key set to null is deleted.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Change set helpers.
 */
final class Schedule_Post_Page_Updates_Changes {

	/**
	 * Post fields a scheduled update can change. The slug, author, status and
	 * dates are never taken from an update, so the URL stays the same.
	 */
	const FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_parent', 'menu_order', 'comment_status', 'ping_status', 'post_password' );

	/**
	 * Meta keys that are never copied into an update or applied from one:
	 * edit locks, slug history, trash bookkeeping, pingback queues and caches
	 * that page builders rebuild for each post.
	 */
	const SKIP_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
		'_encloseme',
		'_pingme',
		'_elementor_css',
		'_elementor_element_cache',
		'_elementor_page_assets',
	);

	/**
	 * Meta that plugins recalculate on every save (SEO scores, reading
	 * times). Applied like any other change, but not named in summaries.
	 */
	const COMPUTED_META = array( 'rank_math_seo_score', 'rank_math_contentai_score', '_yoast_wpseo_content_score', '_yoast_wpseo_estimated-reading-time-minutes', '_yoast_wpseo_wordproof_timestamp', '_reading_time' );

	/**
	 * Meta key prefixes that are skipped the same way: this plugin's own
	 * bookkeeping and oEmbed caches.
	 */
	const SKIP_META_PREFIXES = array( '_sppu_', '_oembed_' );

	/**
	 * Whether a meta key is left out of copies and changes.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function skip_meta( $key ) {
		$key  = (string) $key;
		$skip = in_array( $key, self::SKIP_META, true );

		foreach ( self::SKIP_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$skip = true;
				break;
			}
		}

		/**
		 * Filters whether a meta key is left out when a scheduled update is
		 * copied from its post and applied back to it.
		 *
		 * @param bool   $skip Whether to skip it.
		 * @param string $key  Meta key.
		 */
		return (bool) apply_filters( 'schedule_post_page_updates_skip_meta', $skip, $key );
	}

	/**
	 * Every meta value of a post as stored, grouped by key, skipping the keys
	 * above. Read straight from the database so it's never a stale cache.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string[]>
	 */
	public static function raw_meta( $post_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", absint( $post_id ) ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$meta = array();

		foreach ( (array) $rows as $row ) {
			if ( self::skip_meta( $row[0] ) ) {
				continue;
			}
			$meta[ (string) $row[0] ][] = (string) $row[1];
		}

		return $meta;
	}

	/**
	 * The stored values of one meta key.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string[]|null Null when the post has no such key.
	 */
	public static function meta_values( $post_id, $key ) {
		global $wpdb;

		$values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC", absint( $post_id ), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $values ? array_map( 'strval', $values ) : null;
	}

	/**
	 * Replace every value of a meta key. Goes through the meta API, so caches
	 * are cleared and other plugins' meta hooks run.
	 *
	 * @param int           $post_id Post ID.
	 * @param string        $key     Meta key.
	 * @param string[]|null $values  Values as stored, or null to delete the key.
	 * @return void
	 */
	public static function write_meta( $post_id, $key, $values ) {
		// The meta API unslashes keys and values, so slash them first.
		delete_post_meta( $post_id, wp_slash( $key ) );

		if ( null === $values ) {
			return;
		}

		foreach ( (array) $values as $raw ) {
			add_post_meta( $post_id, wp_slash( $key ), wp_slash( maybe_unserialize( $raw ) ) );
		}
	}

	/**
	 * Term IDs of a post in one taxonomy, sorted.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	public static function term_ids( $post_id, $taxonomy ) {
		$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $ids ) ) {
			return array();
		}

		$ids = array_map( 'intval', $ids );
		sort( $ids );

		return $ids;
	}

	/**
	 * Term IDs in every taxonomy of the post's type.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,int[]>
	 */
	public static function terms( WP_Post $post ) {
		$terms = array();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms[ $taxonomy ] = self::term_ids( $post->ID, $taxonomy );
		}

		return $terms;
	}

	/**
	 * Everything a scheduled update can change, as it is now.
	 *
	 * @param WP_Post $post Post.
	 * @return array{fields: array<string,string>, terms: array<string,int[]>, meta: array<string,string[]>}
	 */
	public static function state( WP_Post $post ) {
		$fields = array();

		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = (string) $post->$field;
		}

		return array(
			'fields' => $fields,
			'terms'  => self::terms( $post ),
			'meta'   => self::raw_meta( $post->ID ),
		);
	}

	/**
	 * Hash of one field value.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function hash_field( $value ) {
		return md5( (string) $value );
	}

	/**
	 * Hash of a list of term IDs.
	 *
	 * @param int[] $ids Term IDs.
	 * @return string
	 */
	private static function hash_terms( array $ids ) {
		return md5( implode( ',', $ids ) );
	}

	/**
	 * Hash of a meta key's values.
	 *
	 * @param string[]|null $values Values as stored.
	 * @return string
	 */
	private static function hash_meta( $values ) {
		return null === $values ? 'none' : md5( serialize( array_values( $values ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hashing a list of strings.
	}

	/**
	 * A state reduced to hashes. Stored with each update when it's created,
	 * so applying it later only touches what was changed in it.
	 *
	 * @param array $state From {@see state()}.
	 * @return array{fields: array<string,string>, terms: array<string,string>, meta: array<string,string>}
	 */
	public static function fingerprint( array $state ) {
		$out = array(
			'fields' => array(),
			'terms'  => array(),
			'meta'   => array(),
		);

		foreach ( $state['fields'] as $field => $value ) {
			$out['fields'][ $field ] = self::hash_field( $value );
		}
		foreach ( $state['terms'] as $taxonomy => $ids ) {
			$out['terms'][ $taxonomy ] = self::hash_terms( $ids );
		}
		foreach ( $state['meta'] as $key => $values ) {
			$out['meta'][ $key ] = self::hash_meta( $values );
		}

		return $out;
	}

	/**
	 * What an update changed since it was created: the parts of its current
	 * state that no longer match the fingerprint taken back then.
	 *
	 * @param array $state       Current state of the update.
	 * @param array $fingerprint Fingerprint taken when it was created.
	 * @return array Change set.
	 */
	public static function diff( array $state, array $fingerprint ) {
		$fingerprint = wp_parse_args(
			$fingerprint,
			array(
				'fields' => array(),
				'terms'  => array(),
				'meta'   => array(),
			)
		);
		$changes     = self::empty_set();

		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $fingerprint['fields'] ) && self::hash_field( $state['fields'][ $field ] ) !== $fingerprint['fields'][ $field ] ) {
				$changes['fields'][ $field ] = $state['fields'][ $field ];
			}
		}

		// Only taxonomies that existed when the update was made, so one
		// registered since can't wipe the live post's terms.
		foreach ( $fingerprint['terms'] as $taxonomy => $hash ) {
			$ids = isset( $state['terms'][ $taxonomy ] ) ? $state['terms'][ $taxonomy ] : array();
			if ( taxonomy_exists( $taxonomy ) && self::hash_terms( $ids ) !== $hash ) {
				$changes['terms'][ $taxonomy ] = $ids;
			}
		}

		foreach ( $state['meta'] as $key => $values ) {
			// The classic editor saves "default" as the page template even
			// when none was set; both mean the theme's default template.
			if ( '_wp_page_template' === $key && array( 'default' ) === $values && ! array_key_exists( $key, $fingerprint['meta'] ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $fingerprint['meta'] ) || self::hash_meta( $values ) !== $fingerprint['meta'][ $key ] ) {
				$changes['meta'][ $key ] = $values;
			}
		}
		foreach ( $fingerprint['meta'] as $key => $hash ) {
			if ( ! array_key_exists( $key, $state['meta'] ) && ! self::skip_meta( $key ) ) {
				$changes['meta'][ $key ] = null;
			}
		}

		return $changes;
	}

	/**
	 * An empty change set.
	 *
	 * @return array
	 */
	public static function empty_set() {
		return array(
			'fields' => array(),
			'terms'  => array(),
			'meta'   => array(),
		);
	}

	/**
	 * Whether a change set changes anything.
	 *
	 * @param array $changes Change set.
	 * @return bool
	 */
	public static function is_empty( array $changes ) {
		return empty( $changes['fields'] ) && empty( $changes['terms'] ) && empty( $changes['meta'] );
	}

	/**
	 * Apply a change set to a post and return what it replaced.
	 *
	 * Meta and terms go first and the post itself last, so plugins that react
	 * to the save (SEO, caching, search indexes) see the finished post. Run
	 * it as the user responsible for the change: WordPress filters the
	 * content for whoever is logged in.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $changes Change set.
	 * @param array $args    touch_date: also move the publish date to now.
	 * @return array|WP_Error Backup: array( 'before' => change set, 'after' => fingerprint of the result ).
	 */
	public static function apply( $post_id, array $changes, array $args = array() ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'sppu_missing_post', __( 'The post no longer exists.', 'schedule-post-page-updates' ) );
		}

		$args    = wp_parse_args( $args, array( 'touch_date' => false ) );
		$changes = wp_parse_args( $changes, self::empty_set() );
		$before  = self::empty_set();
		$postarr = array( 'ID' => $post->ID );

		foreach ( $changes['meta'] as $key => $values ) {
			$before['meta'][ $key ] = self::meta_values( $post->ID, $key );
		}
		foreach ( $changes['terms'] as $taxonomy => $ids ) {
			$before['terms'][ $taxonomy ] = self::term_ids( $post->ID, $taxonomy );
		}
		foreach ( $changes['fields'] as $field => $value ) {
			if ( in_array( $field, self::FIELDS, true ) ) {
				$before['fields'][ $field ] = (string) $post->$field;
				$postarr[ $field ]          = $value;
			}
		}

		if ( $args['touch_date'] && in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			$before['fields']['post_date']     = $post->post_date;
			$before['fields']['post_date_gmt'] = $post->post_date_gmt;
			$postarr['post_date']              = current_time( 'mysql' );
			$postarr['post_date_gmt']          = current_time( 'mysql', true );
		}

		$error = self::write( $post->ID, $changes['meta'], $changes['terms'], $postarr );

		if ( is_wp_error( $error ) ) {
			// Put meta and terms back, so a failed save leaves the post as it was.
			self::write( $post->ID, $before['meta'], $before['terms'], array() );
			return $error;
		}

		return array(
			'before' => self::encode( $before ),
			'after'  => self::fingerprint_of( $post->ID, $before ),
		);
	}

	/**
	 * Undo an applied change, part by part. A part that has been edited since
	 * is left alone, so undo never throws away later work.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $backup  Backup returned by {@see apply()}.
	 * @return array|WP_Error array( 'restored' => labels, 'skipped' => labels ).
	 */
	public static function undo( $post_id, array $backup ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'sppu_missing_post', __( 'The post no longer exists.', 'schedule-post-page-updates' ) );
		}
		if ( 'trash' === $post->post_status ) {
			return new WP_Error( 'sppu_trashed_post', __( 'The post is in the trash. Restore it first.', 'schedule-post-page-updates' ) );
		}
		if ( empty( $backup['before'] ) || ! isset( $backup['after'] ) ) {
			return new WP_Error( 'sppu_no_backup', __( 'There is nothing saved to undo.', 'schedule-post-page-updates' ) );
		}

		$before  = wp_parse_args( self::decode( $backup['before'] ), self::empty_set() );
		$after   = wp_parse_args(
			(array) $backup['after'],
			array(
				'fields' => array(),
				'terms'  => array(),
				'meta'   => array(),
			)
		);
		$now     = self::fingerprint_of( $post->ID, $before );
		$restore = self::empty_set();
		$skipped = self::empty_set();
		$postarr = array( 'ID' => $post->ID );

		foreach ( $before['fields'] as $field => $value ) {
			if ( isset( $after['fields'][ $field ] ) && $now['fields'][ $field ] !== $after['fields'][ $field ] ) {
				$skipped['fields'][ $field ] = true;
				continue;
			}
			$restore['fields'][ $field ] = $value;
			$postarr[ $field ]           = $value;
		}
		foreach ( $before['terms'] as $taxonomy => $ids ) {
			if ( isset( $after['terms'][ $taxonomy ] ) && $now['terms'][ $taxonomy ] !== $after['terms'][ $taxonomy ] ) {
				$skipped['terms'][ $taxonomy ] = true;
				continue;
			}
			$restore['terms'][ $taxonomy ] = $ids;
		}
		foreach ( $before['meta'] as $key => $values ) {
			if ( isset( $after['meta'][ $key ] ) && $now['meta'][ $key ] !== $after['meta'][ $key ] ) {
				$skipped['meta'][ $key ] = true;
				continue;
			}
			$restore['meta'][ $key ] = $values;
		}

		if ( ! self::is_empty( $restore ) ) {
			$error = self::write( $post->ID, $restore['meta'], $restore['terms'], $postarr );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		return array(
			'restored' => self::labels( $restore ),
			'skipped'  => self::labels( $skipped ),
		);
	}

	/**
	 * Write meta, terms and post fields.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array               $meta    Meta values by key (null deletes).
	 * @param array               $terms   Term IDs by taxonomy.
	 * @param array<string,mixed> $postarr Post fields for wp_update_post(), with ID.
	 * @return true|WP_Error
	 */
	private static function write( $post_id, array $meta, array $terms, array $postarr ) {
		foreach ( $meta as $key => $values ) {
			self::write_meta( $post_id, $key, $values );
		}

		foreach ( $terms as $taxonomy => $ids ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$result = wp_set_object_terms( $post_id, array_map( 'intval', (array) $ids ), $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Page builders cache rendered CSS per post; drop it so it's rebuilt
		// from the new layout.
		if ( array_key_exists( '_elementor_data', $meta ) ) {
			delete_post_meta( $post_id, '_elementor_css' );
			delete_post_meta( $post_id, '_elementor_element_cache' );
		}

		if ( ! empty( $postarr['ID'] ) ) {
			// Always saved, even when only meta changed, so "Last modified"
			// moves and caching and SEO plugins see the new version.
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! $result ) {
				return new WP_Error( 'sppu_save_failed', __( 'WordPress could not save the post.', 'schedule-post-page-updates' ) );
			}
		}

		clean_post_cache( $post_id );

		return true;
	}

	/**
	 * Fingerprint of the parts of a post named in a change set.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $set     Change set naming the parts.
	 * @return array
	 */
	private static function fingerprint_of( $post_id, array $set ) {
		clean_post_cache( $post_id );

		$post = get_post( $post_id );
		$out  = array(
			'fields' => array(),
			'terms'  => array(),
			'meta'   => array(),
		);

		foreach ( array_keys( $set['fields'] ) as $field ) {
			$out['fields'][ $field ] = self::hash_field( $post ? $post->$field : '' );
		}
		foreach ( array_keys( $set['terms'] ) as $taxonomy ) {
			$out['terms'][ $taxonomy ] = self::hash_terms( self::term_ids( $post_id, $taxonomy ) );
		}
		foreach ( array_keys( $set['meta'] ) as $key ) {
			$out['meta'][ $key ] = self::hash_meta( self::meta_values( $post_id, (string) $key ) );
		}

		return $out;
	}

	/**
	 * Make a change set safe to store as JSON: strings that aren't valid
	 * UTF-8 (rare, but meta can hold anything) are kept as base64.
	 *
	 * @param array $set Change set.
	 * @return array
	 */
	private static function encode( array $set ) {
		$wrap = function ( $value ) {
			if ( is_string( $value ) && ! preg_match( '//u', $value ) ) {
				return array( 'b64' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storing binary safely.
			}
			return $value;
		};

		foreach ( $set['fields'] as $field => $value ) {
			$set['fields'][ $field ] = $wrap( $value );
		}
		foreach ( $set['meta'] as $key => $values ) {
			if ( is_array( $values ) ) {
				$set['meta'][ $key ] = array_map( $wrap, $values );
			}
		}

		return $set;
	}

	/**
	 * Reverse {@see encode()}.
	 *
	 * @param array $set Stored change set.
	 * @return array
	 */
	private static function decode( array $set ) {
		$set    = wp_parse_args( $set, self::empty_set() );
		$unwrap = function ( $value ) {
			if ( is_array( $value ) && isset( $value['b64'] ) ) {
				return (string) base64_decode( $value['b64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reading our own backup.
			}
			return is_scalar( $value ) ? (string) $value : '';
		};

		foreach ( (array) $set['fields'] as $field => $value ) {
			$set['fields'][ $field ] = $unwrap( $value );
		}
		foreach ( (array) $set['meta'] as $key => $values ) {
			$set['meta'][ $key ] = is_array( $values ) ? array_map( $unwrap, $values ) : null;
		}
		foreach ( (array) $set['terms'] as $taxonomy => $ids ) {
			$set['terms'][ $taxonomy ] = array_map( 'intval', (array) $ids );
		}

		return $set;
	}

	/**
	 * Human labels for what a change set touches, e.g. "Title", "Categories",
	 * "Featured image", "2 custom fields".
	 *
	 * @param array $changes Change set (values don't matter, only keys).
	 * @return string[]
	 */
	public static function labels( array $changes ) {
		$changes = wp_parse_args( $changes, self::empty_set() );
		$names   = array(
			'post_title'     => __( 'Title', 'schedule-post-page-updates' ),
			'post_content'   => __( 'Content', 'schedule-post-page-updates' ),
			'post_excerpt'   => __( 'Excerpt', 'schedule-post-page-updates' ),
			'post_parent'    => __( 'Parent page', 'schedule-post-page-updates' ),
			'menu_order'     => __( 'Order', 'schedule-post-page-updates' ),
			'comment_status' => __( 'Comments setting', 'schedule-post-page-updates' ),
			'ping_status'    => __( 'Pingbacks setting', 'schedule-post-page-updates' ),
			'post_password'  => __( 'Password', 'schedule-post-page-updates' ),
			'post_date'      => __( 'Publish date', 'schedule-post-page-updates' ),
		);
		$labels  = array();

		foreach ( array_keys( $changes['fields'] ) as $field ) {
			if ( isset( $names[ $field ] ) ) {
				$labels[] = $names[ $field ];
			}
		}

		foreach ( array_keys( $changes['terms'] ) as $taxonomy ) {
			$tax      = get_taxonomy( $taxonomy );
			$labels[] = $tax ? $tax->labels->name : $taxonomy;
		}

		$seo    = Schedule_Post_Page_Updates_Replace::seo_keys();
		$other  = 0;
		$hidden = 0;
		$known  = array();

		foreach ( array_keys( $changes['meta'] ) as $key ) {
			$key = (string) $key;
			if ( '_thumbnail_id' === $key ) {
				$known['image'] = __( 'Featured image', 'schedule-post-page-updates' );
			} elseif ( '_wp_page_template' === $key ) {
				$known['template'] = __( 'Template', 'schedule-post-page-updates' );
			} elseif ( in_array( $key, $seo, true ) ) {
				$known['seo'] = __( 'SEO title or description', 'schedule-post-page-updates' );
			} elseif ( in_array( $key, self::COMPUTED_META, true ) ) {
				// Scores and reading times that plugins recalculate on every save.
				++$hidden;
			} elseif ( preg_match( '/^_?(yoast_wpseo_|rank_math_|seopress_|aioseo_|genesis_)/', $key ) ) {
				$known['seo_settings'] = __( 'SEO settings', 'schedule-post-page-updates' );
			} elseif ( 0 === strpos( $key, '_elementor_' ) ) {
				$known['builder'] = __( 'Page builder layout', 'schedule-post-page-updates' );
			} elseif ( 0 === strpos( $key, '_' ) ) {
				// Hidden fields are plugins' own data, usually following a content change.
				++$hidden;
			} else {
				++$other;
			}
		}

		$labels = array_merge( $labels, array_values( $known ) );

		if ( $other ) {
			/* translators: %d: number of custom fields. */
			$labels[] = sprintf( _n( '%d custom field', '%d custom fields', $other, 'schedule-post-page-updates' ), $other );
		}

		if ( $hidden && ! $labels ) {
			$labels[] = __( 'Plugin settings', 'schedule-post-page-updates' );
		}

		return array_values( array_unique( $labels ) );
	}

	/**
	 * Labels as one sentence fragment: "Title, Content and 2 custom fields".
	 *
	 * @param string[] $labels Labels.
	 * @return string
	 */
	public static function sentence( array $labels ) {
		if ( ! $labels ) {
			return __( 'Nothing changed yet', 'schedule-post-page-updates' );
		}

		return wp_sprintf( '%l', $labels );
	}
}
