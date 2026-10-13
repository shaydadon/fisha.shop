<?php
function fs_offered( int $pid ): array {
	return array_map( 'intval', wp_list_pluck( wc_get_product( $pid )->get_available_variations(), 'variation_id' ) );
}

fs_test( 'sizes preview: a draft size price shows in the price range', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '150' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$v = wc_get_product( $p->get_id() );
	fs_eq( 150.0, (float) $v->get_variation_price( 'max' ) );
	fs_eq( 100.0, (float) $v->get_variation_price( 'min' ) );
	fs_eq( '150', wc_get_product( $s )->get_price() );
} );

fs_test( 'sizes preview: a size switched off disappears from the choice', function () {
	$p = fs_variable();
	$m = fs_var_id( $p, 'm' );
	fisha_studio_product_edit( $p, array( "var:$m:enabled" => false ) );
	fs_true( in_array( $m, fs_offered( $p->get_id() ), true ), 'still offered on the live page' );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_false( in_array( $m, fs_offered( $p->get_id() ), true ), 'gone in the preview' );
} );

fs_test( 'sizes preview: a new size shows only in the preview', function () {
	$p = fs_variable( array( 's', 'm' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	fs_false( in_array( $v->get_id(), fs_offered( $p->get_id() ), true ), 'shoppers do not see it' );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_true( in_array( $v->get_id(), fs_offered( $p->get_id() ), true ), 'the preview shows it' );
} );

fs_test( 'sizes preview: sold out when every size that is on is sold out', function () {
	$p      = fs_variable( array( 's', 'm' ) );
	$fields = array();
	foreach ( array( 's', 'm' ) as $slug ) {
		$id = fs_var_id( $p, $slug );
		$fields[ "var:$id:manage_stock" ]   = true;
		$fields[ "var:$id:stock_quantity" ] = 0;
	}
	fisha_studio_product_edit( $p, $fields );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_eq( 'outofstock', wc_get_product( $p->get_id() )->get_stock_status() );
} );
