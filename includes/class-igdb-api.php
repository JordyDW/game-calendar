<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_IGDB_API {

	const TOKEN_TRANSIENT = 'gc_igdb_token';
	const TOKEN_URL       = 'https://id.twitch.tv/oauth2/token';
	const IGDB_BASE       = 'https://api.igdb.com/v4/';

	public function __construct() {
		add_action( 'wp_ajax_gc_igdb_search', array( $this, 'ajax_search' ) );
	}

	public function get_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$client_id     = GC_Settings::get( 'gc_igdb_client_id' );
		$client_secret = GC_Settings::get( 'gc_igdb_client_secret' );

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error( 'gc_no_credentials', __( 'IGDB Client ID and Secret are not configured. Go to Settings → Game Calendar.', 'game-calendar' ) );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'client_credentials',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['access_token'] ) ) {
			$msg = $body['message'] ?? __( 'Failed to obtain IGDB token.', 'game-calendar' );
			return new WP_Error( 'gc_token_error', $msg );
		}

		$expires_in = (int) ( $body['expires_in'] ?? 3600 );
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $expires_in - 300 );

		return $body['access_token'];
	}

	private function request( $endpoint, $body ) {
		$token     = $this->get_token();
		$client_id = GC_Settings::get( 'gc_igdb_client_id' );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( self::IGDB_BASE . $endpoint, array(
			'headers' => array(
				'Client-ID'     => $client_id,
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
			'body'    => $body,
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'gc_igdb_error', sprintf( __( 'IGDB returned HTTP %d.', 'game-calendar' ), $code ) );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	public function query_games( $body ) {
		return $this->request( 'games', $body );
	}

	public function search_games( $query ) {
		$search_mode = GC_Settings::get( 'gc_igdb_search_mode', 'future_releases' );

		$where = ( 'future_releases' === $search_mode )
			? 'where first_release_date >= ' . time() . '; '
			: '';

		$body = 'search "' . addslashes( $query ) . '"; '
			. 'fields id,name,url,cover.url,first_release_date,genres.name,involved_companies.company.name,involved_companies.developer,involved_companies.publisher,platforms.name; '
			. $where
			. 'limit 8;';

		return $this->request( 'games', $body );
	}

	public function ajax_search() {
		check_ajax_referer( 'gc_igdb_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'game-calendar' ) ) );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( strlen( $query ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Query too short.', 'game-calendar' ) ) );
		}

		$results = $this->search_games( $query );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		$games = array();
		foreach ( (array) $results as $game ) {
			$developer = '';
			$publisher = '';
			if ( ! empty( $game['involved_companies'] ) ) {
				foreach ( $game['involved_companies'] as $ic ) {
					if ( ! empty( $ic['developer'] ) && empty( $developer ) ) {
						$developer = $ic['company']['name'] ?? '';
					}
					if ( ! empty( $ic['publisher'] ) && empty( $publisher ) ) {
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

			$genres = '';
			if ( ! empty( $game['genres'] ) ) {
				$genres = implode( ', ', array_column( $game['genres'], 'name' ) );
			}

			$platforms = array();
			if ( ! empty( $game['platforms'] ) ) {
				$platforms = array_column( $game['platforms'], 'name' );
			}

			$games[] = array(
				'id'           => $game['id'],
				'name'         => $game['name'],
				'url'          => $game['url'] ?? '',
				'cover'        => $cover,
				'release_date' => $release_date,
				'developer'    => $developer,
				'publisher'    => $publisher,
				'genre'        => $genres,
				'platforms'    => $platforms,
			);
		}

		wp_send_json_success( $games );
	}
}
