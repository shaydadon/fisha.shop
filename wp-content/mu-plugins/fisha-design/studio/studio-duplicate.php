<?php
/**
 * Fisha Studio duplicate: a copy of a product (WooCommerce's own duplicator: sizes, options, photos, prices, stock
 * settings) or of a category, as a never-published draft named "… (copy)". The copy shows what the owner sees on screen:
 * the source's live values plus its unpublished draft.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Studio bookkeeping that must never travel to a copy. */
function fisha_studio_duplicate_skip_meta( array $exclude ): array {
	return array_merge( $exclude, array( FISHA_STUDIO_DRAFT_META, FISHA_STUDIO_PUBLISHED_META, FISHA_STUDIO_PENDING_META, FISHA_STUDIO_CREATED_META, '_fisha_studio_target_status', '_fisha_studio_slug_manual', '_fisha_studio_bg_manual' ) );
}

/** @return WC_Product|WP_Error */
function fisha_studio_duplicate_product( int $id ) {
	$src = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
	if ( ! $src ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	if ( ! $src->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product cannot be copied here.', array( 'status' => 400 ) );
	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';

	$draft = fisha_studio_draft_get( $id )['fields'];
	add_filter( 'woocommerce_duplicate_product_exclude_meta', 'fisha_studio_duplicate_skip_meta' );
	try {
		$copy = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $src );
	} finally {
		remove_filter( 'woocommerce_duplicate_product_exclude_meta', 'fisha_studio_duplicate_skip_meta' );
	}
	if ( ! $copy || ! $copy->get_id() ) return new WP_Error( 'fisha_studio_duplicate_failed', 'The copy could not be made. Please try again.', array( 'status' => 500 ) );
	$cid = $copy->get_id();

	// Old size id → new size id, matched by their option values.
	$map = array();
	$new = array();
	foreach ( fisha_studio_variation_ids( $copy ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		$new[ fisha_studio_combo_key( $v->get_attributes( 'edit' ) ) ] = $vid;
		$v->set_sku( '' );
		$v->save();
	}
	foreach ( fisha_studio_variation_ids( $src ) as $vid ) {
		$v = wc_get_product( $vid );
		$k = $v ? fisha_studio_combo_key( $v->get_attributes( 'edit' ) ) : '';
		if ( isset( $new[ $k ] ) ) $map[ $vid ] = $new[ $k ];
	}

	$name = fisha_studio_plain( (string) ( $draft['name'] ?? $src->get_name( 'edit' ) ) ) . ' (copy)';
	$copy = wc_get_product( $cid );
	$copy->set_name( $name );
	$copy->set_slug( fisha_studio_unique_product_slug( fisha_studio_slug_from_name( $name ), $cid ) );
	$copy->set_sku( '' );
	$copy->set_featured( false );
	$copy->update_meta_data( FISHA_STUDIO_CREATED_META, '1' );
	// Show on site as the owner sees it on the source: its unpublished switch, else its target (never published) or live status.
	$shown = $draft['status'] ?? ( fisha_studio_is_new( $src ) ? fisha_studio_target_status( $src ) : $src->get_status( 'edit' ) );
	$copy->update_meta_data( '_fisha_studio_target_status', 'private' === $shown ? 'private' : 'publish' );
	$copy->save();

	// The source's unpublished edits go onto the copy (a never-published product takes them directly).
	$values = array();
	foreach ( $draft as $k => $v ) {
		$k = (string) $k;
		if ( in_array( $k, array( 'name', 'slug', 'sku', 'status', 'featured' ), true ) ) continue;
		$parsed = fisha_studio_parse_var_key( $k );
		if ( $parsed ) {
			if ( ! isset( $map[ $parsed[0] ] ) || 'sku' === $parsed[1] ) continue;
			$k = fisha_studio_var_key( $map[ $parsed[0] ], $parsed[1] );
		}
		$values[ $k ] = $v;
	}
	if ( $values ) fisha_studio_product_edit( wc_get_product( $cid ), $values );
	fisha_studio_log( 'product', $cid, 'duplicate', null, array( 'source' => $id ) );
	return wc_get_product( $cid );
}

/** @return WP_Term|WP_Error */
function fisha_studio_duplicate_category( int $id ) {
	$src = fisha_studio_category( $id );
	if ( ! $src ) return fisha_studio_category_missing();
	$v    = array_merge( fisha_studio_category_values( $src ), fisha_studio_draft_get( $id, 'term' )['fields'] );
	$base = mb_substr( (string) $v['name'], 0, 53 );
	$copy = fisha_studio_category_create( $base . ' (copy)', (int) $v['parent'] );
	if ( is_wp_error( $copy ) ) return $copy;
	fisha_studio_category_edit( $copy, array( 'lead' => (string) $v['lead'], 'hero_ids' => (array) $v['hero_ids'], 'shown' => (bool) $v['shown'] ) );
	fisha_studio_log( 'category', (int) $copy->term_id, 'duplicate', null, array( 'source' => $id ) );
	return fisha_studio_category( (int) $copy->term_id );
}
