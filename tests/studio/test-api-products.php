<?php
function fs_ready_product( array $props = array() ): WC_Product_Simple {
	return fs_product( array_merge( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'api-' . wp_rand() )->term_id ) ), $props ) );
}

fs_test( 'api products: list, search by name and sku, filters', function () {
	$p = fs_ready_product( array( 'sku' => 'ZZ-TEST-LIST-' . wp_rand( 100, 999 ), 'manage_stock' => true, 'stock_quantity' => 0 ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/products', array( 'search' => $p->get_name() ) );
	fs_eq( 200, $s );
	fs_eq( array( $p->get_id() ), wp_list_pluck( $d['items'], 'id' ) );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => $p->get_sku() ) );
	fs_true( in_array( $p->get_id(), wp_list_pluck( $d['items'], 'id' ), true ), 'found by SKU' );
	list( , $d ) = fs_api( 'GET', '/products', array( 'stock' => 'outofstock', 'per_page' => 100 ) );
	fs_true( in_array( $p->get_id(), wp_list_pluck( $d['items'], 'id' ), true ), 'sold-out filter' );
	fisha_studio_product_edit( $p, array( 'regular_price' => '1' ) );
	list( , $d ) = fs_api( 'GET', '/products', array( 'state' => 'changed', 'per_page' => 100 ) );
	fs_true( in_array( $p->get_id(), wp_list_pluck( $d['items'], 'id' ), true ), 'changed filter' );
} );

fs_test( 'api products: create, edit (with a bad field), publish', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'POST', '/products', array( 'name' => 'zz-test Api Created' ) );
	fs_eq( 201, $s );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_eq( 'new', $d['state'] );
	fs_eq( 'publish', $d['values']['status'], 'new items default to Live' );

	list( $s, $d ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'regular_price' => '₪95', 'sale_price' => 'abc', 'short_description' => '<p>Hi</p>', 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'api-create' )->term_id ) ) ) );
	fs_eq( 200, $s );
	fs_eq( '95', $d['values']['regular_price'], 'valid field saved' );
	fs_true( isset( $d['field_errors']['sale_price'] ), 'bad field reported' );

	list( $s, $d ) = fs_api( 'POST', "/products/$id/publish" );
	fs_eq( 200, $s, wp_json_encode( $d ) );
	fs_eq( 'live', $d['state'] );
	fs_eq( home_url( '/product/zz-test-api-created/' ), $d['published_url'] );
} );

fs_test( 'api products: payload of a live product', function () {
	$p = fs_ready_product();
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 200, $s );
	fs_eq( 'live', $d['state'] );
	fs_true( $d['editable'] );
	fs_eq( array_keys( fisha_studio_product_fields() ), array_keys( $d['values'] ) );
	fs_eq( array( 'item', 'category', 'shop', 'home' ), wp_list_pluck( $d['previews'], 'key' ) );
	fs_true( isset( $d['images'][ $p->get_image_id() ] ), 'image payloads included' );
	list( $s ) = fs_api( 'GET', '/products/99999999' );
	fs_eq( 404, $s );
} );

fs_test( 'api products: conflict comes back as 409 with the details', function () {
	$p = fs_ready_product( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	fs_as( FISHA_STUDIO_ROLE );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'stock_quantity' => 5 ) ) );
	wc_update_product_stock( $p->get_id(), 2, 'set' );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish' );
	fs_eq( 409, $s );
	fs_eq( 2, $d['data']['conflicts'][0]['live'] );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish', array( 'resolutions' => array( 'stock_quantity' => 'mine' ) ) );
	fs_eq( 200, $s );
	fs_eq( 5, wc_get_product( $p->get_id() )->get_stock_quantity() );
} );

fs_test( 'api products: quick edit publishes at once, but not over pending changes', function () {
	$p = fs_ready_product( array( 'regular_price' => '100' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'regular_price' => '105', 'name' => 'ignored' ) ) );
	fs_eq( 200, $s );
	fs_eq( '105', $row['regular_price'] );
	fs_true( 'ignored' !== wc_get_product( $p->get_id() )->get_name(), 'only price/stock allowed' );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'meta_line' => 'pending' ) ) );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'regular_price' => '110' ) ) );
	fs_eq( 409, $s );
	fs_eq( 'fisha_studio_has_draft', $d['code'] );
} );

fs_test( 'api products: discard and trash', function () {
	$p = fs_ready_product();
	fs_as( FISHA_STUDIO_ROLE );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'regular_price' => '7' ) ) );
	list( $s, $d ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/draft' );
	fs_eq( 'live', $d['state'] );
	list( $s ) = fs_api( 'POST', '/products/' . $p->get_id() . '/trash' );
	fs_eq( 200, $s );
	fs_eq( 'trash', get_post_status( $p->get_id() ) );
	list( $s ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 404, $s, 'trashed products are gone from the Studio' );
} );

fs_test( 'api products: variable products are read-only for now', function () {
	$v = new WC_Product_Variable();
	$v->set_name( 'zz-test api variable' );
	$v->set_status( 'publish' );
	$id = $v->save();
	fs_on_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', "/products/$id" );
	fs_eq( 200, $s );
	fs_false( $d['editable'] );
	list( $s ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'name' => 'x' ) ) );
	fs_eq( 400, $s );
} );

fs_test( 'api products: sku search finds the parent of a variation and respects the category filter', function () {
	$a = fs_term( 'api-var-a' )->term_id;
	$b = fs_term( 'api-var-b' )->term_id;
	$v = new WC_Product_Variable();
	$v->set_name( 'zz-test api sku parent' );
	$v->set_status( 'publish' );
	$v->set_category_ids( array( $a ) );
	$pid = $v->save();
	fs_on_cleanup( function () use ( $pid ) { wp_delete_post( $pid, true ); } );
	$sku = 'ZZ-TEST-VAR-' . wp_rand( 1000, 9999 );
	$c   = new WC_Product_Variation();
	$c->set_parent_id( $pid );
	$c->set_sku( $sku );
	$vid = $c->save();
	fs_on_cleanup( function () use ( $vid ) { wp_delete_post( $vid, true ); } );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => $sku ) );
	$ids = wp_list_pluck( $d['items'], 'id' );
	fs_true( in_array( $pid, $ids, true ), 'parent found' );
	fs_false( in_array( $vid, $ids, true ), 'no variation row' );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => $sku, 'category' => $b ) );
	fs_false( in_array( $pid, wp_list_pluck( $d['items'], 'id' ), true ), 'category filter respected' );
} );

fs_test( 'api products: quick edit refuses when stock moved since the list loaded (seen)', function () {
	$p = fs_ready_product( array( 'manage_stock' => true, 'stock_quantity' => 1 ) );
	fs_as( FISHA_STUDIO_ROLE );
	wc_update_product_stock( $p->get_id(), 0, 'set' );                           // a customer bought the last one
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '3' ), 'seen' => array( 'stock_quantity' => 1 ) ) );
	fs_eq( 409, $s, wp_json_encode( $d ) );
	fs_eq( 'fisha_studio_conflict', $d['code'] );
	fs_eq( 'stock_quantity', $d['data']['conflicts'][0]['field'] );
	fs_eq( 1, $d['data']['conflicts'][0]['base'] );
	fs_eq( 0, $d['data']['conflicts'][0]['live'] );
	fs_eq( 3, $d['data']['conflicts'][0]['mine'] );
	fs_eq( 0, wc_get_product( $p->get_id() )->get_stock_quantity(), 'stock not overwritten' );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'], 'nothing saved' );
	list( $s, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '3' ), 'seen' => array( 'stock_quantity' => 0 ) ) );
	fs_eq( 200, $s, 'a fresh seen value goes through' );
	fs_eq( 3, $row['stock_quantity'] );
} );

fs_test( 'api products: draft PUT uses seen as the base, so publish reports the conflict', function () {
	$p = fs_ready_product( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	fs_as( FISHA_STUDIO_ROLE );
	wc_update_product_stock( $p->get_id(), 2, 'set' );                           // order lands before the first edit reaches the server
	list( $s ) = fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'stock_quantity' => 5 ), 'seen' => array( 'stock_quantity' => '3' ) ) );
	fs_eq( 200, $s );
	fs_eq( 3, fisha_studio_draft_get( $p->get_id() )['base']['stock_quantity'], 'base is what the owner saw (cleaned)' );
	list( $s, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish' );
	fs_eq( 409, $s, wp_json_encode( $d ) );
	fs_eq( 3, $d['data']['conflicts'][0]['base'] );
	fs_eq( 2, $d['data']['conflicts'][0]['live'] );
	fs_eq( 2, wc_get_product( $p->get_id() )->get_stock_quantity(), 'live untouched' );
} );

fs_test( 'api products: without seen, draft base and quick edit behave as before', function () {
	$p = fs_ready_product( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	fs_as( FISHA_STUDIO_ROLE );
	wc_update_product_stock( $p->get_id(), 2, 'set' );
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'stock_quantity' => 5 ) ) );
	fs_eq( 2, fisha_studio_draft_get( $p->get_id() )['base']['stock_quantity'], 'base is the live value' );
	fs_api( 'DELETE', '/products/' . $p->get_id() . '/draft' );
	list( $s, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '4' ) ) );
	fs_eq( 200, $s );
	fs_eq( 4, $row['stock_quantity'] );
} );
