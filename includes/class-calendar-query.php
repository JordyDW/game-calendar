<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Calendar_Query {

	const FULLCALENDAR_VERSION = '6.1.11';

	// FullCalendar locales that carry a region; everything else uses the language.
	const REGIONAL_LOCALES = array( 'ar-dz', 'ar-kw', 'ar-ly', 'ar-ma', 'ar-sa', 'ar-tn', 'de-at', 'en-au', 'en-gb', 'en-nz', 'es-us', 'fr-ca', 'fr-ch', 'pt-br', 'sr-cyrl', 'zh-cn', 'zh-tw' );

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_ical' ), 10, 4 );
		add_shortcode( 'game_calendar', array( $this, 'shortcode' ) );
		add_shortcode( 'game_calendar_upcoming', array( $this, 'upcoming_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_upcoming_style' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_routes() {
		$filter_args = array(
			'type'     => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'platform' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route( 'game-calendar/v1', '/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_events' ),
			'permission_callback' => '__return_true',
			'args'                => $filter_args + array(
				'start'         => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'end'           => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				// Also return releases that only have a window ("Q2 2027"), placed
				// on the window's last day. Used by the admin calendar.
				'include_vague' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		) );

		// Upcoming releases that only have a release window, for the list below
		// the calendar.
		register_rest_route( 'game-calendar/v1', '/unscheduled', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_unscheduled' ),
			'permission_callback' => '__return_true',
			'args'                => $filter_args,
		) );

		// Subscribable iCalendar feed (served as text/calendar by serve_ical()).
		register_rest_route( 'game-calendar/v1', '/calendar\.ics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_ical' ),
			'permission_callback' => '__return_true',
			'args'                => $filter_args,
		) );
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Requested post types. gc_dlc is shown as a game release, so it comes
	 * along whenever releases are requested.
	 */
	private function requested_types( $type ) {
		$valid = array( 'gc_release', 'gc_event', 'gc_dlc' );
		if ( $type ) {
			$types = array_values( array_intersect( array_map( 'sanitize_key', explode( ',', $type ) ), $valid ) );
		} else {
			$types = $valid;
		}
		if ( in_array( 'gc_release', $types, true ) && ! in_array( 'gc_dlc', $types, true ) ) {
			$types[] = 'gc_dlc';
		}
		return $types;
	}

	private static function is_release_type( $post_type ) {
		return in_array( $post_type, array( 'gc_release', 'gc_dlc' ), true );
	}

	private function color_map() {
		$options       = get_option( 'gc_options', array() );
		$release_color = $options['gc_color_release'] ?? '#ac00fb';
		return array(
			'gc_release' => $release_color,
			'gc_event'   => $options['gc_color_event'] ?? '#96eefe',
			'gc_dlc'     => $release_color,
		);
	}

	private function visible_statuses() {
		return ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ? array( 'publish', 'draft' ) : array( 'publish' );
	}

	private function cover_for( $post ) {
		$key   = ( 'gc_event' === $post->post_type ) ? 'gc_event_cover_url' : 'gc_cover_url';
		$cover = get_post_meta( $post->ID, $key, true );
		if ( ! $cover && has_post_thumbnail( $post->ID ) ) {
			$cover = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
		}
		return $cover;
	}

	private function platforms_of( $post_id ) {
		$platforms = wp_get_post_terms( $post_id, 'gc_platform', array( 'fields' => 'names' ) );
		return is_wp_error( $platforms ) ? array() : $platforms;
	}

	/**
	 * Entries of the requested types whose date falls in [$start, $end].
	 *
	 * @param array  $types         Post types.
	 * @param string $start         Y-m-d or ''.
	 * @param string $end           Y-m-d or ''.
	 * @param string $platform      Platform family filter ("xbox,pc") or ''.
	 * @param bool   $include_vague Include releases that only have a window.
	 * @param int    $limit         Max entries per type.
	 * @return WP_Post[]
	 */
	private function query_entries( $types, $start, $end, $platform, $include_vague, $limit = 200 ) {
		$posts = array();
		foreach ( $types as $post_type ) {
			$meta_key = ( 'gc_event' === $post_type ) ? 'gc_event_start' : 'gc_release_date';

			if ( $start && $end ) {
				$date_q = array(
					'key'     => $meta_key,
					'value'   => array( $start, $end ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				);
			} elseif ( $start ) {
				$date_q = array(
					'key'     => $meta_key,
					'value'   => $start,
					'compare' => '>=',
					'type'    => 'DATE',
				);
			} else {
				$date_q = array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				);
			}

			$meta_query = array( 'relation' => 'AND', $date_q );
			$args       = array(
				'post_type'      => $post_type,
				'posts_per_page' => $limit,
				'post_status'    => $this->visible_statuses(),
				'meta_key'       => $meta_key,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			);

			if ( self::is_release_type( $post_type ) ) {
				if ( ! $include_vague ) {
					$meta_query[] = GC_Release_Date::exact_meta_query();
				}
				$tax_query = GC_Platforms::tax_query( $platform );
				if ( $tax_query ) {
					$args['tax_query'] = $tax_query;
				}
			}
			$args['meta_query'] = $meta_query;

			$posts = array_merge( $posts, get_posts( $args ) );
		}
		return $posts;
	}

	/**
	 * Calendar entry in FullCalendar's event format.
	 */
	private function format_event( $post, $color_map ) {
		$post_type = $post->post_type;
		$is_event  = ( 'gc_event' === $post_type );
		$date      = get_post_meta( $post->ID, $is_event ? 'gc_event_start' : 'gc_release_date', true );
		if ( ! $date ) {
			return null;
		}

		$event = array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'start'         => $date,
			'color'         => $color_map[ $post_type ] ?? '#ac00fb',
			'url'           => get_post_meta( $post->ID, $is_event ? 'gc_event_url' : 'gc_url', true ),
			'extendedProps' => array(
				'type'    => $post_type,
				'cover'   => $this->cover_for( $post ),
				'address' => $is_event ? get_post_meta( $post->ID, 'gc_event_address', true ) : '',
				'status'  => $post->post_status,
			),
		);

		if ( $is_event ) {
			$end = get_post_meta( $post->ID, 'gc_event_end', true );
			if ( $end ) {
				$event['end'] = $end;
			}
		} else {
			$event['extendedProps']['developer'] = get_post_meta( $post->ID, 'gc_developer', true );
			$event['extendedProps']['publisher'] = get_post_meta( $post->ID, 'gc_publisher', true );
			$event['extendedProps']['genre']     = get_post_meta( $post->ID, 'gc_genre', true );
			$event['extendedProps']['platforms'] = $this->platforms_of( $post->ID );
			// Short form for compact lists: "PlayStation · PC".
			$event['extendedProps']['platformFamilies'] = GC_Platforms::family_labels( $event['extendedProps']['platforms'] );

			$precision = GC_Release_Date::get_precision( $post->ID );
			if ( 'day' !== $precision ) {
				$event['extendedProps']['precision'] = $precision;
				$event['extendedProps']['dateLabel'] = GC_Release_Date::label( $date, $precision );
			}
		}

		return $event;
	}

	/* ---------------------------------------------------------------------
	 * REST: calendar events
	 * ------------------------------------------------------------------- */

	public function get_events( WP_REST_Request $request ) {
		$types = $this->requested_types( $request->get_param( 'type' ) );
		if ( empty( $types ) ) {
			return rest_ensure_response( array() );
		}

		// FullCalendar sends ISO 8601 datetimes — keep only YYYY-MM-DD for the BETWEEN comparison.
		$start = substr( (string) $request->get_param( 'start' ), 0, 10 );
		$end   = substr( (string) $request->get_param( 'end' ), 0, 10 );

		$posts = $this->query_entries(
			$types,
			$start && $end ? $start : '',
			$start && $end ? $end : '',
			$request->get_param( 'platform' ),
			(bool) $request->get_param( 'include_vague' )
		);

		$color_map = $this->color_map();
		$events    = array();
		foreach ( $posts as $post ) {
			$event = $this->format_event( $post, $color_map );
			if ( $event ) {
				$events[] = $event;
			}
		}

		return rest_ensure_response( $events );
	}

	/* ---------------------------------------------------------------------
	 * REST: releases without an exact date
	 * ------------------------------------------------------------------- */

	/**
	 * Upcoming releases that only have a window ("maart 2027", "Q2 2027",
	 * "2027"), soonest window first.
	 */
	public function get_unscheduled( WP_REST_Request $request ) {
		$types = array_values( array_filter( $this->requested_types( $request->get_param( 'type' ) ), array( __CLASS__, 'is_release_type' ) ) );
		if ( empty( $types ) ) {
			return rest_ensure_response( array() );
		}

		$args = array(
			'post_type'      => $types,
			'posts_per_page' => 150,
			'post_status'    => $this->visible_statuses(),
			'meta_key'       => 'gc_release_date',
			'orderby'        => array( 'meta_value' => 'ASC', 'title' => 'ASC' ),
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'gc_release_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
				array(
					'key'     => GC_Release_Date::META,
					'value'   => array( 'month', 'quarter', 'year' ),
					'compare' => 'IN',
				),
			),
		);
		$tax_query = GC_Platforms::tax_query( $request->get_param( 'platform' ) );
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query;
		}

		$color_map = $this->color_map();
		$items     = array();
		foreach ( get_posts( $args ) as $post ) {
			$event = $this->format_event( $post, $color_map );
			if ( $event ) {
				$items[] = $event;
			}
		}

		// Windows ending on the same day: the narrower one first ("Q4 2026" before "2026").
		usort( $items, function ( $a, $b ) {
			return strcmp( $a['start'], $b['start'] )
				?: GC_Release_Date::RANK[ $a['extendedProps']['precision'] ] <=> GC_Release_Date::RANK[ $b['extendedProps']['precision'] ]
				?: strcasecmp( $a['title'], $b['title'] );
		} );

		return rest_ensure_response( $items );
	}

	/* ---------------------------------------------------------------------
	 * REST: iCalendar feed
	 * ------------------------------------------------------------------- */

	/**
	 * Releases with an exact day and all events, from a month ago to a year
	 * ahead, as an iCalendar document (string; see serve_ical()).
	 */
	public function get_ical( WP_REST_Request $request ) {
		$types = $this->requested_types( $request->get_param( 'type' ) );
		$posts = $types ? $this->query_entries(
			$types,
			gmdate( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ),
			gmdate( 'Y-m-d', strtotime( '+1 year', current_time( 'timestamp' ) ) ),
			$request->get_param( 'platform' ),
			false,
			500
		) : array();

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$name  = sprintf(
			/* translators: %s: site name */
			__( '%s – game calendar', 'game-calendar' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Game Calendar//' . GC_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::ical_text( $name ),
			'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
			'X-PUBLISHED-TTL:PT6H',
		);

		foreach ( $posts as $post ) {
			if ( 'publish' !== $post->post_status ) {
				continue;
			}
			$is_event = ( 'gc_event' === $post->post_type );
			$start    = get_post_meta( $post->ID, $is_event ? 'gc_event_start' : 'gc_release_date', true );
			if ( ! $start ) {
				continue;
			}
			$end  = $is_event ? get_post_meta( $post->ID, 'gc_event_end', true ) : '';
			$url  = get_post_meta( $post->ID, $is_event ? 'gc_event_url' : 'gc_url', true );
			$desc = array();

			if ( $is_event ) {
				$address = get_post_meta( $post->ID, 'gc_event_address', true );
				if ( $address ) {
					$desc[] = $address;
				}
			} else {
				$platforms = $this->platforms_of( $post->ID );
				if ( $platforms ) {
					$desc[] = __( 'Platforms', 'game-calendar' ) . ': ' . implode( ', ', GC_Platforms::family_labels( $platforms ) ?: $platforms );
				}
				$developer = get_post_meta( $post->ID, 'gc_developer', true );
				if ( $developer ) {
					$desc[] = __( 'Developer', 'game-calendar' ) . ': ' . $developer;
				}
			}
			if ( $url ) {
				$desc[] = $url;
			}

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:gc-' . $post->ID . '@' . $host;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( $post->post_modified_gmt . ' UTC' ) );
			$lines   = array_merge( $lines, self::ical_dates( $start, $end ) );
			$lines[] = 'SUMMARY:' . self::ical_text( wp_specialchars_decode( $post->post_title, ENT_QUOTES ) );
			$lines[] = 'CATEGORIES:' . self::ical_text( $is_event ? __( 'Gaming event', 'game-calendar' ) : __( 'Game release', 'game-calendar' ) );
			if ( $desc ) {
				$lines[] = 'DESCRIPTION:' . self::ical_text( implode( "\n", $desc ) );
			}
			if ( $url ) {
				$lines[] = 'URL:' . esc_url_raw( $url );
			}
			if ( $is_event && ! empty( $address ) ) {
				$lines[] = 'LOCATION:' . self::ical_text( $address );
			}
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		return new WP_REST_Response( implode( "\r\n", array_map( array( __CLASS__, 'ical_fold' ), $lines ) ) . "\r\n" );
	}

	/**
	 * Send the .ics route as text/calendar instead of JSON.
	 */
	public function serve_ical( $served, $result, $request, $server ) {
		if ( '/game-calendar/v1/calendar.ics' !== $request->get_route() || ! ( $result instanceof WP_REST_Response ) || $result->is_error() ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="game-calendar.ics"' );
			header( 'Cache-Control: public, max-age=3600' );
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped per RFC 5545.
		return true;
	}

	/**
	 * DTSTART/DTEND lines. Dates without a time are all-day (DTEND exclusive);
	 * times are stored in the site's timezone and written in UTC.
	 */
	private static function ical_dates( $start, $end ) {
		$tz = wp_timezone();
		if ( false === strpos( $start, 'T' ) ) {
			$start_day = new DateTimeImmutable( substr( $start, 0, 10 ), $tz );
			$end_day   = $end ? new DateTimeImmutable( substr( $end, 0, 10 ), $tz ) : $start_day;
			return array(
				'DTSTART;VALUE=DATE:' . $start_day->format( 'Ymd' ),
				'DTEND;VALUE=DATE:' . $end_day->modify( '+1 day' )->format( 'Ymd' ),
			);
		}
		$utc        = new DateTimeZone( 'UTC' );
		$start_time = new DateTimeImmutable( $start, $tz );
		$end_time   = ( $end && false !== strpos( $end, 'T' ) ) ? new DateTimeImmutable( $end, $tz ) : $start_time->modify( '+2 hours' );
		return array(
			'DTSTART:' . $start_time->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			'DTEND:' . $end_time->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
		);
	}

	private static function ical_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $text );
	}

	/**
	 * Fold lines longer than 75 octets (RFC 5545 §3.1) without splitting UTF-8 characters.
	 */
	private static function ical_fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( strlen( $chunk ) + strlen( $char ) > $limit ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$limit = 74; // continuation lines start with a space
			}
			$chunk .= $char;
		}
		return $out . $chunk;
	}

	/* ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------- */

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(), $atts, 'game_calendar' );

		ob_start();
		include GC_PLUGIN_DIR . 'templates/calendar.php';
		return ob_get_clean();
	}

	/**
	 * [game_calendar_upcoming count="6" url="/kalender/"] — the next releases
	 * (exact day) and events as a strip of cover cards linking to the calendar.
	 */
	public function upcoming_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'count' => 6,
				'url'   => GC_Settings::get( 'gc_discord_calendar_url', '' ),
			),
			$atts,
			'game_calendar_upcoming'
		);

		$count = max( 1, min( 12, (int) $atts['count'] ) );
		$posts = $this->query_entries(
			array( 'gc_release', 'gc_dlc', 'gc_event' ),
			current_time( 'Y-m-d' ),
			'',
			'',
			false,
			$count
		);

		$items = array();
		foreach ( $posts as $post ) {
			if ( 'publish' !== $post->post_status ) {
				continue;
			}
			$is_event = ( 'gc_event' === $post->post_type );
			$date     = get_post_meta( $post->ID, $is_event ? 'gc_event_start' : 'gc_release_date', true );
			$items[]  = array(
				'post'      => $post,
				'date'      => substr( $date, 0, 10 ),
				'is_event'  => $is_event,
				'cover'     => $this->cover_for( $post ),
				'platforms' => $is_event ? array() : GC_Platforms::family_labels( $this->platforms_of( $post->ID ) ),
			);
		}
		usort( $items, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		$items = array_slice( $items, 0, $count );

		if ( ! $items ) {
			return '';
		}

		wp_enqueue_style( 'gc-upcoming' );
		$calendar_url = $atts['url'];

		ob_start();
		include GC_PLUGIN_DIR . 'templates/upcoming.php';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * Registered early so themes can enqueue it before the header when they
	 * render [game_calendar_upcoming] from a template.
	 */
	public function register_upcoming_style() {
		wp_register_style( 'gc-upcoming', GC_PLUGIN_URL . 'public/css/upcoming.css', array(), GC_VERSION );

		global $post;
		if ( $post && has_shortcode( $post->post_content, 'game_calendar_upcoming' ) ) {
			wp_enqueue_style( 'gc-upcoming' );
		}
	}

	/**
	 * FullCalendar locale for the site language ("nl_BE" → "nl"), or '' for
	 * FullCalendar's built-in English.
	 */
	public static function fullcalendar_locale() {
		$code = strtolower( str_replace( '_', '-', get_locale() ) );
		if ( in_array( $code, self::REGIONAL_LOCALES, true ) ) {
			return $code;
		}
		$lang = strtok( $code, '-' );
		return ( 'en' === $lang ) ? '' : $lang;
	}

	public function enqueue_assets() {
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'game_calendar' ) ) {
			return;
		}

		$fc = 'https://cdn.jsdelivr.net/npm/fullcalendar@' . self::FULLCALENDAR_VERSION . '/index.global.min';
		wp_enqueue_style( 'fullcalendar', $fc . '.css', array(), self::FULLCALENDAR_VERSION );
		wp_enqueue_script( 'fullcalendar', $fc . '.js', array(), self::FULLCALENDAR_VERSION, true );

		$deps   = array( 'fullcalendar' );
		$locale = self::fullcalendar_locale();
		if ( $locale ) {
			wp_enqueue_script(
				'fullcalendar-locale',
				'https://cdn.jsdelivr.net/npm/@fullcalendar/core@' . self::FULLCALENDAR_VERSION . '/locales/' . $locale . '.global.min.js',
				array( 'fullcalendar' ),
				self::FULLCALENDAR_VERSION,
				true
			);
			$deps[] = 'fullcalendar-locale';
		}

		wp_enqueue_style( 'gc-calendar', GC_PLUGIN_URL . 'public/css/calendar.css', array( 'fullcalendar' ), GC_VERSION );
		wp_enqueue_script( 'gc-calendar', GC_PLUGIN_URL . 'public/js/calendar.js', $deps, GC_VERSION, true );
		wp_localize_script( 'gc-calendar', 'gcCalendar', array(
			'restUrl'        => esc_url_raw( rest_url( 'game-calendar/v1/events' ) ),
			'unscheduledUrl' => esc_url_raw( rest_url( 'game-calendar/v1/unscheduled' ) ),
			'icalUrl'        => esc_url_raw( rest_url( 'game-calendar/v1/calendar.ics' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'locale'         => $locale ? $locale : 'en-gb',
			'dateLocale'     => str_replace( '_', '-', get_locale() ),
			'i18n'           => array(
				'gameRelease'  => __( 'Game release', 'game-calendar' ),
				'gamingEvent'  => __( 'Gaming event', 'game-calendar' ),
				'date'         => __( 'Date', 'game-calendar' ),
				'expected'     => __( 'Expected', 'game-calendar' ),
				'developer'    => __( 'Developer', 'game-calendar' ),
				'publisher'    => __( 'Publisher', 'game-calendar' ),
				'genre'        => __( 'Genre', 'game-calendar' ),
				'address'      => __( 'Address', 'game-calendar' ),
				'platforms'    => __( 'Platforms', 'game-calendar' ),
				'clickToOpen'  => __( 'Click to open ↗', 'game-calendar' ),
				'agenda'       => __( 'Agenda', 'game-calendar' ),
				'more'         => __( 'more', 'game-calendar' ),
				'noEvents'     => __( 'No releases or events in this period.', 'game-calendar' ),
				'share'        => __( 'Share', 'game-calendar' ),
				'copied'       => __( 'Copied!', 'game-calendar' ),
				'linkCopied'   => __( 'Link copied!', 'game-calendar' ),
			),
		) );
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'gc_release', 'gc_event', 'gc_dlc' ), true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'gc-admin', GC_PLUGIN_URL . 'admin/css/admin.css', array(), GC_VERSION );
		wp_enqueue_script(
			'gc-igdb-search',
			GC_PLUGIN_URL . 'admin/js/igdb-search.js',
			array( 'jquery' ),
			GC_VERSION,
			true
		);
		wp_localize_script( 'gc-igdb-search', 'gcAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'gc_igdb_search' ),
		) );
	}
}
