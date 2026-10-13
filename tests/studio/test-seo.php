<?php
function fs_view( int $id ): void {
	$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $id, 'post_type' => 'product', 'post_status' => array( 'publish' ) ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	fs_on_cleanup( function () { wp_reset_query(); } );
}

fs_test( 'seo: product SEO title and description from the Studio', function () {
	$p = fs_product();
	update_post_meta( $p->get_id(), 'fisha_seo_title', 'Night Swim Original' );
	update_post_meta( $p->get_id(), 'fisha_seo_desc', 'An ink Fisha swimming at night.' );
	fs_view( $p->get_id() );
	fs_eq( 'An ink Fisha swimming at night.', fisha_seo_description() );
	fs_eq( 'Night Swim Original | ' . get_bloginfo( 'name' ), apply_filters( 'pre_get_document_title', '' ) );
} );

fs_test( 'seo: products without Studio SEO keep the old behaviour', function () {
	$p = fs_product( array( 'short_description' => 'Old short text.' ) );
	fs_view( $p->get_id() );
	fs_eq( 'Old short text.', fisha_seo_description() );
	fs_eq( '', apply_filters( 'pre_get_document_title', '' ) );
} );

fs_test( 'seo: preview shows the draft SEO text', function () {
	$p = fs_product();
	fisha_studio_product_edit( $p, array( 'seo_desc' => 'Draft description.' ) );
	fisha_studio_preview_install_product( $p->get_id(), fisha_studio_draft_get( $p->get_id() )['fields'] );
	fs_view( $p->get_id() );
	fs_eq( 'Draft description.', fisha_seo_description() );
} );

fs_test( 'seo: the product SEO title is printed escaped', function () {
	$p = fs_product();
	update_post_meta( $p->get_id(), 'fisha_seo_title', 'Black & White <b>' );
	fs_view( $p->get_id() );
	fs_eq( 'Black &amp; White &lt;b&gt; | ' . get_bloginfo( 'name' ), apply_filters( 'pre_get_document_title', '' ) );
} );
