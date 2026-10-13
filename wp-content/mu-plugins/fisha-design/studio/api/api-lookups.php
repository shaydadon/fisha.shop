<?php
/**
 * Fisha Studio REST: the lists the editor's pickers need (categories, tags, design series, card colours,
 * shipping and tax classes) and adding a tag.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/lookups', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_lookups', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/tags', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_add_tag', 'permission_callback' => fisha_studio_permission( 'manage_product_terms' ) ) );
} );

function fisha_studio_rest_lookups(): array {
	global $wpdb;
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	$terms = is_wp_error( $terms ) ? array() : $terms;
	$by_id = array();
	foreach ( $terms as $t ) $by_id[ $t->term_id ] = $t;
	$path = function ( WP_Term $t ) use ( $by_id ): string {
		$names = array( fisha_studio_plain( $t->name ) );
		while ( $t->parent && isset( $by_id[ $t->parent ] ) ) {
			$t = $by_id[ $t->parent ];
			array_unshift( $names, fisha_studio_plain( $t->name ) );
		}
		return implode( ' › ', $names );
	};
	$categories = array();
	foreach ( $terms as $t ) $categories[] = array( 'id' => $t->term_id, 'name' => fisha_studio_plain( $t->name ), 'parent' => $t->parent, 'slug' => $t->slug, 'path' => $path( $t ) );
	usort( $categories, function ( $a, $b ) { return strcasecmp( $a['path'], $b['path'] ); } );

	$tag_terms = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
	$tags      = array();
	foreach ( is_wp_error( $tag_terms ) ? array() : $tag_terms as $t ) $tags[] = array( 'id' => $t->term_id, 'name' => fisha_studio_plain( $t->name ) );

	$ship_terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
	$shipping   = array();
	foreach ( is_wp_error( $ship_terms ) ? array() : $ship_terms as $t ) $shipping[] = array( 'id' => $t->term_id, 'name' => fisha_studio_plain( $t->name ) );

	$tax = array( array( 'slug' => '', 'name' => 'Standard' ) );
	foreach ( array_combine( WC_Tax::get_tax_class_slugs(), WC_Tax::get_tax_classes() ) ?: array() as $slug => $name ) $tax[] = array( 'slug' => $slug, 'name' => $name );

	$palette = array();
	foreach ( $wpdb->get_results( "SELECT LOWER(meta_value) AS color, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = '_fisha_card_bg' AND meta_value <> '' GROUP BY LOWER(meta_value) ORDER BY n DESC LIMIT 16", ARRAY_A ) as $row ) {
		if ( sanitize_hex_color( $row['color'] ) ) $palette[] = array( 'color' => $row['color'], 'count' => (int) $row['n'] );
	}

	return array(
		'categories'       => $categories,
		'tags'             => $tags,
		'series'           => $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fisha_series' AND meta_value <> '' ORDER BY meta_value" ),
		'palette'          => $palette,
		'shipping_classes' => $shipping,
		'tax_classes'      => $tax,
		'attributes'       => fisha_studio_attributes_catalog(),
		'product_base'     => trailingslashit( home_url( wc_get_permalink_structure()['product_rewrite_slug'] ) ),
		'currency'         => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
	);
}

function fisha_studio_rest_add_tag( WP_REST_Request $r ) {
	$name = trim( sanitize_text_field( (string) ( fisha_studio_body( $r )['name'] ?? '' ) ) );
	if ( '' === $name || mb_strlen( $name ) > 40 ) {
		return new WP_Error( 'fisha_studio_invalid', 'A tag needs a name of up to 40 characters.', array( 'status' => 422, 'fields' => array( 'name' => 'A tag needs a name of up to 40 characters.' ) ) );
	}
	$existing = get_term_by( 'name', $name, 'product_tag' );
	if ( $existing ) return array( 'id' => $existing->term_id, 'name' => $existing->name );
	$t = wp_insert_term( $name, 'product_tag' );
	if ( is_wp_error( $t ) ) return new WP_Error( 'fisha_studio_invalid', $t->get_error_message(), array( 'status' => 422 ) );
	return array( 'id' => (int) $t['term_id'], 'name' => $name );
}
