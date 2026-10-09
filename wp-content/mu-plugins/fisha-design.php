<?php
/**
 * Plugin Name: Fisha Design
 * Description: Fisha.shop custom design layer (header fish with mouse-following eyes, styles).
 * Version: 0.1.0
 * Author: Fisha
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_DESIGN_DIR', __DIR__ . '/fisha-design/' );
define( 'FISHA_DESIGN_URL', content_url( 'mu-plugins/fisha-design/' ) );

require_once FISHA_DESIGN_DIR . 'devsite.php';
require_once FISHA_DESIGN_DIR . 'river.php';
require_once FISHA_DESIGN_DIR . 'mosaic.php';
require_once FISHA_DESIGN_DIR . 'tattoos.php';
require_once FISHA_DESIGN_DIR . 'contact.php';
require_once FISHA_DESIGN_DIR . 'shop.php';
require_once FISHA_DESIGN_DIR . 'cart.php';
require_once FISHA_DESIGN_DIR . 'legal.php';
require_once FISHA_DESIGN_DIR . 'ux.php';
require_once FISHA_DESIGN_DIR . 'perf.php';
require_once FISHA_DESIGN_DIR . 'seo.php';
require_once FISHA_DESIGN_DIR . 'leads.php';
require_once FISHA_DESIGN_DIR . 'home.php';

function fisha_asset_ver( $rel ) {
	$f = FISHA_DESIGN_DIR . $rel;
	return file_exists( $f ) ? filemtime( $f ) : '0';
}

function fisha_eyes_ready() {
	return file_exists( FISHA_DESIGN_DIR . 'img/fisha-body.png' ) && file_exists( FISHA_DESIGN_DIR . 'img/fisha-pupils.png' );
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'fisha-nunito', 'https://fonts.googleapis.com/css2?family=Nunito:wght@300..900&display=swap', array(), null );
	wp_enqueue_style( 'fisha-design', FISHA_DESIGN_URL . 'fisha-design.css', array( 'fisha-nunito' ), fisha_asset_ver( 'fisha-design.css' ) );
	wp_enqueue_script( 'fisha-eyes', FISHA_DESIGN_URL . 'fisha-eyes.js', array(), fisha_asset_ver( 'fisha-eyes.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_enqueue_script( 'fisha-layout', FISHA_DESIGN_URL . 'fisha-layout.js', array(), fisha_asset_ver( 'fisha-layout.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );

function fisha_eyes_markup() {
	if ( ! fisha_eyes_ready() ) return '';
	$body   = esc_url( FISHA_DESIGN_URL . 'img/fisha-body-sm.png?v=' . fisha_asset_ver( 'img/fisha-body-sm.png' ) );
	$pupils = esc_url( FISHA_DESIGN_URL . 'img/fisha-pupils-sm.png?v=' . fisha_asset_ver( 'img/fisha-pupils-sm.png' ) );
	return '<span class="fisha-eyes" aria-hidden="true">'
		. '<img class="fisha-eyes__body" src="' . $body . '" width="160" height="113" alt="" draggable="false" decoding="async">'
		. '<span class="fisha-eyes__pupils-wrap"><img class="fisha-eyes__pupils" src="' . $pupils . '" width="160" height="113" alt="" draggable="false" decoding="async"></span>'
		. '</span>';
}

// Inject the fish into the header, right after the mini-cart (once per page).
add_filter( 'render_block', function ( $html, $block ) {
	static $done = false;
	if ( $done || is_admin() || ( $block['blockName'] ?? '' ) !== 'woocommerce/mini-cart' ) return $html;
	$done = true;
	$html = fisha_swap_cart_icon( $html );
	return $html . fisha_eyes_markup();
}, 10, 2 );

// Fallback for pages whose header has no mini-cart: JS moves this into the header.
add_action( 'wp_footer', function () {
	if ( fisha_eyes_ready() ) echo '<template id="fisha-eyes-fallback">' . fisha_eyes_markup() . '</template>';
} );

// Fisha shopping-bag icon (replaces the WooCommerce cart icon). Sized/weighted to match the account icon.
function fisha_bag_icon_svg() {
	return '<svg xmlns="http://www.w3.org/2000/svg" class="wc-block-mini-cart__icon fisha-bag-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="miter" aria-hidden="true" focusable="false">'
		. '<path d="M7.7 9.5 A4.3 4.3 0 0 1 16.3 9.5" />'
		. '<path d="M6.9 9.5 H17.1 L17.7 20.5 H6.3 Z" />'
		. '</svg>';
}

function fisha_swap_cart_icon( $html ) {
	return preg_replace( '#<svg\b[^>]*class="[^"]*wc-block-mini-cart__icon[^"]*"[^>]*>.*?</svg>#s', fisha_bag_icon_svg(), $html, 1 );
}

// Call-to-action button in the Fisha style: [fisha_cta text="…" url="/…/"]
add_shortcode( 'fisha_cta', function ( $atts ) {
	$a = shortcode_atts( array( 'text' => 'Read more', 'url' => '/', 'align' => 'center' ), $atts, 'fisha_cta' );
	return '<div class="fisha-cta-wrap" style="text-align:' . esc_attr( $a['align'] ) . '"><a class="fisha-cta" href="' . esc_url( home_url( $a['url'] ) ) . '">'
		. esc_html( $a['text'] ) . ' <span aria-hidden="true">→</span></a></div>';
} );

// Organization structured data (helps Google show the brand name, logo and site)
add_action( 'wp_head', function () {
	if ( ! is_front_page() ) return;
	$logo_id = (int) get_theme_mod( 'custom_logo' ) ?: (int) get_option( 'site_logo' );
	$data = array(
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'name'     => get_bloginfo( 'name' ),
		'url'      => home_url( '/' ),
	);
	if ( $logo_id && ( $logo = wp_get_attachment_image_url( $logo_id, 'full' ) ) ) $data['logo'] = $logo;
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
} );
