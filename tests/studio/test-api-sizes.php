<?php
fs_test( 'api sizes: payload of a product with sizes', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( 200, $st );
	fs_true( $d['editable'] );
	fs_eq( 3, count( $d['variations'] ) );
	fs_eq( get_term_by( 'slug', 's', 'pa_size' )->name, $d['variations'][0]['label'] );
	fs_eq( '100', $d['values'][ "var:$s:regular_price" ] );
	fs_eq( 'pa_size', $d['values']['attributes'][0]['name'] );
} );

fs_test( 'api sizes: autosave a size, bad values reported, other products ignored', function () {
	$p     = fs_variable();
	$other = fs_variable( array( 'm' ) );
	$s     = fs_var_id( $p, 's' );
	$om    = fs_var_id( $other, 'm' );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( "var:$s:regular_price" => '₪140', "var:$s:sale_price" => 'x', "var:$om:regular_price" => '1' ), 'seen' => array( "var:$s:regular_price" => '100' ) ) );
	fs_eq( 200, $st );
	fs_eq( 'changed', $d['state'] );
	fs_eq( '140', $d['values'][ "var:$s:regular_price" ] );
	fs_true( isset( $d['field_errors'][ "var:$s:sale_price" ] ) );
	fs_eq( '100', wc_get_product( $om )->get_regular_price(), 'other product untouched' );
	fs_false( isset( fisha_studio_draft_get( $other->get_id() )['fields'][ "var:$om:regular_price" ] ) );
} );

fs_test( 'api sizes: create missing sizes, delete a new one, keep live ones', function () {
	$p = fs_variable();
	fs_as( FISHA_STUDIO_ROLE );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = (int) get_term_by( 'slug', 'xl', 'pa_size' )->term_id;
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'attributes' => $attrs ) ) );
	list( $st, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/variations/generate' );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_eq( 1, $d['created'] );
	fs_eq( 4, count( $d['variations'] ) );
	$new = end( $d['variations'] );
	fs_true( $new['pending'] );
	list( $st, $d ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/variations/' . $new['id'] );
	fs_eq( 200, $st );
	fs_eq( 3, count( $d['variations'] ) );
	list( $st ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/variations/' . fs_var_id( $p, 's' ) );
	fs_eq( 409, $st, 'a live size cannot be deleted' );
} );

fs_test( 'api sizes: new product with an empty name, type switch, publish', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'POST', '/products', array( 'name' => '' ) );
	fs_eq( 201, $st );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	fs_eq( 'simple', $d['type'] );
	list( $st, $d ) = fs_api( 'POST', "/products/$id/type", array( 'type' => 'variable' ) );
	fs_eq( 200, $st );
	fs_eq( 'variable', $d['type'] );
	$attrs = array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( get_term_by( 'slug', 's', 'pa_size' )->term_id ), 'variation' => true, 'visible' => true ) );
	fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'name' => 'zz-test Api Hoodie', 'attributes' => $attrs, 'short_description' => '<p>Warm.</p>', 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'api-sz' )->term_id ) ) ) );
	list( , $d ) = fs_api( 'POST', "/products/$id/variations/generate" );
	$vid = $d['variations'][0]['id'];
	fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( "var:$vid:regular_price" => '199' ) ) );
	list( $st, $d ) = fs_api( 'POST', "/products/$id/publish" );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_eq( 'live', $d['state'] );
	list( $st ) = fs_api( 'POST', "/products/$id/type", array( 'type' => 'simple' ) );
	fs_eq( 409, $st, 'live products keep their type' );
} );

fs_test( 'api sizes: discard removes new sizes; list rows describe sizes', function () {
	$p = fs_variable( array( 's', 'm' ) );
	fs_as( FISHA_STUDIO_ROLE );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	list( , $d ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/draft' );
	fs_eq( 'live', $d['state'] );
	fs_eq( null, get_post( $v->get_id() ) );
	list( , $list ) = fs_api( 'GET', '/products', array( 'search' => $p->get_name() ) );
	fs_true( $list['items'][0]['sizes'] );
	fs_eq( '2 of 2 in stock', $list['items'][0]['stock_text'] );
} );

fs_test( 'api sizes: lookups include the shop-wide options', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/lookups' );
	fs_true( in_array( 'pa_size', wp_list_pluck( $d['attributes'], 'name' ), true ) );
} );

fs_test( 'api sizes: a duplicate SKU on a never-published product is a field error, not a 500', function () {
	$taken = new WC_Product_Simple();
	$taken->set_name( 'zz-test sku holder' );
	$taken->set_sku( 'zz-test-dup-sku' );
	$taken->set_status( 'publish' );
	$tid = $taken->save();
	fs_on_cleanup( function () use ( $tid ) { wp_delete_post( $tid, true ); } );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'POST', '/products', array( 'name' => 'zz-test Dup Sku' ) );
	fs_eq( 201, $st );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	list( $st, $d ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'sku' => 'zz-test-dup-sku' ) ) );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_true( isset( $d['field_errors']['sku'] ), 'sku reported' );
	fs_eq( '', wc_get_product( $id )->get_sku(), 'sku not saved' );
} );
