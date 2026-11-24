<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Platform families for the public filter.
 *
 * The gc_platform taxonomy holds both IGDB's names ("PlayStation 5",
 * "PC (Microsoft Windows)") and the importer's short names ("PS5", "PC"), so
 * visitors filter on a handful of families instead of individual terms.
 */
class GC_Platforms {

	const TAXONOMY = 'gc_platform';

	private static $terms = null;

	/**
	 * Family slug => array( label, pattern matched against term names ).
	 */
	public static function families() {
		return array(
			'playstation' => array( 'label' => 'PlayStation', 'pattern' => '/^(PS\s?\d|PlayStation)/i' ),
			'xbox'        => array( 'label' => 'Xbox', 'pattern' => '/^Xbox/i' ),
			'nintendo'    => array( 'label' => 'Nintendo', 'pattern' => '/(Nintendo|Switch)/i' ),
			'pc'          => array( 'label' => 'PC', 'pattern' => '/^(PC\b|Windows|Mac\b|Linux\b)/i' ),
			'mobile'      => array( 'label' => __( 'Mobile', 'game-calendar' ), 'pattern' => '/^(iOS|Android)/i' ),
		);
	}

	public static function family_of( $term_name ) {
		foreach ( self::families() as $slug => $family ) {
			if ( preg_match( $family['pattern'], (string) $term_name ) ) {
				return $slug;
			}
		}
		return null;
	}

	private static function terms() {
		if ( null === self::$terms ) {
			$terms       = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
			self::$terms = is_wp_error( $terms ) ? array() : $terms;
		}
		return self::$terms;
	}

	/**
	 * Parse a "playstation,xbox" request value into valid family slugs.
	 */
	public static function parse( $value ) {
		$slugs = is_array( $value ) ? $value : explode( ',', (string) $value );
		$slugs = array_map( 'sanitize_key', array_map( 'trim', $slugs ) );
		return array_values( array_intersect( array_keys( self::families() ), $slugs ) );
	}

	/**
	 * Term IDs belonging to the requested families, or null when there is no
	 * platform filter. An empty array means "nothing matches".
	 */
	public static function term_ids_for( $value ) {
		$families = self::parse( $value );
		if ( ! $families ) {
			return null;
		}
		$ids = array();
		foreach ( self::terms() as $term ) {
			if ( in_array( self::family_of( $term->name ), $families, true ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * tax_query clause for a platform filter, or null when there is none.
	 */
	public static function tax_query( $value ) {
		$ids = self::term_ids_for( $value );
		if ( null === $ids ) {
			return null;
		}
		return array(
			array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $ids ? $ids : array( 0 ),
			),
		);
	}

	/**
	 * Families that have at least one entry, in display order: slug => label.
	 */
	public static function available_families() {
		$used = array();
		foreach ( self::terms() as $term ) {
			if ( $term->count > 0 ) {
				$family = self::family_of( $term->name );
				if ( $family ) {
					$used[ $family ] = true;
				}
			}
		}
		$out = array();
		foreach ( self::families() as $slug => $family ) {
			if ( isset( $used[ $slug ] ) ) {
				$out[ $slug ] = $family['label'];
			}
		}
		return $out;
	}

	/**
	 * Family labels for a set of platform names, e.g. "PlayStation · PC".
	 */
	public static function family_labels( array $platform_names ) {
		$families = self::families();
		$labels   = array();
		foreach ( $platform_names as $name ) {
			$slug = self::family_of( $name );
			if ( $slug ) {
				$labels[ $slug ] = $families[ $slug ]['label'];
			}
		}
		// Keep the families' display order.
		return array_values( array_intersect_key( array_map( fn( $f ) => $f['label'], $families ), $labels ) );
	}
}
