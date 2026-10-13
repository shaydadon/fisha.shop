<?php
/** Task 14: wp fisha-studio convert-sizes (a legacy product with a local quoted "Size" option becomes a normal product with sizes). */

/** A variable product like the legacy product 21: local "Size" and "Color" with quoted values, whole-product stock counting. */
function fs_legacy_tee(): WC_Product_Variable {
	$p = new WC_Product_Variable();
	$p->set_name( 'zz-test legacy tee ' . strtolower( wp_generate_password( 5, false ) ) );
	$p->set_status( 'publish' );
	$p->set_short_description( 'Test short description.' );
	$size = new WC_Product_Attribute();
	$size->set_name( 'Size' );
	$size->set_options( array( '"XS"', '"S"', '"L"', '"XL"' ) );
	$size->set_position( 0 );
	$size->set_visible( true );
	$size->set_variation( true );
	$color = new WC_Product_Attribute();
	$color->set_name( 'Color' );
	$color->set_options( array( '"White"', '"Black"', '"Blue"', '"Pink"' ) );
	$color->set_position( 1 );
	$color->set_visible( true );
	$color->set_variation( true );
	$p->set_attributes( array( 'size' => $size, 'color' => $color ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 10 );
	$p->set_backorders( 'notify' );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	foreach ( array( 'XS' => '50', 'S' => '60', 'L' => '70', 'XL' => '80' ) as $i => $price ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( 'size' => '"' . $i . '"', 'color' => '"White"' ) );
		$v->set_regular_price( $price );
		$v->set_status( 'publish' );
		$v->save();
	}
	WC_Product_Variable::sync( $id );
	wc_delete_product_transients( $id );
	return wc_get_product( $id );
}

/** Removes the pa_size XS value after the test when the test created it. */
function fs_forget_new_xs(): void {
	if ( get_term_by( 'name', 'XS', 'pa_size' ) ) return;
	fs_on_cleanup( function () {
		$t = get_term_by( 'name', 'XS', 'pa_size' );
		if ( $t ) wp_delete_term( $t->term_id, 'pa_size' );
	} );
}

/** size slug => [variation id, color, price] for every size of $p. */
function fs_legacy_sizes( WC_Product $p ): array {
	$out = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		$a = $v->get_attributes( 'edit' );
		$out[ (string) ( $a['pa_size'] ?? $a['size'] ?? '' ) ] = array( $vid, (string) ( $a['color'] ?? '' ), (string) $v->get_regular_price( 'edit' ) );
	}
	return $out;
}

fs_test( 'convert: refuses a product without sizes', function () {
	$r = fisha_studio_convert_sizes( fs_product()->get_id(), true );
	fs_true( is_wp_error( $r ), 'error for a simple product' );
} );

fs_test( 'convert: dry run changes nothing and lists the plan', function () {
	fs_forget_new_xs();
	$p      = fs_legacy_tee();
	$before = fisha_studio_product_values( $p );
	$r      = fisha_studio_convert_sizes( $p->get_id(), true );
	fs_false( is_wp_error( $r ), 'no error' );
	fs_false( $r['applied'], 'not applied' );
	fs_true( count( $r['changes'] ) > 0, 'plan has changes' );
	clean_post_cache( $p->get_id() );
	fs_eq( $before, fisha_studio_product_values( wc_get_product( $p->get_id() ) ), 'values unchanged' );
	fs_eq( array(), wp_get_object_terms( $p->get_id(), 'pa_size', array( 'fields' => 'ids' ) ), 'no size terms assigned' );
} );

fs_test( 'convert: legacy sizes become shop sizes, color stays, stock per size', function () {
	fs_forget_new_xs();
	$p   = fs_legacy_tee();
	$id  = $p->get_id();
	$old = fs_legacy_sizes( $p );
	$r   = fisha_studio_convert_sizes( $id, false );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_true( $r['applied'], 'applied' );

	$p     = wc_get_product( $id );
	$attrs = fisha_studio_attributes_read( $p );
	fs_eq( 'pa_size', $attrs[0]['name'], 'Size is the shop-wide option, same position' );
	fs_true( $attrs[0]['taxonomy'] && $attrs[0]['variation'] && $attrs[0]['visible'], 'flags kept' );
	$slugs = array();
	foreach ( $attrs[0]['options'] as $tid ) $slugs[] = get_term( $tid, 'pa_size' )->slug;
	sort( $slugs );
	$xs = get_term_by( 'name', 'XS', 'pa_size' );
	fs_true( (bool) $xs, 'XS exists' );
	$want = array( 'l', 's', 'xl', $xs->slug );
	sort( $want );
	fs_eq( $want, $slugs, 'size terms' );
	$ids = wp_get_object_terms( $id, 'pa_size', array( 'fields' => 'ids' ) );
	sort( $ids );
	fs_eq( $attrs[0]['options'], array_map( 'intval', $ids ), 'terms assigned to the product' );
	fs_eq( array( 'name' => 'Color', 'label' => 'Color', 'taxonomy' => false, 'options' => array( 'White', 'Black', 'Blue', 'Pink' ), 'variation' => true, 'visible' => true ), $attrs[1], 'Color kept, quotes gone' );

	$new = fs_legacy_sizes( $p );
	$map = array( '"XS"' => $xs->slug, '"S"' => 's', '"L"' => 'l', '"XL"' => 'xl' );
	foreach ( $old as $was => $row ) {
		fs_true( isset( $new[ $map[ $was ] ] ), "size $was kept: " . wp_json_encode( $new ) );
		fs_eq( array( $row[0], 'White', $row[2] ), $new[ $map[ $was ] ], "size $was keeps its id, White and price" );
		fs_eq( '', (string) get_post_meta( $row[0], 'attribute_size', true ), 'old meta removed' );
		$v = wc_get_product( $row[0] );
		fs_eq( false, $v->get_manage_stock( 'edit' ), 'size does not count' );
		fs_eq( 'instock', $v->get_stock_status( 'edit' ), 'size In stock' );
		fs_eq( 'no', $v->get_backorders( 'edit' ), 'size backorders no' );
		$label = fisha_studio_variation_label( $v );
		fs_false( strpos( $label, '"' ), "label without quotes: $label" );
		fs_true( false !== strpos( $label, get_term_by( 'slug', $map[ $was ], 'pa_size' )->name ) && false !== strpos( $label, 'White' ), "label names size and color: $label" );
	}
	fs_eq( false, $p->get_manage_stock( 'edit' ), 'parent does not count' );
	fs_eq( 'no', $p->get_backorders( 'edit' ), 'parent backorders no' );
	fs_eq( 'instock', $p->get_stock_status( 'edit' ), 'parent stock from sizes' );
	fs_eq( array(), fisha_studio_variable_validate( fisha_studio_product_values( $p ), $p ), 'passes the publish checks' );

	$log = fisha_studio_log_for( 'product', $id );
	fs_eq( 'convert', $log[0]['action'] ?? '', 'audit row' );
	fs_eq( true, $log[0]['before']['manage_stock'], 'audit before' );
	fs_eq( 'pa_size', $log[0]['after']['attributes'][0]['name'], 'audit after' );

	$again = fisha_studio_convert_sizes( $id, false );
	fs_false( is_wp_error( $again ), 'second run ok' );
	fs_eq( array(), $again['changes'], 'second run: nothing to change' );
	fs_false( $again['applied'], 'second run: nothing applied' );
	fs_eq( 1, count( fisha_studio_log_for( 'product', $id ) ), 'second run: no audit row' );
} );

fs_test( 'convert: XS joins the shop sizes last', function () {
	if ( get_term_by( 'name', 'XS', 'pa_size' ) ) return; // only when the test creates it
	fs_forget_new_xs();
	$max = 0;
	foreach ( get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) as $t ) $max = max( $max, (int) get_term_meta( $t->term_id, 'order', true ) );
	fisha_studio_convert_sizes( fs_legacy_tee()->get_id(), false );
	fs_eq( $max + 1, (int) get_term_meta( get_term_by( 'name', 'XS', 'pa_size' )->term_id, 'order', true ) );
} );

fs_test( 'convert: --map renames sizes all at once (XS=S, S=M)', function () {
	fs_forget_new_xs();
	$map = fisha_studio_parse_size_map( 'XS=S, S=M' );
	fs_eq( array( 'XS' => 'S', 'S' => 'M' ), $map );
	$p  = fs_legacy_tee();
	$id = $p->get_id();
	$r  = fisha_studio_convert_sizes( $id, false, $map );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_true( $r['applied'], 'applied' );
	$p     = wc_get_product( $id );
	$sizes = fs_legacy_sizes( $p );
	ksort( $sizes );
	fs_eq( array( 'l', 'm', 's', 'xl' ), array_keys( $sizes ), 'sizes S, M, L, XL' );
	foreach ( array( 's' => '50', 'm' => '60', 'l' => '70', 'xl' => '80' ) as $slug => $price ) {
		fs_eq( array( 'White', $price ), array( $sizes[ $slug ][1], $sizes[ $slug ][2] ), "$slug keeps color and its price" );
	}
	$ids = fisha_studio_attributes_read( $p )[0]['options'];
	$want = array_map( function ( $s ) { return (int) get_term_by( 'slug', $s, 'pa_size' )->term_id; }, array( 's', 'm', 'l', 'xl' ) );
	sort( $want );
	fs_eq( $want, $ids, 'existing shop sizes used, M included' );
	fs_eq( array(), fisha_studio_variable_validate( fisha_studio_product_values( $p ), $p ), 'passes the publish checks' );
	fs_eq( array(), fisha_studio_convert_sizes( $id, false, $map )['changes'], 'second run with the map: nothing to change' );
} );

fs_test( 'convert: a bad --map changes nothing', function () {
	fs_true( is_wp_error( fisha_studio_parse_size_map( 'XS' ) ), 'not OLD=NEW' );
	$p      = fs_legacy_tee();
	$before = fisha_studio_product_values( $p );
	fs_true( is_wp_error( fisha_studio_convert_sizes( $p->get_id(), false, array( 'XS' => 'S' ) ) ), 'two sizes would be S' );
	fs_true( is_wp_error( fisha_studio_convert_sizes( $p->get_id(), false, array( 'XXL' => 'M' ) ) ), 'no such size' );
	clean_post_cache( $p->get_id() );
	fs_eq( $before, fisha_studio_product_values( wc_get_product( $p->get_id() ) ), 'unchanged' );
} );
