<?php
/**
 * Fisha Studio URL rules. The Studio only ever sets slugs: products stay at /product/{slug}/ and categories at
 * /product-category/{parent}/{slug}/. New slugs are short lowercase ASCII; existing slugs are never regenerated.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_reserved_slugs(): array {
	return array( 'page', 'feed', 'embed', 'product', 'product-category', 'product-tag', 'shop', 'shop-2', 'studio',
		'cart', 'checkout', 'my-account', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login' );
}

/** Error message for a slug the owner typed, or null when it is fine. Empty = "make one from the name". */
function fisha_studio_slug_error( string $slug ): ?string {
	if ( '' === $slug ) return null;
	if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) return 'Use only lowercase letters, numbers and single dashes.';
	if ( strlen( $slug ) > 60 ) return 'Keep the address to 60 characters or fewer.';
	if ( in_array( $slug, fisha_studio_reserved_slugs(), true ) ) return 'This address is used by the site. Please pick another one.';
	return null;
}

function fisha_studio_slug_from_name( string $name ): string {
	$slug = sanitize_title( remove_accents( $name ) );
	$slug = preg_replace( '/%[0-9a-f]{2}/i', '', $slug );           // drop letters that have no ASCII form
	$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
	if ( strlen( $slug ) > 60 ) {
		$slug = substr( $slug, 0, 60 );
		$cut  = strrpos( $slug, '-' );
		if ( false !== $cut && $cut > 20 ) $slug = substr( $slug, 0, $cut );
		$slug = trim( $slug, '-' );
	}
	return '' !== $slug ? $slug : 'item';
}

function fisha_studio_unique_product_slug( string $slug, int $id ): string {
	if ( in_array( $slug, fisha_studio_reserved_slugs(), true ) ) $slug .= '-2';
	return wp_unique_post_slug( $slug, $id, 'publish', 'product', 0 );
}
