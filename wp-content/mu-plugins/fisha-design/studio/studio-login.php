<?php
/**
 * Fisha Studio sign-in: checks a login against WordPress, only lets Studio users in, and locks a Studio account
 * (owner or admin) or an IP for 15 minutes after 5 failed tries. The lock also holds on wp-login.php and My Account
 * for Studio accounts, so it cannot be side-stepped; customers there are never counted or locked by this code.
 * The 15 minutes run from the first failure (a fixed window), so further tries do not extend a lock.
 * A shop owner never gets a session from the password alone: see studio-2fa.php for the code step.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_LOGIN_TRIES', 5 );
define( 'FISHA_STUDIO_LOGIN_LOCK', 15 * MINUTE_IN_SECONDS );

function fisha_studio_login_key( string $kind, string $value ): string {
	return 'fs_login_' . $kind . '_' . md5( strtolower( trim( $value ) ) );
}

/** The user a typed login (username or email) names, or null. */
function fisha_studio_login_user( string $login ): ?WP_User {
	$login = trim( $login );
	if ( '' === $login ) return null;
	$u = get_user_by( 'login', $login );
	if ( ! $u && is_email( $login ) ) $u = get_user_by( 'email', $login );
	return $u ?: null;
}

/** What the account counter is keyed on: the user ID when the login names a user (username and email share it), else the typed text. */
function fisha_studio_login_account( string $login ): string {
	$u = fisha_studio_login_user( $login );
	return $u ? 'id:' . $u->ID : strtolower( trim( $login ) );
}

/** A Studio account: an existing user with the fisha_studio capability (owners and admins). */
function fisha_studio_login_is_studio( string $login ): bool {
	$u = fisha_studio_login_user( $login );
	return $u && user_can( $u, 'fisha_studio' );
}

/* Atomic counters with a fixed window: one autoload-off options row "first:count", changed only by single SQL
   statements, so parallel requests cannot lose an increment. The window runs from the first count; when it has
   ended, the next increment starts a new one. Used for every sign-in failure counter (password and code). */

function fisha_studio_counter_option( string $name ): string {
	return 'fisha_ctr_' . $name;
}

/** Adds one and returns the count in the current window (at least this call's own count). */
function fisha_studio_counter_incr( string $name, int $window ): int {
	global $wpdb;
	$now = time();
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )
		ON DUPLICATE KEY UPDATE option_value = IF(
			CAST( SUBSTRING_INDEX( option_value, ':', 1 ) AS UNSIGNED ) + %d <= %d,
			%s,
			CONCAT( SUBSTRING_INDEX( option_value, ':', 1 ), ':', CAST( SUBSTRING_INDEX( option_value, ':', -1 ) AS UNSIGNED ) + 1 ) )",
		fisha_studio_counter_option( $name ), $now . ':1', $window, $now, $now . ':1'
	) );
	return max( 1, fisha_studio_counter_get( $name, $window ) );
}

/** The count in the current window, 0 when there is none or the window has ended. Reads the database, never a cache. */
function fisha_studio_counter_get( string $name, int $window ): int {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", fisha_studio_counter_option( $name ) ) );
	if ( ! is_string( $v ) || ! preg_match( '/^(\d+):(\d+)$/', $v, $m ) ) return 0;
	return time() >= (int) $m[1] + $window ? 0 : (int) $m[2];
}

/** Deletes the rows of counters named $prefix* whose window has ended (abandoned sign-ins, old IP counts). */
function fisha_studio_counter_sweep( string $prefix, int $window ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST( SUBSTRING_INDEX( option_value, ':', 1 ) AS UNSIGNED ) + %d <= %d",
		$wpdb->esc_like( fisha_studio_counter_option( $prefix ) ) . '%', $window, time()
	) );
}

function fisha_studio_counter_clear( string $name ): void {
	global $wpdb;
	$wpdb->delete( $wpdb->options, array( 'option_name' => fisha_studio_counter_option( $name ) ) );
}

/** Failures in the current window, 0 when there are none or the window (15 min from the first failure) has ended. */
function fisha_studio_login_count( string $key ): int {
	return fisha_studio_counter_get( $key, FISHA_STUDIO_LOGIN_LOCK );
}

function fisha_studio_login_bump( string $key ): int {
	return fisha_studio_counter_incr( $key, FISHA_STUDIO_LOGIN_LOCK );
}

function fisha_studio_login_locked( string $login, string $ip ): bool {
	return ( '' !== trim( $login ) && fisha_studio_login_count( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) ) >= FISHA_STUDIO_LOGIN_TRIES )
		|| ( '' !== $ip && fisha_studio_login_count( fisha_studio_login_key( 'ip', $ip ) ) >= FISHA_STUDIO_LOGIN_TRIES );
}
function fisha_studio_login_failed( string $login, string $ip, bool $count_account = true ): void {
	if ( $count_account && '' !== trim( $login ) ) fisha_studio_login_bump( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) );
	if ( '' !== $ip ) fisha_studio_login_bump( fisha_studio_login_key( 'ip', $ip ) );
}
/**
 * Counts a try as failed before it is checked (the success path clears the counters again), and says whether it may
 * go ahead. Parallel requests each take their own slot, so no more than FISHA_STUDIO_LOGIN_TRIES are ever checked.
 */
function fisha_studio_login_reserve( string $login, string $ip ): bool {
	$ok = true;
	if ( '' !== trim( $login ) ) $ok = fisha_studio_login_bump( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) ) <= FISHA_STUDIO_LOGIN_TRIES && $ok;
	if ( '' !== $ip ) $ok = fisha_studio_login_bump( fisha_studio_login_key( 'ip', $ip ) ) <= FISHA_STUDIO_LOGIN_TRIES && $ok;
	return $ok;
}
function fisha_studio_login_clear( string $login, string $ip ): void {
	if ( '' !== trim( $login ) ) fisha_studio_counter_clear( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) );
	if ( '' !== $ip ) fisha_studio_counter_clear( fisha_studio_login_key( 'ip', $ip ) );
}

/** @return WP_User|WP_Error */
function fisha_studio_check_credentials( string $login, string $password, string $ip ) {
	$login = trim( $login );
	if ( fisha_studio_login_locked( $login, $ip ) ) {
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.' );
	}
	$GLOBALS['fisha_studio_in_check'] = true;
	$user = wp_authenticate( $login, $password );
	unset( $GLOBALS['fisha_studio_in_check'] );
	if ( is_wp_error( $user ) || ! user_can( $user, 'fisha_studio' ) ) {
		// The Studio form always counts its IP. The account counter only for Studio accounts or unknown logins,
		// so a customer's account is never locked out of My Account by tries on the Studio form.
		fisha_studio_login_failed( $login, $ip, ! fisha_studio_login_user( $login ) || fisha_studio_login_is_studio( $login ) );
		return new WP_Error( 'fisha_studio_invalid', 'That email/username and password do not match.' );
	}
	// An owner's counters are cleared only after the code step too, so the right password cannot reset the count of wrong codes.
	if ( ! fisha_studio_is_owner( $user ) ) fisha_studio_login_clear( $login, $ip );
	return $user;
}

/**
 * Checks credentials. An admin with Studio access gets a session at once (auth cookie set, returns the WP_User).
 * An owner never does: a pending 2-step sign-in starts instead and this returns { step: 'code' | 'setup', token, user }
 * (the token is also set as the fisha_studio_2fa cookie on $cookie_path). With $allow_setup false (the in-app sign-in),
 * an owner who has not set up 2-step sign-in gets an error.
 * @return WP_User|array|WP_Error
 */
function fisha_studio_sign_in( string $login, string $password, string $ip, ?string $ua = null, bool $allow_setup = true, string $cookie_path = '/studio/' ) {
	$user = fisha_studio_check_credentials( $login, $password, $ip );
	if ( is_wp_error( $user ) ) return $user;
	if ( fisha_studio_is_owner( $user ) ) {
		$enabled = fisha_studio_2fa_enabled( $user->ID );
		if ( ! $enabled && ! $allow_setup ) {
			return new WP_Error( 'fisha_studio_2fa_setup', 'Please sign in at /studio/login/ to set up 2-step sign-in.' );
		}
		$token = fisha_studio_2fa_pending_start( $user, $ip, $ua ?? fisha_studio_2fa_ua(), ! $enabled );
		fisha_studio_2fa_cookie( $token, $cookie_path );
		return array( 'step' => $enabled ? 'code' : 'setup', 'token' => $token, 'user' => $user );
	}
	wp_set_auth_cookie( $user->ID, false, is_ssl() );
	wp_set_current_user( $user->ID );
	do_action( 'wp_login', $user->user_login, $user );
	return $user;
}

// The same lock on wp-login.php and the My Account form, for Studio accounts only.
add_filter( 'authenticate', function ( $user, $login ) {
	if ( ! empty( $GLOBALS['fisha_studio_in_check'] ) || ! $login || ! fisha_studio_login_is_studio( (string) $login ) ) return $user;
	return fisha_studio_login_locked( (string) $login, fisha_client_ip() )
		? new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.' )
		: $user;
}, 99, 2 );
add_action( 'wp_login_failed', function ( $login ) {
	if ( empty( $GLOBALS['fisha_studio_in_check'] ) && fisha_studio_login_is_studio( (string) $login ) ) fisha_studio_login_failed( (string) $login, fisha_client_ip() );
} );
// A fresh REST nonce right after signing in needs the new cookie in this request too (never for an owner the
// 2-step guard in studio-2fa.php refuses).
add_action( 'set_logged_in_cookie', function ( $cookie, $expire = 0, $expiration = 0, $user_id = 0 ) {
	if ( function_exists( 'fisha_studio_2fa_cookie_allowed' ) && ! fisha_studio_2fa_cookie_allowed( (int) $user_id ) ) return;
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
}, 10, 4 );
