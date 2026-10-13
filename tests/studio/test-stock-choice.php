<?php
/**
 * Task 13: one "Stock" choice per product and size: Fixed amount (counted), In stock, Made to order, Sold out.
 * New products and sizes start on Fixed amount with an empty count.
 */

/** A simple product with leftover stock meta that does not count its stock (as older shop data can have). */
function fs_uncounted_with_leftover( string $status = 'instock' ): WC_Product_Simple {
	$p = fs_product( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'stock-' . wp_rand() )->term_id ), 'stock_status' => $status ) );
	update_post_meta( $p->get_id(), '_stock', '4' );
	clean_post_cache( $p->get_id() );
	return wc_get_product( $p->get_id() );
}

fs_test( 'stock choice: new products and new sizes start counted with no number', function () {
	$p = fisha_studio_product_create( 'zz-test Counted New' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	$p = wc_get_product( $p->get_id() );
	fs_true( true === $p->get_manage_stock( 'edit' ), 'counted' );
	fs_eq( null, fisha_studio_product_read( $p )['stock_quantity'] );

	$q = fs_variable( array( 's' ) );
	$v = fisha_studio_variation_create( $q, array( 'pa_size' => 'm' ) );
	fs_true( true === wc_get_product( $v->get_id() )->get_manage_stock( 'edit' ), 'a new size counts' );
	fs_eq( null, fisha_studio_product_values( wc_get_product( $q->get_id() ) )[ 'var:' . $v->get_id() . ':stock_quantity' ] );

	$w = fs_variable( array( 's' ) );
	$w->set_manage_stock( true );
	$w->set_stock_quantity( 4 );
	$w->save();
	$v = fisha_studio_variation_create( wc_get_product( $w->get_id() ), array( 'pa_size' => 'm' ) );
	fs_false( true === wc_get_product( $v->get_id() )->get_manage_stock( 'edit' ), 'counted on the whole product: the size does not count' );
} );

fs_test( 'stock choice: a fixed amount needs a number, the other choices publish without one', function () {
	$cat = fs_term( 'choice-' . wp_rand() );
	$p   = fisha_studio_product_create( 'zz-test Choice New' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'category_ids' => array( $cat->term_id ), 'image_id' => fs_image(), 'short_description' => '<p>Hi.</p>', 'regular_price' => '50' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'Enter how many are in stock.', is_wp_error( $r ) ? ( $r->get_error_data()['fields']['stock_quantity'] ?? '' ) : 'published' );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'manage_stock' => false, 'stock_status' => 'onbackorder' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( 'onbackorder', wc_get_product( $p->get_id() )->get_stock_status(), 'made to order is kept' );
} );

fs_test( 'stock choice: a size on a fixed amount needs a number; a whole-product count needs one too', function () {
	$p = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:manage_stock" => true ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'Enter how many are in stock.', is_wp_error( $r ) ? ( $r->get_error_data()['fields']["var:$s:stock_quantity"] ?? '' ) : 'published' );
	fisha_studio_draft_discard( $p->get_id() );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'manage_stock' => true ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'Enter how many are in stock.', is_wp_error( $r ) ? ( $r->get_error_data()['fields']['stock_quantity'] ?? '' ) : 'published' );
} );

fs_test( 'stock choice: an uncounted item reads its stock as null even with leftover data', function () {
	$p = fs_uncounted_with_leftover();
	fs_eq( null, fisha_studio_product_read( $p )['stock_quantity'] );
	$q = fs_variable( array( 's' ) );
	$s = fs_var_id( $q, 's' );
	update_post_meta( $s, '_stock', '3' );
	clean_post_cache( $s );
	fs_eq( null, fisha_studio_product_values( wc_get_product( $q->get_id() ) )[ "var:$s:stock_quantity" ] );
} );

fs_test( 'stock choice: quick edit of a number turns counting on (product)', function () {
	$p = fs_uncounted_with_leftover();
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '3' ), 'seen' => array( 'stock_quantity' => null ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_true( $row['counted'] );
	fs_eq( 3, $row['stock_quantity'] );
	$live = wc_get_product( $p->get_id() );
	fs_true( true === $live->get_manage_stock( 'edit' ) );
	fs_eq( 'no', $live->get_backorders( 'edit' ) );
} );

fs_test( 'stock choice: quick edit of a choice turns counting off (product)', function () {
	$p = fs_product( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'stock-' . wp_rand() )->term_id ), 'manage_stock' => true, 'stock_quantity' => 2 ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_status' => 'onbackorder' ), 'seen' => array( 'stock_status' => 'instock' ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_false( $row['counted'] );
	$live = wc_get_product( $p->get_id() );
	fs_false( $live->get_manage_stock( 'edit' ) );
	fs_eq( 'onbackorder', $live->get_stock_status( 'edit' ) );
	list( $st ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'regular_price' => '90' ), 'seen' => array( 'regular_price' => '100' ) ) );
	fs_eq( 200, $st, 'a price change of an uncounted product is fine' );
} );

fs_test( 'stock choice: quick edit of a size turns counting on and off', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_quantity' => 4 ), 'seen' => array( 'stock_quantity' => null ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_true( $row['counted'] );
	fs_eq( 4, $row['stock_quantity'] );
	fs_eq( 'no', wc_get_product( $s )->get_backorders( 'edit' ) );
	list( $st, $row ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_status' => 'outofstock' ), 'seen' => array( 'stock_status' => 'instock' ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_false( $row['counted'] );
	fs_eq( null, $row['stock_quantity'] );
	fs_eq( 'outofstock', wc_get_product( $s )->get_stock_status( 'edit' ) );
	list( $st ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_quantity' => 2 ), 'seen' => array( 'stock_quantity' => 5 ) ) );
	fs_eq( 409, $st, 'an uncounted size was seen as 5: conflict' );
} );

fs_test( 'stock choice: inventory flags uncounted rows and filters them', function () {
	$c = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
	$u = fs_uncounted_with_leftover( 'onbackorder' );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $u->get_name() ) );
	fs_false( $d['items'][0]['counted'] );
	fs_eq( null, $d['items'][0]['stock_quantity'] );
	fs_eq( '', $d['items'][0]['locked'], 'uncounted rows are editable' );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'stock' => 'uncounted', 'per_page' => 200 ) );
	$ids = wp_list_pluck( $d['items'], 'id' );
	fs_true( in_array( $u->get_id(), $ids, true ) );
	fs_false( in_array( $c->get_id(), $ids, true ) );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => $c->get_name() ) );
	fs_true( $d['items'][0]['counted'] );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => $u->get_name() ) );
	fs_false( $d['items'][0]['counted'] );
} );

/* ---------- Fix round 1: a fixed amount never oversells ---------- */

fs_test( 'stock choice: a quick edit of a counted product that allowed backorders stops them', function () {
	$p = fs_product( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'bo-' . wp_rand() )->term_id ), 'manage_stock' => true, 'stock_quantity' => 3, 'backorders' => 'yes' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '5' ), 'seen' => array( 'stock_quantity' => 3 ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_eq( 'no', wc_get_product( $p->get_id() )->get_backorders( 'edit' ) );
} );

fs_test( 'stock choice: a quick edit of a counted size that allowed backorders stops them', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->set_backorders( 'notify' );
	$v->save();
	$r = fisha_studio_variation_quick( $s, array( 'stock_quantity' => 5 ), array( 'stock_quantity' => 3 ) );
	fs_true( $r instanceof WC_Product_Variation, is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( 'no', wc_get_product( $s )->get_backorders( 'edit' ) );
	fs_eq( 'no', fisha_studio_log_for( 'variation', $s )[0]['after']['backorders'] );
} );

fs_test( 'stock choice: publishing a counted product or size that allowed backorders stops them (logged)', function () {
	$p = fs_product( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'bo-' . wp_rand() )->term_id ), 'manage_stock' => true, 'stock_quantity' => 3, 'backorders' => 'yes' ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 5 ), array( 'stock_quantity' => 3 ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( 'no', wc_get_product( $p->get_id() )->get_backorders( 'edit' ) );
	fs_eq( 'no', fisha_studio_log_for( 'product', $p->get_id() )[0]['after']['backorders'] );

	$q = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $q, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->set_backorders( 'yes' );
	$v->save();
	fisha_studio_product_edit( wc_get_product( $q->get_id() ), array( "var:$s:stock_quantity" => 5 ), array( "var:$s:stock_quantity" => 3 ) );
	$r = fisha_studio_publish_product( $q->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( 'no', wc_get_product( $s )->get_backorders( 'edit' ) );
	fs_eq( 'no', fisha_studio_log_for( 'product', $q->get_id() )[0]['after']["var:$s:backorders"] );
} );
