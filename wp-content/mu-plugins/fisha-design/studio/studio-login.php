<?php
/**
 * Fisha Studio sign-in: checks a login against WordPress, only lets Studio users in, and locks a Studio account
 * (owner or admin) or an IP for 15 minutes after 5 failed tries. The lock also holds on wp-login.php and My Account
 * for Studio accounts, so it cannot be side-stepped; customers there are never counted or locked by this code.
 * The 15 minutes run from the first failure (a fixed window), so further tries do not extend a lock.
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

/** Failures in the current window, 0 when there are none or the window (15 min from the first failure) has ended. */
function fisha_studio_login_count( string $key ): int {
	$rec = get_transient( $key );
	if ( ! is_array( $rec ) || time() >= (int) ( $rec['first'] ?? 0 ) + FISHA_STUDIO_LOGIN_LOCK ) return 0;
	return (int) ( $rec['count'] ?? 0 );
}

function fisha_studio_login_bump( string $key ): void {
	$now = time();
	$rec = get_transient( $key );
	if ( ! is_array( $rec ) || $now >= (int) ( $rec['first'] ?? 0 ) + FISHA_STUDIO_LOGIN_LOCK ) $rec = array( 'first' => $now, 'count' => 0 );
	$rec['count'] = (int) $rec['count'] + 1;
	// Expires when the window ends: a later failure keeps the original end time instead of starting a new 15 minutes.
	set_transient( $key, $rec, max( 1, (int) $rec['first'] + FISHA_STUDIO_LOGIN_LOCK - $now ) );
}

function fisha_studio_login_locked( string $login, string $ip ): bool {
	return ( '' !== trim( $login ) && fisha_studio_login_count( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) ) >= FISHA_STUDIO_LOGIN_TRIES )
		|| ( '' !== $ip && fisha_studio_login_count( fisha_studio_login_key( 'ip', $ip ) ) >= FISHA_STUDIO_LOGIN_TRIES );
}
function fisha_studio_login_failed( string $login, string $ip, bool $count_account = true ): void {
	if ( $count_account && '' !== trim( $login ) ) fisha_studio_login_bump( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) );
	if ( '' !== $ip ) fisha_studio_login_bump( fisha_studio_login_key( 'ip', $ip ) );
}
function fisha_studio_login_clear( string $login, string $ip ): void {
	if ( '' !== trim( $login ) ) delete_transient( fisha_studio_login_key( 'u', fisha_studio_login_account( $login ) ) );
	if ( '' !== $ip ) delete_transient( fisha_studio_login_key( 'ip', $ip ) );
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
	fisha_studio_login_clear( $login, $ip );
	return $user;
}

/** Checks credentials and starts a session (sets the auth cookie). @return WP_User|WP_Error */
function fisha_studio_sign_in( string $login, string $password, string $ip ) {
	$user = fisha_studio_check_credentials( $login, $password, $ip );
	if ( is_wp_error( $user ) ) return $user;
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
// A fresh REST nonce right after signing in needs the new cookie in this request too.
add_action( 'set_logged_in_cookie', function ( $cookie ) { $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; } );
