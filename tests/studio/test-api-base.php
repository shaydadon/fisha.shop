<?php
fs_test( 'api: signed-out and non-studio users are refused', function () {
	list( $s, $d ) = fs_api( 'GET', '/session' );
	fs_eq( 401, $s );
	fs_eq( 'fisha_studio_signed_out', $d['code'] );
	fs_as( 'customer' );
	list( $s, $d ) = fs_api( 'GET', '/lookups' );
	fs_eq( 403, $s );
} );

fs_test( 'api: session returns the user and a nonce', function () {
	$u = fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/session' );
	fs_eq( 200, $s );
	fs_eq( $u->ID, $d['user']['id'] );
	fs_true( strlen( $d['nonce'] ) >= 10, 'nonce' );
} );

fs_test( 'api: login refuses a wrong password with a generic message', function () {
	$u = fs_user( FISHA_STUDIO_ROLE );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_login_clear( $u->user_login, fisha_client_ip() ); } );
	list( $s, $d ) = fs_api( 'POST', '/login', array( 'login' => $u->user_login, 'password' => 'wrong' ) );
	fs_eq( 401, $s );
	fs_eq( 'That email/username and password do not match.', $d['message'] );
} );

fs_test( 'api: lookups list categories with paths, palette and the product base url', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$parent = fs_term( 'lk-parent' );
	$child  = fs_term( 'lk-child', $parent->term_id );
	list( $s, $d ) = fs_api( 'GET', '/lookups' );
	fs_eq( 200, $s );
	$row = current( array_filter( $d['categories'], fn( $c ) => $c['id'] === $child->term_id ) );
	fs_eq( 'zz-test lk-parent › zz-test lk-child', $row['path'] );
	fs_eq( home_url( '/product/' ), $d['product_base'] );
	fs_true( is_array( $d['palette'] ) && is_array( $d['series'] ) && is_array( $d['tax_classes'] ) );
} );

fs_test( 'api: adding a tag reuses an existing one', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $a ) = fs_api( 'POST', '/tags', array( 'name' => 'zz-test-tag-api' ) );
	fs_eq( 200, $s );
	fs_on_cleanup( function () use ( $a ) { wp_delete_term( $a['id'], 'product_tag' ); } );
	list( , $b ) = fs_api( 'POST', '/tags', array( 'name' => 'zz-test-tag-api' ) );
	fs_eq( $a['id'], $b['id'] );
	list( $s ) = fs_api( 'POST', '/tags', array( 'name' => '' ) );
	fs_eq( 422, $s );
} );
