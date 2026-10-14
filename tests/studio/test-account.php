<?php
/**
 * My account: change password (this browser stays signed in), new backup codes, sign out of other devices.
 */
function fs_account_owner( string $pw ): WP_User {
	$u = fs_user( FISHA_STUDIO_ROLE );
	wp_set_password( $pw, $u->ID );
	wp_set_current_user( $u->ID );
	fs_on_cleanup( function () use ( $u ) {
		global $wpdb;
		fisha_studio_login_clear( $u->user_login, '10.9.8.7' );
		$wpdb->delete( fisha_studio_log_table(), array( 'item_type' => 'user', 'item_id' => $u->ID ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	} );
	return get_user_by( 'id', $u->ID );
}

fs_test( 'account: password rules give field errors', function () {
	$u = fs_account_owner( 'Current-pass-123' );
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'short', 'short', '10.9.8.7' );
	fs_true( isset( $e->get_error_data()['fields']['new'] ) );
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'Long-enough-pass-1', 'Different-pass-12', '10.9.8.7' );
	fs_true( isset( $e->get_error_data()['fields']['confirm'] ) );
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'Current-pass-123', 'Current-pass-123', '10.9.8.7' );
	fs_true( isset( $e->get_error_data()['fields']['new'] ), 'same as current' );
} );

fs_test( 'account: a wrong current password counts toward the lock', function () {
	$u = fs_account_owner( 'Current-pass-123' );
	for ( $i = 0; $i < 5; $i++ ) {
		$e = fisha_studio_change_password( $u, 'wrong', 'Long-enough-pass-1', 'Long-enough-pass-1', '10.9.8.7' );
		fs_true( isset( $e->get_error_data()['fields']['current'] ), "try $i" );
	}
	$e = fisha_studio_change_password( $u, 'Current-pass-123', 'Long-enough-pass-1', 'Long-enough-pass-1', '10.9.8.7' );
	fs_eq( 429, $e->get_error_data()['status'], 'locked even with the right password' );
} );

fs_test( 'account: changing the password keeps this session and returns a fresh nonce', function () {
	$u = fs_account_owner( 'Current-pass-123' );
	$old = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$sent = 0;
	// Runs after the Studio guard: counts cookies it let through, then never really sends from the CLI.
	$count = function ( $send, $expire = 0, $expiration = 0, $uid = 0 ) use ( &$sent, $u ) { if ( (int) $uid === $u->ID && $send ) $sent++; return false; };
	add_filter( 'send_auth_cookies', $count, PHP_INT_MAX, 4 );
	fs_on_cleanup( function () use ( $count ) { remove_filter( 'send_auth_cookies', $count, PHP_INT_MAX ); } );
	fs_true( true === fisha_studio_change_password( $u, 'Current-pass-123', 'Long-enough-pass-1', 'Long-enough-pass-1', '10.9.8.7' ) );
	fs_true( wp_check_password( 'Long-enough-pass-1', get_user_by( 'id', $u->ID )->user_pass, $u->ID ) );
	fs_false( (bool) WP_Session_Tokens::get_instance( $u->ID )->get( $old ), 'older session ended' );
	fs_eq( 1, count( WP_Session_Tokens::get_instance( $u->ID )->get_all() ), 'exactly this new session' );
	fs_eq( 1, $sent, 'the owner cookie was allowed through the guard once' );
	fs_eq( $u->ID, get_current_user_id() );
	fs_eq( 'password-change', fisha_studio_log_for( 'user', $u->ID, 1 )[0]['action'] );
	// The fresh REST nonce belongs to the new session: the mirrored logged-in cookie carries the new token.
	$parsed = wp_parse_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ] ?? '', 'logged_in' );
	fs_true( $parsed && WP_Session_Tokens::get_instance( $u->ID )->get( $parsed['token'] ), 'cookie token is the new session' );
	fs_eq( $parsed['token'], wp_get_session_token() );
	fs_eq( 1, wp_verify_nonce( wp_create_nonce( 'wp_rest' ), 'wp_rest' ), 'fresh nonce is valid for the new session' );
} );

fs_test( 'account: new backup codes need a valid app code and replace the old ones', function () {
	$u = fs_account_owner( 'Current-pass-123' );
	$secret = random_bytes( 20 );
	fisha_studio_2fa_enable( $u->ID, $secret );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_2fa_reset( $u->ID ); } );
	$old = fisha_studio_2fa_new_backup_codes( $u->ID );
	fs_true( is_wp_error( fisha_studio_new_backup_codes_checked( $u, '000000', '10.9.8.7' ) ) );
	fs_true( is_wp_error( fisha_studio_new_backup_codes_checked( $u, $old[0], '10.9.8.7' ) ), 'a backup code is not accepted here' );
	$codes = fisha_studio_new_backup_codes_checked( $u, fisha_studio_totp( $secret, fisha_studio_totp_counter() ), '10.9.8.7' );
	fs_eq( 10, count( $codes ) );
	fs_false( fisha_studio_2fa_backup_use( $u->ID, $old[1] ), 'old code no longer works' );
	fs_true( fisha_studio_2fa_backup_use( $u->ID, $codes[0] ) );
} );

fs_test( 'account fix: wrong app codes for new backup codes count toward the 20-per-day limit', function () {
	$u = fs_account_owner( 'Current-pass-123' );
	$secret = random_bytes( 20 );
	fisha_studio_2fa_enable( $u->ID, $secret );
	fs_on_cleanup( function () use ( $u ) { fisha_studio_2fa_reset( $u->ID ); } );
	fisha_studio_new_backup_codes_checked( $u, '000000', '10.9.8.7' );
	fs_eq( 1, fisha_studio_counter_get( 'fs_2fa_day_' . $u->ID, DAY_IN_SECONDS ), 'counted like the sign-in' );
	for ( $i = 0; $i < 18; $i++ ) fisha_studio_counter_incr( 'fs_2fa_day_' . $u->ID, DAY_IN_SECONDS );
	fisha_studio_login_clear( $u->user_login, '10.9.8.7' );
	$e = fisha_studio_new_backup_codes_checked( $u, '000000', '10.9.8.7' );
	fs_eq( 'fisha_studio_2fa_day_lock', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'the 20th wrong code' );
	fs_eq( 429, $e->get_error_data()['status'] );
	fisha_studio_login_clear( $u->user_login, '10.9.8.7' );
	$e = fisha_studio_new_backup_codes_checked( $u, fisha_studio_totp( $secret, fisha_studio_totp_counter() ), '10.9.8.7' );
	fs_eq( 'fisha_studio_2fa_day_lock', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'even the right code' );
} );

fs_test( 'account: info and sign out of other devices', function () {
	$u = fs_account_owner( 'Current-pass-123' );
	$a = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$b = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $u->ID, time() + HOUR_IN_SECONDS, 'logged_in', $a );
	fs_on_cleanup( function () { unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); } );
	fisha_studio_sign_out_others( $u );
	fs_true( (bool) WP_Session_Tokens::get_instance( $u->ID )->get( $a ), 'this one stays' );
	fs_false( (bool) WP_Session_Tokens::get_instance( $u->ID )->get( $b ) );
	$info = fisha_studio_account_info( $u );
	fs_eq( $u->user_login, $info['login'] );
	fs_true( $info['owner'] );
	fs_false( $info['twofa']['enabled'] );
} );

fs_test( 'account: REST is for the signed-in Studio user only', function () {
	fs_as( 'customer' );
	fs_eq( 403, fs_api( 'GET', '/account' )[0] );
	fs_as( FISHA_STUDIO_ROLE );
	list( $s, $d ) = fs_api( 'GET', '/account' );
	fs_eq( 200, $s );
	fs_true( isset( $d['twofa']['backup_left'] ) );
} );
