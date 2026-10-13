<?php
function fs_size_term( string $slug ): int { return (int) get_term_by( 'slug', $slug, 'pa_size' )->term_id; }

fs_test( 'variations: keys', function () {
	fs_eq( 'var:12:regular_price', fisha_studio_var_key( 12, 'regular_price' ) );
	fs_eq( array( 12, 'stock_quantity' ), fisha_studio_parse_var_key( 'var:12:stock_quantity' ) );
	fs_eq( null, fisha_studio_parse_var_key( 'var:12:nope' ) );
	fs_eq( null, fisha_studio_parse_var_key( 'regular_price' ) );
} );

fs_test( 'variations: values include options and every size', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	$v = fisha_studio_product_values( $p );
	fs_eq( 'pa_size', $v['attributes'][0]['name'] );
	fs_true( $v['attributes'][0]['variation'], 'used for sizes' );
	fs_eq( 3, count( $v['attributes'][0]['options'] ) );
	fs_eq( '100', $v[ "var:$s:regular_price" ] );
	fs_eq( true, $v[ "var:$s:enabled" ] );
	fs_eq( 3 * count( fisha_studio_variation_fields() ), count( preg_grep( '/^var:/', array_keys( $v ) ) ) );
	fs_false( isset( fisha_studio_product_values( fs_product() )['attributes'] ), 'simple products have no options key' );
} );

fs_test( 'variations: options are cleaned', function () {
	$m = fs_size_term( 'm' );
	$s = fs_size_term( 's' );
	list( $c, $e ) = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( $m, (string) $s, $m ), 'variation' => 1, 'visible' => 1 ) ) );
	fs_eq( null, $e );
	$want = array( $m, $s );
	sort( $want );
	fs_eq( $want, $c[0]['options'], 'unique, sorted ids' );
	fs_eq( wc_attribute_label( 'pa_size' ), $c[0]['label'] );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( 99999999 ) ) ) )[1], 'unknown value' );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'pa_zz_nope', 'taxonomy' => true, 'options' => array() ) ) )[1], 'unknown option' );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'Color', 'options' => array( 'a' ) ), array( 'name' => 'color', 'options' => array( 'b' ) ) ) )[1], 'listed twice' );
	list( $c2 ) = fisha_studio_clean_attributes( array( array( 'name' => 'Color', 'options' => array( '"White"', ' Black ', '', '<b>Pink</b>' ), 'variation' => true ) ) );
	fs_eq( array( '"White"', 'Black', 'Pink' ), $c2[0]['options'], 'trimmed, tags stripped, quotes kept' );
	fs_true( fisha_studio_clean_attributes( 'x' )[1], 'not a list' );
} );

fs_test( 'variations: legacy options of product 21 survive a round trip', function () {
	$p = wc_get_product( 21 );
	if ( ! $p || ! $p->is_type( 'variable' ) ) return; // only on a copy of the shop database
	$read = fisha_studio_attributes_read( $p );
	fs_eq( $read, fisha_studio_clean_attributes( $read )[0] );
} );

fs_test( "variations: sanitize keeps only this product's sizes", function () {
	$p     = fs_variable();
	$other = fs_variable( array( 'm' ) );
	$s     = fs_var_id( $p, 's' );
	$om    = fs_var_id( $other, 'm' );
	list( $vals, $errs ) = fisha_studio_sanitize_product_input( $p, array( "var:$s:regular_price" => '₪120', "var:$om:regular_price" => '5', "var:$s:sale_price" => 'abc', 'name' => 'X', "var:$s:nope" => 1 ) );
	fs_eq( '120', $vals[ "var:$s:regular_price" ] );
	fs_false( isset( $vals[ "var:$om:regular_price" ] ), "another product's size is ignored" );
	fs_true( isset( $errs[ "var:$s:sale_price" ] ), 'bad price reported on its key' );
	fs_eq( 'X', $vals['name'] );
} );

fs_test( 'variations: apply writes sizes and options', function () {
	$p     = fs_variable();
	$s     = fs_var_id( $p, 's' );
	$l     = fs_var_id( $p, 'l' );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'] = array_values( array_diff( $attrs[0]['options'], array( fs_size_term( 'l' ) ) ) );
	fisha_studio_product_apply_all( $p, array( 'attributes' => $attrs, "var:$s:regular_price" => '130', "var:$s:sku" => 'ZZ-TEST-S-' . $s, "var:$l:enabled" => false, "var:$s:manage_stock" => true, "var:$s:stock_quantity" => 4 ) );
	$p->save();
	$after = fisha_studio_product_values( wc_get_product( $p->get_id() ) );
	fs_eq( '130', $after[ "var:$s:regular_price" ] );
	fs_eq( 4, $after[ "var:$s:stock_quantity" ] );
	fs_eq( false, $after[ "var:$l:enabled" ] );
	fs_eq( 2, count( $after['attributes'][0]['options'] ) );
} );

fs_test( 'variations: labels name the size', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fs_eq( get_term_by( 'slug', 's', 'pa_size' )->name . ': Stock', fisha_studio_value_label( $p, "var:$s:stock_quantity" ) );
	fs_eq( 'Price', fisha_studio_value_label( $p, 'regular_price' ) );
	fs_eq( 'Sizes and options', fisha_studio_value_label( $p, 'attributes' ) );
} );

fs_test( 'variations: catalog lists the shop-wide options with their values', function () {
	$size = current( array_filter( fisha_studio_attributes_catalog(), function ( $a ) { return 'pa_size' === $a['name']; } ) );
	fs_true( $size, 'pa_size listed' );
	fs_eq( array(), array_diff( array( 's', 'm', 'l', 'xl' ), wp_list_pluck( $size['terms'], 'slug' ) ) );
} );
