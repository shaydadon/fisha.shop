<?php
/**
 * Fisha Studio publish: validate the whole product, resolve conflicts, snapshot the live state into the audit log,
 * then apply the draft through WooCommerce inside one database transaction (all or nothing) and purge caches.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The owner's answer to one conflict: 'mine', 'live' or '' (not answered). An answer is either the old string form or
 * { choice: 'mine'|'live', live: <the site value shown> }; 'mine' with a shown value only counts while the site still
 * has that value, so an order that lands after the owner chose asks again.
 */
function fisha_studio_resolution( array $conflict, array $resolutions ): string {
	$r = $resolutions[ $conflict['field'] ] ?? '';
	if ( is_array( $r ) ) {
		$choice = (string) ( $r['choice'] ?? '' );
		if ( 'mine' === $choice && array_key_exists( 'live', $r ) && ! fisha_studio_same( $r['live'], $conflict['live'] ) ) return '';
		$r = $choice;
	}
	return in_array( $r, array( 'mine', 'live' ), true ) ? $r : '';
}

/** The conflicts the owner has not answered (for the value now on the site). */
function fisha_studio_open_conflicts( array $conflicts, array $resolutions ): array {
	return array_values( array_filter( $conflicts, function ( $c ) use ( $resolutions ) { return '' === fisha_studio_resolution( $c, $resolutions ); } ) );
}

/** @return array{id:int,url:string}|WP_Error */
function fisha_studio_publish_product( int $id, array $resolutions = array(), bool $change_url = false ) {
	$p = wc_get_product( $id );
	if ( ! $p || 'trash' === get_post_status( $id ) ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
	if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product can only be edited in WP admin. Nothing was changed.', array( 'status' => 400 ) );

	$is_new  = fisha_studio_is_new( $p );
	$draft   = fisha_studio_draft_get( $id );
	$before  = fisha_studio_product_values( $p );
	$changes = $draft['fields'];

	if ( ! $is_new ) {
		$conflicts = fisha_studio_conflicts( $p, $draft );
		$open      = fisha_studio_open_conflicts( $conflicts, $resolutions );
		if ( $open ) return new WP_Error( 'fisha_studio_conflict', 'Some details changed on the site while you were editing.', array( 'status' => 409, 'conflicts' => $open ) );
		foreach ( $conflicts as $c ) {
			if ( 'live' === fisha_studio_resolution( $c, $resolutions ) ) unset( $changes[ $c['field'] ] );
		}
		if ( isset( $changes['slug'] ) && $changes['slug'] !== $before['slug'] && ! $change_url ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'slug' => 'This product is live. Use "Change address" to change its URL.' ) ) );
		}
		if ( ! $changes ) return new WP_Error( 'fisha_studio_nothing', 'There are no changes to publish.', array( 'status' => 400 ) );
	} else {
		$changes['status'] = fisha_studio_target_status( $p );
	}

	// "Fixed amount" never sells beyond the count: whatever this publish leaves counted also stops backorders (logged with the rest).
	$changes = array_merge( $changes, fisha_studio_backorder_fixes( array_merge( $before, $changes ), $p ) );
	$after   = array_merge( $before, $changes );
	$errors  = fisha_studio_product_validate( $after, $p );
	if ( $p->is_type( 'variable' ) ) $errors += fisha_studio_variable_validate( $after, $p );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) );

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		if ( ! $is_new ) {
			$stock_error = fisha_studio_lock_and_recheck_stock( $p, $draft, array_keys( $changes ), $resolutions );
			if ( $stock_error ) {
				$wpdb->query( 'ROLLBACK' );
				return $stock_error;
			}
		}
		fisha_studio_product_apply_all( $p, $changes );
		if ( $is_new ) {
			$p->set_slug( fisha_studio_unique_product_slug( $after['slug'] ?: fisha_studio_slug_from_name( $after['name'] ), $id ) );
			$p->set_menu_order( fisha_studio_first_menu_order( $after['category_ids'] ) );
			$p->delete_meta_data( '_fisha_studio_target_status' );
			$p->delete_meta_data( '_fisha_studio_slug_manual' );
			$p->delete_meta_data( '_fisha_studio_bg_manual' );
		}
		$p->delete_meta_data( FISHA_STUDIO_DRAFT_META );
		$p->save();
		update_post_meta( $id, FISHA_STUDIO_PUBLISHED_META, '1' );
		if ( $p->is_type( 'variable' ) ) {
			// Only a size that goes live stops being pending; one left off stays pending, so Discard or Delete can still remove it.
			foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
				if ( ! empty( $after[ fisha_studio_var_key( $vid, 'enabled' ) ] ) ) delete_post_meta( $vid, FISHA_STUDIO_PENDING_META );
			}
			WC_Product_Variable::sync( $id );
		}
		fisha_studio_default_alts( $p );
		do_action( 'fisha_studio_before_commit', $id );
		$fresh = wc_get_product( $id );
		if ( ! fisha_studio_log( 'product', $id, $is_new ? 'create' : 'publish', $is_new ? null : $before, fisha_studio_product_values( $fresh ) ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_post_cache( $id );
		foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
			clean_post_cache( $vid );
			if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $vid );
		}
		clean_object_term_cache( $id, 'product' );
		wc_delete_product_transients( $id );
		if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		fisha_studio_log_error( 'publish product ' . $id, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Publishing failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}

	fisha_studio_purge_product( wc_get_product( $id ), $before['category_ids'] );
	return array( 'id' => $id, 'url' => get_permalink( $id ) );
}

/** backorders 'no' for the product and each size that counts stock ($after = live values merged with the changes) but still allows backorders. */
function fisha_studio_backorder_fixes( array $after, WC_Product $p ): array {
	$out = array();
	if ( ! empty( $after['manage_stock'] ) && 'no' !== ( $after['backorders'] ?? 'no' ) ) $out['backorders'] = 'no';
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$k = fisha_studio_var_key( $vid, 'backorders' );
		if ( ! empty( $after[ fisha_studio_var_key( $vid, 'manage_stock' ) ] ) && 'no' !== ( $after[ $k ] ?? 'no' ) ) $out[ $k ] = 'no';
	}
	return $out;
}

/**
 * Inside the publish transaction, before any write: locks the stock rows of the product and the sizes whose stock is
 * written ($keys = the keys being published), so an order placed now waits, then checks for conflicts again on fresh
 * values. A conflict the owner has not answered yet ($resolutions) means the caller rolls back and asks.
 */
function fisha_studio_lock_and_recheck_stock( WC_Product $p, array $draft, array $keys, array $resolutions ): ?WP_Error {
	global $wpdb;
	$id    = $p->get_id();
	$stock = array( 'stock_quantity', 'manage_stock' );
	$ids   = array();
	foreach ( $keys as $k ) {
		$parsed = fisha_studio_parse_var_key( (string) $k );
		if ( $parsed && in_array( $parsed[1], $stock, true ) ) $ids[ $parsed[0] ] = true;
		elseif ( in_array( (string) $k, $stock, true ) ) $ids[ $id ] = true;
	}
	if ( ! $ids ) return null;
	$in = implode( ',', array_map( 'intval', array_keys( $ids ) ) );
	$wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key = '_stock' FOR UPDATE" );
	do_action( 'fisha_studio_before_stock_recheck', $id );
	foreach ( array_unique( array_merge( array( $id ), array_keys( $ids ) ) ) as $x ) {
		clean_post_cache( $x );
		wc_delete_product_transients( $x );
		if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $x );
	}
	$open = fisha_studio_open_conflicts( fisha_studio_conflicts( wc_get_product( $id ), $draft ), $resolutions );
	return $open ? new WP_Error( 'fisha_studio_conflict', 'Some details changed on the site while you were editing.', array( 'status' => 409, 'conflicts' => $open ) ) : null;
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
	// A top-level category with no other product may have just appeared or disappeared from the menus on every page.
	if ( function_exists( 'fisha_cat_is_enabled' ) ) {
		fisha_cat_cache_reset();
		$tops = array();
		foreach ( array_unique( array_merge( $p->get_category_ids(), $extra_cat_ids ) ) as $cid ) {
			$chain = array_merge( array( (int) $cid ), array_map( 'intval', get_ancestors( (int) $cid, 'product_cat' ) ) );
			$tops[ end( $chain ) ] = true;
		}
		foreach ( array_keys( $tops ) as $tid ) {
			$top = get_term( (int) $tid, 'product_cat' );
			if ( ! $top instanceof WP_Term || ! fisha_cat_is_enabled( $top ) ) continue;
			if ( count( wc_get_products( array( 'status' => 'publish', 'limit' => 2, 'return' => 'ids', 'visibility' => 'catalog', 'category' => array( $top->slug ) ) ) ) < 2 ) {
				do_action( 'litespeed_purge_all' );
				break;
			}
		}
	}
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
