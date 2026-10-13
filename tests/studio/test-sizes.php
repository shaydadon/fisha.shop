<?php
fs_test( 'sizes: editing a size of a live product is a draft', function () {
	$p = fs_variable();
	$s = fs_var_id( $p, 's' );
	fisha_studio_product_edit( $p, array( "var:$s:regular_price" => '150' ) );
	fs_eq( '100', wc_get_product( $s )->get_regular_price() );
	fs_eq( '150', fisha_studio_draft_get( $p->get_id() )['fields'][ "var:$s:regular_price" ] );
} );

fs_test( 'sizes: a new size on a live product stays hidden until publish, discard deletes it', function () {
	$p = fs_variable( array( 's', 'm' ) );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'l' ) );
	fs_eq( 'private', get_post_status( $v->get_id() ) );
	fs_eq( '1', get_post_meta( $v->get_id(), FISHA_STUDIO_PENDING_META, true ) );
	fs_eq( '100', $v->get_regular_price(), 'price copied from the other sizes' );
	fs_eq( true, fisha_studio_draft_get( $p->get_id() )['fields'][ 'var:' . $v->get_id() . ':enabled' ] );
	fs_false( in_array( $v->get_id(), wc_get_product( $p->get_id() )->get_visible_children(), true ), 'shoppers do not see it' );
	fisha_studio_draft_discard( $p->get_id() );
	fs_eq( null, get_post( $v->get_id() ), 'deleted with the draft' );
} );

fs_test( 'sizes: create missing combinations', function () {
	$p     = fs_variable( array( 's', 'm', 'l' ) );
	$attrs = fisha_studio_product_values( $p )['attributes'];
	$attrs[0]['options'][] = (int) get_term_by( 'slug', 'xl', 'pa_size' )->term_id;
	$new = fisha_studio_variation_generate( $p, $attrs );
	fs_eq( 1, count( $new ) );
	fs_eq( array( 'pa_size' => 'xl' ), wc_get_product( $new[0] )->get_attributes() );
	fs_eq( array(), fisha_studio_variation_generate( wc_get_product( $p->get_id() ), $attrs ), 'nothing missing the second time' );
} );

fs_test( 'sizes: too many combinations and no size options are refused', function () {
	$p    = fs_variable( array( 's' ) );
	$many = array(
		array( 'name' => 'A', 'taxonomy' => false, 'options' => array_map( 'strval', range( 1, 8 ) ), 'variation' => true, 'visible' => true ),
		array( 'name' => 'B', 'taxonomy' => false, 'options' => array_map( 'strval', range( 1, 7 ) ), 'variation' => true, 'visible' => true ),
	);
	$r = fisha_studio_variation_generate( $p, $many );
	fs_true( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '56' ), 'names the number' );
	fs_eq( 1, count( fisha_studio_variation_ids( wc_get_product( $p->get_id() ) ) ), 'nothing created' );
	fs_true( is_wp_error( fisha_studio_variation_generate( $p, array() ) ), 'no options' );
} );

fs_test( 'sizes: only never-published sizes can be deleted', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_variation_remove( $p, $s )->get_error_code() );
	$v = fisha_studio_variation_create( $p, array( 'pa_size' => 'm' ) );
	fs_true( true === fisha_studio_variation_remove( wc_get_product( $p->get_id() ), $v->get_id() ) );
	fs_eq( null, get_post( $v->get_id() ) );
	fs_false( isset( fisha_studio_draft_get( $p->get_id() )['fields'][ 'var:' . $v->get_id() . ':enabled' ] ), 'its draft keys are gone' );
} );

fs_test( 'sizes: a new product with sizes builds them directly', function () {
	$p = fisha_studio_product_create( 'zz-test New Hoodie', 'variable' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fs_true( $p->is_type( 'variable' ) );
	$attrs = fisha_studio_clean_attributes( array( array( 'name' => 'pa_size', 'taxonomy' => true, 'options' => array( get_term_by( 'slug', 's', 'pa_size' )->term_id, get_term_by( 'slug', 'm', 'pa_size' )->term_id ), 'variation' => true, 'visible' => true ) ) )[0];
	fisha_studio_product_edit( $p, array( 'attributes' => $attrs ) );
	$fresh = wc_get_product( $p->get_id() );
	$new   = fisha_studio_variation_generate( $fresh, fisha_studio_product_values( $fresh )['attributes'] );
	fs_eq( 2, count( $new ) );
	fs_eq( 'publish', get_post_status( $new[0] ), 'on straight away (the product itself is still hidden)' );
	fs_eq( '', get_post_meta( $new[0], FISHA_STUDIO_PENDING_META, true ) );
	fs_eq( array(), fisha_studio_draft_get( $p->get_id() )['fields'] );
} );

fs_test( 'sizes: a never-published product can switch between one item and sizes', function () {
	$p = fisha_studio_product_create( '' );
	fs_on_cleanup( function () use ( $p ) { fs_delete_product( $p->get_id() ); } );
	fs_eq( '', $p->get_name( 'edit' ), 'empty name allowed while drafting' );
	$v = fisha_studio_set_product_type( $p, 'variable' );
	fs_true( $v instanceof WC_Product_Variable );
	fisha_studio_variation_create( $v, array( 'pa_size' => 's' ) );
	$s = fisha_studio_set_product_type( wc_get_product( $p->get_id() ), 'simple' );
	fs_true( $s instanceof WC_Product_Simple );
	fs_eq( 0, count( get_children( array( 'post_parent' => $p->get_id(), 'post_type' => 'product_variation' ) ) ), 'its sizes are removed' );
	fs_eq( 'fisha_studio_invalid', fisha_studio_set_product_type( fs_product(), 'variable' )->get_error_code(), 'live products keep their type' );
} );

fs_test( 'sizes: conflicts name the size', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	$v = wc_get_product( $s );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->save();
	fisha_studio_product_edit( wc_get_product( $p->get_id() ), array( "var:$s:stock_quantity" => 5 ), array( "var:$s:stock_quantity" => 3 ) );
	wc_update_product_stock( $s, 2, 'set' );
	$c = fisha_studio_conflicts( wc_get_product( $p->get_id() ), fisha_studio_draft_get( $p->get_id() ) );
	fs_eq( get_term_by( 'slug', 's', 'pa_size' )->name . ': Stock', $c[0]['label'] );
	fs_eq( 2, $c[0]['live'] );
} );

fs_test( 'sizes: seen values are cleaned for size keys', function () {
	$p = fs_variable( array( 's' ) );
	$s = fs_var_id( $p, 's' );
	fs_eq( array( "var:$s:regular_price" => '90' ), fisha_studio_clean_seen( array( "var:$s:regular_price" => '90.00', 'evil' => 1 ), $p ) );
} );
