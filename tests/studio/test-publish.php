<?php
function fs_complete_product( array $props = array() ): WC_Product_Simple {
	return fs_product( array_merge( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'publish-' . wp_rand() )->term_id ) ), $props ) );
}

fs_test( 'publish: draft goes live and the draft is cleared', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fs_as( FISHA_STUDIO_ROLE );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( '120', wc_get_product( $p->get_id() )->get_regular_price() );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$log = fisha_studio_log_for( 'product', $p->get_id() );
	fs_eq( 'publish', $log[0]['action'] );
	fs_eq( '100', $log[0]['before']['regular_price'] );
	fs_eq( '120', $log[0]['after']['regular_price'] );
} );

fs_test( 'publish: invalid draft writes nothing', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '90', 'sale_price' => '95' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	fs_eq( 422, $r->get_error_data()['status'] );
	fs_true( isset( $r->get_error_data()['fields']['sale_price'] ) );
	fs_eq( '100', wc_get_product( $p->get_id() )->get_regular_price(), 'live untouched' );
	fs_eq( '90', fisha_studio_draft_get( $p->get_id() )['fields']['regular_price'], 'draft kept' );
} );

fs_test( 'publish: conflict must be resolved, then keep live or mine', function () {
	$p = fs_complete_product( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 5, 'regular_price' => '150' ) );
	wc_update_product_stock( $p->get_id(), 2, 'set' );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_eq( 'stock_quantity', $r->get_error_data()['conflicts'][0]['field'] );
	$ok = fisha_studio_publish_product( $p->get_id(), array( 'stock_quantity' => 'live' ) );
	fs_false( is_wp_error( $ok ) );
	$after = wc_get_product( $p->get_id() );
	fs_eq( 2, $after->get_stock_quantity(), 'kept the site value' );
	fs_eq( '150', $after->get_regular_price(), 'other changes published' );
} );

fs_test( 'publish: a forced failure rolls everything back', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '130', 'name' => 'zz-test Rolled Back' ) );
	$boom = function () { throw new RuntimeException( 'zz-test forced failure' ); };
	add_action( 'fisha_studio_before_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_commit', $boom ); } );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_publish_failed', $r->get_error_code() );
	clean_post_cache( $p->get_id() );
	$fresh = wc_get_product( $p->get_id() );
	fs_eq( '100', $fresh->get_regular_price(), 'price rolled back' );
	fs_true( 0 !== strpos( $fresh->get_name(), 'zz-test Rolled Back' ), 'name rolled back' );
	fs_eq( '130', fisha_studio_draft_get( $p->get_id() )['fields']['regular_price'], 'draft still there' );
	fs_eq( array(), fisha_studio_log_for( 'product', $p->get_id() ), 'no audit row' );
} );

fs_test( 'publish: new product goes live at /product/{slug}/ first in its category with alt texts', function () {
	$cat   = fs_term( 'publish-new' );
	$first = fs_product( array( 'category_ids' => array( $cat->term_id ), 'menu_order' => 0 ) );
	$p     = fisha_studio_product_create( 'zz-test Night Swim' );
	fs_on_cleanup( function () use ( $p ) { wp_delete_post( $p->get_id(), true ); } );
	$img = fs_image();
	update_post_meta( $img, '_wp_attachment_image_alt', '' );
	fisha_studio_product_edit( $p, array( 'category_ids' => array( $cat->term_id ), 'image_id' => $img, 'short_description' => '<p>Short.</p>', 'regular_price' => '80' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	$live = wc_get_product( $p->get_id() );
	fs_eq( 'publish', $live->get_status() );
	fs_eq( home_url( '/product/zz-test-night-swim/' ), get_permalink( $p->get_id() ) );
	fs_true( $live->get_menu_order() < wc_get_product( $first->get_id() )->get_menu_order(), 'first in its category' );
	fs_eq( 'zz-test Night Swim by Fisha', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
	fs_eq( '/%postname%/', get_option( 'permalink_structure' ), 'permalinks untouched' );
	fs_eq( 'product', wc_get_permalink_structure()['product_rewrite_slug'] );
	foreach ( array( '_fisha_studio_bg_manual', '_fisha_studio_target_status', '_fisha_studio_slug_manual' ) as $k ) {
		fs_false( metadata_exists( 'post', $p->get_id(), $k ), $k . ' removed after publish' );
	}
} );

fs_test( 'publish: renaming keeps the address; changing it needs change_url and leaves a redirect', function () {
	$p = fs_complete_product( array( 'slug' => 'zz-test-keep-me' ) );
	fisha_studio_product_edit( $p, array( 'name' => 'zz-test Totally New Name' ) );
	fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'zz-test-keep-me', wc_get_product( $p->get_id() )->get_slug(), 'rename keeps slug' );

	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'slug' => 'zz-test-moved' ) );
	fs_eq( 'fisha_studio_invalid', fisha_studio_publish_product( $p->get_id() )->get_error_code(), 'needs change_url' );
	fs_false( is_wp_error( fisha_studio_publish_product( $p->get_id(), array(), true ) ) );
	fs_eq( 'zz-test-moved', wc_get_product( $p->get_id() )->get_slug() );
	fs_true( in_array( 'zz-test-keep-me', get_post_meta( $p->get_id(), '_wp_old_slug' ), true ), 'WordPress will 301 the old address' );
} );

fs_test( 'publish: kinds of product the Studio does not edit are refused', function () {
	$g = new WC_Product_Grouped();
	$g->set_name( 'zz-test grouped' );
	$id = $g->save();
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_eq( 'fisha_studio_unsupported', fisha_studio_publish_product( $id )->get_error_code() );
} );

fs_test( 'publish: trash is restorable', function () {
	$p = fs_complete_product();
	fs_as( FISHA_STUDIO_ROLE );
	fs_true( true === fisha_studio_trash_product( $p->get_id() ) );
	fs_eq( 'trash', get_post_status( $p->get_id() ) );
	fs_eq( 'trash', fisha_studio_log_for( 'product', $p->get_id() )[0]['action'] );
	fs_true( wp_untrash_post( $p->get_id() ), 'can be restored' );
} );
