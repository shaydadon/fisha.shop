<?php
/**
 * Which product categories the site displays (spec docs/superpowers/specs/2026-10-13-fisha-studio-phase-3-categories-design.md).
 * A category is displayed (header Shop menu, footer, Home cards, Shop page, its parent's shelves) only when it is published
 * (no fisha_studio_hidden), switched on (fisha_shown = 1, and so is every parent) and has a published, catalog-visible
 * product (its sub-categories count, sold-out products count).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_CAT_SHOWN', 'fisha_shown' );
define( 'FISHA_CAT_UNPUBLISHED', 'fisha_studio_hidden' );
define( 'FISHA_CAT_OLD_PATHS', 'fisha_old_paths' );
define( 'FISHA_CAT_VIS_DB', 1 );

/** Created in the Studio and not published yet. */
function fisha_cat_is_unpublished( WP_Term $t ): bool {
	return '1' === (string) get_term_meta( $t->term_id, FISHA_CAT_UNPUBLISHED, true );
}

/** Published and switched on, and so is every parent (products are not checked). */
function fisha_cat_is_enabled( WP_Term $t ): bool {
	for ( $depth = 0; $depth < 20; $depth++ ) {
		if ( fisha_cat_is_unpublished( $t ) || '1' !== (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ) ) return false;
		if ( ! $t->parent ) return true;
		$t = get_term( (int) $t->parent, 'product_cat' );
		if ( ! $t instanceof WP_Term ) return false;
	}
	return false;
}

/** Whether the category, with its sub-categories, has a published, catalog-visible product (sold-out ones count). */
function fisha_cat_has_products( WP_Term $t ): bool {
	$cache = &$GLOBALS['fisha_cat_products_cache'];
	if ( ! is_array( $cache ) ) $cache = array();
	if ( ! isset( $cache[ $t->term_id ] ) ) {
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids', 'visibility' => 'catalog', 'category' => array( $t->slug ) ) );
		$cache[ $t->term_id ] = ! empty( $ids );
	}
	return $cache[ $t->term_id ];
}

function fisha_cat_is_displayed( WP_Term $t ): bool {
	return fisha_cat_is_enabled( $t ) && fisha_cat_has_products( $t );
}

/** Forgets what this request learned about categories (after the Studio changes one, and in tests). */
function fisha_cat_cache_reset(): void {
	$GLOBALS['fisha_cat_products_cache']    = array();
	$GLOBALS['fisha_cat_unpublished_cache'] = null;
}

/** The owner's order (term meta "order"), then by name. */
function fisha_cat_by_order( array $terms ): array {
	usort( $terms, function ( $a, $b ) {
		return array( (int) get_term_meta( $a->term_id, 'order', true ), $a->name ) <=> array( (int) get_term_meta( $b->term_id, 'order', true ), $b->name );
	} );
	return $terms;
}

/** Displayed categories directly below $parent (0 = top level), in the owner's order. @return WP_Term[] */
function fisha_cat_displayed_children( int $parent ): array {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) return array();
	return fisha_cat_by_order( array_values( array_filter( $terms, 'fisha_cat_is_displayed' ) ) );
}

/** @return WP_Term[] */
function fisha_displayed_top_categories(): array {
	return fisha_cat_displayed_children( 0 );
}

/**
 * Once: Wearables, Collectibles, Originals and everything below them are shown, every other category is hidden (only where
 * no value is stored yet); the three get today's order and Home taglines. Result: the site looks exactly as before.
 */
function fisha_cat_visibility_migrate(): void {
	if ( (int) get_option( 'fisha_cat_visibility_db', 0 ) >= FISHA_CAT_VIS_DB || ! taxonomy_exists( 'product_cat' ) ) return;
	$roots = array( 'wearables' => 1, 'collectibles' => 2, 'originals' => 3 );
	$lines = array(
		'wearables'    => 'T-shirts, hoodies, caps and socks carrying the hand-drawn fish.',
		'collectibles' => 'Hand-painted Clippers and small objects, each one made once.',
		'originals'    => 'Ink drawings and paintings, signed and framed by hand.',
	);
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) return;
	$root_ids = array();
	foreach ( $terms as $t ) {
		if ( isset( $roots[ $t->slug ] ) && 0 === (int) $t->parent ) $root_ids[ (int) $t->term_id ] = $t->slug;
	}
	foreach ( $terms as $t ) {
		if ( '' !== (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ) ) continue;
		$chain = array_merge( array( (int) $t->term_id ), array_map( 'intval', get_ancestors( $t->term_id, 'product_cat' ) ) );
		update_term_meta( $t->term_id, FISHA_CAT_SHOWN, array_intersect( $chain, array_keys( $root_ids ) ) ? '1' : '0' );
	}
	foreach ( $root_ids as $id => $slug ) {
		update_term_meta( $id, 'order', $roots[ $slug ] );
		if ( '' === (string) get_term_meta( $id, 'fisha_home_line', true ) ) update_term_meta( $id, 'fisha_home_line', $lines[ $slug ] );
	}
	update_option( 'fisha_cat_visibility_db', FISHA_CAT_VIS_DB );
	fisha_cat_cache_reset();
}
add_action( 'init', 'fisha_cat_visibility_migrate', 20 );

/* ---------- header and footer menus ---------- */

/** A menu link block for a category (same attributes the block editor saves). */
function fisha_nav_cat_link( WP_Term $t ): array {
	return array(
		'blockName'    => 'core/navigation-link',
		'attrs'        => array( 'label' => $t->name, 'url' => get_term_link( $t ), 'type' => 'product_cat', 'id' => (int) $t->term_id, 'kind' => 'taxonomy' ),
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);
}

/** A menu link to a top-level category (or to one that no longer exists). */
function fisha_nav_is_top_cat_link( array $b ): bool {
	if ( 'core/navigation-link' !== ( $b['blockName'] ?? '' ) || 'product_cat' !== ( $b['attrs']['type'] ?? '' ) ) return false;
	$t = get_term( (int) ( $b['attrs']['id'] ?? 0 ), 'product_cat' );
	return ! $t instanceof WP_Term || 0 === (int) $t->parent;
}

/**
 * Replaces the first run of top-level category links in a list of parsed menu blocks (or, when the list has none, inside
 * its first submenu that has them) with links to the displayed top-level categories, in the owner's order.
 */
function fisha_nav_swap_cat_links( array $blocks, ?array $tops = null ): array {
	$blocks = array_values( $blocks );
	$start  = null;
	$end    = null;
	foreach ( $blocks as $i => $b ) {
		if ( fisha_nav_is_top_cat_link( $b ) ) {
			if ( null === $start ) $start = $i;
			$end = $i;
		} elseif ( null !== $start ) {
			break;
		}
	}
	$tops = $tops ?? fisha_displayed_top_categories();
	if ( null !== $start ) {
		array_splice( $blocks, $start, $end - $start + 1, array_map( 'fisha_nav_cat_link', $tops ) );
		return $blocks;
	}
	foreach ( $blocks as $i => $b ) {
		if ( 'core/navigation-submenu' !== ( $b['blockName'] ?? '' ) || empty( $b['innerBlocks'] ) ) continue;
		$inner = fisha_nav_swap_cat_links( $b['innerBlocks'], $tops );
		if ( $inner === array_values( $b['innerBlocks'] ) ) continue;
		$b['innerBlocks']  = $inner;
		$b['innerContent'] = array_fill( 0, count( $inner ), null );
		$b['innerHTML']    = '';
		$blocks[ $i ]      = $b;
		return $blocks;
	}
	return $blocks;
}

// The header "Shop" submenu and the footer Shop column list the displayed top-level categories; the saved menus are not changed.
add_filter( 'block_core_navigation_render_inner_blocks', function ( $inner ) {
	if ( ! $inner instanceof WP_Block_List || is_admin() ) return $inner;
	$parsed = array();
	foreach ( $inner as $b ) $parsed[] = $b->parsed_block;
	$swapped = fisha_nav_swap_cat_links( $parsed );
	if ( $swapped === $parsed ) return $inner;
	$n = count( $inner );
	foreach ( $swapped as $i => $pb ) $inner[ $i ] = $pb;
	for ( $i = count( $swapped ); $i < $n; $i++ ) unset( $inner[ $i ] );
	return $inner;
} );
