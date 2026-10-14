<?php
/**
 * Fisha Studio REST: History (the audit log for the owner), restore a version, recently deleted products.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/history', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_history', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
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
