<?php
/**
 * Cart persistence.
 * WooCommerce keeps every cart in a server-side session linked to the visitor by the
 * wp_woocommerce_session_* cookie (logged-in shoppers also get a persistent cart saved to their account).
 * Here we:
 *  - keep guest carts for 14 days instead of WooCommerce's default 48 hours, renewed while they browse;
 *  - make sure pages that show cart state are never served from a shared page cache to someone with a cart.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Session lifetime: renew when < 13 days are left, expire after 14 days of inactivity.
add_filter( 'wc_session_expiring', function () { return 13 * DAY_IN_SECONDS; } );
add_filter( 'wc_session_expiration', function () { return 14 * DAY_IN_SECONDS; } );

// Logged-in customers: keep the persistent (account-saved) cart on.
add_filter( 'woocommerce_persistent_cart_enabled', '__return_true' );

/**
 * Caching safety net. Visitors with something in the cart carry the woocommerce_items_in_cart cookie;
 * for them, tell page caches (LiteSpeed on Hostinger, CDNs) not to hand out a shared copy of the page.
 * Cart / checkout / account pages are never cached.
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'WC' ) ) return;
	$has_cart = ! empty( $_COOKIE['woocommerce_items_in_cart'] );
	$cart_pages = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
	if ( $has_cart || $cart_pages ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		do_action( 'litespeed_control_set_nocache', 'fisha: visitor has a cart' );
		nocache_headers();
	}
}, 1 );
