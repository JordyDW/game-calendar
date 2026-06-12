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
		add_submenu_page( 'game-calendar', __( 'All Items', 'game-calendar' ),       __( 'All Items', 'game-calendar' ),       'edit_posts',    'gc-entries',                          array( $this, 'render_entries_page' ) );
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
		if ( 'game-calendar_page_gc-entries' === $hook ) {
			wp_enqueue_style( 'gc-admin-calendar', GC_PLUGIN_URL . 'admin/css/admin-calendar.css', array(), GC_VERSION );
			wp_enqueue_script( 'gc-entries', GC_PLUGIN_URL . 'admin/js/entries.js', array( 'jquery' ), GC_VERSION, true );
			wp_localize_script( 'gc-entries', 'gcEntries', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gc_admin_cal' ),
			) );
			return;
		}

		if ( 'toplevel_page_game-calendar' !== $hook ) {
			return;
		}

		wp_enqueue_media();
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
							<label class="gc-label"><?php esc_html_e( 'Start', 'game-calendar' ); ?> <span class="gc-required">*</span></label>
							<div class="gc-date-time-row">
								<input type="date" id="gc-modal-start-date" class="gc-input" />
								<input type="time" id="gc-modal-start-time" class="gc-input" />
							</div>
						</div>
						<div class="gc-field gc-field--event">
							<label class="gc-label"><?php esc_html_e( 'End', 'game-calendar' ); ?></label>
							<div class="gc-date-time-row">
								<input type="date" id="gc-modal-end-date" class="gc-input" />
								<input type="time" id="gc-modal-end-time" class="gc-input" />
							</div>
						</div>
						<div class="gc-field gc-field--event">
							<label class="gc-label" for="gc-modal-event-url"><?php esc_html_e( 'Event URL', 'game-calendar' ); ?></label>
							<input type="url" id="gc-modal-event-url" class="gc-input" placeholder="https://…" />
						</div>
						<div class="gc-field gc-field--event">
							<label class="gc-label" for="gc-modal-event-address"><?php esc_html_e( 'Address', 'game-calendar' ); ?></label>
							<input type="text" id="gc-modal-event-address" class="gc-input" placeholder="<?php esc_attr_e( 'Venue name or street address', 'game-calendar' ); ?>" />
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

						<div class="gc-field gc-field--release">
							<label class="gc-label" for="gc-modal-cover-url"><?php esc_html_e( 'Cover Image', 'game-calendar' ); ?></label>
							<div class="gc-cover-field">
								<input type="url" id="gc-modal-cover-url" class="gc-input" placeholder="https://…" />
								<button type="button" id="gc-modal-cover-pick" class="button"><?php esc_html_e( 'Choose', 'game-calendar' ); ?></button>
							</div>
							<img id="gc-modal-cover-img" src="" alt="" class="gc-cover-preview" style="display:none;" />
						</div>

						<div class="gc-field gc-field--event">
							<label class="gc-label" for="gc-modal-event-cover-url"><?php esc_html_e( 'Cover Image', 'game-calendar' ); ?></label>
							<div class="gc-cover-field">
								<input type="url" id="gc-modal-event-cover-url" class="gc-input" placeholder="https://…" />
								<button type="button" id="gc-modal-event-cover-pick" class="button"><?php esc_html_e( 'Choose', 'game-calendar' ); ?></button>
							</div>
							<img id="gc-modal-event-cover-img" src="" alt="" class="gc-cover-preview" style="display:none;" />
						</div>

						<input type="hidden" id="gc-modal-igdb-id" />
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
			if ( ! $post || ! in_array( $post->post_type, array( 'gc_release', 'gc_event', 'gc_dlc' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Entry not found.', 'game-calendar' ) ) );
			}
			$post_type  = $post->post_type;
			$update_arr = array( 'ID' => $editing_id, 'post_title' => $title );
			if ( isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'publish', 'draft' ), true ) ) {
				$update_arr['post_status'] = sanitize_key( wp_unslash( $_POST['post_status'] ) );
			}
			$result = wp_update_post( $update_arr, true );
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

		$text_fields = array( 'gc_release_date', 'gc_event_start', 'gc_event_end', 'gc_event_url', 'gc_event_address', 'gc_developer', 'gc_publisher', 'gc_genre' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) && '' !== $_POST[ $field ] ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		foreach ( array( 'gc_url', 'gc_cover_url', 'gc_event_cover_url' ) as $url_field ) {
			if ( isset( $_POST[ $url_field ] ) && '' !== $_POST[ $url_field ] ) {
				update_post_meta( $post_id, $url_field, esc_url_raw( wp_unslash( $_POST[ $url_field ] ) ) );
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
			'gc_dlc'     => $options['gc_color_release'] ?? '#3b82f6',
		);

		$date_field = ( 'gc_event' === $post_type ) ? 'gc_event_start' : 'gc_release_date';

		$cover = ( 'gc_event' === $post_type )
			? get_post_meta( $post_id, 'gc_event_cover_url', true )
			: get_post_meta( $post_id, 'gc_cover_url', true );

		wp_send_json_success( array(
			'id'            => $post_id,
			'title'         => $title,
			'start'         => get_post_meta( $post_id, $date_field, true ),
			'post_status'   => get_post_field( 'post_status', $post_id ),
			'color'         => $color_map[ $post_type ] ?? '#3b82f6',
			'extendedProps' => array(
				'type'      => $post_type,
				'cover'     => $cover,
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
			$data['gc_event_start']     = get_post_meta( $post_id, 'gc_event_start', true );
			$data['gc_event_end']       = get_post_meta( $post_id, 'gc_event_end', true );
			$data['gc_event_url']       = get_post_meta( $post_id, 'gc_event_url', true );
			$data['gc_event_address']   = get_post_meta( $post_id, 'gc_event_address', true );
			$data['gc_event_cover_url'] = get_post_meta( $post_id, 'gc_event_cover_url', true );
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

	public function render_entries_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$valid_types = array( 'gc_release', 'gc_event', 'gc_dlc' );
		$type_filter = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$type_filter = in_array( $type_filter, $valid_types, true ) ? $type_filter : '';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby     = ( isset( $_GET['orderby'] ) && 'date' === $_GET['orderby'] ) ? 'date' : 'date';
		$order       = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'asc' : 'desc';

		$type_labels = array(
			'gc_release' => __( 'Release', 'game-calendar' ),
			'gc_event'   => __( 'Event', 'game-calendar' ),
			'gc_dlc'     => __( 'DLC', 'game-calendar' ),
		);

		$counts = array();
		foreach ( $valid_types as $t ) {
			$c            = wp_count_posts( $t );
			$counts[ $t ] = ( (int) ( $c->publish ?? 0 ) ) + ( (int) ( $c->draft ?? 0 ) );
		}
		$total = array_sum( $counts );

		$query_types = $type_filter ? array( $type_filter ) : $valid_types;
		$query_args  = array(
			'post_type'      => $query_types,
			'posts_per_page' => 300,
			'post_status'    => array( 'publish', 'draft' ),
			'no_found_rows'  => true,
		);
		if ( $search ) {
			$query_args['s'] = $search;
		}
		$posts = get_posts( $query_args );

		// Sort by event/release date (different meta keys per type, so sort in PHP).
		usort( $posts, function ( $a, $b ) use ( $order ) {
			$da = get_post_meta( $a->ID, ( 'gc_event' === $a->post_type ) ? 'gc_event_start' : 'gc_release_date', true ) ?: '0000-00-00';
			$db = get_post_meta( $b->ID, ( 'gc_event' === $b->post_type ) ? 'gc_event_start' : 'gc_release_date', true ) ?: '0000-00-00';
			$cmp = strcmp( $da, $db );
			return 'asc' === $order ? $cmp : -$cmp;
		} );

		$page_url    = admin_url( 'admin.php?page=gc-entries' );
		$filter_qs   = ( $type_filter ? '&type=' . $type_filter : '' ) . ( $search ? '&s=' . urlencode( $search ) : '' );

		// Sortable column helpers.
		$next_date_order = ( 'asc' === $order ) ? 'desc' : 'asc';
		$date_sort_url   = add_query_arg( array( 'orderby' => 'date', 'order' => $next_date_order ), $page_url . $filter_qs );
		$date_col_class  = 'column-date sorted ' . $order;
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All Items', 'game-calendar' ); ?></h1>
			<hr class="wp-header-end" />

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:0;">
				<input type="hidden" name="page" value="gc-entries" />
				<?php if ( $type_filter ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $type_filter ); ?>" /><?php endif; ?>
				<?php if ( $orderby ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" /><?php endif; ?>
				<?php if ( $order ) : ?><input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" /><?php endif; ?>
				<p class="search-box">
					<label for="gc-search-input" class="screen-reader-text"><?php esc_html_e( 'Search items', 'game-calendar' ); ?></label>
					<input type="search" id="gc-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by title…', 'game-calendar' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'game-calendar' ); ?></button>
				</p>
			</form>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( $page_url . ( $search ? '&s=' . urlencode( $search ) : '' ) . '&orderby=' . $orderby . '&order=' . $order ); ?>" <?php echo '' === $type_filter ? 'class="current" aria-current="page"' : ''; ?>>
						<?php esc_html_e( 'All', 'game-calendar' ); ?> <span class="count">(<?php echo absint( $total ); ?>)</span>
					</a> |
				</li>
				<?php $last = end( $valid_types ); foreach ( $valid_types as $t ) : ?>
					<li>
						<a href="<?php echo esc_url( $page_url . '&type=' . $t . ( $search ? '&s=' . urlencode( $search ) : '' ) . '&orderby=' . $orderby . '&order=' . $order ); ?>" <?php echo $type_filter === $t ? 'class="current" aria-current="page"' : ''; ?>>
							<?php echo esc_html( $type_labels[ $t ] ); ?> <span class="count">(<?php echo absint( $counts[ $t ] ); ?>)</span>
						</a><?php echo $t !== $last ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<table class="wp-list-table widefat fixed striped gc-entries-table">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-type"><?php esc_html_e( 'Type', 'game-calendar' ); ?></th>
						<th scope="col" class="manage-column column-title column-primary"><?php esc_html_e( 'Title', 'game-calendar' ); ?></th>
						<th scope="col" class="manage-column <?php echo esc_attr( $date_col_class ); ?>">
							<a href="<?php echo esc_url( $date_sort_url ); ?>">
								<span><?php esc_html_e( 'Date', 'game-calendar' ); ?></span>
								<span class="sorting-indicators">
									<span class="sorting-indicator asc" aria-hidden="true"></span>
									<span class="sorting-indicator desc" aria-hidden="true"></span>
								</span>
							</a>
						</th>
						<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'game-calendar' ); ?></th>
					</tr>
				</thead>
				<tbody id="the-list">
					<?php if ( empty( $posts ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No items found.', 'game-calendar' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $posts as $entry ) :
							$ptype     = $entry->post_type;
							$meta_key  = ( 'gc_event' === $ptype ) ? 'gc_event_start' : 'gc_release_date';
							$date_val  = get_post_meta( $entry->ID, $meta_key, true );
							$date_end  = ( 'gc_event' === $ptype ) ? get_post_meta( $entry->ID, 'gc_event_end', true ) : '';
							$edit_link  = get_edit_post_link( $entry->ID );
							$trash_link = get_delete_post_link( $entry->ID );
							$badge_mod  = str_replace( 'gc_', '', $ptype );

							// Parse date parts for the quick edit form.
							$s_date = ''; $s_time = ''; $e_date = ''; $e_time = '';
							if ( $date_val ) {
								if ( false !== strpos( $date_val, 'T' ) ) {
									list( $s_date, $s_time ) = explode( 'T', $date_val, 2 );
								} else {
									$s_date = $date_val;
								}
							}
							if ( $date_end ) {
								if ( false !== strpos( $date_end, 'T' ) ) {
									list( $e_date, $e_time ) = explode( 'T', $date_end, 2 );
								} else {
									$e_date = $date_end;
								}
							}

							$entry_json = array(
								'id'     => $entry->ID,
								'type'   => $ptype,
								'title'  => $entry->post_title,
								'date'   => $date_val,
								'status' => $entry->post_status,
							);
							if ( 'gc_event' === $ptype ) {
								$entry_json['startDate'] = $s_date;
								$entry_json['startTime'] = $s_time;
								$entry_json['endDate']   = $e_date;
								$entry_json['endTime']   = $e_time;
							}
						?>
							<tr id="post-<?php echo absint( $entry->ID ); ?>" data-entry="<?php echo esc_attr( wp_json_encode( $entry_json ) ); ?>">
								<td class="column-type">
									<span class="gc-entry-badge gc-entry-badge--<?php echo esc_attr( $badge_mod ); ?>">
										<?php echo esc_html( $type_labels[ $ptype ] ?? $ptype ); ?>
									</span>
								</td>
								<td class="column-title column-primary">
									<strong>
										<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $entry->post_title ?: __( '(no title)', 'game-calendar' ) ); ?></a>
									</strong>
									<div class="row-actions">
										<span class="edit"><a href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'game-calendar' ); ?></a></span>
										<span> | </span><span class="inline hide-if-no-js"><a href="#" class="gc-qe-trigger"><?php esc_html_e( 'Quick Edit', 'game-calendar' ); ?></a></span>
										<?php if ( $trash_link ) : ?>
											<span> | </span><span class="trash"><a href="<?php echo esc_url( $trash_link ); ?>" class="submitdelete"><?php esc_html_e( 'Trash', 'game-calendar' ); ?></a></span>
										<?php endif; ?>
									</div>
								</td>
								<td class="column-date"><?php echo esc_html( $date_val ?: '—' ); ?></td>
								<td class="column-status">
									<?php if ( 'publish' === $entry->post_status ) : ?>
										<span class="gc-status-dot gc-status-dot--published"></span><?php esc_html_e( 'Published', 'game-calendar' ); ?>
									<?php else : ?>
										<span class="gc-status-dot gc-status-dot--draft"></span><?php esc_html_e( 'Draft', 'game-calendar' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
