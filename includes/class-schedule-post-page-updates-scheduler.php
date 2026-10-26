<?php
/**
 * Timing: WP-Cron events that apply scheduled updates and run find and
 * replace rules, an hourly sweep that catches anything a missed cron run
 * left behind, and email alerts.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling and applying.
 */
final class Schedule_Post_Page_Updates_Scheduler {

	/**
	 * Cron hook that applies one scheduled update (arg: copy ID).
	 */
	const HOOK_UPDATE = 'sppu_apply_update';

	/**
	 * Cron hook that runs one find and replace rule (arg: rule ID).
	 */
	const HOOK_RULE = 'sppu_run_rule';

	/**
	 * Hourly cron hook that catches up on anything overdue.
	 */
	const HOOK_SWEEP = 'sppu_sweep';

	/**
	 * Option: when history was last pruned.
	 */
	const OPTION_PRUNED = 'sppu_last_prune';

	/**
	 * Option: when the sweep last ran, to show that WP-Cron works.
	 */
	const OPTION_SWEPT = 'sppu_last_sweep';

	/**
	 * How late a change can be before the sweep applies it itself.
	 */
	const GRACE = 120;

	/**
	 * Hook everything in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_UPDATE, array( __CLASS__, 'run_update' ) );
		add_action( self::HOOK_RULE, array( 'Schedule_Post_Page_Updates_Replace', 'run' ) );
		add_action( self::HOOK_SWEEP, array( __CLASS__, 'sweep' ) );
		add_action( 'init', array( __CLASS__, 'ensure_sweep' ), 20 );
	}

	/**
	 * Make sure the hourly sweep is scheduled.
	 *
	 * @return void
	 */
	public static function ensure_sweep() {
		if ( ! wp_next_scheduled( self::HOOK_SWEEP ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK_SWEEP );
		}
	}

	/**
	 * Schedule (or reschedule) the event for one update.
	 *
	 * @param int $copy_id   Copy ID.
	 * @param int $timestamp When.
	 * @return void
	 */
	public static function schedule_update( $copy_id, $timestamp ) {
		self::unschedule_update( $copy_id );
		wp_schedule_single_event( (int) $timestamp, self::HOOK_UPDATE, array( absint( $copy_id ) ) );
	}

	/**
	 * Remove the event for one update.
	 *
	 * @param int $copy_id Copy ID.
	 * @return void
	 */
	public static function unschedule_update( $copy_id ) {
		wp_clear_scheduled_hook( self::HOOK_UPDATE, array( absint( $copy_id ) ) );
	}

	/**
	 * Schedule (or reschedule) the event for one rule.
	 *
	 * @param int $rule_id   Rule ID.
	 * @param int $timestamp When.
	 * @return void
	 */
	public static function schedule_rule( $rule_id, $timestamp ) {
		self::unschedule_rule( $rule_id );
		wp_schedule_single_event( (int) $timestamp, self::HOOK_RULE, array( absint( $rule_id ) ) );
	}

	/**
	 * Remove the event for one rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return void
	 */
	public static function unschedule_rule( $rule_id ) {
		wp_clear_scheduled_hook( self::HOOK_RULE, array( absint( $rule_id ) ) );
	}

	/**
	 * Cron: apply an update when its time has come.
	 *
	 * @param int $copy_id Copy ID.
	 * @return void
	 */
	public static function run_update( $copy_id ) {
		$copy_id = absint( $copy_id );
		$time    = Schedule_Post_Page_Updates_Copies::time( $copy_id );

		// Unscheduled since, or moved to a later time.
		if ( ! $time ) {
			return;
		}
		if ( $time > time() + 30 ) {
			self::schedule_update( $copy_id, $time );
			return;
		}

		self::apply_update( $copy_id );
	}

	/**
	 * Replace a live post with its scheduled update, record it in the
	 * history and delete the copy.
	 *
	 * On schedule, it runs as the person who last saved the copy, and only if
	 * everyone who scheduled or edited it can still edit the live post. On
	 * "Apply now", it runs as whoever clicked.
	 *
	 * @param int $copy_id   Copy ID.
	 * @param int $manual_by User applying it by hand, 0 when on schedule.
	 * @return int|WP_Error History row ID.
	 */
	public static function apply_update( $copy_id, $manual_by = 0 ) {
		$copy_id = absint( $copy_id );

		if ( ! self::lock( 'update_' . $copy_id ) ) {
			return new WP_Error( 'sppu_busy', __( 'This update is already being applied.', 'schedule-post-page-updates' ) );
		}

		$result = self::do_apply_update( $copy_id, absint( $manual_by ) );

		self::unlock( 'update_' . $copy_id );

		return $result;
	}

	/**
	 * The work of {@see apply_update()}, inside the lock.
	 *
	 * @param int $copy_id   Copy ID.
	 * @param int $manual_by User applying it by hand, or 0.
	 * @return int|WP_Error
	 */
	private static function do_apply_update( $copy_id, $manual_by ) {
		clean_post_cache( $copy_id );

		$copy = get_post( $copy_id );

		if ( ! $copy || Schedule_Post_Page_Updates_Copies::STATUS !== $copy->post_status ) {
			return new WP_Error( 'sppu_gone', __( 'The scheduled update no longer exists.', 'schedule-post-page-updates' ) );
		}

		$original_id = Schedule_Post_Page_Updates_Copies::original_id( $copy );
		$original    = $original_id ? get_post( $original_id ) : null;

		if ( ! $original ) {
			return self::fail_update( $copy, null, __( 'The live post no longer exists.', 'schedule-post-page-updates' ) );
		}
		if ( 'trash' === $original->post_status ) {
			return self::fail_update( $copy, $original, __( 'The live post is in the trash, so the update wasn\'t applied.', 'schedule-post-page-updates' ) );
		}

		if ( $manual_by ) {
			$actor  = $manual_by;
			$people = array( $manual_by );
		} else {
			$scheduled_by = absint( get_post_meta( $copy_id, Schedule_Post_Page_Updates_Copies::META_SCHEDULED_BY, true ) );
			$edited_by    = absint( get_post_meta( $copy_id, Schedule_Post_Page_Updates_Copies::META_EDITED_BY, true ) );
			$actor        = $edited_by ? $edited_by : $scheduled_by;
			$people       = array_unique( array_filter( array( $scheduled_by, $edited_by ) ) );
		}

		if ( ! $people ) {
			return self::fail_update( $copy, $original, __( 'Nobody is recorded as scheduling this update.', 'schedule-post-page-updates' ) );
		}

		foreach ( $people as $user_id ) {
			if ( ! user_can( $user_id, 'edit_post', $original->ID ) ) {
				$user = get_userdata( $user_id );
				return self::fail_update(
					$copy,
					$original,
					sprintf(
						/* translators: %s: user name. */
						__( '%s can no longer edit the live post, so their update wasn\'t applied.', 'schedule-post-page-updates' ),
						$user ? $user->display_name : __( 'A deleted user', 'schedule-post-page-updates' )
					)
				);
			}
		}

		$changes = Schedule_Post_Page_Updates_Copies::changes( $copy );

		/**
		 * Filters the changes a scheduled update is about to make.
		 *
		 * @param array   $changes  Change set (fields, terms, meta).
		 * @param WP_Post $original Live post.
		 * @param WP_Post $copy     Scheduled update.
		 */
		$changes = apply_filters( 'schedule_post_page_updates_changes', $changes, $original, $copy );

		$labels = Schedule_Post_Page_Updates_Changes::labels( $changes );

		// Keep the live version in the post's revisions too.
		if ( post_type_supports( $original->post_type, 'revisions' ) ) {
			wp_save_post_revision( $original->ID );
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $actor );

		$backup = Schedule_Post_Page_Updates_Changes::apply(
			$original->ID,
			$changes,
			array( 'touch_date' => 'update' === Schedule_Post_Page_Updates_Plugin::instance()->get_setting( 'publish_date' ) )
		);

		wp_set_current_user( $previous_user );

		if ( is_wp_error( $backup ) ) {
			return self::fail_update( $copy, $original, $backup->get_error_message() );
		}

		$log_id = Schedule_Post_Page_Updates_Log::add(
			array(
				'kind'       => Schedule_Post_Page_Updates_Log::KIND_UPDATE,
				'post_id'    => $original->ID,
				'user_id'    => $actor,
				'status'     => Schedule_Post_Page_Updates_Log::APPLIED,
				'post_title' => get_the_title( $original->ID ),
				'summary'    => Schedule_Post_Page_Updates_Changes::sentence( $labels ),
				'message'    => $manual_by ? __( 'Applied by hand.', 'schedule-post-page-updates' ) : '',
				'backup'     => $backup,
			)
		);

		wp_delete_post( $copy->ID, true );

		self::notify(
			'applied',
			/* translators: %s: post title. */
			sprintf( __( 'Scheduled update applied: %s', 'schedule-post-page-updates' ), get_the_title( $original->ID ) ),
			array(
				/* translators: %s: post title. */
				sprintf( __( 'The scheduled update to "%s" is now live.', 'schedule-post-page-updates' ), get_the_title( $original->ID ) ),
				'',
				/* translators: %s: list of changed parts. */
				sprintf( __( 'Changed: %s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Changes::sentence( $labels ) ),
				/* translators: %s: URL. */
				sprintf( __( 'View it: %s', 'schedule-post-page-updates' ), get_permalink( $original->ID ) ),
				/* translators: %s: URL. */
				sprintf( __( 'Undo it from the history: %s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Admin::url( 'history' ) ),
			)
		);

		/**
		 * Fires after a scheduled update has replaced its live post.
		 *
		 * @param int   $post_id Live post ID.
		 * @param array $changes Change set that was applied.
		 * @param int   $log_id  History row ID.
		 */
		do_action( 'schedule_post_page_updates_applied', $original->ID, $changes, $log_id );

		return $log_id;
	}

	/**
	 * Record a failed update: unschedule it so it doesn't retry every hour,
	 * keep the copy for the user to fix, log it and send an alert.
	 *
	 * @param WP_Post      $copy     Copy.
	 * @param WP_Post|null $original Live post, if it exists.
	 * @param string       $message  Why.
	 * @return WP_Error
	 */
	private static function fail_update( WP_Post $copy, $original, $message ) {
		Schedule_Post_Page_Updates_Copies::clear_time( $copy->ID );
		update_post_meta( $copy->ID, Schedule_Post_Page_Updates_Copies::META_ERROR, $message );

		$title = $original ? get_the_title( $original->ID ) : get_the_title( $copy->ID );

		Schedule_Post_Page_Updates_Log::add(
			array(
				'kind'       => Schedule_Post_Page_Updates_Log::KIND_UPDATE,
				'post_id'    => $original ? $original->ID : 0,
				'status'     => Schedule_Post_Page_Updates_Log::FAILED,
				'post_title' => $title,
				'message'    => $message,
			)
		);

		self::notify(
			'failed',
			/* translators: %s: post title. */
			sprintf( __( 'Scheduled update not applied: %s', 'schedule-post-page-updates' ), $title ),
			array(
				/* translators: %s: post title. */
				sprintf( __( 'The scheduled update to "%s" could not be applied.', 'schedule-post-page-updates' ), $title ),
				'',
				$message,
				'',
				/* translators: %s: URL. */
				sprintf( __( 'The update is kept as a draft. Review it here: %s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Admin::url( 'updates' ) ),
			)
		);

		return new WP_Error( 'sppu_failed', $message );
	}

	/**
	 * Hourly: apply anything overdue (a missed or late cron event), give
	 * future items back an event if theirs went missing, and prune history.
	 *
	 * @return void
	 */
	public static function sweep() {
		update_option( self::OPTION_SWEPT, time(), false );

		$applied = 0;

		foreach ( Schedule_Post_Page_Updates_Copies::scheduled() as $copy ) {
			$time = Schedule_Post_Page_Updates_Copies::time( $copy->ID );

			if ( $time <= time() - self::GRACE ) {
				if ( $applied < 10 ) {
					self::apply_update( $copy->ID );
					++$applied;
				}
			} elseif ( ! wp_next_scheduled( self::HOOK_UPDATE, array( $copy->ID ) ) ) {
				self::schedule_update( $copy->ID, $time );
			}
		}

		Schedule_Post_Page_Updates_Replace::sweep();

		$last = (int) get_option( self::OPTION_PRUNED, 0 );
		if ( $last < time() - DAY_IN_SECONDS ) {
			update_option( self::OPTION_PRUNED, time(), false );
			Schedule_Post_Page_Updates_Log::prune( (int) Schedule_Post_Page_Updates_Plugin::instance()->get_setting( 'history_days' ) );
		}
	}

	/**
	 * On deactivation: stop every event, so nothing changes while it's off.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_unschedule_hook( self::HOOK_UPDATE );
		wp_unschedule_hook( self::HOOK_RULE );
		wp_clear_scheduled_hook( self::HOOK_SWEEP );
	}

	/**
	 * On activation: put the events back. Anything whose time passed while
	 * the plugin was off is unscheduled with a note instead of being applied
	 * late, possibly months after it was meant to.
	 *
	 * @return void
	 */
	public static function reactivate() {
		Schedule_Post_Page_Updates_Copies::register_status();

		foreach ( Schedule_Post_Page_Updates_Copies::scheduled() as $copy ) {
			$time = Schedule_Post_Page_Updates_Copies::time( $copy->ID );

			if ( $time <= time() ) {
				Schedule_Post_Page_Updates_Copies::clear_time( $copy->ID );
				update_post_meta( $copy->ID, Schedule_Post_Page_Updates_Copies::META_ERROR, __( 'Its time passed while the plugin was switched off, so it wasn\'t applied. Pick a new time.', 'schedule-post-page-updates' ) );
			} else {
				self::schedule_update( $copy->ID, $time );
			}
		}

		Schedule_Post_Page_Updates_Replace::reactivate();

		self::ensure_sweep();
	}

	/**
	 * Take a lock. Uses INSERT IGNORE on the options table, which is atomic,
	 * unlike add_option(). A lock older than ten minutes is treated as left
	 * behind by a crashed request.
	 *
	 * @param string $name Lock name.
	 * @return bool Whether it was taken.
	 */
	public static function lock( $name ) {
		global $wpdb;

		$key = 'sppu_lock_' . $name;
		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Atomic lock row.
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) $now ) ) ) {
			return true;
		}

		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );

		if ( $since && $since < $now - 10 * MINUTE_IN_SECONDS ) {
			return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $key, (string) $since ) );
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function unlock( $name ) {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => 'sppu_lock_' . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Email an alert, if the settings ask for it.
	 *
	 * @param string   $type    "applied" or "failed".
	 * @param string   $subject Subject, without the site name.
	 * @param string[] $lines   Body lines.
	 * @return void
	 */
	public static function notify( $type, $subject, array $lines ) {
		$plugin = Schedule_Post_Page_Updates_Plugin::instance();
		$when   = $plugin->get_setting( 'notify' );

		if ( 'none' === $when || ( 'applied' === $type && 'all' !== $when ) ) {
			return;
		}

		$to = $plugin->notify_recipients();
		if ( ! $to ) {
			return;
		}

		$lines[] = '';
		/* translators: %s: settings page URL. */
		$lines[] = sprintf( __( 'Change these emails: %s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Admin::url( 'settings' ) );

		wp_mail( $to, sprintf( '[%1$s] %2$s', self::plain( get_bloginfo( 'name' ) ), self::plain( $subject ) ), self::plain( implode( "\n", $lines ) ) );
	}

	/**
	 * Text for a plain-text email: titles come with HTML entities (curly
	 * quotes, &amp;) that would show up literally.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( $text ) {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}
}
