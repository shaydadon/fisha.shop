<?php
fs_test( 'cat visibility: shown with a product is displayed; hidden, empty or never published is not', function () {
	$top = fs_cat( 'vis-top' );
	fs_false( fisha_cat_is_displayed( $top ), 'empty' );
	fs_product( array( 'category_ids' => array( $top->term_id ) ) );
	fisha_cat_cache_reset();
	fs_true( fisha_cat_is_displayed( $top ), 'has a product' );
	update_term_meta( $top->term_id, 'fisha_shown', '0' );
	fisha_cat_cache_reset();
	fs_false( fisha_cat_is_displayed( $top ), 'switched off' );
	update_term_meta( $top->term_id, 'fisha_shown', '1' );
	update_term_meta( $top->term_id, 'fisha_studio_hidden', '1' );
	fs_false( fisha_cat_is_displayed( $top ), 'not published yet' );
	delete_term_meta( $top->term_id, 'fisha_shown' );
	delete_term_meta( $top->term_id, 'fisha_studio_hidden' );
	fs_false( fisha_cat_is_displayed( $top ), 'missing fisha_shown means hidden' );
} );

fs_test( 'cat visibility: draft and private products do not count, sold-out ones do', function () {
	$c = fs_cat( 'vis-count' );
	fs_product( array( 'category_ids' => array( $c->term_id ) ), 'draft' );
	fs_product( array( 'category_ids' => array( $c->term_id ) ), 'private' );
	fisha_cat_cache_reset();
	fs_false( fisha_cat_has_products( $c ), 'draft/private only' );
	fs_product( array( 'category_ids' => array( $c->term_id ), 'stock_status' => 'outofstock' ) );
	fisha_cat_cache_reset();
	fs_true( fisha_cat_has_products( $c ), 'sold out counts' );
} );

fs_test( 'cat visibility: products in a sub-category count for the parent; a hidden parent hides its children', function () {
	$top = fs_cat( 'vis-parent' );
	$kid = fs_cat( 'vis-kid', $top->term_id );
	fs_product( array( 'category_ids' => array( $kid->term_id ) ) );
	fisha_cat_cache_reset();
	fs_true( fisha_cat_is_displayed( $top ), 'parent counts the child product' );
	fs_true( fisha_cat_is_displayed( $kid ) );
	update_term_meta( $top->term_id, 'fisha_shown', '0' );
	fisha_cat_cache_reset();
	fs_false( fisha_cat_is_displayed( $kid ), 'parent hidden' );
} );

fs_test( 'cat visibility: displayed children come in the owner order, then by name', function () {
	$top = fs_cat( 'vis-order' );
	$b = fs_cat( 'vis-b', $top->term_id );
	$a = fs_cat( 'vis-a', $top->term_id );
	$c = fs_cat( 'vis-c', $top->term_id );
	$empty = fs_cat( 'vis-empty', $top->term_id );
	foreach ( array( $a, $b, $c ) as $t ) fs_product( array( 'category_ids' => array( $t->term_id ) ) );
	update_term_meta( $c->term_id, 'order', 1 );
	update_term_meta( $a->term_id, 'order', 2 );
	update_term_meta( $b->term_id, 'order', 2 );
	fisha_cat_cache_reset();
	fs_eq( array( $c->term_id, $a->term_id, $b->term_id ), wp_list_pluck( fisha_cat_displayed_children( $top->term_id ), 'term_id' ) );
	fs_false( in_array( $empty->term_id, wp_list_pluck( fisha_cat_displayed_children( $top->term_id ), 'term_id' ), true ), 'empty child left out' );
} );

fs_test( 'cat visibility: today\'s three top categories are displayed, in today\'s order', function () {
	fisha_cat_cache_reset();
	$slugs = wp_list_pluck( fisha_displayed_top_categories(), 'slug' );
	fs_eq( array( 'wearables', 'collectibles', 'originals' ), array_values( array_intersect( $slugs, array( 'wearables', 'collectibles', 'originals' ) ) ) );
	foreach ( array( 'fisha-wear', 'made-for-city-life', 'shop-fisha', 'street-goods', 'uncategorized' ) as $s ) fs_false( in_array( $s, $slugs, true ), "$s hidden" );
} );

fs_test( 'cat visibility: migration shows the three shop branches, hides the rest, keeps existing values', function () {
	$loose = fs_term( 'vis-migrate-loose' );
	$under = fs_term( 'vis-migrate-under', (int) get_term_by( 'slug', 'wearables', 'product_cat' )->term_id );
	$kept  = fs_term( 'vis-migrate-kept' );
	update_term_meta( $kept->term_id, 'fisha_shown', '1' );
	$version = get_option( 'fisha_cat_visibility_db' );
	fs_on_cleanup( function () use ( $version ) { update_option( 'fisha_cat_visibility_db', $version ); } );
	update_option( 'fisha_cat_visibility_db', 0 );
	fisha_cat_visibility_migrate();
	fs_eq( '0', get_term_meta( $loose->term_id, 'fisha_shown', true ), 'outside the shop branches' );
	fs_eq( '1', get_term_meta( $under->term_id, 'fisha_shown', true ), 'below Wearables' );
	fs_eq( '1', get_term_meta( $kept->term_id, 'fisha_shown', true ), 'existing value kept' );
	$w = get_term_by( 'slug', 'wearables', 'product_cat' );
	fs_eq( 'T-shirts, hoodies, caps and socks carrying the hand-drawn fish.', get_term_meta( $w->term_id, 'fisha_home_line', true ) );
	fs_eq( '1', (string) get_term_meta( $w->term_id, 'order', true ) );
	fs_eq( '3', (string) get_term_meta( get_term_by( 'slug', 'originals', 'product_cat' )->term_id, 'order', true ) );
	fs_eq( 1, (int) get_option( 'fisha_cat_visibility_db' ), 'version stored' );
} );
