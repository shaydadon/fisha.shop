<?php
function fs_log_row( string $type, int $id, string $action, ?array $before, ?array $after ): int {
	$log = fisha_studio_log( $type, $id, $action, $before, $after );
	fs_on_cleanup( function () use ( $log ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $log ) ); } );
	return $log;
}

fs_test( 'history: a publish row reads as plain change lines', function () {
	$p   = fs_product( array( 'regular_price' => '119' ) );
	$v   = fisha_studio_product_values( $p );
	$log = fs_log_row( 'product', $p->get_id(), 'publish', array_merge( $v, array( 'regular_price' => '119', 'description' => '<p>Old</p>' ) ), array_merge( $v, array( 'regular_price' => '109', 'description' => '<p>New</p>' ) ) );
	$res = fisha_studio_history_query( array( 'item_type' => 'product', 'item_id' => $p->get_id() ) );
	$e   = $res['items'][0];
	fs_eq( $log, $e['id'] );
	fs_eq( 'Published changes', $e['title'] );
	fs_true( in_array( 'Price: 119 → 109', $e['changes'], true ), wp_json_encode( $e['changes'] ) );
	fs_true( in_array( 'Full description changed', $e['changes'], true ) );
	fs_true( $e['restorable'] );
	fs_eq( $p->get_id(), $e['item']['id'] );
	fs_true( $e['item']['exists'] );
	fs_true( (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $e['when'] ), 'ISO UTC' );
} );

fs_test( 'history: a product\'s list includes its sizes\' quick edits', function () {
	$p   = fs_variable( array( 's', 'm' ) );
	$vid = fs_var_id( $p, 'm' );
	fs_log_row( 'variation', $vid, 'quick', array( 'stock_quantity' => 4 ), array( 'stock_quantity' => 3 ) );
	$items = fisha_studio_history_query( array( 'item_type' => 'product', 'item_id' => $p->get_id() ) )['items'];
	fs_eq( 1, count( $items ) );
	fs_eq( 'Quick edit of a size', $items[0]['title'] );
	fs_false( $items[0]['restorable'], 'size quick rows are activity only' );
	fs_true( (bool) array_filter( $items[0]['changes'], function ( $l ) { return false !== strpos( $l, '4 → 3' ) && false !== stripos( $l, 'M' ); } ), wp_json_encode( $items[0]['changes'] ) );
} );

fs_test( 'history: kinds, days and paging filter the list', function () {
	$t = fs_cat( 'hist-kind' );
	$p = fs_product();
	$u = fs_user( FISHA_STUDIO_ROLE );
	fs_log_row( 'category', $t->term_id, 'shown', array( 'shown' => true ), array( 'shown' => false ) );
	fs_log_row( 'product', $p->get_id(), 'trash', null, null );
	fs_log_row( 'user', $u->ID, '2fa-signin', null, array( 'ip' => '1.2.3.4' ) );
	$ids = function ( $args ) { return wp_list_pluck( fisha_studio_history_query( $args )['items'], 'title' ); };
	fs_true( in_array( 'Signed in', $ids( array( 'kind' => 'signin', 'per_page' => 100 ) ), true ) );
	fs_false( in_array( 'Signed in', $ids( array( 'kind' => 'product', 'per_page' => 100 ) ), true ) );
	fs_true( in_array( 'Switched Show on site off', $ids( array( 'kind' => 'category', 'per_page' => 100 ) ), true ) );
	$page = fisha_studio_history_query( array( 'per_page' => 1, 'page' => 2 ) );
	fs_eq( 1, count( $page['items'] ) );
	fs_true( $page['pages'] >= 3 );
	global $wpdb;
	$old = fs_log_row( 'product', $p->get_id(), 'publish', array( 'name' => 'a' ), array( 'name' => 'b' ) );
	$wpdb->update( fisha_studio_log_table(), array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
	fs_false( in_array( $old, wp_list_pluck( fisha_studio_history_query( array( 'days' => 7, 'per_page' => 100 ) )['items'], 'id' ), true ), '7 days' );
	fs_true( in_array( $old, wp_list_pluck( fisha_studio_history_query( array( 'days' => 30, 'per_page' => 100 ) )['items'], 'id' ), true ), '30 days' );
} );

fs_test( 'history: long change lists are cut at 6 with a count', function () {
	$p = fs_product();
	$b = array(); $a = array();
	foreach ( array( 'name', 'sku', 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ) as $i => $k ) { $b[ $k ] = 'x' . $i; $a[ $k ] = 'y' . $i; }
	fs_log_row( 'product', $p->get_id(), 'publish', $b, $a );
	$e = fisha_studio_history_query( array( 'item_type' => 'product', 'item_id' => $p->get_id() ) )['items'][0];
	fs_eq( 6, count( $e['changes'] ) );
	fs_eq( 2, $e['more'] );
} );

fs_test( 'history: REST lists for the owner only', function () {
	fs_as( 'customer' );
	fs_eq( 403, fs_api( 'GET', '/history' )[0] );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/history', array( 'kind' => 'all', 'per_page' => 5 ) );
	fs_eq( 200, $s );
	fs_true( isset( $d['items'], $d['total'], $d['pages'] ) );
} );
