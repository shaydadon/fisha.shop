<?php
/**
 * Fisha Studio drafts. Edits to a live product are kept in one post meta ({fields, base, user, updated}) until
 * Publish; "base" remembers the value of each edited field the owner saw when editing started (sent by the Studio
 * as "seen", or the live value when it is not sent), so a change made on the site meanwhile (an order lowering
 * stock) is noticed. A product created in the Studio is a normal
 * WooCommerce draft: its edits apply directly and customers cannot see it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_DRAFT_META', '_fisha_studio_draft' );
define( 'FISHA_STUDIO_CREATED_META', '_fisha_studio_created' );
define( 'FISHA_STUDIO_PUBLISHED_META', '_fisha_studio_published' );

function fisha_studio_is_new( WC_Product $p ): bool {
	return in_array( get_post_status( $p->get_id() ), array( 'draft', 'auto-draft', 'pending' ), true );
}

/**
 * A product that goes live by any route (WP admin too) is marked, so moving it back to Draft never makes it "never live".
 * Its sizes that are on go live with it, so they stop being pending (Discard or Delete must never remove them); the same
 * for a size switched on while its product is live.
 */
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	$live = array( 'publish', 'private' );
	if ( 'product' === $post->post_type && in_array( $new, $live, true ) ) {
		update_post_meta( $post->ID, FISHA_STUDIO_PUBLISHED_META, '1' );
		$on = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $post->ID, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => FISHA_STUDIO_PENDING_META, 'meta_value' => '1' ) );
		foreach ( $on as $vid ) delete_post_meta( (int) $vid, FISHA_STUDIO_PENDING_META );
	} elseif ( 'product_variation' === $post->post_type && 'publish' === $new && $post->post_parent && in_array( get_post_status( $post->post_parent ), $live, true ) ) {
		delete_post_meta( $post->ID, FISHA_STUDIO_PENDING_META );
	}
}, 10, 3 );

/** The draft of a product (meta type 'post') or a category ('term'). */
function fisha_studio_draft_get( int $id, string $meta_type = 'post' ): array {
	$d = get_metadata( $meta_type, $id, FISHA_STUDIO_DRAFT_META, true );
	$empty = array( 'fields' => array(), 'base' => array(), 'user' => 0, 'updated' => 0 );
	return ( is_array( $d ) && isset( $d['fields'] ) && is_array( $d['fields'] ) ) ? array_merge( $empty, $d ) : $empty;
}

/** Stores a draft (or removes it when it has no fields). */
function fisha_studio_draft_put( int $id, array $d, string $meta_type = 'post' ): array {
	if ( empty( $d['fields'] ) ) {
		delete_metadata( $meta_type, $id, FISHA_STUDIO_DRAFT_META );
		return fisha_studio_draft_get( $id, $meta_type );
	}
	update_metadata( $meta_type, $id, FISHA_STUDIO_DRAFT_META, wp_slash( $d ) );
	return $d;
}

/**
 * Puts edited values into a draft. Each field keeps the value the owner saw when it first entered the draft (its base:
 * $seen, else the live value); a field set back to its live value while live did not move leaves the draft.
 */
function fisha_studio_draft_merge( array $d, array $live, array $values, array $seen ): array {
	foreach ( $values as $k => $v ) {
		if ( ! array_key_exists( $k, $live ) ) continue;
		if ( ! array_key_exists( $k, $d['base'] ) ) $d['base'][ $k ] = array_key_exists( $k, $seen ) ? $seen[ $k ] : $live[ $k ];
		$d['fields'][ $k ] = $v;
		if ( fisha_studio_same( $v, $live[ $k ] ) && fisha_studio_same( $d['base'][ $k ], $live[ $k ] ) ) {
			unset( $d['fields'][ $k ], $d['base'][ $k ] );
		}
	}
	$d['user']    = get_current_user_id();
	$d['updated'] = time();
	return $d;
}

/** Draft fields whose live value moved since the owner saw it and differs from theirs; $label names a field for the owner. */
function fisha_studio_draft_conflicts( array $draft, array $live, callable $label ): array {
	$out = array();
	foreach ( $draft['fields'] as $k => $mine ) {
		if ( ! array_key_exists( $k, $draft['base'] ) || ! array_key_exists( $k, $live ) ) continue;
		if ( fisha_studio_same( $draft['base'][ $k ], $live[ $k ] ) || fisha_studio_same( $mine, $live[ $k ] ) ) continue;
		$out[] = array( 'field' => $k, 'label' => $label( (string) $k ), 'base' => $draft['base'][ $k ], 'live' => $live[ $k ], 'mine' => $mine );
	}
	return $out;
}

/** $seen: cleaned values the owner had on screen, keyed by field; the base of a field entering the draft. */
function fisha_studio_draft_save( WC_Product $p, array $values, array $seen = array() ): array {
	return fisha_studio_draft_put( $p->get_id(), fisha_studio_draft_merge( fisha_studio_draft_get( $p->get_id() ), fisha_studio_product_values( $p ), $values, $seen ) );
}

function fisha_studio_draft_discard( int $id ): void {
	delete_post_meta( $id, FISHA_STUDIO_DRAFT_META );
	fisha_studio_variation_discard_pending( $id );
}

function fisha_studio_conflicts( WC_Product $p, array $draft ): array {
	return fisha_studio_draft_conflicts( $draft, fisha_studio_product_values( $p ), function ( string $k ) use ( $p ) { return fisha_studio_value_label( $p, $k ); } );
}

function fisha_studio_target_status( WC_Product $p ): string {
	$s = get_post_meta( $p->get_id(), '_fisha_studio_target_status', true );
	return in_array( $s, array( 'publish', 'private' ), true ) ? $s : 'publish';
}

/** The most used card colour among products of these categories, or the template's own default. */
function fisha_studio_default_card_bg( array $cat_ids ): string {
	global $wpdb;
	$ids = implode( ',', array_filter( array_map( 'absint', $cat_ids ) ) );
	if ( ! $ids ) return '#efe8dc';
	$bg = $wpdb->get_var( "SELECT m.meta_value FROM {$wpdb->postmeta} m
		JOIN {$wpdb->term_relationships} r ON r.object_id = m.post_id
		JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
		WHERE m.meta_key = '_fisha_card_bg' AND t.taxonomy = 'product_cat' AND t.term_id IN ($ids)
		GROUP BY m.meta_value ORDER BY COUNT(*) DESC LIMIT 1" );
	$bg = $bg ? sanitize_hex_color( $bg ) : '';
	return $bg ? strtolower( $bg ) : '#efe8dc';
}

function fisha_studio_product_create( string $name, string $type = 'simple' ): WC_Product {
	$p = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'draft' );
	$p->set_slug( fisha_studio_slug_from_name( $name ) );
	$p->set_catalog_visibility( 'visible' );
	$p->set_reviews_allowed( false );
	// Starts on "Fixed amount" with no number yet, so the owner is asked how many there are (they can pick another choice).
	// A product with sizes counts per size instead (each new size starts on a fixed amount).
	if ( 'variable' !== $type ) {
		$p->set_manage_stock( true );
		$p->set_backorders( 'no' );
	}
	// Made here and never published: only such a product may have its sizes deleted or its type switched.
	$p->update_meta_data( FISHA_STUDIO_CREATED_META, '1' );
	$p->save();
	if ( '' === $name ) {
		// WooCommerce titles an unnamed new product "Product"; keep it empty so the owner types the real name.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => '' ), array( 'ID' => $p->get_id() ) );
		clean_post_cache( $p->get_id() );
	}
	return $p;
}

/** WordPress refuses to save a post with no title, content or excerpt; an unnamed product draft must still save and go to the trash. */
add_filter( 'wp_insert_post_empty_content', function ( $empty, $post ) {
	return $empty && ! ( 'product' === ( $post['post_type'] ?? '' ) && in_array( $post['post_status'] ?? '', array( 'draft', 'trash' ), true ) );
}, 10, 2 );

/** Cleans the "seen" values sent with an edit (size keys too when the product is given); bad or unknown keys are dropped. */
function fisha_studio_clean_seen( $seen, ?WC_Product $p = null ): array {
	if ( ! is_array( $seen ) ) return array();
	list( $values, $errors ) = $p ? fisha_studio_sanitize_product_input( $p, $seen ) : fisha_studio_sanitize( fisha_studio_product_fields(), $seen );
	return array_diff_key( $values, $errors );
}

/**
 * Owner rule: a fixed amount is 1 or more, and 0 under "Fixed amount" means Sold out. Rewrites a stock count of 0 in an
 * edit (the product's or a size's) whose item would still count stock into Sold out: counting off, status outofstock.
 * A product with sizes that counts its stock as a whole keeps 0, as it has no Sold out of its own.
 */
function fisha_studio_zero_is_sold_out( WC_Product $p, array $values ): array {
	$current = null;
	foreach ( array_keys( $values ) as $k ) {
		$k = (string) $k;
		if ( 'stock_quantity' === $k ) {
			if ( $p->is_type( 'variable' ) ) continue;
			$prefix = '';
		} elseif ( ( $parsed = fisha_studio_parse_var_key( $k ) ) && 'stock_quantity' === $parsed[1] ) {
			$prefix = "var:{$parsed[0]}:";
		} else {
			continue;
		}
		if ( 0 !== $values[ $k ] ) continue;
		$mk = $prefix . 'manage_stock';
		if ( array_key_exists( $mk, $values ) ) {
			$manage = $values[ $mk ];
		} else {
			if ( null === $current ) $current = array_merge( fisha_studio_product_values( $p ), fisha_studio_draft_get( $p->get_id() )['fields'] );
			$manage = $current[ $mk ] ?? false;
		}
		if ( empty( $manage ) ) continue;
		unset( $values[ $k ] );
		$values[ $mk ]                    = false;
		$values[ $prefix . 'stock_status' ] = 'outofstock';
	}
	return $values;
}

/** The one entry point for an edit coming from the Studio. $values and $seen must already be cleaned. */
function fisha_studio_product_edit( WC_Product $p, array $values, array $seen = array() ): void {
	if ( isset( $values['category_ids'] ) && is_array( $values['category_ids'] ) ) $values['category_ids'] = fisha_studio_drop_default_cat( $values['category_ids'] );
	$values = fisha_studio_zero_is_sold_out( $p, $values );
	if ( ! fisha_studio_is_new( $p ) ) {
		fisha_studio_draft_save( $p, $values, $seen );
		return;
	}
	$id = $p->get_id();
	if ( array_key_exists( 'status', $values ) ) {
		update_post_meta( $id, '_fisha_studio_target_status', 'private' === $values['status'] ? 'private' : 'publish' );
		unset( $values['status'] );
	}
	if ( array_key_exists( 'slug', $values ) ) {
		$manual = '' !== $values['slug'];
		update_post_meta( $id, '_fisha_studio_slug_manual', $manual ? '1' : '' );
		if ( ! $manual ) $values['slug'] = fisha_studio_slug_from_name( $values['name'] ?? $p->get_name( 'edit' ) );
	}
	if ( isset( $values['name'] ) && ! get_post_meta( $id, '_fisha_studio_slug_manual', true ) && ! array_key_exists( 'slug', $values ) ) {
		$values['slug'] = fisha_studio_slug_from_name( $values['name'] );
	}
	if ( array_key_exists( 'card_bg', $values ) ) update_post_meta( $id, '_fisha_studio_bg_manual', '1' );
	fisha_studio_product_apply_all( $p, $values );
	if ( ! array_key_exists( 'card_bg', $values ) && array_key_exists( 'category_ids', $values ) && ! get_post_meta( $id, '_fisha_studio_bg_manual', true ) ) {
		$p->update_meta_data( '_fisha_card_bg', fisha_studio_default_card_bg( (array) $values['category_ids'] ) );
	}
	$p->save();
}
