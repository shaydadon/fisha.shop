<?php
/**
 * Safety switches for the dev copy (dev.fisha.shop): hidden from search engines,
 * every email goes to the site admin instead of customers, and a "DEV" ribbon so
 * nobody mistakes it for the live shop. Does nothing on fisha.shop or localhost.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_dev_site() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : wp_parse_url( home_url(), PHP_URL_HOST );
	return (bool) preg_match( '/^(dev|staging)\./', (string) $host );
}

if ( fisha_is_dev_site() ) {
	// never indexed
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_filter( 'pre_option_blog_public', '__return_zero' );
	add_action( 'send_headers', function () { header( 'X-Robots-Tag: noindex, nofollow', true ); } );
	add_filter( 'robots_txt', function () { return "User-agent: *\nDisallow: /\n"; }, 99 );

	// orders / contact forms on dev never email real people
	add_filter( 'wp_mail', function ( $args ) {
		$orig          = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];
		$args['to']    = get_option( 'admin_email' );
		$args['subject'] = '[DEV → ' . $orig . '] ' . $args['subject'];
		return $args;
	}, 999 );

	// visible ribbon
	$ribbon = function () {
		echo '<div style="position:fixed;left:12px;bottom:12px;z-index:99999;background:#E48734;color:#fff;font:800 12px/1 Nunito,sans-serif;letter-spacing:.12em;padding:8px 12px;border-radius:999px;box-shadow:0 4px 12px rgba(0,0,0,.25);pointer-events:none">DEV SITE</div>';
	};
	add_action( 'wp_footer', $ribbon );
	add_action( 'admin_footer', $ribbon );
}
