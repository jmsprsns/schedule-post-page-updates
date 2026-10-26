<?php
/**
 * Plugin Name:       Schedule Post and Page Updates
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Schedule changes to published posts and pages. Edit a copy that replaces the live version on the date you pick, or schedule a find and replace (2026 to 2027) that runs on its own, every year if you like.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       schedule-post-page-updates
 * Update URI:        https://github.com/jmsprsns/schedule-post-page-updates
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$schedule_post_page_updates_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'SCHEDULE_POST_PAGE_UPDATES_VERSION', $schedule_post_page_updates_headers['version'] );
define( 'SCHEDULE_POST_PAGE_UPDATES_REPO', $schedule_post_page_updates_headers['repo'] );
define( 'SCHEDULE_POST_PAGE_UPDATES_FILE', __FILE__ );
define( 'SCHEDULE_POST_PAGE_UPDATES_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCHEDULE_POST_PAGE_UPDATES_URL', plugin_dir_url( __FILE__ ) );

unset( $schedule_post_page_updates_headers );

require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-icons.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-updater.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-menu.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-feedback.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-install.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-log.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-changes.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-copies.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-replace.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-scheduler.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-editor.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-admin.php';
require_once SCHEDULE_POST_PAGE_UPDATES_DIR . 'includes/class-schedule-post-page-updates-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Schedule_Post_Page_Updates_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Schedule_Post_Page_Updates_Plugin
 */
function schedule_post_page_updates_plugin() {
	return Schedule_Post_Page_Updates_Plugin::instance();
}
add_action( 'plugins_loaded', 'schedule_post_page_updates_plugin' );

/**
 * Create the tables and put back scheduled events on activation.
 *
 * Network activation and new sites on a multisite are covered too: the
 * tables are created on each site's first request (see
 * Schedule_Post_Page_Updates_Install::maybe_upgrade()).
 *
 * @return void
 */
function schedule_post_page_updates_activate() {
	Schedule_Post_Page_Updates_Install::install();
	Schedule_Post_Page_Updates_Scheduler::reactivate();
}
register_activation_hook( SCHEDULE_POST_PAGE_UPDATES_FILE, 'schedule_post_page_updates_activate' );

/**
 * Stop every scheduled event when the plugin is deactivated, so nothing
 * changes posts while it's off. Scheduled updates and rules stay put
 * (uninstall.php removes them) and are rescheduled on reactivation.
 *
 * @return void
 */
function schedule_post_page_updates_deactivate() {
	Schedule_Post_Page_Updates_Scheduler::deactivate();
}
register_deactivation_hook( SCHEDULE_POST_PAGE_UPDATES_FILE, 'schedule_post_page_updates_deactivate' );
