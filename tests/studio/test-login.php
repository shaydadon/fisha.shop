<?php
function fs_ip(): string {
	$ip = '203.0.113.' . wp_rand( 1, 254 );
	fs_on_cleanup( function () use ( $ip ) { fisha_studio_login_clear( '', $ip ); } );
	return $ip;
}
function fs_owner_with_password( string $pw ): WP_User {
	$u = fs_user( FISHA_STUDIO_ROLE );
	wp_set_password( $pw, $u->ID );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_login_clear( $u->user_login, '' ); } );
	return get_user_by( 'id', $u->ID );
}

fs_test( 'login: owner with the right password gets in', function () {
	$u = fs_owner_with_password( 'right-password-123' );
	$r = fisha_studio_check_credentials( $u->user_login, 'right-password-123', fs_ip() );
	fs_true( $r instanceof WP_User, 'returns the user' );
	fs_eq( $u->ID, $r->ID );
} );

fs_test( 'login: wrong password and non-studio users get the same generic error', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	$r  = fisha_studio_check_credentials( $u->user_login, 'nope', $ip );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	$c = fs_user( 'customer' );
	wp_set_password( 'customer-pass-123', $c->ID );
	$r2 = fisha_studio_check_credentials( $c->user_login, 'customer-pass-123', $ip );
	fs_eq( 'fisha_studio_invalid', $r2->get_error_code() );
	fs_eq( $r->get_error_message(), $r2->get_error_message(), 'same message, no hint which part was wrong' );
} );

fs_test( 'login: five failures lock the account even with the right password', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	for ( $i = 0; $i < 5; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	$r = fisha_studio_check_credentials( $u->user_login, 'right-password-123', fs_ip() );
	fs_eq( 'fisha_studio_locked', $r->get_error_code(), 'locked by login from another IP' );
	fs_true( fisha_studio_login_locked( 'someone-else', $ip ), 'the IP is locked too' );
} );

fs_test( 'login: success clears earlier failures', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	for ( $i = 0; $i < 4; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	fisha_studio_check_credentials( $u->user_login, 'right-password-123', $ip );
	fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	fs_false( fisha_studio_login_locked( $u->user_login, $ip ), 'counter was reset' );
} );

fs_test( 'login: import map versions every studio module', function () {
	$map = fisha_studio_import_map();
	fs_true( isset( $map['imports'] ), 'has imports' );
	foreach ( $map['imports'] as $from => $to ) {
		fs_true( 0 === strpos( $to, $from . '?v=' ), "$from is versioned" );
	}
} );
