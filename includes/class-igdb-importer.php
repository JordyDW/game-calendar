<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_IGDB_Importer {

	const CRON_HOOK = 'gc_igdb_auto_import';

	const PLATFORM_MAP = array(
		6   => 'PC',
		167 => 'PS5',
		48  => 'PS4',
		169 => 'Xbox Series X/S',
		49  => 'Xbox One',
		130 => 'Nintendo Switch',
		471 => 'Nintendo Switch 2',
		39  => 'iOS',
		34  => 'Android',
	);

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'update_option_' . GC_Settings::OPTION_NAME, array( $this, 'reschedule' ) );
		add_action( 'add_option_' . GC_Settings::OPTION_NAME, array( $this, 'reschedule' ) );
		add_action( 'wp_ajax_gc_igdb_run_import', array( $this, 'ajax_run_import' ) );
	}

	public static function schedule_events() {
		self::clear_events();
		if ( ! GC_Settings::get( 'gc_igdb_auto_import_enabled' ) ) {
			return;
		}
		$freq       = GC_Settings::get( 'gc_igdb_auto_import_frequency', 'daily' );
		$recurrence = ( 'weekly' === $freq ) ? 'weekly' : 'daily';
		wp_schedule_event( time() + 60, $recurrence, self::CRON_HOOK );
	}

	public static function clear_events() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function maybe_schedule() {
		if ( GC_Settings::get( 'gc_igdb_auto_import_enabled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_events();
		}
	}

	public function reschedule() {
		self::schedule_events();
	}

	public function run() {
		$imported = $this->fetch_upcoming();
		$this->run_date_sync();
		update_option( 'gc_igdb_last_import', array(
			'time'     => current_time( 'mysql' ),
			'imported' => $imported,
		) );
	}

	private function fetch_upcoming() {
		$threshold    = max( 0, (int) GC_Settings::get( 'gc_igdb_hypes_threshold', 5 ) );
		$platform_ids = (array) GC_Settings::get( 'gc_igdb_auto_import_platforms', array() );
		$window_days  = max( 30, (int) GC_Settings::get( 'gc_igdb_import_window_days', 365 ) );
		$now          = time();
		$future       = $now + ( $window_days * DAY_IN_SECONDS );

		$where = sprintf(
			'first_release_date >= %d & first_release_date <= %d & hypes >= %d',
			$now,
			$future,
			$threshold
		);
		if ( ! empty( $platform_ids ) ) {
			$where .= ' & platforms = (' . implode( ',', array_map( 'intval', $platform_ids ) ) . ')';
		}

		$body = 'fields id,name,url,cover.url,first_release_date,genres.name,'
			. 'involved_companies.company.name,involved_companies.developer,'
			. 'involved_companies.publisher,platforms.id,platforms.name,hypes,follows; '
			. 'where ' . $where . '; '
			. 'sort hypes desc; limit 50;';

		$api     = new GC_IGDB_API();
		$results = $api->query_games( $body );

		if ( is_wp_error( $results ) || ! is_array( $results ) ) {
			return 0;
		}

		$imported = 0;
		foreach ( $results as $game ) {
			$post_id = $this->upsert_game( $game );
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$imported++;
			}
		}
		return $imported;
	}

	private function find_post_by_igdb_id( $igdb_id ) {
		$q = new WP_Query( array(
			'post_type'      => 'gc_release',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => 'gc_igdb_id',
				'value'   => (int) $igdb_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			) ),
		) );
		return $q->posts[0] ?? null;
	}

	private function map_game( $game ) {
		$developer = '';
		$publisher = '';
		if ( ! empty( $game['involved_companies'] ) ) {
			foreach ( $game['involved_companies'] as $ic ) {
				if ( ! empty( $ic['developer'] ) && '' === $developer ) {
					$developer = $ic['company']['name'] ?? '';
				}
				if ( ! empty( $ic['publisher'] ) && '' === $publisher ) {
					$publisher = $ic['company']['name'] ?? '';
				}
			}
		}

		$cover = '';
		if ( ! empty( $game['cover']['url'] ) ) {
			$cover = 'https:' . str_replace( 't_thumb', 't_cover_big', $game['cover']['url'] );
		}

		$release_date = '';
		if ( ! empty( $game['first_release_date'] ) ) {
			$release_date = gmdate( 'Y-m-d', (int) $game['first_release_date'] );
		}

		$genre = '';
		if ( ! empty( $game['genres'] ) ) {
			$genre = implode( ', ', array_column( $game['genres'], 'name' ) );
		}

		$platforms = array();
		if ( ! empty( $game['platforms'] ) ) {
			foreach ( $game['platforms'] as $p ) {
				$pid = (int) ( $p['id'] ?? 0 );
				if ( isset( self::PLATFORM_MAP[ $pid ] ) ) {
					$platforms[] = self::PLATFORM_MAP[ $pid ];
				} elseif ( ! empty( $p['name'] ) ) {
					$platforms[] = $p['name'];
				}
			}
			$platforms = array_unique( $platforms );
		}

		return array(
			'title'        => $game['name'] ?? '',
			'release_date' => $release_date,
			'developer'    => $developer,
			'publisher'    => $publisher,
			'genre'        => $genre,
			'cover'        => $cover,
			'url'          => $game['url'] ?? '',
			'platforms'    => $platforms,
		);
	}

	private function upsert_game( $game ) {
		$igdb_id     = (int) ( $game['id'] ?? 0 );
		$existing_id = $this->find_post_by_igdb_id( $igdb_id );

		if ( $existing_id ) {
			return false;
		}

		$mapped      = $this->map_game( $game );
		$post_status = GC_Settings::get( 'gc_igdb_import_post_status', 'publish' );
		$post_id     = wp_insert_post( array(
			'post_title'   => $mapped['title'],
			'post_content' => '',
			'post_status'  => $post_status,
			'post_type'    => 'gc_release',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Mark as auto-imported before the 30-second Discord cron fires.
		update_post_meta( $post_id, 'gc_auto_imported', 1 );

		// Only suppress instant Discord on auto-published entries — drafts should
		// still notify when the admin manually publishes them after review.
		if ( 'publish' === $post_status && GC_Settings::get( 'gc_igdb_suppress_discord' ) ) {
			update_post_meta( $post_id, 'gc_discord_skip_instant', 1 );
		}

		update_post_meta( $post_id, 'gc_igdb_id',     $igdb_id );
		update_post_meta( $post_id, 'gc_release_date', $mapped['release_date'] );
		update_post_meta( $post_id, 'gc_developer',    $mapped['developer'] );
		update_post_meta( $post_id, 'gc_publisher',    $mapped['publisher'] );
		update_post_meta( $post_id, 'gc_genre',        $mapped['genre'] );
		update_post_meta( $post_id, 'gc_cover_url',    $mapped['cover'] );
		update_post_meta( $post_id, 'gc_url',          $mapped['url'] );

		if ( ! empty( $mapped['platforms'] ) ) {
			wp_set_object_terms( $post_id, array_values( $mapped['platforms'] ), 'gc_platform' );
		}

		return $post_id;
	}

	public function run_date_sync() {
		$posts = get_posts( array(
			'post_type'      => 'gc_release',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => 'gc_igdb_id',
				'compare' => 'EXISTS',
			) ),
		) );

		if ( empty( $posts ) ) {
			return;
		}

		$id_map = array();
		foreach ( $posts as $post_id ) {
			$igdb_id = (int) get_post_meta( $post_id, 'gc_igdb_id', true );
			if ( $igdb_id > 0 ) {
				$id_map[ $igdb_id ] = $post_id;
			}
		}

		if ( empty( $id_map ) ) {
			return;
		}

		$api = new GC_IGDB_API();
		foreach ( array_chunk( array_keys( $id_map ), 500 ) as $chunk ) {
			$ids_str = implode( ',', array_map( 'intval', $chunk ) );
			$body    = 'fields id,first_release_date; where id = (' . $ids_str . '); limit 500;';
			$results = $api->query_games( $body );

			if ( is_wp_error( $results ) || ! is_array( $results ) ) {
				continue;
			}

			foreach ( $results as $igdb_game ) {
				$igdb_id = (int) ( $igdb_game['id'] ?? 0 );
				if ( ! isset( $id_map[ $igdb_id ] ) || empty( $igdb_game['first_release_date'] ) ) {
					continue;
				}
				$post_id      = $id_map[ $igdb_id ];
				$new_date     = gmdate( 'Y-m-d', (int) $igdb_game['first_release_date'] );
				$current_date = get_post_meta( $post_id, 'gc_release_date', true );
				if ( $new_date !== $current_date ) {
					update_post_meta( $post_id, 'gc_release_date', $new_date );
				}
			}
		}
	}

	public function ajax_run_import() {
		check_ajax_referer( 'gc_igdb_run_import', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}
		$this->run();
		$last = get_option( 'gc_igdb_last_import', array() );
		wp_send_json_success( array( 'message' => sprintf(
			/* translators: %d: number of new entries created */
			__( 'Import complete. %d new entries created.', 'game-calendar' ),
			(int) ( $last['imported'] ?? 0 )
		) ) );
	}
}
