<?php
function fs_field( string $key ): array { return fisha_studio_product_fields()[ $key ]; }

fs_test( 'fields: money accepts human input', function () {
	$def = fs_field( 'regular_price' );
	fs_eq( array( '12.5', null ), fisha_studio_clean( $def, '12,50' ) );
	fs_eq( array( '120', null ), fisha_studio_clean( $def, '₪120' ) );
	fs_eq( array( '99', null ), fisha_studio_clean( $def, ' 99 ' ) );
	fs_eq( array( '2200', null ), fisha_studio_clean( $def, '2,200' ), 'thousands comma' );
	fs_eq( array( '', null ), fisha_studio_clean( $def, '' ) );
	fs_true( fisha_studio_clean( $def, '-5' )[1], 'negative refused' );
	fs_true( fisha_studio_clean( $def, 'abc' )[1], 'letters refused' );
} );

fs_test( 'fields: html is cleaned', function () {
	list( $v ) = fisha_studio_clean( fs_field( 'description' ), '<p style="color:red" class="MsoNormal">Hi <b>there</b><script>alert(1)</script><img src=x onerror=alert(1)></p>' );
	fs_false( strpos( $v, '<script' ), 'no script' );
	fs_false( strpos( $v, 'onerror' ), 'no handlers' );
	fs_false( strpos( $v, 'style=' ), 'no inline styles' );
	fs_false( strpos( $v, 'MsoNormal' ), 'no Word classes' );
	fs_true( false !== strpos( $v, '<b>there</b>' ), 'bold kept' );
	list( $r ) = fisha_studio_clean( fs_field( 'description' ), '<img src="https://example.com/a.jpg" alt="x"><table><tr><td>cell</td></tr></table><a href="javascript:alert(1)">x</a>' );
	fs_true( false !== strpos( $r, '<img' ), 'img kept' );
	fs_true( false !== strpos( $r, '<table>' ) && false !== strpos( $r, '<td>cell</td>' ), 'table kept' );
	fs_true( false === strpos( $r, 'javascript:' ), 'javascript href dropped' );
} );

fs_test( 'fields: other types', function () {
	fs_eq( array( 3, null ), fisha_studio_clean( fs_field( 'stock_quantity' ), '3' ) );
	fs_eq( array( null, null ), fisha_studio_clean( fs_field( 'stock_quantity' ), '' ) );
	fs_true( fisha_studio_clean( fs_field( 'stock_quantity' ), '2.5' )[1], 'whole numbers only' );
	fs_true( fisha_studio_clean( fs_field( 'stock_quantity' ), '-1' )[1], 'no negative stock input' );
	fs_eq( array( true, null ), fisha_studio_clean( fs_field( 'featured' ), 'true' ) );
	fs_eq( array( false, null ), fisha_studio_clean( fs_field( 'featured' ), '0' ) );
	fs_true( fisha_studio_clean( fs_field( 'stock_status' ), 'maybe' )[1], 'unknown enum' );
	fs_eq( array( '#efe8dc', null ), fisha_studio_clean( fs_field( 'card_bg' ), '#EFE8DC' ) );
	fs_true( fisha_studio_clean( fs_field( 'card_bg' ), 'red' )[1], 'colour must be hex' );
	fs_eq( array( '2026-11-01', null ), fisha_studio_clean( fs_field( 'date_on_sale_from' ), '2026-11-01' ) );
	fs_true( fisha_studio_clean( fs_field( 'date_on_sale_from' ), '2026-02-30' )[1], 'impossible date' );
	fs_true( fisha_studio_clean( fs_field( 'name' ), str_repeat( 'x', 121 ) )[1], 'name max 120' );
	fs_true( fisha_studio_clean( fs_field( 'category_ids' ), array( 99999999 ) )[1], 'unknown category' );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'zz-test not an image', 'post_status' => 'draft' ) );
	fs_on_cleanup( function () use ( $page ) { wp_delete_post( $page, true ); } );
	fs_true( fisha_studio_clean( fs_field( 'image_id' ), $page )[1], 'non-image refused' );
	$img = fs_image();
	fs_eq( array( $img, null ), fisha_studio_clean( fs_field( 'image_id' ), (string) $img ), 'image accepted as int' );
} );

fs_test( 'fields: sanitize ignores unknown keys and reports errors per field', function () {
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), array( 'name' => ' Hello ', 'evil' => 'x', 'regular_price' => 'abc' ) );
	fs_eq( 'Hello', $values['name'] );
	fs_false( isset( $values['evil'] ), 'unknown key dropped' );
	fs_true( isset( $errors['regular_price'] ), 'price error reported' );
} );

fs_test( 'fields: every field round-trips through apply, save and read', function () {
	$cat  = fs_term( 'roundtrip' );
	$img  = fs_image();
	$img2 = fs_image();
	$tag  = wp_insert_term( 'zz-test-tag', 'product_tag' );
	fs_on_cleanup( function () use ( $tag ) { wp_delete_term( $tag['term_id'], 'product_tag' ); } );
	$p = fs_product();
	$want = array(
		'name' => 'zz-test Round Trip', 'slug' => 'zz-test-round-trip', 'status' => 'private', 'catalog_visibility' => 'search',
		'featured' => true, 'category_ids' => array( $cat->term_id ), 'tag_ids' => array( $tag['term_id'] ),
		'short_description' => '<p>Short <b>bold</b></p>', 'description' => '<h2>Long</h2><p>Text</p>', 'reviews_allowed' => false,
		'menu_order' => -3, 'regular_price' => '650', 'sale_price' => '500', 'date_on_sale_from' => '2026-11-01', 'date_on_sale_to' => '2026-11-30',
		'sku' => 'ZZ-TEST-RT-' . wp_rand( 1000, 9999 ), 'manage_stock' => true, 'stock_quantity' => 4, 'stock_status' => 'instock',
		'low_stock_amount' => 1, 'backorders' => 'no', 'sold_individually' => true, 'image_id' => $img, 'gallery_image_ids' => array( $img2 ),
		'weight' => '1.2', 'length' => '33', 'width' => '33', 'height' => '2', 'shipping_class_id' => 0, 'tax_status' => 'taxable', 'tax_class' => '',
		'card_bg' => '#e3ecef', 'meta_line' => 'Ink on cotton paper · 33 × 33 cm', 'series' => 'zz-test-series', 'hero_skip' => true,
		'seo_title' => 'Round Trip | Fisha', 'seo_desc' => 'A test product for the round trip.',
	);
	fs_eq( array_keys( fisha_studio_product_fields() ), array_keys( $want ), 'the test covers every field' );
	fisha_studio_product_apply( $p, $want );
	$p->save();
	$got = fisha_studio_product_read( wc_get_product( $p->get_id() ) );
	foreach ( $want as $k => $v ) fs_eq( $v, $got[ $k ], $k );
} );

fs_test( 'fields: validation of a complete product', function () {
	$p   = fs_product();
	$ok  = fisha_studio_product_read( $p );
	$ok['image_id']     = fs_image();
	$ok['category_ids'] = array( fs_term( 'validate' )->term_id );
	fs_eq( array(), fisha_studio_product_validate( $ok, $p ), 'complete product is valid' );

	$bad = array_merge( $ok, array( 'name' => '', 'short_description' => '', 'image_id' => 0, 'category_ids' => array() ) );
	$e   = fisha_studio_product_validate( $bad, $p );
	foreach ( array( 'name', 'short_description', 'image_id', 'category_ids' ) as $k ) fs_true( isset( $e[ $k ] ), "$k required" );

	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'sale_price' => '100' ) ), $p )['sale_price'] ), 'sale must be lower' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'regular_price' => '', 'sale_price' => '50' ) ), $p )['sale_price'] ), 'sale needs a price' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'date_on_sale_from' => '2026-12-02', 'date_on_sale_to' => '2026-12-01' ) ), $p )['date_on_sale_to'] ), 'dates in order' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'manage_stock' => true, 'stock_quantity' => null ) ), $p )['stock_quantity'] ), 'stock needed' );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'slug' => 'studio' ) ), $p )['slug'] ), 'reserved slug' );
	$other = fs_product( array( 'sku' => 'ZZ-TEST-DUP-' . $p->get_id() ) );
	fs_true( isset( fisha_studio_product_validate( array_merge( $ok, array( 'sku' => $other->get_sku() ) ), $p )['sku'] ), 'duplicate sku' );
} );
