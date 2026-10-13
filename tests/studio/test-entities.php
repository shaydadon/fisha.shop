<?php
/**
 * Task 13 B: text WordPress stores with HTML entities ("&amp;") reads back as plain text ("&").
 */

fs_test( 'entities: text fields decode entities after cleaning', function () {
	fs_eq( array( 'Black & White', null ), fisha_studio_clean( fisha_studio_product_fields()['name'], 'Black &amp; White' ) );
	fs_eq( array( 'Black & White', null ), fisha_studio_clean( fisha_studio_product_fields()['name'], 'Black & White' ) );
	fs_eq( array( "Fisha's", null ), fisha_studio_clean( fisha_studio_product_fields()['meta_line'], 'Fisha&#039;s' ) );
} );

fs_test( 'entities: a title with & reads back as &, and publishing another change keeps the title byte-identical', function () {
	global $wpdb;
	$p = fs_product( array( 'image_id' => fs_image(), 'category_ids' => array( fs_term( 'ent-' . wp_rand() )->term_id ) ) );
	$title = 'zz-test Black &amp; White ' . wp_rand();
	$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $p->get_id() ) );
	clean_post_cache( $p->get_id() );
	$p = wc_get_product( $p->get_id() );
	fs_eq( str_replace( '&amp;', '&', $title ), fisha_studio_product_read( $p )['name'] );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/products/' . $p->get_id() );
	fs_eq( str_replace( '&amp;', '&', $title ), $d['values']['name'] );
	// The editor sends the name it showed: that is no change.
	fs_api( 'PUT', '/products/' . $p->get_id() . '/draft', array( 'fields' => array( 'name' => $d['values']['name'], 'regular_price' => '120' ), 'seen' => array( 'name' => $d['values']['name'], 'regular_price' => '100' ) ) );
	fs_eq( array( 'regular_price' ), array_keys( fisha_studio_draft_get( $p->get_id() )['fields'] ) );
	list( $st, $d ) = fs_api( 'POST', '/products/' . $p->get_id() . '/publish' );
	fs_eq( 200, $st, wp_json_encode( $d ) );
	clean_post_cache( $p->get_id() );
	fs_eq( $title, get_post_field( 'post_title', $p->get_id(), 'raw' ), 'title untouched' );
	list( , $d ) = fs_api( 'GET', '/products', array( 'search' => 'Black' ) );
	$row = wp_list_filter( $d['items'], array( 'id' => $p->get_id() ) );
	fs_eq( str_replace( '&amp;', '&', $title ), reset( $row )['name'], 'list row name' );
	list( , $d ) = fs_api( 'GET', '/inventory', array( 'search' => 'Black & White' ) );
	$row = wp_list_filter( $d['items'], array( 'id' => $p->get_id() ) );
	fs_eq( str_replace( '&amp;', '&', $title ), reset( $row )['name'], 'inventory row name' );
} );

fs_test( 'entities: category names and paths, option values and size labels are plain text', function () {
	$parent = fs_term( 'Tops &amp; Tees' );
	$child  = fs_term( 'Kids & Babies', $parent->term_id );
	fs_as( FISHA_STUDIO_ROLE );
	list( , $d ) = fs_api( 'GET', '/lookups' );
	$row = wp_list_filter( $d['categories'], array( 'id' => $child->term_id ) );
	$row = reset( $row );
	fs_eq( 'zz-test Kids & Babies', $row['name'] );
	fs_eq( 'zz-test Tops & Tees › zz-test Kids & Babies', $row['path'] );

	$t = wp_insert_term( 'zz-test B&W ' . wp_rand(), 'pa_size' );
	fs_on_cleanup( function () use ( $t ) { wp_delete_term( $t['term_id'], 'pa_size' ); } );
	$name = get_term( $t['term_id'], 'pa_size' )->name;
	fs_true( false !== strpos( $name, '&amp;' ), 'WordPress stores the term name encoded' );
	$cat = wp_list_filter( fisha_studio_attributes_catalog(), array( 'name' => 'pa_size' ) );
	$terms = wp_list_filter( reset( $cat )['terms'], array( 'id' => (int) $t['term_id'] ) );
	fs_eq( str_replace( '&amp;', '&', $name ), reset( $terms )['name'] );

	$p = fs_variable( array( 's' ) );
	$v = new WC_Product_Variation();
	$v->set_parent_id( $p->get_id() );
	$v->set_attributes( array( 'pa_size' => get_term( $t['term_id'], 'pa_size' )->slug ) );
	$v->set_status( 'publish' );
	$v->save();
	fs_false( strpos( fisha_studio_variation_label( wc_get_product( $v->get_id() ) ), '&amp;' ), 'size label decoded' );
} );

fs_test( 'entities: decoding never brings back tags', function () {
	$name = fisha_studio_product_fields()['seo_title'];
	$out  = fisha_studio_clean( $name, '&lt;script&gt;alert(1)&lt;/script&gt;Hi' )[0];
	fs_false( false !== strpos( $out, '<' ) || false !== strpos( $out, '>' ), 'no tags: ' . $out );
	fs_false( false !== stripos( $out, 'script' ) && false !== strpos( $out, '<' ), 'no script tag' );
	$out = fisha_studio_clean( $name, '&lt;/title&gt;&lt;script&gt;x()&lt;/script&gt;' )[0];
	fs_false( false !== strpos( $out, '<' ), 'no tags: ' . $out );
	fs_eq( 'Black & White', fisha_studio_clean( $name, 'Black &amp; White' )[0] );
} );
