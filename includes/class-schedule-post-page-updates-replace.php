<?php
/**
 * Scheduled find and replace: change a piece of text (like "2026" to "2027")
 * in chosen posts at a set time, once or every year.
 *
 * Content is edited as HTML, not as a string: tags, attributes, links,
 * shortcodes, scripts and styles are left alone, so "2026" in an image URL
 * like /uploads/2026/01/ never changes. Text inside block settings is changed
 * too (with the same care), so blocks stay valid in the editor.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find and replace rules.
 */
final class Schedule_Post_Page_Updates_Replace {

	/**
	 * Parts of a post a rule can change.
	 */
	const FIELDS = array( 'title', 'content', 'excerpt', 'seo' );

	/**
	 * Posts per batch.
	 */
	const BATCH = 20;

	/**
	 * Seconds one request may spend on a run before handing over to the next cron run.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Most posts a rule can name.
	 */
	const MAX_POSTS = 500;

	/**
	 * Longest find or replace text.
	 */
	const MAX_TEXT = 500;

	/**
	 * Statuses of posts a rule can change.
	 */
	const POST_STATUSES = array( 'publish', 'private', 'future' );

	/**
	 * Block settings never changed: IDs, links, class names and other
	 * values that aren't text people read.
	 */
	const SKIP_ATTRS = array( 'id', 'ref', 'url', 'href', 'src', 'link', 'linkTarget', 'linkDestination', 'linkClass', 'rel', 'className', 'anchor', 'slug', 'mediaId', 'mediaLink', 'mediaUrl', 'mediaType', 'imageUrl', 'videoUrl', 'poster', 'align', 'tagName', 'lock', 'layout', 'style', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'fontFamily', 'providerNameSlug', 'type', 'format', 'sizeSlug', 'templateLock', 'metadata' );

	/**
	 * Parts of HTML that are never changed: scripts, styles, comments
	 * (including block delimiters, handled separately), tags with their
	 * attributes, shortcode tags and bare URLs.
	 */
	const PROTECT = '~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->|<[^>]*>|\[/?[A-Za-z_][\w-]*(?:\s[^\[\]]*)?/?\]|\bhttps?://[^\s<>"\'\]]+|\bwww\.[^\s<>"\'\]]+)~is';

	/**
	 * Meta keys of SEO plugins that hold titles and descriptions: Yoast SEO,
	 * Rank Math, SEOPress and The SEO Framework.
	 *
	 * @return string[]
	 */
	public static function seo_keys() {
		/**
		 * Filters the meta keys changed by a rule's "SEO title and description" option.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return (array) apply_filters(
			'schedule_post_page_updates_seo_meta_keys',
			array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_opengraph-title',
				'_yoast_wpseo_opengraph-description',
				'_yoast_wpseo_twitter-title',
				'_yoast_wpseo_twitter-description',
				'rank_math_title',
				'rank_math_description',
				'rank_math_facebook_title',
				'rank_math_facebook_description',
				'rank_math_twitter_title',
				'rank_math_twitter_description',
				'_seopress_titles_title',
				'_seopress_titles_desc',
				'_seopress_social_fb_title',
				'_seopress_social_fb_desc',
				'_seopress_social_twitter_title',
				'_seopress_social_twitter_desc',
				'_genesis_title',
				'_genesis_description',
				'_open_graph_title',
				'_open_graph_description',
				'_twitter_title',
				'_twitter_description',
			)
		);
	}

	/*
	 * Storage
	 */

	/**
	 * One rule.
	 *
	 * @param int $id Rule ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Schedule_Post_Page_Updates_Install::table( 'rules' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.

		return $row ? self::from_row( $row ) : null;
	}

	/**
	 * Every rule, soonest first; finished ones last.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		global $wpdb;

		$table = Schedule_Post_Page_Updates_Install::table( 'rules' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY status = 'done', run_at ASC, id ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.

		return array_map( array( __CLASS__, 'from_row' ), (array) $rows );
	}

	/**
	 * A database row as a rule.
	 *
	 * @param array<string,string> $row Row.
	 * @return array<string,mixed>
	 */
	private static function from_row( array $row ) {
		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'find'          => (string) $row['find_text'],
			'replace'       => (string) $row['replace_text'],
			'fields'        => array_values( array_intersect( self::FIELDS, explode( ',', (string) $row['fields'] ) ) ),
			'match_case'    => (bool) $row['match_case'],
			'whole_words'   => (bool) $row['whole_words'],
			'scope'         => 'all' === $row['scope'] ? 'all' : 'posts',
			'post_ids'      => array_values( array_filter( array_map( 'intval', explode( ',', (string) $row['post_ids'] ) ) ) ),
			'post_types'    => array_values( array_filter( explode( ',', (string) $row['post_types'] ) ) ),
			'repeat'        => (bool) $row['repeat_yearly'],
			'run_at'        => self::to_time( $row['run_at'] ),
			'status'        => (string) $row['status'],
			'cursor'        => (int) $row['cursor_id'],
			'run_count'     => (int) $row['run_count'],
			'changed_count' => (int) $row['changed_count'],
			'last_run'      => self::to_time( $row['last_run'] ),
			'last_result'   => (string) $row['last_result'],
			'created_by'    => (int) $row['created_by'],
		);
	}

	/**
	 * A UTC MySQL datetime as a Unix timestamp.
	 *
	 * @param string|null $datetime Datetime.
	 * @return int 0 when empty.
	 */
	private static function to_time( $datetime ) {
		if ( ! $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return 0;
		}

		$time = strtotime( $datetime . ' UTC' );

		return $time ? $time : 0;
	}

	/**
	 * Save a new rule or replace an existing one's settings, and schedule it.
	 *
	 * @param array<string,mixed> $rule    Clean rule from {@see validate()}.
	 * @param int                 $id      Existing rule ID, 0 for new.
	 * @param int                 $user_id Who's saving it.
	 * @return int|WP_Error Rule ID.
	 */
	public static function save( array $rule, $id, $user_id ) {
		global $wpdb;

		$table = Schedule_Post_Page_Updates_Install::table( 'rules' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$data  = array(
			'name'          => $rule['name'],
			'find_text'     => $rule['find'],
			'replace_text'  => $rule['replace'],
			'fields'        => implode( ',', $rule['fields'] ),
			'match_case'    => $rule['match_case'] ? 1 : 0,
			'whole_words'   => $rule['whole_words'] ? 1 : 0,
			'scope'         => $rule['scope'],
			'post_ids'      => implode( ',', $rule['post_ids'] ),
			'post_types'    => implode( ',', $rule['post_types'] ),
			'repeat_yearly' => $rule['repeat'] ? 1 : 0,
			'run_at'        => gmdate( 'Y-m-d H:i:s', $rule['run_at'] ),
			'status'        => 'scheduled',
			'cursor_id'     => 0,
			'changed_count' => 0,
			// The rule runs as whoever saved it last.
			'created_by'    => absint( $user_id ),
			'updated_at'    => $now,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Our own table.
		if ( $id ) {
			$existing = self::get( $id );
			if ( ! $existing ) {
				return new WP_Error( 'sppu_no_rule', __( 'That rule no longer exists.', 'schedule-post-page-updates' ) );
			}
			if ( 'running' === $existing['status'] ) {
				return new WP_Error( 'sppu_rule_running', __( 'This rule is running right now. Try again in a minute.', 'schedule-post-page-updates' ) );
			}
			$wpdb->update( $table, $data, array( 'id' => absint( $id ) ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable

		if ( ! $id ) {
			return new WP_Error( 'sppu_save_rule', __( 'The rule could not be saved.', 'schedule-post-page-updates' ) );
		}

		Schedule_Post_Page_Updates_Scheduler::schedule_rule( $id, $rule['run_at'] );

		return (int) $id;
	}

	/**
	 * Update some columns of a rule.
	 *
	 * @param int                 $id   Rule ID.
	 * @param array<string,mixed> $data Columns.
	 * @return void
	 */
	private static function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$wpdb->update( Schedule_Post_Page_Updates_Install::table( 'rules' ), $data, array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
	}

	/**
	 * Delete a rule. Its history stays.
	 *
	 * @param int $id Rule ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		Schedule_Post_Page_Updates_Scheduler::unschedule_rule( $id );
		$wpdb->delete( Schedule_Post_Page_Updates_Install::table( 'rules' ), array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
	}

	/**
	 * Pause a rule.
	 *
	 * @param int $id Rule ID.
	 * @return void
	 */
	public static function pause( $id ) {
		Schedule_Post_Page_Updates_Scheduler::unschedule_rule( $id );
		self::update( $id, array( 'status' => 'paused' ) );
	}

	/**
	 * Resume a paused rule.
	 *
	 * @param int $id Rule ID.
	 * @return true|WP_Error
	 */
	public static function resume( $id ) {
		$rule = self::get( $id );

		if ( ! $rule ) {
			return new WP_Error( 'sppu_no_rule', __( 'That rule no longer exists.', 'schedule-post-page-updates' ) );
		}
		if ( $rule['run_at'] <= time() ) {
			return new WP_Error( 'sppu_rule_past', __( 'Its date has passed. Edit the rule and pick a new one.', 'schedule-post-page-updates' ) );
		}

		self::update(
			$id,
			array(
				'status'    => 'scheduled',
				'cursor_id' => 0,
			)
		);
		Schedule_Post_Page_Updates_Scheduler::schedule_rule( $id, $rule['run_at'] );

		return true;
	}

	/*
	 * Validation
	 */

	/**
	 * Plain text for a find or replace box: no tags, no control characters.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function clean_text( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = wp_check_invalid_utf8( $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Check a submitted rule and turn it into a clean one.
	 *
	 * @param array<string,mixed> $in         Submitted values (unslashed).
	 * @param int                 $user_id    Who's submitting.
	 * @param bool                $check_date Whether the date matters (not for a preview).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $in, $user_id, $check_date = true ) {
		$types   = Schedule_Post_Page_Updates_Plugin::post_types();
		$find    = self::clean_text( isset( $in['find'] ) ? $in['find'] : '' );
		$replace = self::clean_text( isset( $in['replace'] ) ? $in['replace'] : '' );
		$fields  = isset( $in['fields'] ) && is_array( $in['fields'] ) ? array_values( array_intersect( self::FIELDS, $in['fields'] ) ) : array();
		$scope   = isset( $in['scope'] ) && 'all' === $in['scope'] ? 'all' : 'posts';
		$repeat  = ! empty( $in['repeat'] );
		$errors  = array();

		if ( '' === $find ) {
			$errors[] = __( 'Enter the text to find.', 'schedule-post-page-updates' );
		} elseif ( strlen( $find ) > self::MAX_TEXT || strlen( $replace ) > self::MAX_TEXT ) {
			/* translators: %d: character limit. */
			$errors[] = sprintf( __( 'Keep the find and replace text under %d characters.', 'schedule-post-page-updates' ), self::MAX_TEXT );
		} elseif ( $find === $replace ) {
			$errors[] = __( 'The replacement is the same as the text to find, so nothing would change.', 'schedule-post-page-updates' );
		}

		if ( ! $fields ) {
			$errors[] = __( 'Choose at least one place to change: title, content, excerpt or SEO fields.', 'schedule-post-page-updates' );
		}

		$post_ids = array();
		if ( isset( $in['post_ids'] ) ) {
			$list     = is_array( $in['post_ids'] ) ? $in['post_ids'] : explode( ',', (string) $in['post_ids'] );
			$post_ids = array_values( array_unique( array_filter( array_map( 'absint', array_filter( $list, 'is_scalar' ) ) ) ) );
		}

		// Only posts this person can edit, of a type the plugin works on.
		$post_ids = array_values(
			array_filter(
				$post_ids,
				function ( $id ) use ( $types, $user_id ) {
					$post = get_post( $id );
					return $post
						&& in_array( $post->post_type, $types, true )
						&& 'trash' !== $post->post_status
						&& Schedule_Post_Page_Updates_Copies::STATUS !== $post->post_status
						&& user_can( $user_id, 'edit_post', $id );
				}
			)
		);
		$post_ids = array_slice( $post_ids, 0, self::MAX_POSTS );
		sort( $post_ids );

		$post_types = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_values( array_intersect( $types, $in['post_types'] ) ) : array();

		if ( 'posts' === $scope && ! $post_ids ) {
			$errors[] = __( 'Pick at least one post or page to change.', 'schedule-post-page-updates' );
		}
		if ( 'all' === $scope && ! $post_types ) {
			$errors[] = __( 'Pick at least one post type to search.', 'schedule-post-page-updates' );
		}

		if ( $repeat && ! preg_match( self::YEAR, $find ) ) {
			$errors[] = __( 'To repeat every year, the text to find needs a year in it, like 2026.', 'schedule-post-page-updates' );
		}

		$run_at = self::parse_local(
			isset( $in['date'] ) ? (string) $in['date'] : '',
			isset( $in['time'] ) ? (string) $in['time'] : ''
		);

		if ( ! $check_date ) {
			$run_at = 0;
		} elseif ( ! $run_at ) {
			$errors[] = __( 'Pick a valid date and time.', 'schedule-post-page-updates' );
		} elseif ( $run_at <= time() ) {
			$errors[] = __( 'Pick a date and time in the future. To make the change now, save the rule and use Run now.', 'schedule-post-page-updates' );
		}

		if ( $errors ) {
			return new WP_Error( 'sppu_invalid_rule', implode( ' ', $errors ) );
		}

		$name = isset( $in['name'] ) ? substr( sanitize_text_field( (string) $in['name'] ), 0, 190 ) : '';

		return array(
			'name'        => $name,
			'find'        => $find,
			'replace'     => $replace,
			'fields'      => $fields,
			'match_case'  => ! empty( $in['match_case'] ),
			'whole_words' => ! empty( $in['whole_words'] ),
			'scope'       => $scope,
			'post_ids'    => 'posts' === $scope ? $post_ids : array(),
			'post_types'  => 'all' === $scope ? $post_types : array(),
			'repeat'      => $repeat,
			'run_at'      => $run_at,
		);
	}

	/**
	 * A date and time typed in the site's time zone, as a Unix timestamp.
	 *
	 * @param string $date Y-m-d.
	 * @param string $time H:i.
	 * @return int 0 when invalid.
	 */
	public static function parse_local( $date, $time ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '00:00';
		}

		$parsed = DateTime::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
		$errors = DateTime::getLastErrors();

		if ( ! $parsed || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return 0;
		}

		return $parsed->getTimestamp();
	}

	/*
	 * Matching
	 */

	/**
	 * A four-digit year from 1800 to 2999.
	 */
	const YEAR = '/(?<![0-9])(1[89][0-9]{2}|2[0-9]{3})(?![0-9])/';

	/**
	 * Add one to every year in a text: "Best of 2026" becomes "Best of 2027".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function bump_years( $text ) {
		return (string) preg_replace_callback(
			self::YEAR,
			function ( $m ) {
				return (string) ( (int) $m[1] + 1 );
			},
			$text
		);
	}

	/**
	 * The regular expression for a rule's text.
	 *
	 * @param string $find        Text to find.
	 * @param bool   $match_case  Case sensitive.
	 * @param bool   $whole_words Only whole words: "2026" won't match "20260".
	 * @return string
	 */
	private static function regex( $find, $match_case, $whole_words ) {
		$pattern = preg_quote( $find, '/' );

		// Only add a boundary on a side that starts or ends with a letter or
		// number, so "2026!" still matches before a space.
		if ( $whole_words ) {
			if ( preg_match( '/^[\p{L}\p{N}_]/u', $find ) ) {
				$pattern = '(?<![\p{L}\p{N}_])' . $pattern;
			}
			if ( preg_match( '/[\p{L}\p{N}_]$/u', $find ) ) {
				$pattern .= '(?![\p{L}\p{N}_])';
			}
		}

		return '/' . $pattern . '/u' . ( $match_case ? '' : 'i' );
	}

	/**
	 * Replace in plain text.
	 *
	 * @param string $text    Text.
	 * @param array  $rule    Rule.
	 * @param string $find    Text to find (defaults to the rule's).
	 * @param string $replace Replacement (defaults to the rule's).
	 * @return array{0: string, 1: int} New text and number of replacements.
	 */
	private static function replace_string( $text, array $rule, $find = null, $replace = null ) {
		$find    = null === $find ? $rule['find'] : $find;
		$replace = null === $replace ? $rule['replace'] : $replace;
		$count   = 0;

		if ( '' === $text || '' === $find ) {
			return array( $text, 0 );
		}

		// A callback, so "$1" or "\1" in the replacement stay literal.
		$out = preg_replace_callback(
			self::regex( $find, $rule['match_case'], $rule['whole_words'] ),
			function () use ( $replace ) {
				return $replace;
			},
			$text,
			-1,
			$count
		);

		// Null means the text isn't valid UTF-8; leave it alone.
		return null === $out ? array( $text, 0 ) : array( $out, $count );
	}

	/**
	 * Replace in a title, excerpt or SEO field. Also tries the text as
	 * WordPress may have stored it, with &amp; and friends.
	 *
	 * @param string $text Text.
	 * @param array  $rule Rule.
	 * @return array{0: string, 1: int}
	 */
	public static function replace_plain( $text, array $rule ) {
		list( $out, $count ) = self::replace_string( $text, $rule );

		$escaped = esc_html( $rule['find'] );
		if ( $escaped !== $rule['find'] ) {
			list( $out, $more ) = self::replace_string( $out, $rule, $escaped, esc_html( $rule['replace'] ) );
			$count             += $more;
		}

		return array( $out, $count );
	}

	/**
	 * Replace in HTML: only in the text people read. The replacement is
	 * escaped, so it can never add markup.
	 *
	 * @param string $html HTML.
	 * @param array  $rule Rule.
	 * @return array{0: string, 1: int}
	 */
	public static function replace_html( $html, array $rule ) {
		if ( '' === $html ) {
			return array( $html, 0 );
		}

		$parts = preg_split( self::PROTECT, $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return array( $html, 0 );
		}

		$find_html    = htmlspecialchars( $rule['find'], ENT_NOQUOTES, 'UTF-8' );
		$replace_html = htmlspecialchars( $rule['replace'], ENT_NOQUOTES, 'UTF-8' );
		$total        = 0;

		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}

			// Odd entries are the protected parts. Only block delimiters
			// among them are looked into, for text in block settings.
			if ( $i % 2 ) {
				if ( 0 === strpos( $part, '<!-- wp:' ) ) {
					list( $parts[ $i ], $count ) = self::replace_block_comment( $part, $rule );
					$total                      += $count;
				}
				continue;
			}

			list( $part, $count ) = self::replace_string( $part, $rule, $find_html, $replace_html );
			$total               += $count;

			if ( $find_html !== $rule['find'] ) {
				list( $part, $count ) = self::replace_string( $part, $rule, $rule['find'], $replace_html );
				$total               += $count;
			}

			$parts[ $i ] = $part;
		}

		return array( implode( '', $parts ), $total );
	}

	/**
	 * Replace in the settings of a block delimiter, e.g. the questions of an
	 * FAQ block, so the block's saved settings match its HTML.
	 *
	 * @param string $comment "<!-- wp:name {...} -->".
	 * @param array  $rule    Rule.
	 * @return array{0: string, 1: int}
	 */
	private static function replace_block_comment( $comment, array $rule ) {
		if ( ! function_exists( 'serialize_block_attributes' ) || ! preg_match( '#^<!--\s+wp:([a-z][a-z0-9_-]*/)?([a-z][a-z0-9_-]*)\s+(\{.*\})\s+(/)?-->$#s', $comment, $m ) ) {
			return array( $comment, 0 );
		}

		// Objects, not arrays, so {} stays {} when written back.
		$attrs = json_decode( $m[3] );

		if ( ! is_object( $attrs ) ) {
			return array( $comment, 0 );
		}

		$count = 0;
		$attrs = self::replace_in_attrs( $attrs, $rule, $count, '' );

		if ( ! $count ) {
			return array( $comment, 0 );
		}

		return array( '<!-- wp:' . $m[1] . $m[2] . ' ' . serialize_block_attributes( $attrs ) . ' ' . ( empty( $m[4] ) ? '' : '/' ) . '-->', $count );
	}

	/**
	 * Walk block settings and replace in text values.
	 *
	 * @param mixed  $value Value.
	 * @param array  $rule  Rule.
	 * @param int    $count Replacements so far.
	 * @param string $key   Name of the setting holding the value.
	 * @return mixed
	 */
	private static function replace_in_attrs( $value, array $rule, &$count, $key ) {
		if ( is_string( $value ) ) {
			// Links and paths are left alone: web addresses, uploads folders, anchors.
			if ( in_array( $key, self::SKIP_ATTRS, true ) || preg_match( '~^(?:(?:https?|ftp|mailto|tel|data):|//|/|#|www\.)|^\S*/\S*$~i', $value ) ) {
				return $value;
			}
			list( $value, $n ) = self::replace_html( $value, $rule );
			$count            += $n;
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::replace_in_attrs( $v, $rule, $count, is_string( $k ) ? $k : $key );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				if ( '' !== (string) $k ) {
					$value->$k = self::replace_in_attrs( $v, $rule, $count, (string) $k );
				}
			}
		}

		return $value;
	}

	/**
	 * What a rule would change in a post.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $rule Rule.
	 * @return array{0: array, 1: array<string,int>} Change set, and replacements per field.
	 */
	public static function plan( WP_Post $post, array $rule ) {
		$changes = Schedule_Post_Page_Updates_Changes::empty_set();
		$counts  = array();
		$columns = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		);

		foreach ( $columns as $field => $column ) {
			if ( ! in_array( $field, $rule['fields'], true ) ) {
				continue;
			}

			$old                 = (string) $post->$column;
			list( $new, $count ) = 'content' === $field ? self::replace_html( $old, $rule ) : self::replace_plain( $old, $rule );

			if ( $count && $new !== $old ) {
				$changes['fields'][ $column ] = $new;
				$counts[ $field ]             = $count;
			}
		}

		if ( in_array( 'seo', $rule['fields'], true ) ) {
			foreach ( self::seo_keys() as $key ) {
				$values = Schedule_Post_Page_Updates_Changes::meta_values( $post->ID, $key );
				if ( null === $values ) {
					continue;
				}

				$changed = 0;
				foreach ( $values as $i => $raw ) {
					// Plain strings only; serialized values are left alone.
					if ( is_serialized( $raw ) ) {
						continue;
					}
					list( $new, $count ) = self::replace_plain( $raw, $rule );
					if ( $count && $new !== $raw ) {
						$values[ $i ] = $new;
						$changed     += $count;
					}
				}

				if ( $changed ) {
					$changes['meta'][ $key ] = $values;
					$counts['seo']           = ( isset( $counts['seo'] ) ? $counts['seo'] : 0 ) + $changed;
				}
			}
		}

		return array( $changes, $counts );
	}

	/**
	 * Posts a rule may change, after a given ID.
	 *
	 * For "every post that contains it", the database only narrows the list
	 * down; {@see plan()} makes the real decision.
	 *
	 * @param array $rule     Rule.
	 * @param int   $after_id Start after this post ID.
	 * @param int   $limit    Most to return.
	 * @return int[]
	 */
	public static function candidates( array $rule, $after_id, $limit ) {
		global $wpdb;

		if ( 'posts' === $rule['scope'] ) {
			$ids = array_filter(
				$rule['post_ids'],
				function ( $id ) use ( $after_id ) {
					return $id > $after_id;
				}
			);
			sort( $ids );
			return array_slice( $ids, 0, $limit );
		}

		$types = array_values( array_intersect( $rule['post_types'], Schedule_Post_Page_Updates_Plugin::post_types() ) );
		if ( ! $types || '' === $rule['find'] ) {
			return array();
		}

		$likes = array_unique( array( $rule['find'], esc_html( $rule['find'] ) ) );
		$or    = array();
		$args  = array();

		foreach ( $likes as $text ) {
			$like = '%' . $wpdb->esc_like( $text ) . '%';
			foreach ( array(
				'title'   => 'post_title',
				'content' => 'post_content',
				'excerpt' => 'post_excerpt',
			) as $field => $column ) {
				if ( in_array( $field, $rule['fields'], true ) ) {
					$or[]   = "{$column} LIKE %s";
					$args[] = $like;
				}
			}
			if ( in_array( 'seo', $rule['fields'], true ) && self::seo_keys() ) {
				$keys   = self::seo_keys();
				$or[]   = "ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') AND meta_value LIKE %s)';
				$args   = array_merge( $args, $keys );
				$args[] = $like;
			}
		}

		if ( ! $or ) {
			return array();
		}

		$sql = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d"
			. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')'
			. " AND post_status IN ('publish','private','future')"
			. ' AND (' . implode( ' OR ', $or ) . ')'
			. ' ORDER BY ID ASC LIMIT %d';

		$params = array_merge( array( absint( $after_id ) ), $types, $args, array( absint( $limit ) ) );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders only.
	}

	/**
	 * Whether a post can be changed by a rule at all.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private static function is_target( $post ) {
		return $post instanceof WP_Post
			&& in_array( $post->post_type, Schedule_Post_Page_Updates_Plugin::post_types(), true )
			&& ! in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit', Schedule_Post_Page_Updates_Copies::STATUS ), true )
			&& ! Schedule_Post_Page_Updates_Copies::is_copy( $post );
	}

	/**
	 * Preview a rule: the posts it would change and a few lines of context.
	 *
	 * @param array $rule    Rule (from {@see validate()}, date not needed).
	 * @param int   $user_id Whose permissions to use.
	 * @return array{posts: array, scanned: int, more: bool}
	 */
	public static function preview( array $rule, $user_id ) {
		$results = array();
		$scanned = 0;
		$after   = 0;
		$more    = false;
		$started = microtime( true );

		do {
			$ids   = self::candidates( $rule, $after, 50 );
			$found = count( $ids );

			foreach ( $ids as $id ) {
				$after = $id;
				++$scanned;

				$post = get_post( $id );
				if ( ! self::is_target( $post ) || ! user_can( $user_id, 'edit_post', $id ) ) {
					continue;
				}

				list( , $counts ) = self::plan( $post, $rule );
				if ( ! $counts ) {
					continue;
				}

				$results[] = array(
					'id'       => $post->ID,
					'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'type'     => self::type_label( $post->post_type ),
					'status'   => self::status_label( $post ),
					'edit'     => (string) get_edit_post_link( $post->ID, 'raw' ),
					'counts'   => $counts,
					'snippets' => self::snippets( $post, $rule ),
				);

				if ( count( $results ) >= 100 ) {
					$more = true;
					break 2;
				}
			}
		} while ( 50 === $found && microtime( true ) - $started < 8 );

		if ( 50 === $found && ! $more ) {
			$more = true;
		}

		return array(
			'posts'   => $results,
			'scanned' => $scanned,
			'more'    => $more,
		);
	}

	/**
	 * A few matches in context, as plain text pieces for the preview.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $rule Rule.
	 * @return array<int,array<string,string>>
	 */
	private static function snippets( WP_Post $post, array $rule ) {
		$sources = array();

		if ( in_array( 'title', $rule['fields'], true ) ) {
			$sources[ __( 'Title', 'schedule-post-page-updates' ) ] = html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' );
		}
		if ( in_array( 'content', $rule['fields'], true ) ) {
			// Only the readable text, the same parts replace_html() changes.
			$text = preg_replace( self::PROTECT, ' ', $post->post_content );
			$sources[ __( 'Content', 'schedule-post-page-updates' ) ] = html_entity_decode( preg_replace( '/\s+/u', ' ', (string) $text ), ENT_QUOTES, 'UTF-8' );
		}
		if ( in_array( 'excerpt', $rule['fields'], true ) ) {
			$sources[ __( 'Excerpt', 'schedule-post-page-updates' ) ] = html_entity_decode( $post->post_excerpt, ENT_QUOTES, 'UTF-8' );
		}
		if ( in_array( 'seo', $rule['fields'], true ) ) {
			foreach ( self::seo_keys() as $key ) {
				$value = get_post_meta( $post->ID, $key, true );
				if ( is_string( $value ) && '' !== $value ) {
					$sources[ __( 'SEO', 'schedule-post-page-updates' ) ] = $value;
					break;
				}
			}
		}

		$regex = self::regex( $rule['find'], $rule['match_case'], $rule['whole_words'] );
		$out   = array();

		foreach ( $sources as $label => $text ) {
			if ( ! preg_match_all( $regex, (string) $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( array_slice( $matches[0], 0, 2 ) as $match ) {
				$start = max( 0, $match[1] - 60 );
				$end   = $match[1] + strlen( $match[0] );

				$out[] = array(
					'field'   => $label,
					'before'  => ( $start > 0 ? '…' : '' ) . self::trim_utf8( substr( $text, $start, $match[1] - $start ), true ),
					'match'   => $match[0],
					'replace' => $rule['replace'],
					'after'   => self::trim_utf8( substr( $text, $end, 60 ), false ) . ( $end + 60 < strlen( $text ) ? '…' : '' ),
				);

				if ( count( $out ) >= 3 ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Drop broken multibyte characters left at the cut end of a byte slice.
	 *
	 * @param string $text     Slice.
	 * @param bool   $at_start Whether the cut is at the start.
	 * @return string
	 */
	private static function trim_utf8( $text, $at_start ) {
		$clean = wp_check_invalid_utf8( $text, true );

		return $at_start ? ltrim( $clean ) : rtrim( $clean );
	}

	/**
	 * Singular name of a post type.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	private static function type_label( $type ) {
		$object = get_post_type_object( $type );

		return $object ? $object->labels->singular_name : $type;
	}

	/**
	 * Name of a post's status, e.g. "Published".
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function status_label( WP_Post $post ) {
		$object = get_post_status_object( $post->post_status );

		return $object ? $object->label : $post->post_status;
	}

	/*
	 * Running
	 */

	/**
	 * Cron: run a rule, in batches, until every post is done or the time
	 * budget runs out (the next batch is then scheduled right away).
	 *
	 * @param int $rule_id   Rule ID.
	 * @param int $manual_by User running it by hand, 0 when on schedule.
	 * @return void
	 */
	public static function run( $rule_id, $manual_by = 0 ) {
		$rule_id = absint( $rule_id );

		if ( ! Schedule_Post_Page_Updates_Scheduler::lock( 'rule_' . $rule_id ) ) {
			return;
		}

		self::do_run( $rule_id, absint( $manual_by ) );

		Schedule_Post_Page_Updates_Scheduler::unlock( 'rule_' . $rule_id );
	}

	/**
	 * Run a rule right away, as the person clicking "Run now". A repeating
	 * rule still moves on to next year's date afterwards.
	 *
	 * @param int $rule_id Rule ID.
	 * @param int $user_id Who's running it.
	 * @return array|WP_Error The rule afterwards.
	 */
	public static function run_now( $rule_id, $user_id ) {
		$rule = self::get( $rule_id );

		if ( ! $rule ) {
			return new WP_Error( 'sppu_no_rule', __( 'That rule no longer exists.', 'schedule-post-page-updates' ) );
		}
		if ( 'running' === $rule['status'] ) {
			return new WP_Error( 'sppu_rule_running', __( 'This rule is already running.', 'schedule-post-page-updates' ) );
		}
		if ( 'scheduled' !== $rule['status'] ) {
			self::update( $rule_id, array( 'status' => 'scheduled' ) );
		}

		self::run( $rule_id, $user_id );

		return self::get( $rule_id );
	}

	/**
	 * The work of {@see run()}, inside the lock.
	 *
	 * @param int $rule_id   Rule ID.
	 * @param int $manual_by User running it by hand, or 0.
	 * @return void
	 */
	private static function do_run( $rule_id, $manual_by ) {
		$rule = self::get( $rule_id );

		if ( ! $rule || ! in_array( $rule['status'], array( 'scheduled', 'running' ), true ) ) {
			return;
		}

		if ( 'scheduled' === $rule['status'] ) {
			if ( ! $manual_by && $rule['run_at'] > time() + 30 ) {
				// Moved to a later time since the event was set.
				Schedule_Post_Page_Updates_Scheduler::schedule_rule( $rule_id, $rule['run_at'] );
				return;
			}

			$rule['status']        = 'running';
			$rule['cursor']        = 0;
			$rule['changed_count'] = 0;
			++$rule['run_count'];

			self::update(
				$rule_id,
				array(
					'status'        => 'running',
					'cursor_id'     => 0,
					'changed_count' => 0,
					'run_count'     => $rule['run_count'],
				)
			);
		}

		// A rule changes many posts at once, so only a site admin's rule runs,
		// and as that admin, so WordPress applies their content permissions.
		$actor = $manual_by ? $manual_by : $rule['created_by'];

		if ( ! $actor || ! user_can( $actor, 'manage_options' ) ) {
			self::finish( $rule, __( 'Stopped: the person who saved this rule is no longer an administrator. Edit and save it again to restart it.', 'schedule-post-page-updates' ), 'paused' );
			return;
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $actor );

		$started = time();

		do {
			$ids   = self::candidates( $rule, $rule['cursor'], self::BATCH );
			$found = count( $ids );

			foreach ( $ids as $id ) {
				$rule['cursor'] = $id;
				if ( self::apply_to_post( $id, $rule, $actor ) ) {
					++$rule['changed_count'];
				}
			}

			self::update(
				$rule_id,
				array(
					'cursor_id'     => $rule['cursor'],
					'changed_count' => $rule['changed_count'],
				)
			);
		} while ( self::BATCH === $found && time() - $started < self::TIME_BUDGET );

		wp_set_current_user( $previous_user );

		if ( self::BATCH === $found ) {
			// More to do: carry on in the next cron run.
			Schedule_Post_Page_Updates_Scheduler::schedule_rule( $rule_id, time() );
			return;
		}

		$count = $rule['changed_count'];
		self::finish(
			$rule,
			$count
				/* translators: %d: number of posts. */
				? sprintf( _n( 'Changed %d post.', 'Changed %d posts.', $count, 'schedule-post-page-updates' ), $count )
				: __( 'Nothing to change: no post had the text.', 'schedule-post-page-updates' )
		);
	}

	/**
	 * Apply a rule to one post and record it.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $rule    Rule.
	 * @param int   $actor   User the change is made as.
	 * @return bool Whether the post changed.
	 */
	private static function apply_to_post( $post_id, array $rule, $actor ) {
		$post = get_post( $post_id );

		if ( ! self::is_target( $post ) ) {
			return false;
		}

		list( $changes, $counts ) = self::plan( $post, $rule );

		if ( ! $counts ) {
			return false;
		}

		$log = array(
			'kind'       => Schedule_Post_Page_Updates_Log::KIND_REPLACE,
			'post_id'    => $post->ID,
			'rule_id'    => $rule['id'],
			'run'        => $rule['run_count'],
			'user_id'    => $actor,
			'post_title' => get_the_title( $post ),
			'summary'    => self::summary( $rule, $counts ),
		);

		if ( ! user_can( $actor, 'edit_post', $post->ID ) ) {
			$log['status']  = Schedule_Post_Page_Updates_Log::FAILED;
			$log['message'] = __( 'Skipped: the rule\'s owner can\'t edit this post.', 'schedule-post-page-updates' );
			Schedule_Post_Page_Updates_Log::add( $log );
			return false;
		}

		if ( post_type_supports( $post->post_type, 'revisions' ) ) {
			wp_save_post_revision( $post->ID );
		}

		$backup = Schedule_Post_Page_Updates_Changes::apply(
			$post->ID,
			$changes,
			array( 'touch_date' => 'update' === Schedule_Post_Page_Updates_Plugin::instance()->get_setting( 'publish_date' ) )
		);

		if ( is_wp_error( $backup ) ) {
			$log['status']  = Schedule_Post_Page_Updates_Log::FAILED;
			$log['message'] = $backup->get_error_message();
			Schedule_Post_Page_Updates_Log::add( $log );
			return false;
		}

		$log['status'] = Schedule_Post_Page_Updates_Log::APPLIED;
		$log['backup'] = $backup;
		Schedule_Post_Page_Updates_Log::add( $log );

		return true;
	}

	/**
	 * History summary of one post's changes, e.g.
	 * "“2026” → “2027”: 3 in content, 1 in title".
	 *
	 * @param array             $rule   Rule.
	 * @param array<string,int> $counts Replacements per field.
	 * @return string
	 */
	private static function summary( array $rule, array $counts ) {
		$names = array(
			'title'   => __( 'title', 'schedule-post-page-updates' ),
			'content' => __( 'content', 'schedule-post-page-updates' ),
			'excerpt' => __( 'excerpt', 'schedule-post-page-updates' ),
			'seo'     => __( 'SEO fields', 'schedule-post-page-updates' ),
		);
		$parts = array();

		foreach ( $counts as $field => $count ) {
			/* translators: 1: number of replacements, 2: where (title, content...). */
			$parts[] = sprintf( __( '%1$d in %2$s', 'schedule-post-page-updates' ), $count, $names[ $field ] );
		}

		/* translators: 1: text found, 2: replacement, 3: counts per field. */
		return sprintf( __( '“%1$s” → “%2$s”: %3$s', 'schedule-post-page-updates' ), $rule['find'], $rule['replace'], implode( ', ', $parts ) );
	}

	/**
	 * Wrap up a run: schedule next year's run for a repeating rule (with
	 * every year in its text moved on by one), or mark it done.
	 *
	 * @param array  $rule   Rule.
	 * @param string $result What happened.
	 * @param string $status Status to set instead of the usual one.
	 * @return void
	 */
	private static function finish( array $rule, $result, $status = '' ) {
		$data = array(
			'last_run'    => gmdate( 'Y-m-d H:i:s' ),
			'last_result' => $result,
			'cursor_id'   => 0,
		);

		if ( $status ) {
			$data['status'] = $status;
		} elseif ( $rule['repeat'] ) {
			$next = self::next_year( $rule['run_at'] );

			$data['status']       = 'scheduled';
			$data['find_text']    = self::bump_years( $rule['find'] );
			$data['replace_text'] = self::bump_years( $rule['replace'] );
			$data['name']         = self::bump_years( $rule['name'] );
			$data['run_at']       = gmdate( 'Y-m-d H:i:s', $next );

			Schedule_Post_Page_Updates_Scheduler::schedule_rule( $rule['id'], $next );
		} else {
			$data['status'] = 'done';
			Schedule_Post_Page_Updates_Scheduler::unschedule_rule( $rule['id'] );
		}

		self::update( $rule['id'], $data );

		if ( $status ) {
			Schedule_Post_Page_Updates_Scheduler::notify(
				'failed',
				/* translators: %s: rule name. */
				sprintf( __( 'Find and replace stopped: %s', 'schedule-post-page-updates' ), self::display_name( $rule ) ),
				array( $result, '', Schedule_Post_Page_Updates_Admin::url( 'replace' ) )
			);
			return;
		}

		$lines = array(
			/* translators: 1: text found, 2: replacement. */
			sprintf( __( 'Your scheduled find and replace of "%1$s" with "%2$s" has run.', 'schedule-post-page-updates' ), $rule['find'], $rule['replace'] ),
			'',
			$result,
		);
		if ( $rule['repeat'] ) {
			/* translators: 1: date, 2: text found, 3: replacement. */
			$lines[] = sprintf( __( 'Next run: %1$s, "%2$s" to "%3$s".', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Copies::format_time( self::next_year( $rule['run_at'] ) ), self::bump_years( $rule['find'] ), self::bump_years( $rule['replace'] ) );
		}
		/* translators: %s: URL. */
		$lines[] = sprintf( __( 'See or undo the changes: %s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Admin::url( 'history' ) );

		/* translators: %s: rule name. */
		Schedule_Post_Page_Updates_Scheduler::notify( 'applied', sprintf( __( 'Find and replace done: %s', 'schedule-post-page-updates' ), self::display_name( $rule ) ), $lines );
	}

	/**
	 * The same moment a year on, in the site's time zone, skipping years
	 * already past.
	 *
	 * @param int $timestamp Current run time.
	 * @return int
	 */
	public static function next_year( $timestamp ) {
		$date = new DateTime( '@' . (int) $timestamp );
		$date->setTimezone( wp_timezone() );

		do {
			$date->modify( '+1 year' );
		} while ( $date->getTimestamp() <= time() );

		return $date->getTimestamp();
	}

	/**
	 * A rule's name, or a description when it has none.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	public static function display_name( array $rule ) {
		if ( '' !== $rule['name'] ) {
			return $rule['name'];
		}

		/* translators: 1: text found, 2: replacement. */
		return sprintf( __( '“%1$s” → “%2$s”', 'schedule-post-page-updates' ), $rule['find'], $rule['replace'] );
	}

	/**
	 * Hourly: run rules whose event went missing, give future rules back
	 * their event.
	 *
	 * @return void
	 */
	public static function sweep() {
		foreach ( self::all() as $rule ) {
			if ( 'running' === $rule['status'] && ! wp_next_scheduled( Schedule_Post_Page_Updates_Scheduler::HOOK_RULE, array( $rule['id'] ) ) ) {
				self::run( $rule['id'] );
			} elseif ( 'scheduled' === $rule['status'] ) {
				if ( $rule['run_at'] <= time() - Schedule_Post_Page_Updates_Scheduler::GRACE ) {
					self::run( $rule['id'] );
				} elseif ( ! wp_next_scheduled( Schedule_Post_Page_Updates_Scheduler::HOOK_RULE, array( $rule['id'] ) ) ) {
					Schedule_Post_Page_Updates_Scheduler::schedule_rule( $rule['id'], $rule['run_at'] );
				}
			}
		}
	}

	/**
	 * On activation: reschedule rules; pause any that were due while the
	 * plugin was off.
	 *
	 * @return void
	 */
	public static function reactivate() {
		foreach ( self::all() as $rule ) {
			if ( 'running' === $rule['status'] || ( 'scheduled' === $rule['status'] && $rule['run_at'] <= time() ) ) {
				self::update(
					$rule['id'],
					array(
						'status'      => 'paused',
						'last_result' => __( 'Paused: its time passed while the plugin was switched off. Edit it to pick a new date.', 'schedule-post-page-updates' ),
					)
				);
			} elseif ( 'scheduled' === $rule['status'] ) {
				Schedule_Post_Page_Updates_Scheduler::schedule_rule( $rule['id'], $rule['run_at'] );
			}
		}
	}

	/**
	 * Undo the next few posts changed by one run of a rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @param int $run     Run number.
	 * @param int $user_id Who's undoing.
	 * @return array{undone: int, remaining: int}
	 */
	public static function undo_run( $rule_id, $run, $user_id ) {
		$undone = 0;

		foreach ( Schedule_Post_Page_Updates_Log::applied_in_run( $rule_id, $run, self::BATCH ) as $log_id ) {
			$result = Schedule_Post_Page_Updates_Admin::undo_entry( $log_id, $user_id );
			if ( ! is_wp_error( $result ) ) {
				++$undone;
			}
		}

		return array(
			'undone'    => $undone,
			'remaining' => count( Schedule_Post_Page_Updates_Log::applied_in_run( $rule_id, $run, 1 ) ),
		);
	}
}
