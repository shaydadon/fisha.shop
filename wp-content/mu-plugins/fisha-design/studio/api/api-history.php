<?php
/**
 * Fisha Studio REST: History (the audit log for the owner), restore a version, recently deleted products.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/history', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_history', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/products/(?P<id>\d+)/restore', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_product_restore', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/categories/(?P<id>\d+)/restore', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_category_restore', 'permission_callback' => fisha_studio_permission( 'manage_product_terms' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/trash', array( 'methods' => 'GET', 'callback' => function () { return array( 'items' => fisha_studio_trash_list() ); }, 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/products/(?P<id>\d+)/untrash', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_product_untrash', 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
} );

function fisha_studio_rest_history( WP_REST_Request $r ): array {
	return fisha_studio_history_query( array(
		'kind'      => in_array( $r['kind'], array( 'all', 'product', 'category', 'signin', 'other' ), true ) ? $r['kind'] : 'all',
		'item_type' => in_array( $r['item_type'], array( 'product', 'category' ), true ) ? $r['item_type'] : '',
		'item_id'   => absint( $r['item_id'] ),
		'days'      => in_array( (int) $r['days'], array( 1, 7, 30 ), true ) ? (int) $r['days'] : 0,
		'page'      => max( 1, (int) $r['page'] ),
		'per_page'  => (int) ( $r['per_page'] ?: 20 ),
	) );
}

function fisha_studio_rest_product_restore( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$res = fisha_studio_restore( 'product', $p->get_id(), absint( fisha_studio_body( $r )['log_id'] ?? 0 ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'skipped' => $res['skipped'] ) );
}
function fisha_studio_rest_category_restore( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$res = fisha_studio_restore( 'category', (int) $t->term_id, absint( fisha_studio_body( $r )['log_id'] ?? 0 ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) ), array( 'skipped' => $res['skipped'] ) );
}
function fisha_studio_rest_product_untrash( WP_REST_Request $r ) {
	$p = fisha_studio_untrash_product( (int) $r['id'] );
	return is_wp_error( $p ) ? $p : fisha_studio_product_payload( $p );
}
