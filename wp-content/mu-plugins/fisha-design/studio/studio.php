<?php
/**
 * Fisha Studio: the shop owner's catalog panel at /studio
 * (spec: docs/superpowers/specs/2026-10-12-fisha-studio-design.md).
 * This file defines the constants, loads the Studio parts and serves the /studio pages.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_DIR', FISHA_DESIGN_DIR . 'studio/' );
define( 'FISHA_STUDIO_URL', FISHA_DESIGN_URL . 'studio/' );
define( 'FISHA_STUDIO_NS', 'fisha-studio/v1' );
define( 'FISHA_STUDIO_ROLE', 'fisha_owner' );

/** 'app' | 'login' | 'logout' for a request path (relative to the site root), or null when it is not a Studio URL. */
function fisha_studio_route_for_path( string $path ): ?string {
	$path = trim( $path, '/' );
	if ( 'studio' === $path ) return 'app';
	if ( 0 !== strpos( $path, 'studio/' ) ) return null;
	$rest = trim( substr( $path, 7 ), '/' );
	return in_array( $rest, array( 'login', 'logout' ), true ) ? $rest : 'app';
}

require_once FISHA_STUDIO_DIR . 'studio-role.php';
require_once FISHA_STUDIO_DIR . 'studio-gates.php';

require_once FISHA_STUDIO_DIR . 'studio-login.php';

/** Per-request nonce for the one inline <script type="importmap">. */
function fisha_studio_csp_nonce(): string {
	static $nonce = null;
	return $nonce ?? ( $nonce = base64_encode( random_bytes( 16 ) ) );
}

function fisha_studio_page_headers(): void {
	nocache_headers();
	do_action( 'litespeed_control_set_nocache', 'fisha studio' );
	header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . fisha_studio_csp_nonce() . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'" );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: same-origin' );
}

/** Maps every Studio JS module URL to the same URL + ?v=<mtime>, so a deploy never leaves a stale module in a browser cache. */
function fisha_studio_import_map(): array {
	$imports = array();
	$base    = FISHA_STUDIO_DIR . 'app/js/';
	$files   = array_merge( glob( $base . '*.js' ) ?: array(), glob( $base . '*/*.js' ) ?: array() );
	foreach ( $files as $file ) {
		$rel = substr( $file, strlen( FISHA_STUDIO_DIR ) );
		$url = FISHA_STUDIO_URL . $rel;
		$imports[ $url ] = $url . '?v=' . filemtime( $file );
	}
	return array( 'imports' => $imports );
}

function fisha_studio_login_page(): void {
	if ( current_user_can( 'fisha_studio' ) ) {
		wp_safe_redirect( home_url( '/studio/' ) );
		exit;
	}
	$error = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fs_nonce'] ?? '' ) ), 'fisha_studio_login' ) ) {
			$error = 'This page was open for a long time. Please try again.';
		} else {
			$r = fisha_studio_sign_in( (string) wp_unslash( $_POST['log'] ?? '' ), (string) wp_unslash( $_POST['pwd'] ?? '' ), fisha_client_ip() );
			if ( ! is_wp_error( $r ) ) {
				wp_safe_redirect( home_url( '/studio/' ) );
				exit;
			}
			$error = $r->get_error_message();
		}
	}
	status_header( 200 );
	require FISHA_STUDIO_DIR . 'app/login.php';
}

add_action( 'parse_request', function ( $wp ) {
	$route = fisha_studio_route_for_path( (string) $wp->request );
	if ( ! $route ) return;
	fisha_studio_page_headers();
	if ( 'logout' === $route ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'fisha_studio_logout' ) ) wp_logout();
		wp_safe_redirect( home_url( '/studio/login/' ) );
		exit;
	}
	if ( 'login' === $route ) {
		fisha_studio_login_page();
		exit;
	}
	if ( ! current_user_can( 'fisha_studio' ) ) {
		wp_safe_redirect( home_url( '/studio/login/' ) );
		exit;
	}
	status_header( 200 );
	require FISHA_STUDIO_DIR . 'app/index.php';
	exit;
}, 0 );
require_once FISHA_STUDIO_DIR . 'studio-urls.php';
require_once FISHA_STUDIO_DIR . 'studio-fields.php';

require_once FISHA_STUDIO_DIR . 'studio-audit.php';
require_once FISHA_STUDIO_DIR . 'studio-drafts.php';
require_once FISHA_STUDIO_DIR . 'studio-publish.php';
