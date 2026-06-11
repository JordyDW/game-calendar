<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Post_Types {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		$this->register_release();
		$this->register_event();
		$this->register_dlc();
		$this->register_event_type_taxonomy();
		$this->register_dlc_type_taxonomy();
		$this->register_platform_taxonomy();
	}

	private function register_release() {
		register_post_type( 'gc_release', array(
			'labels'       => array(
				'name'               => __( 'Game Releases', 'game-calendar' ),
				'singular_name'      => __( 'Game Release', 'game-calendar' ),
				'add_new_item'       => __( 'Add New Game Release', 'game-calendar' ),
				'edit_item'          => __( 'Edit Game Release', 'game-calendar' ),
				'new_item'           => __( 'New Game Release', 'game-calendar' ),
				'view_item'          => __( 'View Game Release', 'game-calendar' ),
				'search_items'       => __( 'Search Game Releases', 'game-calendar' ),
				'not_found'          => __( 'No game releases found.', 'game-calendar' ),
				'not_found_in_trash' => __( 'No game releases found in Trash.', 'game-calendar' ),
				'menu_name'          => __( 'Game Releases', 'game-calendar' ),
			),
			'public'       => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'  => true,
			'rewrite'      => array( 'slug' => 'game-releases' ),
			'menu_icon'    => 'dashicons-games',
		) );
	}

	private function register_event() {
		register_post_type( 'gc_event', array(
			'labels'       => array(
				'name'               => __( 'Gaming Events', 'game-calendar' ),
				'singular_name'      => __( 'Gaming Event', 'game-calendar' ),
				'add_new_item'       => __( 'Add New Gaming Event', 'game-calendar' ),
				'edit_item'          => __( 'Edit Gaming Event', 'game-calendar' ),
				'new_item'           => __( 'New Gaming Event', 'game-calendar' ),
				'view_item'          => __( 'View Gaming Event', 'game-calendar' ),
				'search_items'       => __( 'Search Gaming Events', 'game-calendar' ),
				'not_found'          => __( 'No gaming events found.', 'game-calendar' ),
				'not_found_in_trash' => __( 'No gaming events found in Trash.', 'game-calendar' ),
				'menu_name'          => __( 'Gaming Events', 'game-calendar' ),
			),
			'public'       => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'  => true,
			'rewrite'      => array( 'slug' => 'gaming-events' ),
		) );
	}

	private function register_dlc() {
		register_post_type( 'gc_dlc', array(
			'labels'       => array(
				'name'               => __( 'DLC & Updates', 'game-calendar' ),
				'singular_name'      => __( 'DLC / Update', 'game-calendar' ),
				'add_new_item'       => __( 'Add New DLC / Update', 'game-calendar' ),
				'edit_item'          => __( 'Edit DLC / Update', 'game-calendar' ),
				'new_item'           => __( 'New DLC / Update', 'game-calendar' ),
				'view_item'          => __( 'View DLC / Update', 'game-calendar' ),
				'search_items'       => __( 'Search DLC & Updates', 'game-calendar' ),
				'not_found'          => __( 'No DLC or updates found.', 'game-calendar' ),
				'not_found_in_trash' => __( 'No DLC or updates found in Trash.', 'game-calendar' ),
				'menu_name'          => __( 'DLC & Updates', 'game-calendar' ),
			),
			'public'       => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'  => true,
			'rewrite'      => array( 'slug' => 'dlc-updates' ),
		) );
	}

	private function register_event_type_taxonomy() {
		register_taxonomy( 'gc_event_type', 'gc_event', array(
			'labels'       => array(
				'name'          => __( 'Event Types', 'game-calendar' ),
				'singular_name' => __( 'Event Type', 'game-calendar' ),
				'add_new_item'  => __( 'Add New Event Type', 'game-calendar' ),
				'edit_item'     => __( 'Edit Event Type', 'game-calendar' ),
			),
			'hierarchical' => true,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'event-type' ),
		) );

		$defaults = array( 'Conference', 'Showcase', 'Stream', 'Podcast Episode' );
		foreach ( $defaults as $term ) {
			if ( ! term_exists( $term, 'gc_event_type' ) ) {
				wp_insert_term( $term, 'gc_event_type' );
			}
		}
	}

	private function register_dlc_type_taxonomy() {
		register_taxonomy( 'gc_dlc_type', 'gc_dlc', array(
			'labels'       => array(
				'name'          => __( 'DLC Types', 'game-calendar' ),
				'singular_name' => __( 'DLC Type', 'game-calendar' ),
				'add_new_item'  => __( 'Add New DLC Type', 'game-calendar' ),
				'edit_item'     => __( 'Edit DLC Type', 'game-calendar' ),
			),
			'hierarchical' => true,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'dlc-type' ),
		) );

		$defaults = array( 'DLC', 'Expansion', 'Patch', 'Update' );
		foreach ( $defaults as $term ) {
			if ( ! term_exists( $term, 'gc_dlc_type' ) ) {
				wp_insert_term( $term, 'gc_dlc_type' );
			}
		}
	}

	private function register_platform_taxonomy() {
		register_taxonomy( 'gc_platform', array( 'gc_release', 'gc_dlc' ), array(
			'labels'       => array(
				'name'          => __( 'Platforms', 'game-calendar' ),
				'singular_name' => __( 'Platform', 'game-calendar' ),
				'add_new_item'  => __( 'Add New Platform', 'game-calendar' ),
				'edit_item'     => __( 'Edit Platform', 'game-calendar' ),
			),
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'platform' ),
		) );

		$defaults = array( 'PC', 'PS5', 'PS4', 'Xbox Series X/S', 'Xbox One', 'Nintendo Switch', 'iOS', 'Android' );
		foreach ( $defaults as $term ) {
			if ( ! term_exists( $term, 'gc_platform' ) ) {
				wp_insert_term( $term, 'gc_platform' );
			}
		}
	}
}
