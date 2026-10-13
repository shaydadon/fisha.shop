<?php
function fs_counted( int $vid, int $qty ): void {
	$v = wc_get_product( $vid );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( $qty );
	$v->save();
}

fs_test( 'sizes quick: a price change goes live and is logged', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	$r = fisha_studio_variation_quick( $s, array( 'regular_price' => '115' ), array( 'regular_price' => '100' ) );
	fs_true( $r instanceof WC_Product_Variation, is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( '115', wc_get_product( $s )->get_regular_price() );
	fs_eq( 'quick', fisha_studio_log_for( 'variation', $s )[0]['action'] );
} );

fs_test( 'sizes quick: a stock that moved since the list loaded is not overwritten', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_counted( $s, 1 );
	wc_update_product_stock( $s, 0, 'set' );
	$r = fisha_studio_variation_quick( $s, array( 'stock_quantity' => 3 ), array( 'stock_quantity' => 1 ) );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_eq( 0, wc_get_product( $s )->get_stock_quantity() );
} );

fs_test( 'sizes quick: refused when the product has a draft or the size is new', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( 'meta_line' => 'pending' ) );
	fs_eq( 'fisha_studio_has_draft', fisha_studio_variation_quick( $s, array( 'regular_price' => '1' ), array() )->get_error_code() );
	$q = fs_variable( array( 's' ) );
	$v = fisha_studio_variation_create( $q, array( 'pa_size' => 'm' ) );
	fisha_studio_draft_put( $q->get_id(), array( 'fields' => array() ) ); // no draft left, but the size is still pending
	fs_eq( 'fisha_studio_unsupported', fisha_studio_variation_quick( $v->get_id(), array( 'regular_price' => '1' ), array() )->get_error_code() );
} );

fs_test( 'sizes quick: invalid values are refused', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_quick( $s, array( 'stock_quantity' => 3 ), array( 'stock_quantity' => null ) )->get_error_code(), 'stock not counted for this size' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_quick( $s, array( 'regular_price' => '' ), array( 'regular_price' => '100' ) )->get_error_code(), 'a size that is on needs a price' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_quick( $s, array( 'regular_price' => 'abc' ), array( 'regular_price' => '100' ) )->get_error_code() );
} );

fs_test( 'sizes quick: a sent field without the seen value is refused', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$r = fisha_studio_variation_quick( $s, array( 'regular_price' => '115' ), array() );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	fs_eq( 422, $r->get_error_data()['status'] );
	fs_eq( '100', wc_get_product( $s )->get_regular_price(), 'nothing written' );
} );

fs_test( 'sizes quick: an order between the check and the save is caught', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_counted( $s, 5 );
	add_action( 'fisha_studio_quick_before_recheck', function () use ( $s ) { update_post_meta( $s, '_stock', 4 ); } );
	fs_on_cleanup( function () { remove_all_actions( 'fisha_studio_quick_before_recheck' ); } );
	$r = fisha_studio_variation_quick( $s, array( 'stock_quantity' => 3 ), array( 'stock_quantity' => 5 ) );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_true( 3 !== (int) wc_get_product( $s )->get_stock_quantity(), 'our write must not land' );
} );
