<?php
/**
 * 2-step sign-in for Studio owners: TOTP, backup codes, pending token, the code step and the closed bypasses.
 * Uses fixture owners only (never the real local test owner).
 */

/** Records whether an auth cookie would be set during this test (and never sends real cookies from the CLI). */
function fs_2fa_watch_cookies(): object {
	$seen = (object) array( 'auth' => 0, 'logged_in' => 0, 'sent' => array() );
	$a    = function () use ( $seen ) { $seen->auth++; };
	$b    = function () use ( $seen ) { $seen->logged_in++; };
	// Runs last: records whether the cookies would really be sent (after the Studio guard), then never sends from the CLI.
	$c    = function ( $send, $expire = 0, $expiration = 0, $user_id = 0 ) use ( $seen ) {
		if ( $user_id ) $seen->sent[] = array( (int) $user_id, (bool) $send );
		return false;
	};
	add_action( 'set_auth_cookie', $a );
	add_action( 'set_logged_in_cookie', $b );
	add_filter( 'send_auth_cookies', $c, PHP_INT_MAX, 4 );
	fs_on_cleanup( function () use ( $a, $b, $c ) {
		remove_action( 'set_auth_cookie', $a );
		remove_action( 'set_logged_in_cookie', $b );
		remove_filter( 'send_auth_cookies', $c, PHP_INT_MAX );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_COOKIE['fisha_studio_2fa'] );
	} );
	return $seen;
}

function fs_2fa_ip(): string {
	$ip = '198.51.100.' . wp_rand( 1, 254 );
	fs_on_cleanup( function () use ( $ip ) { fisha_studio_login_clear( '', $ip ); } );
	return $ip;
}

/** A fixture owner with a password, and (when $enable) 2-step sign-in turned on with a known secret. */
function fs_2fa_owner( bool $enable = true ): array {
	$u = fs_user( FISHA_STUDIO_ROLE );
	wp_set_password( 'right-password-123', $u->ID );
	$secret = random_bytes( 20 );
	if ( $enable ) fisha_studio_2fa_enable( $u->ID, $secret );
	fs_on_cleanup( function () use ( $u ) {
		global $wpdb;
		fisha_studio_login_clear( $u->user_login, '' );
		fisha_studio_counter_clear( 'fs_2fa_day_' . $u->ID );
		$wpdb->delete( $wpdb->prefix . 'fisha_studio_log', array( 'item_type' => 'user', 'item_id' => $u->ID ) );
	} );
	return array( get_user_by( 'id', $u->ID ), $secret );
}

function fs_2fa_code( string $secret, int $offset = 0 ): string {
	return fisha_studio_totp( $secret, (int) floor( time() / 30 ) + $offset );
}

/** Runs $fn as if the request came from $ip. */
function fs_2fa_from_ip( string $ip, callable $fn ) {
	$old = $_SERVER['REMOTE_ADDR'] ?? null;
	$_SERVER['REMOTE_ADDR'] = $ip;
	try {
		return $fn();
	} finally {
		if ( null === $old ) unset( $_SERVER['REMOTE_ADDR'] );
		else $_SERVER['REMOTE_ADDR'] = $old;
	}
}

const FS_2FA_UA = 'zz-test-agent/1.0';

/* ---------- TOTP ---------- */

fs_test( '2fa: RFC 6238 SHA1 vectors (6-digit truncation)', function () {
	$s = '12345678901234567890';
	fs_eq( '287082', fisha_studio_totp( $s, intdiv( 59, 30 ) ) );
	fs_eq( '081804', fisha_studio_totp( $s, intdiv( 1111111109, 30 ) ) );
	fs_eq( '005924', fisha_studio_totp( $s, intdiv( 1234567890, 30 ) ) );
} );

fs_test( '2fa: base32 round trip', function () {
	fs_eq( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', fisha_studio_base32_encode( '12345678901234567890' ) );
	for ( $i = 0; $i < 20; $i++ ) {
		$bin = random_bytes( 20 );
		fs_eq( $bin, fisha_studio_base32_decode( fisha_studio_base32_encode( $bin ) ) );
	}
	fs_eq( '12345678901234567890', fisha_studio_base32_decode( 'gezd gnbv gy3t qojq gezd gnbv gy3t qojq' ), 'spaces and lowercase are fine' );
} );

fs_test( '2fa: verify accepts one step either side, refuses two, refuses a replay', function () {
	list( $u, $s ) = fs_2fa_owner();
	fs_false( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, -2 ) ), 'two steps back' );
	fs_false( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, 2 ) ), 'two steps ahead' );
	fs_true( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, -1 ) ), 'one step back' );
	fs_true( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, 0 ) ), 'now' );
	fs_false( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, 0 ) ), 'same code again is a replay' );
	fs_false( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, -1 ) ), 'an older code after a newer one' );
	fs_true( fisha_studio_totp_verify( $u->ID, fs_2fa_code( $s, 1 ) ), 'one step ahead' );
	fs_false( fisha_studio_totp_verify( $u->ID, 'abcdef' ), 'not digits' );
} );

/* ---------- storage ---------- */

fs_test( '2fa: the secret is stored encrypted and decrypts back', function () {
	list( $u, $s ) = fs_2fa_owner();
	$raw = (string) get_user_meta( $u->ID, '_fisha_2fa_secret', true );
	fs_true( '' !== $raw, 'stored' );
	fs_true( false === strpos( $raw, fisha_studio_base32_encode( $s ) ), 'not the base32 secret' );
	fs_true( false === strpos( $raw, $s ), 'not the raw secret' );
	fs_true( false === strpos( base64_decode( $raw ), $s ), 'not the raw secret inside the base64' );
	fs_eq( $s, fisha_studio_2fa_secret( $u->ID ), 'decrypts back' );
	fs_true( fisha_studio_2fa_enabled( $u->ID ) );
	fs_false( fisha_studio_2fa_enabled( fs_user( FISHA_STUDIO_ROLE )->ID ), 'a new owner has it off' );
} );

fs_test( '2fa: a backup code works once, ignores case, a wrong one fails', function () {
	list( $u ) = fs_2fa_owner();
	$codes     = fisha_studio_2fa_new_backup_codes( $u->ID );
	fs_eq( 10, count( $codes ) );
	foreach ( $codes as $c ) fs_true( (bool) preg_match( '/^[a-km-np-z2-9]{4}-[a-km-np-z2-9]{4}$/', $c ), "format of $c" );
	$hashes = get_user_meta( $u->ID, '_fisha_2fa_backup', true );
	fs_true( is_array( $hashes ) && 10 === count( $hashes ) && ! in_array( $codes[0], $hashes, true ), 'hashes stored, not codes' );
	fs_false( fisha_studio_2fa_backup_use( $u->ID, 'zzzz-zzzz' ), 'wrong code' );
	fs_true( fisha_studio_2fa_backup_use( $u->ID, strtoupper( $codes[3] ) ), 'upper case works' );
	fs_false( fisha_studio_2fa_backup_use( $u->ID, $codes[3] ), 'only once' );
	fs_eq( 9, fisha_studio_2fa_backup_left( $u->ID ) );
} );

/* ---------- password step, code step ---------- */

fs_test( '2fa: password alone does not set the auth cookie for an owner, the right code does', function () {
	$seen        = fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip          = fs_2fa_ip();
	$r           = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	fs_true( is_array( $r ) && 'code' === $r['step'] && strlen( $r['token'] ) >= 32, 'pending code step' );
	fs_eq( 0, $seen->auth + $seen->logged_in, 'no auth cookie after the password' );
	fs_eq( 0, get_current_user_id(), 'not signed in' );
	$done = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'signed in: ' . ( is_wp_error( $done ) ? $done->get_error_message() : '' ) );
	fs_true( $seen->auth > 0 && $seen->logged_in > 0, 'auth cookie set' );
	fs_eq( $u->ID, get_current_user_id() );
	global $wpdb;
	fs_eq( '2fa-signin', $wpdb->get_var( $wpdb->prepare( 'SELECT action FROM ' . fisha_studio_log_table() . " WHERE item_type = 'user' AND item_id = %d ORDER BY id DESC LIMIT 1", $u->ID ) ), 'audit row' );
} );

fs_test( '2fa: an admin with Studio access still signs in with the password alone', function () {
	$seen = fs_2fa_watch_cookies();
	$a    = fs_user( 'administrator' );
	wp_set_password( 'admin-pass-123', $a->ID );
	fs_on_cleanup( function () use ( $a ) { fisha_studio_login_clear( $a->user_login, '' ); } );
	$r = fisha_studio_sign_in( $a->user_login, 'admin-pass-123', fs_2fa_ip(), FS_2FA_UA );
	fs_true( $r instanceof WP_User, 'signed in at once' );
	fs_true( $seen->auth > 0, 'cookie set' );
} );

fs_test( '2fa: wrong codes count toward the lock, five lock the account', function () {
	$seen        = fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip          = fs_2fa_ip();
	for ( $i = 0; $i < 4; $i++ ) {
		if ( 0 === $i % 2 ) $r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
		$e = fisha_studio_2fa_code_step( $r['token'], '000000' === fs_2fa_code( $s ) ? '111111' : '000000', $ip, FS_2FA_UA );
		fs_eq( 'fisha_studio_2fa_wrong', is_wp_error( $e ) ? $e->get_error_code() : 'ok', "try $i" );
	}
	// The right password again must not reset the counter (else codes could be guessed without end).
	$r2 = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	fs_true( is_array( $r2 ), 'password still right' );
	$e = fisha_studio_2fa_code_step( $r2['token'], 'zzzz-zzzz', $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_wrong', $e->get_error_code(), 'fifth wrong (a backup code)' );
	$e = fisha_studio_2fa_code_step( $r2['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_locked', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'locked even with the right code' );
	fs_eq( 0, $seen->auth, 'never signed in' );
	$e = fisha_studio_sign_in( $u->user_login, 'right-password-123', fs_2fa_ip(), FS_2FA_UA );
	fs_eq( 'fisha_studio_locked', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'password step locked too' );
} );

fs_test( '2fa: a backup code signs in once and reports how many are left', function () {
	$seen      = fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner();
	$codes     = fisha_studio_2fa_new_backup_codes( $u->ID );
	$ip        = fs_2fa_ip();
	$r         = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$done      = fisha_studio_2fa_code_step( $r['token'], strtoupper( $codes[0] ), $ip, FS_2FA_UA );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'signed in' );
	fs_eq( 9, $done['backup_left'] );
	global $wpdb;
	fs_eq( 'backup-code', $wpdb->get_var( $wpdb->prepare( 'SELECT action FROM ' . fisha_studio_log_table() . " WHERE item_type = 'user' AND item_id = %d ORDER BY id DESC LIMIT 1", $u->ID ) ) );
	wp_set_current_user( 0 );
	$r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$e = fisha_studio_2fa_code_step( $r['token'], $codes[0], $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_wrong', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'used code refused' );
} );

/* ---------- token ---------- */

fs_test( '2fa: the pending token is refused when expired, from another IP or agent, or reused', function () {
	fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip          = fs_2fa_ip();

	$r   = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$key = 'fisha_2fa_pending_' . hash( 'sha256', $r['token'] );
	$rec = get_transient( $key );
	fs_true( is_array( $rec ) && $u->ID === $rec['uid'], 'stored under the hash of the token' );
	fs_true( false === get_transient( 'fisha_2fa_pending_' . $r['token'] ), 'not under the token itself' );
	$rec['created'] = time() - 301;
	set_transient( $key, $rec, 300 );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'expired' );
	fs_eq( 'Please sign in again.', $e->get_error_message() );

	$r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), '192.0.2.77', FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'other IP' );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'a token tried from another IP is gone' );

	$r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, 'other-agent' );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'other user agent' );

	$r    = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$done = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'signed in' );
	wp_set_current_user( 0 );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s, 1 ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'reused' );
	fs_false( get_transient( $key ), 'nothing left behind' );
} );

/* ---------- setup ---------- */

fs_test( '2fa: an owner without it goes through setup, the secret stays out of user meta until confirmed', function () {
	$seen      = fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner( false );
	$ip        = fs_2fa_ip();
	$r         = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	fs_true( is_array( $r ) && 'setup' === $r['step'], 'setup step' );
	$setup = fisha_studio_2fa_setup_info( $r['token'], $ip, FS_2FA_UA );
	fs_true( is_array( $setup ) && 32 === strlen( $setup['key'] ), 'a base32 key of 20 bytes' );
	fs_true( 0 === strpos( $setup['uri'], 'otpauth://totp/Fisha%20Studio:' . rawurlencode( $u->user_login ) . '?secret=' . $setup['key'] . '&issuer=Fisha%20Studio&digits=6&period=30' ), 'otpauth uri: ' . $setup['uri'] );
	fs_eq( '', (string) get_user_meta( $u->ID, '_fisha_2fa_secret', true ), 'not in user meta yet' );
	fs_false( fisha_studio_2fa_enabled( $u->ID ) );

	$secret = fisha_studio_base32_decode( $setup['key'] );
	$e      = fisha_studio_2fa_code_step( $r['token'], '12345', $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_wrong', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'wrong code' );
	fs_false( fisha_studio_2fa_enabled( $u->ID ), 'still off' );

	$step = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $secret ), $ip, FS_2FA_UA );
	fs_true( is_array( $step ) && empty( $step['done'] ) && 10 === count( $step['codes'] ), 'backup codes shown' );
	fs_true( fisha_studio_2fa_enabled( $u->ID ), 'enabled' );
	fs_eq( $secret, fisha_studio_2fa_secret( $u->ID ) );
	fs_eq( 0, $seen->auth, 'not signed in before the codes are saved' );
	fs_eq( 'fisha_studio_2fa_expired', fisha_studio_2fa_setup_info( $r['token'], $ip, FS_2FA_UA ) instanceof WP_Error ? 'fisha_studio_2fa_expired' : 'still there', 'the key is not shown again' );

	$done = fisha_studio_2fa_saved_step( $r['token'], $ip, FS_2FA_UA );
	fs_true( $done instanceof WP_User, 'signed in after "I have saved these codes"' );
	fs_true( $seen->auth > 0, 'cookie set' );
	wp_set_current_user( 0 );
	fs_true( is_wp_error( fisha_studio_2fa_saved_step( $r['token'], $ip, FS_2FA_UA ) ), 'saved step is single use' );
	fs_true( fisha_studio_2fa_backup_use( $u->ID, $step['codes'][0] ), 'the shown codes work' );
} );

fs_test( '2fa: the codes step cannot be skipped by posting "saved" before a code', function () {
	fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip          = fs_2fa_ip();
	$r           = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	fs_true( is_wp_error( fisha_studio_2fa_saved_step( $r['token'], $ip, FS_2FA_UA ) ), 'refused' );
	list( $u2 ) = fs_2fa_owner( false );
	$r2         = fisha_studio_sign_in( $u2->user_login, 'right-password-123', $ip, FS_2FA_UA );
	fs_true( is_wp_error( fisha_studio_2fa_saved_step( $r2['token'], $ip, FS_2FA_UA ) ), 'refused during setup' );
	fs_eq( 0, get_current_user_id() );
} );

/* ---------- bypasses ---------- */

fs_test( '2fa: wp_authenticate outside the Studio refuses owners, not customers or admins', function () {
	list( $u ) = fs_2fa_owner();
	$r         = fs_2fa_from_ip( fs_2fa_ip(), function () use ( $u ) { return wp_authenticate( $u->user_login, 'right-password-123' ); } );
	fs_eq( 'fisha_studio_use_studio', is_wp_error( $r ) ? $r->get_error_code() : 'user', 'owner refused' );
	fs_eq( 'Please sign in at /studio/login/.', $r->get_error_message() );
	list( $u2 ) = fs_2fa_owner( false );
	$r          = fs_2fa_from_ip( fs_2fa_ip(), function () use ( $u2 ) { return wp_authenticate( $u2->user_email, 'right-password-123' ); } );
	fs_eq( 'fisha_studio_use_studio', is_wp_error( $r ) ? $r->get_error_code() : 'user', 'owner without 2FA refused too (by email)' );

	$c = fs_user( 'customer' );
	wp_set_password( 'customer-pass-123', $c->ID );
	fs_true( wp_authenticate( $c->user_login, 'customer-pass-123' ) instanceof WP_User, 'customer fine' );
	$a = fs_user( 'administrator' );
	wp_set_password( 'admin-pass-123', $a->ID );
	fs_on_cleanup( function () use ( $a ) { fisha_studio_login_clear( $a->user_login, '' ); } );
	fs_true( wp_authenticate( $a->user_login, 'admin-pass-123' ) instanceof WP_User, 'admin fine' );
} );

fs_test( '2fa: application passwords are not available to owners', function () {
	list( $u ) = fs_2fa_owner();
	fs_false( wp_is_application_passwords_available_for_user( $u ), 'owner' );
	$a = fs_user( 'administrator' );
	fs_eq( wp_is_application_passwords_available(), wp_is_application_passwords_available_for_user( $a ), 'admin unchanged' );
} );

/* ---------- REST ---------- */

fs_test( '2fa: REST login asks an owner for the code without a cookie, the code call signs in', function () {
	$seen        = fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip          = fs_2fa_ip();
	$ua_old      = $_SERVER['HTTP_USER_AGENT'] ?? null;
	$_SERVER['HTTP_USER_AGENT'] = FS_2FA_UA;
	fs_on_cleanup( function () use ( $ua_old ) {
		if ( null === $ua_old ) unset( $_SERVER['HTTP_USER_AGENT'] );
		else $_SERVER['HTTP_USER_AGENT'] = $ua_old;
	} );
	list( $st, $d ) = fs_2fa_from_ip( $ip, function () use ( $u ) { return fs_api( 'POST', '/login', array( 'login' => $u->user_login, 'password' => 'right-password-123' ) ); } );
	fs_eq( 200, $st );
	fs_eq( array( 'step' => 'code' ), $d, 'only the step, the token travels in the HttpOnly cookie' );
	$token = (string) ( $_COOKIE['fisha_studio_2fa'] ?? '' );
	fs_true( 64 === strlen( $token ), 'token cookie set' );
	fs_eq( 0, $seen->auth, 'no cookie' );
	fs_eq( 0, get_current_user_id() );

	unset( $_COOKIE['fisha_studio_2fa'] );
	list( $st, $d2 ) = fs_2fa_from_ip( $ip, function () use ( $token, $s ) { return fs_api( 'POST', '/login/code', array( 'code' => fs_2fa_code( $s ), 'token' => $token ) ); } );
	fs_eq( 401, $st, 'a token in the body is not accepted: ' . wp_json_encode( $d2 ) );
	$_COOKIE['fisha_studio_2fa'] = $token;
	list( $st, $d2 ) = fs_2fa_from_ip( $ip, function () { return fs_api( 'POST', '/login/code', array( 'code' => 'zzzz-zzzz' ) ); } );
	fs_eq( 401, $st, 'wrong code' );
	list( $st, $d2 ) = fs_2fa_from_ip( $ip, function () use ( $s ) { return fs_api( 'POST', '/login/code', array( 'code' => fs_2fa_code( $s ) ) ); } );
	fs_eq( 200, $st, wp_json_encode( $d2 ) );
	fs_true( ! empty( $d2['nonce'] ) && $u->ID === $d2['user']['id'], 'nonce and user' );
	fs_true( $seen->auth > 0, 'cookie set' );
} );

fs_test( '2fa: REST login for an owner without 2-step sign-in is refused (403)', function () {
	$seen      = fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner( false );
	list( $st, $d ) = fs_2fa_from_ip( fs_2fa_ip(), function () use ( $u ) { return fs_api( 'POST', '/login', array( 'login' => $u->user_login, 'password' => 'right-password-123' ) ); } );
	fs_eq( 403, $st );
	fs_eq( 'Please sign in at /studio/login/ to set up 2-step sign-in.', $d['message'] ?? '' );
	fs_eq( 0, $seen->auth );
} );

/* ---------- WP-CLI helpers ---------- */

fs_test( '2fa: reset turns it off and clears everything', function () {
	list( $u ) = fs_2fa_owner();
	fisha_studio_2fa_new_backup_codes( $u->ID );
	update_user_meta( $u->ID, '_fisha_2fa_last', 5 );
	fisha_studio_2fa_reset( $u->ID );
	fs_false( fisha_studio_2fa_enabled( $u->ID ) );
	foreach ( array( '_fisha_2fa_secret', '_fisha_2fa_enabled', '_fisha_2fa_backup', '_fisha_2fa_last' ) as $k ) {
		fs_eq( array(), get_user_meta( $u->ID, $k ), "$k removed" );
	}
} );

fs_test( '2fa: a secret that cannot be decrypted never falls back to a password-only setup', function () {
	fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner();
	$codes     = fisha_studio_2fa_new_backup_codes( $u->ID );
	update_user_meta( $u->ID, '_fisha_2fa_secret', base64_encode( random_bytes( 48 ) ) ); // as if the salt changed
	$ip = fs_2fa_ip();
	$r  = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	fs_eq( 'code', is_array( $r ) ? $r['step'] : 'error', 'still asks for a code, no new setup' );
	fs_true( is_wp_error( fisha_studio_2fa_code_step( $r['token'], '123456', $ip, FS_2FA_UA ) ), 'no app code can work' );
	$done = fisha_studio_2fa_code_step( $r['token'], $codes[0], $ip, FS_2FA_UA );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'a backup code still works' );
} );

/* ---------- fix round 1 ---------- */

fs_test( '2fa fix C1: no other auth-cookie caller (WooCommerce reset) gives an owner a session', function () {
	$seen      = fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner();
	wc_set_customer_auth_cookie( $u->ID );   // what WooCommerce's My Account password reset does
	wp_set_auth_cookie( $u->ID, true );
	fs_eq( array( array( $u->ID, false ), array( $u->ID, false ) ), $seen->sent, 'cookies not sent for the owner' );
	fs_eq( array(), WP_Session_Tokens::get_instance( $u->ID )->get_all(), 'no session token left' );
	fs_eq( 0, get_current_user_id(), 'not the current user' );
	fs_false( isset( $_COOKIE[ LOGGED_IN_COOKIE ] ), 'no logged-in cookie in this request' );

	$c = fs_user( 'customer' );
	$a = fs_user( 'administrator' );
	$seen->sent = array();
	wc_set_customer_auth_cookie( $c->ID );
	wp_set_auth_cookie( $a->ID );
	fs_eq( array( array( $c->ID, true ), array( $a->ID, true ) ), $seen->sent, 'customer and admin unchanged' );
	WP_Session_Tokens::get_instance( $c->ID )->destroy_all();
	WP_Session_Tokens::get_instance( $a->ID )->destroy_all();
} );

fs_test( '2fa fix C1: the code step still gives the owner a session; the WooCommerce reset sends owners to the Studio', function () {
	$seen          = fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip            = fs_2fa_ip();
	$r             = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$done          = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'signed in' );
	fs_eq( array( array( $u->ID, true ) ), $seen->sent, 'cookie sent' );
	fs_eq( 1, count( WP_Session_Tokens::get_instance( $u->ID )->get_all() ), 'one session' );
	WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
	fs_eq( home_url( '/studio/login/?reset=1' ), fisha_studio_2fa_reset_redirect( $u ) );
	fs_eq( null, fisha_studio_2fa_reset_redirect( fs_user( 'customer' ) ) );
	fs_true( false !== has_action( 'woocommerce_customer_reset_password', 'fisha_studio_2fa_after_wc_reset' ), 'wired to the WooCommerce reset' );
} );

fs_test( '2fa fix I1: the atomic counter counts every call and restarts after its window', function () {
	$name = 'fs_test_ctr_' . wp_rand();
	fs_on_cleanup( function () use ( $name ) { fisha_studio_counter_clear( $name ); } );
	for ( $i = 1; $i <= 7; $i++ ) fs_eq( $i, fisha_studio_counter_incr( $name, 60 ), "call $i" );
	fs_eq( 7, fisha_studio_counter_get( $name, 60 ) );
	global $wpdb;
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 61 ) . ':7' ), array( 'option_name' => fisha_studio_counter_option( $name ) ) );
	fs_eq( 0, fisha_studio_counter_get( $name, 60 ), 'window over' );
	fs_eq( 1, fisha_studio_counter_incr( $name, 60 ), 'starts again' );
	fisha_studio_counter_clear( $name );
	fs_eq( 0, fisha_studio_counter_get( $name, 60 ), 'cleared' );
} );

fs_test( '2fa fix I1: three wrong codes end a pending sign-in', function () {
	fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip            = fs_2fa_ip();
	$r             = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	for ( $i = 1; $i <= 3; $i++ ) $e = fisha_studio_2fa_code_step( $r['token'], 'zzzz-zzzz', $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', $e->get_error_code(), 'third wrong code ends it' );
	fs_false( get_transient( fisha_studio_2fa_pending_key( $r['token'] ) ), 'token gone' );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'right code no longer helps' );
} );

fs_test( '2fa fix I1: twenty wrong codes in 24 hours lock code entry until reset', function () {
	fs_2fa_watch_cookies();
	list( $u, $s ) = fs_2fa_owner();
	$ip            = fs_2fa_ip();
	for ( $i = 0; $i < 19; $i++ ) fisha_studio_counter_incr( 'fs_2fa_day_' . $u->ID, DAY_IN_SECONDS );
	$r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$e = fisha_studio_2fa_code_step( $r['token'], 'zzzz-zzzz', $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_day_lock', $e->get_error_code(), 'the 20th wrong code' );
	fs_eq( 'Too many wrong codes. Please contact your web developer.', $e->get_error_message() );
	global $wpdb;
	fs_eq( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . fisha_studio_log_table() . " WHERE item_type = 'user' AND item_id = %d AND action = '2fa-code-lock'", $u->ID ) ), 'audit row' );
	fisha_studio_login_clear( $u->user_login, $ip );
	$r = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$e = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_day_lock', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'even the right code' );
	fisha_studio_2fa_reset( $u->ID );
	fisha_studio_2fa_enable( $u->ID, $s );
	$r    = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$done = fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $s ), $ip, FS_2FA_UA );
	fs_true( is_array( $done ) && ! empty( $done['done'] ), 'reset lifts it' );
	WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
} );

fs_test( '2fa fix I2: a second pending setup cannot replace the authenticator after enrolment', function () {
	fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner( false );
	$ip        = fs_2fa_ip();
	$ra        = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$rb        = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$sa        = fisha_studio_base32_decode( fisha_studio_2fa_setup_info( $ra['token'], $ip, FS_2FA_UA )['key'] );
	$sb        = fisha_studio_base32_decode( fisha_studio_2fa_setup_info( $rb['token'], $ip, FS_2FA_UA )['key'] );
	$step      = fisha_studio_2fa_code_step( $ra['token'], fs_2fa_code( $sa ), $ip, FS_2FA_UA );
	fs_true( is_array( $step ) && 10 === count( $step['codes'] ), 'A enrolled' );
	$hashes = get_user_meta( $u->ID, '_fisha_2fa_backup', true );
	$e      = fisha_studio_2fa_code_step( $rb['token'], fs_2fa_code( $sb ), $ip, FS_2FA_UA );
	fs_eq( 'fisha_studio_2fa_expired', is_wp_error( $e ) ? $e->get_error_code() : 'ok', 'B refused' );
	fs_eq( $sa, fisha_studio_2fa_secret( $u->ID ), 'A secret kept' );
	fs_eq( $hashes, get_user_meta( $u->ID, '_fisha_2fa_backup', true ), 'A backup codes kept' );
	fs_true( is_wp_error( fisha_studio_2fa_saved_step( $rb['token'], $ip, FS_2FA_UA ) ), 'B cannot finish' );
	fs_false( fisha_studio_2fa_enable( $u->ID, random_bytes( 20 ) ), 'enable is first-wins' );
	fs_eq( $sa, fisha_studio_2fa_secret( $u->ID ), 'still A' );
} );

fs_test( '2fa fix M1: the time step is claimed with compare-and-set', function () {
	list( $u ) = fs_2fa_owner();
	fs_true( fisha_studio_2fa_claim_counter( $u->ID, 100, null ), 'first claim' );
	fs_false( fisha_studio_2fa_claim_counter( $u->ID, 101, null ), 'a second first-time claim loses' );
	fs_false( fisha_studio_2fa_claim_counter( $u->ID, 101, 99 ), 'stale previous value loses' );
	fs_true( fisha_studio_2fa_claim_counter( $u->ID, 101, 100 ), 'right previous value wins' );
	fs_false( fisha_studio_2fa_claim_counter( $u->ID, 102, 100 ), 'the same previous value cannot be claimed twice' );
	fs_eq( '101', (string) get_user_meta( $u->ID, '_fisha_2fa_last', true ) );
} );

fs_test( '2fa fix M7: finishing setup ends older sessions of the owner', function () {
	fs_2fa_watch_cookies();
	list( $u ) = fs_2fa_owner( false );
	$old       = WP_Session_Tokens::get_instance( $u->ID )->create( time() + HOUR_IN_SECONDS );
	$ip        = fs_2fa_ip();
	$r         = fisha_studio_sign_in( $u->user_login, 'right-password-123', $ip, FS_2FA_UA );
	$secret    = fisha_studio_base32_decode( fisha_studio_2fa_setup_info( $r['token'], $ip, FS_2FA_UA )['key'] );
	fisha_studio_2fa_code_step( $r['token'], fs_2fa_code( $secret ), $ip, FS_2FA_UA );
	fs_true( fisha_studio_2fa_saved_step( $r['token'], $ip, FS_2FA_UA ) instanceof WP_User, 'signed in' );
	$m = WP_Session_Tokens::get_instance( $u->ID );
	fs_false( $m->verify( $old ), 'old session ended' );
	fs_eq( 1, count( $m->get_all() ), 'only the new session' );
	$m->destroy_all();
} );

fs_test( '2fa fix I1: old counter rows are swept when a sign-in starts', function () {
	fs_2fa_watch_cookies();
	global $wpdb;
	$old = 'fs_2fa_tok_zz' . wp_rand();
	$new = 'fs_2fa_tok_zz' . wp_rand() . 'b';
	fs_on_cleanup( function () use ( $old, $new ) { fisha_studio_counter_clear( $old ); fisha_studio_counter_clear( $new ); } );
	fisha_studio_counter_incr( $old, 300 );
	fisha_studio_counter_incr( $new, 300 );
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 301 ) . ':2' ), array( 'option_name' => fisha_studio_counter_option( $old ) ) );
	list( $u ) = fs_2fa_owner();
	fisha_studio_sign_in( $u->user_login, 'right-password-123', fs_2fa_ip(), FS_2FA_UA );
	$has = function ( $n ) use ( $wpdb ) { return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", fisha_studio_counter_option( $n ) ) ); };
	fs_false( $has( $old ), 'expired row removed' );
	fs_true( $has( $new ), 'current row kept' );
} );
