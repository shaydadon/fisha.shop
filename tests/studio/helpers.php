<?php
/**
 * Tiny test framework + fixtures for the Fisha Studio integration tests.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Fs_Fail extends Exception {}
$GLOBALS['fs_results'] = array( 'pass' => 0, 'fail' => 0 );
$GLOBALS['fs_cleanup'] = array();

function fs_test( string $name, callable $fn ): void {
	if ( $GLOBALS['fs_filter'] && false === stripos( $name, $GLOBALS['fs_filter'] ) ) return;
	try {
		$fn();
		$GLOBALS['fs_results']['pass']++;
		echo "  PASS  $name\n";
	} catch ( Throwable $e ) {
		$GLOBALS['fs_results']['fail']++;
		echo "  FAIL  $name\n        " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
	} finally {
		fs_cleanup();
	}
}
function fs_eq( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new Fs_Fail( ( $msg ? "$msg: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function fs_true( $v, string $msg = 'expected true' ): void { if ( ! $v ) throw new Fs_Fail( $msg ); }
function fs_false( $v, string $msg = 'expected false' ): void { if ( $v ) throw new Fs_Fail( $msg ); }
function fs_on_cleanup( callable $fn ): void { $GLOBALS['fs_cleanup'][] = $fn; }
function fs_cleanup(): void {
	while ( null !== ( $fn = array_pop( $GLOBALS['fs_cleanup'] ) ) ) {
		try { $fn(); } catch ( Throwable $e ) { echo '        cleanup error: ' . $e->getMessage() . "\n"; }
	}
	if ( function_exists( 'fisha_studio_preview_uninstall' ) ) fisha_studio_preview_uninstall();
	wp_set_current_user( 0 );
}

/* ---------- fixtures ---------- */

function fs_user( string $role ): WP_User {
	$login = 'zz-test-' . $role . '-' . strtolower( wp_generate_password( 6, false ) );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( is_wp_error( $id ) ) throw new Fs_Fail( $id->get_error_message() );
	fs_on_cleanup( function () use ( $id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	} );
	return get_user_by( 'id', $id );
}
function fs_as( string $role ): WP_User {
	$u = fs_user( $role );
	wp_set_current_user( $u->ID );
	return $u;
}
function fs_product( array $props = array(), string $status = 'publish' ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( 'zz-test product ' . strtolower( wp_generate_password( 5, false ) ) );
	$p->set_status( $status );
	$p->set_regular_price( '100' );
	$p->set_short_description( 'Test short description.' );
	foreach ( $props as $k => $v ) $p->{"set_$k"}( $v );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fisha_studio_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) $wpdb->delete( $table, array( 'item_type' => 'product', 'item_id' => $id ) );
		wp_delete_post( $id, true );
	} );
	return wc_get_product( $id );
}
function fs_term( string $name, int $parent = 0 ): WP_Term {
	$r = wp_insert_term( 'zz-test ' . $name, 'product_cat', array( 'parent' => $parent, 'slug' => 'zz-test-' . sanitize_title( $name ) ) );
	if ( is_wp_error( $r ) ) throw new Fs_Fail( $r->get_error_message() );
	fs_on_cleanup( function () use ( $r ) { wp_delete_term( $r['term_id'], 'product_cat' ); } );
	return get_term( $r['term_id'], 'product_cat' );
}
/** A real image attachment (copy of the Fisha logo). */
function fs_image(): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$tmp = wp_tempnam( 'zz-test.png' );
	copy( FISHA_DESIGN_DIR . 'img/fisha-logo.png', $tmp );
	$id = media_handle_sideload( array( 'name' => 'zz-test-' . wp_generate_password( 5, false ) . '.png', 'tmp_name' => $tmp ), 0 );
	if ( is_wp_error( $id ) ) throw new Fs_Fail( $id->get_error_message() );
	fs_on_cleanup( function () use ( $id ) { wp_delete_attachment( $id, true ); } );
	return $id;
}

/* ---------- REST ---------- */

/** Runs a REST request in-process. Returns [status, data]. */
function fs_rest( string $method, string $route, array $params = array() ): array {
	$req = new WP_REST_Request( $method, $route );
	if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $params ) );
	} else {
		foreach ( $params as $k => $v ) $req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	// JSON round trip, so tests see exactly what the browser gets (objects become arrays).
	return array( $res->get_status(), json_decode( wp_json_encode( rest_get_server()->response_to_data( $res, false ) ), true ) );
}
function fs_api( string $method, string $path, array $params = array() ): array {
	return fs_rest( $method, '/' . FISHA_STUDIO_NS . $path, $params );
}
