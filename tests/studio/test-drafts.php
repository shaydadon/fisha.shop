<?php
fs_test( 'drafts: editing a live product leaves it untouched', function () {
	$p = fs_product( array( 'regular_price' => '100' ) );
	fs_as( FISHA_STUDIO_ROLE );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120', 'name' => 'zz-test Changed' ) );
	$live = wc_get_product( $p->get_id() );
	fs_eq( '100', $live->get_regular_price() );
	$d = fisha_studio_draft_get( $p->get_id() );
	fs_eq( array( 'regular_price' => '120', 'name' => 'zz-test Changed' ), $d['fields'] );
	fs_eq( '100', $d['base']['regular_price'], 'base remembers the live value' );
	fs_eq( get_current_user_id(), $d['user'] );
} );

fs_test( 'drafts: two saves merge', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'sku' => 'ZZ-TEST-MERGE' ) );
	$d = fisha_studio_draft_get( $p->get_id() );
	fs_eq( '120', $d['fields']['regular_price'], 'first tab change kept' );
	fs_eq( 'ZZ-TEST-MERGE', $d['fields']['sku'], 'second tab change kept' );
} );

fs_test( 'drafts: changing a value back removes it from the draft', function () {
	$p = fs_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'regular_price' => '100' ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_eq( '', get_post_meta( $p->get_id(), FISHA_STUDIO_DRAFT_META, true ), 'meta removed' );
} );

fs_test( 'drafts: quotes and backslashes survive', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'short_description' => '<p>Fisha\'s "best" \ fish</p>' ) );
	fs_eq( '<p>Fisha\'s "best" \ fish</p>', fisha_studio_draft_get( $p->get_id() )['fields']['short_description'] );
} );

fs_test( 'drafts: discard', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'regular_price' => '1' ) );
	fisha_studio_draft_discard( $p->get_id() );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
} );

fs_test( 'drafts: new product is a WooCommerce draft and edits apply directly', function () {
	$cat = fs_term( 'drafts-new' );
	$p   = fisha_studio_product_create( 'zz-test Blue Reed Fisha' );
	fs_on_cleanup( function () use ( $p ) { wp_delete_post( $p->get_id(), true ); } );
	fs_eq( 'draft', get_post_status( $p->get_id() ) );
	fs_true( fisha_studio_is_new( $p ) );
	fs_eq( 'zz-test-blue-reed-fisha', $p->get_slug(), 'slug from the name' );
	fisha_studio_product_edit( $p, array( 'name' => 'zz-test Green Reed Fisha', 'category_ids' => array( $cat->term_id ), 'status' => 'private' ) );
	$p = wc_get_product( $p->get_id() );
	fs_eq( 'zz-test Green Reed Fisha', $p->get_name(), 'applied directly' );
	fs_eq( 'zz-test-green-reed-fisha', $p->get_slug(), 'slug follows the name until edited' );
	fs_eq( 'draft', get_post_status( $p->get_id() ), 'still a draft' );
	fs_eq( 'private', fisha_studio_target_status( $p ), 'chosen visibility remembered' );
	fs_eq( '#efe8dc', $p->get_meta( '_fisha_card_bg' ), 'card colour default for an empty category' );
	fisha_studio_product_edit( $p, array( 'slug' => 'zz-test-my-own-url' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'name' => 'zz-test Renamed Again' ) );
	fs_eq( 'zz-test-my-own-url', wc_get_product( $p->get_id() )->get_slug(), 'typed slug is kept' );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'], 'no draft meta for new items' );
} );

fs_test( 'drafts: card colour default follows the category', function () {
	$cat = fs_term( 'drafts-colour' );
	foreach ( array( '#E3ECEF', '#e3ecef', '#ffffff' ) as $colour ) {
		$q = fs_product( array( 'category_ids' => array( $cat->term_id ) ) );
		$q->update_meta_data( '_fisha_card_bg', $colour );
		$q->save();
	}
	fs_eq( '#e3ecef', fisha_studio_default_card_bg( array( $cat->term_id ) ) );
} );

fs_test( 'drafts: conflicts only when the live value moved under an edited field', function () {
	$p = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 3, 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 5, 'regular_price' => '110' ) );
	fs_eq( array(), fisha_studio_conflicts( wc_get_product( $p->get_id() ), fisha_studio_draft_get( $p->get_id() ) ), 'nothing moved yet' );
	wc_update_product_stock( $p->get_id(), 2, 'set' );                          // an order came in
	$c = fisha_studio_conflicts( wc_get_product( $p->get_id() ), fisha_studio_draft_get( $p->get_id() ) );
	fs_eq( 1, count( $c ) );
	fs_eq( 'stock_quantity', $c[0]['field'] );
	fs_eq( 3, $c[0]['base'] );
	fs_eq( 2, $c[0]['live'] );
	fs_eq( 5, $c[0]['mine'] );
} );
