<?php
/**
 * Fisha Studio 2-step sign-in for shop owners: an authenticator app code (TOTP, RFC 6238) or a one-time backup code
 * after the password. The password alone never creates a session for an owner: it only starts a short pending
 * sign-in (a single-use token bound to the IP and browser), and the auth cookie is set after the code step.
 * Owners cannot sign in anywhere else (wp-login.php, My Account, XML-RPC, application passwords).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_2FA_COOKIE', 'fisha_studio_2fa' );
define( 'FISHA_STUDIO_2FA_TTL', 5 * MINUTE_IN_SECONDS );
define( 'FISHA_STUDIO_2FA_ISSUER', 'Fisha Studio' );
define( 'FISHA_STUDIO_2FA_TOKEN_TRIES', 3 ); // wrong codes per pending sign-in
define( 'FISHA_STUDIO_2FA_DAY_TRIES', 20 );  // wrong codes per account in 24 hours
define( 'FISHA_STUDIO_2FA_BACKUP_CHARS', 'abcdefghijkmnpqrstuvwxyz23456789' ); // no 0, o, 1, l

/* ---------- base32 and TOTP ---------- */

function fisha_studio_base32_encode( string $bin ): string {
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits  = '';
	foreach ( str_split( $bin ) as $c ) $bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
	if ( '' === $bin ) return '';
	$out = '';
	foreach ( str_split( $bits, 5 ) as $chunk ) $out .= $alpha[ bindec( str_pad( $chunk, 5, '0' ) ) ];
	return $out;
}

/** Decodes base32 (any case, spaces and padding ignored). Returns '' for invalid input. */
function fisha_studio_base32_decode( string $b32 ): string {
	$b32 = strtoupper( preg_replace( '/[\s=]+/', '', $b32 ) );
	if ( '' === $b32 || ! preg_match( '/^[A-Z2-7]+$/', $b32 ) ) return '';
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits  = '';
	foreach ( str_split( $b32 ) as $c ) $bits .= str_pad( decbin( strpos( $alpha, $c ) ), 5, '0', STR_PAD_LEFT );
	$out = '';
	foreach ( str_split( $bits, 8 ) as $byte ) {
		if ( 8 === strlen( $byte ) ) $out .= chr( bindec( $byte ) );
	}
	return $out;
}

/** The 6-digit code for a time step (HMAC-SHA1, dynamic truncation). */
function fisha_studio_totp( string $secret_bin, int $counter ): string {
	$hash   = hash_hmac( 'sha1', pack( 'J', $counter ), $secret_bin, true );
	$offset = ord( $hash[19] ) & 0x0f;
	$value  = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 ) | ( ord( $hash[ $offset + 1 ] ) << 16 ) | ( ord( $hash[ $offset + 2 ] ) << 8 ) | ord( $hash[ $offset + 3 ] );
	return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
}

function fisha_studio_totp_counter(): int {
	return (int) floor( time() / 30 );
}

/** The time step a code matches (now-1, now, now+1) that is newer than $last, or null. Compares in constant time. */
function fisha_studio_totp_match( string $secret_bin, string $code, int $last ): ?int {
	$code = preg_replace( '/\s+/', '', $code );
	if ( '' === $secret_bin || ! preg_match( '/^\d{6}$/', $code ) ) return null;
	$now   = fisha_studio_totp_counter();
	$found = null;
	for ( $c = $now - 1; $c <= $now + 1; $c++ ) {
		$ok = hash_equals( fisha_studio_totp( $secret_bin, $c ), $code );
		if ( $ok && $c > $last && null === $found ) $found = $c;
	}
	return $found;
}

/** Checks an owner's authenticator code; a code is accepted once (its time step is claimed with compare-and-set). */
function fisha_studio_totp_verify( int $user_id, string $code ): bool {
	$secret = fisha_studio_2fa_secret( $user_id );
	if ( null === $secret ) return false;
	$last    = (string) get_user_meta( $user_id, '_fisha_2fa_last', true );
	$prev    = '' === $last ? null : (int) $last;
	$counter = fisha_studio_totp_match( $secret, $code, $prev ?? -1 );
	return null !== $counter && fisha_studio_2fa_claim_counter( $user_id, $counter, $prev );
}

/**
 * Stores $counter as the last used time step only if the stored one is still $prev (null: none stored yet), in one
 * database statement, so two requests with the same code cannot both win. False means a replay.
 */
function fisha_studio_2fa_claim_counter( int $uid, int $counter, ?int $prev ): bool {
	if ( null === $prev ) return false !== add_user_meta( $uid, '_fisha_2fa_last', (string) $counter, true );
	if ( $counter <= $prev ) return false;
	return (bool) update_user_meta( $uid, '_fisha_2fa_last', (string) $counter, (string) $prev );
}

/* ---------- storage ---------- */

function fisha_studio_2fa_key(): string {
	return hash( 'sha256', wp_salt( 'auth' ) . 'fisha-2fa', true );
}

/** AES-256-GCM, stored as base64(iv | tag | ciphertext). */
function fisha_studio_2fa_encrypt( string $plain ): string {
	$iv  = random_bytes( 12 );
	$tag = '';
	$ct  = openssl_encrypt( $plain, 'aes-256-gcm', fisha_studio_2fa_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
	return base64_encode( $iv . $tag . $ct );
}

function fisha_studio_2fa_decrypt( string $stored ): ?string {
	$raw = base64_decode( $stored, true );
	if ( false === $raw || strlen( $raw ) < 29 ) return null;
	$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', fisha_studio_2fa_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	return false === $plain ? null : $plain;
}

/**
 * On once confirmed. Deliberately the flag only: if the secret ever cannot be decrypted (for example the auth salt
 * changed), the owner must use a backup code or be reset with WP-CLI, never fall back to a password-only setup.
 */
function fisha_studio_2fa_enabled( int $uid ): bool {
	return '1' === (string) get_user_meta( $uid, '_fisha_2fa_enabled', true );
}

/** The owner's authenticator secret (raw bytes), or null. */
function fisha_studio_2fa_secret( int $uid ): ?string {
	$stored = (string) get_user_meta( $uid, '_fisha_2fa_secret', true );
	return '' === $stored ? null : fisha_studio_2fa_decrypt( $stored );
}

/**
 * Saves a confirmed secret and turns 2-step sign-in on. First wins: the enabled flag is added as a unique row and the
 * secret is only stored when that add succeeded, so a second (older) setup can never replace a working authenticator.
 */
function fisha_studio_2fa_enable( int $uid, string $secret_bin, int $last = -1 ): bool {
	if ( false === add_user_meta( $uid, '_fisha_2fa_enabled', '1', true ) ) return false;
	update_user_meta( $uid, '_fisha_2fa_secret', fisha_studio_2fa_encrypt( $secret_bin ) );
	if ( $last >= 0 ) update_user_meta( $uid, '_fisha_2fa_last', (string) $last );
	else delete_user_meta( $uid, '_fisha_2fa_last' );
	return true;
}

/** Turns 2-step sign-in off and removes the secret, backup codes, replay marker and the 24-hour wrong-code count. */
function fisha_studio_2fa_reset( int $uid ): void {
	foreach ( array( '_fisha_2fa_secret', '_fisha_2fa_enabled', '_fisha_2fa_backup', '_fisha_2fa_last' ) as $k ) delete_user_meta( $uid, $k );
	fisha_studio_counter_clear( 'fs_2fa_day_' . $uid );
}

/** 'abcd-efgh' from what was typed (any case, spaces or dash), or '' when it is not a backup code. */
function fisha_studio_2fa_backup_normalize( string $code ): string {
	$c = strtolower( preg_replace( '/[\s-]+/', '', $code ) );
	if ( 8 !== strlen( $c ) || strspn( $c, FISHA_STUDIO_2FA_BACKUP_CHARS ) !== 8 ) return '';
	return substr( $c, 0, 4 ) . '-' . substr( $c, 4 );
}

/** Makes 10 new backup codes (old ones stop working) and returns them; only their hashes are stored. */
function fisha_studio_2fa_new_backup_codes( int $uid ): array {
	$chars  = FISHA_STUDIO_2FA_BACKUP_CHARS;
	$codes  = array();
	$hashes = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$c = '';
		for ( $j = 0; $j < 8; $j++ ) $c .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		$c        = substr( $c, 0, 4 ) . '-' . substr( $c, 4 );
		$codes[]  = $c;
		$hashes[] = wp_hash_password( $c );
	}
	update_user_meta( $uid, '_fisha_2fa_backup', $hashes );
	return $codes;
}

function fisha_studio_2fa_backup_left( int $uid ): int {
	$h = get_user_meta( $uid, '_fisha_2fa_backup', true );
	return is_array( $h ) ? count( $h ) : 0;
}

/** Uses a backup code: true when it matches an unused one, which is then removed (compare-and-set, so two parallel requests cannot both use it). */
function fisha_studio_2fa_backup_use( int $uid, string $code ): bool {
	$code   = fisha_studio_2fa_backup_normalize( $code );
	$hashes = get_user_meta( $uid, '_fisha_2fa_backup', true );
	if ( '' === $code || ! is_array( $hashes ) || ! $hashes ) return false;
	foreach ( $hashes as $i => $hash ) {
		if ( wp_check_password( $code, (string) $hash ) ) {
			$left = $hashes;
			unset( $left[ $i ] );
			return (bool) update_user_meta( $uid, '_fisha_2fa_backup', array_values( $left ), $hashes );
		}
	}
	return false;
}

/* ---------- pending sign-in token ---------- */

function fisha_studio_2fa_pending_key( string $token ): string {
	return 'fisha_2fa_pending_' . hash( 'sha256', $token );
}

function fisha_studio_2fa_ua(): string {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
}

/** Sets (or with '' clears) the HttpOnly, SameSite=Strict token cookie. $path: /studio/ for the page, the REST route for the API. */
function fisha_studio_2fa_cookie( string $token, string $path = '/studio/' ): void {
	if ( '' === $token ) unset( $_COOKIE[ FISHA_STUDIO_2FA_COOKIE ] );
	else $_COOKIE[ FISHA_STUDIO_2FA_COOKIE ] = $token;
	if ( headers_sent() ) return;
	// Clearing removes it from both places it can be set (the page and the REST route).
	$paths = '' === $token ? array_unique( array( '/studio/', fisha_studio_2fa_rest_cookie_path() ) ) : array( $path );
	foreach ( $paths as $p ) {
		setcookie( FISHA_STUDIO_2FA_COOKIE, $token, array(
			'expires'  => '' === $token ? time() - YEAR_IN_SECONDS : time() + FISHA_STUDIO_2FA_TTL,
			'path'     => $p,
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		) );
	}
}

/** The cookie path for the REST code step: the path of the /login route (it also covers /login/code). */
function fisha_studio_2fa_rest_cookie_path(): string {
	$path = (string) wp_parse_url( rest_url( FISHA_STUDIO_NS . '/login' ), PHP_URL_PATH );
	return ( '' === $path || false !== strpos( $path, 'index.php' ) ) ? '/' : $path;
}

/** Starts a pending sign-in after a right password. A setup sign-in carries a new secret (not in user meta yet). */
function fisha_studio_2fa_pending_start( WP_User $u, string $ip, string $ua, bool $setup ): string {
	$token = bin2hex( random_bytes( 32 ) );
	$rec   = array( 'uid' => $u->ID, 'ip' => $ip, 'ua_hash' => hash( 'sha256', $ua ), 'created' => time(), 'setup' => $setup, 'confirmed' => false );
	if ( $setup ) $rec['secret'] = fisha_studio_2fa_encrypt( random_bytes( 20 ) );
	set_transient( fisha_studio_2fa_pending_key( $token ), $rec, FISHA_STUDIO_2FA_TTL );
	// Housekeeping: counter rows of abandoned sign-ins and ended lock windows.
	fisha_studio_counter_sweep( 'fs_2fa_tok_', FISHA_STUDIO_2FA_TTL );
	fisha_studio_counter_sweep( 'fs_login_', FISHA_STUDIO_LOGIN_LOCK );
	return $token;
}

/** The pending record for a token used from this IP and browser, or null (expired, unknown, or used elsewhere, which ends it). */
function fisha_studio_2fa_pending_get( string $token, string $ip, string $ua ): ?array {
	if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) return null;
	$key = fisha_studio_2fa_pending_key( $token );
	$rec = get_transient( $key );
	if ( ! is_array( $rec ) ) return null;
	if ( time() - (int) ( $rec['created'] ?? 0 ) > FISHA_STUDIO_2FA_TTL
		|| ! hash_equals( (string) $rec['ip'], $ip )
		|| ! hash_equals( (string) $rec['ua_hash'], hash( 'sha256', $ua ) ) ) {
		delete_transient( $key );
		return null;
	}
	$u = get_user_by( 'id', (int) $rec['uid'] );
	if ( ! $u || ! fisha_studio_is_owner( $u ) ) {
		delete_transient( $key );
		return null;
	}
	$rec['user'] = $u;
	return $rec;
}

function fisha_studio_2fa_expired(): WP_Error {
	return new WP_Error( 'fisha_studio_2fa_expired', 'Please sign in again.' );
}

/** The key and otpauth link to show on the setup step, or an error when the token has no unconfirmed setup. @return array|WP_Error */
function fisha_studio_2fa_setup_info( string $token, string $ip, string $ua ) {
	$p = fisha_studio_2fa_pending_get( $token, $ip, $ua );
	if ( ! $p || empty( $p['setup'] ) || ! empty( $p['confirmed'] ) || empty( $p['secret'] ) ) return fisha_studio_2fa_expired();
	$secret = fisha_studio_2fa_decrypt( (string) $p['secret'] );
	if ( null === $secret ) return fisha_studio_2fa_expired();
	$key   = fisha_studio_base32_encode( $secret );
	$label = rawurlencode( FISHA_STUDIO_2FA_ISSUER ) . ':' . rawurlencode( $p['user']->user_login );
	return array(
		'key'   => $key,
		'uri'   => 'otpauth://totp/' . $label . '?secret=' . $key . '&issuer=' . rawurlencode( FISHA_STUDIO_2FA_ISSUER ) . '&digits=6&period=30',
		'login' => $p['user']->user_login,
	);
}

/** Ends a pending sign-in (its transient and its wrong-code counter). */
function fisha_studio_2fa_drop( string $token ): void {
	delete_transient( fisha_studio_2fa_pending_key( $token ) );
	fisha_studio_counter_clear( 'fs_2fa_tok_' . hash( 'sha256', $token ) );
}

/** Code entry is locked for 24 hours after FISHA_STUDIO_2FA_DAY_TRIES wrong codes (or until wp fisha-studio reset-2fa). */
function fisha_studio_2fa_day_locked( int $uid ): bool {
	return fisha_studio_counter_get( 'fs_2fa_day_' . $uid, DAY_IN_SECONDS ) >= FISHA_STUDIO_2FA_DAY_TRIES;
}

function fisha_studio_2fa_day_lock_error(): WP_Error {
	return new WP_Error( 'fisha_studio_2fa_day_lock', 'Too many wrong codes. Please contact your web developer.' );
}

/** Starts the session for a finished 2-step sign-in: auth cookie, current user, wp_login, counters cleared, token gone, audit row. */
function fisha_studio_2fa_finish( string $token, WP_User $u, string $ip, string $action, bool $end_other_sessions = false ): void {
	fisha_studio_2fa_drop( $token );
	fisha_studio_2fa_cookie( '' );
	fisha_studio_login_clear( $u->user_login, $ip );
	// Enrolment ends every earlier session (some may have started with the password alone, before 2-step sign-in).
	if ( $end_other_sessions ) WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
	// The only place an owner's auth cookie may be sent (see fisha_studio_2fa_guard_cookies()).
	$GLOBALS['fisha_studio_2fa_ok'] = $u->ID;
	try {
		wp_set_auth_cookie( $u->ID, false, is_ssl() );
	} finally {
		unset( $GLOBALS['fisha_studio_2fa_ok'] );
	}
	wp_set_current_user( $u->ID );
	do_action( 'wp_login', $u->user_login, $u );
	fisha_studio_log( 'user', $u->ID, $action, null, array( 'ip' => $ip ) );
}

/** A wrong code: counts it for the 24-hour limit and for this token (3 per token) and returns the error to show. */
function fisha_studio_2fa_wrong( string $token, WP_User $u, string $ip, string $message ): WP_Error {
	$day = fisha_studio_counter_incr( 'fs_2fa_day_' . $u->ID, DAY_IN_SECONDS );
	if ( $day >= FISHA_STUDIO_2FA_DAY_TRIES ) {
		if ( FISHA_STUDIO_2FA_DAY_TRIES === $day ) fisha_studio_log( 'user', $u->ID, '2fa-code-lock', null, array( 'ip' => $ip ) );
		fisha_studio_2fa_drop( $token );
		return fisha_studio_2fa_day_lock_error();
	}
	if ( fisha_studio_counter_incr( 'fs_2fa_tok_' . hash( 'sha256', $token ), FISHA_STUDIO_2FA_TTL ) >= FISHA_STUDIO_2FA_TOKEN_TRIES ) {
		fisha_studio_2fa_drop( $token );
		return new WP_Error( 'fisha_studio_2fa_expired', 'Too many wrong codes. Please sign in again.' );
	}
	return new WP_Error( 'fisha_studio_2fa_wrong', $message );
}

/**
 * The code step. With 2-step sign-in on: a 6-digit app code or a backup code; signs in and returns
 * { done: true, user, backup_left (null unless a backup code was used) }. During setup: the first app code confirms the
 * new secret, turns it on and returns { done: false, step: 'codes', codes } (shown once; the session starts on "saved").
 * Every try first takes a slot in the 5-tries/15-minute lock (atomic, so parallel requests cannot get around it);
 * wrong codes also count toward 3 per token and 20 per account in 24 hours.
 * @return array|WP_Error
 */
function fisha_studio_2fa_code_step( string $token, string $code, string $ip, string $ua, bool $allow_setup = true ) {
	$p = fisha_studio_2fa_pending_get( $token, $ip, $ua );
	if ( ! $p || ! empty( $p['confirmed'] ) || ( ! $allow_setup && ! empty( $p['setup'] ) ) ) return fisha_studio_2fa_expired();
	$u = $p['user'];
	if ( fisha_studio_2fa_day_locked( $u->ID ) ) {
		fisha_studio_2fa_drop( $token );
		return fisha_studio_2fa_day_lock_error();
	}
	if ( ! fisha_studio_login_reserve( $u->user_login, $ip ) ) {
		fisha_studio_2fa_drop( $token );
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.' );
	}
	$code = trim( $code );
	if ( ! empty( $p['setup'] ) ) {
		// Set up meanwhile through another sign-in: this older setup must never replace the working authenticator.
		if ( fisha_studio_2fa_enabled( $u->ID ) ) {
			fisha_studio_2fa_drop( $token );
			return fisha_studio_2fa_expired();
		}
		$secret  = fisha_studio_2fa_decrypt( (string) ( $p['secret'] ?? '' ) );
		$counter = null === $secret ? null : fisha_studio_totp_match( $secret, $code, -1 );
		if ( null === $counter ) return fisha_studio_2fa_wrong( $token, $u, $ip, 'That code is not right. Please check the app and try again.' );
		if ( ! fisha_studio_2fa_enable( $u->ID, $secret, $counter ) ) {
			fisha_studio_2fa_drop( $token );
			return fisha_studio_2fa_expired();
		}
		$codes = fisha_studio_2fa_new_backup_codes( $u->ID );
		fisha_studio_log( 'user', $u->ID, '2fa-setup', null, array( 'ip' => $ip ) );
		unset( $p['secret'], $p['user'] );
		$p['confirmed'] = true;
		$p['secret_fp'] = hash( 'sha256', $secret );
		$p['created']   = time();
		set_transient( fisha_studio_2fa_pending_key( $token ), $p, FISHA_STUDIO_2FA_TTL );
		return array( 'done' => false, 'step' => 'codes', 'codes' => $codes, 'user' => $u );
	}
	$is_app = (bool) preg_match( '/^\d{6}$/', preg_replace( '/\s+/', '', $code ) );
	$ok     = fisha_studio_2fa_enabled( $u->ID ) && ( $is_app ? fisha_studio_totp_verify( $u->ID, $code ) : fisha_studio_2fa_backup_use( $u->ID, $code ) );
	if ( ! $ok ) {
		return fisha_studio_2fa_wrong( $token, $u, $ip, $is_app ? 'That code is not right. Please check the app and try again.' : 'That backup code is not right or was already used.' );
	}
	fisha_studio_2fa_finish( $token, $u, $ip, $is_app ? '2fa-signin' : 'backup-code' );
	return array( 'done' => true, 'user' => $u, 'backup_left' => $is_app ? null : fisha_studio_2fa_backup_left( $u->ID ) );
}

/**
 * After setup, "I have saved these codes": starts the session and ends every older one. Only for the token that
 * enrolled the authenticator now in place (a reset or another enrolment in between ends it).
 * @return WP_User|WP_Error
 */
function fisha_studio_2fa_saved_step( string $token, string $ip, string $ua ) {
	$p = fisha_studio_2fa_pending_get( $token, $ip, $ua );
	if ( ! $p || empty( $p['setup'] ) || empty( $p['confirmed'] ) || ! fisha_studio_2fa_enabled( $p['user']->ID ) ) return fisha_studio_2fa_expired();
	$now = fisha_studio_2fa_secret( $p['user']->ID );
	if ( null === $now || ! hash_equals( (string) ( $p['secret_fp'] ?? '' ), hash( 'sha256', $now ) ) ) {
		fisha_studio_2fa_drop( $token );
		return fisha_studio_2fa_expired();
	}
	fisha_studio_2fa_finish( $token, $p['user'], $ip, '2fa-signin', true );
	return $p['user'];
}

/** Plain-English note after a backup-code sign-in. */
function fisha_studio_2fa_backup_note( int $left ): string {
	$note = 1 === $left ? 'You have 1 backup code left.' : "You have $left backup codes left.";
	if ( $left <= 2 ) $note .= ' Please ask your web developer to make new backup codes for you soon.';
	return $note;
}

/* ---------- closing the other ways in ---------- */

/** May an auth cookie be sent for this user now? Always for non-owners; for an owner only inside fisha_studio_2fa_finish(). */
function fisha_studio_2fa_cookie_allowed( int $uid ): bool {
	if ( $uid <= 0 ) return true;
	$u = get_user_by( 'id', $uid );
	if ( ! $u || ! fisha_studio_is_owner( $u ) ) return true;
	return ( $GLOBALS['fisha_studio_2fa_ok'] ?? 0 ) === $uid;
}

/**
 * One guard for every wp_set_auth_cookie() caller (WooCommerce password reset, wp_update_user, any plugin): an owner's
 * cookie is not sent, the session token just made for it is destroyed and the owner is not the current user.
 * (WordPress fires set_auth_cookie / set_logged_in_cookie before this filter; those cookies never leave the server.)
 */
function fisha_studio_2fa_guard_cookies( $send, $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ) {
	$user_id = (int) $user_id;
	if ( fisha_studio_2fa_cookie_allowed( $user_id ) ) return $send;
	if ( is_string( $token ) && '' !== $token ) WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	if ( get_current_user_id() === $user_id ) wp_set_current_user( 0 );
	return false;
}
add_filter( 'send_auth_cookies', 'fisha_studio_2fa_guard_cookies', PHP_INT_MAX - 1, 6 );

/** Where to send an owner after a WooCommerce My Account password reset (the new password is saved by then), or null. */
function fisha_studio_2fa_reset_redirect( WP_User $u ): ?string {
	return fisha_studio_is_owner( $u ) ? home_url( '/studio/login/?reset=1' ) : null;
}
function fisha_studio_2fa_after_wc_reset( $user ): void {
	$to = $user instanceof WP_User ? fisha_studio_2fa_reset_redirect( $user ) : null;
	if ( ! $to ) return;
	wp_safe_redirect( $to );
	exit;
}
add_action( 'woocommerce_customer_reset_password', 'fisha_studio_2fa_after_wc_reset' );

// Owners sign in only through the Studio's own check (password, then code). This covers wp-login.php, the My Account
// form, XML-RPC and any other wp_authenticate() caller, whether the password was right or not, so it is no password oracle.
// The lock error (priority 99) is kept so a locked owner sees that message. Wrong tries still count (wp_login_failed).
add_filter( 'authenticate', function ( $user, $login = '' ) {
	if ( ! empty( $GLOBALS['fisha_studio_in_check'] ) ) return $user;
	if ( is_wp_error( $user ) && 'fisha_studio_locked' === $user->get_error_code() ) return $user;
	$owner = ( $user instanceof WP_User && fisha_studio_is_owner( $user ) );
	if ( ! $owner && is_string( $login ) && '' !== trim( $login ) ) {
		$named = fisha_studio_login_user( $login );
		$owner = $named && fisha_studio_is_owner( $named );
	}
	return $owner ? new WP_Error( 'fisha_studio_use_studio', 'Please sign in at /studio/login/.' ) : $user;
}, 100, 2 );

add_filter( 'wp_is_application_passwords_available_for_user', function ( $available, $user ) {
	return ( $user instanceof WP_User && fisha_studio_is_owner( $user ) ) ? false : $available;
}, 10, 2 );

/* ---------- WP-CLI ---------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$fisha_studio_cli_owner = function ( array $args, string $usage ): WP_User {
		if ( empty( $args[0] ) ) WP_CLI::error( $usage );
		$u = fisha_studio_login_user( (string) $args[0] );
		if ( ! $u || ! fisha_studio_is_owner( $u ) ) WP_CLI::error( "No Studio owner '{$args[0]}'." );
		return $u;
	};
	WP_CLI::add_command( 'fisha-studio reset-2fa', function ( $args ) use ( $fisha_studio_cli_owner ) {
		$u = $fisha_studio_cli_owner( $args, 'Usage: wp fisha-studio reset-2fa <login>' );
		fisha_studio_2fa_reset( $u->ID );
		WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
		fisha_studio_log( 'user', $u->ID, '2fa-reset', null, array( 'by' => 'wp-cli' ) );
		WP_CLI::success( "2-step sign-in is off for '{$u->user_login}' and all their sessions ended. They set it up again at their next sign-in at /studio/login/." );
	} );
	WP_CLI::add_command( 'fisha-studio backup-codes', function ( $args ) use ( $fisha_studio_cli_owner ) {
		$u = $fisha_studio_cli_owner( $args, 'Usage: wp fisha-studio backup-codes <login>' );
		if ( ! fisha_studio_2fa_enabled( $u->ID ) ) WP_CLI::error( "'{$u->user_login}' has not set up 2-step sign-in yet." );
		$codes = fisha_studio_2fa_new_backup_codes( $u->ID );
		fisha_studio_log( 'user', $u->ID, 'backup-codes-new', null, array( 'by' => 'wp-cli' ) );
		WP_CLI::line( "New backup codes for '{$u->user_login}' (old ones no longer work). Each works once. They are not shown again:" );
		foreach ( $codes as $c ) WP_CLI::line( '  ' . $c );
		WP_CLI::success( '10 backup codes made.' );
	} );
}
