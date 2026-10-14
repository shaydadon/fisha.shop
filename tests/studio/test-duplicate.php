<?php
fs_test( 'duplicate: a simple product becomes a never-published copy without SKU', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_product( array( 'sku' => 'ZZ-DUP-' . wp_rand( 1000, 9999 ), 'featured' => true, 'image_id' => fs_image() ) );
	update_post_meta( $src->get_id(), FISHA_STUDIO_PUBLISHED_META, '1' );
	$copy = fisha_studio_duplicate_product( $src->get_id() );
	fs_false( is_wp_error( $copy ) );
	fs_on_cleanup( function () use ( $copy ) { fs_delete_product( $copy->get_id() ); } );
	fs_eq( $src->get_name() . ' (copy)', $copy->get_name() );
	fs_eq( 'draft', $copy->get_status() );
	fs_eq( '', $copy->get_sku( 'edit' ) );
	fs_false( $copy->get_featured() );
	fs_eq( $src->get_image_id(), $copy->get_image_id() );
	fs_eq( $src->get_regular_price(), $copy->get_regular_price() );
	fs_eq( '1', get_post_meta( $copy->get_id(), FISHA_STUDIO_CREATED_META, true ) );
	fs_eq( '', (string) get_post_meta( $copy->get_id(), FISHA_STUDIO_PUBLISHED_META, true ) );
	fs_true( fisha_studio_never_live( $copy ), 'treated as never published' );
	fs_eq( sanitize_title( $copy->get_name() ), $copy->get_slug(), 'slug from the new name' );
	fs_eq( 'duplicate', fisha_studio_log_for( 'product', $copy->get_id(), 1 )[0]['action'] );
} );

fs_test( 'duplicate: a product with sizes copies sizes and the draft, clears SKUs', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_variable( array( 's', 'm' ) );
	$vm  = fs_var_id( $src, 'm' );
	$v   = wc_get_product( $vm );
	$v->set_sku( 'ZZ-DUP-M-' . wp_rand( 1000, 9999 ) );
	$v->save();
	fisha_studio_product_edit( $src, array( 'short_description' => '<p>Draft text</p>', fisha_studio_var_key( $vm, 'regular_price' ) => '150' ) );
	$copy = fisha_studio_duplicate_product( $src->get_id() );
	fs_on_cleanup( function () use ( $copy ) { fs_delete_product( $copy->get_id() ); } );
	fs_eq( 2, count( fisha_studio_variation_ids( $copy ) ) );
	$cm = fs_var_id( $copy, 'm' );
	fs_true( $cm !== $vm );
	fs_eq( '', wc_get_product( $cm )->get_sku( 'edit' ), 'size SKU cleared' );
	fs_eq( '150', wc_get_product( $cm )->get_regular_price( 'edit' ), 'draft price of M on the copy' );
	fs_eq( '<p>Draft text</p>', $copy->get_short_description( 'edit' ) );
	fs_eq( array(), fisha_studio_draft_get( $copy->get_id() )['fields'], 'a never-published copy keeps no draft' );
	fs_true( (bool) fisha_studio_draft_get( $src->get_id() )['fields'], 'source draft untouched' );
} );

fs_test( 'duplicate: a category copy keeps intro, photos and Show on site, not products', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'dup-parent' );
	$src = fs_cat( 'dup-src', $top->term_id );
	$img = fs_image();
	update_term_meta( $src->term_id, 'fisha_lead', 'Source intro.' );
	update_term_meta( $src->term_id, 'fisha_hero_ids', (string) $img );
	fs_product( array( 'category_ids' => array( $src->term_id ) ) );
	$copy = fisha_studio_duplicate_category( $src->term_id );
	fs_false( is_wp_error( $copy ) );
	fs_on_cleanup( function () use ( $copy ) { wp_delete_term( $copy->term_id, 'product_cat' ); } );
	fs_eq( 'zz-test dup-src (copy)', $copy->name );
	fs_eq( (int) $top->term_id, (int) $copy->parent );
	fs_eq( 'Source intro.', get_term_meta( $copy->term_id, 'fisha_lead', true ) );
	fs_eq( (string) $img, get_term_meta( $copy->term_id, 'fisha_hero_ids', true ) );
	fs_eq( '1', get_term_meta( $copy->term_id, 'fisha_studio_hidden', true ), 'never published' );
	fs_eq( array(), get_objects_in_term( $copy->term_id, 'product_cat' ) );
} );

fs_test( 'duplicate: REST returns 201 with the copy', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_product();
	list( $s, $d ) = fs_api( 'POST', '/products/' . $src->get_id() . '/duplicate' );
	fs_eq( 201, $s, wp_json_encode( $d ) );
	fs_on_cleanup( function () use ( $d ) { fs_delete_product( (int) $d['id'] ); } );
	fs_eq( 'new', $d['state'] );
	fs_true( $d['id'] !== $src->get_id() );
} );

fs_test( 'duplicate: a draft Featured switch does not make the copy featured', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_product();
	fisha_studio_product_edit( $src, array( 'featured' => true ) );
	$copy = fisha_studio_duplicate_product( $src->get_id() );
	fs_false( is_wp_error( $copy ) );
	fs_on_cleanup( function () use ( $copy ) { fs_delete_product( $copy->get_id() ); } );
	fs_false( wc_get_product( $copy->get_id() )->get_featured(), 'copy is not featured' );
} );

fs_test( 'duplicate fix: a copy of a Hidden product starts Hidden', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$src = fs_product( array(), 'private' );
	update_post_meta( $src->get_id(), FISHA_STUDIO_PUBLISHED_META, '1' );
	$copy = fisha_studio_duplicate_product( $src->get_id() );
	fs_on_cleanup( function () use ( $copy ) { fs_delete_product( $copy->get_id() ); } );
	fs_eq( 'private', fisha_studio_target_status( $copy ) );
	$shown = fs_product();
	fisha_studio_product_edit( $shown, array( 'status' => 'private' ) );
	$copy2 = fisha_studio_duplicate_product( $shown->get_id() );
	fs_on_cleanup( function () use ( $copy2 ) { fs_delete_product( $copy2->get_id() ); } );
	fs_eq( 'private', fisha_studio_target_status( $copy2 ), 'an unpublished Hidden switch counts too' );
	$copy3 = fisha_studio_duplicate_product( fs_product()->get_id() );
	fs_on_cleanup( function () use ( $copy3 ) { fs_delete_product( $copy3->get_id() ); } );
	fs_eq( 'publish', fisha_studio_target_status( $copy3 ) );
} );
