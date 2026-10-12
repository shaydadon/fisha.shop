<?php
/**
 * Fisha Studio publish: validate the whole product, resolve conflicts, snapshot the live state into the audit log,
 * then apply the draft through WooCommerce inside one database transaction (all or nothing) and purge caches.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** @return array{id:int,url:string}|WP_Error */
function fisha_studio_publish_product( int $id, array $resolutions = array(), bool $change_url = false ) {
	$p = wc_get_product( $id );
	if ( ! $p || 'trash' === get_post_status( $id ) ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	if ( ! $p->is_type( 'simple' ) ) return new WP_Error( 'fisha_studio_unsupported', 'Products with sizes or options can be published from the Studio soon. Nothing was changed.', array( 'status' => 400 ) );

	$is_new  = fisha_studio_is_new( $p );
	$draft   = fisha_studio_draft_get( $id );
	$before  = fisha_studio_product_read( $p );
	$changes = $draft['fields'];

	if ( ! $is_new ) {
		$conflicts = fisha_studio_conflicts( $p, $draft );
		$open      = array_values( array_filter( $conflicts, function ( $c ) use ( $resolutions ) {
			return ! in_array( $resolutions[ $c['field'] ] ?? '', array( 'mine', 'live' ), true );
		} ) );
		if ( $open ) return new WP_Error( 'fisha_studio_conflict', 'Some details changed on the site while you were editing.', array( 'status' => 409, 'conflicts' => $open ) );
		foreach ( $conflicts as $c ) {
			if ( 'live' === $resolutions[ $c['field'] ] ) unset( $changes[ $c['field'] ] );
		}
		if ( isset( $changes['slug'] ) && $changes['slug'] !== $before['slug'] && ! $change_url ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'slug' => 'This product is live. Use "Change address" to change its URL.' ) ) );
		}
		if ( ! $changes ) return new WP_Error( 'fisha_studio_nothing', 'There are no changes to publish.', array( 'status' => 400 ) );
	} else {
		$changes['status'] = fisha_studio_target_status( $p );
	}

	$after  = array_merge( $before, $changes );
	$errors = fisha_studio_product_validate( $after, $p );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) );

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		fisha_studio_product_apply( $p, $changes );
		if ( $is_new ) {
			$p->set_slug( fisha_studio_unique_product_slug( $after['slug'] ?: fisha_studio_slug_from_name( $after['name'] ), $id ) );
			$p->set_menu_order( fisha_studio_first_menu_order( $after['category_ids'] ) );
			$p->delete_meta_data( '_fisha_studio_target_status' );
			$p->delete_meta_data( '_fisha_studio_slug_manual' );
			$p->delete_meta_data( '_fisha_studio_bg_manual' );
		}
		$p->delete_meta_data( FISHA_STUDIO_DRAFT_META );
		$p->save();
		fisha_studio_default_alts( $p );
		do_action( 'fisha_studio_before_commit', $id );
		$fresh = wc_get_product( $id );
		if ( ! fisha_studio_log( 'product', $id, $is_new ? 'create' : 'publish', $is_new ? null : $before, fisha_studio_product_read( $fresh ) ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		$wpdb->query( 'COMMIT' );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_post_cache( $id );
		clean_object_term_cache( $id, 'product' );
		wc_delete_product_transients( $id );
		if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		fisha_studio_log_error( 'publish product ' . $id, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Publishing failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}

	fisha_studio_purge_product( wc_get_product( $id ), $before['category_ids'] );
	return array( 'id' => $id, 'url' => get_permalink( $id ) );
}

function fisha_studio_first_menu_order( array $cat_ids ): int {
	global $wpdb;
	$ids = implode( ',', array_filter( array_map( 'absint', $cat_ids ) ) );
	if ( ! $ids ) return 0;
	$min = $wpdb->get_var( "SELECT MIN(p.menu_order) FROM {$wpdb->posts} p
		JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
		JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
		WHERE p.post_type = 'product' AND p.post_status IN ('publish','private') AND t.taxonomy = 'product_cat' AND t.term_id IN ($ids)" );
	return null === $min ? 0 : (int) $min - 1;
}

/** Images without alt text get "{name} by Fisha". */
function fisha_studio_default_alts( WC_Product $p ): void {
	foreach ( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) as $aid ) {
		if ( $aid && '' === trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $aid, '_wp_attachment_image_alt', $p->get_name() . ' by Fisha' );
		}
	}
}

function fisha_studio_purge_product( WC_Product $p, array $extra_cat_ids = array() ): void {
	$urls = array( get_permalink( $p->get_id() ), get_permalink( wc_get_page_id( 'shop' ) ), home_url( '/' ) );
	foreach ( array_unique( array_merge( $p->get_category_ids(), $extra_cat_ids ) ) as $cid ) {
		foreach ( array_merge( array( $cid ), get_ancestors( $cid, 'product_cat' ) ) as $tid ) {
			$link = get_term_link( (int) $tid, 'product_cat' );
			if ( ! is_wp_error( $link ) ) $urls[] = $link;
		}
	}
	foreach ( array_unique( array_filter( $urls ) ) as $url ) do_action( 'litespeed_purge_url', $url );
	do_action( 'litespeed_purge_post', $p->get_id() );
	wc_delete_product_transients( $p->get_id() );
}

/** @return true|WP_Error */
function fisha_studio_trash_product( int $id ) {
	$p = wc_get_product( $id );
	if ( ! $p ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	$before = fisha_studio_product_read( $p );
	if ( ! wp_trash_post( $id ) ) return new WP_Error( 'fisha_studio_trash_failed', 'The product could not be moved to the trash.', array( 'status' => 500 ) );
	fisha_studio_log( 'product', $id, 'trash', $before, null );
	fisha_studio_purge_product( $p );
	return true;
}
