<?php
/**
 * Site-wide UX/accessibility layer (from the UI/UX audit):
 * text-safe orange, 44px touch targets, 14px minimum text, skip links, WooCommerce pages in the Fisha style.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_woo_page() {
	return function_exists( 'is_woocommerce' ) && ( is_product() || is_cart() || is_checkout() || is_account_page() );
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'fisha-ux', FISHA_DESIGN_URL . 'ux.css', array( 'fisha-design' ), fisha_asset_ver( 'ux.css' ) );
	if ( fisha_is_woo_page() ) {
		wp_enqueue_style( 'fisha-woo', FISHA_DESIGN_URL . 'woo.css', array( 'fisha-ux' ), fisha_asset_ver( 'woo.css' ) );
		wp_enqueue_script( 'fisha-woo', FISHA_DESIGN_URL . 'woo.js', array( 'jquery' ), fisha_asset_ver( 'woo.js' ), array( 'in_footer' => true ) );
	}
	if ( function_exists( 'is_product' ) && is_product() ) {
		wp_enqueue_style( 'fisha-shop', FISHA_DESIGN_URL . 'shop.css', array( 'fisha-design' ), fisha_asset_ver( 'shop.css' ) );
		wp_enqueue_script( 'fisha-shop', FISHA_DESIGN_URL . 'shop.js', array(), fisha_asset_ver( 'shop.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_script( 'wc-add-to-cart' );
	}
}, 30 );

// Skip link for our own full-page templates (shop, categories) — block templates already print one.
add_action( 'wp_body_open', function () {
	if ( ! ( function_exists( 'fisha_is_shop_cat' ) && ( fisha_is_shop_cat() || ( function_exists( 'fisha_is_shop_home' ) && fisha_is_shop_home() ) ) ) ) return;
	echo '<a class="skip-link screen-reader-text fisha-skip" href="#wp--skip-link--target">Skip to content</a>';
}, 1 );

/* ---------- WooCommerce ---------- */
add_filter( 'woocommerce_product_single_add_to_cart_text', fn() => 'Add to bag' );
add_filter( 'woocommerce_sale_flash', fn() => '<span class="onsale">Sale</span>' );

// Checkout: use the normal site header (the checkout-only header showed a bare "Fisha" + floating fish) and give it an H1.
add_filter( 'render_block_data', function ( $block ) {
	if ( ( $block['blockName'] ?? '' ) === 'core/template-part' && in_array( $block['attrs']['slug'] ?? '', array( 'checkout-header', 'simple-header' ), true ) ) {
		$block['attrs']['slug']  = 'header';
		$block['attrs']['theme'] = get_stylesheet();
		$block['attrs']['tagName'] = 'header';
	}
	return $block;
} );
add_filter( 'render_block_woocommerce/checkout', function ( $html ) {
	if ( is_admin() || strpos( $html, 'fisha-woo-title' ) !== false ) return $html;
	return '<div class="fisha-woo-head"><p class="fisha-woo-eyebrow">Almost yours</p><h1 class="fisha-woo-title">Checkout</h1></div>' . $html;
} );

// Product page: replace the default related-products block with a Fisha shelf.
add_filter( 'render_block_woocommerce/product-collection', function ( $html, $block ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'fisha_product_card' ) ) return $html;
	$pid = get_queried_object_id();
	$ids = wc_get_related_products( $pid, 12 );
	if ( ! $ids ) return '';
	$items = array_filter( array_map( 'wc_get_product', $ids ), fn( $p ) => $p && $p->is_visible() );
	if ( ! $items ) return '';
	$cats = wc_get_product_term_ids( $pid, 'product_cat' );
	$term = $cats ? get_term( end( $cats ), 'product_cat' ) : null;
	ob_start(); ?>
	<section class="fisha-shop fs-related alignwide" aria-labelledby="fs-related-h">
		<div class="fs-shelf">
			<header class="fs-shelf__head">
				<div><h2 id="fs-related-h">You might also like</h2></div>
				<div class="fs-shelf__tools">
					<button type="button" class="fs-arrow fs-arrow--prev" aria-label="Scroll back" hidden>‹</button>
					<button type="button" class="fs-arrow fs-arrow--next" aria-label="Scroll forward" hidden>›</button>
					<?php if ( $term && ! is_wp_error( $term ) ) : ?><a class="fs-viewall" href="<?php echo esc_url( get_term_link( $term ) ); ?>">Shop all <?php echo esc_html( $term->name ); ?> <span aria-hidden="true">→</span></a><?php endif; ?>
				</div>
			</header>
			<ul class="fs-row">
				<?php $i = 0; foreach ( $items as $p ) echo fisha_product_card( $p, 10 + $i++ ); ?>
			</ul>
		</div>
	</section>
	<?php
	return ob_get_clean();
}, 10, 2 );

// Card titles are h3: give single-collection category grids an (invisible) h2 so headings don't skip a level.
add_action( 'fisha_before_grid', function ( $term ) {
	echo '<h2 class="screen-reader-text">' . esc_html( $term->name ) . ' — all pieces</h2>';
} );

// Checkout: Woo's section titles are h3 — add an h2 so the outline doesn't skip a level.
add_action( 'woocommerce_checkout_before_customer_details', function () { echo '<h2 class="screen-reader-text">Your details</h2>'; } );
