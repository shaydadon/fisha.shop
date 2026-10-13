<?php
fs_test( 'sizes publish: a size price goes live and the price range follows', function () {
	$p = fs_ready_variable();
	$s = fs_var_id( $p, 's' );
	fs_as( FISHA_STUDIO_ROLE );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '150' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( '150', wc_get_product( $s )->get_regular_price() );
	fs_eq( 150.0, (float) wc_get_product( $p->get_id() )->get_variation_regular_price( 'max' ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_eq( '100', fisha_studio_log_for( 'product', $p->get_id() )[0]['before'][ "var:$s:regular_price" ] );
} );

fs_test( 'sizes publish: a new size goes live', function () {
	$p = fs_ready_variable( array( 's', 'm' ) );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = (int) get_term_by( 'slug', 'l', 'pa_size' )->term_id;
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$v = fisha_studio_variation_create( wc_get_product( $p->get_id() ), array( 'pa_size' => 'l' ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( 'var:' . $v->get_id() . ':stock_quantity' => 4 ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( 'publish', get_post_status( $v->get_id() ) );
	fs_eq( '', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
	fs_true( in_array( $v->get_id(), wc_get_product( $p->get_id() )->get_visible_children(), true ) );
} );

fs_test( 'sizes publish: a size that is on needs a price, and one size must be on', function () {
	$p = fs_ready_variable( array( 's', 'm' ) );
	$s = fs_var_id( $p, 's' );
	$m = fs_var_id( $p, 'm' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '' ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_invalid', $r->get_error_code() );
	fs_true( isset( $r->get_error_data()['fields'][ "var:$s:regular_price" ] ) );
	fs_eq( '100', wc_get_product( $s )->get_regular_price(), 'nothing written' );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:regular_price" => '100', "var:$s:enabled" => false, "var:$m:enabled" => false ) );
	fs_true( isset( fisha_studio_publish_product( $p->get_id() )->get_error_data()['fields']['variations'] ) );
} );

fs_test( 'sizes publish: a size whose value was removed must be switched off', function () {
	$p     = fs_ready_variable( array( 's', 'm' ) );
	$m     = fs_var_id( $p, 'm' );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'] = array( (int) get_term_by( 'slug', 's', 'pa_size' )->term_id );
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_true( is_wp_error( $r ) && isset( $r->get_error_data()['fields'][ "var:$m:enabled" ] ) );
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$m:enabled" => false ) );
	fs_false( is_wp_error( fisha_studio_publish_product( $p->get_id() ) ) );
	fs_eq( 'private', get_post_status( $m ), 'switched off, not deleted' );
} );

fs_test( 'sizes publish: SKUs must be unique', function () {
	$p   = fs_ready_variable( array( 's', 'm' ) );
	$s   = fs_var_id( $p, 's' );
	$m   = fs_var_id( $p, 'm' );
	$sku = 'ZZ-TEST-DUP-' . $s;
	fisha_studio_product_edit( $p, array( "var:$s:sku" => $sku, "var:$m:sku" => $sku ) );
	$f = fisha_studio_publish_product( $p->get_id() )->get_error_data()['fields'];
	fs_true( isset( $f[ "var:$s:sku" ] ) || isset( $f[ "var:$m:sku" ] ) );
} );

fs_test( "sizes publish: a size's stock conflict is asked about", function () {
	$p = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->save();
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:stock_quantity" => 5, "var:$s:regular_price" => '120' ), array( "var:$s:stock_quantity" => 3, "var:$s:regular_price" => '100' ) );
	wc_update_product_stock( $s, 2, 'set' );
	$r = fisha_studio_publish_product( $p->get_id() );
	fs_eq( 'fisha_studio_conflict', $r->get_error_code() );
	fs_eq( "var:$s:stock_quantity", $r->get_error_data()['conflicts'][0]['field'] );
	fs_false( is_wp_error( fisha_studio_publish_product( $p->get_id(), array( "var:$s:stock_quantity" => 'live' ) ) ) );
	fs_eq( 2, wc_get_product( $s )->get_stock_quantity(), 'kept the site value' );
	fs_eq( '120', wc_get_product( $s )->get_regular_price() );
} );

fs_test( 'sizes publish: a forced failure rolls back sizes too', function () {
	$p = fs_ready_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '130' ) );
	$boom = function () { throw new RuntimeException( 'zz-test forced failure' ); };
	add_action( 'fisha_studio_before_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_commit', $boom ); } );
	fs_eq( 'fisha_studio_publish_failed', fisha_studio_publish_product( $p->get_id() )->get_error_code() );
	fs_eq( '100', wc_get_product( $s )->get_regular_price(), 'size price rolled back (the code clears the caches)' );
	fs_eq( '130', fisha_studio_draft_get( $p->get_id() )['fields'][ "var:$s:regular_price" ] );
} );

fs_test( 'sizes publish: editing another field leaves options untouched', function () {
	$p = new WC_Product_Variable();
	$p->set_name( 'zz-test local options' );
	$p->set_status( 'publish' );
	$p->set_short_description( 'Short.' );
	$p->set_image_id( fs_image() );
	$p->set_category_ids( array( fs_term( 'sz-local' )->term_id ) );
	$a = new WC_Product_Attribute();
	$a->set_name( 'Color' );
	$a->set_options( array( '"White"', 'Black' ) );
	$a->set_visible( true );
	$a->set_variation( true );
	$p->set_attributes( array( $a ) );
	$id = $p->save();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	$v = new WC_Product_Variation();
	$v->set_parent_id( $id );
	$v->set_attributes( array( 'color' => '"White"' ) );
	$v->set_regular_price( '100' );
	$v->set_status( 'publish' );
	$vid = $v->save();
	WC_Product_Variable::sync( $id );
	$before = fisha_studio_attributes_read( wc_get_product( $id ) );
	fisha_studio_product_edit( wc_get_product( $id ), array( 'name' => 'zz-test local options renamed' ) );
	$r = fisha_studio_publish_product( $id );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	fs_eq( $before, fisha_studio_attributes_read( wc_get_product( $id ) ) );
	fs_eq( '"White"', get_post_meta( $vid, 'attribute_color', true ) );
} );

fs_test( 'sizes publish: a new product with sizes goes live in one go', function () {
	$p = fisha_studio_product_create( 'zz-test Studio Tee', 'variable' );
	$id = $p->get_id();
	fs_on_cleanup( function () use ( $id ) { fs_delete_product( $id ); } );
	$attrs = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( get_term_by( 'slug', 's', 'pa_size' )->term_id, get_term_by( 'slug', 'm', 'pa_size' )->term_id ), 'variation' => true, 'visible' => true ) ) )[0];
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs, 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'sz-new' )->term_id ), 'short_description' => '<p>Soft tee.</p>' ) );
	$ids = fisha_studio_variation_generate( wc_get_product( $id ), fisha_studio_product_values( wc_get_product( $id ) )['attributes'] );
	foreach ( $ids as $vid ) fisha_studio_product_edit( wc_get_product( $id ), array( "var:$vid:regular_price" => '119', "var:$vid:stock_quantity" => 5 ) );
	$r = fisha_studio_publish_product( $id );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : '' );
	$live = wc_get_product( $id );
	fs_eq( 'publish', $live->get_status() );
	fs_eq( 2, count( $live->get_visible_children() ) );
	fs_eq( 119.0, (float) $live->get_variation_price( 'min' ) );
} );
