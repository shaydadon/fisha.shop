<?php
/**
 * Fisha shop — product category pages.
 * Every product-category archive is rendered by fisha-design/templates/product-cat.php (real block-theme header +
 * footer, Fisha layout). Parent categories show one "shelf" per sub-category (grid on desktop, swipe row on mobile);
 * leaf categories show a full grid.
 * Per-category term meta (all optional): fisha_h1, fisha_seo_title, fisha_eyebrow, fisha_lead, fisha_hero_ids (CSV attachment ids).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_shop_cat() {
	return function_exists( 'is_product_category' ) && is_product_category();
}
/** The main shop page (/shop-2/) — not a search or a filtered/paged product query. */
function fisha_is_shop_home() {
	return function_exists( 'is_shop' ) && is_shop() && ! is_search() && ! get_query_var( 'paged' );
}

// Take over the category + main shop templates (after WooLentor's 999).
add_filter( 'template_include', function ( $t ) {
	if ( fisha_is_shop_home() ) return FISHA_DESIGN_DIR . 'templates/shop-home.php';
	return fisha_is_shop_cat() ? FISHA_DESIGN_DIR . 'templates/product-cat.php' : $t;
}, 10001 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fisha_is_shop_cat() && ! fisha_is_shop_home() ) return;
	wp_enqueue_style( 'fisha-shop', FISHA_DESIGN_URL . 'shop.css', array( 'fisha-design' ), fisha_asset_ver( 'shop.css' ) );
	wp_enqueue_script( 'fisha-shop', FISHA_DESIGN_URL . 'shop.js', array(), fisha_asset_ver( 'shop.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_enqueue_script( 'wc-add-to-cart' );
}, 20 );

add_filter( 'body_class', function ( $c ) {
	if ( fisha_is_shop_cat() || fisha_is_shop_home() ) $c[] = 'fisha-shop-cat';
	if ( fisha_is_shop_home() ) $c[] = 'fisha-shop-home';
	return $c;
} );

// Main shop page: SEO title + description (skipped when an SEO plugin is active)
define( 'FISHA_SHOP_SEO_TITLE', 'Fisha Shop — Hand-Drawn Wearables, Collectibles & Original Art' );
define( 'FISHA_SHOP_SEO_DESC', 'Shop Fisha: T-shirts, hoodies, caps and socks carrying the hand-drawn fish, plus collectibles and original artworks — a little light, in every form.' );
add_filter( 'pre_get_document_title', function ( $title ) {
	return fisha_is_shop_home() ? FISHA_SHOP_SEO_TITLE . ' | ' . get_bloginfo( 'name' ) : $title;
}, 20 );
add_action( 'wp_head', function () {
	if ( ! fisha_is_shop_home() || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) return;
	echo '<meta name="description" content="' . esc_attr( FISHA_SHOP_SEO_DESC ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( FISHA_SHOP_SEO_TITLE ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( FISHA_SHOP_SEO_DESC ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">' . "\n";
}, 2 );

function fisha_cat_meta( $term, $key, $default = '' ) {
	$v = get_term_meta( $term->term_id, $key, true );
	return '' !== $v && null !== $v ? $v : $default;
}

// <title>
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( ! fisha_is_shop_cat() ) return $title;
	$t = get_queried_object();
	$seo = fisha_cat_meta( $t, 'fisha_seo_title' );
	return $seo ? $seo . ' | ' . get_bloginfo( 'name' ) : $title;
}, 20 );

// meta description + canonical-ish social tags (skipped when an SEO plugin is active)
add_action( 'wp_head', function () {
	if ( ! fisha_is_shop_cat() || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) return;
	$t    = get_queried_object();
	$desc = wp_strip_all_tags( fisha_cat_meta( $t, 'fisha_lead', term_description( $t ) ) );
	$desc = trim( preg_replace( '/\s+/', ' ', $desc ) );
	if ( ! $desc ) return;
	$desc = mb_substr( $desc, 0, 158 );
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( get_term_link( $t ) ) . '">' . "\n";
	$hero = fisha_cat_hero_ids( $t );
	if ( $hero ) echo '<meta property="og:image" content="' . esc_url( wp_get_attachment_image_url( $hero[0], 'large' ) ) . '">' . "\n";
}, 2 );

function fisha_cat_hero_ids( $term ) {
	$ids = array_filter( array_map( 'absint', explode( ',', (string) fisha_cat_meta( $term, 'fisha_hero_ids' ) ) ) );
	if ( ! $ids && ( $thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) ) ) $ids = array( $thumb );
	if ( ! $ids ) { // fall back to the first products' images
		foreach ( fisha_cat_products( $term, 2 ) as $p ) if ( $p->get_image_id() ) $ids[] = $p->get_image_id();
	}
	return array_values( $ids );
}

/** Published, visible products of a category (incl. its children). */
function fisha_cat_products( $term, $limit = 48 ) {
	return wc_get_products( array(
		'status'     => 'publish',
		'limit'      => $limit,
		'category'   => array( $term->slug ),
		'visibility' => 'catalog',
		'orderby'    => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
	) );
}

function fisha_product_card( $p, $i = 0 ) {
	$link   = get_permalink( $p->get_id() );
	$img_id = $p->get_image_id();
	$alt_id = $p->get_gallery_image_ids()[0] ?? 0;
	$bg     = get_post_meta( $p->get_id(), '_fisha_card_bg', true ) ?: '#efe8dc';
	$colour = get_post_meta( $p->get_id(), '_fisha_colour', true );
	$name   = $p->get_name();
	$load   = $i < 4 ? 'eager' : 'lazy';
	// data for the shop filters: every category slug incl. parents, price, sizes
	$cats = array();
	foreach ( $p->get_category_ids() as $cid ) {
		$cats[] = get_term( $cid, 'product_cat' )->slug;
		foreach ( get_ancestors( $cid, 'product_cat' ) as $aid ) $cats[] = get_term( $aid, 'product_cat' )->slug;
	}
	$sizes = array();
	foreach ( array( 'pa_size', 'pa_shoe-size' ) as $tax ) {
		$terms = wc_get_product_terms( $p->get_id(), $tax, array( 'fields' => 'names' ) );
		if ( $terms && ! is_wp_error( $terms ) ) $sizes = array_merge( $sizes, $terms );
	}
	ob_start(); ?>
	<li class="fs-card" data-cats="<?php echo esc_attr( implode( ' ', array_unique( $cats ) ) ); ?>" data-price="<?php echo esc_attr( (float) $p->get_price() ); ?>" data-sizes="<?php echo esc_attr( implode( '|', $sizes ) ); ?>">
		<a class="fs-card__link" href="<?php echo esc_url( $link ); ?>">
			<span class="fs-card__media<?php echo $alt_id ? ' has-alt' : ''; ?>" style="--card-bg:<?php echo esc_attr( $bg ); ?>">
				<?php echo $img_id ? wp_get_attachment_image( $img_id, 'woocommerce_thumbnail', false, array( 'class' => 'fs-card__img', 'loading' => $load, 'alt' => $name ) ) : wc_placeholder_img( 'woocommerce_thumbnail', array( 'class' => 'fs-card__img' ) ); ?>
				<?php if ( $alt_id ) echo wp_get_attachment_image( $alt_id, 'woocommerce_thumbnail', false, array( 'class' => 'fs-card__img fs-card__img--alt', 'loading' => 'lazy', 'alt' => '', 'aria-hidden' => 'true' ) ); ?>
				<?php if ( $p->is_on_sale() ) : ?><span class="fs-card__badge">Sale</span><?php endif; ?>
				<?php if ( ! $p->is_in_stock() ) : ?><span class="fs-card__badge fs-card__badge--out">Sold out</span><?php endif; ?>
			</span>
			<span class="fs-card__body">
				<h3 class="fs-card__title"><?php echo esc_html( $name ); ?></h3>
				<?php if ( $colour ) : ?><span class="fs-card__meta"><?php echo esc_html( $colour ); ?></span><?php endif; ?>
				<span class="fs-card__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
			</span>
		</a>
		<?php if ( $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock() ) : ?>
			<a href="<?php echo esc_url( $p->add_to_cart_url() ); ?>" data-quantity="1" data-product_id="<?php echo (int) $p->get_id(); ?>" data-product_sku="<?php echo esc_attr( $p->get_sku() ); ?>" class="fs-card__add add_to_cart_button ajax_add_to_cart" rel="nofollow" aria-label="<?php echo esc_attr( sprintf( 'Add %s to your bag', $name ) ); ?>">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 11.5v5M9.5 14h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
			</a>
		<?php else : ?>
			<a href="<?php echo esc_url( $link ); ?>" class="fs-card__add fs-card__add--opts" aria-label="<?php echo esc_attr( sprintf( 'Choose options for %s', $name ) ); ?>">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</a>
		<?php endif; ?>
	</li>
	<?php
	return ob_get_clean();
}
