<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 * On a multisite this covers every site.
 *
 * Scheduled updates (the private copies) are deleted too: without the
 * plugin nothing would ever apply them. Live posts are not touched, and
 * changes already made stay, along with their post revisions.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's tables, settings, copies and events for the current site.
 *
 * @return void
 */
function schedule_post_page_updates_uninstall_site() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own data on uninstall.
	$copies = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_sppu_original' ) );

	foreach ( array_map( 'intval', $copies ) as $copy_id ) {
		wp_delete_post( $copy_id, true );
	}

	foreach ( array( 'sppu_rules', 'sppu_log' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}

	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_sppu\\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sppu\\_lock\\_%' OR option_name LIKE '\\_transient\\_sppu\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sppu\\_%'" );
	// phpcs:enable

	foreach ( array( 'schedule_post_page_updates_settings', 'schedule_post_page_updates_db_version', 'sppu_last_prune', 'sppu_last_sweep' ) as $option ) {
		delete_option( $option );
	}

	wp_unschedule_hook( 'sppu_apply_update' );
	wp_unschedule_hook( 'sppu_run_rule' );
	wp_clear_scheduled_hook( 'sppu_sweep' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $schedule_post_page_updates_site ) {
		switch_to_blog( $schedule_post_page_updates_site );
		schedule_post_page_updates_uninstall_site();
		restore_current_blog();
	}
} else {
	schedule_post_page_updates_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-schedule-post-page-updates' );
