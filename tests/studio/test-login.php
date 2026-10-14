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

// Changed with 2-step sign-in: for an owner the right password alone no longer clears the counters (else wrong codes
// could be guessed without end); the finished code step does. An admin's right password still clears them.
fs_test( 'login: success clears earlier failures (owner: only after the code step)', function () {
	add_filter( 'send_auth_cookies', '__return_false' );
	fs_on_cleanup( function () { remove_filter( 'send_auth_cookies', '__return_false' ); unset( $_COOKIE['fisha_studio_2fa'] ); } );
	$u      = fs_owner_with_password( 'right-password-123' );
	$secret = random_bytes( 20 );
	fisha_studio_2fa_enable( $u->ID, $secret );
	fs_on_cleanup( function () use ( $u ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'fisha_studio_log', array( 'item_type' => 'user', 'item_id' => $u->ID ) ); } );
	$ip = fs_ip();
	for ( $i = 0; $i < 4; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	$r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, 'zz-test-agent' );
	fs_eq( 4, fisha_studio_login_count( fisha_studio_login_key( 'u', fisha_studio_login_account( $u->user_login ) ) ), 'the password alone keeps the count' );
	$done = fisha_studio_2fa_code_step( $r['token'], fisha_studio_totp( $secret, fisha_studio_totp_counter() ), $ip, 'zz-test-agent' );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'signed in with the code' );
	fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	fs_false( fisha_studio_login_locked( $u->user_login, $ip ), 'counter was reset by the code step' );

	$a = fs_user( 'administrator' );
	wp_set_password( 'admin-pass-123', $a->ID );
	fs_on_cleanup( function () use ( $a ) { fisha_studio_login_clear( $a->user_login, '' ); } );
	$ip2 = fs_ip();
	for ( $i = 0; $i < 4; $i++ ) fisha_studio_check_credentials( $a->user_login, 'wrong', $ip2 );
	fisha_studio_check_credentials( $a->user_login, 'admin-pass-123', $ip2 );
	fisha_studio_check_credentials( $a->user_login, 'wrong', $ip2 );
	fs_false( fisha_studio_login_locked( $a->user_login, $ip2 ), 'admin counter was reset by the password' );
} );

fs_test( 'login: import map versions every studio module', function () {
	$map = fisha_studio_import_map();
	fs_true( isset( $map['imports'] ), 'has imports' );
	foreach ( $map['imports'] as $from => $to ) {
		fs_true( 0 === strpos( $to, $from . '?v=' ), "$from is versioned" );
	}
} );

/** Runs $fn as if the request came from $ip (wp-login.php and My Account read REMOTE_ADDR). */
function fs_from_ip( string $ip, callable $fn ) {
	$old = $_SERVER['REMOTE_ADDR'] ?? null;
	$_SERVER['REMOTE_ADDR'] = $ip;
	try {
		return $fn();
	} finally {
		if ( null === $old ) unset( $_SERVER['REMOTE_ADDR'] );
		else $_SERVER['REMOTE_ADDR'] = $old;
	}
}

fs_test( 'login: customers on wp-login/My Account are not counted or locked by the Studio', function () {
	$c = fs_user( 'customer' );
	wp_set_password( 'customer-pass-123', $c->ID );
	$ip = fs_ip();
	fs_on_cleanup( function () use ( $c ) { fisha_studio_login_clear( $c->user_login, '' ); fisha_studio_login_clear( $c->user_email, '' ); } );
	fs_from_ip( $ip, function () use ( $c ) {
		for ( $i = 0; $i < 6; $i++ ) wp_authenticate( $c->user_login, 'wrong' );
	} );
	$r = fs_from_ip( $ip, function () use ( $c ) { return wp_authenticate( $c->user_login, 'customer-pass-123' ); } );
	fs_true( $r instanceof WP_User, 'customer can still sign in: ' . ( is_wp_error( $r ) ? $r->get_error_code() : '' ) );
	fs_false( fisha_studio_login_locked( 'someone-else', $ip ), 'shared IP not locked by customer failures' );
} );

fs_test( 'login: an owner failing on wp-login.php is still locked there', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	fs_from_ip( $ip, function () use ( $u ) {
		for ( $i = 0; $i < 5; $i++ ) wp_authenticate( $u->user_login, 'wrong' );
	} );
	$r = fs_from_ip( fs_ip(), function () use ( $u ) { return wp_authenticate( $u->user_login, 'right-password-123' ); } );
	fs_true( is_wp_error( $r ) && 'fisha_studio_locked' === $r->get_error_code(), 'owner locked on wp-login.php too' );
} );

fs_test( 'login: username and email of one owner share one counter', function () {
	$u  = fs_owner_with_password( 'right-password-123' );
	$ip = fs_ip();
	for ( $i = 0; $i < 3; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', $ip );
	for ( $i = 0; $i < 2; $i++ ) fisha_studio_check_credentials( $u->user_email, 'wrong', fs_ip() );
	$r = fisha_studio_check_credentials( $u->user_login, 'right-password-123', fs_ip() );
	fs_true( is_wp_error( $r ), 'not let in' );
	fs_eq( 'fisha_studio_locked', $r->get_error_code(), 'five tries across username and email lock the account' );
} );

fs_test( 'login: the lock ends 15 minutes after the first failure, later failures do not extend it', function () {
	$u   = fs_owner_with_password( 'right-password-123' );
	$key = fisha_studio_login_key( 'u', fisha_studio_login_account( $u->user_login ) );
	$age = function ( int $seconds ) use ( $key ) {
		$rec          = get_transient( $key );
		$rec['first'] = time() - $seconds;
		set_transient( $key, $rec, HOUR_IN_SECONDS );
	};
	for ( $i = 0; $i < 4; $i++ ) fisha_studio_check_credentials( $u->user_login, 'wrong', fs_ip() );
	$age( 14 * MINUTE_IN_SECONDS );
	fisha_studio_check_credentials( $u->user_login, 'wrong', fs_ip() );                    // fifth failure, 14 min after the first
	fs_true( fisha_studio_login_locked( $u->user_login, '' ), 'locked after five' );
	$age( 15 * MINUTE_IN_SECONDS + 1 );
	fs_false( fisha_studio_login_locked( $u->user_login, '' ), 'unlocked 15 min after the first failure' );
	$r = fisha_studio_check_credentials( $u->user_login, 'right-password-123', fs_ip() );
	fs_true( $r instanceof WP_User, 'can sign in again' );
} );
