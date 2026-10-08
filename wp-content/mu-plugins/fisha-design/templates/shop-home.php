<?php
/**
 * Fisha main shop page (/shop-2/): hero, filter bar, one carousel ("shelf") per top-level category with a mix of
 * its products and a CTA to the category page. Filtering is client-side (shop.js) and mirrored in the URL.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$shop_url  = get_permalink( wc_get_page_id( 'shop' ) );
$top_slugs = apply_filters( 'fisha_shop_top_categories', array( 'wearables', 'collectibles', 'originals' ) );
$per_shelf = 16;

$shelves = array(); $types = array(); $all = array();
foreach ( $top_slugs as $slug ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( ! $term ) continue;
	$children = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false ) );
	$children = is_wp_error( $children ) ? array() : $children;
	usort( $children, fn( $a, $b ) => (int) get_term_meta( $a->term_id, 'order', true ) <=> (int) get_term_meta( $b->term_id, 'order', true ) );
	// "selected" products: round-robin across sub-categories so every type shows up early
	$pools = array();
	foreach ( $children as $c ) { $ps = fisha_cat_products( $c, $per_shelf ); if ( $ps ) { $pools[] = $ps; $types[ $c->slug ] = $c->name; } }
	if ( ! $pools ) $pools[] = fisha_cat_products( $term, $per_shelf );
	$picked = array(); $seen = array();
	for ( $i = 0; count( $picked ) < $per_shelf; $i++ ) {
		$added = false;
		foreach ( $pools as $pool ) {
			if ( isset( $pool[ $i ] ) && ! isset( $seen[ $pool[ $i ]->get_id() ] ) && count( $picked ) < $per_shelf ) {
				$picked[] = $pool[ $i ]; $seen[ $pool[ $i ]->get_id() ] = 1; $added = true;
			}
		}
		if ( ! $added ) break;
	}
	$count = 0; foreach ( fisha_cat_products( $term, 200 ) as $_ ) $count++;
	$shelves[] = array( 'term' => $term, 'products' => $picked, 'count' => $count );
	$all = array_merge( $all, $picked );
}
$total = array_sum( wp_list_pluck( $shelves, 'count' ) );

// sizes / prices that actually exist, for the filter options
$sizes = array();
foreach ( array( 'pa_size', 'pa_shoe-size' ) as $tax ) {
	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'orderby' => 'meta_value_num', 'meta_key' => 'order' ) );
	if ( ! is_wp_error( $terms ) ) foreach ( $terms as $t ) $sizes[] = $t->name;
}

// hero collage: one product photo from each of three different types
$hero = array();
foreach ( array( 'hoodies', 'tshirts', 'hats' ) as $s ) {
	$t = get_term_by( 'slug', $s, 'product_cat' );
	$ps = $t ? fisha_cat_products( $t, 8 ) : array();
	foreach ( $ps as $p ) { if ( $p->get_image_id() && ! get_post_meta( $p->get_id(), '_fisha_hero_skip', true ) ) { $hero[] = $p; break; } }
}

// FAQ (orders, tattoos, shipping, returns) — edit here or via the 'fisha_shop_faq' filter
$contact_url = home_url( '/contact/' );
$terms_url   = get_permalink( 85 );
$faq = apply_filters( 'fisha_shop_faq', array(
	'orders' => array( 'Orders', array(
		array( 'How do I place an order?', 'Pick a piece, choose your size or shoe size where there is one, and add it to your bag. When you are ready, open the bag and check out — you will get an order confirmation by email straight away.' ),
		array( 'When is my order confirmed?', 'Your order is confirmed once the payment is approved. If anything about stock, sizing or delivery needs a check, we will contact you by email before it ships.' ),
		array( 'Can I change or cancel my order?', 'Yes, as long as it has not shipped yet. <a href="' . esc_url( $contact_url . '?topic=Order' ) . '">Write to us</a> with your order number as soon as you can and we will update it.' ),
		array( 'Will my bag be saved if I leave the site?', 'Yes. Your bag stays saved on this device for up to two weeks, so you can keep browsing and come back to check out later.' ),
		array( 'Which payment methods do you accept?', 'All major credit cards through our secure checkout. Card details are handled by the payment provider and never stored by Fisha.' ),
	) ),
	'tattoos' => array( 'Tattoos', array(
		array( 'Can I get a Fisha tattoo?', 'Yes! Fisha flash designs and custom pieces can be tattooed. Have a look at the <a href="' . esc_url( home_url( '/tattoos/' ) ) . '">Fisha Tattood</a> gallery for ideas.' ),
		array( 'How do I book a tattoo?', 'Send a <a href="' . esc_url( $contact_url . '?topic=Tattoo+enquiry' ) . '">tattoo enquiry</a> with the design you like, the placement and the rough size. We will reply with availability and a quote.' ),
		array( 'Can I ask for a custom Fisha design?', 'Of course. Tell us what your Fisha should be doing, where it will live on your body and any colours you have in mind, and Fisha will draw a one-off design for you.' ),
		array( 'Can I take a Fisha artwork to another tattoo artist?', 'Fisha artwork belongs to Fisha and may not be reused without written permission. Get in touch first and we will find a way to make it happen.' ),
	) ),
	'shipping' => array( 'Shipping', array(
		array( 'Where do you ship?', 'We ship across Israel. For international orders, <a href="' . esc_url( $contact_url . '?topic=Shipping' ) . '">get in touch</a> and we will check the options and cost for your country.' ),
		array( 'How long does delivery take?', 'Wearables and collectibles usually leave the studio within a few business days. Original artworks are packed by hand and may take a little longer. Delivery times are shown at checkout.' ),
		array( 'How much does shipping cost?', 'The shipping cost is calculated at checkout based on your address and the delivery method you choose, before you pay.' ),
		array( 'How do I track my order?', 'Once your order ships you will get an email with the tracking details.' ),
	) ),
	'returns' => array( 'Returns', array(
		array( 'Can I return or exchange an item?', 'Yes. Unused, undamaged items in their original condition can be returned or exchanged within the period stated in our <a href="' . esc_url( $terms_url ) . '">Terms &amp; Conditions</a>.' ),
		array( 'The size is not right — what now?', 'No problem. <a href="' . esc_url( $contact_url . '?topic=Return' ) . '">Write to us</a> with your order number and the size you need, and we will arrange an exchange while stock lasts.' ),
		array( 'My item arrived damaged.', 'We are sorry! Send us a photo of the item and the packaging within a few days of delivery and we will replace or refund it.' ),
		array( 'Are original artworks and custom pieces returnable?', 'One-of-a-kind originals and pieces made especially for you cannot always be returned. Check the details on the product page or ask us before you order.' ),
	) ),
) );
$faq_ld = array( '@type' => 'FAQPage', 'mainEntity' => array() );
foreach ( $faq as $g ) foreach ( $g[1] as $qa ) $faq_ld['mainEntity'][] = array( '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $qa[1] ) ) );

$ld = array(
	'@context' => 'https://schema.org',
	'@graph'   => array(
		array( '@type' => 'BreadcrumbList', 'itemListElement' => array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Shop', 'item' => $shop_url ) ) ),
		array(
			'@type' => 'CollectionPage', 'name' => 'Fisha Shop', 'description' => FISHA_SHOP_SEO_DESC, 'url' => $shop_url,
			'hasPart' => array_map( fn( $s ) => array( '@type' => 'CollectionPage', 'name' => $s['term']->name, 'url' => get_term_link( $s['term'] ) ), $shelves ),
			'mainEntity' => array( '@type' => 'ItemList', 'numberOfItems' => count( $all ),
				'itemListElement' => array_map( fn( $p, $i ) => array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => get_permalink( $p->get_id() ), 'name' => $p->get_name() ), $all, array_keys( $all ) ) ),
		),
		$faq_ld,
	),
);

// render header/footer before wp_head (block assets + import map)
$fisha_header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
$fisha_footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php echo $fisha_header; // phpcs:ignore ?>

<main id="wp--skip-link--target" class="fisha-shop fisha-shop--home">
	<section class="fs-hero fs-hero--home" aria-labelledby="fs-title">
		<div class="fs-hero__text">
			<p class="fs-eyebrow">fisha.shop</p>
			<h1 id="fs-title" class="fs-title">The Fisha <span>Shop</span></h1>
			<p class="fs-lead">Hand-drawn Fisha on things you wear, keep and hang on the wall — T-shirts, hoodies, caps and socks, collectibles and original artworks.</p>
			<ul class="fs-cats">
				<?php foreach ( $shelves as $s ) : ?>
				<li><a href="<?php echo esc_url( get_term_link( $s['term'] ) ); ?>">
					<span class="fs-cats__name"><?php echo esc_html( $s['term']->name ); ?></span>
					<span class="fs-cats__count"><?php echo $s['count'] ? esc_html( sprintf( _n( '%d piece', '%d pieces', $s['count'] ), $s['count'] ) ) : 'Coming soon'; ?></span>
					<span class="fs-cats__arrow" aria-hidden="true">→</span>
				</a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php if ( $hero ) : ?>
		<div class="fs-hero__stack" aria-hidden="true">
			<?php foreach ( array_slice( $hero, 0, 3 ) as $k => $p ) : ?>
				<figure class="fs-polaroid fs-polaroid--<?php echo (int) $k + 1; ?>">
					<span class="fs-photo__tape"></span>
					<?php echo wp_get_attachment_image( $p->get_image_id(), 'woocommerce_single', false, array( 'loading' => 'eager', 'alt' => '', 'sizes' => '(max-width: 900px) 45vw, 300px' ) ); ?>
				</figure>
			<?php endforeach; ?>
			<img class="fs-hero__fish" src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" width="120" height="85">
		</div>
		<?php endif; ?>
	</section>

	<div class="fs-shopwrap"><!-- bounds the sticky filter bar to the product area -->
	<form class="fs-filters" role="search" aria-label="Filter products" onsubmit="return false">
		<div class="fs-filters__inner">
			<div class="fs-filters__group fs-filters__cats" role="group" aria-label="Category">
				<button type="button" class="fs-chip is-active" data-filter="cat" data-value="" aria-pressed="true">All <span><?php echo (int) $total; ?></span></button>
				<?php foreach ( $shelves as $s ) : ?>
					<button type="button" class="fs-chip" data-filter="cat" data-value="<?php echo esc_attr( $s['term']->slug ); ?>" aria-pressed="false"><?php echo esc_html( $s['term']->name ); ?> <span><?php echo (int) $s['count']; ?></span></button>
				<?php endforeach; ?>
			</div>
			<div class="fs-filters__group fs-filters__selects">
				<label class="fs-select"><span class="screen-reader-text">Type</span>
					<select data-filter="type">
						<option value="">All types</option>
						<?php foreach ( $types as $slug => $name ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?>
					</select>
				</label>
				<?php if ( $sizes ) : ?>
				<label class="fs-select"><span class="screen-reader-text">Size</span>
					<select data-filter="size">
						<option value="">Any size</option>
						<?php foreach ( $sizes as $sz ) : ?><option value="<?php echo esc_attr( $sz ); ?>"><?php echo esc_html( preg_match( '/\d/', $sz ) ? 'Shoe size ' . $sz : 'Size ' . $sz ); ?></option><?php endforeach; ?>
					</select>
				</label>
				<?php endif; ?>
				<label class="fs-select"><span class="screen-reader-text">Price</span>
					<select data-filter="price">
						<option value="">Any price</option>
						<option value="0-100">Under ₪100</option>
						<option value="100-200">₪100 – ₪200</option>
						<option value="200-">Over ₪200</option>
					</select>
				</label>
			</div>
			<p class="fs-filters__status" aria-live="polite"><span class="fs-filters__count"></span> <button type="button" class="fs-filters__clear" hidden>Clear filters</button></p>
		</div>
	</form>

	<div class="fs-body" id="fs-top">
		<?php foreach ( $shelves as $s ) : $t = $s['term']; $sid = 'cat-' . $t->slug; ?>
		<section class="fs-shelf" id="<?php echo esc_attr( $sid ); ?>" data-cat="<?php echo esc_attr( $t->slug ); ?>" aria-labelledby="<?php echo esc_attr( $sid ); ?>-h">
			<header class="fs-shelf__head">
				<div>
					<h2 id="<?php echo esc_attr( $sid ); ?>-h"><?php echo esc_html( $t->name ); ?></h2>
					<?php $d = fisha_cat_meta( $t, 'fisha_lead', wp_strip_all_tags( $t->description ) ); if ( $d ) : ?><p><?php echo esc_html( wp_trim_words( $d, 26 ) ); ?></p><?php endif; ?>
				</div>
				<div class="fs-shelf__tools">
					<button type="button" class="fs-arrow fs-arrow--prev" aria-label="Scroll back" hidden>‹</button>
					<button type="button" class="fs-arrow fs-arrow--next" aria-label="Scroll forward" hidden>›</button>
					<a class="fs-viewall" href="<?php echo esc_url( get_term_link( $t ) ); ?>">Shop all <?php echo esc_html( $t->name ); ?> <span aria-hidden="true">→</span></a>
				</div>
			</header>
			<?php if ( $s['products'] ) : ?>
				<ul class="fs-row">
					<?php foreach ( $s['products'] as $i => $p ) echo fisha_product_card( $p, $i ); ?>
					<?php if ( $s['count'] > count( $s['products'] ) ) : ?>
					<li class="fs-card fs-card--more" data-cats="<?php echo esc_attr( $t->slug ); ?>" data-more="1">
						<a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><span>See all <?php echo (int) $s['count']; ?></span><strong><?php echo esc_html( $t->name ); ?> →</strong></a>
					</li>
					<?php endif; ?>
				</ul>
			<?php else : ?>
				<div class="fs-soon">
					<img src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" aria-hidden="true" width="90" height="64">
					<p><strong><?php echo esc_html( $t->name ); ?> are on their way.</strong> Fisha is still drawing this collection — take a peek at the category page.</p>
				</div>
			<?php endif; ?>
		</section>
		<?php endforeach; ?>
		<div class="fs-noresults" hidden>
			<img src="<?php echo esc_url( content_url( 'uploads/fisha-contact/loop-fisha.webp' ) ); ?>" alt="" aria-hidden="true" width="160" height="144" loading="lazy">
			<h2>Nothing swims here yet</h2>
			<p>No pieces match these filters. Try another size or price, or clear the filters.</p>
			<button type="button" class="fisha-cta fs-filters__clear2">Clear filters</button>
		</div>
	</div>
	</div>

	<section class="fs-about" aria-labelledby="fs-about-h">
		<h2 id="fs-about-h">About the Fisha shop</h2>
		<p>Every Fisha piece starts as an ink drawing in the sketchbook. From there the little fish swims onto soft cotton tees and hoodies, embroidered caps and cosy beanies, everyday socks, collectible objects and one-of-a-kind original artworks.</p>
		<p>Pick a category above, or filter by type, size and price to find your Fisha.</p>
	</section>

	<section class="fs-faq" id="faq" aria-labelledby="fs-faq-h">
		<div class="fs-faq__intro">
			<p class="fs-eyebrow">Questions</p>
			<h2 id="fs-faq-h">Good to know</h2>
			<p>Orders, tattoos, shipping and returns — the things people ask us most.</p>
			<div class="fs-faq__tabs" role="group" aria-label="FAQ topic">
				<button type="button" class="fs-chip is-active" data-faq="" aria-pressed="true">All</button>
				<?php foreach ( $faq as $key => $g ) : ?><button type="button" class="fs-chip" data-faq="<?php echo esc_attr( $key ); ?>" aria-pressed="false"><?php echo esc_html( $g[0] ); ?></button><?php endforeach; ?>
			</div>
			<div class="fs-faq__help">
				<img src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" aria-hidden="true" width="70" height="50" loading="lazy">
				<p><strong>Still wondering?</strong> We are happy to help.</p>
				<a class="fisha-cta" href="<?php echo esc_url( $contact_url ); ?>">Contact us <span aria-hidden="true">→</span></a>
			</div>
		</div>
		<div class="fs-faq__list">
			<?php foreach ( $faq as $key => $g ) : ?>
			<div class="fs-faq__group" data-faq-group="<?php echo esc_attr( $key ); ?>">
				<h3><?php echo esc_html( $g[0] ); ?></h3>
				<?php foreach ( $g[1] as $qa ) : ?>
				<details class="fs-faq__item">
					<summary><?php echo esc_html( $qa[0] ); ?><span class="fs-faq__icon" aria-hidden="true"></span></summary>
					<div class="fs-faq__a"><p><?php echo wp_kses_post( $qa[1] ); ?></p></div>
				</details>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</section>

	<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</main>

<?php echo $fisha_footer; // phpcs:ignore ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
