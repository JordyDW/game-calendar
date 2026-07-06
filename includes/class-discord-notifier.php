<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes calendar updates to a Discord channel via an incoming webhook.
 *
 * Four independently configurable triggers:
 *  - instant:   announce a release/event/DLC the first time it is published.
 *  - daily:     each morning, post entries releasing that day.
 *  - countdown: each morning, post entries releasing in N days.
 *  - weekly:    once a week, post a digest of the coming 7 days.
 *
 * Everything is configurable globally (Settings → Game Calendar → Discord) and
 * can be overridden per entry (skip, mention override, countdown days).
 */
class GC_Discord_Notifier {

	const CRON_DAILY    = 'gc_discord_cron_daily';
	const CRON_WEEKLY   = 'gc_discord_cron_weekly';
	const ACTION_SINGLE = 'gc_discord_send_single';

	// Cap on how long we'll honour a Discord rate-limit Retry-After before giving up.
	const MAX_RETRY_WAIT = 5;

	const TYPES = array( 'gc_release', 'gc_event', 'gc_dlc' );

	public function __construct() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( self::ACTION_SINGLE, array( $this, 'send_instant' ) );
		add_action( self::CRON_DAILY, array( $this, 'run_daily' ) );
		add_action( self::CRON_WEEKLY, array( $this, 'run_weekly' ) );

		// Re-align cron events whenever the timing settings are saved.
		add_action( 'update_option_' . GC_Settings::OPTION_NAME, array( $this, 'reschedule' ) );
		add_action( 'add_option_' . GC_Settings::OPTION_NAME, array( $this, 'reschedule' ) );

		// Self-heal: the activation hook does not run on plugin auto-update, so make
		// sure the cron events exist even when the plugin was upgraded in place.
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	public function reschedule() {
		self::schedule_events();
	}

	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			self::schedule_daily();
		}
		if ( ! wp_next_scheduled( self::CRON_WEEKLY ) ) {
			self::schedule_weekly();
		}
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * (Re)schedule the daily and weekly cron events to the configured times.
	 * Safe to call repeatedly — each helper clears its own hook first.
	 *
	 * These are one-off (single) events rather than WP-Cron recurring events: each
	 * run re-arms the next one (see run_daily()/run_weekly()). Recomputing the next
	 * fire time every run — instead of repeating a fixed 1-day/1-week interval —
	 * keeps the local wall-clock time correct across daylight-saving transitions,
	 * which a fixed interval would drift by an hour.
	 */
	public static function schedule_events() {
		self::schedule_daily();
		self::schedule_weekly();
	}

	/**
	 * Arm the next single daily run at the configured time, replacing any pending one.
	 */
	private static function schedule_daily() {
		wp_clear_scheduled_hook( self::CRON_DAILY );
		$daily_time = GC_Settings::get( 'gc_discord_daily_time', '09:00' );
		wp_schedule_single_event( self::next_run( $daily_time ), self::CRON_DAILY );
	}

	/**
	 * Arm the next single weekly run at the configured weekday + time, replacing any
	 * pending one.
	 */
	private static function schedule_weekly() {
		wp_clear_scheduled_hook( self::CRON_WEEKLY );
		$weekly_day  = (int) GC_Settings::get( 'gc_discord_weekly_day', '1' ); // 0 = Sunday.
		$weekly_time = GC_Settings::get( 'gc_discord_weekly_time', '09:00' );
		wp_schedule_single_event( self::next_run( $weekly_time, $weekly_day ), self::CRON_WEEKLY );
	}

	public static function clear_events() {
		wp_clear_scheduled_hook( self::CRON_DAILY );
		wp_clear_scheduled_hook( self::CRON_WEEKLY );
	}

	/**
	 * Compute the next UTC timestamp matching a local HH:MM (and optional weekday).
	 *
	 * @param string   $time    Local time as 'HH:MM'.
	 * @param int|null $weekday 0-6 (Sun-Sat) to align to, or null for the next daily occurrence.
	 * @return int UTC timestamp.
	 */
	private static function next_run( $time, $weekday = null ) {
		// Use the site's real timezone (wp_timezone) rather than the raw gmt_offset
		// option: on a site configured with a named timezone, gmt_offset holds the
		// standard-time offset and is not adjusted for DST, so scheduling off it
		// fires an hour late in summer. A DateTime in wp_timezone() is DST-aware.
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );

		$parts   = explode( ':', $time );
		$hours   = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		$target = ( clone $now )->setTime( $hours, $minutes, 0 );

		if ( null !== $weekday ) {
			$current_dow = (int) $now->format( 'w' ); // 0 = Sunday.
			$delta       = ( $weekday - $current_dow + 7 ) % 7;
			if ( $delta > 0 ) {
				$target->modify( "+{$delta} days" );
			}
		}

		// If that moment already passed, roll forward (a day for daily, a week for weekly).
		if ( $target <= $now ) {
			$target->modify( ( null !== $weekday ) ? '+1 week' : '+1 day' );
		}

		return $target->getTimestamp();
	}

	/* ---------------------------------------------------------------------
	 * Instant trigger
	 * ------------------------------------------------------------------- */

	/**
	 * On first publish of a calendar entry, defer an instant announcement.
	 *
	 * Deferred via a single cron event because the admin quick-add path writes
	 * post meta *after* wp_insert_post() returns — so meta isn't available yet
	 * during this hook. Deferring also keeps the editor save snappy (no blocking
	 * HTTP round-trip) and handles classic-editor and quick-add paths uniformly.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::TYPES, true ) ) {
			return;
		}
		if ( ! GC_Settings::get( 'gc_discord_enable_instant' ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, 'gc_discord_sent_instant', true ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::ACTION_SINGLE, array( $post->ID ) ) ) {
			wp_schedule_single_event( time() + 30, self::ACTION_SINGLE, array( $post->ID ) );
		}
	}

	public function send_instant( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! $this->is_eligible( $post_id, $post->post_type ) ) {
			return;
		}
		// Per-entry opt-out chosen at add time ("Announce on Discord" unchecked).
		// Only the instant alert is suppressed; scheduled digests still cover it.
		if ( get_post_meta( $post_id, 'gc_discord_skip_instant', true ) ) {
			return;
		}
		if ( get_post_meta( $post_id, 'gc_discord_sent_instant', true ) ) {
			return;
		}

		$embed   = $this->build_embed( $post_id, 'instant' );
		$mention = $this->mention_for( $post_id );
		$result  = $this->post_message( array( $embed ), $mention );

		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $post_id, 'gc_discord_sent_instant', 1 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Daily + countdown triggers
	 * ------------------------------------------------------------------- */

	public function run_daily() {
		// Re-arm tomorrow's run first: keeps the chain alive even if the work below
		// fatals, and recomputes the fire time so it stays correct across DST. Armed
		// regardless of the toggles so re-enabling a trigger doesn't need a re-save.
		self::schedule_daily();

		if ( GC_Settings::get( 'gc_discord_enable_daily' ) ) {
			$this->run_dated_batch( 0, 'today', 'gc_discord_sent_today', '🎮 ' . __( 'Releasing today', 'game-calendar' ) );
		}

		if ( GC_Settings::get( 'gc_discord_enable_countdown' ) ) {
			$days = max( 1, (int) GC_Settings::get( 'gc_discord_countdown_days', 1 ) );
			$this->run_dated_batch( $days, 'countdown', 'gc_discord_sent_countdown', '⏳ ' . __( 'Coming soon', 'game-calendar' ) );
		}
	}

	/**
	 * Find entries dated today+$offset_days, announce the un-sent ones as one
	 * batched message, and flag them so they are not announced again.
	 */
	private function run_dated_batch( $offset_days, $context, $sent_meta, $heading ) {
		$date  = $this->add_days( $this->today(), $offset_days );
		$posts = $this->query_entries( $date, $date );

		$items = array();
		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, $sent_meta, true ) ) {
				continue;
			}
			$items[] = array(
				'id'    => $post->ID,
				'embed' => $this->build_embed( $post->ID, $context ),
			);
		}

		if ( empty( $items ) ) {
			return;
		}

		// Discord caps a message at 10 embeds; flag entries only once their
		// chunk has actually been delivered, so a failed send retries next run.
		foreach ( array_chunk( $items, 10 ) as $i => $chunk ) {
			$content = ( 0 === $i ) ? $this->merge_mention( $heading ) : '';
			$result  = $this->post_message( wp_list_pluck( $chunk, 'embed' ), $content );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			foreach ( $chunk as $item ) {
				update_post_meta( $item['id'], $sent_meta, 1 );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Weekly digest
	 * ------------------------------------------------------------------- */

	public function run_weekly() {
		// Re-arm next week's run first (see run_daily) — keeps the chain alive and
		// the fire time DST-correct, independent of the toggle below.
		self::schedule_weekly();

		if ( ! GC_Settings::get( 'gc_discord_enable_weekly' ) ) {
			return;
		}

		$start = $this->today();
		$end   = $this->add_days( $start, 7 );
		$posts = $this->query_entries( $start, $end );

		if ( empty( $posts ) ) {
			return;
		}

		$embeds = array();
		foreach ( $posts as $post ) {
			$embeds[] = $this->build_embed( $post->ID, 'digest' );
		}

		$heading = '🗓️ ' . __( 'This week in gaming', 'game-calendar' );
		foreach ( array_chunk( $embeds, 10 ) as $i => $chunk ) {
			$content = ( 0 === $i ) ? $this->merge_mention( $heading ) : '';
			$this->post_message( $chunk, $content );
		}
	}

	/* ---------------------------------------------------------------------
	 * Querying
	 * ------------------------------------------------------------------- */

	/**
	 * Eligible, non-skipped entries of every enabled type whose date falls in
	 * the inclusive [$start, $end] range. Mirrors the meta_query shape used by
	 * GC_Calendar_Query::get_events(); the per-entry skip flag is filtered in PHP
	 * to keep the orderby join unambiguous.
	 */
	private function query_entries( $start, $end ) {
		$enabled = $this->enabled_types();
		$results = array();

		foreach ( $enabled as $post_type ) {
			$meta_key = ( 'gc_event' === $post_type ) ? 'gc_event_start' : 'gc_release_date';

			$posts = get_posts( array(
				'post_type'      => $post_type,
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'meta_key'       => $meta_key,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => array( $start, $end ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			) );

			foreach ( $posts as $post ) {
				if ( ! get_post_meta( $post->ID, 'gc_discord_skip', true ) ) {
					$results[] = $post;
				}
			}
		}

		return $results;
	}

	/* ---------------------------------------------------------------------
	 * Eligibility / mentions
	 * ------------------------------------------------------------------- */

	private function enabled_types() {
		// get() returns the all-types default only when the option was never saved;
		// a saved-but-empty array means the admin deliberately disabled every type.
		$types = GC_Settings::get( 'gc_discord_types', self::TYPES );
		if ( ! is_array( $types ) ) {
			return self::TYPES;
		}
		return array_values( array_intersect( $types, self::TYPES ) );
	}

	private function is_eligible( $post_id, $post_type ) {
		if ( ! in_array( $post_type, $this->enabled_types(), true ) ) {
			return false;
		}
		if ( get_post_meta( $post_id, 'gc_discord_skip', true ) ) {
			return false;
		}
		return true;
	}

	/** Per-entry mention override, falling back to the global mention. */
	private function mention_for( $post_id ) {
		$override = get_post_meta( $post_id, 'gc_discord_mention_override', true );
		$mention  = $override ? $override : GC_Settings::get( 'gc_discord_mention' );
		return $this->merge_mention( '', $mention );
	}

	/** Prefix $text with the global mention string (used for batched headings). */
	private function merge_mention( $text, $mention = null ) {
		if ( null === $mention ) {
			$mention = GC_Settings::get( 'gc_discord_mention' );
		}
		$mention = trim( (string) $mention );
		if ( '' === $mention ) {
			return $text;
		}
		return '' === $text ? $mention : $mention . ' ' . $text;
	}

	/**
	 * Build the allowed_mentions object so a ping in `content` actually fires.
	 * Discord suppresses all pings unless explicitly allowed here.
	 */
	private function allowed_mentions( $content ) {
		$allowed = array( 'parse' => array() );

		if ( false !== strpos( $content, '@everyone' ) || false !== strpos( $content, '@here' ) ) {
			$allowed['parse'][] = 'everyone';
		}
		if ( preg_match_all( '/<@&(\d+)>/', $content, $m ) && ! empty( $m[1] ) ) {
			$allowed['roles'] = array_values( array_unique( $m[1] ) );
		}
		if ( preg_match_all( '/<@!?(\d+)>/', $content, $m ) && ! empty( $m[1] ) ) {
			$allowed['users'] = array_values( array_unique( $m[1] ) );
		}

		return $allowed;
	}

	/* ---------------------------------------------------------------------
	 * Embeds
	 * ------------------------------------------------------------------- */

	/**
	 * Read a post's calendar fields. Mirrors the field map proven in
	 * GC_Calendar_Query::get_events() (cover falls back to the featured image).
	 */
	private function get_post_fields( $post_id ) {
		$post_type = get_post_type( $post_id );
		$is_event  = ( 'gc_event' === $post_type );

		$date = $is_event
			? get_post_meta( $post_id, 'gc_event_start', true )
			: get_post_meta( $post_id, 'gc_release_date', true );

		$url = $is_event
			? get_post_meta( $post_id, 'gc_event_url', true )
			: get_post_meta( $post_id, 'gc_url', true );

		$cover_key = $is_event ? 'gc_event_cover_url' : 'gc_cover_url';
		$cover     = get_post_meta( $post_id, $cover_key, true );
		if ( ! $cover && has_post_thumbnail( $post_id ) ) {
			$cover = get_the_post_thumbnail_url( $post_id, 'large' );
		}

		$fields = array(
			'type'        => $post_type,
			'title'       => get_the_title( $post_id ),
			'description' => $this->excerpt( $post_id ),
			'date'        => $date,
			'url'         => $url,
			'cover'       => $cover,
			'address'     => $is_event ? get_post_meta( $post_id, 'gc_event_address', true ) : '',
			'developer'   => '',
			'publisher'   => '',
			'genre'       => '',
			'platforms'   => array(),
		);

		if ( in_array( $post_type, array( 'gc_release', 'gc_dlc' ), true ) ) {
			$fields['developer'] = get_post_meta( $post_id, 'gc_developer', true );
			$fields['publisher'] = get_post_meta( $post_id, 'gc_publisher', true );
			$fields['genre']     = get_post_meta( $post_id, 'gc_genre', true );
			$platforms           = wp_get_post_terms( $post_id, 'gc_platform', array( 'fields' => 'names' ) );
			$fields['platforms'] = is_wp_error( $platforms ) ? array() : $platforms;
		}

		return $fields;
	}

	private function excerpt( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return '';
		}
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return wp_html_excerpt( $text, 350, '…' );
	}

	private function build_embed( $post_id, $context ) {
		$f = $this->get_post_fields( $post_id );

		$prefix = '';
		switch ( $context ) {
			case 'instant':
				$prefix = '📢 ';
				break;
			case 'today':
				$prefix = '🎮 ';
				break;
			case 'countdown':
				$days   = max( 1, (int) $this->countdown_days( $post_id ) );
				/* translators: %d: number of days until release. */
				$prefix = '⏳ ' . sprintf( _n( '%d day until · ', '%d days until · ', $days, 'game-calendar' ), $days );
				break;
		}

		$embed = array(
			'title' => $this->truncate( $prefix . $f['title'], 256 ),
			'color' => $this->color_for( $f['type'] ),
		);

		if ( $f['url'] ) {
			$embed['url'] = $f['url'];
		}
		if ( $f['description'] ) {
			$embed['description'] = $f['description'];
		}
		if ( $f['cover'] ) {
			$embed['thumbnail'] = array( 'url' => $f['cover'] );
		}

		$fields = $this->embed_fields( $f );

		// Optional link back to the site's calendar page. Empty setting → omitted,
		// so installs without a configured calendar page behave exactly as before.
		$calendar_url = GC_Settings::get( 'gc_discord_calendar_url' );
		if ( $calendar_url ) {
			$fields[] = array(
				'name'   => '📅 ' . __( 'Calendar', 'game-calendar' ),
				'value'  => sprintf( '[%s](%s)', __( 'View the full calendar', 'game-calendar' ), esc_url( $calendar_url ) ),
				'inline' => false,
			);
		}

		$embed['fields'] = $fields;

		$footer = GC_Settings::get( 'gc_discord_footer', get_bloginfo( 'name' ) );
		if ( $footer ) {
			$embed['footer'] = array( 'text' => $footer );
		}

		return $embed;
	}

	private function embed_fields( $f ) {
		$fields = array();

		if ( $f['date'] ) {
			$fields[] = array(
				'name'   => ( 'gc_event' === $f['type'] ) ? __( 'When', 'game-calendar' ) : __( 'Release date', 'game-calendar' ),
				'value'  => $this->format_date( $f['date'] ),
				'inline' => true,
			);
		}
		if ( ! empty( $f['platforms'] ) ) {
			$fields[] = array(
				'name'   => __( 'Platforms', 'game-calendar' ),
				'value'  => implode( ', ', $f['platforms'] ),
				'inline' => true,
			);
		}
		if ( $f['developer'] ) {
			$fields[] = array( 'name' => __( 'Developer', 'game-calendar' ), 'value' => $f['developer'], 'inline' => true );
		}
		if ( $f['publisher'] ) {
			$fields[] = array( 'name' => __( 'Publisher', 'game-calendar' ), 'value' => $f['publisher'], 'inline' => true );
		}
		if ( $f['genre'] ) {
			$fields[] = array( 'name' => __( 'Genre', 'game-calendar' ), 'value' => $f['genre'], 'inline' => true );
		}
		if ( $f['address'] ) {
			$fields[] = array( 'name' => __( 'Where', 'game-calendar' ), 'value' => $f['address'], 'inline' => true );
		}

		return $fields;
	}

	private function countdown_days( $post_id ) {
		$override = get_post_meta( $post_id, 'gc_discord_countdown_days', true );
		if ( '' !== $override && null !== $override ) {
			return (int) $override;
		}
		return (int) GC_Settings::get( 'gc_discord_countdown_days', 1 );
	}

	private function color_for( $post_type ) {
		$map = array(
			'gc_release' => GC_Settings::get( 'gc_color_release', '#ac00fb' ),
			'gc_event'   => GC_Settings::get( 'gc_color_event', '#96eefe' ),
			'gc_dlc'     => GC_Settings::get( 'gc_color_release', '#ac00fb' ),
		);
		$hex = isset( $map[ $post_type ] ) ? $map[ $post_type ] : '#5865F2';
		return hexdec( ltrim( $hex, '#' ) );
	}

	private function format_date( $raw ) {
		// Event dates may carry a time component (YYYY-MM-DDTHH:MM).
		$ts = strtotime( $raw );
		if ( ! $ts ) {
			return $raw;
		}
		$has_time = ( false !== strpos( $raw, 'T' ) );
		$format   = $has_time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' );
		return date_i18n( $format, $ts );
	}

	private function truncate( $text, $limit ) {
		return wp_html_excerpt( $text, $limit, '…' );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/** Assemble the webhook payload and POST it. */
	private function post_message( $embeds, $content = '', $webhook = '' ) {
		if ( '' === $webhook ) {
			$webhook = GC_Settings::get( 'gc_discord_webhook_url' );
		}
		if ( ! $webhook ) {
			return new WP_Error( 'gc_no_webhook', __( 'No Discord webhook URL is configured.', 'game-calendar' ) );
		}

		$payload = array(
			'embeds'           => array_values( $embeds ),
			'allowed_mentions' => $this->allowed_mentions( $content ),
		);
		if ( '' !== trim( (string) $content ) ) {
			$payload['content'] = $content;
		}

		// The webhook's display name and avatar are configured in Discord itself.

		return $this->send( $webhook, $payload );
	}

	/**
	 * Low-level webhook POST. Discord returns 204 No Content on success.
	 *
	 * On HTTP 429 (rate limited) it waits the advised Retry-After and retries
	 * once, so a burst of triggers doesn't silently drop a message.
	 *
	 * @return true|WP_Error
	 */
	public function send( $webhook_url, $payload ) {
		$body     = wp_json_encode( $payload );
		$attempts = 0;

		while ( true ) {
			$attempts++;
			$response = wp_remote_post( $webhook_url, array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
				'timeout' => 10,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code || 204 === $code ) {
				return true;
			}

			// Rate limited: honour Retry-After once, within a sane cap.
			if ( 429 === $code && $attempts < 2 ) {
				$wait = $this->retry_after_seconds( $response );
				if ( $wait > 0 && $wait <= self::MAX_RETRY_WAIT ) {
					sleep( $wait );
					continue;
				}
			}

			$msg = wp_remote_retrieve_body( $response );
			/* translators: 1: HTTP status code, 2: response body. */
			return new WP_Error( 'gc_discord_error', sprintf( __( 'Discord returned HTTP %1$d: %2$s', 'game-calendar' ), $code, $msg ) );
		}
	}

	/** Seconds to wait after a 429, from the Retry-After header or JSON body. */
	private function retry_after_seconds( $response ) {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( '' !== $header && is_numeric( $header ) ) {
			return (int) ceil( (float) $header );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['retry_after'] ) ) {
			return (int) ceil( (float) $body['retry_after'] );
		}
		return 0;
	}

	/**
	 * Send a sample embed so the admin can confirm the webhook works.
	 *
	 * @return true|WP_Error
	 */
	public function send_test( $webhook = '' ) {
		$embed = array(
			'title'       => '✅ ' . __( 'Game Calendar test message', 'game-calendar' ),
			'description' => __( 'Your Discord webhook is connected. Calendar updates will appear here.', 'game-calendar' ),
			'color'       => hexdec( ltrim( GC_Settings::get( 'gc_color_release', '#ac00fb' ), '#' ) ),
			'footer'      => array( 'text' => GC_Settings::get( 'gc_discord_footer', get_bloginfo( 'name' ) ) ),
		);
		return $this->post_message( array( $embed ), '', $webhook );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/** Today's date in the site's local timezone, as 'Y-m-d'. */
	private function today() {
		return current_time( 'Y-m-d' );
	}

	/** Add (or subtract) whole days to a 'Y-m-d' string, returning 'Y-m-d'. */
	private function add_days( $date, $days ) {
		// WordPress sets PHP's default timezone to UTC, so this is pure date math.
		return gmdate( 'Y-m-d', strtotime( $date ) + ( $days * DAY_IN_SECONDS ) );
	}
}
