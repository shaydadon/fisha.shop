<?php
/**
 * Fisha Studio sign-in: checks a login against WordPress, only lets Studio users in, and locks an account or IP for
 * 15 minutes after 5 failed tries (also for wp-login.php, so the lock cannot be side-stepped).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_LOGIN_TRIES', 5 );
define( 'FISHA_STUDIO_LOGIN_LOCK', 15 * MINUTE_IN_SECONDS );

function fisha_studio_login_key( string $kind, string $value ): string {
	return 'fs_login_' . $kind . '_' . md5( strtolower( trim( $value ) ) );
}
function fisha_studio_login_locked( string $login, string $ip ): bool {
	return ( '' !== $login && (int) get_transient( fisha_studio_login_key( 'u', $login ) ) >= FISHA_STUDIO_LOGIN_TRIES )
		|| ( '' !== $ip && (int) get_transient( fisha_studio_login_key( 'ip', $ip ) ) >= FISHA_STUDIO_LOGIN_TRIES );
}
function fisha_studio_login_failed( string $login, string $ip ): void {
	foreach ( array( 'u' => $login, 'ip' => $ip ) as $kind => $value ) {
		if ( '' === $value ) continue;
		$key = fisha_studio_login_key( $kind, $value );
		set_transient( $key, (int) get_transient( $key ) + 1, FISHA_STUDIO_LOGIN_LOCK );
	}
}
function fisha_studio_login_clear( string $login, string $ip ): void {
	if ( '' !== $login ) delete_transient( fisha_studio_login_key( 'u', $login ) );
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
		fisha_studio_login_failed( $login, $ip );
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

// The same lock on wp-login.php and the My Account form.
add_filter( 'authenticate', function ( $user, $login ) {
	if ( ! empty( $GLOBALS['fisha_studio_in_check'] ) || ! $login ) return $user;
	return fisha_studio_login_locked( (string) $login, fisha_client_ip() )
		? new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.' )
		: $user;
}, 99, 2 );
add_action( 'wp_login_failed', function ( $login ) {
	if ( empty( $GLOBALS['fisha_studio_in_check'] ) ) fisha_studio_login_failed( (string) $login, fisha_client_ip() );
} );
// A fresh REST nonce right after signing in needs the new cookie in this request too.
add_action( 'set_logged_in_cookie', function ( $cookie ) { $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; } );
