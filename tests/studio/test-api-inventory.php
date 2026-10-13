<?php
fs_test( 'api inventory: products and each size, sizes in shop order', function () {
	$simple = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
	$p      = fs_variable( array( 's', 'm', 'l' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name() ) );
	fs_eq( 200, $st );
	fs_eq( array( 'S', 'M', 'L' ), array_map( 'strtoupper', wp_list_pluck( $d['items'], 'option' ) ) );
	fs_eq( 'variation', $d['items'][0]['kind'] );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $simple->get_name() ) );
	fs_eq( 'product', $d['items'][0]['kind'] );
	fs_eq( 5, $d['items'][0]['stock_quantity'] );
} );

fs_test( 'api inventory: filters, sort by stock, search by size SKU, locked rows', function () {
	$p = fs_variable( array( 's', 'm' ) );
	$s = fs_var_id( $p, 's' );
	$m = fs_var_id( $p, 'm' );
	foreach ( array( $s => 0, $m => 7 ) as $vid => $q ) {
		$v = wc_get_product( $vid );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( $q );
		$v->set_sku( 'ZZ-TEST-INV-' . $vid );
		$v->save();
	}
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name(), 'sort' => 'stock' ) );
	fs_eq( array( $s, $m ), wp_list_pluck( $d['items'], 'id' ), 'sold out first' );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'stock' => 'outofstock', 'per_page' => 200 ) );
	fs_true( in_array( $s, wp_list_pluck( $d['items'], 'id' ), true ) );
	fs_false( in_array( $m, wp_list_pluck( $d['items'], 'id' ), true ) );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => 'ZZ-TEST-INV-' . $m ) );
	fs_eq( array( $m ), wp_list_pluck( $d['items'], 'id' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'meta_line' => 'pending' ) );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name() ) );
	fs_eq( 'Unpublished changes', $d['items'][0]['locked'] );
} );

fs_test( 'api inventory: size quick edit returns the new row, refuses stale values', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 2 );
	$v->save();
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_quantity' => 6 ), 'seen' => array( 'stock_quantity' => 2 ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_eq( 6, $row['stock_quantity'] );
	wc_update_product_stock( $s, 5, 'set' );
	list( $st, $d ) = fs_api( 'POST', "/variations/$s/quick", array( 'fields' => array( 'stock_quantity' => 9 ), 'seen' => array( 'stock_quantity' => 6 ) ) );
	fs_eq( 409, $st );
	fs_eq( 'fisha_studio_conflict', $d['code'] );
	fs_eq( 5, wc_get_product( $s )->get_stock_quantity() );
} );
