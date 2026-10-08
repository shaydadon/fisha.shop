<?php
/**
 * Fisha product-category template (block theme header/footer + Fisha shop layout).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$term     = get_queried_object();
$children = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false, 'orderby' => 'term_order' ) );
$children = is_wp_error( $children ) ? array() : $children;
$by_order = function ( $a, $b ) { return (int) get_term_meta( $a->term_id, 'order', true ) <=> (int) get_term_meta( $b->term_id, 'order', true ); };
usort( $children, $by_order );
$parent   = $term->parent ? get_term( $term->parent, 'product_cat' ) : null;
$shop_url = get_permalink( wc_get_page_id( 'shop' ) );

$h1      = fisha_cat_meta( $term, 'fisha_h1', $term->name );
$eyebrow = fisha_cat_meta( $term, 'fisha_eyebrow', $parent ? $parent->name : 'Fisha Shop' );
$lead    = fisha_cat_meta( $term, 'fisha_lead', wp_strip_all_tags( term_description( $term ) ) );
$hero    = fisha_cat_hero_ids( $term );

// Shelves (parent category) or one grid (leaf category)
$shelves = array();
if ( $children ) {
	foreach ( $children as $c ) $shelves[] = array( 'term' => $c, 'products' => fisha_cat_products( $c, count( $children ) === 1 ? 24 : 8 ) );
	// products sitting directly in the parent (not in any child)
	$direct = array_filter( fisha_cat_products( $term, 24 ), function ( $p ) use ( $children ) {
		return ! array_intersect( $p->get_category_ids(), wp_list_pluck( $children, 'term_id' ) );
	} );
	if ( $direct ) $shelves[] = array( 'term' => null, 'products' => array_values( $direct ) );
} else {
	$grid = fisha_cat_products( $term, 48 );
}

// Chips: sub-categories of a parent, or siblings of a child
$chip_terms = $children ? $children : ( $parent ? get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent->term_id, 'hide_empty' => false ) ) : array() );
if ( ! $children && $chip_terms ) usort( $chip_terms, $by_order );

// JSON-LD: breadcrumb + collection with its products
$crumbs = array( array( 'Shop', $shop_url ) );
if ( $parent ) $crumbs[] = array( $parent->name, get_term_link( $parent ) );
$crumbs[] = array( $term->name, get_term_link( $term ) );
$all_products = $children ? array_merge( ...array_map( fn( $s ) => $s['products'], $shelves ?: array( array( 'products' => array() ) ) ) ) : $grid;
$ld = array(
	'@context' => 'https://schema.org',
	'@graph'   => array(
		array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array_map( fn( $c, $i ) => array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] ), $crumbs, array_keys( $crumbs ) ),
		),
		array(
			'@type'       => 'CollectionPage',
			'name'        => $h1,
			'description' => $lead,
			'url'         => get_term_link( $term ),
			'mainEntity'  => array(
				'@type'           => 'ItemList',
				'numberOfItems'   => count( $all_products ),
				'itemListElement' => array_map( fn( $p, $i ) => array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => get_permalink( $p->get_id() ), 'name' => $p->get_name() ), $all_products, array_keys( $all_products ) ),
			),
		),
	),
);
// Render the block header/footer BEFORE wp_head(), like block templates do, so their styles,
// script modules and the import map (navigation overlay, mini-cart) are registered in time.
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

<main id="wp--skip-link--target" class="fisha-shop">
	<section class="fs-hero" aria-labelledby="fs-title">
		<div class="fs-hero__text">
			<nav class="fs-crumbs" aria-label="Breadcrumb">
				<ol>
					<?php foreach ( $crumbs as $k => $c ) : $last = $k === count( $crumbs ) - 1; ?>
						<li><?php if ( $last ) : ?><span aria-current="page"><?php echo esc_html( $c[0] ); ?></span><?php else : ?><a href="<?php echo esc_url( $c[1] ); ?>"><?php echo esc_html( $c[0] ); ?></a><?php endif; ?></li>
					<?php endforeach; ?>
				</ol>
			</nav>
			<p class="fs-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<h1 id="fs-title" class="fs-title"><?php echo wp_kses( $h1, array( 'span' => array() ) ); ?></h1>
			<?php if ( $lead ) : ?><p class="fs-lead"><?php echo esc_html( $lead ); ?></p><?php endif; ?>
			<?php if ( $chip_terms ) : ?>
			<p class="fs-hero__count"><?php
				$n = count( $all_products );
				echo esc_html( sprintf( _n( '%d piece', '%d pieces', $n ), $n ) . ' · ' . sprintf( _n( '%d collection', '%d collections', count( $chip_terms ) ), count( $chip_terms ) ) );
			?></p>
			<?php endif; ?>
		</div>
		<?php if ( $hero ) : ?>
		<div class="fs-hero__media<?php echo count( $hero ) > 1 ? ' is-duo' : ''; ?>">
			<?php foreach ( array_slice( $hero, 0, 2 ) as $k => $id ) : ?>
				<figure class="fs-photo fs-photo--<?php echo $k ? 'b' : 'a'; ?>">
					<span class="fs-photo__tape" aria-hidden="true"></span>
					<?php echo wp_get_attachment_image( $id, 'large', false, array( 'loading' => 'eager', 'fetchpriority' => $k ? 'auto' : 'high', 'sizes' => '(max-width: 900px) 70vw, 420px', 'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: ( $term->name . ' by Fisha' ) ) ); ?>
				</figure>
			<?php endforeach; ?>
			<img class="fs-hero__fish" src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" aria-hidden="true" width="120" height="85">
		</div>
		<?php endif; ?>
	</section>

	<?php if ( $chip_terms ) : ?>
	<nav class="fs-chips" aria-label="<?php echo esc_attr( ( $parent ? $parent->name : $term->name ) . ' collections' ); ?>">
		<ul>
			<li><a class="fs-chip<?php echo $children ? ' is-active' : ''; ?>" href="<?php echo esc_url( $children ? '#fs-top' : get_term_link( $parent ) ); ?>"<?php echo $children ? ' aria-current="page"' : ''; ?>>All <?php echo esc_html( $children ? $term->name : $parent->name ); ?></a></li>
			<?php foreach ( $chip_terms as $c ) : $active = $c->term_id === $term->term_id; ?>
				<li><a class="fs-chip<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $children ? '#cat-' . $c->slug : get_term_link( $c ) ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $c->name ); ?> <span><?php echo (int) $c->count; ?></span></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php endif; ?>

	<div class="fs-body" id="fs-top">
	<?php if ( $children ) : ?>
		<?php foreach ( $shelves as $s ) :
			if ( ! $s['products'] ) continue;
			$c = $s['term'];
			$sid = $c ? 'cat-' . $c->slug : 'cat-more';
		?>
		<section class="fs-shelf" id="<?php echo esc_attr( $sid ); ?>" aria-labelledby="<?php echo esc_attr( $sid ); ?>-h">
			<header class="fs-shelf__head">
				<div>
					<h2 id="<?php echo esc_attr( $sid ); ?>-h"><?php echo esc_html( $c ? $c->name : 'More ' . $term->name ); ?></h2>
					<?php if ( $c && $c->description ) : ?><p><?php echo esc_html( wp_strip_all_tags( $c->description ) ); ?></p><?php endif; ?>
				</div>
				<div class="fs-shelf__tools">
					<button type="button" class="fs-arrow fs-arrow--prev" aria-label="Scroll back" hidden>‹</button>
					<button type="button" class="fs-arrow fs-arrow--next" aria-label="Scroll forward" hidden>›</button>
					<?php if ( $c ) : ?><a class="fs-viewall" href="<?php echo esc_url( get_term_link( $c ) ); ?>">View all <?php echo esc_html( $c->name ); ?> <span aria-hidden="true">→</span></a><?php endif; ?>
				</div>
			</header>
			<ul class="fs-row">
				<?php foreach ( $s['products'] as $i => $p ) echo fisha_product_card( $p, $i ); ?>
			</ul>
		</section>
		<?php endforeach; ?>
	<?php elseif ( $grid ) : ?>
		<?php do_action( 'fisha_before_grid', $term ); ?>
		<ul class="fs-grid">
			<?php foreach ( $grid as $i => $p ) echo fisha_product_card( $p, $i ); ?>
		</ul>
	<?php else : ?>
		<div class="fs-empty">
			<img src="<?php echo esc_url( content_url( 'uploads/fisha-contact/loop-fisha.webp' ) ); ?>" alt="" aria-hidden="true" width="200" height="180" loading="lazy">
			<h2>Fresh pieces are on their way</h2>
			<p>Fisha is still drawing this collection. In the meantime, have a look around the rest of the shop.</p>
			<?php echo do_shortcode( '[fisha_cta text="Back to the shop" url="/shop-2/"]' ); ?>
		</div>
	<?php endif; ?>
	</div>

	<?php
	$happy = array_filter( array_map( 'absint', explode( ',', (string) fisha_cat_meta( $term, 'fisha_happy_ids' ) ) ) );
	if ( $happy ) : ?>
	<section class="fs-happy" aria-labelledby="fs-happy-h">
		<div class="fs-happy__head">
			<p class="fs-eyebrow">Sold · now at home</p>
			<h2 id="fs-happy-h">Happy clients</h2>
			<p>Originals that already found their people. Every Fisha is one of a kind &mdash; when a piece is sold, it moves to this wall.</p>
		</div>
		<ul class="fs-happy__grid">
			<?php foreach ( $happy as $k => $hid ) : $cap = wp_get_attachment_caption( $hid ); ?>
			<li class="fs-happy__item fs-happy__item--<?php echo (int) $k % 3 + 1; ?>">
				<figure>
					<span class="fs-photo__tape" aria-hidden="true"></span>
					<?php echo wp_get_attachment_image( $hid, 'large', false, array( 'loading' => 'lazy', 'sizes' => '(max-width: 700px) 80vw, 360px' ) ); ?>
					<?php if ( $cap ) : ?><figcaption><?php echo esc_html( $cap ); ?></figcaption><?php endif; ?>
					<span class="fs-happy__stamp" aria-hidden="true">Sold</span>
				</figure>
			</li>
			<?php endforeach; ?>
		</ul>
		<div class="fs-happy__cta">
			<p>Want a Fisha made just for you?</p>
			<?php echo do_shortcode( '[fisha_cta text="Ask about a commission" url="/contact/?topic=Collab+or+commission"]' ); ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( trim( (string) $term->description ) && fisha_cat_meta( $term, 'fisha_lead' ) ) : ?>
	<section class="fs-about" aria-labelledby="fs-about-h">
		<h2 id="fs-about-h">About <?php echo esc_html( $term->name ); ?></h2>
		<?php echo wp_kses_post( wpautop( $term->description ) ); ?>
	</section>
	<?php endif; ?>

	<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</main>

<?php echo $fisha_footer; // phpcs:ignore ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
