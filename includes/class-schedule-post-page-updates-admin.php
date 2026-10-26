<?php
/**
 * Admin side: the Content Powered > Schedule Post and Page Updates page
 * (overview, scheduled updates, find and replace, history, settings) and the
 * Plugins-screen link.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Schedule_Post_Page_Updates_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'schedule-post-page-updates';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'calendar-clock';

	/**
	 * Tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview' => 'layout-dashboard',
		'updates'  => 'calendar-sync',
		'replace'  => 'replace',
		'history'  => 'history',
		'settings' => 'sliders-horizontal',
	);

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'sppu_admin';

	/**
	 * History rows per page.
	 */
	const PER_PAGE = 30;

	/**
	 * Plugin instance.
	 *
	 * @var Schedule_Post_Page_Updates_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Schedule_Post_Page_Updates_Plugin $plugin Plugin instance.
	 */
	public function __construct( Schedule_Post_Page_Updates_Plugin $plugin ) {
		$this->plugin = $plugin;

		Schedule_Post_Page_Updates_Menu::init();
		Schedule_Post_Page_Updates_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		add_action( 'admin_post_sppu_save_rule', array( $this, 'handle_save_rule' ) );
		add_action( 'admin_post_sppu_rule_action', array( $this, 'handle_rule_action' ) );
		add_action( 'admin_post_sppu_undo', array( $this, 'handle_undo' ) );
		add_action( 'wp_ajax_sppu_search_posts', array( $this, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_sppu_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_sppu_undo_run', array( $this, 'ajax_undo_run' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( SCHEDULE_POST_PAGE_UPDATES_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * URL of a tab on the page.
	 *
	 * @param string               $tab  Tab ID.
	 * @param array<string,scalar> $args Extra query args.
	 * @return string Raw URL; escape before printing.
	 */
	public static function url( $tab = 'overview', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of a new find and replace, filled in for this year's year update.
	 *
	 * @return string Raw URL; escape before printing.
	 */
	private static function year_preset_url() {
		return self::url(
			'replace',
			array(
				'edit'   => 'new',
				'preset' => 'year',
			)
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'schedule-post-page-updates' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Schedule_Post_Page_Updates_Icons::get( self::ICON, 17, 'schedule-post-page-updates-menu-icon' );
		$menu_title = $icon . '<span class="schedule-post-page-updates-menu-label">' . esc_html__( 'Schedule Post and Page Updates', 'schedule-post-page-updates' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Schedule_Post_Page_Updates_Menu::SLUG,
			__( 'Schedule Post and Page Updates', 'schedule-post-page-updates' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' sppu-settings-page' : $classes;
	}

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Schedule_Post_Page_Updates_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Schedule_Post_Page_Updates_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in        = is_array( $input ) ? $input : array();
		$available = array_keys( Schedule_Post_Page_Updates_Plugin::available_post_types() );
		$types     = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_values( array_intersect( $available, $in['post_types'] ) ) : array();
		$emails    = isset( $in['notify_to'] ) && is_string( $in['notify_to'] ) ? array_filter( array_map( 'sanitize_email', explode( ',', $in['notify_to'] ) ), 'is_email' ) : array();
		$days      = isset( $in['history_days'] ) && is_numeric( $in['history_days'] ) ? (int) $in['history_days'] : 365;

		return array(
			'post_types'   => $types,
			'publish_date' => isset( $in['publish_date'] ) && 'update' === $in['publish_date'] ? 'update' : 'keep',
			'notify'       => isset( $in['notify'] ) && in_array( $in['notify'], array( 'failures', 'all', 'none' ), true ) ? $in['notify'] : 'failures',
			'notify_to'    => implode( ', ', array_slice( array_unique( $emails ), 0, 10 ) ),
			'history_days' => max( 0, min( 3650, $days ) ),
		);
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="schedule-post-page-updates-menu-icon-style">'
			. '#adminmenu a:has(>.schedule-post-page-updates-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .schedule-post-page-updates-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .schedule-post-page-updates-menu-icon,#adminmenu a:hover .schedule-post-page-updates-menu-icon,#adminmenu a:focus .schedule-post-page-updates-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'schedule-post-page-updates-admin', SCHEDULE_POST_PAGE_UPDATES_URL . 'assets/admin.css', array(), SCHEDULE_POST_PAGE_UPDATES_VERSION );
		wp_enqueue_script( 'schedule-post-page-updates-admin', SCHEDULE_POST_PAGE_UPDATES_URL . 'assets/admin.js', array(), SCHEDULE_POST_PAGE_UPDATES_VERSION, true );

		wp_localize_script(
			'schedule-post-page-updates-admin',
			'schedule_post_page_updates_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'searching'   => __( 'Searching…', 'schedule-post-page-updates' ),
					'noResults'   => __( 'No posts found.', 'schedule-post-page-updates' ),
					'schedule'    => __( 'Schedule Update', 'schedule-post-page-updates' ),
					'add'         => __( 'Add', 'schedule-post-page-updates' ),
					'added'       => __( 'Added', 'schedule-post-page-updates' ),
					'remove'      => __( 'Remove', 'schedule-post-page-updates' ),
					'notEligible' => __( 'Only published or private posts can get a scheduled update.', 'schedule-post-page-updates' ),
					'previewing'  => __( 'Looking for matches…', 'schedule-post-page-updates' ),
					/* translators: %d: number of posts. */
					'previewOne'  => __( '%d post would change.', 'schedule-post-page-updates' ),
					/* translators: %d: number of posts. */
					'previewMany' => __( '%d posts would change.', 'schedule-post-page-updates' ),
					'previewNone' => __( 'Nothing matches right now. Check the text, the options and the posts you picked.', 'schedule-post-page-updates' ),
					'previewMore' => __( 'Showing the first matches only.', 'schedule-post-page-updates' ),
					'edit'        => __( 'Edit', 'schedule-post-page-updates' ),
					'error'       => __( 'Something went wrong. Please try again.', 'schedule-post-page-updates' ),
					'noYear'      => __( 'Add a year (like 2026) to the text to find, so there is something to count up.', 'schedule-post-page-updates' ),
					'undoing'     => __( 'Undoing…', 'schedule-post-page-updates' ),
					/* translators: %d: number of posts. */
					'undone'      => __( 'Undone on %d posts.', 'schedule-post-page-updates' ),
					'undoConfirm' => __( 'Undo the changes this run made? Parts edited since are left alone.', 'schedule-post-page-updates' ),
					'fields'      => array(
						'title'   => __( 'title', 'schedule-post-page-updates' ),
						'content' => __( 'content', 'schedule-post-page-updates' ),
						'excerpt' => __( 'excerpt', 'schedule-post-page-updates' ),
						'seo'     => __( 'SEO', 'schedule-post-page-updates' ),
					),
				),
			)
		);
	}

	/**
	 * The tab being shown.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'overview';
	}

	/**
	 * Label of a tab.
	 *
	 * @param string $tab Tab ID.
	 * @return string
	 */
	private function tab_label( $tab ) {
		$labels = array(
			'overview' => __( 'Overview', 'schedule-post-page-updates' ),
			'updates'  => __( 'Scheduled Updates', 'schedule-post-page-updates' ),
			'replace'  => __( 'Find & Replace', 'schedule-post-page-updates' ),
			'history'  => __( 'History', 'schedule-post-page-updates' ),
			'settings' => __( 'Settings', 'schedule-post-page-updates' ),
		);

		return $labels[ $tab ];
	}

	/**
	 * Inline Lucide icon.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Schedule_Post_Page_Updates_Icons::get( $name, $size );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = $this->current_tab();
		?>
		<div class="sppu-header">
			<div class="sppu-header-title">
				<span class="sppu-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="sppu-version"><?php echo esc_html( 'v' . SCHEDULE_POST_PAGE_UPDATES_VERSION ); ?></span>
			</div>
			<nav class="sppu-tabs" aria-label="<?php esc_attr_e( 'Sections', 'schedule-post-page-updates' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="sppu-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap sppu-wrap">
			<hr class="wp-header-end">

			<?php
			if ( 'settings' === $tab ) {
				// Pages outside the Settings menu have to show "Settings saved." themselves.
				settings_errors();
			}

			$this->render_message();
			?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						switch ( $tab ) {
							case 'updates':
								$this->render_updates();
								break;
							case 'replace':
								$this->render_replace();
								break;
							case 'history':
								$this->render_history();
								break;
							case 'settings':
								$this->render_settings();
								break;
							default:
								$this->render_overview();
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="sppu-sidebar">
							<?php $this->render_sidebar( $tab ); ?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * Notice after an action on this page.
	 *
	 * @return void
	 */
	private function render_message() {
		$key = isset( $_GET['sppu_msg'] ) ? sanitize_key( wp_unslash( $_GET['sppu_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( '' === $key ) {
			return;
		}

		$messages = array(
			'rule_saved'   => array( 'success', __( 'Find and replace saved and scheduled.', 'schedule-post-page-updates' ) ),
			'rule_deleted' => array( 'success', __( 'Find and replace deleted. Changes it already made stay in the history.', 'schedule-post-page-updates' ) ),
			'rule_paused'  => array( 'success', __( 'Find and replace paused.', 'schedule-post-page-updates' ) ),
			'rule_resumed' => array( 'success', __( 'Find and replace resumed.', 'schedule-post-page-updates' ) ),
			'rule_ran'     => array( 'success', __( 'Find and replace ran. The result is shown in its row.', 'schedule-post-page-updates' ) ),
			'undone'       => array( 'success', __( 'Change undone.', 'schedule-post-page-updates' ) ),
			'applied'      => array( 'success', __( 'The scheduled update is now live.', 'schedule-post-page-updates' ) ),
			'unscheduled'  => array( 'success', __( 'Update unscheduled. It\'s kept as a draft until you pick a new time.', 'schedule-post-page-updates' ) ),
			'failed'       => array( 'error', __( 'The update could not be applied. The reason is shown in its row.', 'schedule-post-page-updates' ) ),
		);

		$error = get_transient( 'sppu_notice_' . get_current_user_id() );

		if ( 'error' === $key && is_string( $error ) && '' !== $error ) {
			delete_transient( 'sppu_notice_' . get_current_user_id() );
			$messages['error'] = array( 'error', $error );
		}

		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	/**
	 * Redirect back to a tab with a message, or an error message.
	 *
	 * @param string               $tab     Tab.
	 * @param string               $message Message key, or the text of an error.
	 * @param bool                 $error   Whether $message is an error text.
	 * @param array<string,scalar> $args    Extra query args.
	 * @return void
	 */
	private function redirect( $tab, $message, $error = false, array $args = array() ) {
		if ( $error ) {
			set_transient( 'sppu_notice_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
			$message = 'error';
		}

		wp_safe_redirect( self::url( $tab, array_merge( $args, array( 'sppu_msg' => $message ) ) ) );
		exit;
	}

	/*
	 * Shared pieces
	 */

	/**
	 * Start a box.
	 *
	 * @param string $icon      Lucide icon.
	 * @param string $title     Title.
	 * @param string $box_class Extra class.
	 * @param string $extra     HTML for the right of the header (already escaped).
	 * @return void
	 */
	private static function box_open( $icon, $title, $box_class = '', $extra = '' ) {
		?>
		<div class="postbox sppu-box <?php echo esc_attr( $box_class ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>
			</div>
			<div class="inside">
		<?php
	}

	/**
	 * End a box.
	 *
	 * @return void
	 */
	private static function box_close() {
		echo '</div></div>';
	}

	/**
	 * A status pill.
	 *
	 * @param string $status Status key.
	 * @return string HTML.
	 */
	private static function pill( $status ) {
		$labels = array(
			'scheduled' => __( 'Scheduled', 'schedule-post-page-updates' ),
			'running'   => __( 'Running', 'schedule-post-page-updates' ),
			'draft'     => __( 'No date yet', 'schedule-post-page-updates' ),
			'overdue'   => __( 'Overdue', 'schedule-post-page-updates' ),
			'error'     => __( 'Needs attention', 'schedule-post-page-updates' ),
			'paused'    => __( 'Paused', 'schedule-post-page-updates' ),
			'done'      => __( 'Done', 'schedule-post-page-updates' ),
			'applied'   => __( 'Applied', 'schedule-post-page-updates' ),
			'undone'    => __( 'Undone', 'schedule-post-page-updates' ),
			'failed'    => __( 'Failed', 'schedule-post-page-updates' ),
		);

		return sprintf( '<span class="sppu-pill is-%1$s">%2$s</span>', esc_attr( $status ), esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) );
	}

	/**
	 * Date and relative time, for a table cell.
	 *
	 * @param int $time Unix time.
	 * @return string HTML.
	 */
	private static function when( $time ) {
		if ( ! $time ) {
			return '<span class="sppu-muted">' . esc_html__( 'Not scheduled', 'schedule-post-page-updates' ) . '</span>';
		}

		return sprintf(
			'<strong>%1$s</strong><span class="sppu-sub">%2$s</span>',
			esc_html( Schedule_Post_Page_Updates_Copies::format_time( $time ) ),
			esc_html( Schedule_Post_Page_Updates_Editor::relative( $time ) )
		);
	}

	/**
	 * Name of a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function user_name( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;

		return $user ? $user->display_name : '—';
	}

	/**
	 * Every scheduled update with its details, soonest first, undated last.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function updates() {
		$rows = array();

		foreach ( Schedule_Post_Page_Updates_Copies::all() as $copy ) {
			$info            = Schedule_Post_Page_Updates_Copies::info( $copy );
			$info['copy']    = $copy;
			$info['changes'] = Schedule_Post_Page_Updates_Changes::labels( Schedule_Post_Page_Updates_Copies::changes( $copy ) );
			$rows[]          = $info;
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( ! $a['time'] || ! $b['time'] ) {
					return $a['time'] ? -1 : ( $b['time'] ? 1 : $b['id'] - $a['id'] );
				}
				return $a['time'] - $b['time'];
			}
		);

		return $rows;
	}

	/**
	 * Fields a rule changes, as words.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function rule_fields( array $rule ) {
		$names = array(
			'title'   => __( 'titles', 'schedule-post-page-updates' ),
			'content' => __( 'content', 'schedule-post-page-updates' ),
			'excerpt' => __( 'excerpts', 'schedule-post-page-updates' ),
			'seo'     => __( 'SEO fields', 'schedule-post-page-updates' ),
		);
		$words = array();

		foreach ( $rule['fields'] as $field ) {
			$words[] = $names[ $field ];
		}

		return wp_sprintf( '%l', $words );
	}

	/**
	 * Which posts a rule covers, as words.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function rule_scope( array $rule ) {
		if ( 'posts' === $rule['scope'] ) {
			$count = count( $rule['post_ids'] );
			/* translators: %d: number of posts. */
			return sprintf( _n( '%d chosen post', '%d chosen posts', $count, 'schedule-post-page-updates' ), $count );
		}

		$names = array();
		foreach ( $rule['post_types'] as $type ) {
			$object  = get_post_type_object( $type );
			$names[] = $object ? $object->labels->name : $type;
		}

		/* translators: %s: post types, e.g. "Posts and Pages". */
		return sprintf( __( 'Any of the %s that contain it', 'schedule-post-page-updates' ), wp_sprintf( '%l', $names ) );
	}

	/*
	 * Overview
	 */

	/**
	 * Overview tab.
	 *
	 * @return void
	 */
	private function render_overview() {
		$updates   = $this->updates();
		$rules     = Schedule_Post_Page_Updates_Replace::all();
		$scheduled = array_filter(
			$updates,
			function ( $row ) {
				return in_array( $row['status'], array( 'scheduled', 'overdue' ), true );
			}
		);
		$active    = array_filter(
			$rules,
			function ( $rule ) {
				return in_array( $rule['status'], array( 'scheduled', 'running' ), true );
			}
		);
		$attention = count(
			array_filter(
				$updates,
				function ( $row ) {
					return in_array( $row['status'], array( 'error', 'overdue' ), true );
				}
			)
		) + count(
			array_filter(
				$rules,
				function ( $rule ) {
					return 'paused' === $rule['status'] && '' !== $rule['last_result'];
				}
			)
		);

		$stats = array(
			array( 'calendar-sync', count( $scheduled ), __( 'Scheduled updates', 'schedule-post-page-updates' ), '' ),
			array( 'replace', count( $active ), __( 'Find & replace rules', 'schedule-post-page-updates' ), '' ),
			array( 'circle-check', Schedule_Post_Page_Updates_Log::count_recent( Schedule_Post_Page_Updates_Log::APPLIED, 30 ), __( 'Changes in the last 30 days', 'schedule-post-page-updates' ), 'is-good' ),
			array( 'triangle-alert', $attention, __( 'Need attention', 'schedule-post-page-updates' ), $attention ? 'is-bad' : '' ),
		);
		?>
		<div class="sppu-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="sppu-stat <?php echo esc_attr( $stat[3] ); ?>">
					<span class="sppu-stat-icon"><?php echo self::icon( $stat[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="sppu-stat-num"><?php echo esc_html( number_format_i18n( $stat[1] ) ); ?></span>
					<span class="sppu-stat-label"><?php echo esc_html( $stat[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		// Everything coming up, both kinds, soonest first.
		$coming = array();
		foreach ( $scheduled as $row ) {
			$coming[] = array(
				'time' => $row['time'],
				'kind' => 'update',
				'row'  => $row,
			);
		}
		foreach ( $active as $rule ) {
			$coming[] = array(
				'time' => $rule['run_at'],
				'kind' => 'rule',
				'row'  => $rule,
			);
		}
		usort(
			$coming,
			function ( $a, $b ) {
				return $a['time'] - $b['time'];
			}
		);

		self::box_open( 'calendar-days', __( 'Coming up', 'schedule-post-page-updates' ) );

		if ( ! $coming ) :
			?>
			<div class="sppu-empty">
				<span class="sppu-empty-icon"><?php echo self::icon( 'calendar-check', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<p><strong><?php esc_html_e( 'Nothing scheduled yet.', 'schedule-post-page-updates' ); ?></strong></p>
				<p><?php esc_html_e( 'Schedule an edited version of a post below, or set up a find and replace that runs on a date.', 'schedule-post-page-updates' ); ?></p>
			</div>
			<?php
		else :
			?>
			<ul class="sppu-timeline">
				<?php foreach ( array_slice( $coming, 0, 10 ) as $item ) : ?>
					<?php $this->render_timeline_item( $item ); ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $coming ) > 10 ) : ?>
				<p class="sppu-more">
					<a href="<?php echo esc_url( self::url( 'updates' ) ); ?>"><?php esc_html_e( 'See all scheduled updates', 'schedule-post-page-updates' ); ?></a> ·
					<a href="<?php echo esc_url( self::url( 'replace' ) ); ?>"><?php esc_html_e( 'See all find & replace rules', 'schedule-post-page-updates' ); ?></a>
				</p>
			<?php endif; ?>
			<?php
		endif;

		self::box_close();

		self::box_open( 'file-pen-line', __( 'Schedule an update to a post or page', 'schedule-post-page-updates' ) );
		?>
		<p class="sppu-intro"><?php esc_html_e( 'Make your changes in a private copy and pick when they go live. Until then, visitors see the current version. Find a post to start:', 'schedule-post-page-updates' ); ?></p>
		<div class="sppu-search" data-sppu-search="schedule">
			<span class="sppu-search-field">
				<?php echo self::icon( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<input type="search" class="regular-text" placeholder="<?php esc_attr_e( 'Search posts and pages by title…', 'schedule-post-page-updates' ); ?>" aria-label="<?php esc_attr_e( 'Search posts and pages', 'schedule-post-page-updates' ); ?>" autocomplete="off">
			</span>
			<ul class="sppu-search-results" aria-live="polite"></ul>
		</div>
		<p class="description"><?php esc_html_e( 'You can also hover over any published post in Posts or Pages and click Schedule Update, or use the Scheduled Updates panel in the editor.', 'schedule-post-page-updates' ); ?></p>
		<?php
		self::box_close();

		self::box_open( 'repeat', __( 'Keep the year in your posts current', 'schedule-post-page-updates' ) );
		$year = (int) wp_date( 'Y' );
		?>
		<div class="sppu-feature">
			<div class="sppu-feature-text">
				<p>
					<?php
					printf(
						/* translators: 1: this year, 2: next year. */
						esc_html__( 'Posts like “Best tools for %1$s” go stale on January 1st. Set up a find and replace once: it changes %1$s to %2$s in the posts you pick on New Year’s Day, then %2$s to %3$s the year after, and so on.', 'schedule-post-page-updates' ),
						(int) $year,
						(int) $year + 1,
						(int) $year + 2
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::year_preset_url() ); ?>"><?php echo self::icon( 'calendar-plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Set up a yearly update', 'schedule-post-page-updates' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::url( 'replace', array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Schedule any find and replace', 'schedule-post-page-updates' ); ?></a>
				</p>
			</div>
			<div class="sppu-year-flip" aria-hidden="true">
				<span><?php echo esc_html( $year ); ?></span>
				<?php echo self::icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span class="is-next"><?php echo esc_html( $year + 1 ); ?></span>
			</div>
		</div>
		<?php
		self::box_close();
	}

	/**
	 * One line of the "Coming up" list.
	 *
	 * @param array $item Item: time, kind, row.
	 * @return void
	 */
	private function render_timeline_item( array $item ) {
		$row = $item['row'];

		if ( 'update' === $item['kind'] ) {
			$copy     = $row['copy'];
			$original = $row['original'];
			$title    = $original ? get_the_title( $original ) : get_the_title( $copy );
			$detail   = Schedule_Post_Page_Updates_Changes::sentence( $row['changes'] );
			$icon     = 'file-pen-line';
			$kind     = __( 'Scheduled update', 'schedule-post-page-updates' );
			$links    = array(
				array( get_edit_post_link( $copy->ID ), __( 'Edit', 'schedule-post-page-updates' ) ),
				array( get_preview_post_link( $copy ), __( 'Preview', 'schedule-post-page-updates' ) ),
			);
		} else {
			$title  = Schedule_Post_Page_Updates_Replace::display_name( $row );
			$detail = self::rule_scope( $row ) . ' · ' . self::rule_fields( $row );
			$icon   = 'replace';
			$kind   = $row['repeat'] ? __( 'Find & replace, every year', 'schedule-post-page-updates' ) : __( 'Find & replace', 'schedule-post-page-updates' );
			$links  = array(
				array( self::url( 'replace', array( 'edit' => $row['id'] ) ), __( 'Edit', 'schedule-post-page-updates' ) ),
			);
		}
		?>
		<li class="sppu-timeline-item<?php echo 'overdue' === ( isset( $row['status'] ) ? $row['status'] : '' ) ? ' is-overdue' : ''; ?>">
			<span class="sppu-timeline-icon"><?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span class="sppu-timeline-main">
				<span class="sppu-timeline-kind"><?php echo esc_html( $kind ); ?></span>
				<strong class="sppu-timeline-title"><?php echo esc_html( wp_strip_all_tags( $title ) ); ?></strong>
				<span class="sppu-sub"><?php echo esc_html( $detail ); ?></span>
			</span>
			<span class="sppu-timeline-when"><?php echo self::when( $item['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?></span>
			<span class="sppu-timeline-links">
				<?php foreach ( $links as $link ) : ?>
					<?php if ( $link[0] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $link[0] ); ?>"><?php echo esc_html( $link[1] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</span>
		</li>
		<?php
	}

	/*
	 * Scheduled updates
	 */

	/**
	 * Scheduled Updates tab.
	 *
	 * @return void
	 */
	private function render_updates() {
		$rows = $this->updates();

		self::box_open( 'calendar-sync', __( 'Scheduled updates', 'schedule-post-page-updates' ) );
		?>
		<p class="sppu-intro"><?php esc_html_e( 'Edited copies of live posts and pages, each waiting for its time. When it comes, only what was changed in the copy replaces the live version; its address, author, comments and date stay the same. The old version is kept in the post\'s revisions and in the history, where you can undo it.', 'schedule-post-page-updates' ); ?></p>

		<?php if ( ! $rows ) : ?>
			<div class="sppu-empty">
				<span class="sppu-empty-icon"><?php echo self::icon( 'calendar-sync', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<p><strong><?php esc_html_e( 'No scheduled updates.', 'schedule-post-page-updates' ); ?></strong></p>
				<p><?php esc_html_e( 'Hover over a published post in Posts or Pages and click Schedule Update, or start from the Overview tab.', 'schedule-post-page-updates' ); ?></p>
			</div>
		<?php else : ?>
			<div class="sppu-table-wrap">
				<table class="widefat striped sppu-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Post', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Changes', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Goes live', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'schedule-post-page-updates' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$copy     = $row['copy'];
							$original = $row['original'];
							$type     = get_post_type_object( $copy->post_type );
							?>
							<tr>
								<td class="sppu-cell-title">
									<strong><a href="<?php echo esc_url( get_edit_post_link( $copy->ID ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $original ? $original : $copy ) ) ); ?></a></strong>
									<span class="sppu-sub">
										<?php echo esc_html( $type ? $type->labels->singular_name : $copy->post_type ); ?>
										<?php if ( $row['scheduled_by'] ) : ?>
											<?php
											/* translators: %s: user name. */
											echo esc_html( ' · ' . sprintf( __( 'by %s', 'schedule-post-page-updates' ), self::user_name( $row['scheduled_by'] ) ) );
											?>
										<?php endif; ?>
									</span>
									<div class="sppu-row-actions">
										<a href="<?php echo esc_url( get_edit_post_link( $copy->ID ) ); ?>"><?php esc_html_e( 'Edit', 'schedule-post-page-updates' ); ?></a>
										<a href="<?php echo esc_url( get_preview_post_link( $copy ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'schedule-post-page-updates' ); ?></a>
										<?php if ( $original && 'trash' !== $original->post_status ) : ?>
											<a href="<?php echo esc_url( get_permalink( $original ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Live post', 'schedule-post-page-updates' ); ?></a>
											<a href="<?php echo esc_url( Schedule_Post_Page_Updates_Editor::apply_url( $copy->ID ) ); ?>" class="sppu-confirm" data-confirm="<?php esc_attr_e( 'Replace the live post with this update now?', 'schedule-post-page-updates' ); ?>"><?php esc_html_e( 'Apply now', 'schedule-post-page-updates' ); ?></a>
										<?php endif; ?>
										<?php if ( $row['time'] ) : ?>
											<a href="<?php echo esc_url( Schedule_Post_Page_Updates_Editor::unschedule_url( $copy->ID ) ); ?>"><?php esc_html_e( 'Unschedule', 'schedule-post-page-updates' ); ?></a>
										<?php endif; ?>
										<a href="<?php echo esc_url( get_delete_post_link( $copy->ID ) ); ?>" class="sppu-delete sppu-confirm" data-confirm="<?php esc_attr_e( 'Move this scheduled update to the trash? The live post is not affected.', 'schedule-post-page-updates' ); ?>"><?php esc_html_e( 'Trash', 'schedule-post-page-updates' ); ?></a>
									</div>
								</td>
								<td><?php echo esc_html( Schedule_Post_Page_Updates_Changes::sentence( $row['changes'] ) ); ?></td>
								<td class="sppu-cell-when"><?php echo self::when( $row['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?></td>
								<td>
									<?php echo self::pill( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
									<?php if ( '' !== $row['error'] ) : ?>
										<span class="sppu-sub sppu-error-text"><?php echo esc_html( $row['error'] ); ?></span>
									<?php elseif ( 'overdue' === $row['status'] ) : ?>
										<span class="sppu-sub"><?php esc_html_e( 'Waiting for WP-Cron to run.', 'schedule-post-page-updates' ); ?></span>
									<?php elseif ( $row['conflict'] ) : ?>
										<span class="sppu-sub sppu-warning-text"><?php esc_html_e( 'The live post was edited since this copy was made.', 'schedule-post-page-updates' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
		self::box_close();
	}

	/*
	 * Find and replace
	 */

	/**
	 * Find & Replace tab: the list, or the form.
	 *
	 * @return void
	 */
	private function render_replace() {
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		if ( '' !== $edit ) {
			$this->render_rule_form( 'new' === $edit ? 0 : absint( $edit ) );
			return;
		}

		$rules = Schedule_Post_Page_Updates_Replace::all();
		$extra = '<span class="sppu-box-actions"><a class="button button-primary" href="' . esc_url( self::url( 'replace', array( 'edit' => 'new' ) ) ) . '">' . self::icon( 'plus', 16 ) . esc_html__( 'Add Find & Replace', 'schedule-post-page-updates' ) . '</a></span>';

		self::box_open( 'replace', __( 'Scheduled find and replace', 'schedule-post-page-updates' ), '', $extra );
		?>
		<p class="sppu-intro"><?php esc_html_e( 'Change a word, phrase or year in the posts you choose at a set time, once or every year. Only the text people read is changed: links, image addresses, HTML and shortcodes are left alone. Every change can be undone from the history.', 'schedule-post-page-updates' ); ?></p>

		<?php if ( ! $rules ) : ?>
			<div class="sppu-empty">
				<span class="sppu-empty-icon"><?php echo self::icon( 'replace', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<p><strong><?php esc_html_e( 'No find and replace scheduled.', 'schedule-post-page-updates' ); ?></strong></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::year_preset_url() ); ?>"><?php esc_html_e( 'Set up a yearly year update', 'schedule-post-page-updates' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::url( 'replace', array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add Find & Replace', 'schedule-post-page-updates' ); ?></a>
				</p>
			</div>
		<?php else : ?>
			<div class="sppu-table-wrap">
				<table class="widefat striped sppu-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Change', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Next run', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'schedule-post-page-updates' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $rule ) : ?>
							<?php $this->render_rule_row( $rule ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
		self::box_close();
	}

	/**
	 * One rule in the list.
	 *
	 * @param array $rule Rule.
	 * @return void
	 */
	private function render_rule_row( array $rule ) {
		$action = function ( $task, $label, $confirm = '' ) use ( $rule ) {
			return sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sppu_rule_action&do=' . $task . '&rule=' . $rule['id'] ), 'sppu_rule_' . $task . '_' . $rule['id'] ) ),
				$confirm ? ' class="sppu-confirm' . ( 'delete' === $task ? ' sppu-delete' : '' ) . '" data-confirm="' . esc_attr( $confirm ) . '"' : '',
				esc_html( $label )
			);
		};
		$undo   = $rule['run_count'] ? Schedule_Post_Page_Updates_Log::applied_in_run( $rule['id'], $rule['run_count'], 1 ) : array();
		?>
		<tr>
			<td class="sppu-cell-title">
				<strong><a href="<?php echo esc_url( self::url( 'replace', array( 'edit' => $rule['id'] ) ) ); ?>"><?php echo esc_html( Schedule_Post_Page_Updates_Replace::display_name( $rule ) ); ?></a></strong>
				<span class="sppu-change">
					<code><?php echo esc_html( $rule['find'] ); ?></code>
					<?php echo self::icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<code><?php echo '' === $rule['replace'] ? '<em>' . esc_html__( '(removed)', 'schedule-post-page-updates' ) . '</em>' : esc_html( $rule['replace'] ); ?></code>
				</span>
				<div class="sppu-row-actions">
					<a href="<?php echo esc_url( self::url( 'replace', array( 'edit' => $rule['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'schedule-post-page-updates' ); ?></a>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by $action.
					if ( 'running' !== $rule['status'] ) {
						echo $action( 'run', __( 'Run now', 'schedule-post-page-updates' ), __( 'Run this find and replace now? You can undo it from the history.', 'schedule-post-page-updates' ) );
					}
					if ( 'scheduled' === $rule['status'] ) {
						echo $action( 'pause', __( 'Pause', 'schedule-post-page-updates' ) );
					} elseif ( 'paused' === $rule['status'] ) {
						echo $action( 'resume', __( 'Resume', 'schedule-post-page-updates' ) );
					}
					echo $action( 'delete', __( 'Delete', 'schedule-post-page-updates' ), __( 'Delete this find and replace? Changes it already made stay, and can still be undone from the history.', 'schedule-post-page-updates' ) );
					// phpcs:enable
					?>
				</div>
			</td>
			<td>
				<?php echo esc_html( self::rule_scope( $rule ) ); ?>
				<span class="sppu-sub"><?php echo esc_html( ucfirst( self::rule_fields( $rule ) ) ); ?></span>
			</td>
			<td class="sppu-cell-when">
				<?php if ( in_array( $rule['status'], array( 'scheduled', 'running' ), true ) ) : ?>
					<?php echo self::when( $rule['run_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
				<?php else : ?>
					<span class="sppu-muted">—</span>
				<?php endif; ?>
				<?php if ( $rule['repeat'] ) : ?>
					<span class="sppu-tag"><?php echo self::icon( 'repeat', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Every year', 'schedule-post-page-updates' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php echo self::pill( $rule['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
				<?php if ( $rule['last_run'] ) : ?>
					<span class="sppu-sub">
						<?php
						/* translators: 1: date and time, 2: result. */
						echo esc_html( sprintf( __( 'Last run %1$s. %2$s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Copies::format_time( $rule['last_run'] ), $rule['last_result'] ) );
						?>
					</span>
				<?php elseif ( '' !== $rule['last_result'] ) : ?>
					<span class="sppu-sub sppu-warning-text"><?php echo esc_html( $rule['last_result'] ); ?></span>
				<?php endif; ?>
				<?php if ( $undo ) : ?>
					<button type="button" class="button button-small sppu-undo-run" data-rule="<?php echo esc_attr( $rule['id'] ); ?>" data-run="<?php echo esc_attr( $rule['run_count'] ); ?>"><?php echo self::icon( 'undo-2', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Undo last run', 'schedule-post-page-updates' ); ?></button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Values for the rule form: a saved rule, a preset, or what was just
	 * submitted when it had an error.
	 *
	 * @param int $id Rule ID, 0 for new.
	 * @return array<string,mixed>
	 */
	private function form_values( $id ) {
		$year   = (int) wp_date( 'Y' );
		$values = array(
			'name'        => '',
			'find'        => '',
			'replace'     => '',
			'fields'      => array( 'title', 'content', 'excerpt', 'seo' ),
			'match_case'  => true,
			'whole_words' => true,
			'scope'       => 'posts',
			'post_ids'    => array(),
			'post_types'  => array_slice( Schedule_Post_Page_Updates_Plugin::post_types(), 0, 1 ),
			'repeat'      => false,
			'date'        => wp_date( 'Y-m-d', strtotime( '+1 day', time() ) ),
			'time'        => '00:00',
		);

		$preset = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Prefill only.

		if ( $id ) {
			$rule = Schedule_Post_Page_Updates_Replace::get( $id );
			if ( $rule ) {
				$values = array_merge(
					$values,
					$rule,
					array(
						'date' => wp_date( 'Y-m-d', $rule['run_at'] ),
						'time' => wp_date( 'H:i', $rule['run_at'] ),
					)
				);
			}
		} elseif ( 'year' === $preset ) {
			$values = array_merge(
				$values,
				array(
					/* translators: %d: year. */
					'name'    => sprintf( __( 'Year update %d', 'schedule-post-page-updates' ), $year + 1 ),
					'find'    => (string) $year,
					'replace' => (string) ( $year + 1 ),
					'repeat'  => true,
					'date'    => ( $year + 1 ) . '-01-01',
					'time'    => '00:00',
				)
			);
		}

		// After a failed save, show what was typed.
		$draft = get_transient( 'sppu_rule_draft_' . get_current_user_id() );
		if ( is_array( $draft ) && isset( $draft['id'] ) && (int) $draft['id'] === (int) $id ) {
			delete_transient( 'sppu_rule_draft_' . get_current_user_id() );
			$values = array_merge( $values, $draft['values'] );
		}

		return $values;
	}

	/**
	 * The add/edit form for a rule.
	 *
	 * @param int $id Rule ID, 0 for new.
	 * @return void
	 */
	private function render_rule_form( $id ) {
		$rule = $id ? Schedule_Post_Page_Updates_Replace::get( $id ) : null;

		if ( $id && ! $rule ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That find and replace no longer exists.', 'schedule-post-page-updates' ) . '</p></div>';
			return;
		}
		if ( $rule && 'running' === $rule['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This find and replace is running right now. Come back in a minute to edit it.', 'schedule-post-page-updates' ) . '</p></div>';
			return;
		}

		$v     = $this->form_values( $id );
		$types = Schedule_Post_Page_Updates_Plugin::post_types();
		$name  = 'rule';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sppu-rule-form" id="sppu-rule-form">
			<input type="hidden" name="action" value="sppu_save_rule">
			<input type="hidden" name="rule_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'sppu_save_rule_' . $id ); ?>

			<p class="sppu-back"><a href="<?php echo esc_url( self::url( 'replace' ) ); ?>">&larr; <?php esc_html_e( 'All find and replace', 'schedule-post-page-updates' ); ?></a></p>

			<?php self::box_open( 'text-search', $id ? __( 'Edit find and replace', 'schedule-post-page-updates' ) : __( 'New find and replace', 'schedule-post-page-updates' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sppu-find"><?php esc_html_e( 'Find', 'schedule-post-page-updates' ); ?></label></th>
					<td>
						<input type="text" class="regular-text sppu-big-input" id="sppu-find" name="<?php echo esc_attr( $name ); ?>[find]" value="<?php echo esc_attr( $v['find'] ); ?>" maxlength="<?php echo (int) Schedule_Post_Page_Updates_Replace::MAX_TEXT; ?>" required placeholder="<?php esc_attr_e( 'e.g. 2026', 'schedule-post-page-updates' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sppu-replace"><?php esc_html_e( 'Replace with', 'schedule-post-page-updates' ); ?></label></th>
					<td>
						<input type="text" class="regular-text sppu-big-input" id="sppu-replace" name="<?php echo esc_attr( $name ); ?>[replace]" value="<?php echo esc_attr( $v['replace'] ); ?>" maxlength="<?php echo (int) Schedule_Post_Page_Updates_Replace::MAX_TEXT; ?>" placeholder="<?php esc_attr_e( 'e.g. 2027', 'schedule-post-page-updates' ); ?>">
						<p class="description"><?php esc_html_e( 'Plain text. Leave it empty to remove the text.', 'schedule-post-page-updates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Matching', 'schedule-post-page-updates' ); ?></th>
					<td>
						<label class="sppu-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[whole_words]" value="1" <?php checked( $v['whole_words'] ); ?>> <span><?php esc_html_e( 'Whole words only', 'schedule-post-page-updates' ); ?> <span class="sppu-muted"><?php esc_html_e( '(“2026” won’t match “20261”)', 'schedule-post-page-updates' ); ?></span></span></label><br>
						<label class="sppu-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[match_case]" value="1" <?php checked( $v['match_case'] ); ?>> <span><?php esc_html_e( 'Match upper and lower case', 'schedule-post-page-updates' ); ?></span></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Change it in', 'schedule-post-page-updates' ); ?></th>
					<td>
						<div class="sppu-options sppu-options-grid">
							<?php
							$fields = array(
								'title'   => array( 'heading', __( 'Title', 'schedule-post-page-updates' ), __( 'The post or page title.', 'schedule-post-page-updates' ) ),
								'content' => array( 'file-text', __( 'Content', 'schedule-post-page-updates' ), __( 'The post body, including headings and block text. Check the preview for facts like “founded in 2026”.', 'schedule-post-page-updates' ) ),
								'excerpt' => array( 'text-search', __( 'Excerpt', 'schedule-post-page-updates' ), __( 'The short summary, if the post has one.', 'schedule-post-page-updates' ) ),
								'seo'     => array( 'search', __( 'SEO title and description', 'schedule-post-page-updates' ), __( 'Yoast SEO, Rank Math, SEOPress and The SEO Framework.', 'schedule-post-page-updates' ) ),
							);
							foreach ( $fields as $key => $field ) :
								?>
								<label class="sppu-option">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[fields][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $v['fields'], true ) ); ?>>
									<span class="sppu-option-icon"><?php echo self::icon( $field[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
									<span class="sppu-option-text"><strong><?php echo esc_html( $field[1] ); ?></strong><span><?php echo esc_html( $field[2] ); ?></span></span>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sppu-name"><?php esc_html_e( 'Name', 'schedule-post-page-updates' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="sppu-name" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $v['name'] ); ?>" maxlength="190" placeholder="<?php esc_attr_e( 'Optional, for your own reference', 'schedule-post-page-updates' ); ?>">
					</td>
				</tr>
			</table>
			<?php self::box_close(); ?>

			<?php self::box_open( 'files', __( 'Which posts', 'schedule-post-page-updates' ) ); ?>
			<div class="sppu-options">
				<label class="sppu-option">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>[scope]" value="posts" <?php checked( 'posts', $v['scope'] ); ?>>
					<span class="sppu-option-icon"><?php echo self::icon( 'list-checks', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="sppu-option-text"><strong><?php esc_html_e( 'Only the posts I pick', 'schedule-post-page-updates' ); ?></strong><span><?php esc_html_e( 'Safest: nothing else is touched, whatever it says.', 'schedule-post-page-updates' ); ?></span></span>
				</label>
				<div class="sppu-scope-panel" data-sppu-scope="posts">
					<div class="sppu-search" data-sppu-search="pick">
						<span class="sppu-search-field">
							<?php echo self::icon( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<input type="search" class="regular-text" placeholder="<?php esc_attr_e( 'Search posts and pages to add…', 'schedule-post-page-updates' ); ?>" aria-label="<?php esc_attr_e( 'Search posts and pages to add', 'schedule-post-page-updates' ); ?>" autocomplete="off">
						</span>
						<ul class="sppu-search-results" aria-live="polite"></ul>
					</div>
					<ul class="sppu-picked" data-name="<?php echo esc_attr( $name ); ?>[post_ids][]">
						<?php foreach ( (array) $v['post_ids'] as $post_id ) : ?>
							<?php
							$post = get_post( $post_id );
							if ( ! $post ) {
								continue;
							}
							?>
							<li data-id="<?php echo esc_attr( $post->ID ); ?>">
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[post_ids][]" value="<?php echo esc_attr( $post->ID ); ?>">
								<span class="sppu-picked-title"><?php echo esc_html( wp_strip_all_tags( get_the_title( $post ) ) ); ?></span>
								<button type="button" class="sppu-picked-remove" aria-label="<?php esc_attr_e( 'Remove', 'schedule-post-page-updates' ); ?>">×</button>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="sppu-picked-empty sppu-muted"><?php esc_html_e( 'No posts picked yet.', 'schedule-post-page-updates' ); ?></p>
				</div>

				<label class="sppu-option">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>[scope]" value="all" <?php checked( 'all', $v['scope'] ); ?>>
					<span class="sppu-option-icon"><?php echo self::icon( 'files', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="sppu-option-text"><strong><?php esc_html_e( 'Every post that contains the text', 'schedule-post-page-updates' ); ?></strong><span><?php esc_html_e( 'Published, private and scheduled posts of the types below, found when it runs. Preview first.', 'schedule-post-page-updates' ); ?></span></span>
				</label>
				<div class="sppu-scope-panel" data-sppu-scope="all">
					<?php if ( ! $types ) : ?>
						<p class="sppu-muted"><?php esc_html_e( 'No post types are switched on. Choose them on the Settings tab.', 'schedule-post-page-updates' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $types as $type ) : ?>
						<?php $object = get_post_type_object( $type ); ?>
						<label class="sppu-check sppu-inline-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $v['post_types'], true ) ); ?>> <span><?php echo esc_html( $object ? $object->labels->name : $type ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</div>
			<?php self::box_close(); ?>

			<?php self::box_open( 'calendar-clock', __( 'When', 'schedule-post-page-updates' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sppu-date"><?php esc_html_e( 'Date and time', 'schedule-post-page-updates' ); ?></label></th>
					<td>
						<span class="sppu-inline">
							<input type="date" id="sppu-date" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $v['date'] ); ?>" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required>
							<input type="time" id="sppu-time" name="<?php echo esc_attr( $name ); ?>[time]" value="<?php echo esc_attr( $v['time'] ); ?>" step="60" aria-label="<?php esc_attr_e( 'Time', 'schedule-post-page-updates' ); ?>">
						</span>
						<p class="description">
							<?php
							printf(
								/* translators: 1: time zone, 2: link to General Settings. */
								esc_html__( 'In the site’s time zone (%1$s). %2$s', 'schedule-post-page-updates' ),
								esc_html( Schedule_Post_Page_Updates_Copies::timezone_label() ),
								'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Change it', 'schedule-post-page-updates' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Repeat', 'schedule-post-page-updates' ); ?></th>
					<td>
						<label class="sppu-check"><input type="radio" name="<?php echo esc_attr( $name ); ?>[repeat]" value="0" <?php checked( ! $v['repeat'] ); ?>> <span><?php esc_html_e( 'Just once', 'schedule-post-page-updates' ); ?></span></label><br>
						<label class="sppu-check"><input type="radio" name="<?php echo esc_attr( $name ); ?>[repeat]" value="1" <?php checked( (bool) $v['repeat'] ); ?>> <span><?php esc_html_e( 'Every year, counting the years up by one each time', 'schedule-post-page-updates' ); ?></span></label>
						<p class="description sppu-repeat-help"><?php esc_html_e( 'For example, 2026 → 2027 this time, then 2027 → 2028 a year later, and so on. Set it once and forget it.', 'schedule-post-page-updates' ); ?></p>
						<p class="sppu-inline-warning" hidden></p>
					</td>
				</tr>
			</table>
			<?php self::box_close(); ?>

			<?php
			$extra = '<span class="sppu-box-actions"><button type="button" class="button sppu-preview-button">' . self::icon( 'eye', 16 ) . esc_html__( 'Preview matches', 'schedule-post-page-updates' ) . '</button></span>';
			self::box_open( 'eye', __( 'Preview', 'schedule-post-page-updates' ), 'sppu-preview-box', $extra );
			?>
			<div class="sppu-preview" aria-live="polite">
				<p class="sppu-muted"><?php esc_html_e( 'See which posts would change, and how, before you save. Nothing is changed by previewing.', 'schedule-post-page-updates' ); ?></p>
			</div>
			<?php self::box_close(); ?>
		</form>
		<?php
	}

	/**
	 * Save a rule.
	 *
	 * @return void
	 */
	public function handle_save_rule() {
		$id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

		check_admin_referer( 'sppu_save_rule_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t manage find and replace.', 'schedule-post-page-updates' ), '', array( 'response' => 403 ) );
		}

		$in   = isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ? wp_unslash( $_POST['rule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned field by field in validate().
		$rule = Schedule_Post_Page_Updates_Replace::validate( $in, get_current_user_id() );

		if ( ! is_wp_error( $rule ) ) {
			$rule = Schedule_Post_Page_Updates_Replace::save( $rule, $id, get_current_user_id() );
		}

		if ( is_wp_error( $rule ) ) {
			$this->remember_form( $id, $in );
			$this->redirect( 'replace', $rule->get_error_message(), true, array( 'edit' => $id ? $id : 'new' ) );
		}

		$this->redirect( 'replace', 'rule_saved' );
	}

	/**
	 * Keep what was typed, to show it again after an error.
	 *
	 * @param int   $id Rule ID.
	 * @param array $in Submitted values.
	 * @return void
	 */
	private function remember_form( $id, array $in ) {
		$values = array(
			'name'        => isset( $in['name'] ) ? sanitize_text_field( (string) $in['name'] ) : '',
			'find'        => Schedule_Post_Page_Updates_Replace::clean_text( isset( $in['find'] ) ? $in['find'] : '' ),
			'replace'     => Schedule_Post_Page_Updates_Replace::clean_text( isset( $in['replace'] ) ? $in['replace'] : '' ),
			'fields'      => isset( $in['fields'] ) && is_array( $in['fields'] ) ? array_values( array_intersect( Schedule_Post_Page_Updates_Replace::FIELDS, $in['fields'] ) ) : array(),
			'match_case'  => ! empty( $in['match_case'] ),
			'whole_words' => ! empty( $in['whole_words'] ),
			'scope'       => isset( $in['scope'] ) && 'all' === $in['scope'] ? 'all' : 'posts',
			'post_ids'    => isset( $in['post_ids'] ) && is_array( $in['post_ids'] ) ? array_slice( array_filter( array_map( 'absint', array_filter( $in['post_ids'], 'is_scalar' ) ) ), 0, Schedule_Post_Page_Updates_Replace::MAX_POSTS ) : array(),
			'post_types'  => isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_values( array_intersect( Schedule_Post_Page_Updates_Plugin::post_types(), $in['post_types'] ) ) : array(),
			'repeat'      => ! empty( $in['repeat'] ),
			'date'        => isset( $in['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['date'] ) ? (string) $in['date'] : '',
			'time'        => isset( $in['time'] ) && preg_match( '/^\d{2}:\d{2}$/', (string) $in['time'] ) ? (string) $in['time'] : '00:00',
		);

		set_transient(
			'sppu_rule_draft_' . get_current_user_id(),
			array(
				'id'     => $id,
				'values' => $values,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Run, pause, resume or delete a rule.
	 *
	 * @return void
	 */
	public function handle_rule_action() {
		$id = isset( $_GET['rule'] ) ? absint( $_GET['rule'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.

		check_admin_referer( 'sppu_rule_' . $do . '_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t manage find and replace.', 'schedule-post-page-updates' ), '', array( 'response' => 403 ) );
		}

		switch ( $do ) {
			case 'run':
				$result = Schedule_Post_Page_Updates_Replace::run_now( $id, get_current_user_id() );
				if ( is_wp_error( $result ) ) {
					$this->redirect( 'replace', $result->get_error_message(), true );
				}
				$this->redirect( 'replace', 'rule_ran' );
				break;
			case 'pause':
				Schedule_Post_Page_Updates_Replace::pause( $id );
				$this->redirect( 'replace', 'rule_paused' );
				break;
			case 'resume':
				$result = Schedule_Post_Page_Updates_Replace::resume( $id );
				if ( is_wp_error( $result ) ) {
					$this->redirect( 'replace', $result->get_error_message(), true );
				}
				$this->redirect( 'replace', 'rule_resumed' );
				break;
			case 'delete':
				Schedule_Post_Page_Updates_Replace::delete( $id );
				$this->redirect( 'replace', 'rule_deleted' );
				break;
		}

		$this->redirect( 'replace', '' );
	}

	/*
	 * AJAX
	 */

	/**
	 * Search posts: to schedule an update, or to pick for a rule.
	 *
	 * @return void
	 */
	public function ajax_search_posts() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'schedule-post-page-updates' ) ), 403 );
		}

		$term  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$mode  = isset( $_GET['mode'] ) && 'schedule' === $_GET['mode'] ? 'schedule' : 'pick';
		$types = Schedule_Post_Page_Updates_Plugin::post_types();

		if ( ! $types ) {
			wp_send_json_success( array() );
		}

		$args = array(
			'post_type'              => $types,
			'post_status'            => 'schedule' === $mode ? Schedule_Post_Page_Updates_Copies::SOURCE_STATUSES : array( 'publish', 'private', 'future', 'draft', 'pending' ),
			'posts_per_page'         => 15,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
		);

		if ( '' !== $term ) {
			if ( ctype_digit( $term ) ) {
				$args['post__in'] = array( absint( $term ) );
			} else {
				$args['s']              = $term;
				$args['search_columns'] = array( 'post_title' );
				$args['orderby']        = 'relevance';
			}
		}

		$query   = new WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) || Schedule_Post_Page_Updates_Copies::is_copy( $post ) ) {
				continue;
			}

			$type   = get_post_type_object( $post->post_type );
			$status = get_post_status_object( $post->post_status );
			$item   = array(
				'id'     => $post->ID,
				'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'type'   => $type ? $type->labels->singular_name : $post->post_type,
				'status' => $status ? $status->label : $post->post_status,
				'date'   => get_the_modified_date( '', $post ),
			);

			if ( '' === $item['title'] ) {
				$item['title'] = __( '(no title)', 'schedule-post-page-updates' );
			}

			if ( 'schedule' === $mode ) {
				$item['url'] = Schedule_Post_Page_Updates_Copies::can_schedule( $post, get_current_user_id() ) ? Schedule_Post_Page_Updates_Editor::create_url( $post->ID ) : '';
			}

			$results[] = $item;
		}

		wp_send_json_success( $results );
	}

	/**
	 * Preview a rule from the form.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'schedule-post-page-updates' ) ), 403 );
		}

		$in   = isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ? wp_unslash( $_POST['rule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned field by field in validate().
		$rule = Schedule_Post_Page_Updates_Replace::validate( $in, get_current_user_id(), false );

		if ( is_wp_error( $rule ) ) {
			wp_send_json_error( array( 'message' => $rule->get_error_message() ) );
		}

		wp_send_json_success( Schedule_Post_Page_Updates_Replace::preview( $rule, get_current_user_id() ) );
	}

	/**
	 * Undo the next batch of a rule's run.
	 *
	 * @return void
	 */
	public function ajax_undo_run() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'schedule-post-page-updates' ) ), 403 );
		}

		$rule = isset( $_POST['rule'] ) ? absint( $_POST['rule'] ) : 0;
		$run  = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0;

		wp_send_json_success( Schedule_Post_Page_Updates_Replace::undo_run( $rule, $run, get_current_user_id() ) );
	}

	/*
	 * History
	 */

	/**
	 * Undo one history row, as a user who can edit the post.
	 *
	 * @param int $log_id  History row ID.
	 * @param int $user_id Who's undoing it.
	 * @return array|WP_Error
	 */
	public static function undo_entry( $log_id, $user_id ) {
		$row = Schedule_Post_Page_Updates_Log::get( $log_id );

		if ( ! $row || Schedule_Post_Page_Updates_Log::APPLIED !== $row->status || ! is_array( $row->backup ) ) {
			return new WP_Error( 'sppu_cannot_undo', __( 'This change can\'t be undone.', 'schedule-post-page-updates' ) );
		}
		if ( ! user_can( $user_id, 'edit_post', (int) $row->post_id ) ) {
			return new WP_Error( 'sppu_cannot_undo', __( 'You can\'t edit that post.', 'schedule-post-page-updates' ) );
		}

		if ( post_type_supports( get_post_type( (int) $row->post_id ), 'revisions' ) ) {
			wp_save_post_revision( (int) $row->post_id );
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		$result = Schedule_Post_Page_Updates_Changes::undo( (int) $row->post_id, $row->backup );
		wp_set_current_user( $previous );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$note = $result['skipped']
			/* translators: %s: list of parts, e.g. "Title and Content". */
			? sprintf( __( 'Left alone because they were edited since: %s.', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Changes::sentence( $result['skipped'] ) )
			: '';

		Schedule_Post_Page_Updates_Log::mark_undone( $log_id, $user_id, $note );

		return $result;
	}

	/**
	 * Undo from the History tab.
	 *
	 * @return void
	 */
	public function handle_undo() {
		$id = isset( $_POST['log'] ) ? absint( $_POST['log'] ) : 0;

		check_admin_referer( 'sppu_undo_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t undo changes.', 'schedule-post-page-updates' ), '', array( 'response' => 403 ) );
		}

		$result = self::undo_entry( $id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'history', $result->get_error_message(), true );
		}

		if ( $result['skipped'] ) {
			$this->redirect(
				'history',
				/* translators: 1: restored parts, 2: skipped parts. */
				sprintf( __( 'Undone: %1$s. Left alone because they were edited since: %2$s.', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Changes::sentence( $result['restored'] ), Schedule_Post_Page_Updates_Changes::sentence( $result['skipped'] ) ),
				true
			);
		}

		$this->redirect( 'history', 'undone' );
	}

	/**
	 * History tab.
	 *
	 * @return void
	 */
	private function render_history() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters and paging only.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		if ( ! in_array( $status, array( '', 'applied', 'undone', 'failed' ), true ) ) {
			$status = '';
		}

		$result  = Schedule_Post_Page_Updates_Log::query(
			array(
				'status'   => $status,
				'page'     => $page,
				'per_page' => self::PER_PAGE,
			)
		);
		$filters = array(
			''        => __( 'All', 'schedule-post-page-updates' ),
			'applied' => __( 'Applied', 'schedule-post-page-updates' ),
			'undone'  => __( 'Undone', 'schedule-post-page-updates' ),
			'failed'  => __( 'Failed', 'schedule-post-page-updates' ),
		);

		$links = array();
		foreach ( $filters as $key => $label ) {
			$links[] = sprintf( '<li><a href="%1$s"%2$s>%3$s</a></li>', esc_url( self::url( 'history', '' === $key ? array() : array( 'status' => $key ) ) ), $key === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
		}

		self::box_open( 'history', __( 'History', 'schedule-post-page-updates' ), '', '<ul class="subsubsub sppu-filters">' . implode( '', $links ) . '</ul>' );
		?>
		<p class="sppu-intro"><?php esc_html_e( 'Every change this plugin made, newest first. Undo puts back what was there before, part by part; anything edited since is left alone.', 'schedule-post-page-updates' ); ?></p>

		<?php if ( ! $result['rows'] ) : ?>
			<div class="sppu-empty">
				<span class="sppu-empty-icon"><?php echo self::icon( 'history', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<p><strong><?php esc_html_e( 'Nothing here yet.', 'schedule-post-page-updates' ); ?></strong></p>
				<p><?php esc_html_e( 'Changes show up here once a scheduled update or find and replace has run.', 'schedule-post-page-updates' ); ?></p>
			</div>
		<?php else : ?>
			<div class="sppu-table-wrap">
				<table class="widefat striped sppu-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Post', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Change', 'schedule-post-page-updates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'schedule-post-page-updates' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<?php $this->render_history_row( $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
			$pages = (int) ceil( $result['total'] / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( 'history', '' === $status ? array() : array( 'status' => $status ) ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				) . '</div></div>';
			}
			?>
		<?php endif; ?>
		<?php
		self::box_close();
	}

	/**
	 * One history row.
	 *
	 * @param object $row Row.
	 * @return void
	 */
	private function render_history_row( $row ) {
		$post    = $row->post_id ? get_post( (int) $row->post_id ) : null;
		$time    = strtotime( $row->created_at . ' UTC' );
		$is_rule = Schedule_Post_Page_Updates_Log::KIND_REPLACE === $row->kind;
		?>
		<tr>
			<td class="sppu-cell-when">
				<strong><?php echo esc_html( Schedule_Post_Page_Updates_Copies::format_time( $time ) ); ?></strong>
				<span class="sppu-sub"><?php echo esc_html( self::user_name( (int) $row->user_id ) ); ?></span>
			</td>
			<td class="sppu-cell-title">
				<?php if ( $post && current_user_can( 'edit_post', $post->ID ) ) : ?>
					<strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $post ) ) ); ?></a></strong>
					<div class="sppu-row-actions">
						<?php if ( 'trash' !== $post->post_status ) : ?>
							<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'schedule-post-page-updates' ); ?></a>
						<?php endif; ?>
						<?php
						$revisions = wp_get_post_revisions(
							$post->ID,
							array(
								'posts_per_page' => 1,
								'fields'         => 'ids',
							)
						);
						if ( $revisions ) :
							?>
							<a href="<?php echo esc_url( admin_url( 'revision.php?revision=' . (int) reset( $revisions ) ) ); ?>"><?php esc_html_e( 'Revisions', 'schedule-post-page-updates' ); ?></a>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<strong><?php echo esc_html( '' !== (string) $row->post_title ? wp_strip_all_tags( $row->post_title ) : __( '(deleted post)', 'schedule-post-page-updates' ) ); ?></strong>
				<?php endif; ?>
			</td>
			<td>
				<span class="sppu-kind <?php echo $is_rule ? 'is-rule' : 'is-update'; ?>">
					<?php echo self::icon( $is_rule ? 'replace' : 'file-pen-line', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $is_rule ? __( 'Find & replace', 'schedule-post-page-updates' ) : __( 'Scheduled update', 'schedule-post-page-updates' ) ); ?>
				</span>
				<?php if ( '' !== (string) $row->summary ) : ?>
					<span class="sppu-sub"><?php echo esc_html( $row->summary ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php echo self::pill( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
				<?php if ( '' !== (string) $row->message ) : ?>
					<span class="sppu-sub<?php echo Schedule_Post_Page_Updates_Log::FAILED === $row->status ? ' sppu-error-text' : ''; ?>"><?php echo esc_html( $row->message ); ?></span>
				<?php endif; ?>
				<?php if ( Schedule_Post_Page_Updates_Log::UNDONE === $row->status && $row->undone_at ) : ?>
					<span class="sppu-sub">
						<?php
						/* translators: 1: date and time, 2: user name. */
						echo esc_html( sprintf( __( '%1$s by %2$s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Copies::format_time( strtotime( $row->undone_at . ' UTC' ) ), self::user_name( (int) $row->undone_by ) ) );
						?>
					</span>
				<?php endif; ?>
				<?php if ( Schedule_Post_Page_Updates_Log::APPLIED === $row->status && $row->has_backup && $post && current_user_can( 'edit_post', $post->ID ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sppu-undo-form">
						<input type="hidden" name="action" value="sppu_undo">
						<input type="hidden" name="log" value="<?php echo esc_attr( $row->id ); ?>">
						<?php wp_nonce_field( 'sppu_undo_' . $row->id ); ?>
						<button type="submit" class="button button-small sppu-confirm-submit" data-confirm="<?php esc_attr_e( 'Undo this change? Parts edited since are left alone.', 'schedule-post-page-updates' ); ?>"><?php echo self::icon( 'undo-2', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Undo', 'schedule-post-page-updates' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/*
	 * Settings
	 */

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Schedule_Post_Page_Updates_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private function render_settings() {
		$s     = $this->plugin->get_settings();
		$types = Schedule_Post_Page_Updates_Plugin::available_post_types();
		?>
		<form action="options.php" method="post" id="sppu-settings-form">
			<?php settings_fields( self::PAGE ); ?>

			<?php self::box_open( 'files', __( 'Post types', 'schedule-post-page-updates' ) ); ?>
			<p class="sppu-intro"><?php esc_html_e( 'Where scheduled updates and find and replace are available.', 'schedule-post-page-updates' ); ?></p>
			<div class="sppu-options sppu-options-grid">
				<?php foreach ( $types as $type ) : ?>
					<label class="sppu-option">
						<input type="checkbox" name="<?php echo esc_attr( self::name( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>>
						<span class="sppu-option-icon"><?php echo self::icon( 'page' === $type->name ? 'file' : ( 'post' === $type->name ? 'file-text' : 'files' ), 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
						<span class="sppu-option-text"><strong><?php echo esc_html( $type->labels->name ); ?></strong><span><code><?php echo esc_html( $type->name ); ?></code></span></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php self::box_close(); ?>

			<?php self::box_open( 'calendar-check', __( 'When a change goes live', 'schedule-post-page-updates' ) ); ?>
			<div class="sppu-options">
				<label class="sppu-option">
					<input type="radio" name="<?php echo esc_attr( self::name( 'publish_date' ) ); ?>" value="keep" <?php checked( 'keep', $s['publish_date'] ); ?>>
					<span class="sppu-option-icon"><?php echo self::icon( 'calendar-days', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="sppu-option-text"><strong><?php esc_html_e( 'Keep the original publish date', 'schedule-post-page-updates' ); ?></strong><span><?php esc_html_e( 'Recommended. The “last modified” date still moves to the time of the change, which search engines and many themes show as “Updated on”.', 'schedule-post-page-updates' ); ?></span></span>
				</label>
				<label class="sppu-option">
					<input type="radio" name="<?php echo esc_attr( self::name( 'publish_date' ) ); ?>" value="update" <?php checked( 'update', $s['publish_date'] ); ?>>
					<span class="sppu-option-icon"><?php echo self::icon( 'calendar-sync', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="sppu-option-text"><strong><?php esc_html_e( 'Also move the publish date to the time of the change', 'schedule-post-page-updates' ); ?></strong><span><?php esc_html_e( 'Republishes the post as new. Its address stays the same, unless your permalinks include the date.', 'schedule-post-page-updates' ); ?></span></span>
				</label>
			</div>
			<?php self::box_close(); ?>

			<?php self::box_open( 'mail', __( 'Email alerts', 'schedule-post-page-updates' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send an email', 'schedule-post-page-updates' ); ?></th>
					<td>
						<?php
						$choices = array(
							'failures' => __( 'When a change can\'t be made', 'schedule-post-page-updates' ),
							'all'      => __( 'When a change goes live, and when one can\'t be made', 'schedule-post-page-updates' ),
							'none'     => __( 'Never', 'schedule-post-page-updates' ),
						);
						foreach ( $choices as $value => $label ) :
							?>
							<label class="sppu-check"><input type="radio" name="<?php echo esc_attr( self::name( 'notify' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $s['notify'] ); ?>> <span><?php echo esc_html( $label ); ?></span></label><br>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sppu-notify-to"><?php esc_html_e( 'Send to', 'schedule-post-page-updates' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="sppu-notify-to" name="<?php echo esc_attr( self::name( 'notify_to' ) ); ?>" value="<?php echo esc_attr( $s['notify_to'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Separate several addresses with commas. Empty sends to the site\'s admin email.', 'schedule-post-page-updates' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::box_close(); ?>

			<?php self::box_open( 'history', __( 'History', 'schedule-post-page-updates' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sppu-history-days"><?php esc_html_e( 'Keep history for', 'schedule-post-page-updates' ); ?></label></th>
					<td>
						<input type="number" class="small-text" id="sppu-history-days" name="<?php echo esc_attr( self::name( 'history_days' ) ); ?>" value="<?php echo esc_attr( $s['history_days'] ); ?>" min="0" max="3650" step="1"> <?php esc_html_e( 'days', 'schedule-post-page-updates' ); ?>
						<p class="description"><?php esc_html_e( 'Older changes can no longer be undone from here (their post revisions stay). 0 keeps everything.', 'schedule-post-page-updates' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::box_close(); ?>
		</form>
		<?php
	}

	/*
	 * Sidebar
	 */

	/**
	 * Sidebar: Save (settings tab), status, tips and updates.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	private function render_sidebar( $tab ) {
		if ( 'settings' === $tab ) {
			self::box_open( 'save', __( 'Save', 'schedule-post-page-updates' ), 'sppu-save-box' );
			echo '<p>' . esc_html__( 'Changes apply to everything scheduled from now on.', 'schedule-post-page-updates' ) . '</p>';
			submit_button( __( 'Save Changes', 'schedule-post-page-updates' ), 'primary large', 'submit', false, array( 'form' => 'sppu-settings-form' ) );
			self::box_close();
		}

		if ( 'replace' === $tab && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
			self::box_open( 'save', __( 'Save', 'schedule-post-page-updates' ), 'sppu-save-box' );
			echo '<p>' . esc_html__( 'Nothing changes until the date you picked. You can preview first, and undo every change later.', 'schedule-post-page-updates' ) . '</p>';
			submit_button( __( 'Save and Schedule', 'schedule-post-page-updates' ), 'primary large', 'submit', false, array( 'form' => 'sppu-rule-form' ) );
			echo '<p class="sppu-cancel"><a href="' . esc_url( self::url( 'replace' ) ) . '">' . esc_html__( 'Cancel', 'schedule-post-page-updates' ) . '</a></p>';
			self::box_close();
		}

		$this->render_status_box();

		if ( in_array( $tab, array( 'overview', 'updates' ), true ) ) {
			self::box_open( 'lightbulb', __( 'How it works', 'schedule-post-page-updates' ) );
			?>
			<ol class="sppu-steps">
				<li><?php esc_html_e( 'Click Schedule Update on a published post. You get a private copy in the normal editor.', 'schedule-post-page-updates' ); ?></li>
				<li><?php esc_html_e( 'Change anything: text, images, categories, SEO fields, the featured image.', 'schedule-post-page-updates' ); ?></li>
				<li><?php esc_html_e( 'Pick a date and time and click Schedule Update.', 'schedule-post-page-updates' ); ?></li>
				<li><?php esc_html_e( 'At that time, your changes replace the live post. Same address, same comments, and you can undo it.', 'schedule-post-page-updates' ); ?></li>
			</ol>
			<?php
			self::box_close();
		}

		$this->render_updates_box();
	}

	/**
	 * Status checklist.
	 *
	 * @return void
	 */
	private function render_status_box() {
		$types  = array();
		$labels = Schedule_Post_Page_Updates_Plugin::available_post_types();
		foreach ( Schedule_Post_Page_Updates_Plugin::post_types() as $type ) {
			$types[] = isset( $labels[ $type ] ) ? $labels[ $type ]->labels->name : $type;
		}

		$swept  = (int) get_option( Schedule_Post_Page_Updates_Scheduler::OPTION_SWEPT, 0 );
		$next   = 0;
		$cron   = array( true, __( 'WP-Cron is running', 'schedule-post-page-updates' ) );
		$notify = $this->plugin->get_setting( 'notify' );

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$cron = array( false, __( 'WP-Cron is switched off (DISABLE_WP_CRON). Changes only go live if your server runs wp-cron.php on a schedule.', 'schedule-post-page-updates' ) );
		} elseif ( $swept && $swept < time() - 3 * HOUR_IN_SECONDS ) {
			/* translators: %s: time span. */
			$cron = array( false, sprintf( __( 'WP-Cron last ran %s ago. Changes may go live late.', 'schedule-post-page-updates' ), human_time_diff( $swept ) ) );
		}

		foreach ( Schedule_Post_Page_Updates_Copies::scheduled() as $copy ) {
			$next = Schedule_Post_Page_Updates_Copies::time( $copy->ID );
			break;
		}
		foreach ( Schedule_Post_Page_Updates_Replace::all() as $rule ) {
			if ( 'scheduled' === $rule['status'] && ( ! $next || $rule['run_at'] < $next ) ) {
				$next = $rule['run_at'];
			}
		}

		$recipients = $this->plugin->notify_recipients();
		$status     = array(
			$cron,
			array(
				(bool) $types,
				$types
					/* translators: %s: post type names. */
					? sprintf( __( 'Works on %s', 'schedule-post-page-updates' ), wp_sprintf( '%l', $types ) )
					: __( 'No post types switched on', 'schedule-post-page-updates' ),
			),
			array(
				(bool) $next,
				$next
					/* translators: %s: date and time. */
					? sprintf( __( 'Next change: %s', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Copies::format_time( $next ) )
					: __( 'Nothing scheduled', 'schedule-post-page-updates' ),
			),
			array(
				'none' !== $notify && $recipients,
				'none' === $notify || ! $recipients
					? __( 'Email alerts off', 'schedule-post-page-updates' )
					/* translators: %s: email address(es). */
					: sprintf( 'all' === $notify ? __( 'Emails every change to %s', 'schedule-post-page-updates' ) : __( 'Emails problems to %s', 'schedule-post-page-updates' ), implode( ', ', $recipients ) ),
			),
		);

		self::box_open( 'activity', __( 'Status', 'schedule-post-page-updates' ) );
		?>
		<ul class="sppu-checklist">
			<?php foreach ( $status as $item ) : ?>
				<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<p class="sppu-muted sppu-timezone-note">
			<?php
			printf(
				/* translators: 1: time zone, 2: link. */
				esc_html__( 'Times are in the site’s time zone, %1$s. %2$s', 'schedule-post-page-updates' ),
				esc_html( Schedule_Post_Page_Updates_Copies::timezone_label() ),
				'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Change', 'schedule-post-page-updates' ) . '</a>'
			);
			?>
		</p>
		<?php
		self::box_close();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( SCHEDULE_POST_PAGE_UPDATES_REPO ) );
		$check_url  = Schedule_Post_Page_Updates_Updater::check_now_url();

		self::box_open( 'refresh-cw', __( 'Updates', 'schedule-post-page-updates' ), 'sppu-updates-box' );
		?>
		<p>
			<?php
			/* translators: %s: installed plugin version. */
			printf( esc_html__( 'Installed version: %s', 'schedule-post-page-updates' ), '<strong>' . esc_html( SCHEDULE_POST_PAGE_UPDATES_VERSION ) . '</strong>' );
			?>
		</p>

		<?php if ( ! Schedule_Post_Page_Updates_Updater::has_repo() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: header name, 2: main plugin file. */
					esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'schedule-post-page-updates' ),
					'<code>Update URI</code>',
					'<code>' . esc_html( plugin_basename( SCHEDULE_POST_PAGE_UPDATES_FILE ) ) . '</code>'
				);
				?>
			</p>
		<?php elseif ( Schedule_Post_Page_Updates_Updater::is_dev_copy() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: repo, 2: constant name. */
					esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'schedule-post-page-updates' ),
					'<a href="' . esc_url( SCHEDULE_POST_PAGE_UPDATES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
					'<code>SCHEDULE_POST_PAGE_UPDATES_DEV_MODE</code>'
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: repo. */
					esc_html__( 'Updates come from the latest release of %s.', 'schedule-post-page-updates' ),
					'<a href="' . esc_url( SCHEDULE_POST_PAGE_UPDATES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
				);
				?>
			</p>
			<?php if ( $check_url ) : ?>
				<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'schedule-post-page-updates' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		self::box_close();
	}
}
