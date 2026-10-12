<?php
/**
 * Fisha Studio REST: who is signed in (and a fresh nonce), and signing in again from inside the app.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/session', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_session', 'permission_callback' => fisha_studio_permission() ) );
	register_rest_route( FISHA_STUDIO_NS, '/login', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_login', 'permission_callback' => '__return_true' ) );
} );

function fisha_studio_rest_session(): array {
	$u = wp_get_current_user();
	return array( 'user' => array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email ), 'nonce' => wp_create_nonce( 'wp_rest' ) );
}

function fisha_studio_rest_login( WP_REST_Request $r ) {
	$b = fisha_studio_body( $r );
	$u = fisha_studio_sign_in( (string) ( $b['login'] ?? '' ), (string) ( $b['password'] ?? '' ), fisha_client_ip() );
	if ( is_wp_error( $u ) ) {
		return new WP_Error( $u->get_error_code(), $u->get_error_message(), array( 'status' => 'fisha_studio_locked' === $u->get_error_code() ? 429 : 401 ) );
	}
	return array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'user' => array( 'id' => $u->ID, 'name' => $u->display_name ) );
}
