<?php
/**
 * Phase 2 final review fixes: destructive actions only on products that were never live, pending sizes, row-locked
 * stock writes, SKU checks in autosave, stock counted on the whole product, option name/value rules, COMMIT checks.
 */

function fs_sz( string $slug ): int { return (int) get_term_by( 'slug', $slug, 'pa_size' )->term_id; }

/** A product with sizes made in the Studio and published there (sizes S and M at 119). */
function fs_studio_published_variable(): WC_Product_Variable {
	$p  = fisha_studio_product_create( 'zz-test Safe Tee ' . strtolower( wp_generate_password( 4, false ) ), 'variable' );
	$id = $p->get_id();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	$attrs = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( fs_sz( 's' ), fs_sz( 'm' ) ), 'variation' => true, 'visible' => true ) ) )[0];
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs, 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'safe-' . wp_rand() )->term_id ), 'short_description' => '<p>Soft.</p>' ) );
	$ids = fisha_studio_variation_generate( wc_get_product( $id ), fisha_studio_product_values( wc_get_product( $id ) )['attributes'] );
	foreach ( $ids as $vid ) fisha_studio_product_edit( wc_get_product( $id ), array( "var:$vid:regular_price" => '119' ) );
	$r = fisha_studio_publish_product( $id );
	if ( is_wp_error( $r ) ) throw new Fs_Fail( wp_json_encode( $r->get_error_data() ) );
	return wc_get_product( $id );
}

/** Adds an order line for a product (and size) to WooCommerce's order lookup table; removed on cleanup. */
function fs_order_row( int $pid, int $vid = 0 ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'wc_order_product_lookup';
	$item  = 990000000 + wp_rand( 1, 9999999 );
	$wpdb->insert( $table, array( 'order_item_id' => $item, 'order_id' => $item, 'product_id' => $pid, 'variation_id' => $vid, 'customer_id' => 0, 'date_created' => current_time( 'mysql' ), 'product_qty' => 1 ) );
	fs_on_cleanup( function () use ( $table, $item ) {
		global $wpdb;
		$wpdb->delete( $table, array( 'order_item_id' => $item ) );
	} );
}

/* ---------- I-1 never live ---------- */

fs_test( 'safety: a Studio draft that was never live can restructure, a WP admin draft cannot', function () {
	$p = fisha_studio_product_create( 'zz-test Never Live', 'variable' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fs_true( fisha_studio_never_live( $p ), 'made in the Studio, never published' );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_true( $d['restructurable'] );
	$other = fs_variable( array( 's' ), 'draft' );
	fs_false( fisha_studio_never_live( $other ), 'not made in the Studio' );
	fs_eq( 409, fisha_studio_set_product_type( $other, 'simple' )->get_error_data()['status'] );
	fs_eq( 1, count( fisha_studio_variation_ids( wc_get_product( $other->get_id() ) ) ), 'its size is kept' );
} );

fs_test( 'safety: a product published then moved to draft keeps its sizes and type', function () {
	$p   = fs_studio_published_variable();
	$id  = $p->get_id();
	$vid = fisha_studio_variation_ids( $p )[0];
	fs_eq( '1', get_post_meta( $id, '_fisha_studio_published', true ), 'publish marks the product' );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
	$p = wc_get_product( $id );
	fs_true( fisha_studio_is_new( $p ) );
	fs_false( fisha_studio_never_live( $p ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( $st ) = fs_api( 'DELETE', "/products/$id/variations/$vid" );
	fs_eq( 409, $st, 'size delete refused' );
	fs_true( null !== get_post( $vid ), 'size still there' );
	list( $st ) = fs_api( 'POST', "/products/$id/type", array( 'type' => 'simple' ) );
	fs_eq( 409, $st, 'type switch refused' );
	fs_true( wc_get_product( $id )->is_type( 'variable' ) );
	list( , $d ) = fs_api( 'GET', "/products/$id" );
	fs_false( $d['restructurable'] );
	fs_false( $d['variations'][0]['deletable'] );
} );

fs_test( 'safety: a size with an order line cannot be deleted even if pending', function () {
	$p = fs_variable( array( 's' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'm' ) );
	fs_eq( '1', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
	fs_order_row( $p->get_id(), $v->get_id() );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	$row = wp_list_filter( $d['variations'], array( 'id' => $v->get_id() ) );
	fs_false( reset( $row )['deletable'] );
	list( $st ) = fs_api( 'DELETE', '/products/' . $p->get_id() . '/variations/' . $v->get_id() );
	fs_eq( 409, $st );
	fs_true( null !== get_post( $v->get_id() ) );
} );

fs_test( 'safety: a Studio draft with an order line is not restructurable', function () {
	$p = fisha_studio_product_create( 'zz-test Sold Draft', 'variable' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fs_order_row( $p->get_id() );
	fs_false( fisha_studio_never_live( $p ) );
	fs_eq( 409, fisha_studio_set_product_type( $p, 'simple' )->get_error_data()['status'] );
} );

fs_test( 'safety: only one item and sizes products switch type', function () {
	$g = new WC_Product_Grouped();
	$g->set_name( 'zz-test Grouped' );
	$g->set_status( 'draft' );
	$g->update_meta_data( '_fisha_studio_created', '1' );
	$id = $g->save();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	fs_true( is_wp_error( fisha_studio_set_product_type( wc_get_product( $id ), 'variable' ) ) );
	fs_true( wc_get_product( $id )->is_type( 'grouped' ) );
} );

/* ---------- I-2 pending sizes that stay off ---------- */

fs_test( 'safety: publish keeps a pending size that is off pending, so it can still be deleted', function () {
	$p     = fs_ready_variable( array( 's', 'm' ) );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = fs_sz( 'l' );
	$attrs[0]['options'][] = fs_sz( 'xl' );
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$on  = fisha_studio_variation_create( wc_get_product( $p->get_id() ), array( 'pa_size' => 'l' ) );
	$off = fisha_studio_variation_create( wc_get_product( $p->get_id() ), array( 'pa_size' => 'xl' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'var:' . $off->get_id() . ':enabled' => false ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( '', get_post_meta( $on->get_id(), FISHA_STUDIO_PENDING_META, true ), 'the size that went live is no longer pending' );
	fs_eq( '1', get_post_meta( $off->get_id(), FISHA_STUDIO_PENDING_META, true ), 'the size that stayed off is still pending' );
	fs_eq( 'private', get_post_status( $off->get_id() ) );
	fs_true( true === fisha_studio_variation_remove( wc_get_product( $p->get_id() ), $off->get_id() ) );
	fs_eq( null, get_post( $off->get_id() ) );
} );

fs_test( 'safety: a forced failure keeps a new size private and pending', function () {
	$p     = fs_ready_variable( array( 's' ) );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = fs_sz( 'm' );
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$v    = fisha_studio_variation_create( wc_get_product( $p->get_id() ), array( 'pa_size' => 'm' ) );
	$boom = function () { throw new RuntimeException( 'zz-test forced failure' ); };
	add_action( 'fisha_studio_before_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_commit', $boom ); } );
	fs_eq( 'fisha_studio_publish_failed', fisha_studio_publish_product( $p->get_id() )->get_error_code() );
	clean_post_cache( $v->get_id() );
	fs_eq( 'private', get_post_status( $v->get_id() ) );
	fs_eq( '1', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
} );

/* ---------- I-3 stock rows locked and checked again ---------- */

fs_test( 'safety: an order between the publish check and the write is caught (one item)', function () {
	$p = fs_complete_product( array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
	fisha_studio_product_edit( $p, array( 'stock_quantity' => 7 ), array( 'stock_quantity' => 5 ) );
	$hook = function () use ( $p ) { update_post_meta( $p->get_id(), '_stock', 4 ); };
	add_action( 'fisha_studio_before_stock_recheck', $hook );
	fs_on_cleanup( function () use ( $hook ) { remove_action( 'fisha_studio_before_stock_recheck', $hook ); } );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_conflict', is_wp_error( $r ) ? $r->get_error_code() : 'published' );
	fs_eq( 409, $r->get_error_data()['status'] );
	fs_eq( 'stock_quantity', $r->get_error_data()['conflicts'][0]['field'] );
	clean_post_cache( $p->get_id() );
	fs_true( 7 !== (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'our write must not land (the hook write is rolled back with ours)' );
	fs_eq( 7, fisha_studio_draft_get( $p->get_id() )['fields']['stock_quantity'], 'draft kept' );
} );

fs_test( 'safety: an order between the publish check and the write is caught (a size)', function () {
	$p = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_counted( $s, 5 );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:stock_quantity" => 7 ), array( "var:$s:stock_quantity" => 5 ) );
	$hook = function () use ( $s ) { update_post_meta( $s, '_stock', 4 ); };
	add_action( 'fisha_studio_before_stock_recheck', $hook );
	fs_on_cleanup( function () use ( $hook ) { remove_action( 'fisha_studio_before_stock_recheck', $hook ); } );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_conflict', is_wp_error( $r ) ? $r->get_error_code() : 'published' );
	fs_eq( "var:$s:stock_quantity", $r->get_error_data()['conflicts'][0]['field'] );
	clean_post_cache( $s );
	fs_true( 7 !== (int) wc_get_product( $s )->get_stock_quantity(), 'our write must not land' );
} );

/* ---------- I-4 SKUs in an autosave ---------- */

fs_test( 'safety: a taken SKU in an autosave does not drop the other edits', function () {
	$taken = fs_product( array( 'sku' => 'zz-test-taken-' . wp_rand() ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'POST', '/products', array( 'name' => 'zz-test Sku Batch' ) );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	list( $st, $d ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'name' => 'zz-test Sku Batch 2', 'description' => '<p>Long.</p>', 'sku' => $taken->get_sku() ) ) );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_eq( 'Another product or size already uses this SKU.', $d['field_errors']['sku'] ?? null );
	$p = wc_get_product( $id );
	fs_eq( 'zz-test Sku Batch 2', $p->get_name() );
	fs_eq( '<p>Long.</p>', $p->get_description() );
	fs_eq( '', $p->get_sku() );
} );

fs_test( 'safety: a taken size SKU in an autosave does not drop the name', function () {
	$taken = fs_product( array( 'sku' => 'zz-test-taken-' . wp_rand() ) );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'POST', '/products', array( 'name' => 'zz-test Size Sku', 'type' => 'variable' ) );
	$id = $d['id'];
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	$v   = fisha_studio_variation_create( wc_get_product( $id ), array( 'pa_size' => 's' ) );
	$vid = $v->get_id();
	list( $st, $d ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'name' => 'zz-test Size Sku 2', "var:$vid:sku" => $taken->get_sku() ) ) );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	fs_true( isset( $d['field_errors'][ "var:$vid:sku" ] ), 'size sku reported' );
	fs_eq( 'zz-test Size Sku 2', wc_get_product( $id )->get_name() );
	fs_eq( '', wc_get_product( $vid )->get_sku() );
	$same = 'zz-test-same-' . wp_rand();
	list( , $d ) = fs_api( 'PUT', "/products/$id/draft", array( 'fields' => array( 'sku' => $same, "var:$vid:sku" => $same ) ) );
	fs_true( isset( $d['field_errors'][ "var:$vid:sku" ] ), 'the same SKU twice in one batch' );
	fs_eq( $same, wc_get_product( $id )->get_sku(), 'the first one is saved' );
} );

/* ---------- I-5 stock counted on the whole product ---------- */

/** fs_ready_variable() whose stock is counted on the product (5), not on each size. */
function fs_parent_counted( array $sizes = array( 's', 'm' ) ): WC_Product_Variable {
	$p = fs_ready_variable( $sizes );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 5 );
	$p->save();
	return wc_get_product( $p->get_id() );
}

fs_test( 'safety: inventory shows one stock row for a product counted as a whole', function () {
	$p = fs_parent_counted();
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => $p->get_name(), 'sort' => 'name' ) );
	$prod = wp_list_filter( $d['items'], array( 'kind' => 'product' ) );
	fs_eq( 1, count( $prod ) );
	$prod = reset( $prod );
	fs_eq( $p->get_id(), $prod['id'] );
	fs_eq( '', $prod['option'] );
	fs_true( $prod['manage_stock'] );
	fs_eq( 5, $prod['stock_quantity'] );
	fs_eq( '', $prod['locked'] );
	$sizes = wp_list_filter( $d['items'], array( 'kind' => 'variation' ) );
	fs_eq( 2, count( $sizes ) );
	foreach ( $sizes as $row ) fs_eq( 'Stock is kept for the whole product', $row['locked'] );
} );

fs_test( 'safety: quick edit of a product counted as a whole', function () {
	$p = fs_parent_counted();
	fs_as( FISHA_STUDIO_ROLE );
	list( $st, $row ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '7' ), 'seen' => array( 'stock_quantity' => 5 ) ) );
	fs_eq( 200, $st, wp_json_encode( $row ) );
	fs_eq( 7, $row['stock_quantity'] );
	fs_eq( 7, wc_get_product( $p->get_id() )->get_stock_quantity() );
	list( $st ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '8' ), 'seen' => array( 'stock_quantity' => 5 ) ) );
	fs_eq( 409, $st, 'stale seen refused' );
	list( $st ) = fs_api( 'POST', '/products/' . $p->get_id() . '/quick', array( 'fields' => array( 'regular_price' => '90' ), 'seen' => array( 'regular_price' => '' ) ) );
	fs_eq( 400, $st, 'other fields refused' );
	$q = fs_ready_variable( array( 's' ) );
	list( $st ) = fs_api( 'POST', '/products/' . $q->get_id() . '/quick', array( 'fields' => array( 'stock_quantity' => '3' ), 'seen' => array( 'stock_quantity' => null ) ) );
	fs_eq( 400, $st, 'not counted on the product' );
} );

fs_test( 'safety: a size of a product counted as a whole has no stock of its own to edit', function () {
	$p = fs_parent_counted( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$r = fisha_studio_variation_quick( $s, array( 'stock_status' => 'outofstock' ), array( 'stock_status' => 'instock' ) );
	fs_eq( 422, is_wp_error( $r ) ? $r->get_error_data()['status'] : 0 );
	fs_eq( 'Stock is kept for the whole product. Change it on the product.', $r->get_error_message() );
	fs_true( fisha_studio_variation_quick( $s, array( 'regular_price' => '110' ), array( 'regular_price' => '100' ) ) instanceof WC_Product_Variation, 'price still allowed' );
} );

/* ---------- cheap items ---------- */

fs_test( 'safety: option names need a letter or number, values cannot contain |', function () {
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => '!!!', 'options' => array( 'a' ) ) ) )[1], 'name with nothing to key on' );
	fs_true( fisha_studio_clean_attributes( array( array( 'name' => 'Colour', 'options' => array( 'Red|Blue' ) ) ) )[1], 'value with |' );
	fs_eq( null, fisha_studio_clean_attributes( array( array( 'name' => 'Colour', 'options' => array( 'Red', 'Blue' ) ) ) )[1] );
} );

fs_test( 'safety: check-sizes lists products with size problems', function () {
	$ok  = fs_ready_variable( array( 's' ) );
	$bad = fs_ready_variable( array( 's' ) );
	$s   = fs_var_id( $bad, 's' );
	update_post_meta( $s, '_regular_price', '' );
	update_post_meta( $s, '_price', '' );
	clean_post_cache( $s );
	$found = fisha_studio_check_sizes();
	$ids   = wp_list_pluck( $found, 'id' );
	fs_true( in_array( $bad->get_id(), $ids, true ), 'the product with a size without a price is listed' );
	fs_false( in_array( $ok->get_id(), $ids, true ) );
} );

/** Makes the next COMMIT fail (a broken statement), as a lost connection would. */
function fs_break_commit(): void {
	global $wpdb;
	$break = function ( $q ) { return 'COMMIT' === trim( $q ) ? 'COMMIT zz_test_broken' : $q; };
	add_filter( 'query', $break );
	$was = $wpdb->suppress_errors( true );
	fs_on_cleanup( function () use ( $break, $was ) {
		global $wpdb;
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $was );
	} );
}

fs_test( 'safety: a failed COMMIT on publish is a failure, nothing changed', function () {
	$p = fs_complete_product( array( 'regular_price' => '100' ) );
	fisha_studio_product_edit( $p, array( 'regular_price' => '130' ) );
	fs_break_commit();
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_publish_failed', is_wp_error( $r ) ? $r->get_error_code() : 'published' );
	clean_post_cache( $p->get_id() );
	fs_eq( '100', wc_get_product( $p->get_id() )->get_regular_price() );
} );

fs_test( 'safety: a failed COMMIT on a size quick edit is a failure, nothing changed', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_break_commit();
	$r = fisha_studio_variation_quick( $s, array( 'regular_price' => '115' ), array( 'regular_price' => '100' ) );
	fs_eq( 'fisha_studio_publish_failed', is_wp_error( $r ) ? $r->get_error_code() : 'saved' );
	clean_post_cache( $s );
	fs_eq( '100', wc_get_product( $s )->get_regular_price() );
} );
