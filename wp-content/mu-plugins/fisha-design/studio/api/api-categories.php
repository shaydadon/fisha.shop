<?php
/**
 * Fisha Studio REST: categories. The tree with product counts and why a category is not on the site, create (a
 * never-published category), read, autosave a draft, discard, publish, Show on site, order among siblings and delete.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$id   = '/categories/(?P<id>\d+)';
	$edit = fisha_studio_permission( 'manage_product_terms' );
	register_rest_route( FISHA_STUDIO_NS, '/categories', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_categories_list', 'permission_callback' => $edit ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_create', 'permission_callback' => $edit ),
	) );
	register_rest_route( FISHA_STUDIO_NS, '/categories/order', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_order', 'permission_callback' => $edit ) );
	register_rest_route( FISHA_STUDIO_NS, $id, array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_categories_get', 'permission_callback' => $edit ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_categories_delete', 'permission_callback' => fisha_studio_permission( 'delete_product_terms' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/draft', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_categories_draft', 'permission_callback' => $edit ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_categories_discard', 'permission_callback' => $edit ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/publish', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_publish', 'permission_callback' => $edit ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/shown', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_categories_shown', 'permission_callback' => $edit ) );
} );

/** @return WP_Term|WP_Error */
function fisha_studio_rest_category( WP_REST_Request $r ) {
	return fisha_studio_category( (int) $r['id'] ) ?? fisha_studio_category_missing();
}

/** Published products in the category and below it. */
function fisha_studio_category_count( WP_Term $t ): int {
	return count( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'ids', 'category' => array( $t->slug ) ) ) );
}

/** The address up to the category's own part: ".../product-category/" or the parent's address. */
function fisha_studio_category_base_url( int $parent ): string {
	$p = fisha_studio_category( $parent );
	if ( $p ) return trailingslashit( get_term_link( $p ) );
	return home_url( '/' . trim( wc_get_permalink_structure()['category_rewrite_slug'], '/' ) . '/' );
}

/** Why a category is not on the site: '' (it is), 'new', 'hidden', 'parent' (a parent is hidden) or 'empty' (no products). */
function fisha_studio_category_off_reason( WP_Term $t ): string {
	if ( fisha_cat_is_unpublished( $t ) ) return 'new';
	if ( '1' !== (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ) ) return 'hidden';
	if ( ! fisha_cat_is_enabled( $t ) ) return 'parent';
	return fisha_cat_has_products( $t ) ? '' : 'empty';
}

function fisha_studio_category_row( WP_Term $t ): array {
	return array(
		'id'         => (int) $t->term_id,
		'name'       => fisha_studio_plain( $t->name ),
		'parent'     => (int) $t->parent,
		'slug'       => $t->slug,
		'order'      => (int) get_term_meta( $t->term_id, 'order', true ),
		'state'      => fisha_studio_category_state( $t ),
		'shown'      => '1' === (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ),
		'off_reason' => fisha_studio_category_off_reason( $t ),
		'count'      => fisha_studio_category_count( $t ),
		'url'        => fisha_cat_is_unpublished( $t ) ? null : get_term_link( $t ),
	);
}

function fisha_studio_category_payload( WP_Term $t ): array {
	$id     = (int) $t->term_id;
	$live   = fisha_studio_category_values( $t );
	$draft  = fisha_studio_draft_get( $id, 'term' );
	$values = array_merge( $live, $draft['fields'] );
	$images = array();
	foreach ( (array) $values['hero_ids'] as $aid ) {
		$img = fisha_studio_image_payload( (int) $aid );
		if ( $img ) $images[ (int) $aid ] = $img;
	}
	$new = fisha_cat_is_unpublished( $t );
	return array(
		'id'                 => $id,
		'state'              => fisha_studio_category_state( $t ),
		'values'             => $values,
		'live'               => $live,
		'images'             => (object) $images,
		'url'                => $new ? null : get_term_link( $t ),
		'base_url'           => fisha_studio_category_base_url( (int) $values['parent'] ),
		'product_count'      => fisha_studio_category_count( $t ),
		'displayed'          => fisha_cat_is_displayed( $t ),
		'has_photo_products' => fisha_studio_category_has_photo_products( $t ),
		'direct_products'    => count( fisha_studio_category_direct_products( $id ) ),
		'orphan_products'    => count( fisha_studio_category_orphans( $id ) ),
		'deletable'          => (int) get_option( 'default_product_cat' ) !== $id,
		'previews'           => fisha_studio_category_preview_targets( $t ),
	);
}

function fisha_studio_rest_categories_list(): array {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	fisha_cat_cache_reset();
	return array( 'items' => array_map( 'fisha_studio_category_row', fisha_cat_by_order( is_wp_error( $terms ) ? array() : $terms ) ) );
}

function fisha_studio_rest_categories_create( WP_REST_Request $r ) {
	$b      = fisha_studio_body( $r );
	$name   = trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) );
	$parent = absint( $b['parent'] ?? 0 );
	if ( mb_strlen( $name ) > 60 ) return new WP_Error( 'fisha_studio_invalid', 'A name can be at most 60 characters.', array( 'status' => 422, 'fields' => array( 'name' => 'A name can be at most 60 characters.' ) ) );
	if ( $parent && ! fisha_studio_category( $parent ) ) return new WP_Error( 'fisha_studio_invalid', 'That parent category no longer exists.', array( 'status' => 422, 'fields' => array( 'parent' => 'That parent category no longer exists.' ) ) );
	$t = fisha_studio_category_create( $name, $parent );
	if ( is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_create_failed', 'The category could not be created. Please try again.', array( 'status' => 500 ) );
	fisha_studio_log( 'category', (int) $t->term_id, 'new', null, fisha_studio_category_values( $t ) );
	return new WP_REST_Response( fisha_studio_category_payload( $t ), 201 );
}

function fisha_studio_rest_categories_get( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	return is_wp_error( $t ) ? $t : fisha_studio_category_payload( $t );
}

function fisha_studio_rest_categories_draft( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$b      = fisha_studio_body( $r );
	$schema = fisha_studio_category_fields();
	list( $values, $errors ) = fisha_studio_sanitize( $schema, (array) ( $b['fields'] ?? array() ) );
	$values = array_diff_key( $values, $errors );
	list( $seen, $seen_errors ) = fisha_studio_sanitize( $schema, is_array( $b['seen'] ?? null ) ? $b['seen'] : array() );
	if ( $values ) $errors += fisha_studio_category_edit( $t, $values, array_diff_key( $seen, $seen_errors ) );
	return array_merge( fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) ), array( 'field_errors' => (object) $errors ) );
}

function fisha_studio_rest_categories_discard( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	fisha_studio_category_discard( (int) $t->term_id );
	return fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) );
}

function fisha_studio_rest_categories_publish( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_publish_category( (int) $t->term_id, is_array( $b['resolutions'] ?? null ) ? $b['resolutions'] : array(), ! empty( $b['change_url'] ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_category_payload( fisha_studio_category( (int) $t->term_id ) ), array( 'published_url' => $res['url'] ) );
}

function fisha_studio_rest_categories_shown( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$res = fisha_studio_category_set_shown( (int) $t->term_id, ! empty( fisha_studio_body( $r )['shown'] ) );
	return is_wp_error( $res ) ? $res : fisha_studio_category_row( fisha_studio_category( (int) $t->term_id ) );
}

function fisha_studio_rest_categories_order( WP_REST_Request $r ) {
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_category_reorder( absint( $b['parent'] ?? 0 ), is_array( $b['ids'] ?? null ) ? $b['ids'] : array() );
	return is_wp_error( $res ) ? $res : fisha_studio_rest_categories_list();
}

function fisha_studio_rest_categories_delete( WP_REST_Request $r ) {
	$t = fisha_studio_rest_category( $r );
	if ( is_wp_error( $t ) ) return $t;
	$body = fisha_studio_body( $r );
	$res  = fisha_studio_category_delete( (int) $t->term_id, absint( $body['move_to'] ?? $r['move_to'] ?? 0 ) );
	return is_wp_error( $res ) ? $res : array( 'deleted' => true );
}
