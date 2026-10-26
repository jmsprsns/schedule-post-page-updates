<?php
/**
 * Scheduled updates: private copies of a live post that replace it at a set time.
 *
 * A copy is a post of the same type with its own status ("Scheduled
 * Update"), so it never shows on the site, in feeds, sitemaps or search, and
 * it stays hidden even if this plugin is removed. Whoever can edit the live
 * post can edit its copies; nobody else can.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copy lifecycle and the Posts screen integration.
 */
final class Schedule_Post_Page_Updates_Copies {

	/**
	 * Post status of every copy.
	 */
	const STATUS = 'sppu_update';

	/**
	 * Meta: ID of the live post a copy updates.
	 */
	const META_ORIGINAL = '_sppu_original';

	/**
	 * Meta: fingerprint of the copy when it was made (see Changes::fingerprint()).
	 */
	const META_SNAPSHOT = '_sppu_snapshot';

	/**
	 * Meta: hash of the live post's title, content and excerpt when the
	 * copy was made, to tell when the live post has been edited since.
	 */
	const META_BASE = '_sppu_base_hash';

	/**
	 * Meta: when the copy goes live, as a Unix timestamp. Missing = not scheduled.
	 */
	const META_TIME = '_sppu_scheduled_at';

	/**
	 * Meta: user who set the time.
	 */
	const META_SCHEDULED_BY = '_sppu_scheduled_by';

	/**
	 * Meta: user who last saved the copy.
	 */
	const META_EDITED_BY = '_sppu_edited_by';

	/**
	 * Meta: user who made the copy.
	 */
	const META_CREATED_BY = '_sppu_created_by';

	/**
	 * Meta: why the last attempt to apply it failed.
	 */
	const META_ERROR = '_sppu_error';

	/**
	 * Statuses a live post can have to get a scheduled update.
	 */
	const SOURCE_STATUSES = array( 'publish', 'private' );

	/**
	 * Set while a copy is being inserted, before it has its meta.
	 *
	 * @var bool
	 */
	private static $creating = false;

	/**
	 * Copies per live post on the current list screen.
	 *
	 * @var array<int,int[]>|null
	 */
	private static $list_index = null;

	/**
	 * Hook everything in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ), 5 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'enforce' ), 99, 2 );
		add_filter( 'pre_wp_unique_post_slug', array( __CLASS__, 'no_slug' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_caps' ), 10, 4 );
		add_filter( 'post_link', array( __CLASS__, 'filter_link' ), 99, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'filter_link' ), 99, 2 );
		add_filter( 'page_link', array( __CLASS__, 'filter_link' ), 99, 2 );
		add_action( 'save_post', array( __CLASS__, 'track_editor' ), 10, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );

		if ( is_admin() ) {
			add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
			add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		}
	}

	/**
	 * Register the "Scheduled Update" status. Not public, so copies 404 for
	 * visitors; protected, so editors can preview them.
	 *
	 * @return void
	 */
	public static function register_status() {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Scheduled Update', 'post status', 'schedule-post-page-updates' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'private'                   => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of scheduled updates. */
				'label_count'               => _n_noop( 'Scheduled Updates <span class="count">(%s)</span>', 'Scheduled Updates <span class="count">(%s)</span>', 'schedule-post-page-updates' ),
			)
		);
	}

	/**
	 * Whether a post is a scheduled update copy.
	 *
	 * @param int|WP_Post $post Post or ID.
	 * @return bool
	 */
	public static function is_copy( $post ) {
		return self::original_id( $post ) > 0;
	}

	/**
	 * ID of the live post a copy updates.
	 *
	 * @param int|WP_Post $post Copy or ID.
	 * @return int 0 when it isn't a copy.
	 */
	public static function original_id( $post ) {
		$id = $post instanceof WP_Post ? $post->ID : absint( $post );

		return $id ? absint( get_post_meta( $id, self::META_ORIGINAL, true ) ) : 0;
	}

	/**
	 * When a copy goes live.
	 *
	 * @param int $copy_id Copy ID.
	 * @return int Unix timestamp, 0 when not scheduled.
	 */
	public static function time( $copy_id ) {
		return absint( get_post_meta( $copy_id, self::META_TIME, true ) );
	}

	/**
	 * IDs of every copy of a live post, in any status (trash included).
	 *
	 * @param int $original_id Live post ID.
	 * @return int[]
	 */
	public static function copies_of( $original_id ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_ORIGINAL, (string) absint( $original_id ) ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		);
	}

	/**
	 * Every copy that isn't in the trash, newest first.
	 *
	 * @param int $limit Most to return.
	 * @return WP_Post[]
	 */
	public static function all( $limit = 500 ) {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s ORDER BY ID DESC LIMIT %d", self::STATUS, absint( $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $ids ) {
			return array();
		}

		_prime_post_caches( array_map( 'intval', $ids ), false, true );

		return array_values( array_filter( array_map( 'get_post', $ids ) ) );
	}

	/**
	 * Copies that are scheduled, soonest first.
	 *
	 * @return WP_Post[]
	 */
	public static function scheduled() {
		$copies = array_filter(
			self::all(),
			function ( $copy ) {
				return Schedule_Post_Page_Updates_Copies::time( $copy->ID ) > 0;
			}
		);

		usort(
			$copies,
			function ( $a, $b ) {
				return Schedule_Post_Page_Updates_Copies::time( $a->ID ) - Schedule_Post_Page_Updates_Copies::time( $b->ID );
			}
		);

		return $copies;
	}

	/**
	 * Whether a user may schedule an update for a post.
	 *
	 * @param WP_Post|null $post    Live post.
	 * @param int          $user_id User ID.
	 * @return bool
	 */
	public static function can_schedule( $post, $user_id ) {
		return $post instanceof WP_Post
			&& in_array( $post->post_type, Schedule_Post_Page_Updates_Plugin::post_types(), true )
			&& in_array( $post->post_status, self::SOURCE_STATUSES, true )
			&& ! self::is_copy( $post )
			&& user_can( $user_id, 'edit_post', $post->ID );
	}

	/**
	 * Make a scheduled update copy of a live post.
	 *
	 * @param int $original_id Live post ID.
	 * @param int $user_id     Who's making it.
	 * @return int|WP_Error Copy ID.
	 */
	public static function create( $original_id, $user_id ) {
		$original = get_post( $original_id );

		if ( ! self::can_schedule( $original, $user_id ) ) {
			return new WP_Error( 'sppu_cannot_schedule', __( 'You can\'t schedule an update for this post.', 'schedule-post-page-updates' ) );
		}

		$categories = 'post' === $original->post_type ? Schedule_Post_Page_Updates_Changes::term_ids( $original->ID, 'category' ) : array();

		self::$creating = true;

		$copy_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $original->post_type,
					'post_status'    => self::STATUS,
					'post_title'     => $original->post_title,
					'post_content'   => $original->post_content,
					'post_excerpt'   => $original->post_excerpt,
					'post_author'    => $original->post_author,
					'post_parent'    => $original->post_parent,
					'menu_order'     => $original->menu_order,
					'comment_status' => $original->comment_status,
					'ping_status'    => $original->ping_status,
					'post_password'  => $original->post_password,
					'post_mime_type' => $original->post_mime_type,
					'post_date'      => $original->post_date,
					'post_date_gmt'  => $original->post_date_gmt,
					'post_category'  => $categories,
					'meta_input'     => array(
						self::META_ORIGINAL   => $original->ID,
						self::META_CREATED_BY => absint( $user_id ),
						self::META_EDITED_BY  => absint( $user_id ),
						self::META_BASE       => self::content_hash( $original ),
					),
				)
			),
			true
		);

		self::$creating = false;

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		foreach ( Schedule_Post_Page_Updates_Changes::terms( $original ) as $taxonomy => $ids ) {
			wp_set_object_terms( $copy_id, $ids, $taxonomy );
		}

		foreach ( Schedule_Post_Page_Updates_Changes::raw_meta( $original->ID ) as $key => $values ) {
			Schedule_Post_Page_Updates_Changes::write_meta( $copy_id, $key, $values );
		}

		clean_post_cache( $copy_id );

		// Fingerprint the copy itself (not the live post), so the comparison
		// later is like for like, whatever WordPress did while saving it.
		update_post_meta( $copy_id, self::META_SNAPSHOT, Schedule_Post_Page_Updates_Changes::fingerprint( Schedule_Post_Page_Updates_Changes::state( get_post( $copy_id ) ) ) );

		/**
		 * Fires after a scheduled update copy is made.
		 *
		 * @param int $copy_id     Copy ID.
		 * @param int $original_id Live post ID.
		 */
		do_action( 'schedule_post_page_updates_copy_created', $copy_id, $original->ID );

		return $copy_id;
	}

	/**
	 * Hash of what people read in a post: title, content and excerpt.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function content_hash( WP_Post $post ) {
		return md5( $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt );
	}

	/**
	 * What a copy has changed so far.
	 *
	 * @param WP_Post $copy Copy.
	 * @return array Change set.
	 */
	public static function changes( WP_Post $copy ) {
		$snapshot = get_post_meta( $copy->ID, self::META_SNAPSHOT, true );

		if ( ! is_array( $snapshot ) ) {
			return Schedule_Post_Page_Updates_Changes::empty_set();
		}

		return Schedule_Post_Page_Updates_Changes::diff( Schedule_Post_Page_Updates_Changes::state( $copy ), $snapshot );
	}

	/**
	 * Schedule a copy.
	 *
	 * @param int $copy_id   Copy ID.
	 * @param int $timestamp When it goes live (Unix time).
	 * @param int $user_id   Who's scheduling it.
	 * @return true|WP_Error
	 */
	public static function set_time( $copy_id, $timestamp, $user_id ) {
		$timestamp = (int) $timestamp;

		if ( ! self::is_copy( $copy_id ) ) {
			return new WP_Error( 'sppu_not_update', __( 'That isn\'t a scheduled update.', 'schedule-post-page-updates' ) );
		}
		if ( $timestamp <= time() ) {
			return new WP_Error( 'sppu_past', __( 'Pick a time in the future. To make the change now, use Apply now.', 'schedule-post-page-updates' ) );
		}

		update_post_meta( $copy_id, self::META_TIME, $timestamp );
		update_post_meta( $copy_id, self::META_SCHEDULED_BY, absint( $user_id ) );
		delete_post_meta( $copy_id, self::META_ERROR );

		Schedule_Post_Page_Updates_Scheduler::schedule_update( $copy_id, $timestamp );

		return true;
	}

	/**
	 * Unschedule a copy. It stays as a draft of the change.
	 *
	 * @param int $copy_id Copy ID.
	 * @return void
	 */
	public static function clear_time( $copy_id ) {
		delete_post_meta( $copy_id, self::META_TIME );
		Schedule_Post_Page_Updates_Scheduler::unschedule_update( $copy_id );
	}

	/**
	 * A copy's state for lists and the editor panel.
	 *
	 * @param WP_Post $copy Copy.
	 * @return array<string,mixed>
	 */
	public static function info( WP_Post $copy ) {
		$original_id = self::original_id( $copy );
		$original    = $original_id ? get_post( $original_id ) : null;
		$time        = self::time( $copy->ID );
		$error       = (string) get_post_meta( $copy->ID, self::META_ERROR, true );
		$base        = (string) get_post_meta( $copy->ID, self::META_BASE, true );

		if ( '' !== $error ) {
			$status = 'error';
		} elseif ( ! $original || 'trash' === $original->post_status ) {
			$status = 'error';
			$error  = $original ? __( 'The live post is in the trash.', 'schedule-post-page-updates' ) : __( 'The live post no longer exists.', 'schedule-post-page-updates' );
		} elseif ( ! $time ) {
			$status = 'draft';
		} elseif ( $time <= time() - 5 * MINUTE_IN_SECONDS ) {
			$status = 'overdue';
		} else {
			$status = 'scheduled';
		}

		return array(
			'id'           => $copy->ID,
			'original'     => $original,
			'time'         => $time,
			'status'       => $status,
			'error'        => $error,
			'conflict'     => $original && '' !== $base && self::content_hash( $original ) !== $base,
			'scheduled_by' => absint( get_post_meta( $copy->ID, self::META_SCHEDULED_BY, true ) ),
		);
	}

	/**
	 * Keep copies in their own status and without a slug, whatever the editor
	 * sends. "Publish" on a copy can never make it a second live post; it
	 * just saves the copy. Trashing is still allowed.
	 *
	 * @param array $data    Post data about to be saved.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function enforce( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;

		if ( ! self::$creating && ( ! $id || ! self::is_copy( $id ) ) ) {
			// Only copies made here may hold the status; anyone else asking
			// for it (e.g. through the REST API) gets a draft.
			if ( self::STATUS === $data['post_status'] ) {
				$data['post_status'] = 'draft';
			}
			return $data;
		}

		if ( 'trash' !== $data['post_status'] ) {
			$data['post_status'] = self::STATUS;
		}

		// No slug, so a copy never reserves the live post's URL.
		$data['post_name'] = '';

		return $data;
	}

	/**
	 * WordPress gives every post outside draft and pending a unique slug,
	 * even one saved without it. A copy keeps none, so it never takes a
	 * "-2" slug that a later post would have wanted.
	 *
	 * @param string|null $override    Slug to use instead, or null.
	 * @param string      $slug        Desired slug.
	 * @param int         $post_id     Post ID.
	 * @param string      $post_status Post status.
	 * @return string|null
	 */
	public static function no_slug( $override, $slug, $post_id, $post_status = '' ) {
		return self::STATUS === $post_status ? '' : $override;
	}

	/**
	 * Tie every permission on a copy to the live post: whoever can edit the
	 * live post can edit, preview and delete its scheduled updates, and
	 * nobody else can, whoever is set as the copy's author.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Meta cap being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra arguments; [0] is the post ID.
	 * @return string[]
	 */
	public static function map_caps( $caps, $cap, $user_id, $args ) {
		if ( empty( $args[0] ) || ! in_array( $cap, array( 'edit_post', 'edit_page', 'delete_post', 'delete_page', 'read_post', 'read_page', 'publish_post' ), true ) ) {
			return $caps;
		}

		$post = get_post( $args[0] );

		if ( $post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}

		if ( ! $post || ! in_array( $post->post_status, array( self::STATUS, 'trash' ), true ) ) {
			return $caps;
		}

		$original_id = self::original_id( $post );

		if ( ! $original_id ) {
			return $caps;
		}

		$original = get_post( $original_id );

		if ( ! $original || self::is_copy( $original ) ) {
			// Orphaned: only people who can edit everyone's posts may clean it up.
			$type = get_post_type_object( $post->post_type );
			return array( $type ? $type->cap->edit_others_posts : 'edit_others_posts' );
		}

		return map_meta_cap( 'edit_post', $user_id, $original_id );
	}

	/**
	 * Copies have no pretty permalink. Link them like drafts (?p=123), so
	 * Preview works and nothing guesses a public URL.
	 *
	 * @param string      $link Permalink.
	 * @param int|WP_Post $post Post or ID.
	 * @return string
	 */
	public static function filter_link( $link, $post ) {
		$post = get_post( $post );

		if ( ! $post || self::STATUS !== $post->post_status ) {
			return $link;
		}

		if ( 'page' === $post->post_type ) {
			$args = array( 'page_id' => $post->ID );
		} elseif ( 'post' === $post->post_type ) {
			$args = array( 'p' => $post->ID );
		} else {
			$args = array(
				'post_type' => $post->post_type,
				'p'         => $post->ID,
			);
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Remember who last saved a copy. Applying it later needs that person to
	 * still be allowed to edit the live post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function track_editor( $post_id, $post ) {
		if ( self::$creating || self::STATUS !== $post->post_status || ! get_current_user_id() || ! self::is_copy( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_EDITED_BY, get_current_user_id() );
	}

	/**
	 * A trashed copy is unscheduled, so restoring it never applies an old change by surprise.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_trash( $post_id ) {
		if ( self::is_copy( $post_id ) ) {
			self::clear_time( $post_id );
		}
	}

	/**
	 * Deleting a copy unschedules it; deleting a live post deletes its copies.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'revision' === $post->post_type ) {
			return;
		}

		if ( self::is_copy( $post ) ) {
			Schedule_Post_Page_Updates_Scheduler::unschedule_update( $post->ID );
			return;
		}

		foreach ( self::copies_of( $post->ID ) as $copy_id ) {
			if ( $copy_id !== $post->ID ) {
				wp_delete_post( $copy_id, true );
			}
		}
	}

	/**
	 * Copies of the live posts, keyed by live post ID, for the list screen.
	 *
	 * @return array<int,int[]>
	 */
	private static function list_index() {
		global $wpdb;

		if ( null !== self::$list_index ) {
			return self::$list_index;
		}

		self::$list_index = array();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT m.meta_value AS original_id, p.ID AS copy_id FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = %s",
				self::META_ORIGINAL,
				self::STATUS
			)
		);

		foreach ( (array) $rows as $row ) {
			self::$list_index[ (int) $row->original_id ][] = (int) $row->copy_id;
		}

		return self::$list_index;
	}

	/**
	 * A date and time in the site's time zone, e.g. "January 1, 2027 at 12:00 am".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function format_time( $timestamp ) {
		/* translators: 1: date, 2: time. */
		return sprintf( __( '%1$s at %2$s', 'schedule-post-page-updates' ), wp_date( get_option( 'date_format' ), $timestamp ), wp_date( get_option( 'time_format' ), $timestamp ) );
	}

	/**
	 * The site's time zone the way Settings > General names it: a city
	 * ("America/New_York") or an offset ("UTC+5:30").
	 *
	 * @return string
	 */
	public static function timezone_label() {
		$name = (string) get_option( 'timezone_string' );

		if ( '' !== $name ) {
			return $name;
		}

		$offset  = (float) get_option( 'gmt_offset' );
		$hours   = (int) $offset;
		$minutes = (int) round( abs( $offset - $hours ) * 60 );

		return 'UTC' . ( $offset < 0 ? '-' : '+' ) . abs( $hours ) . ( $minutes ? ':' . sprintf( '%02d', $minutes ) : '' );
	}

	/**
	 * Show scheduled updates in the post's row on the list screen.
	 *
	 * @param string[] $states Post states.
	 * @param WP_Post  $post   Post.
	 * @return string[]
	 */
	public static function post_states( $states, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $states;
		}

		if ( self::STATUS === $post->post_status ) {
			$info = self::info( $post );

			if ( 'error' === $info['status'] ) {
				$states['sppu'] = __( 'Scheduled update: needs attention', 'schedule-post-page-updates' );
			} elseif ( $info['time'] ) {
				/* translators: %s: date and time. */
				$states['sppu'] = sprintf( __( 'Goes live %s', 'schedule-post-page-updates' ), self::format_time( $info['time'] ) );
			} else {
				$states['sppu'] = __( 'Scheduled update, no date yet', 'schedule-post-page-updates' );
			}

			return $states;
		}

		$index = self::list_index();

		if ( empty( $index[ $post->ID ] ) ) {
			return $states;
		}

		$times = array_filter( array_map( array( __CLASS__, 'time' ), $index[ $post->ID ] ) );

		if ( $times ) {
			/* translators: %s: date and time. */
			$states['sppu'] = sprintf( __( 'Update scheduled for %s', 'schedule-post-page-updates' ), self::format_time( min( $times ) ) );
		} else {
			$states['sppu'] = __( 'Has an unscheduled update', 'schedule-post-page-updates' );
		}

		return $states;
	}

	/**
	 * "Schedule Update" on live posts; "Apply Now" and "Preview" on copies.
	 *
	 * @param string[] $actions Row actions.
	 * @param WP_Post  $post    Post.
	 * @return string[]
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $actions;
		}

		if ( self::STATUS === $post->post_status ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return $actions;
			}

			// Quick Edit can't show what a copy is, and "View" is really a preview.
			unset( $actions['inline hide-if-no-js'], $actions['view'] );

			$original = get_post( self::original_id( $post ) );
			$extra    = array(
				'sppu_preview' => sprintf( '<a href="%s" target="wp-preview-%d">%s</a>', esc_url( get_preview_post_link( $post ) ), (int) $post->ID, esc_html__( 'Preview', 'schedule-post-page-updates' ) ),
			);

			if ( $original && 'trash' !== $original->post_status ) {
				$extra['sppu_live']  = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $original ) ), esc_html__( 'View Live Post', 'schedule-post-page-updates' ) );
				$extra['sppu_apply'] = sprintf(
					'<a href="%s" class="sppu-confirm" data-confirm="%s">%s</a>',
					esc_url( Schedule_Post_Page_Updates_Editor::apply_url( $post->ID ) ),
					esc_attr__( 'Replace the live post with this update now?', 'schedule-post-page-updates' ),
					esc_html__( 'Apply Now', 'schedule-post-page-updates' )
				);
			}

			$edit = isset( $actions['edit'] ) ? array( 'edit' => $actions['edit'] ) : array();
			unset( $actions['edit'] );

			return array_merge( $edit, $extra, $actions );
		}

		if ( self::can_schedule( $post, get_current_user_id() ) ) {
			$actions['sppu_schedule'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Schedule_Post_Page_Updates_Editor::create_url( $post->ID ) ),
				esc_html__( 'Schedule Update', 'schedule-post-page-updates' )
			);
		}

		return $actions;
	}
}
