<?php
/**
 * Main plugin orchestrator: owns the settings and boots everything else.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Schedule_Post_Page_Updates_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'schedule_post_page_updates_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Schedule_Post_Page_Updates_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Schedule_Post_Page_Updates_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Schedule_Post_Page_Updates_Install::maybe_upgrade();
		Schedule_Post_Page_Updates_Copies::init();
		Schedule_Post_Page_Updates_Scheduler::init();

		if ( is_admin() ) {
			new Schedule_Post_Page_Updates_Admin( $this );
			Schedule_Post_Page_Updates_Editor::init();
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'post_types'   => array( 'post', 'page' ),
			'publish_date' => 'keep',
			'notify'       => 'failures',
			'notify_to'    => '',
			'history_days' => 365,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Post types that can be offered: public ones with an editing screen.
	 *
	 * @return WP_Post_Type[] Keyed by name.
	 */
	public static function available_post_types() {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		unset( $types['attachment'] );

		return $types;
	}

	/**
	 * Post types switched on in the settings that exist on this site.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$chosen = (array) self::instance()->get_setting( 'post_types' );
		$types  = array_values( array_intersect( $chosen, array_keys( self::available_post_types() ) ) );

		/**
		 * Filters the post types that get scheduled updates and find and replace.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_filter( (array) apply_filters( 'schedule_post_page_updates_post_types', $types ), 'is_string' ) );
	}

	/**
	 * Email addresses for alerts: the ones in the settings, or the site's admin email.
	 *
	 * @return string[]
	 */
	public function notify_recipients() {
		$list = array_filter( array_map( 'trim', explode( ',', (string) $this->get_setting( 'notify_to' ) ) ), 'is_email' );

		if ( ! $list ) {
			$admin = get_option( 'admin_email' );
			$list  = is_email( $admin ) ? array( $admin ) : array();
		}

		return array_values( $list );
	}
}
