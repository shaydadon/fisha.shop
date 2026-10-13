<?php
function fs_new_option( string $label ): array {
	list( $st, $d ) = fs_api( 'POST', '/attributes', array( 'label' => $label ) );
	if ( 201 !== $st ) throw new Fs_Fail( 'could not create option: ' . wp_json_encode( $d ) );
	fs_on_cleanup( function () use ( $d ) { wc_delete_attribute( $d['id'] ); } );
	return $d;
}

fs_test( 'api attributes: list with how many sizes use each value', function () {
	fs_variable( array( 's' ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $d ) = fs_api( 'GET', '/attributes' );
	fs_eq( 200, $st );
	$size = current( array_filter( $d, function ( $a ) { return 'pa_size' === $a['name']; } ) );
	$s    = current( array_filter( $size['terms'], function ( $t ) { return 's' === $t['slug']; } ) );
	fs_true( $s['sizes'] >= 1, 'S is used by sizes' );
	fs_as( 'customer' );
	list( $st ) = fs_api( 'GET', '/attributes' );
	fs_eq( 403, $st );
} );

fs_test( 'api attributes: a new option with values, renamed and reordered', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$opt = fs_new_option( 'zz-test Fit' );
	$ids = array();
	foreach ( array( 'Zed', 'Mid', 'Alpha' ) as $name ) {
		list( $st, $t ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => $name ) );
		fs_eq( 200, $st, wp_json_encode( $t ) );
		$ids[] = $t['id'];
	}
	list( , $again ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => 'Zed' ) );
	fs_eq( $ids[0], $again['id'], 'no duplicate value' );
	list( , $all ) = fs_api( 'GET', '/attributes' );
	$mine = current( array_filter( $all, function ( $a ) use ( $opt ) { return $a['id'] === $opt['id']; } ) );
	fs_eq( array( 'Zed', 'Mid', 'Alpha' ), wp_list_pluck( $mine['terms'], 'name' ), 'new values go last' );
	list( , $d ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/order', array( 'term_ids' => array( $ids[1], $ids[0], $ids[2] ) ) );
	fs_eq( array( 'Mid', 'Zed', 'Alpha' ), wp_list_pluck( $d['terms'], 'name' ) );
	list( , $t ) = fs_api( 'PUT', '/attributes/terms/' . $ids[0], array( 'name' => 'Zedd' ) );
	fs_eq( 'Zedd', $t['name'] );
	fs_eq( 'zed', $t['slug'], 'the slug stays, so sizes keep working' );
	list( , $d ) = fs_api( 'PUT', '/attributes/' . $opt['id'], array( 'label' => 'zz-test Cut' ) );
	fs_eq( 'zz-test Cut', $d['label'] );
	fs_eq( $opt['name'], $d['name'], 'option slug unchanged' );
} );

fs_test( 'api attributes: values in use cannot be deleted, unused ones can', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$p = fs_variable( array( 's' ) );
	list( $st, $d ) = fs_api( 'DELETE', '/attributes/terms/' . get_term_by( 'slug', 's', 'pa_size' )->term_id );
	fs_eq( 409, $st );
	fs_eq( 'fisha_studio_in_use', $d['code'] );
	fs_true( in_array( $p->get_name(), $d['data']['products'], true ) || count( $d['data']['products'] ) === 10, 'names the products' );
	$opt = fs_new_option( 'zz-test Gone' );
	list( , $t ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => 'Unused' ) );
	list( $st, $d ) = fs_api( 'DELETE', '/attributes/terms/' . $t['id'] );
	fs_eq( 200, $st );
	fs_eq( null, get_term( $t['id'] ) );
} );

fs_test( 'api attributes: bad names are refused', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $st ) = fs_api( 'POST', '/attributes', array( 'label' => '' ) );
	fs_eq( 422, $st );
	list( $st ) = fs_api( 'POST', '/attributes', array( 'label' => str_repeat( 'x', 29 ) ) );
	fs_eq( 422, $st );
	list( $st ) = fs_api( 'PUT', '/attributes/terms/' . fs_term( 'not an option value' )->term_id, array( 'name' => 'x' ) );
	fs_eq( 404, $st, 'only option values can be edited here' );
} );

fs_test( 'api attributes: a draft size using a value still blocks deleting it', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$opt = fs_new_option( 'zz-test Draft' );
	list( , $t ) = fs_api( 'POST', '/attributes/' . $opt['id'] . '/terms', array( 'name' => 'Held' ) );
	$p = fs_variable( array( 's' ) );
	$v = wp_insert_post( array( 'post_type' => 'product_variation', 'post_parent' => $p->get_id(), 'post_status' => 'publish', 'post_title' => 'zz-test draft size' ) );
	update_post_meta( $v, 'attribute_' . $opt['name'], $t['slug'] );
	wp_update_post( array( 'ID' => $v, 'post_status' => 'draft' ) );
	list( $st, $d ) = fs_api( 'DELETE', '/attributes/terms/' . $t['id'] );
	fs_eq( 409, $st, wp_json_encode( $d ) );
	fs_eq( 'fisha_studio_in_use', $d['code'] );
	fs_true( null !== get_term( $t['id'] ) && ! is_wp_error( get_term( $t['id'] ) ), 'value still exists' );
} );
