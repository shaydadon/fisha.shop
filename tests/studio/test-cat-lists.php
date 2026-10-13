<?php
function fs_nav_link( WP_Term $t ): array {
	return array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => $t->name, 'url' => get_term_link( $t ), 'type' => 'product_cat', 'id' => $t->term_id, 'kind' => 'taxonomy' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
}
function fs_page_link( string $label ): array {
	return array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => $label, 'url' => home_url( '/' . sanitize_title( $label ) . '/' ), 'kind' => 'post-type', 'type' => 'page' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
}

fs_test( 'cat lists: a run of top-level category links is replaced by the displayed ones, pages stay', function () {
	$a = fs_cat( 'nav-a' ); $b = fs_cat( 'nav-b' ); $c = fs_cat( 'nav-c' );
	$blocks = array( fs_page_link( 'River' ), fs_nav_link( $a ), fs_nav_link( $b ), fs_page_link( 'Contact' ) );
	$out = fisha_nav_swap_cat_links( $blocks, array( $c, $a ) );
	fs_eq( array( 'River', $c->name, $a->name, 'Contact' ), array_map( function ( $x ) { return $x['attrs']['label']; }, $out ) );
	fs_eq( $c->term_id, $out[1]['attrs']['id'] );
	fs_eq( 'product_cat', $out[1]['attrs']['type'] );
} );

fs_test( 'cat lists: category links inside the Shop submenu are replaced and the submenu stays whole', function () {
	$a = fs_cat( 'nav-sub-a' ); $b = fs_cat( 'nav-sub-b' );
	$sub = array( 'blockName' => 'core/navigation-submenu', 'attrs' => array( 'label' => 'Shop' ), 'innerBlocks' => array( fs_nav_link( $a ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
	$out = fisha_nav_swap_cat_links( array( $sub, fs_page_link( 'River' ) ), array( $a, $b ) );
	fs_eq( 2, count( $out[0]['innerBlocks'] ) );
	fs_eq( array( null, null ), $out[0]['innerContent'], 'one placeholder per link' );
	fs_eq( 'River', $out[1]['attrs']['label'] );
} );

fs_test( 'cat lists: sub-category links and lists without category links are left alone', function () {
	$top = fs_cat( 'nav-top' );
	$kid = fs_cat( 'nav-kid', $top->term_id );
	$blocks = array( fs_nav_link( $kid ), fs_page_link( 'River' ) );
	fs_eq( $blocks, fisha_nav_swap_cat_links( $blocks, array( $top ) ) );
	$pages = array( fs_page_link( 'River' ) );
	fs_eq( $pages, fisha_nav_swap_cat_links( $pages, array( $top ) ) );
} );

fs_test( 'cat lists: a rendered menu shows a newly shown top category with a product', function () {
	$new = fs_cat( 'nav-render' );
	update_term_meta( $new->term_id, 'order', 99 );
	fs_product( array( 'category_ids' => array( $new->term_id ) ) );
	fisha_cat_cache_reset();
	$w = get_term_by( 'slug', 'wearables', 'product_cat' );
	$content = serialize_block( array( 'blockName' => 'core/navigation-submenu', 'attrs' => array( 'label' => 'Shop', 'url' => home_url( '/shop-2/' ), 'kind' => 'post-type', 'type' => 'page' ), 'innerBlocks' => array( fs_nav_link( $w ) ), 'innerHTML' => '', 'innerContent' => array( null ) ) );
	$nav = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'zz-test nav', 'post_content' => $content ) );
	fs_on_cleanup( function () use ( $nav ) { wp_delete_post( $nav, true ); } );
	$html = do_blocks( '<!-- wp:navigation {"ref":' . $nav . '} /-->' );
	fs_true( false !== strpos( $html, get_term_link( $new ) ), 'new category linked' );
	fs_true( strpos( $html, get_term_link( $w ) ) < strpos( $html, get_term_link( $new ) ), 'after Wearables (order)' );
	update_term_meta( $new->term_id, 'fisha_shown', '0' );
	fisha_cat_cache_reset();
	fs_false( strpos( do_blocks( '<!-- wp:navigation {"ref":' . $nav . '} /-->' ), get_term_link( $new ) ), 'hidden: gone' );
} );

fs_test( 'cat lists: Home list follows the displayed top categories, taglines from meta or the intro', function () {
	$new = fs_cat( 'home-list' );
	update_term_meta( $new->term_id, 'fisha_lead', 'A short intro.' );
	update_term_meta( $new->term_id, 'order', 99 );
	fs_product( array( 'category_ids' => array( $new->term_id ) ) );
	fisha_cat_cache_reset();
	$list = fisha_home_cat_list();
	fs_eq( array( 'wearables', 'collectibles', 'originals' ), array_slice( array_keys( $list ), 0, 3 ) );
	fs_eq( 'Ink drawings and paintings, signed and framed by hand.', $list['originals'] );
	fs_eq( 'A short intro.', $list[ $new->slug ] );
} );

fs_test( 'cat lists: Home header text follows the number of cards', function () {
	$d = 'Three ways to take the little fish home.';
	fs_eq( $d, fisha_home_cats_text( $d, 3 ) );
	fs_eq( 'Four ways to take the little fish home.', fisha_home_cats_text( $d, 4 ) );
	fs_eq( 'One way to take the little fish home.', fisha_home_cats_text( $d, 1 ) );
	fs_eq( 'Our own words.', fisha_home_cats_text( 'Our own words.', 4 ), 'custom text untouched' );
} );
