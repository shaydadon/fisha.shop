<?php
fs_test( 'api categories: create, edit, publish', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'POST', '/categories', array() );
	fs_eq( 201, $s );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'fisha_studio_log', array( 'item_type' => 'category', 'item_id' => $id ) ); wp_delete_term( $id, 'product_cat' ); } );
	fs_eq( 'new', $d['state'] );
	fs_eq( 'New category', substr( $d['values']['name'], 0, 12 ) );
	fs_eq( null, $d['url'] );
	fs_eq( array( 'item', 'shop', 'home' ), wp_list_pluck( $d['previews'], 'key' ) );
	list( $s, $d ) = fs_api( 'PUT', "/categories/$id/draft", array( 'fields' => array( 'name' => 'zz-test Api Cat', 'lead' => 'Intro here.', 'hero_ids' => array( fs_image() ), 'parent' => 'abc' ) ) );
	fs_eq( 200, $s );
	fs_eq( 'zz-test Api Cat', $d['values']['name'] );
	fs_true( isset( $d['field_errors']['parent'] ), 'bad parent reported' );
	fs_eq( home_url( '/product-category/' ), $d['base_url'] );
	list( $s, $d ) = fs_api( 'POST', "/categories/$id/publish" );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 'live', $d['state'] );
	fs_eq( home_url( '/product-category/zz-test-api-cat/' ), $d['published_url'] );
} );

fs_test( 'api categories: list rows say why a category is not on the site', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$on    = fs_cat( 'row-on' );
	fs_product( array( 'category_ids' => array( $on->term_id ) ) );
	$empty = fs_cat( 'row-empty' );
	$off   = fs_cat( 'row-off', 0, false );
	$kid   = fs_cat( 'row-kid', $off->term_id );
	$new   = fs_studio_cat( 'zz-test Row New' );
	fisha_cat_cache_reset();
	list( $s, $d ) = fs_api( 'GET', '/categories' );
	fs_eq( 200, $s );
	$by = array_column( $d['items'], null, 'id' );
	fs_eq( '', $by[ $on->term_id ]['off_reason'] );
	fs_eq( 1, $by[ $on->term_id ]['count'] );
	fs_eq( 'empty', $by[ $empty->term_id ]['off_reason'] );
	fs_eq( 'hidden', $by[ $off->term_id ]['off_reason'] );
	fs_eq( 'parent', $by[ $kid->term_id ]['off_reason'] );
	fs_eq( 'new', $by[ $new->term_id ]['off_reason'] );
} );

fs_test( 'api categories: shown, order and delete', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'api-top' );
	$a = fs_cat( 'api-a', $top->term_id );
	$b = fs_cat( 'api-b', $top->term_id );
	list( $s, $d ) = fs_api( 'POST', "/categories/{$a->term_id}/shown", array( 'shown' => false ) );
	fs_eq( 200, $s );
	fs_false( $d['shown'] );
	list( $s ) = fs_api( 'POST', '/categories/order', array( 'parent' => $top->term_id, 'ids' => array( $b->term_id, $a->term_id ) ) );
	fs_eq( 200, $s );
	fs_eq( '1', get_term_meta( $b->term_id, 'order', true ) );
	$before = get_option( 'fisha_deleted_cat_paths', null );
	fs_on_cleanup( function () use ( $before ) { null === $before ? delete_option( 'fisha_deleted_cat_paths' ) : update_option( 'fisha_deleted_cat_paths', $before, false ); } );
	list( $s, $d ) = fs_api( 'DELETE', "/categories/{$a->term_id}", array( 'move_to' => 0 ) );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_false( (bool) get_term( $a->term_id, 'product_cat' ) );
} );

fs_test( 'api categories: someone without the Studio role is refused', function () {
	fs_as( 'customer' );
	list( $s ) = fs_api( 'GET', '/categories' );
	fs_eq( 403, $s );
} );

fs_test( 'api categories: lookups label never-published categories; a product published into one gets a warning', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$new = fs_studio_cat( 'zz-test Lookup New' );
	list( , $l ) = fs_api( 'GET', '/lookups' );
	$by = array_column( $l['categories'], null, 'id' );
	fs_eq( 'zz-test Lookup New (not published yet)', $by[ $new->term_id ]['name'] );
	$p = fs_product( array( 'category_ids' => array( $new->term_id ), 'image_id' => fs_image() ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '120' ) );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish' );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 1, count( $d['warnings'] ) );
} );
