<?php
/**
 * Home page — "Pick your Fisha": the three shop categories as taped photos.
 *   [fisha_home_cats wearables="<img id>" collectibles="<img id>" originals="<img id>"]
 * Counts and prices come from the shop, so they stay right as pieces are added or sold.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_home_cat_list() {
	return apply_filters( 'fisha_home_cats', array(
		'wearables'    => 'T-shirts, hoodies, caps and socks carrying the hand-drawn fish.',
		'collectibles' => 'Hand-painted Clippers and small objects, each one made once.',
		'originals'    => 'Ink drawings and paintings, signed and framed by hand.',
	) );
}

/** "12 pieces · from ₪89" — in-stock items and the lowest price in a category (incl. sub-categories). */
function fisha_home_cat_facts( $term ) {
	$n = 0; $min = null;
	foreach ( fisha_cat_products( $term, 200 ) as $p ) {
		if ( ! $p->is_in_stock() ) continue;
		$n++;
		$price = (float) $p->get_price();
		if ( $price > 0 && ( null === $min || $price < $min ) ) $min = $price;
	}
	if ( ! $n ) return 'New pieces coming soon';
	$parts = array();
	if ( 'originals' === $term->slug ) {
		$parts[] = sprintf( _n( '%d available', '%d available', $n ), $n );
		$parts[] = 'one of a kind';
	} else {
		$parts[] = sprintf( _n( '%d piece', '%d pieces', $n ), $n );
		if ( null !== $min ) $parts[] = 'from ' . wp_strip_all_tags( wc_price( $min, array( 'decimals' => floor( $min ) == $min ? 0 : 2 ) ) );
	}
	return implode( ' · ', $parts );
}

function fisha_home_assets() {
	wp_enqueue_style( 'fisha-home', FISHA_DESIGN_URL . 'home.css', array(), fisha_asset_ver( 'home.css' ) );
}
add_action( 'wp_enqueue_scripts', function () { if ( is_front_page() ) fisha_home_assets(); } );

add_shortcode( 'fisha_home_cats', function ( $atts ) {
	$a = shortcode_atts( array(
		'eyebrow'      => 'The shop',
		'title'        => 'Pick your Fisha',
		'text'         => 'Three ways to take the little fish home.',
		'wearables'    => '',
		'collectibles' => '',
		'originals'    => '',
		'all'          => '/shop-2/',
	), $atts, 'fisha_home_cats' );
	if ( ! function_exists( 'fisha_cat_products' ) ) return '';

	fisha_home_assets();

	$cards = array();
	foreach ( fisha_home_cat_list() as $slug => $line ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $t ) continue;
		$img = absint( $a[ $slug ] ?? 0 );
		if ( ! $img ) { $ids = fisha_cat_hero_ids( $t ); $img = $ids ? (int) $ids[0] : 0; }
		$cards[] = array( 'term' => $t, 'line' => $line, 'img' => $img, 'facts' => fisha_home_cat_facts( $t ) );
	}
	if ( ! $cards ) return '';

	ob_start(); ?>
<section class="fh-cats" aria-labelledby="fh-cats-h">
	<header class="fh-cats__head">
		<p class="fh-cats__eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
		<h2 id="fh-cats-h" class="fh-cats__title"><?php echo esc_html( $a['title'] ); ?></h2>
		<p class="fh-cats__text"><?php echo esc_html( $a['text'] ); ?></p>
	</header>
	<ul class="fh-cats__row">
		<?php foreach ( $cards as $i => $c ) : $t = $c['term']; ?>
		<li class="fh-cat fh-cat--<?php echo (int) $i % 3 + 1; ?>">
			<a class="fh-cat__link" href="<?php echo esc_url( get_term_link( $t ) ); ?>">
				<span class="fh-cat__tape" aria-hidden="true"></span>
				<span class="fh-cat__photo">
					<?php echo $c['img'] ? wp_get_attachment_image( $c['img'], 'medium_large', false, array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'sizes' => '(max-width: 760px) 78vw, 380px' ) ) : ''; ?>
				</span>
				<span class="fh-cat__body">
					<h3 class="fh-cat__name"><?php echo esc_html( $t->name ); ?></h3>
					<span class="fh-cat__line"><?php echo esc_html( $c['line'] ); ?></span>
					<span class="fh-cat__foot">
						<span class="fh-cat__facts"><?php echo esc_html( $c['facts'] ); ?></span>
						<span class="fh-cat__go" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
					</span>
				</span>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
	<p class="fh-cats__all"><a href="<?php echo esc_url( home_url( $a['all'] ) ); ?>">Or browse everything <span aria-hidden="true">→</span></a></p>
</section>
<?php
	return ob_get_clean();
} );
