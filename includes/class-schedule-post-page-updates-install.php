<?php
/**
 * Database tables: created on activation and upgraded when the schema changes.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema management.
 */
final class Schedule_Post_Page_Updates_Install {

	/**
	 * Bump when a table changes; dbDelta then runs on the next request.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'schedule_post_page_updates_db_version';

	/**
	 * Table names without the WordPress prefix.
	 */
	const TABLES = array( 'sppu_rules', 'sppu_log' );

	/**
	 * A table name with the site's prefix.
	 *
	 * @param string $name "rules" or "log".
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'sppu_' . $name;
	}

	/**
	 * Create or update the tables when the stored version is behind. Cheap:
	 * one autoloaded option read on most requests.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$rules   = self::table( 'rules' );
		$log     = self::table( 'log' );

		// Find and replace rules. Times are UTC. cursor_id is the last post ID
		// a run has finished with, so a big run can carry on in the next batch.
		dbDelta(
			"CREATE TABLE {$rules} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				find_text text NOT NULL,
				replace_text text NOT NULL,
				fields varchar(100) NOT NULL DEFAULT '',
				match_case tinyint(1) NOT NULL DEFAULT 1,
				whole_words tinyint(1) NOT NULL DEFAULT 1,
				scope varchar(10) NOT NULL DEFAULT 'posts',
				post_ids longtext NULL,
				post_types varchar(255) NOT NULL DEFAULT '',
				repeat_yearly tinyint(1) NOT NULL DEFAULT 0,
				run_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'scheduled',
				cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				run_count int(10) unsigned NOT NULL DEFAULT 0,
				changed_count int(10) unsigned NOT NULL DEFAULT 0,
				last_run datetime NULL,
				last_result text NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_run (status,run_at)
			) {$collate};"
		);

		// One row per post changed (or not changed, when it failed), with
		// what it held before, so the change can be undone.
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL DEFAULT 'update',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
				run int(10) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(10) NOT NULL DEFAULT 'applied',
				post_title text NULL,
				summary text NULL,
				message text NULL,
				backup longtext NULL,
				created_at datetime NOT NULL,
				undone_at datetime NULL,
				undone_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY post_id (post_id),
				KEY rule_run (rule_id,run)
			) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}
