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
