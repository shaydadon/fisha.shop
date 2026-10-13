<?php
/**
 * Fisha Studio access gates: a shop owner never reaches wp-admin, the admin bar or any REST route outside the
 * Studio's own (plus the public store/form routes the site itself uses), cannot sign in over XML-RPC, and their
 * sessions last 12 hours.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_is_owner( ?WP_User $u = null ): bool {
	$u = $u ?: wp_get_current_user();
	return $u && $u->exists() && in_array( FISHA_STUDIO_ROLE, (array) $u->roles, true );
}

/** Where to send this user when they open a wp-admin script, or null to let them in. */
function fisha_studio_admin_gate_target( WP_User $u, string $script ): ?string {
	if ( ! fisha_studio_is_owner( $u ) ) return null;
	if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true ) ) return null;
	return home_url( '/studio/' );
}
// Priority 0: before WooCommerce's own admin redirect (which would send the owner to My Account).
add_action( 'admin_init', function () {
	$script = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) );
	$target = fisha_studio_admin_gate_target( wp_get_current_user(), $script );
	if ( $target ) {
		wp_safe_redirect( $target );
		exit;
	}
}, 0 );

add_filter( 'show_admin_bar', function ( $show ) {
	return fisha_studio_is_owner() ? false : $show;
} );

function fisha_studio_rest_allowed( WP_User $u, string $route ): bool {
	if ( ! fisha_studio_is_owner( $u ) ) return true;
	foreach ( array( '/' . FISHA_STUDIO_NS . '/', '/wc/store/', '/fisha/v1/' ) as $prefix ) {
		if ( 0 === strpos( $route, $prefix ) ) return true;
	}
	return false;
}
/** Runs first and refuses a disallowed route for the owner even when an earlier filter already produced a result. */
function fisha_studio_rest_gate( $result, $server, $request ) {
	if ( fisha_studio_rest_allowed( wp_get_current_user(), $request->get_route() ) ) return $result;
	return new WP_Error( 'fisha_studio_forbidden', 'This is not available from the Studio.', array( 'status' => 403 ) );
}
add_filter( 'rest_pre_dispatch', 'fisha_studio_rest_gate', PHP_INT_MIN, 3 );

/** XML-RPC can edit products outside the Studio, so the owner's credentials never work there. */
function fisha_studio_xmlrpc_blocked( WP_User $u ): bool {
	return fisha_studio_is_owner( $u );
}
function fisha_studio_xmlrpc_gate( $user ) {
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $user instanceof WP_User && fisha_studio_xmlrpc_blocked( $user ) ) {
		return new WP_Error( 'fisha_studio_xmlrpc', 'Studio accounts cannot sign in here.' );
	}
	return $user;
}
add_filter( 'authenticate', 'fisha_studio_xmlrpc_gate', 100 );

$fisha_studio_landing = function ( $to, $requested = '', $user = null ) {
	return ( $user instanceof WP_User && fisha_studio_is_owner( $user ) ) ? home_url( '/studio/' ) : $to;
};
add_filter( 'login_redirect', $fisha_studio_landing, 20, 3 );
add_filter( 'woocommerce_login_redirect', function ( $to, $user = null ) use ( $fisha_studio_landing ) {
	return $fisha_studio_landing( $to, '', $user );
}, 20, 2 );

add_filter( 'auth_cookie_expiration', function ( $length, $user_id ) {
	$u = get_user_by( 'id', (int) $user_id );
	return ( $u && fisha_studio_is_owner( $u ) ) ? 12 * HOUR_IN_SECONDS : $length;
}, 20, 2 );
