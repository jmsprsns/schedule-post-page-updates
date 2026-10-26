<?php
/**
 * The post editor side: "Schedule an update" on live posts, the schedule
 * panel on updates (block editor and classic editor), and the links that
 * create, apply and unschedule updates.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor integration.
 */
final class Schedule_Post_Page_Updates_Editor {

	/**
	 * Nonce action for the editor panel's AJAX requests.
	 */
	const NONCE = 'sppu_editor';

	/**
	 * Message to pass back after a classic-editor save.
	 *
	 * @var string
	 */
	private static $classic_message = '';

	/**
	 * Hook everything in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_sppu_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_sppu_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_sppu_unschedule', array( __CLASS__, 'handle_unschedule' ) );
		add_action( 'wp_ajax_sppu_schedule', array( __CLASS__, 'ajax_schedule' ) );

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );

		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'classic_box' ) );
		add_action( 'post_submitbox_start', array( __CLASS__, 'classic_button' ) );
		add_action( 'save_post', array( __CLASS__, 'classic_save' ), 20, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'classic_redirect' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/*
	 * URLs
	 */

	/**
	 * Link that makes a scheduled update of a live post.
	 *
	 * @param int $post_id Live post ID.
	 * @return string Raw URL (also used from JavaScript); escape before printing.
	 */
	public static function create_url( $post_id ) {
		return self::action_url( 'sppu_create', $post_id );
	}

	/**
	 * Link that applies an update now.
	 *
	 * @param int $copy_id Copy ID.
	 * @return string Raw URL; escape before printing.
	 */
	public static function apply_url( $copy_id ) {
		return self::action_url( 'sppu_apply', $copy_id );
	}

	/**
	 * Link that unschedules an update.
	 *
	 * @param int $copy_id Copy ID.
	 * @return string Raw URL; escape before printing.
	 */
	public static function unschedule_url( $copy_id ) {
		return self::action_url( 'sppu_unschedule', $copy_id );
	}

	/**
	 * An admin-post.php link with a nonce for one post. Not wp_nonce_url(),
	 * which returns HTML-escaped "&amp;" that breaks in location.href.
	 *
	 * @param string $action  Action name; also the nonce prefix.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private static function action_url( $action, $post_id ) {
		$post_id = absint( $post_id );

		return add_query_arg(
			array(
				'action'   => $action,
				'post'     => $post_id,
				'_wpnonce' => wp_create_nonce( $action . '_' . $post_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Where to go after an action: back where the user came from (if it's
	 * in this admin), else the fallback, with a message.
	 *
	 * @param string $fallback Fallback URL.
	 * @param string $message  Message key.
	 * @return string
	 */
	private static function back( $fallback, $message ) {
		$referer = wp_get_referer();
		$url     = $referer && false === strpos( $referer, 'post.php' ) ? $referer : $fallback;

		return add_query_arg( 'sppu_msg', $message, remove_query_arg( array( 'sppu_msg', 'sppu_error' ), $url ) );
	}

	/*
	 * Actions
	 */

	/**
	 * Make a scheduled update and open it in the editor.
	 *
	 * @return void
	 */
	public static function handle_create() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'sppu_create_' . $post_id );

		$copy_id = Schedule_Post_Page_Updates_Copies::create( $post_id, get_current_user_id() );

		if ( is_wp_error( $copy_id ) ) {
			wp_die( esc_html( $copy_id->get_error_message() ), esc_html__( 'Schedule Update', 'schedule-post-page-updates' ), array( 'response' => 403 ) );
		}

		wp_safe_redirect( add_query_arg( 'sppu_msg', 'created', get_edit_post_link( $copy_id, 'raw' ) ) );
		exit;
	}

	/**
	 * Apply an update now.
	 *
	 * @return void
	 */
	public static function handle_apply() {
		$copy_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'sppu_apply_' . $copy_id );

		if ( ! Schedule_Post_Page_Updates_Copies::is_copy( $copy_id ) || ! current_user_can( 'edit_post', $copy_id ) ) {
			wp_die( esc_html__( 'You can\'t apply this update.', 'schedule-post-page-updates' ), esc_html__( 'Apply Update', 'schedule-post-page-updates' ), array( 'response' => 403 ) );
		}

		$original_id = Schedule_Post_Page_Updates_Copies::original_id( $copy_id );
		$result      = Schedule_Post_Page_Updates_Scheduler::apply_update( $copy_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'sppu_msg', 'failed', get_edit_post_link( $copy_id, 'raw' ) ) );
			exit;
		}

		$edit = get_edit_post_link( $original_id, 'raw' );
		wp_safe_redirect( self::back( $edit ? $edit : admin_url(), 'applied' ) );
		exit;
	}

	/**
	 * Unschedule an update.
	 *
	 * @return void
	 */
	public static function handle_unschedule() {
		$copy_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'sppu_unschedule_' . $copy_id );

		if ( ! Schedule_Post_Page_Updates_Copies::is_copy( $copy_id ) || ! current_user_can( 'edit_post', $copy_id ) ) {
			wp_die( esc_html__( 'You can\'t change this update.', 'schedule-post-page-updates' ), esc_html__( 'Unschedule Update', 'schedule-post-page-updates' ), array( 'response' => 403 ) );
		}

		Schedule_Post_Page_Updates_Copies::clear_time( $copy_id );

		wp_safe_redirect( self::back( get_edit_post_link( $copy_id, 'raw' ), 'unscheduled' ) );
		exit;
	}

	/**
	 * A date and time from the editor ("2027-01-01T00:00"), in the site's
	 * time zone, as a Unix timestamp.
	 *
	 * @param string $value Value.
	 * @return int 0 when invalid.
	 */
	public static function parse_local( $value ) {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', (string) $value, $m ) ) {
			return 0;
		}

		return Schedule_Post_Page_Updates_Replace::parse_local( $m[1], $m[2] );
	}

	/**
	 * Block editor panel: set or clear an update's time.
	 *
	 * @return void
	 */
	public static function ajax_schedule() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$copy_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;

		if ( ! Schedule_Post_Page_Updates_Copies::is_copy( $copy_id ) || ! current_user_can( 'edit_post', $copy_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t change this update.', 'schedule-post-page-updates' ) ), 403 );
		}

		$when = isset( $_POST['when'] ) ? sanitize_text_field( wp_unslash( $_POST['when'] ) ) : '';

		if ( '' === $when ) {
			Schedule_Post_Page_Updates_Copies::clear_time( $copy_id );
			wp_send_json_success( self::schedule_data( $copy_id ) );
		}

		$time = self::parse_local( $when );

		if ( ! $time ) {
			wp_send_json_error( array( 'message' => __( 'Pick a valid date and time.', 'schedule-post-page-updates' ) ) );
		}

		$result = Schedule_Post_Page_Updates_Copies::set_time( $copy_id, $time, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( self::schedule_data( $copy_id ) );
	}

	/**
	 * An update's schedule, for the panel.
	 *
	 * @param int $copy_id Copy ID.
	 * @return array<string,mixed>
	 */
	private static function schedule_data( $copy_id ) {
		$time = Schedule_Post_Page_Updates_Copies::time( $copy_id );

		return array(
			'time'     => $time,
			'local'    => $time ? wp_date( 'Y-m-d\TH:i:s', $time ) : '',
			'label'    => $time ? Schedule_Post_Page_Updates_Copies::format_time( $time ) : '',
			'relative' => $time ? self::relative( $time ) : '',
			'error'    => (string) get_post_meta( $copy_id, Schedule_Post_Page_Updates_Copies::META_ERROR, true ),
		);
	}

	/**
	 * "in 3 days" or "2 hours ago".
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function relative( $time ) {
		/* translators: %s: time span like "3 days". */
		$format = $time >= time() ? __( 'in %s', 'schedule-post-page-updates' ) : __( '%s ago', 'schedule-post-page-updates' );

		return sprintf( $format, human_time_diff( $time, time() ) );
	}

	/*
	 * Screens
	 */

	/**
	 * The post being edited on post.php, if any.
	 *
	 * @return WP_Post|null
	 */
	private static function current_post() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base ) {
			return null;
		}

		$post = get_post();

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Body class on an update's edit screen, which hides the Publish
	 * controls (publishing a copy is never what's wanted; it would only
	 * save it anyway).
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$post = self::current_post();

		if ( $post && Schedule_Post_Page_Updates_Copies::STATUS === $post->post_status ) {
			$classes .= ' sppu-is-update';
		}

		return $classes;
	}

	/**
	 * Data for the editor panel.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return array<string,mixed>|null Null when there's nothing to show.
	 */
	private static function panel_data( WP_Post $post ) {
		$message = isset( $_GET['sppu_msg'] ) ? sanitize_key( wp_unslash( $_GET['sppu_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$data    = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::NONCE ),
			'postId'        => $post->ID,
			'message'       => self::message_text( $message, $post ),
			'is12Hour'      => (bool) preg_match( '/[aAgh]/', (string) get_option( 'time_format' ) ),
			'now'           => wp_date( 'Y-m-d\TH:i:s' ),
			'timezone'      => Schedule_Post_Page_Updates_Copies::timezone_label(),
			'pageUrl'       => Schedule_Post_Page_Updates_Admin::url( 'updates' ),
			// Buttons took a "variant" prop in WordPress 5.9; older ones need isPrimary etc.
			'legacyButtons' => version_compare( get_bloginfo( 'version' ), '5.9', '<' ),
		);

		if ( Schedule_Post_Page_Updates_Copies::STATUS === $post->post_status && Schedule_Post_Page_Updates_Copies::is_copy( $post ) ) {
			$info     = Schedule_Post_Page_Updates_Copies::info( $post );
			$original = $info['original'];

			return array_merge(
				$data,
				self::schedule_data( $post->ID ),
				array(
					'mode'          => 'update',
					'error'         => $info['error'],
					'originalTitle' => $original ? html_entity_decode( get_the_title( $original ), ENT_QUOTES, 'UTF-8' ) : '',
					'originalView'  => $original ? (string) get_permalink( $original ) : '',
					'originalEdit'  => $original ? (string) get_edit_post_link( $original->ID, 'raw' ) : '',
					'conflict'      => $info['conflict'] ? sprintf(
						/* translators: %s: date and time. */
						__( 'The live post was edited on %s, after this update was made. Only what you change here will replace it.', 'schedule-post-page-updates' ),
						Schedule_Post_Page_Updates_Copies::format_time( strtotime( $original->post_modified_gmt . ' UTC' ) )
					) : '',
					'applyUrl'      => $original && 'trash' !== $original->post_status ? self::apply_url( $post->ID ) : '',
				)
			);
		}

		if ( ! in_array( $post->post_type, Schedule_Post_Page_Updates_Plugin::post_types(), true ) || ! in_array( $post->post_status, Schedule_Post_Page_Updates_Copies::SOURCE_STATUSES, true ) ) {
			return null;
		}

		$updates = array();

		foreach ( Schedule_Post_Page_Updates_Copies::copies_of( $post->ID ) as $copy_id ) {
			$copy = get_post( $copy_id );
			if ( ! $copy || Schedule_Post_Page_Updates_Copies::STATUS !== $copy->post_status || ! current_user_can( 'edit_post', $copy_id ) ) {
				continue;
			}
			$time      = Schedule_Post_Page_Updates_Copies::time( $copy_id );
			$updates[] = array(
				'id'    => $copy_id,
				'time'  => $time,
				'label' => $time ? Schedule_Post_Page_Updates_Copies::format_time( $time ) : __( 'Not scheduled yet', 'schedule-post-page-updates' ),
				'edit'  => (string) get_edit_post_link( $copy_id, 'raw' ),
			);
		}

		usort(
			$updates,
			function ( $a, $b ) {
				if ( ! $a['time'] || ! $b['time'] ) {
					return $a['time'] ? -1 : ( $b['time'] ? 1 : 0 );
				}
				return $a['time'] - $b['time'];
			}
		);

		return array_merge(
			$data,
			array(
				'mode'        => 'original',
				'updates'     => $updates,
				'createUrl'   => Schedule_Post_Page_Updates_Copies::can_schedule( $post, get_current_user_id() ) ? self::create_url( $post->ID ) : '',
				'canSchedule' => Schedule_Post_Page_Updates_Copies::can_schedule( $post, get_current_user_id() ),
			)
		);
	}

	/**
	 * Load the block editor panel.
	 *
	 * @return void
	 */
	public static function block_editor_assets() {
		$post = self::current_post();

		if ( ! $post ) {
			return;
		}

		$data = self::panel_data( $post );

		if ( ! $data ) {
			return;
		}

		wp_enqueue_script(
			'sppu-editor',
			SCHEDULE_POST_PAGE_UPDATES_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-edit-post', 'wp-notices' ),
			SCHEDULE_POST_PAGE_UPDATES_VERSION,
			true
		);
		// Hex-encode < > & so titles can't close or confuse the <script> tag.
		wp_add_inline_script( 'sppu-editor', 'window.sppuEditor = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		wp_set_script_translations( 'sppu-editor', 'schedule-post-page-updates' );

		wp_enqueue_style( 'sppu-editor', SCHEDULE_POST_PAGE_UPDATES_URL . 'assets/editor.css', array(), SCHEDULE_POST_PAGE_UPDATES_VERSION );
	}

	/**
	 * Styles for the classic editor and the Posts screen.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public static function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'sppu-editor', SCHEDULE_POST_PAGE_UPDATES_URL . 'assets/editor.css', array(), SCHEDULE_POST_PAGE_UPDATES_VERSION );

		// "Apply Now" links ask before replacing a live post.
		wp_add_inline_script(
			'common',
			'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a.sppu-confirm");if(a&&!window.confirm(a.getAttribute("data-confirm"))){e.preventDefault();}});'
		);
	}

	/*
	 * Classic editor
	 */

	/**
	 * Whether the current screen uses the classic editor.
	 *
	 * @return bool
	 */
	private static function is_classic() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'post' === $screen->base && ! ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() );
	}

	/**
	 * Lines in the Publish box: the schedule on an update, the scheduled
	 * updates and a "Schedule an update" link on a live post.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function classic_box( $post ) {
		if ( ! $post instanceof WP_Post || ! self::is_classic() ) {
			return;
		}

		$data = self::panel_data( $post );

		if ( ! $data ) {
			return;
		}

		if ( 'update' === $data['mode'] ) {
			$value = $data['time'] ? wp_date( 'Y-m-d\TH:i', $data['time'] ) : '';
			wp_nonce_field( 'sppu_classic_' . $post->ID, 'sppu_classic_nonce' );
			?>
			<div class="misc-pub-section sppu-classic">
				<?php echo Schedule_Post_Page_Updates_Icons::get( 'calendar-sync', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span>
					<?php esc_html_e( 'Update for:', 'schedule-post-page-updates' ); ?>
					<?php if ( $data['originalEdit'] ) : ?>
						<a href="<?php echo esc_url( $data['originalView'] ); ?>"><strong><?php echo esc_html( $data['originalTitle'] ); ?></strong></a>
					<?php else : ?>
						<strong><?php esc_html_e( 'a deleted post', 'schedule-post-page-updates' ); ?></strong>
					<?php endif; ?>
				</span>
			</div>
			<div class="misc-pub-section sppu-classic">
				<?php echo Schedule_Post_Page_Updates_Icons::get( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span class="sppu-classic-when">
					<label for="sppu-when"><?php esc_html_e( 'Goes live:', 'schedule-post-page-updates' ); ?></label>
					<input type="datetime-local" id="sppu-when" name="sppu_when" value="<?php echo esc_attr( $value ); ?>" step="60">
					<span class="description">
						<?php
						echo esc_html(
							$data['time']
								/* translators: %s: "in 3 days". */
								? sprintf( __( 'Scheduled, %s.', 'schedule-post-page-updates' ), $data['relative'] )
								: __( 'Not scheduled yet. Pick a time, then Schedule Update.', 'schedule-post-page-updates' )
						);
						?>
						<?php
						/* translators: %s: time zone name. */
						echo esc_html( sprintf( __( 'Time zone: %s.', 'schedule-post-page-updates' ), $data['timezone'] ) );
						?>
					</span>
				</span>
			</div>
			<?php if ( $data['error'] ) : ?>
				<div class="misc-pub-section sppu-classic sppu-classic-error">
					<?php echo Schedule_Post_Page_Updates_Icons::get( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php echo esc_html( $data['error'] ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $data['conflict'] ) : ?>
				<div class="misc-pub-section sppu-classic sppu-classic-warning">
					<?php echo Schedule_Post_Page_Updates_Icons::get( 'git-compare', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php echo esc_html( $data['conflict'] ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $data['applyUrl'] ) : ?>
				<div class="misc-pub-section sppu-classic">
					<?php echo Schedule_Post_Page_Updates_Icons::get( 'zap', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><a href="<?php echo esc_url( $data['applyUrl'] ); ?>" class="sppu-confirm" data-confirm="<?php esc_attr_e( 'Replace the live post with this update now? Save your changes first.', 'schedule-post-page-updates' ); ?>"><?php esc_html_e( 'Apply now instead', 'schedule-post-page-updates' ); ?></a></span>
				</div>
			<?php endif; ?>
			<?php
			return;
		}
		?>
		<div class="misc-pub-section sppu-classic">
			<?php echo Schedule_Post_Page_Updates_Icons::get( 'calendar-clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<span>
				<?php if ( $data['updates'] ) : ?>
					<?php esc_html_e( 'Scheduled updates:', 'schedule-post-page-updates' ); ?>
					<?php foreach ( $data['updates'] as $i => $update ) : ?>
						<?php echo $i ? ', ' : ' '; ?><a href="<?php echo esc_url( $update['edit'] ); ?>"><?php echo esc_html( $update['label'] ); ?></a>
					<?php endforeach; ?>
					<br>
				<?php endif; ?>
				<?php if ( $data['createUrl'] ) : ?>
					<a href="<?php echo esc_url( $data['createUrl'] ); ?>"><?php esc_html_e( 'Schedule an update', 'schedule-post-page-updates' ); ?></a>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * The "Schedule Update" button that stands in for Publish on an update.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function classic_button( $post ) {
		if ( ! $post instanceof WP_Post || ! self::is_classic() || Schedule_Post_Page_Updates_Copies::STATUS !== $post->post_status ) {
			return;
		}
		?>
		<div class="sppu-classic-action">
			<input type="submit" name="sppu_schedule" class="button button-primary button-large" value="<?php echo esc_attr( Schedule_Post_Page_Updates_Copies::time( $post->ID ) ? __( 'Update Schedule', 'schedule-post-page-updates' ) : __( 'Schedule Update', 'schedule-post-page-updates' ) ); ?>">
		</div>
		<?php
	}

	/**
	 * Save the classic editor's "Goes live" field.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function classic_save( $post_id, $post ) {
		if ( ! isset( $_POST['sppu_classic_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sppu_classic_nonce'] ) ), 'sppu_classic_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || Schedule_Post_Page_Updates_Copies::STATUS !== $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$when = isset( $_POST['sppu_when'] ) ? sanitize_text_field( wp_unslash( $_POST['sppu_when'] ) ) : '';

		if ( '' === $when ) {
			if ( Schedule_Post_Page_Updates_Copies::time( $post_id ) ) {
				Schedule_Post_Page_Updates_Copies::clear_time( $post_id );
				self::$classic_message = 'unscheduled';
			} elseif ( isset( $_POST['sppu_schedule'] ) ) {
				self::$classic_message = 'nodate';
			}
			return;
		}

		$time = self::parse_local( $when );

		if ( Schedule_Post_Page_Updates_Copies::time( $post_id ) === $time ) {
			self::$classic_message = isset( $_POST['sppu_schedule'] ) ? 'scheduled' : '';
			return;
		}

		$result = $time ? Schedule_Post_Page_Updates_Copies::set_time( $post_id, $time, get_current_user_id() ) : new WP_Error( 'sppu_invalid', '' );

		self::$classic_message = is_wp_error( $result ) ? 'past' : 'scheduled';
	}

	/**
	 * Carry the classic save's message to the next screen.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public static function classic_redirect( $location, $post_id ) {
		if ( '' === self::$classic_message || ! Schedule_Post_Page_Updates_Copies::is_copy( $post_id ) ) {
			return $location;
		}

		// WordPress's own "Post published." would be wrong for an update.
		return add_query_arg( 'sppu_msg', self::$classic_message, remove_query_arg( 'message', $location ) );
	}

	/**
	 * Text for a message key.
	 *
	 * @param string  $key  Message key.
	 * @param WP_Post $post Post on screen.
	 * @return array{type: string, text: string}|null
	 */
	private static function message_text( $key, $post ) {
		$time = $post ? Schedule_Post_Page_Updates_Copies::time( $post->ID ) : 0;

		switch ( $key ) {
			case 'created':
				return array(
					'type' => 'info',
					'text' => __( 'This is a private copy of the live post. Make your changes, pick when they go live, and click Schedule Update. Nothing changes on the site until then.', 'schedule-post-page-updates' ),
				);
			case 'scheduled':
				return array(
					'type' => 'success',
					/* translators: %s: date and time. */
					'text' => $time ? sprintf( __( 'Update scheduled. It replaces the live post on %s.', 'schedule-post-page-updates' ), Schedule_Post_Page_Updates_Copies::format_time( $time ) ) : __( 'Update saved.', 'schedule-post-page-updates' ),
				);
			case 'unscheduled':
				return array(
					'type' => 'info',
					'text' => __( 'Update unscheduled. It\'s kept as a draft until you pick a new time.', 'schedule-post-page-updates' ),
				);
			case 'nodate':
				return array(
					'type' => 'warning',
					'text' => __( 'Your changes are saved. Pick a date and time to schedule them.', 'schedule-post-page-updates' ),
				);
			case 'past':
				return array(
					'type' => 'error',
					'text' => __( 'Your changes are saved, but the time wasn\'t: pick a date and time in the future.', 'schedule-post-page-updates' ),
				);
			case 'applied':
				return array(
					'type' => 'success',
					'text' => __( 'The scheduled update is now live. You can undo it from Content Powered > Schedule Post and Page Updates > History.', 'schedule-post-page-updates' ),
				);
			case 'failed':
				return array(
					'type' => 'error',
					'text' => __( 'The update could not be applied. The reason is shown below.', 'schedule-post-page-updates' ),
				);
		}

		return null;
	}

	/**
	 * Notices on classic editor screens and the Posts list.
	 *
	 * @return void
	 */
	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'edit' ), true ) || ( 'post' === $screen->base && ! self::is_classic() ) ) {
			return;
		}

		$key  = isset( $_GET['sppu_msg'] ) ? sanitize_key( wp_unslash( $_GET['sppu_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$post = 'post' === $screen->base ? get_post() : null;
		$msg  = self::message_text( $key, $post );

		if ( $msg ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $msg['type'] ), esc_html( $msg['text'] ) );
		}

		if ( $post && Schedule_Post_Page_Updates_Copies::STATUS === $post->post_status && 'created' !== $key ) {
			$original = get_post( Schedule_Post_Page_Updates_Copies::original_id( $post ) );
			if ( $original ) {
				printf(
					'<div class="notice notice-info sppu-notice"><p>%s</p></div>',
					wp_kses(
						sprintf(
							/* translators: %s: linked post title. */
							__( 'You\'re editing a scheduled update of %s. Visitors keep seeing the live version until it goes live.', 'schedule-post-page-updates' ),
							'<a href="' . esc_url( get_permalink( $original ) ) . '"><strong>' . esc_html( get_the_title( $original ) ) . '</strong></a>'
						),
						array(
							'a'      => array( 'href' => array() ),
							'strong' => array(),
						)
					)
				);
			}
		}
	}
}
