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
	fs_on_cleanup( function () use ( $r ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fisha_studio_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) $wpdb->delete( $table, array( 'item_type' => 'category', 'item_id' => $r['term_id'] ) );
		wp_delete_term( $r['term_id'], 'product_cat' );
	} );
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

/* ---------- products with sizes ---------- */

/** Deletes a product, its sizes and their audit rows (fixture cleanup). */
function fs_delete_product( int $id ): void {
	global $wpdb;
	$log = $wpdb->prefix . 'fisha_studio_log';
	foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $vid ) {
		$wpdb->delete( $log, array( 'item_type' => 'variation', 'item_id' => $vid ) );
		wp_delete_post( (int) $vid, true );
	}
	$wpdb->delete( $log, array( 'item_type' => 'product', 'item_id' => $id ) );
	wp_delete_post( $id, true );
}

/** A variable product with the pa_size option and one published size per slug (price 100, stock not counted). */
function fs_variable( array $sizes = array( 's', 'm', 'l' ), string $status = 'publish' ): WC_Product_Variable {
	$term_ids = array();
	foreach ( $sizes as $slug ) {
		$t = get_term_by( 'slug', $slug, 'pa_size' );
		if ( ! $t ) throw new Fs_Fail( "pa_size value $slug is missing" );
		$term_ids[] = (int) $t->term_id;
	}
	$p = new WC_Product_Variable();
	$p->set_name( 'zz-test variable ' . strtolower( wp_generate_password( 5, false ) ) );
	$p->set_status( $status );
	$p->set_short_description( 'Test short description.' );
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
	$a->set_name( 'pa_size' );
	$a->set_options( $term_ids );
	$a->set_visible( true );
	$a->set_variation( true );
	$p->set_attributes( array( $a ) );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	foreach ( $sizes as $i => $slug ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( 'pa_size' => $slug ) );
		$v->set_regular_price( '100' );
		$v->set_status( 'publish' );
		$v->set_menu_order( $i );
		$v->save();
	}
	WC_Product_Variable::sync( $id );
	wc_delete_product_transients( $id );
	return wc_get_product( $id );
}

function fs_var_id( WC_Product $p, string $slug ): int {
	$ids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $p->get_id(), 'post_status' => array( 'publish', 'private' ), 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => 'attribute_pa_size', 'meta_value' => $slug ) );
	if ( ! $ids ) throw new Fs_Fail( "no $slug size" );
	return (int) $ids[0];
}

/** fs_variable() plus a photo and a category, so it passes publish validation. */
function fs_ready_variable( array $sizes = array( 's', 'm', 'l' ) ): WC_Product_Variable {
	$p = fs_variable( $sizes );
	$p->set_image_id( fs_image() );
	$p->set_category_ids( array( fs_term( 'sz-' . wp_rand() )->term_id ) );
	$p->save();
	return wc_get_product( $p->get_id() );
}

/* ---------- categories ---------- */

/** A category with "Show on site" set (fs_term() ones have no fisha_shown, so the site treats them as hidden). */
function fs_cat( string $name, int $parent = 0, bool $shown = true ): WP_Term {
	$t = fs_term( $name, $parent );
	update_term_meta( $t->term_id, 'fisha_shown', $shown ? '1' : '0' );
	// This environment's object cache can keep a stale product_cat_children after delete_option(); drop it so sub-categories resolve.
	delete_option( 'product_cat_children' );
	wp_cache_delete( 'alloptions', 'options' );
	clean_taxonomy_cache( 'product_cat' );
	if ( function_exists( 'fisha_cat_cache_reset' ) ) fisha_cat_cache_reset();
	return $t;
}

/** Makes this test act like a public page view: never-published categories are hidden (undone after the test). */
function fs_hiding(): void {
	add_filter( 'fisha_cat_hiding_active', '__return_true' );
	fisha_cat_cache_reset();
	fs_on_cleanup( function () {
		remove_filter( 'fisha_cat_hiding_active', '__return_true' );
		unset( $GLOBALS['fisha_studio_preview_term'] );
		fisha_cat_cache_reset();
	} );
}

/** A never-published category made by the Studio (removed with its audit rows after the test). */
function fs_studio_cat( string $name = '', int $parent = 0 ): WP_Term {
	$t = fisha_studio_category_create( $name, $parent );
	if ( is_wp_error( $t ) ) throw new Fs_Fail( $t->get_error_message() );
	$id = $t->term_id;
	// Same stale product_cat_children issue as fs_cat(): drop it so sub-categories resolve in get_term_children().
	delete_option( 'product_cat_children' );
	wp_cache_delete( 'alloptions', 'options' );
	clean_taxonomy_cache( 'product_cat' );
	fs_on_cleanup( function () use ( $id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'fisha_studio_log', array( 'item_type' => 'category', 'item_id' => $id ) );
		wp_delete_term( $id, 'product_cat' );
	} );
	return $t;
}
