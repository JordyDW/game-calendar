<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Admin_Calendar {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_gc_quick_add', array( $this, 'ajax_quick_add' ) );
		add_action( 'wp_ajax_gc_get_entry', array( $this, 'ajax_get_entry' ) );
		add_action( 'wp_ajax_gc_delete_entry', array( $this, 'ajax_delete_entry' ) );
		add_filter( 'parent_file', array( $this, 'fix_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'fix_submenu_file' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Game Calendar', 'game-calendar' ),
			__( 'Game Calendar', 'game-calendar' ),
			'edit_posts',
			'game-calendar',
			array( $this, 'render_page' ),
			'dashicons-calendar-alt',
			25
		);

		// Calendar must be first so clicking the parent menu item lands here.
		add_submenu_page( 'game-calendar', __( 'Calendar', 'game-calendar' ),       __( 'Calendar', 'game-calendar' ),       'edit_posts',    'game-calendar',                       array( $this, 'render_page' ) );
		add_submenu_page( 'game-calendar', __( 'Settings', 'game-calendar' ),        __( 'Settings', 'game-calendar' ),        'manage_options', 'gc-settings',                        array( new GC_Settings(), 'render' ) );
	}

	public function fix_parent_file( $parent_file ) {
		global $current_screen;
		if ( $current_screen && in_array( $current_screen->post_type, array( 'gc_release', 'gc_event', 'gc_dlc' ), true ) ) {
			return 'game-calendar';
		}
		return $parent_file;
	}

	public function fix_submenu_file( $submenu_file ) {
		global $current_screen;
		if ( $current_screen && in_array( $current_screen->post_type, array( 'gc_release', 'gc_event', 'gc_dlc' ), true ) ) {
			return 'edit.php?post_type=' . $current_screen->post_type;
		}
		return $submenu_file;
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_game-calendar' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css', array(), '6.1.11' );
		wp_enqueue_script( 'fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js', array(), '6.1.11', true );
		wp_enqueue_style( 'gc-admin-calendar', GC_PLUGIN_URL . 'admin/css/admin-calendar.css', array( 'fullcalendar' ), GC_VERSION );
		wp_enqueue_script( 'gc-admin-calendar', GC_PLUGIN_URL . 'admin/js/admin-calendar.js', array( 'fullcalendar', 'jquery' ), GC_VERSION, true );

		wp_localize_script( 'gc-admin-calendar', 'gcAdminCal', array(
			'restUrl'   => esc_url_raw( rest_url( 'game-calendar/v1/events' ) ),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'adminUrl'  => admin_url(),
			'nonce'     => wp_create_nonce( 'gc_admin_cal' ),
			'igdbNonce' => wp_create_nonce( 'gc_igdb_search' ),
			'colors'    => array(
				'gc_release' => GC_Settings::get( 'gc_color_release', '#ac00fb' ),
				'gc_event'   => GC_Settings::get( 'gc_color_event', '#96eefe' ),
			),
		) );
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="gc-admin-page">
			<div class="gc-toolbar">
				<div class="gc-toolbar-left">
					<span class="dashicons dashicons-calendar-alt gc-toolbar-icon"></span>
					<h1 class="gc-toolbar-title"><?php esc_html_e( 'Game Calendar', 'game-calendar' ); ?></h1>
				</div>
				<button id="gc-add-entry-btn" class="gc-add-btn">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add Release or Event', 'game-calendar' ); ?>
				</button>
			</div>
			<div class="gc-calendar-card">
				<div id="gc-admin-calendar"></div>
			</div>

			<div id="gc-modal" class="gc-modal" hidden role="dialog" aria-modal="true" aria-labelledby="gc-modal-heading">
				<div class="gc-modal-backdrop"></div>
				<div class="gc-modal-panel">
					<div class="gc-modal-head">
						<h2 id="gc-modal-heading" class="gc-modal-title"><?php esc_html_e( 'Add Entry', 'game-calendar' ); ?></h2>
						<button class="gc-modal-close" aria-label="<?php esc_attr_e( 'Close', 'game-calendar' ); ?>">&#x2715;</button>
					</div>

					<div class="gc-type-tabs" role="tablist">
						<button class="gc-tab gc-tab--active" data-type="gc_release" role="tab">
							<?php esc_html_e( 'Game Release', 'game-calendar' ); ?>
						</button>
						<button class="gc-tab" data-type="gc_event" role="tab">
							<?php esc_html_e( 'Gaming Event', 'game-calendar' ); ?>
						</button>
					</div>

					<div class="gc-modal-body">

						<div class="gc-field gc-field--release">
							<label class="gc-label"><?php esc_html_e( 'Search IGDB', 'game-calendar' ); ?></label>
							<div class="gc-igdb-wrap">
								<input type="text" id="gc-modal-igdb" placeholder="<?php esc_attr_e( 'Type to search IGDB…', 'game-calendar' ); ?>" autocomplete="off" class="gc-input" />
								<div id="gc-modal-igdb-results" class="gc-igdb-results" hidden></div>
							</div>
							<p class="gc-igdb-hint">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'Selecting a result will overwrite all fields below.', 'game-calendar' ); ?>
							</p>
						</div>

						<div class="gc-field gc-field--all">
							<label class="gc-label" for="gc-modal-title"><?php esc_html_e( 'Title', 'game-calendar' ); ?> <span class="gc-required">*</span></label>
							<input type="text" id="gc-modal-title" class="gc-input" required />
						</div>

						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-date"><?php esc_html_e( 'Release Date', 'game-calendar' ); ?></label>
							<input type="date" id="gc-modal-date" class="gc-input" />
						</div>

						<div class="gc-field gc-field--event">
							<label class="gc-label" for="gc-modal-start"><?php esc_html_e( 'Start', 'game-calendar' ); ?></label>
							<input type="datetime-local" id="gc-modal-start" class="gc-input" />
						</div>
						<div class="gc-field gc-field--event">
							<label class="gc-label" for="gc-modal-end"><?php esc_html_e( 'End', 'game-calendar' ); ?></label>
							<input type="datetime-local" id="gc-modal-end" class="gc-input" />
						</div>
						<div class="gc-field gc-field--event">
							<label class="gc-label" for="gc-modal-event-url"><?php esc_html_e( 'Event URL', 'game-calendar' ); ?></label>
							<input type="url" id="gc-modal-event-url" class="gc-input" placeholder="https://…" />
						</div>

						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-developer"><?php esc_html_e( 'Developer', 'game-calendar' ); ?></label>
							<input type="text" id="gc-modal-developer" class="gc-input" />
						</div>
						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-publisher"><?php esc_html_e( 'Publisher', 'game-calendar' ); ?></label>
							<input type="text" id="gc-modal-publisher" class="gc-input" />
						</div>
						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-genre"><?php esc_html_e( 'Genre', 'game-calendar' ); ?></label>
							<input type="text" id="gc-modal-genre" class="gc-input" />
						</div>
						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-platforms"><?php esc_html_e( 'Platforms', 'game-calendar' ); ?></label>
							<input type="text" id="gc-modal-platforms" class="gc-input" placeholder="PC, PS5, Xbox…" />
						</div>
						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-url"><?php esc_html_e( 'URL', 'game-calendar' ); ?></label>
							<input type="url" id="gc-modal-url" class="gc-input" placeholder="https://www.igdb.com/games/…" />
						</div>

						<div class="gc-field gc-field--release" id="gc-modal-cover-row" style="display:none;">
							<label class="gc-label"><?php esc_html_e( 'Cover', 'game-calendar' ); ?></label>
							<img id="gc-modal-cover-img" src="" alt="" class="gc-cover-preview" />
						</div>

						<input type="hidden" id="gc-modal-igdb-id" />
						<input type="hidden" id="gc-modal-cover-url" />
					</div>

					<div class="gc-modal-foot">
						<span id="gc-modal-error" class="gc-modal-error" hidden></span>
						<button id="gc-modal-save" class="button button-primary"><?php esc_html_e( 'Save', 'game-calendar' ); ?></button>
						<button class="gc-modal-close button button-secondary"><?php esc_html_e( 'Cancel', 'game-calendar' ); ?></button>
					</div>
				</div>
			</div>

			<div id="gc-popover" class="gc-admin-popover" hidden></div>
		</div>
		<?php
	}

	public function ajax_quick_add() {
		check_ajax_referer( 'gc_admin_cal', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}

		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( ! $title ) {
			wp_send_json_error( array( 'message' => __( 'Title is required.', 'game-calendar' ) ) );
		}

		$editing_id = absint( $_POST['post_id'] ?? 0 );

		if ( $editing_id ) {
			// Update existing post.
			$post = get_post( $editing_id );
			if ( ! $post || ! in_array( $post->post_type, array( 'gc_release', 'gc_event' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Entry not found.', 'game-calendar' ) ) );
			}
			$post_type = $post->post_type;
			$result    = wp_update_post( array( 'ID' => $editing_id, 'post_title' => $title ), true );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$post_id = $editing_id;
		} else {
			// Create new post.
			$post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );
			if ( ! in_array( $post_type, array( 'gc_release', 'gc_event' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid type.', 'game-calendar' ) ) );
			}
			$post_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_type'   => $post_type,
				'post_status' => 'publish',
			), true );
			if ( is_wp_error( $post_id ) ) {
				wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
			}
		}

		$text_fields = array( 'gc_release_date', 'gc_event_start', 'gc_event_end', 'gc_event_url', 'gc_developer', 'gc_publisher', 'gc_genre', 'gc_cover_url' );
		if ( ! empty( $_POST['gc_url'] ) ) {
			update_post_meta( $post_id, 'gc_url', esc_url_raw( wp_unslash( $_POST['gc_url'] ) ) );
		}
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) && '' !== $_POST[ $field ] ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		if ( ! empty( $_POST['gc_igdb_id'] ) ) {
			update_post_meta( $post_id, 'gc_igdb_id', absint( $_POST['gc_igdb_id'] ) );
		}
		if ( ! empty( $_POST['gc_parent_game'] ) ) {
			update_post_meta( $post_id, 'gc_parent_game', absint( $_POST['gc_parent_game'] ) );
		}

		if ( ! empty( $_POST['gc_platforms'] ) ) {
			$platforms = array_filter( array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['gc_platforms'] ) ) ) );
			if ( $platforms ) {
				wp_set_object_terms( $post_id, array_values( $platforms ), 'gc_platform' );
			}
		}

		$options   = get_option( 'gc_options', array() );
		$color_map = array(
			'gc_release' => $options['gc_color_release'] ?? '#3b82f6',
			'gc_event'   => $options['gc_color_event']   ?? '#f97316',
		);

		$date_field = ( 'gc_event' === $post_type ) ? 'gc_event_start' : 'gc_release_date';

		wp_send_json_success( array(
			'id'            => $post_id,
			'title'         => $title,
			'start'         => get_post_meta( $post_id, $date_field, true ),
			'color'         => $color_map[ $post_type ],
			'extendedProps' => array(
				'type'      => $post_type,
				'cover'     => get_post_meta( $post_id, 'gc_cover_url', true ),
				'developer' => get_post_meta( $post_id, 'gc_developer', true ),
			),
		) );
	}

	public function ajax_get_entry() {
		check_ajax_referer( 'gc_admin_cal', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'gc_release', 'gc_event' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'game-calendar' ) ) );
		}

		$data = array(
			'post_id'   => $post_id,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
		);

		if ( 'gc_release' === $post->post_type ) {
			$data['gc_release_date'] = get_post_meta( $post_id, 'gc_release_date', true );
			$data['gc_developer']    = get_post_meta( $post_id, 'gc_developer', true );
			$data['gc_publisher']    = get_post_meta( $post_id, 'gc_publisher', true );
			$data['gc_genre']        = get_post_meta( $post_id, 'gc_genre', true );
			$data['gc_cover_url']    = get_post_meta( $post_id, 'gc_cover_url', true );
			$data['gc_igdb_id']      = get_post_meta( $post_id, 'gc_igdb_id', true );
			$data['gc_url']          = get_post_meta( $post_id, 'gc_url', true );
			$platforms               = wp_get_post_terms( $post_id, 'gc_platform', array( 'fields' => 'names' ) );
			$data['gc_platforms']    = is_wp_error( $platforms ) ? '' : implode( ', ', $platforms );
		} else {
			$data['gc_event_start'] = get_post_meta( $post_id, 'gc_event_start', true );
			$data['gc_event_end']   = get_post_meta( $post_id, 'gc_event_end', true );
			$data['gc_event_url']   = get_post_meta( $post_id, 'gc_event_url', true );
		}

		wp_send_json_success( $data );
	}

	public function ajax_delete_entry() {
		check_ajax_referer( 'gc_admin_cal', 'nonce' );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid entry.', 'game-calendar' ) ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'gc_release', 'gc_event', 'gc_dlc' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Entry not found.', 'game-calendar' ) ) );
		}

		wp_trash_post( $post_id );
		wp_send_json_success();
	}
}
