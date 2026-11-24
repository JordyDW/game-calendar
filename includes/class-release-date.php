<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Release date precision.
 *
 * Many upcoming games only have a release window on IGDB ("Q2 2027", "2027").
 * Such releases are stored with gc_release_precision = month|quarter|year and
 * gc_release_date = the last day of that window (IGDB's own convention), so
 * "is it still upcoming?" stays a plain date comparison. They are kept off the
 * calendar grid (they would land on a made-up day) and listed separately.
 *
 * Entries without the meta are exact ("day"); that covers every release that
 * was entered by hand before precision existed.
 */
class GC_Release_Date {

	const META = 'gc_release_precision';

	// "tbd": IGDB no longer has any date. Kept, but off the calendar and lists.
	const PRECISIONS = array( 'day', 'month', 'quarter', 'year', 'tbd' );

	// IGDB release_dates.date_format ids (GET /v4/date_formats).
	const IGDB_DATE_FORMATS = array(
		0 => 'day',     // YYYYMMDD
		1 => 'month',   // YYYYMM
		2 => 'year',    // YYYY
		3 => 'quarter', // YYYYQ1
		4 => 'quarter', // YYYYQ2
		5 => 'quarter', // YYYYQ3
		6 => 'quarter', // YYYYQ4
		7 => 'tbd',
	);

	// The deprecated release_dates.category field used a different numbering.
	// Only used when IGDB still sends it and date_format is missing.
	const IGDB_CATEGORIES = array(
		0 => 'day',
		1 => 'month',
		2 => 'quarter',
		3 => 'quarter',
		4 => 'quarter',
		5 => 'quarter',
		6 => 'year',
		7 => 'tbd',
	);

	// Lower = more precise.
	const RANK = array( 'day' => 0, 'month' => 1, 'quarter' => 2, 'year' => 3 );

	/**
	 * IGDB field list for release dates, for use in a games query.
	 */
	const IGDB_FIELDS = 'release_dates.date,release_dates.date_format';

	/**
	 * The earliest release IGDB knows for a game (like IGDB's own
	 * first_release_date), with its precision. A window counts by its last
	 * day, so an exact date wins unless a window ends before it; on the same
	 * day the more precise one wins.
	 *
	 * @param array $game IGDB game with release_dates (see IGDB_FIELDS).
	 * @return array|null array( 'date' => 'Y-m-d', 'precision' => day|month|quarter|year ),
	 *                    array( 'date' => null, 'precision' => 'tbd' ) when IGDB only says TBD,
	 *                    or null when IGDB has no release information at all.
	 */
	public static function from_igdb( array $game ) {
		$best    = null;
		$has_tbd = false;

		foreach ( (array) ( $game['release_dates'] ?? array() ) as $rd ) {
			if ( isset( $rd['date_format'] ) ) {
				$precision = self::IGDB_DATE_FORMATS[ (int) $rd['date_format'] ] ?? null;
			} elseif ( isset( $rd['category'] ) ) {
				$precision = self::IGDB_CATEGORIES[ (int) $rd['category'] ] ?? null;
			} else {
				$precision = null;
			}
			if ( 'tbd' === $precision ) {
				$has_tbd = true;
				continue;
			}
			if ( empty( $rd['date'] ) || ! isset( self::RANK[ $precision ] ) ) {
				continue; // Unknown format.
			}

			$date = self::normalize( gmdate( 'Y-m-d', (int) $rd['date'] ), $precision );
			if (
				null === $best
				|| $date < $best['date']
				|| ( $date === $best['date'] && self::RANK[ $precision ] < self::RANK[ $best['precision'] ] )
			) {
				$best = array( 'date' => $date, 'precision' => $precision );
			}
		}

		if ( $best ) {
			return $best;
		}
		if ( $has_tbd ) {
			return array( 'date' => null, 'precision' => 'tbd' );
		}

		// No per-platform release dates at all: trust first_release_date as a day.
		if ( empty( $game['release_dates'] ) && ! empty( $game['first_release_date'] ) ) {
			return array(
				'date'      => gmdate( 'Y-m-d', (int) $game['first_release_date'] ),
				'precision' => 'day',
			);
		}

		return null;
	}

	/**
	 * IGDB no longer has a date: keep the stored date, but take the entry off
	 * the calendar until a date (or window) appears again.
	 */
	public static function mark_tbd( $post_id ) {
		update_post_meta( $post_id, self::META, 'tbd' );
	}

	/**
	 * Move a date to the last day of its window (month, quarter or year).
	 */
	public static function normalize( $date, $precision ) {
		$ts = strtotime( $date . ' 00:00:00 UTC' );
		if ( ! $ts ) {
			return $date;
		}
		$y = (int) gmdate( 'Y', $ts );
		$m = (int) gmdate( 'n', $ts );

		switch ( $precision ) {
			case 'month':
				return gmdate( 'Y-m-t', $ts );
			case 'quarter':
				$last_month = (int) ( ceil( $m / 3 ) * 3 );
				return gmdate( 'Y-m-t', gmmktime( 0, 0, 0, $last_month, 1, $y ) );
			case 'year':
				return sprintf( '%04d-12-31', $y );
			default:
				return gmdate( 'Y-m-d', $ts );
		}
	}

	public static function sanitize( $precision ) {
		return in_array( $precision, self::PRECISIONS, true ) ? $precision : 'day';
	}

	public static function get_precision( $post_id ) {
		return self::sanitize( get_post_meta( $post_id, self::META, true ) ?: 'day' );
	}

	public static function is_vague( $post_id ) {
		return in_array( get_post_type( $post_id ), array( 'gc_release', 'gc_dlc' ), true )
			&& 'day' !== self::get_precision( $post_id );
	}

	/**
	 * Store a release date. Pass $precision = null to keep the stored precision
	 * (e.g. quick edits that only send a date).
	 */
	public static function save( $post_id, $date, $precision = null ) {
		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return;
		}
		$precision = null === $precision ? self::get_precision( $post_id ) : self::sanitize( $precision );

		update_post_meta( $post_id, 'gc_release_date', self::normalize( $date, $precision ) );
		if ( 'day' === $precision ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $precision );
		}
	}

	/**
	 * Human label: "12 maart 2027", "maart 2027", "Q1 2027" or "2027".
	 */
	public static function label( $date, $precision ) {
		$ts = strtotime( $date . ' 12:00:00' );
		if ( ! $ts ) {
			return (string) $date;
		}
		switch ( self::sanitize( $precision ) ) {
			case 'month':
				return date_i18n( 'F Y', $ts );
			case 'quarter':
				return sprintf(
					/* translators: 1: quarter number (1-4), 2: year. */
					__( 'Q%1$d %2$d', 'game-calendar' ),
					(int) ceil( (int) gmdate( 'n', $ts ) / 3 ),
					(int) gmdate( 'Y', $ts )
				);
			case 'year':
				return gmdate( 'Y', $ts );
			case 'tbd':
				return __( 'Date unknown', 'game-calendar' );
			default:
				return date_i18n( 'j F Y', $ts );
		}
	}

	public static function post_label( $post_id ) {
		return self::label( get_post_meta( $post_id, 'gc_release_date', true ), self::get_precision( $post_id ) );
	}

	/**
	 * meta_query clause matching only releases with an exact day.
	 */
	public static function exact_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::META,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => self::META,
				'value' => 'day',
			),
		);
	}

	public static function precision_options() {
		return array(
			'day'     => __( 'Exact day', 'game-calendar' ),
			'month'   => __( 'Month only', 'game-calendar' ),
			'quarter' => __( 'Quarter only', 'game-calendar' ),
			'year'    => __( 'Year only', 'game-calendar' ),
			'tbd'     => __( 'Unknown (hidden)', 'game-calendar' ),
		);
	}

	public static function render_select( $id, $name, $current, $class = '' ) {
		printf( '<select id="%s" name="%s" class="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $class ) );
		foreach ( self::precision_options() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}
