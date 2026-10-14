<?php
/**
 * Fisha Studio REST API (namespace fisha-studio/v1): shared helpers, then one file per resource.
 * Errors are WP_Error with a plain-English message; field errors travel in data.fields.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_permission( string $cap = 'fisha_studio' ): callable {
	return function () use ( $cap ) {
		if ( ! is_user_logged_in() ) return new WP_Error( 'fisha_studio_signed_out', 'Please sign in again.', array( 'status' => 401 ) );
		if ( ! current_user_can( 'fisha_studio' ) || ! current_user_can( $cap ) ) return new WP_Error( 'fisha_studio_forbidden', 'You do not have access to this.', array( 'status' => 403 ) );
		return true;
	};
}

function fisha_studio_body( WP_REST_Request $r ): array {
	$b = $r->get_json_params();
	return is_array( $b ) ? $b : array();
}

require_once __DIR__ . '/api-session.php';
require_once __DIR__ . '/api-lookups.php';
require_once __DIR__ . '/api-media.php';
require_once __DIR__ . '/api-products.php';
require_once __DIR__ . '/api-attributes.php';
require_once __DIR__ . '/api-inventory.php';
require_once __DIR__ . '/api-categories.php';
require_once __DIR__ . '/api-history.php';
require_once __DIR__ . '/api-duplicate.php';
