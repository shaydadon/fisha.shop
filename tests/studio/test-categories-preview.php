<?php
fs_test( 'categories preview: the draft is laid over name, intro, photos, Show on site and automatic fields', function () {
	$t = fs_cat( 'prev-live' );
	update_term_meta( $t->term_id, 'fisha_lead', 'Live intro.' );
	$img = fs_image();
	fs_product( array( 'category_ids' => array( $t->term_id ) ) );
	fisha_studio_preview_install_category( $t->term_id, array( 'name' => 'zz-test Preview Name', 'lead' => 'Draft intro.', 'hero_ids' => array( $img ), 'shown' => false ) );
	fs_eq( 'zz-test Preview Name', get_term( $t->term_id, 'product_cat' )->name );
	fs_eq( 'Draft intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
	fs_eq( (string) $img, get_term_meta( $t->term_id, 'fisha_hero_ids', true ) );
	fs_false( fisha_cat_is_displayed( get_term( $t->term_id, 'product_cat' ) ), 'switched off in the draft' );
	fisha_studio_preview_uninstall();
	fs_eq( 'zz-test prev-live', get_term( $t->term_id, 'product_cat' )->name, 'gone after uninstall' );
	fs_eq( 'Live intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
} );

fs_test( 'categories preview: a never-published category shows only in its own preview', function () {
	$t = fs_studio_cat( 'zz-test Prev New' );
	fs_hiding();
	$listed = function () use ( $t ) { return in_array( $t->term_id, array_map( 'intval', get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) ), true ); };
	fs_false( $listed(), 'public' );
	fisha_studio_preview_install_category( $t->term_id, array() );
	fs_true( $listed(), 'in its preview' );
	fs_eq( 'ok', fisha_cat_page_status( get_term( $t->term_id, 'product_cat' ) ), 'page opens in the preview' );
	fisha_studio_preview_uninstall();
	fisha_cat_cache_reset();
	fs_false( $listed(), 'public again' );
} );

fs_test( 'categories preview: a hand-written heading stays, an automatic one follows the draft name', function () {
	$hand = fs_cat( 'prev-hand' );
	update_term_meta( $hand->term_id, 'fisha_h1', 'Fisha <span>Hand</span>' );
	fisha_studio_preview_install_category( $hand->term_id, array( 'name' => 'zz-test Other' ) );
	fs_eq( 'Fisha <span>Hand</span>', get_term_meta( $hand->term_id, 'fisha_h1', true ) );
	fisha_studio_preview_uninstall();
	$auto = fs_studio_cat( 'zz-test Prev Auto' );
	fisha_studio_preview_install_category( $auto->term_id, array( 'name' => 'zz-test Prev Changed' ) );
	fs_eq( 'zz-test Prev <span>Changed</span>', get_term_meta( $auto->term_id, 'fisha_h1', true ) );
} );

fs_test( 'categories preview: targets are the category, its parent, Shop and Home, each with a token', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'prev-top' );
	$kid = fs_cat( 'prev-kid', $top->term_id );
	$targets = fisha_studio_category_preview_targets( $kid );
	fs_eq( array( 'item', 'parent', 'shop', 'home' ), wp_list_pluck( $targets, 'key' ) );
	parse_str( (string) wp_parse_url( $targets[0]['url'], PHP_URL_QUERY ), $q );
	fs_eq( array( 'type' => 'category', 'id' => $kid->term_id ), fisha_studio_preview_verify( $q['fisha_preview'] ) );
} );
