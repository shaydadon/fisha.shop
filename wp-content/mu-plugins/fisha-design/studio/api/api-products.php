<?php
/**
 * Fisha Studio REST: products. List with filters, create (as a hidden draft), read, autosave a draft, discard,
 * publish, quick price/stock edit from the list, and move to trash.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$id = '/products/(?P<id>\d+)';
	register_rest_route( FISHA_STUDIO_NS, '/products', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_products_list', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_create', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id, array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_products_get', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/draft', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_products_draft', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_products_discard', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/publish', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_publish', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/quick', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_quick', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/trash', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_trash', 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
} );

/** @return WC_Product|WP_Error */
function fisha_studio_rest_product( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	$p  = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
	return $p ?: new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
}

function fisha_studio_product_state( WC_Product $p ): string {
	if ( fisha_studio_is_new( $p ) ) return 'new';
	return fisha_studio_draft_get( $p->get_id() )['fields'] ? 'changed' : 'live';
}

function fisha_studio_product_payload( WC_Product $p ): array {
	$id     = $p->get_id();
	$live   = fisha_studio_product_read( $p );
	$draft  = fisha_studio_draft_get( $id );
	$is_new = fisha_studio_is_new( $p );
	if ( $is_new ) $live['status'] = fisha_studio_target_status( $p );
	$values = array_merge( $live, $draft['fields'] );
	$images = array();
	foreach ( array_merge( array( (int) $values['image_id'] ), $values['gallery_image_ids'] ) as $aid ) {
		$img = fisha_studio_image_payload( (int) $aid );
		if ( $img ) $images[ $aid ] = $img;
	}
	return array(
		'id'           => $id,
		'type'         => $p->get_type(),
		'editable'     => $p->is_type( 'simple' ),
		'state'        => fisha_studio_product_state( $p ),
		'values'       => $values,
		'live'         => $live,
		'changed'      => array_keys( $draft['fields'] ),
		'url'          => $is_new ? '' : get_permalink( $id ),
		'product_base' => trailingslashit( home_url( wc_get_permalink_structure()['product_rewrite_slug'] ) ),
		'previews'     => fisha_studio_preview_targets( $p, $values['category_ids'] ),
		'images'       => (object) $images,
		'conflicts'    => $is_new ? array() : fisha_studio_conflicts( $p, $draft ),
	);
}

function fisha_studio_product_row( WC_Product $p ): array {
	$qty   = $p->get_manage_stock() ? $p->get_stock_quantity() : null;
	$alert = '' !== (string) $p->get_low_stock_amount() ? (int) $p->get_low_stock_amount() : 2;
	$names = array();
	foreach ( $p->get_category_ids() as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) $names[] = $t->name;
	}
	return array(
		'id'             => $p->get_id(),
		'name'           => $p->get_name( 'edit' ),
		'sku'            => $p->get_sku( 'edit' ),
		'type'           => $p->get_type(),
		'editable'       => $p->is_type( 'simple' ),
		'state'          => fisha_studio_product_state( $p ),
		'hidden'         => 'private' === get_post_status( $p->get_id() ),
		'regular_price'  => (string) $p->get_regular_price( 'edit' ),
		'sale_price'     => (string) $p->get_sale_price( 'edit' ),
		'price_text'     => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
		'manage_stock'   => (bool) $p->get_manage_stock(),
		'stock_quantity' => $qty,
		'stock_status'   => $p->get_stock_status( 'edit' ),
		'low'            => null !== $qty && $qty > 0 && $qty <= $alert,
		'thumb'          => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
		'categories'     => $names,
	);
}

function fisha_studio_rest_products_list( WP_REST_Request $r ): array {
	$args   = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
	$search = trim( sanitize_text_field( (string) $r['search'] ) );
	if ( '' !== $search ) $args['s'] = $search;
	if ( $r['category'] ) $args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'terms' => (int) $r['category'], 'include_children' => true ) );
	$ids = ( new WP_Query( $args ) )->posts;
	if ( '' !== $search && ( $by_sku = wc_get_product_id_by_sku( $search ) ) && ! in_array( $by_sku, $ids, true ) ) array_unshift( $ids, $by_sku );

	$state = (string) ( $r['state'] ?: 'all' );
	$stock = (string) ( $r['stock'] ?: 'all' );
	$rows  = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p ) continue;
		$row = fisha_studio_product_row( $p );
		if ( 'hidden' === $state && ! $row['hidden'] ) continue;
		if ( in_array( $state, array( 'live', 'changed', 'new' ), true ) && $row['state'] !== $state ) continue;
		if ( 'low' === $stock && ! $row['low'] ) continue;
		if ( in_array( $stock, array( 'instock', 'outofstock' ), true ) && $row['stock_status'] !== $stock ) continue;
		$rows[] = $row;
	}
	$per  = min( 100, max( 1, (int) ( $r['per_page'] ?: 50 ) ) );
	$page = max( 1, (int) ( $r['page'] ?: 1 ) );
	return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'pages' => (int) ceil( count( $rows ) / $per ) );
}

function fisha_studio_rest_products_create( WP_REST_Request $r ) {
	$name = trim( sanitize_text_field( (string) ( fisha_studio_body( $r )['name'] ?? '' ) ) );
	if ( '' === $name || mb_strlen( $name ) > 120 ) {
		return new WP_Error( 'fisha_studio_invalid', 'Give the product a name (up to 120 characters).', array( 'status' => 422, 'fields' => array( 'name' => 'Give the product a name (up to 120 characters).' ) ) );
	}
	$p = fisha_studio_product_create( $name );
	fisha_studio_log( 'product', $p->get_id(), 'new', null, array( 'name' => $name ) );
	return new WP_REST_Response( fisha_studio_product_payload( $p ), 201 );
}

function fisha_studio_rest_products_get( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	return is_wp_error( $p ) ? $p : fisha_studio_product_payload( $p );
}

function fisha_studio_rest_products_draft( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! $p->is_type( 'simple' ) ) return new WP_Error( 'fisha_studio_unsupported', 'Products with sizes or options can be edited in the Studio soon.', array( 'status' => 400 ) );
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), (array) ( fisha_studio_body( $r )['fields'] ?? array() ) );
	$values = array_diff_key( $values, $errors );
	if ( $values ) fisha_studio_product_edit( $p, $values );
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'field_errors' => (object) $errors ) );
}

function fisha_studio_rest_products_discard( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	fisha_studio_draft_discard( $p->get_id() );
	return fisha_studio_product_payload( wc_get_product( $p->get_id() ) );
}

function fisha_studio_rest_products_publish( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_publish_product( $p->get_id(), array_map( 'strval', (array) ( $b['resolutions'] ?? array() ) ), ! empty( $b['change_url'] ) );
	if ( is_wp_error( $res ) ) return $res;
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'published_url' => $res['url'] ) );
}

function fisha_studio_rest_products_quick( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! $p->is_type( 'simple' ) || fisha_studio_is_new( $p ) ) return new WP_Error( 'fisha_studio_unsupported', 'Open this product to edit it.', array( 'status' => 400 ) );
	if ( fisha_studio_draft_get( $p->get_id() )['fields'] ) {
		return new WP_Error( 'fisha_studio_has_draft', 'This product has unpublished changes. Open it to review and publish them together.', array( 'status' => 409 ) );
	}
	$input = array_intersect_key( (array) ( fisha_studio_body( $r )['fields'] ?? array() ), array_flip( array( 'regular_price', 'stock_quantity', 'stock_status' ) ) );
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), $input );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', reset( $errors ), array( 'status' => 422, 'fields' => $errors ) );
	fisha_studio_draft_save( $p, $values );
	$res = fisha_studio_publish_product( $p->get_id() );
	if ( is_wp_error( $res ) ) {
		fisha_studio_draft_discard( $p->get_id() );
		if ( 'fisha_studio_nothing' !== $res->get_error_code() ) {
			$fields = $res->get_error_data()['fields'] ?? array();
			return $fields ? new WP_Error( $res->get_error_code(), reset( $fields ), $res->get_error_data() ) : $res;
		}
	}
	return fisha_studio_product_row( wc_get_product( $p->get_id() ) );
}

function fisha_studio_rest_products_trash( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! current_user_can( 'delete_post', $p->get_id() ) ) return new WP_Error( 'fisha_studio_forbidden', 'You do not have access to this.', array( 'status' => 403 ) );
	$res = fisha_studio_trash_product( $p->get_id() );
	return is_wp_error( $res ) ? $res : array( 'trashed' => true );
}
