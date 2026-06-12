<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Calendar_Query {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_shortcode( 'game_calendar', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_routes() {
		register_rest_route( 'game-calendar/v1', '/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_events' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'start' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'end'   => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'type'  => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	public function get_events( WP_REST_Request $request ) {
		$start = $request->get_param( 'start' );
		$end   = $request->get_param( 'end' );
		$type  = $request->get_param( 'type' );

		// FullCalendar sends ISO 8601 datetimes — keep only YYYY-MM-DD for the BETWEEN comparison.
		$start_date = $start ? substr( $start, 0, 10 ) : '';
		$end_date   = $end   ? substr( $end,   0, 10 ) : '';

		$events = array();
		$valid  = array( 'gc_release', 'gc_event', 'gc_dlc' );

		if ( $type ) {
			$types = array_values( array_intersect(
				array_map( 'sanitize_key', explode( ',', $type ) ),
				$valid
			) );
		} else {
			$types = $valid;
		}

		// gc_dlc is treated as gc_release — include it whenever releases are requested.
		if ( in_array( 'gc_release', $types, true ) && ! in_array( 'gc_dlc', $types, true ) ) {
			$types[] = 'gc_dlc';
		}

		if ( empty( $types ) ) {
			return rest_ensure_response( array() );
		}

		$options      = get_option( 'gc_options', array() );
		$release_color = $options['gc_color_release'] ?? '#ac00fb';
		$color_map    = array(
			'gc_release' => $release_color,
			'gc_event'   => $options['gc_color_event'] ?? '#96eefe',
			'gc_dlc'     => $release_color,
		);

		foreach ( $types as $post_type ) {
			$meta_key = ( 'gc_event' === $post_type ) ? 'gc_event_start' : 'gc_release_date';

			// When a date range is provided, filter by it; otherwise require the meta key to exist.
			if ( $start_date && $end_date ) {
				$meta_q = array(
					'key'     => $meta_key,
					'value'   => array( $start_date, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				);
			} else {
				$meta_q = array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				);
			}

			$args = array(
				'post_type'      => $post_type,
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				'meta_key'       => $meta_key,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( $meta_q ),
			);

			$posts = get_posts( $args );

			foreach ( $posts as $post ) {
				$date      = get_post_meta( $post->ID, $meta_key, true );
				$event_end = ( 'gc_event' === $post_type ) ? get_post_meta( $post->ID, 'gc_event_end', true ) : '';
				$url       = ( 'gc_event' === $post_type )
					? get_post_meta( $post->ID, 'gc_event_url', true )
					: get_post_meta( $post->ID, 'gc_url', true );

				if ( ! $date ) {
					continue;
				}

				$cover = '';
				if ( in_array( $post_type, array( 'gc_release', 'gc_dlc' ), true ) ) {
					$cover = get_post_meta( $post->ID, 'gc_cover_url', true );
					if ( ! $cover && has_post_thumbnail( $post->ID ) ) {
						$cover = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
					}
				} elseif ( 'gc_event' === $post_type ) {
					$cover = get_post_meta( $post->ID, 'gc_event_cover_url', true );
					if ( ! $cover && has_post_thumbnail( $post->ID ) ) {
						$cover = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
					}
				}

				$event = array(
					'id'            => $post->ID,
					'title'         => $post->post_title,
					'start'         => $date,
					'color'         => $color_map[ $post_type ],
					'url'           => $url,
					'extendedProps' => array(
						'type'  => $post_type,
						'cover' => $cover,
					),
				);

				if ( $event_end ) {
					$event['end'] = $event_end;
				}

				if ( in_array( $post_type, array( 'gc_release', 'gc_dlc' ), true ) ) {
					$event['extendedProps']['developer'] = get_post_meta( $post->ID, 'gc_developer', true );
					$event['extendedProps']['publisher'] = get_post_meta( $post->ID, 'gc_publisher', true );
					$event['extendedProps']['genre']     = get_post_meta( $post->ID, 'gc_genre', true );
					$platforms = wp_get_post_terms( $post->ID, 'gc_platform', array( 'fields' => 'names' ) );
					$event['extendedProps']['platforms'] = is_wp_error( $platforms ) ? array() : $platforms;
				}

				$events[] = $event;
			}
		}

		return rest_ensure_response( $events );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(), $atts, 'game_calendar' );

		ob_start();
		include GC_PLUGIN_DIR . 'templates/calendar.php';
		return ob_get_clean();
	}

	public function enqueue_assets() {
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'game_calendar' ) ) {
			return;
		}

		wp_enqueue_style(
			'fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css',
			array(),
			'6.1.11'
		);
		wp_enqueue_script(
			'fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
			array(),
			'6.1.11',
			true
		);
		wp_enqueue_style(
			'gc-calendar',
			GC_PLUGIN_URL . 'public/css/calendar.css',
			array( 'fullcalendar' ),
			GC_VERSION
		);
		wp_enqueue_script(
			'gc-calendar',
			GC_PLUGIN_URL . 'public/js/calendar.js',
			array( 'fullcalendar' ),
			GC_VERSION,
			true
		);
		wp_localize_script( 'gc-calendar', 'gcCalendar', array(
			'restUrl' => esc_url_raw( rest_url( 'game-calendar/v1/events' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
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
