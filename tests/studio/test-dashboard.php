<?php
fs_test( 'dashboard: counts follow the product rows', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$before = fisha_studio_dashboard()['counts'];
	fs_product( array( 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) );
	fs_product( array( 'manage_stock' => true, 'stock_quantity' => 1, 'low_stock_amount' => 2 ) );
	$p = fisha_studio_product_create( 'zz-test dash draft' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	$after = fisha_studio_dashboard()['counts'];
	fs_eq( $before['live'] + 2, $after['live'] );
	fs_eq( $before['sold_out'] + 1, $after['sold_out'] );
	fs_eq( $before['low'] + 1, $after['low'] );
	fs_eq( $before['drafts'] + 1, $after['drafts'] );
} );

fs_test( 'dashboard: waiting lists products and categories with unpublished changes', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'regular_price' => '7' ) );
	$t = fs_cat( 'dash-wait' );
	fisha_studio_category_edit( $t, array( 'lead' => 'Draft.' ) );
	$w = fisha_studio_dashboard()['waiting'];
	$keys = array_map( function ( $x ) { return $x['kind'] . ':' . $x['id']; }, $w );
	fs_true( in_array( 'product:' . $p->get_id(), $keys, true ) );
	fs_true( in_array( 'category:' . $t->term_id, $keys, true ) );
	fs_true( count( $w ) <= 20 );
} );

fs_test( 'dashboard: recent and activity come from History; REST works', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p   = fs_product();
	$log = fisha_studio_log( 'product', $p->get_id(), 'publish', array( 'name' => 'a' ), array( 'name' => 'b' ) );
	fs_on_cleanup( function () use ( $log ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $log ) ); } );
	$d = fisha_studio_dashboard();
	fs_eq( $p->get_id(), $d['recent'][0]['id'] );
	fs_eq( $log, $d['activity'][0]['id'] );
	fs_true( count( $d['activity'] ) <= 15 );
	list( $s, $r ) = fs_api( 'GET', '/dashboard' );
	fs_eq( 200, $s );
	fs_true( isset( $r['counts']['live'] ) );
} );

fs_test( 'dashboard: 11 newer rows for deleted products do not hide older existing items', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_product();
	$old = fisha_studio_log( 'product', $p->get_id(), 'publish', array( 'name' => 'a' ), array( 'name' => 'b' ) );
	$gone = array();
	for ( $i = 0; $i < 11; $i++ ) $gone[] = fisha_studio_log( 'product', 99999990 + $i, 'publish', array( 'name' => 'a' ), array( 'name' => 'b' ) );
	fs_on_cleanup( function () use ( $old, $gone ) { global $wpdb; foreach ( array_merge( array( $old ), $gone ) as $i ) $wpdb->delete( fisha_studio_log_table(), array( 'id' => $i ) ); } );
	$ids = array_column( fisha_studio_dashboard()['recent'], 'id' );
	fs_true( in_array( $p->get_id(), $ids, true ) );
	fs_true( count( $ids ) <= 10 );
} );

fs_test( 'dashboard: counts equal the matching product lists (state=published)', function () {
	fs_as( FISHA_STUDIO_ROLE );
	fs_product( array( 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) );
	$p = fisha_studio_product_create( 'zz-test dash draft 2' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	$c = fisha_studio_dashboard()['counts'];
	list( $s, $r ) = fs_api( 'GET', '/products', array( 'state' => 'published', 'per_page' => 100 ) );
	fs_eq( 200, $s );
	fs_eq( $c['live'], count( $r['items'] ), 'live count equals list' );
	list( $s, $r ) = fs_api( 'GET', '/products', array( 'state' => 'published', 'stock' => 'outofstock', 'per_page' => 100 ) );
	fs_eq( $c['sold_out'], count( $r['items'] ), 'sold out count equals list' );
} );
