<?php
fs_test( 'urls: slugs from names are short, lowercase ascii', function () {
	fs_eq( 'single-fisha-no-4', fisha_studio_slug_from_name( 'Single Fisha No. 4' ) );
	fs_eq( 'cafe-fisha', fisha_studio_slug_from_name( 'Café Fisha!' ) );
	fs_true( strlen( fisha_studio_slug_from_name( str_repeat( 'very long words ', 10 ) ) ) <= 60, 'max 60 chars' );
	fs_false( '-' === substr( fisha_studio_slug_from_name( str_repeat( 'abc ', 30 ) ), -1 ), 'no trailing dash' );
	fs_eq( 'item', fisha_studio_slug_from_name( 'שלום' ), 'non-latin falls back' );
} );

fs_test( 'urls: bad and reserved slugs are refused', function () {
	fs_eq( null, fisha_studio_slug_error( 'out-to-the-river' ) );
	fs_eq( null, fisha_studio_slug_error( '' ), 'empty means generate' );
	fs_true( fisha_studio_slug_error( 'Out To River' ), 'spaces/capitals' );
	fs_true( fisha_studio_slug_error( 'a--b' ), 'double dash' );
	fs_true( fisha_studio_slug_error( str_repeat( 'a', 61 ) ), 'too long' );
	fs_true( fisha_studio_slug_error( 'studio' ), 'reserved' );
	fs_true( fisha_studio_slug_error( 'shop-2' ), 'reserved' );
} );

fs_test( 'urls: unique product slug skips taken ones', function () {
	$p = fs_product( array( 'slug' => 'zz-test-unique' ) );
	fs_eq( 'zz-test-unique', fisha_studio_unique_product_slug( 'zz-test-unique', $p->get_id() ), 'own slug stays' );
	fs_eq( 'zz-test-unique-2', fisha_studio_unique_product_slug( 'zz-test-unique', 0 ) );
} );
