<?php
/**
 * Fisha Studio REST: duplicate a product or a category (the copy opens in the editor).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/products/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$p = fisha_studio_duplicate_product( (int) $r['id'] );
		return is_wp_error( $p ) ? $p : new WP_REST_Response( fisha_studio_product_payload( $p ), 201 );
	}, 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/categories/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$t = fisha_studio_duplicate_category( (int) $r['id'] );
		return is_wp_error( $t ) ? $t : new WP_REST_Response( fisha_studio_category_payload( $t ), 201 );
	}, 'permission_callback' => fisha_studio_permission( 'manage_product_terms' ) ) );
} );
