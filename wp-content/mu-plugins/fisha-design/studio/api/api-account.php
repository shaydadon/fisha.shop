<?php
/**
 * Fisha Studio REST: My account (details, change password, new backup codes, sign out of other devices).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$me = fisha_studio_permission();
	register_rest_route( FISHA_STUDIO_NS, '/account', array( 'methods' => 'GET', 'callback' => function () { return fisha_studio_account_info( wp_get_current_user() ); }, 'permission_callback' => $me ) );
	register_rest_route( FISHA_STUDIO_NS, '/account/password', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$b   = fisha_studio_body( $r );
		$res = fisha_studio_change_password( wp_get_current_user(), (string) ( $b['current'] ?? '' ), (string) ( $b['new'] ?? '' ), (string) ( $b['confirm'] ?? '' ), fisha_client_ip() );
		return is_wp_error( $res ) ? $res : array( 'nonce' => wp_create_nonce( 'wp_rest' ) );
	}, 'permission_callback' => $me ) );
	register_rest_route( FISHA_STUDIO_NS, '/account/backup-codes', array( 'methods' => 'POST', 'callback' => function ( WP_REST_Request $r ) {
		$res = fisha_studio_new_backup_codes_checked( wp_get_current_user(), (string) ( fisha_studio_body( $r )['code'] ?? '' ), fisha_client_ip() );
		return is_wp_error( $res ) ? $res : array( 'codes' => $res );
	}, 'permission_callback' => $me ) );
	register_rest_route( FISHA_STUDIO_NS, '/account/sign-out-others', array( 'methods' => 'POST', 'callback' => function () {
		fisha_studio_sign_out_others( wp_get_current_user() );
		return array( 'ok' => true );
	}, 'permission_callback' => $me ) );
} );
