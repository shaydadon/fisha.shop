<?php
/**
 * Task 13 D: no stale "pending" flag on a size that went live outside the Studio, Discard never deletes a sold size,
 * and "Use mine" only counts for the live value the owner saw.
 */

fs_test( 'safety 2: a size goes live with its product outside the Studio and is no longer pending', function () {
	$p  = fs_studio_published_variable();
	$id = $p->get_id();
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
	$attrs = fisha_studio_product_values( wc_get_product( $id ) )['attributes'];
	$attrs[0]['options'][] = fs_sz( 'l' );
	fisha_studio_product_edit( wc_get_product( $id ), array( 'attributes' => $attrs ) );
	$v = fisha_studio_variation_create( wc_get_product( $id ), array( 'pa_size' => 'l' ) );
	fs_eq( 'publish', get_post_status( $v->get_id() ), 'a product back in Draft takes the size at once' );
	fs_eq( '1', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); // WP admin, not the Studio
	fs_eq( '', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ), 'flag gone' );
	fisha_studio_draft_discard( $id );
	fs_true( null !== get_post( $v->get_id() ), 'Discard keeps it' );
} );

fs_test( 'safety 2: a pending size switched on while its product is live stops being pending', function () {
	$p = fs_variable( array( 's' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'm' ) );
	fs_eq( 'private', get_post_status( $v->get_id() ) );
	wp_update_post( array( 'ID' => $v->get_id(), 'post_status' => 'publish' ) );
	fs_eq( '', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
} );

fs_test( 'safety 2: Discard does not delete a pending size that has an order', function () {
	$p = fs_variable( array( 's' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'm' ) );
	fs_order_row( $p->get_id(), $v->get_id() );
	fisha_studio_draft_discard( $p->get_id() );
	fs_true( null !== get_post( $v->get_id() ), 'sold size kept' );
	$w = fisha_studio_variation_create( wc_get_product( $p->get_id() ), array( 'pa_size' => 'l' ) );
	fisha_studio_draft_discard( $p->get_id() );
	fs_eq( null, get_post( $w->get_id() ), 'an unsold pending size is still removed' );
} );

fs_test( 'safety 2: "Use mine" only counts for the live value the owner saw', function () {
	$p = fs_complete_product( array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 7 ), array( 'stock_quantity' => 5 ) );
	wc_update_product_stock( $p->get_id(), 2, 'set' );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 2, is_wp_error( $r ) ? $r->get_error_data()['conflicts'][0]['live'] : 'published' );
	// The owner saw 2 and chose theirs; an order lands before the locked recheck.
	$hook = function () use ( $p ) { update_post_meta( $p->get_id(), '_stock', 1 ); };
	add_action( 'fisha_studio_before_stock_recheck', $hook );
	fs_on_cleanup( function () use ( $hook ) { remove_action( 'fisha_studio_before_stock_recheck', $hook ); } );
	$r = fisha_studio_publish_product( $p->get_id(), array( 'stock_quantity' => array( 'choice' => 'mine', 'live' => 2 ) ) );
	fs_eq( 'fisha_studio_conflict', is_wp_error( $r ) ? $r->get_error_code() : 'published' );
	fs_eq( 409, $r->get_error_data()['status'] );
	fs_eq( 1, $r->get_error_data()['conflicts'][0]['live'] );
	remove_action( 'fisha_studio_before_stock_recheck', $hook );
	clean_post_cache( $p->get_id() );
	fs_true( 7 !== (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'nothing written' );
	// Seen 1 now, chosen again over REST: it publishes.
	update_post_meta( $p->get_id(), '_stock', 1 );
	clean_post_cache( $p->get_id() );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish', array( 'resolutions' => array( 'stock_quantity' => array( 'choice' => 'mine', 'live' => 2 ) ) ) );
	fs_eq( 409, $st, 'stale live over REST' );
	list( $st, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish', array( 'resolutions' => array( 'stock_quantity' => array( 'choice' => 'mine', 'live' => 1 ) ) ) );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_eq( 7, wc_get_product( $p->get_id() )->get_stock_quantity() );
} );
