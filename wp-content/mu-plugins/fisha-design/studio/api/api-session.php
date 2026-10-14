<?php
/**
 * Fisha Studio REST: who is signed in (and a fresh nonce), and signing in again from inside the app.
 * Owners sign in again in two calls: POST /login (password) returns { step: 'code', token } without a session,
 * then POST /login/code { code } (app code or backup code; token from the cookie or the body) starts it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/session', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_session', 'permission_callback' => fisha_studio_permission() ) );
	register_rest_route( FISHA_STUDIO_NS, '/login', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_login', 'permission_callback' => '__return_true' ) );
	register_rest_route( FISHA_STUDIO_NS, '/login/code', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_login_code', 'permission_callback' => '__return_true' ) );
} );

function fisha_studio_rest_session(): array {
	$u = wp_get_current_user();
	return array( 'user' => array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email ), 'nonce' => wp_create_nonce( 'wp_rest' ) );
}

function fisha_studio_rest_login_error( WP_Error $e ): WP_Error {
	$status = array( 'fisha_studio_locked' => 429, 'fisha_studio_2fa_setup' => 403 )[ $e->get_error_code() ] ?? 401;
	return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $status ) );
}

function fisha_studio_rest_login( WP_REST_Request $r ) {
	$b = fisha_studio_body( $r );
	$u = fisha_studio_sign_in( (string) ( $b['login'] ?? '' ), (string) ( $b['password'] ?? '' ), fisha_client_ip(), null, false, fisha_studio_2fa_rest_cookie_path() );
	if ( is_wp_error( $u ) ) return fisha_studio_rest_login_error( $u );
	if ( is_array( $u ) ) return array( 'step' => 'code', 'token' => $u['token'] );
	return array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'user' => array( 'id' => $u->ID, 'name' => $u->display_name ) );
}

function fisha_studio_rest_login_code( WP_REST_Request $r ) {
	$b     = fisha_studio_body( $r );
	$token = (string) ( $_COOKIE[ FISHA_STUDIO_2FA_COOKIE ] ?? '' );
	if ( '' === $token ) $token = (string) ( $b['token'] ?? '' );
	$res = fisha_studio_2fa_code_step( $token, (string) ( $b['code'] ?? '' ), fisha_client_ip(), fisha_studio_2fa_ua(), false );
	if ( is_wp_error( $res ) ) return fisha_studio_rest_login_error( $res );
	if ( empty( $res['done'] ) ) return fisha_studio_rest_login_error( fisha_studio_2fa_expired() ); // setup is only on /studio/login/
	$u = $res['user'];
	return array(
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'user'        => array( 'id' => $u->ID, 'name' => $u->display_name ),
		'backup_left' => $res['backup_left'],
		'note'        => null === $res['backup_left'] ? '' : fisha_studio_2fa_backup_note( (int) $res['backup_left'] ),
	);
}
