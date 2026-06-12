<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Settings {

	const OPTION_GROUP = 'gc_settings';
	const OPTION_NAME  = 'gc_options';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_gc_test_igdb_connection', array( $this, 'ajax_test_connection' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( 'game-calendar_page_gc-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'gc-admin-calendar', GC_PLUGIN_URL . 'admin/css/admin-calendar.css', array(), GC_VERSION );
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array(
			'sanitize_callback' => array( $this, 'sanitize' ),
		) );
	}

	public function sanitize( $input ) {
		$output   = array();
		$existing = get_option( self::OPTION_NAME, array() );

		$output['gc_igdb_client_id'] = isset( $input['gc_igdb_client_id'] )
			? sanitize_text_field( $input['gc_igdb_client_id'] )
			: ( $existing['gc_igdb_client_id'] ?? '' );

		$valid_modes = array( 'future_releases', 'all_releases' );
		$output['gc_igdb_search_mode'] = ( isset( $input['gc_igdb_search_mode'] ) && in_array( $input['gc_igdb_search_mode'], $valid_modes, true ) )
			? $input['gc_igdb_search_mode']
			: ( $existing['gc_igdb_search_mode'] ?? 'future_releases' );

		// Only update secret if a new non-empty value is provided.
		$output['gc_igdb_client_secret'] = ( isset( $input['gc_igdb_client_secret'] ) && '' !== $input['gc_igdb_client_secret'] )
			? sanitize_text_field( $input['gc_igdb_client_secret'] )
			: ( $existing['gc_igdb_client_secret'] ?? '' );

		// Only update GitHub token if a new non-empty value is provided.
		$output['gc_github_token'] = ( isset( $input['gc_github_token'] ) && '' !== $input['gc_github_token'] )
			? sanitize_text_field( $input['gc_github_token'] )
			: ( $existing['gc_github_token'] ?? '' );

		foreach ( array( 'gc_color_release', 'gc_color_event' ) as $color_key ) {
			$output[ $color_key ] = isset( $input[ $color_key ] )
				? sanitize_hex_color( $input[ $color_key ] )
				: ( $existing[ $color_key ] ?? '' );
		}

		// Preserve legacy gc_color_dlc without exposing it in the UI.
		$output['gc_color_dlc'] = $existing['gc_color_dlc'] ?? '#22c55e';

		// Invalidate cached IGDB token when credentials change.
		if (
			( $output['gc_igdb_client_id'] !== ( $existing['gc_igdb_client_id'] ?? '' ) ) ||
			( isset( $input['gc_igdb_client_secret'] ) && '' !== $input['gc_igdb_client_secret'] )
		) {
			delete_transient( 'gc_igdb_token' );
		}

		return $output;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options        = get_option( self::OPTION_NAME, array() );
		$client_id      = $options['gc_igdb_client_id']     ?? '';
		$secret_stored  = $options['gc_igdb_client_secret'] ?? '';
		$github_stored  = $options['gc_github_token']       ?? '';
		$color_release  = $options['gc_color_release']      ?? '#ac00fb';
		$color_event    = $options['gc_color_event']        ?? '#96eefe';
		$search_mode    = $options['gc_igdb_search_mode']   ?? 'future_releases';
		?>
		<div class="gc-admin-page">

			<div class="gc-toolbar">
				<div class="gc-toolbar-left">
					<span class="dashicons dashicons-admin-settings gc-toolbar-icon"></span>
					<h1 class="gc-toolbar-title"><?php esc_html_e( 'Settings', 'game-calendar' ); ?></h1>
				</div>
			</div>

			<form method="post" action="options.php" class="gc-settings-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<div class="gc-settings-section">
					<div class="gc-settings-section-head">
						<h2 class="gc-settings-section-title">
							<span class="dashicons dashicons-games"></span>
							<?php esc_html_e( 'IGDB API Credentials', 'game-calendar' ); ?>
						</h2>
						<p class="gc-settings-section-desc">
							<?php echo wp_kses(
								__( 'Register a free application at <a href="https://dev.twitch.tv/console/apps" target="_blank" rel="noopener">dev.twitch.tv</a> to obtain your Client ID and Secret. Used for the IGDB game search in the quick-add modal.', 'game-calendar' ),
								array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
							); ?>
						</p>
					</div>
					<div class="gc-settings-section-body">
						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-client-id">
								<?php esc_html_e( 'Client ID', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<input type="text" id="gc-client-id"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_client_id]"
									value="<?php echo esc_attr( $client_id ); ?>"
									class="gc-settings-input" />
							</div>
						</div>
						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-client-secret">
								<?php esc_html_e( 'Client Secret', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<input type="password" id="gc-client-secret"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_client_secret]"
									value=""
									placeholder="<?php echo esc_attr( $secret_stored ? str_repeat( '•', 20 ) : __( 'Enter secret…', 'game-calendar' ) ); ?>"
									class="gc-settings-input"
									autocomplete="new-password" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Leave blank to keep the saved secret.', 'game-calendar' ); ?></p>
							</div>
						</div>
						<div class="gc-settings-row">
							<label class="gc-settings-label">
								<?php esc_html_e( 'Search Mode', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
									<input type="radio"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_search_mode]"
										value="future_releases"
										<?php checked( $search_mode, 'future_releases' ); ?> />
									<?php esc_html_e( 'Future releases (default)', 'game-calendar' ); ?>
								</label>
								<label style="display:flex;align-items:center;gap:6px;">
									<input type="radio"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_search_mode]"
										value="all_releases"
										<?php checked( $search_mode, 'all_releases' ); ?> />
									<?php esc_html_e( 'All releases', 'game-calendar' ); ?>
								</label>
								<p class="gc-settings-hint"><?php esc_html_e( 'Filter IGDB search results to upcoming games only, or include all releases.', 'game-calendar' ); ?></p>
							</div>
						</div>
						<div class="gc-settings-row gc-test-row">
							<span class="gc-settings-label"></span>
							<div class="gc-settings-control">
								<button type="button" id="gc-test-connection" class="button button-secondary">
									<?php esc_html_e( 'Test Connection', 'game-calendar' ); ?>
								</button>
								<span id="gc-test-result" class="gc-test-result"></span>
							</div>
						</div>
					</div>
				</div>

				<div class="gc-settings-section">
					<div class="gc-settings-section-head">
						<h2 class="gc-settings-section-title">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Auto-Updates', 'game-calendar' ); ?>
						</h2>
						<p class="gc-settings-section-desc">
							<?php echo wp_kses(
								__( 'A GitHub Personal Access Token is required to check for plugin updates. Create one at <a href="https://github.com/settings/tokens/new" target="_blank" rel="noopener">github.com/settings/tokens</a> — classic token, <strong>no scopes needed</strong>. This authenticates the GitHub API call and avoids rate-limit errors.', 'game-calendar' ),
								array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array() )
							); ?>
						</p>
					</div>
					<div class="gc-settings-section-body">
						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-github-token">
								<?php esc_html_e( 'GitHub Token', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<input type="password" id="gc-github-token"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_github_token]"
									value=""
									placeholder="<?php echo esc_attr( $github_stored ? str_repeat( '•', 20 ) : __( 'ghp_…', 'game-calendar' ) ); ?>"
									class="gc-settings-input"
									autocomplete="new-password" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Leave blank to keep the saved token.', 'game-calendar' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="gc-settings-section">
					<div class="gc-settings-section-head">
						<h2 class="gc-settings-section-title">
							<span class="dashicons dashicons-art"></span>
							<?php esc_html_e( 'Calendar Colors', 'game-calendar' ); ?>
						</h2>
						<p class="gc-settings-section-desc">
							<?php esc_html_e( 'Choose the accent color for each entry type on the calendar.', 'game-calendar' ); ?>
						</p>
					</div>
					<div class="gc-settings-section-body">
						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-color-release">
								<?php esc_html_e( 'Game Releases', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control gc-color-control">
								<input type="color" id="gc-color-release"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_color_release]"
									value="<?php echo esc_attr( $color_release ); ?>"
									class="gc-color-swatch" />
								<span class="gc-color-value"><?php echo esc_html( $color_release ); ?></span>
							</div>
						</div>
						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-color-event">
								<?php esc_html_e( 'Gaming Events', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control gc-color-control">
								<input type="color" id="gc-color-event"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_color_event]"
									value="<?php echo esc_attr( $color_event ); ?>"
									class="gc-color-swatch" />
								<span class="gc-color-value"><?php echo esc_html( $color_event ); ?></span>
							</div>
						</div>
					</div>
				</div>

				<div class="gc-settings-footer">
					<?php submit_button( __( 'Save Settings', 'game-calendar' ), 'primary', 'submit', false ); ?>
				</div>

			</form>
		</div>

		<script>
		( function () {
			// Test connection.
			document.getElementById( 'gc-test-connection' ).addEventListener( 'click', function () {
				var btn    = this;
				var result = document.getElementById( 'gc-test-result' );
				btn.disabled        = true;
				result.textContent  = '<?php echo esc_js( __( 'Testing…', 'game-calendar' ) ); ?>';
				result.className    = 'gc-test-result';
				fetch( ajaxurl, {
					method:  'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:    new URLSearchParams( {
						action: 'gc_test_igdb_connection',
						nonce:  '<?php echo esc_js( wp_create_nonce( 'gc_test_igdb' ) ); ?>'
					} )
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					result.textContent = data.data.message;
					result.classList.add( data.success ? 'gc-test-ok' : 'gc-test-fail' );
					btn.disabled = false;
				} ).catch( function () {
					result.textContent = '<?php echo esc_js( __( 'Request failed.', 'game-calendar' ) ); ?>';
					result.classList.add( 'gc-test-fail' );
					btn.disabled = false;
				} );
			} );

			// Live hex label on color pickers.
			document.querySelectorAll( '.gc-color-swatch' ).forEach( function ( picker ) {
				var label = picker.nextElementSibling;
				picker.addEventListener( 'input', function () {
					if ( label ) label.textContent = picker.value;
				} );
			} );
		} )();
		</script>
		<?php
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'gc_test_igdb', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}

		delete_transient( 'gc_igdb_token' );
		$api   = new GC_IGDB_API();
		$token = $api->get_token();

		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful! Token acquired.', 'game-calendar' ) ) );
	}

	public static function get( $key, $default = '' ) {
		$options = get_option( self::OPTION_NAME, array() );
		return $options[ $key ] ?? $default;
	}
}
