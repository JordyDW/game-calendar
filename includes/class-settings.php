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
		add_action( 'wp_ajax_gc_test_discord', array( $this, 'ajax_test_discord' ) );
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

		// --- Discord notifications ---------------------------------------

		// Webhook URL is secret-ish: only overwrite when a new value is provided.
		$output['gc_discord_webhook_url'] = ( isset( $input['gc_discord_webhook_url'] ) && '' !== trim( $input['gc_discord_webhook_url'] ) )
			? esc_url_raw( trim( $input['gc_discord_webhook_url'] ) )
			: ( $existing['gc_discord_webhook_url'] ?? '' );

		foreach ( array( 'gc_discord_enable_instant', 'gc_discord_enable_daily', 'gc_discord_enable_weekly', 'gc_discord_enable_countdown' ) as $flag ) {
			$output[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$valid_types = array( 'gc_release', 'gc_event', 'gc_dlc' );
		$output['gc_discord_types'] = ( isset( $input['gc_discord_types'] ) && is_array( $input['gc_discord_types'] ) )
			? array_values( array_intersect( array_map( 'sanitize_key', $input['gc_discord_types'] ), $valid_types ) )
			: array();

		$output['gc_discord_mention'] = isset( $input['gc_discord_mention'] )
			? $this->sanitize_mention( $input['gc_discord_mention'] )
			: ( $existing['gc_discord_mention'] ?? '' );

		$output['gc_discord_daily_time']  = $this->sanitize_time( $input['gc_discord_daily_time'] ?? '', $existing['gc_discord_daily_time'] ?? '09:00' );
		$output['gc_discord_weekly_time'] = $this->sanitize_time( $input['gc_discord_weekly_time'] ?? '', $existing['gc_discord_weekly_time'] ?? '09:00' );

		$output['gc_discord_weekly_day'] = ( isset( $input['gc_discord_weekly_day'] ) && '' !== $input['gc_discord_weekly_day'] )
			? max( 0, min( 6, (int) $input['gc_discord_weekly_day'] ) )
			: ( $existing['gc_discord_weekly_day'] ?? 1 );

		$output['gc_discord_countdown_days'] = isset( $input['gc_discord_countdown_days'] )
			? max( 1, absint( $input['gc_discord_countdown_days'] ) )
			: ( $existing['gc_discord_countdown_days'] ?? 1 );

		$output['gc_discord_footer']     = isset( $input['gc_discord_footer'] ) ? sanitize_text_field( $input['gc_discord_footer'] ) : ( $existing['gc_discord_footer'] ?? '' );

		$output['gc_discord_calendar_url'] = isset( $input['gc_discord_calendar_url'] ) ? esc_url_raw( trim( $input['gc_discord_calendar_url'] ) ) : ( $existing['gc_discord_calendar_url'] ?? '' );

		foreach ( array( 'gc_color_release', 'gc_color_event' ) as $color_key ) {
			$output[ $color_key ] = isset( $input[ $color_key ] )
				? sanitize_hex_color( $input[ $color_key ] )
				: ( $existing[ $color_key ] ?? '' );
		}

		// Preserve legacy gc_color_dlc without exposing it in the UI.
		$output['gc_color_dlc'] = $existing['gc_color_dlc'] ?? '#22c55e';

		// --- IGDB Auto-Import --------------------------------------------

		$output['gc_igdb_auto_import_enabled'] = empty( $input['gc_igdb_auto_import_enabled'] ) ? 0 : 1;

		$valid_freqs = array( 'daily', 'weekly' );
		$output['gc_igdb_auto_import_frequency'] = ( isset( $input['gc_igdb_auto_import_frequency'] ) && in_array( $input['gc_igdb_auto_import_frequency'], $valid_freqs, true ) )
			? $input['gc_igdb_auto_import_frequency']
			: ( $existing['gc_igdb_auto_import_frequency'] ?? 'daily' );

		$valid_statuses = array( 'publish', 'draft' );
		$output['gc_igdb_import_post_status'] = ( isset( $input['gc_igdb_import_post_status'] ) && in_array( $input['gc_igdb_import_post_status'], $valid_statuses, true ) )
			? $input['gc_igdb_import_post_status']
			: ( $existing['gc_igdb_import_post_status'] ?? 'publish' );

		$output['gc_igdb_hypes_threshold'] = isset( $input['gc_igdb_hypes_threshold'] )
			? max( 0, (int) $input['gc_igdb_hypes_threshold'] )
			: ( $existing['gc_igdb_hypes_threshold'] ?? 5 );

		$output['gc_igdb_import_window_days'] = isset( $input['gc_igdb_import_window_days'] )
			? max( 30, min( 730, (int) $input['gc_igdb_import_window_days'] ) )
			: ( $existing['gc_igdb_import_window_days'] ?? 365 );

		$valid_platform_ids = array_keys( GC_IGDB_Importer::PLATFORM_MAP );
		$raw_platforms = ( isset( $input['gc_igdb_auto_import_platforms'] ) && is_array( $input['gc_igdb_auto_import_platforms'] ) )
			? $input['gc_igdb_auto_import_platforms']
			: null;
		$output['gc_igdb_auto_import_platforms'] = null !== $raw_platforms
			? array_values( array_intersect( array_map( 'intval', $raw_platforms ), $valid_platform_ids ) )
			: ( $existing['gc_igdb_auto_import_platforms'] ?? $valid_platform_ids );

		$output['gc_igdb_suppress_discord'] = empty( $input['gc_igdb_suppress_discord'] ) ? 0 : 1;

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

		$ai_enabled     = ! empty( $options['gc_igdb_auto_import_enabled'] );
		$ai_frequency   = $options['gc_igdb_auto_import_frequency']  ?? 'daily';
		$ai_status      = $options['gc_igdb_import_post_status']     ?? 'publish';
		$ai_threshold   = isset( $options['gc_igdb_hypes_threshold'] ) ? (int) $options['gc_igdb_hypes_threshold'] : 5;
		$ai_window      = isset( $options['gc_igdb_import_window_days'] ) ? (int) $options['gc_igdb_import_window_days'] : 365;
		$ai_platforms   = isset( $options['gc_igdb_auto_import_platforms'] ) && is_array( $options['gc_igdb_auto_import_platforms'] )
			? $options['gc_igdb_auto_import_platforms']
			: array_keys( GC_IGDB_Importer::PLATFORM_MAP );
		$ai_suppress    = ! empty( $options['gc_igdb_suppress_discord'] );
		$ai_next_run    = wp_next_scheduled( GC_IGDB_Importer::CRON_HOOK );
		$ai_last        = get_option( 'gc_igdb_last_import', array() );

		$discord_stored   = $options['gc_discord_webhook_url']  ?? '';
		$d_instant        = ! empty( $options['gc_discord_enable_instant'] );
		$d_daily          = ! empty( $options['gc_discord_enable_daily'] );
		$d_weekly         = ! empty( $options['gc_discord_enable_weekly'] );
		$d_countdown      = ! empty( $options['gc_discord_enable_countdown'] );
		$d_types          = ( isset( $options['gc_discord_types'] ) && is_array( $options['gc_discord_types'] ) )
			? $options['gc_discord_types']
			: array( 'gc_release', 'gc_event', 'gc_dlc' );
		$d_mention        = $options['gc_discord_mention']         ?? '';
		$d_daily_time     = $options['gc_discord_daily_time']      ?? '09:00';
		$d_weekly_time    = $options['gc_discord_weekly_time']     ?? '09:00';
		$d_weekly_day     = isset( $options['gc_discord_weekly_day'] ) ? (int) $options['gc_discord_weekly_day'] : 1;
		$d_countdown_days = isset( $options['gc_discord_countdown_days'] ) ? (int) $options['gc_discord_countdown_days'] : 1;
		$d_footer         = $options['gc_discord_footer']          ?? '';
		$d_calendar_url   = $options['gc_discord_calendar_url']    ?? '';
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
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Auto-Import', 'game-calendar' ); ?>
						</h2>
						<p class="gc-settings-section-desc">
							<?php esc_html_e( 'Automatically pull popular upcoming game releases from IGDB on a schedule. Each run also syncs release dates on existing entries that were imported from IGDB.', 'game-calendar' ); ?>
						</p>
					</div>
					<div class="gc-settings-section-body">

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-ai-enabled">
								<?php esc_html_e( 'Enable', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<label style="display:flex;align-items:center;gap:6px;">
									<input type="checkbox" id="gc-ai-enabled"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_auto_import_enabled]"
										value="1" <?php checked( $ai_enabled ); ?> />
									<?php esc_html_e( 'Enable scheduled auto-import', 'game-calendar' ); ?>
								</label>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label"><?php esc_html_e( 'Frequency', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
									<input type="radio"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_auto_import_frequency]"
										value="daily" <?php checked( $ai_frequency, 'daily' ); ?> />
									<?php esc_html_e( 'Daily', 'game-calendar' ); ?>
								</label>
								<label style="display:flex;align-items:center;gap:6px;">
									<input type="radio"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_auto_import_frequency]"
										value="weekly" <?php checked( $ai_frequency, 'weekly' ); ?> />
									<?php esc_html_e( 'Weekly', 'game-calendar' ); ?>
								</label>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label"><?php esc_html_e( 'Import as', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
									<input type="radio"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_import_post_status]"
										value="publish" <?php checked( $ai_status, 'publish' ); ?> />
									<?php esc_html_e( 'Published — entries appear on the calendar immediately', 'game-calendar' ); ?>
								</label>
								<label style="display:flex;align-items:center;gap:6px;">
									<input type="radio"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_import_post_status]"
										value="draft" <?php checked( $ai_status, 'draft' ); ?> />
									<?php esc_html_e( 'Draft — you review and publish each entry manually', 'game-calendar' ); ?>
								</label>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-ai-threshold">
								<?php esc_html_e( 'Hypes threshold', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<input type="number" id="gc-ai-threshold" min="0" max="9999"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_hypes_threshold]"
									value="<?php echo esc_attr( $ai_threshold ); ?>" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Minimum IGDB hypes score. Games with no hypes score are excluded. Recommended: 5–20.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-ai-window">
								<?php esc_html_e( 'Look ahead (days)', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<input type="number" id="gc-ai-window" min="30" max="730"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_import_window_days]"
									value="<?php echo esc_attr( $ai_window ); ?>" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Only import games releasing within this many days from today (30–730).', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label"><?php esc_html_e( 'Platforms', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<?php foreach ( GC_IGDB_Importer::PLATFORM_MAP as $pid => $pname ) : ?>
									<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
										<input type="checkbox"
											name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_auto_import_platforms][]"
											value="<?php echo esc_attr( $pid ); ?>"
											<?php checked( in_array( $pid, $ai_platforms, false ) ); ?> />
										<?php echo esc_html( $pname ); ?>
									</label>
								<?php endforeach; ?>
								<p class="gc-settings-hint"><?php esc_html_e( 'Only import games available on at least one of the checked platforms. Uncheck all to import across all platforms.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label"><?php esc_html_e( 'Discord', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<label style="display:flex;align-items:center;gap:6px;">
									<input type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_igdb_suppress_discord]"
										value="1" <?php checked( $ai_suppress ); ?> />
									<?php esc_html_e( 'Suppress instant Discord announcement for auto-imported entries', 'game-calendar' ); ?>
								</label>
								<p class="gc-settings-hint"><?php esc_html_e( 'Recommended when importing many games at once. Daily/weekly digests are unaffected.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row gc-test-row">
							<span class="gc-settings-label"></span>
							<div class="gc-settings-control">
								<button type="button" id="gc-run-import" class="button button-secondary">
									<?php esc_html_e( 'Run Now', 'game-calendar' ); ?>
								</button>
								<span id="gc-run-import-result" class="gc-test-result"></span>
								<p class="gc-settings-hint">
									<?php
									if ( $ai_next_run ) {
										echo esc_html( sprintf(
											/* translators: %s: formatted date/time */
											__( 'Next scheduled run: %s.', 'game-calendar' ),
											wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ai_next_run )
										) );
									} else {
										esc_html_e( 'No run scheduled — enable auto-import and save.', 'game-calendar' );
									}
									if ( ! empty( $ai_last['time'] ) ) {
										echo ' ' . esc_html( sprintf(
											/* translators: 1: date/time, 2: count */
											__( 'Last run: %1$s (%2$d new entries).', 'game-calendar' ),
											$ai_last['time'],
											(int) ( $ai_last['imported'] ?? 0 )
										) );
									}
									?>
								</p>
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
							<span class="dashicons dashicons-megaphone"></span>
							<?php esc_html_e( 'Discord Notifications', 'game-calendar' ); ?>
						</h2>
						<p class="gc-settings-section-desc">
							<?php echo wp_kses(
								__( 'Push calendar updates to your Discord community. In Discord, open <strong>Channel Settings → Integrations → Webhooks → New Webhook</strong>, copy the webhook URL, and paste it below.', 'game-calendar' ),
								array( 'strong' => array() )
							); ?>
						</p>
					</div>
					<div class="gc-settings-section-body">
						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-webhook">
								<?php esc_html_e( 'Webhook URL', 'game-calendar' ); ?>
							</label>
							<div class="gc-settings-control">
								<input type="password" id="gc-discord-webhook"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_webhook_url]"
									value=""
									placeholder="<?php echo esc_attr( $discord_stored ? str_repeat( '•', 20 ) : 'https://discord.com/api/webhooks/…' ); ?>"
									class="gc-settings-input"
									autocomplete="new-password" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Leave blank to keep the saved webhook.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label"><?php esc_html_e( 'Triggers', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_enable_instant]" value="1" <?php checked( $d_instant ); ?> />
									<?php esc_html_e( 'Instant — announce each entry the first time it is published', 'game-calendar' ); ?>
								</label>
								<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_enable_daily]" value="1" <?php checked( $d_daily ); ?> />
									<?php esc_html_e( 'Daily — each morning, post what releases that day', 'game-calendar' ); ?>
								</label>
								<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_enable_countdown]" value="1" <?php checked( $d_countdown ); ?> />
									<?php esc_html_e( 'Countdown — post a reminder a set number of days before release', 'game-calendar' ); ?>
								</label>
								<label style="display:flex;align-items:center;gap:6px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_enable_weekly]" value="1" <?php checked( $d_weekly ); ?> />
									<?php esc_html_e( 'Weekly digest — once a week, recap the coming 7 days', 'game-calendar' ); ?>
								</label>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label"><?php esc_html_e( 'Entry types', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<?php
								$type_labels = array(
									'gc_release' => __( 'Game releases', 'game-calendar' ),
									'gc_event'   => __( 'Gaming events', 'game-calendar' ),
									'gc_dlc'     => __( 'DLC & updates', 'game-calendar' ),
								);
								foreach ( $type_labels as $type_key => $type_label ) :
									?>
									<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_types][]" value="<?php echo esc_attr( $type_key ); ?>" <?php checked( in_array( $type_key, $d_types, true ) ); ?> />
										<?php echo esc_html( $type_label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="gc-settings-hint"><?php esc_html_e( 'Only the selected types are pushed to Discord.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-daily-time"><?php esc_html_e( 'Daily time', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<input type="time" id="gc-discord-daily-time" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_daily_time]" value="<?php echo esc_attr( $d_daily_time ); ?>" />
								<p class="gc-settings-hint"><?php esc_html_e( 'When the daily and countdown posts go out (site timezone).', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-countdown-days"><?php esc_html_e( 'Countdown days', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<input type="number" id="gc-discord-countdown-days" min="1" max="60" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_countdown_days]" value="<?php echo esc_attr( $d_countdown_days ); ?>" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Days before a release to post the countdown reminder. Can be overridden per entry.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-weekly-day"><?php esc_html_e( 'Weekly digest', 'game-calendar' ); ?></label>
							<div class="gc-settings-control gc-color-control">
								<select id="gc-discord-weekly-day" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_weekly_day]">
									<?php
									$wp_locale = $GLOBALS['wp_locale'];
									for ( $i = 0; $i <= 6; $i++ ) {
										printf(
											'<option value="%d" %s>%s</option>',
											(int) $i,
											selected( $d_weekly_day, $i, false ),
											esc_html( $wp_locale->get_weekday( $i ) )
										);
									}
									?>
								</select>
								<input type="time" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_weekly_time]" value="<?php echo esc_attr( $d_weekly_time ); ?>" />
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-mention"><?php esc_html_e( 'Mention', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<input type="text" id="gc-discord-mention"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_mention]"
									value="<?php echo esc_attr( $d_mention ); ?>"
									class="gc-settings-input"
									placeholder="@everyone" />
								<p class="gc-settings-hint">
									<?php echo wp_kses(
										__( 'Optional ping added to posts. Use <code>@everyone</code>, <code>@here</code>, or a role mention like <code>&lt;@&amp;123456789012345678&gt;</code> (copy the role ID in Discord). Leave blank for no ping.', 'game-calendar' ),
										array( 'code' => array() )
									); ?>
								</p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-calendar-url"><?php esc_html_e( 'Calendar page URL', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<input type="url" id="gc-discord-calendar-url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_calendar_url]" value="<?php echo esc_attr( $d_calendar_url ); ?>" class="gc-settings-input" placeholder="https://example.com/calendar" />
								<p class="gc-settings-hint"><?php esc_html_e( 'The page where you embedded the [game_calendar] shortcode. When set, each embed gets a “View the full calendar” link. Leave blank to omit it.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row">
							<label class="gc-settings-label" for="gc-discord-footer"><?php esc_html_e( 'Footer text', 'game-calendar' ); ?></label>
							<div class="gc-settings-control">
								<input type="text" id="gc-discord-footer" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gc_discord_footer]" value="<?php echo esc_attr( $d_footer ); ?>" class="gc-settings-input" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
								<p class="gc-settings-hint"><?php esc_html_e( 'Small line shown at the bottom of each embed. The message name and avatar are configured on the webhook in Discord.', 'game-calendar' ); ?></p>
							</div>
						</div>

						<div class="gc-settings-row gc-test-row">
							<span class="gc-settings-label"></span>
							<div class="gc-settings-control">
								<button type="button" id="gc-test-discord" class="button button-secondary">
									<?php esc_html_e( 'Send Test Message', 'game-calendar' ); ?>
								</button>
								<span id="gc-test-discord-result" class="gc-test-result"></span>
								<p class="gc-settings-hint"><?php esc_html_e( 'Sends a sample embed. Paste a new webhook above to test it before saving, or save first to test the stored one.', 'game-calendar' ); ?></p>
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

			// Run auto-import now.
			var runImportBtn = document.getElementById( 'gc-run-import' );
			if ( runImportBtn ) {
				runImportBtn.addEventListener( 'click', function () {
					var btn    = this;
					var result = document.getElementById( 'gc-run-import-result' );
					btn.disabled       = true;
					result.textContent = '<?php echo esc_js( __( 'Importing…', 'game-calendar' ) ); ?>';
					result.className   = 'gc-test-result';
					fetch( ajaxurl, {
						method:  'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body:    new URLSearchParams( {
							action: 'gc_igdb_run_import',
							nonce:  '<?php echo esc_js( wp_create_nonce( 'gc_igdb_run_import' ) ); ?>'
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
			}

			// Send a test Discord message.
			var discordBtn = document.getElementById( 'gc-test-discord' );
			if ( discordBtn ) {
				discordBtn.addEventListener( 'click', function () {
					var btn     = this;
					var result  = document.getElementById( 'gc-test-discord-result' );
					var webhook = document.getElementById( 'gc-discord-webhook' );
					btn.disabled       = true;
					result.textContent = '<?php echo esc_js( __( 'Sending…', 'game-calendar' ) ); ?>';
					result.className   = 'gc-test-result';
					fetch( ajaxurl, {
						method:  'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body:    new URLSearchParams( {
							action:  'gc_test_discord',
							nonce:   '<?php echo esc_js( wp_create_nonce( 'gc_test_discord' ) ); ?>',
							webhook: webhook ? webhook.value : ''
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
			}

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

	public function ajax_test_discord() {
		check_ajax_referer( 'gc_test_discord', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}

		// Allow testing a freshly typed (unsaved) webhook; otherwise fall back to the saved one.
		$override = isset( $_POST['webhook'] ) ? esc_url_raw( wp_unslash( $_POST['webhook'] ) ) : '';
		if ( $override
			&& 0 !== strpos( $override, 'https://discord.com/api/webhooks/' )
			&& 0 !== strpos( $override, 'https://discordapp.com/api/webhooks/' ) ) {
			$override = '';
		}

		$notifier = new GC_Discord_Notifier();
		$result   = $notifier->send_test( $override );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Test message sent! Check your Discord channel.', 'game-calendar' ) ) );
	}

	private function sanitize_mention( $value ) {
		// Preserve Discord mention syntax (@everyone, @here, <@&ROLE_ID>, <@USER_ID>)
		// which sanitize_text_field() would strip as if it were an HTML tag.
		$value = preg_replace( '/[^a-zA-Z0-9 @&!<>#_-]/', '', (string) $value );
		return trim( $value );
	}

	private function sanitize_time( $value, $fallback ) {
		$value = trim( (string) $value );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
	}

	public static function get( $key, $default = '' ) {
		$options = get_option( self::OPTION_NAME, array() );
		return $options[ $key ] ?? $default;
	}
}
