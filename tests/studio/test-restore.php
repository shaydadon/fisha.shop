<?php
function fs_snapshot( string $type, int $id, array $after ): int {
	$log = fisha_studio_log( $type, $id, 'publish', null, $after );
	fs_on_cleanup( function () use ( $log ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $log ) ); } );
	return $log;
}

fs_test( 'restore: values go to the draft, missing sizes and photos are skipped', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p    = fs_variable( array( 's', 'm' ) );
	$live = fisha_studio_product_values( $p );
	$old  = $live;
	$old['name'] = $live['name'] . ' old';
	$old[ 'var:' . fs_var_id( $p, 'm' ) . ':regular_price' ] = '77';
	$old['var:999999:regular_price'] = '10';
	$old['image_id'] = 999999;
	$log = fs_snapshot( 'product', $p->get_id(), $old );
	$res = fisha_studio_restore( 'product', $p->get_id(), $log );
	fs_false( is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	$d = fisha_studio_draft_get( $p->get_id() );
	fs_eq( $live['name'] . ' old', $d['fields']['name'] );
	fs_eq( '77', $d['fields'][ 'var:' . fs_var_id( $p, 'm' ) . ':regular_price' ] );
	fs_false( isset( $d['fields']['var:999999:regular_price'] ) );
	fs_false( isset( $d['fields']['image_id'] ) );
	fs_eq( array( '1 size from that version no longer exists.', '1 photo from that version no longer exists.' ), $res['skipped'] );
	fs_eq( $live['name'], wc_get_product( $p->get_id() )->get_name(), 'live untouched until Publish' );
} );

fs_test( 'restore: a version equal to live is refused; activity rows are not restorable', function () {
	$p   = fs_product();
	$log = fs_snapshot( 'product', $p->get_id(), fisha_studio_product_values( $p ) );
	fs_eq( 'fisha_studio_same', fisha_studio_restore( 'product', $p->get_id(), $log )->get_error_code() );
	$trash = fisha_studio_log( 'product', $p->get_id(), 'trash', fisha_studio_product_values( $p ), null );
	fs_on_cleanup( function () use ( $trash ) { global $wpdb; $wpdb->delete( fisha_studio_log_table(), array( 'id' => $trash ) ); } );
	fs_eq( 'fisha_studio_not_restorable', fisha_studio_restore( 'product', $p->get_id(), $trash )->get_error_code() );
	$other = fs_product();
	fs_eq( 'fisha_studio_not_restorable', fisha_studio_restore( 'product', $other->get_id(), $log )->get_error_code(), 'row of another item' );
} );

fs_test( 'restore: a category version, a different parent is not restored', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t   = fs_cat( 'restore-cat' );
	$v   = fisha_studio_category_values( $t );
	$log = fs_snapshot( 'category', $t->term_id, array_merge( $v, array( 'lead' => 'Older intro.', 'parent' => 999999 ) ) );
	$res = fisha_studio_restore( 'category', $t->term_id, $log );
	fs_eq( array( 'The address is not restored. It stays as it is now.' ), $res['skipped'] );
	fs_eq( 'Older intro.', fisha_studio_draft_get( $t->term_id, 'term' )['fields']['lead'] );
} );

fs_test( 'restore: a never-published product takes the values directly', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fisha_studio_product_create( 'zz-test restore new' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	$v   = fisha_studio_product_values( $p );
	$log = fs_snapshot( 'product', $p->get_id(), array_merge( $v, array( 'regular_price' => '55' ) ) );
	fisha_studio_restore( 'product', $p->get_id(), $log );
	fs_eq( '55', wc_get_product( $p->get_id() )->get_regular_price( 'edit' ) );
} );

fs_test( 'trash: a trashed product is listed and comes back as a draft with its sizes', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_variable( array( 's', 'm' ) );
	fisha_studio_trash_product( $p->get_id() );
	fs_true( in_array( $p->get_id(), wp_list_pluck( fisha_studio_trash_list(), 'id' ), true ) );
	$back = fisha_studio_untrash_product( $p->get_id() );
	fs_false( is_wp_error( $back ) );
	fs_eq( 'draft', get_post_status( $p->get_id() ) );
	fs_eq( 'publish', get_post_status( fs_var_id( $p, 'm' ) ), 'sizes back' );
	fs_false( in_array( $p->get_id(), wp_list_pluck( fisha_studio_trash_list(), 'id' ), true ) );
	fs_eq( 'untrash', fisha_studio_log_for( 'product', $p->get_id(), 1 )[0]['action'] );
	fs_eq( 'fisha_studio_not_found', fisha_studio_untrash_product( fs_product()->get_id() )->get_error_code(), 'not in trash' );
} );

fs_test( 'restore: REST restore returns the payload and skipped list', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p   = fs_product();
	$log = fs_snapshot( 'product', $p->get_id(), array_merge( fisha_studio_product_values( $p ), array( 'regular_price' => '88' ) ) );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/restore', array( 'log_id' => $log ) );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 'changed', $d['state'] );
	fs_eq( '88', $d['values']['regular_price'] );
	fs_eq( array(), $d['skipped'] );
} );

/* ---------- final review fixes: stock, SKU and address are never restored; Bring back keeps Hidden ---------- */

define( 'FS_RESTORE_KEEP_NOTE', 'Stock, SKU and address are not restored. They stay as they are now.' );

fs_test( 'restore fix I1: stock, SKU, address and order stay as they are now, with one note', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 2, 'sku' => 'ZZ-RST-' . wp_rand( 1000, 9999 ), 'menu_order' => 3 ) );
	$live = fisha_studio_product_values( $p );
	$old  = array_merge( $live, array( 'description' => '<p>Older text</p>', 'stock_quantity' => 10, 'sku' => 'ZZ-OLD-' . wp_rand( 1000, 9999 ), 'slug' => $live['slug'] . '-old', 'menu_order' => 7, 'manage_stock' => false, 'stock_status' => 'outofstock' ) );
	$res  = fisha_studio_restore( 'product', $p->get_id(), fs_snapshot( 'product', $p->get_id(), $old ) );
	fs_false( is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	$f = fisha_studio_draft_get( $p->get_id() )['fields'];
	fs_eq( array( 'description' ), array_keys( $f ), 'only the description goes to the draft' );
	fs_eq( array( FS_RESTORE_KEEP_NOTE ), $res['skipped'] );
} );

fs_test( 'restore fix I1: a size keeps its stock and SKU, its price comes back', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p  = fs_variable( array( 's', 'm' ) );
	$vm = fs_var_id( $p, 'm' );
	$v  = wc_get_product( $vm );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 2 );
	$v->save();
	$p    = wc_get_product( $p->get_id() );
	$live = fisha_studio_product_values( $p );
	$old  = $live;
	$old[ "var:$vm:stock_quantity" ] = 10;
	$old[ "var:$vm:stock_status" ]   = 'outofstock';
	$old[ "var:$vm:manage_stock" ]   = false;
	$old[ "var:$vm:sku" ]            = 'ZZ-OLD-' . wp_rand( 1000, 9999 );
	$old[ "var:$vm:regular_price" ]  = '77';
	$res = fisha_studio_restore( 'product', $p->get_id(), fs_snapshot( 'product', $p->get_id(), $old ) );
	fs_false( is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	fs_eq( array( "var:$vm:regular_price" ), array_keys( fisha_studio_draft_get( $p->get_id() )['fields'] ) );
	fs_eq( array( FS_RESTORE_KEEP_NOTE ), $res['skipped'] );
} );

fs_test( 'restore fix I1: a version that differs only in stock is refused', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p   = fs_product( array( 'manage_stock' => true, 'stock_quantity' => 2 ) );
	$res = fisha_studio_restore( 'product', $p->get_id(), fs_snapshot( 'product', $p->get_id(), array_merge( fisha_studio_product_values( $p ), array( 'stock_quantity' => 10 ) ) ) );
	fs_eq( 'fisha_studio_same', is_wp_error( $res ) ? $res->get_error_code() : 'ok' );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
} );

fs_test( 'restore fix I1: a category keeps its address and parent, with one note', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'restore-top' );
	$t   = fs_cat( 'restore-cat2' );
	$v   = fisha_studio_category_values( $t );
	$log = fs_snapshot( 'category', $t->term_id, array_merge( $v, array( 'lead' => 'Older intro.', 'slug' => $v['slug'] . '-old', 'parent' => $top->term_id ) ) );
	$res = fisha_studio_restore( 'category', $t->term_id, $log );
	fs_false( is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	fs_eq( array( 'lead' ), array_keys( fisha_studio_draft_get( $t->term_id, 'term' )['fields'] ) );
	fs_eq( array( 'The address is not restored. It stays as it is now.' ), $res['skipped'] );
} );

fs_test( 'untrash fix I2: a Hidden product comes back Hidden, its old draft is gone', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_product( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'untr-' . wp_rand() )->term_id ) ), 'private' );
	update_post_meta( $p->get_id(), FISHA_STUDIO_PUBLISHED_META, '1' );
	fisha_studio_product_edit( $p, array( 'regular_price' => '1' ) );
	fs_true( (bool) fisha_studio_draft_get( $p->get_id() )['fields'], 'a draft before the trash' );
	fisha_studio_trash_product( $p->get_id() );
	$back = fisha_studio_untrash_product( $p->get_id() );
	fs_false( is_wp_error( $back ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'], 'old draft dropped' );
	list( $s, $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 200, $s );
	fs_eq( 'private', $d['values']['status'], 'editor shows Hidden' );
	fs_eq( '100', $d['values']['regular_price'] );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( 'private', get_post_status( $p->get_id() ), 'Publish keeps it hidden' );
	fs_eq( '100', wc_get_product( $p->get_id() )->get_regular_price( 'edit' ), 'stale draft price not applied' );
} );

fs_test( 'untrash fix I2: a Shown product comes back Shown', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_product();
	fisha_studio_trash_product( $p->get_id() );
	fisha_studio_untrash_product( $p->get_id() );
	fs_eq( 'publish', fisha_studio_target_status( wc_get_product( $p->get_id() ) ) );
	fs_eq( 'publish', get_post_meta( $p->get_id(), '_fisha_studio_target_status', true ) );
} );
