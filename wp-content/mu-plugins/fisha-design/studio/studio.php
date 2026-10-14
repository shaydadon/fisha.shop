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
require_once FISHA_STUDIO_DIR . 'studio-2fa.php';

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

/**
 * The no-JS sign-in page. Step 1 is the password. An owner then gets the code step (app code or backup code), or the
 * setup step (QR code and key) and then their backup codes, shown once. The pending sign-in lives in the
 * fisha_studio_2fa cookie (see studio-2fa.php); the session starts only after the code step.
 */
function fisha_studio_login_page(): void {
	if ( current_user_can( 'fisha_studio' ) ) {
		wp_safe_redirect( home_url( '/studio/' ) );
		exit;
	}
	$ip      = fisha_client_ip();
	$ua      = fisha_studio_2fa_ua();
	$token   = preg_replace( '/[^0-9a-f]/', '', (string) ( $_COOKIE[ FISHA_STUDIO_2FA_COOKIE ] ?? '' ) );
	$pending = '' !== $token ? fisha_studio_2fa_pending_get( $token, $ip, $ua ) : null;
	$error   = '';
	$notice  = '';
	$step    = '';
	$codes   = array();
	$setup   = null;
	$mode    = ( isset( $_GET['backup'] ) || 'backup' === ( $_POST['fs_mode'] ?? '' ) ) ? 'backup' : 'code';
	$restart = function () use ( &$pending, $token ) {
		if ( '' !== $token ) fisha_studio_2fa_drop( $token );
		fisha_studio_2fa_cookie( '' );
		$pending = null;
	};

	if ( isset( $_GET['restart'] ) && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) $restart();
	if ( isset( $_GET['reset'] ) && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) $notice = 'Your password was changed. Please sign in.';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$posted = sanitize_key( $_POST['fs_step'] ?? 'password' );
		if ( 'password' === $posted ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fs_nonce'] ?? '' ) ), 'fisha_studio_login' ) ) {
				$error = 'This page was open for a long time. Please try again.';
			} else {
				$r = fisha_studio_sign_in( (string) wp_unslash( $_POST['log'] ?? '' ), (string) wp_unslash( $_POST['pwd'] ?? '' ), $ip, $ua );
				if ( is_wp_error( $r ) ) {
					$error = $r->get_error_message();
				} else {
					// An admin is signed in now; an owner goes on to the code (or setup) step, which this page shows on GET.
					wp_safe_redirect( home_url( $r instanceof WP_User ? '/studio/' : '/studio/login/' ) );
					exit;
				}
			}
		} elseif ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fs_nonce'] ?? '' ) ), 'fisha_studio_2fa' ) ) {
			$error = 'This page was open for a long time. Please try again.';
		} elseif ( ! $pending ) {
			$restart();
			$error = fisha_studio_2fa_expired()->get_error_message();
		} elseif ( 'saved' === $posted ) {
			$r = fisha_studio_2fa_saved_step( $token, $ip, $ua );
			if ( ! is_wp_error( $r ) ) {
				wp_safe_redirect( home_url( '/studio/' ) );
				exit;
			}
			$restart();
			$error = $r->get_error_message();
		} else {
			$r = fisha_studio_2fa_code_step( $token, (string) wp_unslash( $_POST['code'] ?? '' ), $ip, $ua );
			if ( is_wp_error( $r ) ) {
				$error = $r->get_error_message();
				if ( 'fisha_studio_2fa_wrong' !== $r->get_error_code() ) $restart();
			} elseif ( ! empty( $r['done'] ) && null === $r['backup_left'] ) {
				wp_safe_redirect( home_url( '/studio/' ) );
				exit;
			} elseif ( ! empty( $r['done'] ) ) {
				$step   = 'done';
				$notice = fisha_studio_2fa_backup_note( (int) $r['backup_left'] );
			} else {
				$step  = 'codes';
				$codes = $r['codes'];
			}
		}
	}

	if ( '' === $step && $pending ) {
		if ( empty( $pending['setup'] ) ) {
			$step = $mode;
		} elseif ( ! empty( $pending['confirmed'] ) ) {
			$step = 'codes'; // reloaded: the codes were shown once and are not shown again
		} else {
			$setup = fisha_studio_2fa_setup_info( $token, $ip, $ua );
			$step  = is_wp_error( $setup ) ? 'password' : 'setup';
		}
	}
	if ( '' === $step ) $step = 'password';
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
require_once FISHA_STUDIO_DIR . 'studio-variations.php';
require_once FISHA_STUDIO_DIR . 'studio-publish.php';
require_once FISHA_STUDIO_DIR . 'studio-categories.php';
require_once FISHA_STUDIO_DIR . 'studio-categories-publish.php';
require_once FISHA_STUDIO_DIR . 'studio-convert.php';

require_once FISHA_STUDIO_DIR . 'studio-preview.php';

require_once FISHA_STUDIO_DIR . 'api/api.php';
