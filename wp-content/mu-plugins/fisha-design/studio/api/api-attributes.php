<?php
/**
 * Fisha Studio REST: the shop-wide options (Size, Shoe size…) and their values. Changes here apply at once, they are
 * not product drafts: add or rename an option, add, rename or reorder its values, and delete a value nothing uses.
 * Slugs never change, because sizes store them.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$perm = fisha_studio_permission( 'manage_product_terms' );
	register_rest_route( FISHA_STUDIO_NS, '/attributes', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_attributes', 'permission_callback' => $perm ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_attribute_add', 'permission_callback' => $perm ),
	) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/(?P<id>\d+)', array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_attribute_rename', 'permission_callback' => $perm ) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/(?P<id>\d+)/terms', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_attribute_term_add', 'permission_callback' => $perm ) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/(?P<id>\d+)/order', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_attribute_order', 'permission_callback' => $perm ) );
	register_rest_route( FISHA_STUDIO_NS, '/attributes/terms/(?P<term>\d+)', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_attribute_term_rename', 'permission_callback' => $perm ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_attribute_term_delete', 'permission_callback' => $perm ),
	) );
} );

function fisha_studio_attribute_tax( int $id ): ?string {
	$a = wc_get_attribute( $id );
	return $a ? (string) $a->slug : null;
}

/** WooCommerce caches its list of options. After a change drop every copy (the autoloaded-options cache can keep a deleted transient alive) so this request sees it. */
function fisha_studio_attributes_refresh(): void {
	delete_transient( 'wc_attribute_taxonomies' );
	wp_cache_delete( 'alloptions', 'options' );
	WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
}

/** A just-created option is not registered until the next request; register it for this one. */
function fisha_studio_ensure_attribute_tax( string $tax ): void {
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, array( 'product', 'product_variation' ), array( 'hierarchical' => false, 'public' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false ) );
	}
}

/** How many sizes use each value (by slug) of an option. */
function fisha_studio_attribute_usage( string $tax ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT pm.meta_value AS slug, COUNT(*) AS n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = %s AND p.post_type = 'product_variation' AND p.post_status IN ('publish','private','draft','pending','future','trash') GROUP BY pm.meta_value",
		'attribute_' . $tax
	), ARRAY_A );
	$out = array();
	foreach ( $rows as $r ) $out[ $r['slug'] ] = (int) $r['n'];
	return $out;
}

function fisha_studio_attribute_payload( int $id ): ?array {
	foreach ( fisha_studio_attributes_catalog() as $a ) {
		if ( $a['id'] !== $id ) continue;
		$usage = fisha_studio_attribute_usage( $a['name'] );
		foreach ( $a['terms'] as &$t ) {
			$term          = get_term( $t['id'], $a['name'] );
			$t['sizes']    = $usage[ $t['slug'] ] ?? 0;
			$t['products'] = ( $term && ! is_wp_error( $term ) ) ? (int) $term->count : 0;
		}
		return $a;
	}
	return null;
}

function fisha_studio_rest_attributes(): array {
	$out = array();
	foreach ( fisha_studio_attributes_catalog() as $a ) $out[] = fisha_studio_attribute_payload( $a['id'] );
	return array_values( array_filter( $out ) );
}

function fisha_studio_attribute_label_input( WP_REST_Request $r, string $key, int $max ) {
	$v = trim( sanitize_text_field( (string) ( fisha_studio_body( $r )[ $key ] ?? '' ) ) );
	if ( '' === $v || mb_strlen( $v ) > $max ) {
		$msg = sprintf( 'Use a name of 1 to %d characters.', $max );
		return new WP_Error( 'fisha_studio_invalid', $msg, array( 'status' => 422, 'fields' => array( $key => $msg ) ) );
	}
	return $v;
}

function fisha_studio_rest_attribute_add( WP_REST_Request $r ) {
	$label = fisha_studio_attribute_label_input( $r, 'label', 28 );
	if ( is_wp_error( $label ) ) return $label;
	$slug = wc_sanitize_taxonomy_name( $label );
	$id   = wc_create_attribute( array( 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
	if ( is_wp_error( $id ) ) return new WP_Error( 'fisha_studio_invalid', $id->get_error_message(), array( 'status' => 422 ) );
	fisha_studio_attributes_refresh();
	fisha_studio_ensure_attribute_tax( wc_attribute_taxonomy_name( $slug ) );
	fisha_studio_log( 'attribute', (int) $id, 'create', null, array( 'label' => $label ) );
	return new WP_REST_Response( fisha_studio_attribute_payload( (int) $id ), 201 );
}

function fisha_studio_rest_attribute_rename( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	$a  = wc_get_attribute( $id );
	if ( ! $a ) return new WP_Error( 'fisha_studio_not_found', 'This option no longer exists.', array( 'status' => 404 ) );
	$label = fisha_studio_attribute_label_input( $r, 'label', 28 );
	if ( is_wp_error( $label ) ) return $label;
	$res = wc_update_attribute( $id, array( 'name' => $label, 'slug' => substr( $a->slug, 3 ), 'type' => $a->type, 'order_by' => $a->order_by, 'has_archives' => $a->has_archives ) );
	if ( is_wp_error( $res ) ) return new WP_Error( 'fisha_studio_invalid', $res->get_error_message(), array( 'status' => 422 ) );
	fisha_studio_attributes_refresh();
	fisha_studio_log( 'attribute', $id, 'rename', array( 'label' => $a->name ), array( 'label' => $label ) );
	do_action( 'litespeed_purge_all' );
	return fisha_studio_attribute_payload( $id );
}

function fisha_studio_rest_attribute_term_add( WP_REST_Request $r ) {
	$tax = fisha_studio_attribute_tax( (int) $r['id'] );
	if ( ! $tax ) return new WP_Error( 'fisha_studio_not_found', 'This option no longer exists.', array( 'status' => 404 ) );
	fisha_studio_ensure_attribute_tax( $tax );
	$name = fisha_studio_attribute_label_input( $r, 'name', 40 );
	if ( is_wp_error( $name ) ) return $name;
	$existing = get_term_by( 'name', $name, $tax );
	if ( $existing ) return array( 'id' => (int) $existing->term_id, 'name' => $existing->name, 'slug' => $existing->slug );
	$t = wp_insert_term( $name, $tax );
	if ( is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_invalid', $t->get_error_message(), array( 'status' => 422 ) );
	update_term_meta( (int) $t['term_id'], 'order', count( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ) ) ) );
	$term = get_term( (int) $t['term_id'], $tax );
	fisha_studio_log( 'attribute', (int) $r['id'], 'add value', null, array( 'value' => $name ) );
	return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug );
}

function fisha_studio_rest_attribute_order( WP_REST_Request $r ) {
	$id  = (int) $r['id'];
	$tax = fisha_studio_attribute_tax( $id );
	if ( ! $tax ) return new WP_Error( 'fisha_studio_not_found', 'This option no longer exists.', array( 'status' => 404 ) );
	$ids = array_values( array_unique( array_map( 'absint', (array) ( fisha_studio_body( $r )['term_ids'] ?? array() ) ) ) );
	foreach ( $ids as $tid ) {
		$t = get_term( $tid, $tax );
		if ( ! $t || is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_invalid', 'The order could not be saved. Please reload the page.', array( 'status' => 422 ) );
	}
	foreach ( $ids as $i => $tid ) update_term_meta( $tid, 'order', $i );
	fisha_studio_log( 'attribute', $id, 'reorder', null, array( 'term_ids' => $ids ) );
	do_action( 'litespeed_purge_all' );
	return fisha_studio_attribute_payload( $id );
}

/** An option value (a term of a pa_ taxonomy), or null. */
function fisha_studio_rest_attribute_term( WP_REST_Request $r ): ?WP_Term {
	$t = get_term( (int) $r['term'] );
	return ( $t instanceof WP_Term && 0 === strpos( $t->taxonomy, 'pa_' ) ) ? $t : null;
}

function fisha_studio_rest_attribute_term_rename( WP_REST_Request $r ) {
	$t = fisha_studio_rest_attribute_term( $r );
	if ( ! $t ) return new WP_Error( 'fisha_studio_not_found', 'This value no longer exists.', array( 'status' => 404 ) );
	$name = fisha_studio_attribute_label_input( $r, 'name', 40 );
	if ( is_wp_error( $name ) ) return $name;
	$res = wp_update_term( $t->term_id, $t->taxonomy, array( 'name' => $name ) );
	if ( is_wp_error( $res ) ) return new WP_Error( 'fisha_studio_invalid', $res->get_error_message(), array( 'status' => 422 ) );
	fisha_studio_log( 'attribute', $t->term_id, 'rename value', array( 'name' => $t->name ), array( 'name' => $name ) );
	do_action( 'litespeed_purge_all' );
	$fresh = get_term( $t->term_id, $t->taxonomy );
	return array( 'id' => (int) $fresh->term_id, 'name' => $fresh->name, 'slug' => $fresh->slug );
}

function fisha_studio_rest_attribute_term_delete( WP_REST_Request $r ) {
	$t = fisha_studio_rest_attribute_term( $r );
	if ( ! $t ) return new WP_Error( 'fisha_studio_not_found', 'This value no longer exists.', array( 'status' => 404 ) );
	$product_ids = array_map( 'intval', get_objects_in_term( $t->term_id, $t->taxonomy ) );
	foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), 'numberposts' => -1, 'fields' => 'id=>parent', 'meta_key' => 'attribute_' . $t->taxonomy, 'meta_value' => $t->slug ) ) as $parent ) {
		$product_ids[] = (int) $parent;
	}
	$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
	if ( $product_ids ) {
		$names = array();
		foreach ( array_slice( $product_ids, 0, 10 ) as $pid ) $names[] = get_the_title( $pid );
		return new WP_Error( 'fisha_studio_in_use', sprintf( '%s is used by %s. Remove it from those products first.', $t->name, implode( ', ', $names ) ), array( 'status' => 409, 'products' => $names ) );
	}
	wp_delete_term( $t->term_id, $t->taxonomy );
	fisha_studio_log( 'attribute', $t->term_id, 'delete value', array( 'name' => $t->name ), null );
	return array( 'deleted' => true );
}
