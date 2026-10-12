<?php
fs_test( 'preview: tokens only work for the person they were made for', function () {
	$owner = fs_as( FISHA_STUDIO_ROLE );
	$tok   = fisha_studio_preview_token( $owner->ID, 'product', 123 );
	fs_eq( array( 'type' => 'product', 'id' => 123 ), fisha_studio_preview_verify( $tok ) );
	fs_eq( null, fisha_studio_preview_verify( $tok . 'x' ), 'tampered signature' );
	fs_eq( null, fisha_studio_preview_verify( str_replace( '-123-', '-124-', $tok ) ), 'tampered id' );
	fs_eq( null, fisha_studio_preview_verify( fisha_studio_preview_token( $owner->ID, 'product', 123, time() - 1 ) ), 'expired' );
	fs_eq( null, fisha_studio_preview_verify( fisha_studio_preview_token( $owner->ID, 'page', 2 ) ), 'unknown type' );
	fs_eq( null, fisha_studio_preview_verify( 'garbage' ) );
	fs_as( FISHA_STUDIO_ROLE );
	fs_eq( null, fisha_studio_preview_verify( $tok ), 'another owner' );
	wp_set_current_user( 0 );
	fs_eq( null, fisha_studio_preview_verify( $tok ), 'signed out' );
} );

fs_test( 'preview: draft values show through the normal APIs, only for that product', function () {
	$p     = fs_product( array( 'regular_price' => '100' ) );
	$other = fs_product();
	fisha_studio_product_edit( $p, array( 'name' => 'zz-test Preview Name', 'regular_price' => '150', 'card_bg' => '#123456', 'short_description' => '<p>Draft short</p>', 'description' => '<p>Draft long</p>' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$v = wc_get_product( $p->get_id() );
	fs_eq( 'zz-test Preview Name', $v->get_name() );
	fs_eq( '150', $v->get_price() );
	fs_eq( '150', $v->get_regular_price() );
	fs_eq( '#123456', get_post_meta( $p->get_id(), '_fisha_card_bg', true ) );
	fs_eq( 'zz-test Preview Name', apply_filters( 'the_title', 'x', $p->get_id() ) );
	fs_eq( '<p>Draft short</p>', apply_filters( 'get_the_excerpt', 'x', get_post( $p->get_id() ) ) );
	fs_eq( $other->get_name(), wc_get_product( $other->get_id() )->get_name(), 'other products untouched' );
	fs_eq( 'x', apply_filters( 'the_title', 'x', $other->get_id() ) );
	fs_eq( '100', wc_get_product( $p->get_id() )->get_regular_price( 'edit' ), 'edit context (what publish reads) untouched' );
	fisha_studio_preview_uninstall();
	fs_true( 'zz-test Preview Name' !== wc_get_product( $p->get_id() )->get_name(), 'gone after uninstall' );
} );

fs_test( 'preview: sold out and sale show the way they will after publish', function () {
	$p = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 3, 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 0, 'sale_price' => '80' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$v = wc_get_product( $p->get_id() );
	fs_eq( 'outofstock', $v->get_stock_status() );
	fs_false( $v->is_in_stock() );
	fs_eq( '80', $v->get_price() );
	fs_true( $v->is_on_sale() );
} );

fs_test( 'preview: category pages follow the draft categories', function () {
	$a = fs_term( 'pv-a' );
	$b = fs_term( 'pv-b' );
	$p = fs_product( array( 'category_ids' => array( $a->term_id ) ) );
	fisha_studio_product_edit( $p, array( 'category_ids' => array( $b->term_id ) ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$in = function ( $slug ) { return wc_get_products( array( 'category' => array( $slug ), 'status' => 'publish', 'return' => 'ids', 'limit' => -1 ) ); };
	fs_false( in_array( $p->get_id(), $in( $a->slug ), true ), 'left the old category' );
	fs_true( in_array( $p->get_id(), $in( $b->slug ), true ), 'appears in the new category' );
} );

fs_test( 'preview: a new product shows on its category page in preview only', function () {
	$cat = fs_term( 'pv-new' );
	$p   = fisha_studio_product_create( 'zz-test Preview New' );
	fs_on_cleanup( function () use ( $p ) { wp_delete_post( $p->get_id(), true ); } );
	fisha_studio_product_edit( $p, array( 'category_ids' => array( $cat->term_id ) ) );
	$in = function () use ( $cat ) { return wc_get_products( array( 'category' => array( $cat->slug ), 'status' => 'publish', 'return' => 'ids', 'limit' => -1 ) ); };
	fs_false( in_array( $p->get_id(), $in(), true ), 'customers do not see it' );
	fisha_studio_preview_install_product( $p->get_id(), array() );
	fs_true( in_array( $p->get_id(), $in(), true ), 'preview shows it' );
} );

fs_test( 'preview: targets carry a token for every page', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$cat = fs_term( 'pv-targets' );
	$p   = fs_product( array( 'category_ids' => array( $cat->term_id ) ) );
	$t   = fisha_studio_preview_targets( $p, array( $cat->term_id ) );
	fs_eq( array( 'item', 'category', 'shop', 'home' ), wp_list_pluck( $t, 'key' ) );
	foreach ( $t as $x ) fs_true( false !== strpos( $x['url'], 'fisha_preview=' ), $x['key'] . ' has a token' );
} );
