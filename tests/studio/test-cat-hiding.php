<?php
fs_test( 'cat hiding: a never-published category is invisible to the public site only', function () {
	$t = fs_cat( 'hide-new' );
	update_term_meta( $t->term_id, 'fisha_studio_hidden', '1' );
	$p = fs_product( array( 'category_ids' => array( $t->term_id ) ) );
	fisha_cat_cache_reset();
	$ids = function () { return get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ); };
	fs_true( in_array( $t->term_id, array_map( 'intval', $ids() ), true ), 'Studio/CLI still sees it' );
	fs_hiding();
	fs_false( in_array( $t->term_id, array_map( 'intval', $ids() ), true ), 'public lists skip it' );
	fs_eq( array(), array_filter( (array) get_the_terms( $p->get_id(), 'product_cat' ) ), 'product page meta skips it' );
	$GLOBALS['fisha_studio_preview_term'] = $t->term_id;
	fs_true( in_array( $t->term_id, array_map( 'intval', $ids() ), true ), 'its own preview sees it' );
} );

fs_test( 'cat hiding: page status is 404 for never published, noindex for hidden, ok for shown', function () {
	$shown = fs_cat( 'status-shown' );
	$off   = fs_cat( 'status-off', 0, false );
	$kid   = fs_cat( 'status-kid', $off->term_id );
	$new   = fs_cat( 'status-new' );
	update_term_meta( $new->term_id, 'fisha_studio_hidden', '1' );
	fs_eq( 'ok', fisha_cat_page_status( $shown ) );
	fs_eq( 'noindex', fisha_cat_page_status( $off ) );
	fs_eq( 'noindex', fisha_cat_page_status( $kid ), 'parent hidden' );
	fs_eq( '404', fisha_cat_page_status( $new ) );
} );

fs_test( 'cat hiding: hidden categories are left out of the sitemap', function () {
	$on  = fs_cat( 'map-on' );
	$off = fs_cat( 'map-off', 0, false );
	$args = apply_filters( 'wp_sitemaps_taxonomies_query_args', array( 'taxonomy' => 'product_cat' ), 'product_cat' );
	fs_true( in_array( $off->term_id, (array) ( $args['exclude'] ?? array() ), true ), 'hidden excluded' );
	fs_false( in_array( $on->term_id, (array) ( $args['exclude'] ?? array() ), true ), 'shown kept' );
} );

fs_test( 'cat hiding: an old path redirects to the category\'s address now', function () {
	$t = fs_cat( 'redir' );
	add_term_meta( $t->term_id, 'fisha_old_paths', 'product-category/zz-test-old-place' );
	fs_eq( get_term_link( $t ), fisha_cat_redirect_target( '/product-category/zz-test-old-place/' ) );
	fs_eq( null, fisha_cat_redirect_target( fisha_cat_path( $t ) ), 'current address never redirects' );
	fs_eq( null, fisha_cat_redirect_target( 'product-category/zz-test-nothing' ) );
} );

fs_test( 'cat hiding: a deleted category\'s path goes to its parent, or the Shop', function () {
	$parent = fs_cat( 'redir-parent' );
	$before = get_option( 'fisha_deleted_cat_paths', null );
	fs_on_cleanup( function () use ( $before ) { null === $before ? delete_option( 'fisha_deleted_cat_paths' ) : update_option( 'fisha_deleted_cat_paths', $before, false ); } );
	update_option( 'fisha_deleted_cat_paths', array( 'product-category/zz-test-gone' => $parent->term_id, 'product-category/zz-test-gone-top' => 0 ), false );
	fs_eq( get_term_link( $parent ), fisha_cat_redirect_target( 'product-category/zz-test-gone' ) );
	fs_eq( get_permalink( wc_get_page_id( 'shop' ) ), fisha_cat_redirect_target( 'product-category/zz-test-gone-top' ) );
} );
