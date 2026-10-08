<?php
/**
 * Performance layer (from Lighthouse): load plugin assets only where they are used,
 * prioritise the LCP image, trim fonts and third-party scripts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_ctx() {
	static $c = null;
	if ( null !== $c ) return $c;
	$woo     = function_exists( 'is_woocommerce' );
	$shop    = function_exists( 'fisha_is_shop_cat' ) && ( fisha_is_shop_cat() || ( function_exists( 'fisha_is_shop_home' ) && fisha_is_shop_home() ) );
	$product = $woo && is_product();
	$cartish = $woo && ( is_cart() || is_checkout() || is_account_page() );
	$post    = is_singular() ? get_post() : null;
	$content = $post ? (string) $post->post_content : '';
	$elementor = false;
	if ( $post && class_exists( '\Elementor\Plugin' ) ) {
		$doc = \Elementor\Plugin::$instance->documents->get( $post->ID );
		$elementor = $doc && $doc->is_built_with_elementor();
	}
	$c = array(
		'shop' => $shop || ( $woo && is_woocommerce() ), 'product' => $product, 'cartish' => $cartish,
		'woo_any' => $shop || $product || $cartish || ( $woo && is_woocommerce() ),
		'elementor' => $elementor && ! $shop, // our shop templates don't render Elementor content
		'cf7' => $post && ( has_shortcode( $content, 'contact-form-7' ) || has_shortcode( $content, 'fisha_contact' ) ),
		'woolentor' => $post && ( false !== strpos( $content, 'woolentor' ) ),
	);
	return $c;
}

function fisha_perf_strip() {
	if ( is_admin() || is_customize_preview() || ( isset( $_GET['elementor-preview'] ) ) ) return;
	$c = fisha_ctx();
	$styles = array(); $scripts = array();
	// WooCommerce classic assets: only on shop / product / cart pages (the header mini-cart is a block with its own assets)
	if ( ! $c['woo_any'] ) {
		$styles  = array_merge( $styles, array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'woocommerce-blocktheme' ) );
		$scripts = array_merge( $scripts, array( 'wc-jquery-blockui', 'jquery-blockui', 'wc-add-to-cart', 'wc-js-cookie', 'js-cookie', 'woocommerce', 'wc-cart-fragments', 'sourcebuster-js', 'wc-order-attribution' ) );
	}
	// single-product scripts (gallery, zoom, variations) only on product pages
	if ( ! $c['product'] ) {
		$styles  = array_merge( $styles, array( 'photoswipe', 'photoswipe-default-skin' ) );
		$scripts = array_merge( $scripts, array( 'wc-photoswipe', 'photoswipe', 'wc-photoswipe-ui-default', 'photoswipe-ui-default', 'wc-zoom', 'zoom', 'wc-flexslider', 'flexslider', 'wc-single-product', 'wc-add-to-cart-variation' ) );
	}
	// Contact Form 7 only where a form is shown
	if ( ! $c['cf7'] ) { $styles[] = 'contact-form-7'; $scripts = array_merge( $scripts, array( 'contact-form-7', 'swv' ) ); }
	// Elementor only on pages built with it
	if ( ! $c['elementor'] ) {
		$styles  = array_merge( $styles, array( 'elementor-frontend', 'elementor-post-6', 'elementor-icons', 'widget-image', 'widget-heading', 'e-animations', 'elementor-gf-nunito', 'elementor-pro', 'pro-elements-handlers' ) );
		$scripts = array_merge( $scripts, array( 'elementor-frontend', 'elementor-frontend-modules', 'elementor-webpack-runtime', 'elementor-pro-frontend', 'elementor-pro-webpack-runtime', 'pro-elements-handlers', 'elementor-waypoints' ) );
	}
	// third-party marketing embed (Hostinger Reach) – not used on the site
	$scripts[] = 'hostinger-reach-embed';
	foreach ( $styles as $h ) { wp_dequeue_style( $h ); }
	foreach ( $scripts as $h ) { wp_dequeue_script( $h ); }

	// WooLentor: its widgets/blocks are not used anywhere on the site (Fisha templates replaced them)
	if ( ! $c['woolentor'] ) {
		global $wp_styles, $wp_scripts;
		foreach ( (array) ( $wp_styles->queue ?? array() ) as $h ) {
			$src = $wp_styles->registered[ $h ]->src ?? '';
			if ( strpos( $src, '/woolentor-addons' ) !== false || in_array( $h, array( 'font-awesome', 'simple-line-icons-wl', 'htflexboxgrid', 'slick' ), true ) ) wp_dequeue_style( $h );
		}
		foreach ( (array) ( $wp_scripts->queue ?? array() ) as $h ) {
			$src = $wp_scripts->registered[ $h ]->src ?? '';
			if ( strpos( $src, '/woolentor-addons' ) !== false || $h === 'slick' ) wp_dequeue_script( $h );
		}
	}
	// jQuery Migrate only helps very old code; drop it (removes console noise + deprecation warnings)
	global $wp_scripts;
	if ( isset( $wp_scripts->registered['jquery'] ) ) $wp_scripts->registered['jquery']->deps = array_diff( $wp_scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
}
add_action( 'wp_enqueue_scripts', 'fisha_perf_strip', 9999 );
add_action( 'wp_print_footer_scripts', 'fisha_perf_strip', 1 );

// WooLentor quick-view modal markup in the footer isn't needed either
add_action( 'wp', function () {
	if ( is_admin() || fisha_ctx()['woolentor'] ) return;
	foreach ( array( 'wp_footer' ) as $hook ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) ) continue;
		foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) foreach ( $cbs as $id => $cb ) {
			$f = $cb['function'];
			$cls = is_array( $f ) && is_object( $f[0] ) ? get_class( $f[0] ) : ( is_array( $f ) ? $f[0] : '' );
			if ( $cls && stripos( $cls, 'woolentor' ) !== false && stripos( is_array( $f ) ? $f[1] : '', 'quick' ) !== false ) remove_action( $hook, $f, $prio );
		}
	}
}, 20 );

// Elementor: we already load Nunito ourselves
add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );

// Fonts: connect early
add_action( 'wp_head', function () {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n" . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 1 );

// LCP: the home hero drawing loads first and at the right size
define( 'FISHA_HERO_ATTACHMENT', 200 );
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $att ) {
	if ( (int) $att->ID !== FISHA_HERO_ATTACHMENT ) return $attr;
	$attr['fetchpriority'] = 'high';
	$attr['loading']       = 'eager';
	$attr['decoding']      = 'async';
	$attr['sizes']         = '(max-width: 781px) 94vw, 1100px';
	return $attr;
}, 20, 2 );
add_action( 'wp_head', function () {
	if ( ! is_front_page() ) return;
	$set = wp_get_attachment_image_srcset( FISHA_HERO_ATTACHMENT, 'full' );
	$src = wp_get_attachment_image_url( FISHA_HERO_ATTACHMENT, 'large' );
	if ( ! $set || ! $src ) return;
	printf( '<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="(max-width: 781px) 94vw, 1100px" fetchpriority="high">' . "\n", esc_url( $src ), esc_attr( $set ) );
}, 2 );
// Elementor sometimes lazy-loads the first image; keep the hero eager
add_filter( 'wp_img_tag_add_loading_optimization_attrs', function ( $attrs, $tag ) {
	if ( strpos( $tag, 'wp-image-' . FISHA_HERO_ATTACHMENT ) !== false ) { $attrs['loading'] = 'eager'; $attrs['fetchpriority'] = 'high'; }
	return $attrs;
}, 10, 2 );

// The Hostinger AI theme marks the page uncacheable on `woocommerce_cart_updated`,
// which WooCommerce fires on every request that calculates totals, even with an
// empty cart. So no page was ever page-cached. Keep the real cart actions
// (add to cart, AJAX add) but only skip the cache for visitors who have a cart.
add_action( 'init', function () {
	global $wp_filter;
	if ( empty( $wp_filter['woocommerce_cart_updated'] ) ) {
		return;
	}
	foreach ( $wp_filter['woocommerce_cart_updated']->callbacks as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn = $cb['function'];
			if ( is_array( $fn ) && is_object( $fn[0] ) && 'Hostinger\\AiTheme\\Compatibility\\LiteSpeedCache' === get_class( $fn[0] ) ) {
				remove_action( 'woocommerce_cart_updated', $fn, $prio );
				add_action( 'woocommerce_cart_updated', function () {
					if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
						do_action( 'litespeed_control_set_nocache', 'Fisha: visitor has a cart' );
					}
				} );
			}
		}
	}
}, 99 );

// SEO: canonical URL on our full-page shop templates (WordPress only prints one on single pages)
add_action( 'wp_head', function () {
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) return;
	$url = '';
	if ( function_exists( 'fisha_is_shop_home' ) && fisha_is_shop_home() ) $url = get_permalink( wc_get_page_id( 'shop' ) );
	elseif ( function_exists( 'fisha_is_shop_cat' ) && fisha_is_shop_cat() ) { $t = get_queried_object(); $url = $t ? get_term_link( $t ) : ''; }
	if ( $url && ! is_wp_error( $url ) ) echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
}, 3 );
