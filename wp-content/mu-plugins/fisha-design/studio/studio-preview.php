<?php
/**
 * Fisha Studio preview. The editor's iframe loads the real page with ?fisha_preview=<token>. For that request only,
 * and only for the signed-in user the token was made for, the product's draft is laid over the live data through
 * WooCommerce and WordPress filters, so the preview comes from exactly the same templates customers get.
 * Preview responses are never cached and never indexed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_preview_sig( int $user_id, string $type, int $id, int $expires ): string {
	return substr( hash_hmac( 'sha256', "$user_id|$type|$id|$expires", wp_salt( 'auth' ) ), 0, 32 );
}
function fisha_studio_preview_token( int $user_id, string $type, int $id, int $expires = 0 ): string {
	$expires = $expires ?: time() + 30 * MINUTE_IN_SECONDS;
	return implode( '-', array( $user_id, $type, $id, $expires, fisha_studio_preview_sig( $user_id, $type, $id, $expires ) ) );
}
function fisha_studio_preview_verify( string $token ): ?array {
	$parts = explode( '-', $token );
	if ( 5 !== count( $parts ) ) return null;
	list( $user, $type, $id, $expires, $sig ) = $parts;
	if ( ! ctype_digit( $user ) || ! ctype_digit( $id ) || ! ctype_digit( $expires ) ) return null;
	if ( ! in_array( $type, array( 'product', 'category' ), true ) || (int) $expires < time() ) return null;
	if ( ! hash_equals( fisha_studio_preview_sig( (int) $user, $type, (int) $id, (int) $expires ), $sig ) ) return null;
	if ( (int) $user !== get_current_user_id() || ! current_user_can( 'fisha_studio' ) ) return null;
	return array( 'type' => $type, 'id' => (int) $id );
}

function fisha_studio_preview_active(): bool {
	return ! empty( $GLOBALS['fisha_studio_preview'] );
}
function fisha_studio_preview_add( string $hook, callable $fn, int $priority = 99, int $args = 2 ): void {
	add_filter( $hook, $fn, $priority, $args );
	$GLOBALS['fisha_studio_preview_hooks'][] = array( $hook, $fn, $priority );
}
function fisha_studio_preview_uninstall(): void {
	foreach ( $GLOBALS['fisha_studio_preview_hooks'] ?? array() as $h ) remove_filter( $h[0], $h[1], $h[2] );
	$GLOBALS['fisha_studio_preview_hooks'] = array();
	unset( $GLOBALS['fisha_studio_preview'] );
}

/** The stock status WooCommerce will store for these values (it derives it when stock is tracked). */
function fisha_studio_effective_stock_status( array $v ): string {
	if ( empty( $v['manage_stock'] ) ) return (string) $v['stock_status'];
	if ( (int) $v['stock_quantity'] > (int) get_option( 'woocommerce_notify_no_stock_amount', 0 ) ) return 'instock';
	return 'no' === $v['backorders'] ? 'outofstock' : 'onbackorder';
}
/** The price customers will pay today for these values. */
function fisha_studio_effective_price( array $v ): string {
	$today   = current_time( 'Y-m-d' );
	$sale_on = '' !== (string) $v['sale_price']
		&& ( '' === (string) $v['date_on_sale_from'] || $v['date_on_sale_from'] <= $today )
		&& ( '' === (string) $v['date_on_sale_to'] || $v['date_on_sale_to'] >= $today );
	return (string) ( $sale_on ? $v['sale_price'] : $v['regular_price'] );
}

/** null = the query has no category, true/false = whether the draft categories (or their parents) match it. */
function fisha_studio_preview_category_match( array $fields, array $args ): ?bool {
	if ( empty( $args['category'] ) || ! array_key_exists( 'category_ids', $fields ) ) return null;
	$slugs = array();
	foreach ( $fields['category_ids'] as $cid ) {
		foreach ( array_merge( array( (int) $cid ), get_ancestors( (int) $cid, 'product_cat' ) ) as $tid ) {
			$t = get_term( (int) $tid, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) $slugs[] = $t->slug;
		}
	}
	return (bool) array_intersect( $slugs, (array) $args['category'] );
}

function fisha_studio_preview_install_product( int $id, array $fields ): void {
	fisha_studio_preview_uninstall();
	$p = wc_get_product( $id );
	if ( ! $p ) return;
	$GLOBALS['fisha_studio_preview'] = array( 'type' => 'product', 'id' => $id, 'fields' => $fields );

	$merged = array_merge( fisha_studio_product_values( $p ), $fields );
	$over   = array_intersect_key( $merged, $fields );
	if ( array_intersect_key( $fields, array_flip( array( 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ) ) ) ) {
		$over['stock_status'] = fisha_studio_effective_stock_status( $merged );
	}
	if ( array_intersect_key( $fields, array_flip( array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' ) ) ) ) {
		$over['price'] = fisha_studio_effective_price( $merged );
	}

	// 1. WooCommerce product props (the 'view' context getters run these filters).
	$props = array( 'name', 'status', 'catalog_visibility', 'featured', 'short_description', 'description', 'reviews_allowed', 'menu_order',
		'regular_price', 'sale_price', 'price', 'date_on_sale_from', 'date_on_sale_to', 'sku', 'manage_stock', 'stock_quantity',
		'stock_status', 'backorders', 'sold_individually', 'low_stock_amount', 'image_id', 'gallery_image_ids', 'category_ids',
		'tag_ids', 'weight', 'length', 'width', 'height' );
	foreach ( $props as $prop ) {
		if ( ! array_key_exists( $prop, $over ) ) continue;
		$value = $over[ $prop ];
		if ( 'date_on_sale_from' === $prop || 'date_on_sale_to' === $prop ) {
			$value = $value ? new WC_DateTime( $value . ( 'date_on_sale_to' === $prop ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() ) : null;
		}
		fisha_studio_preview_add( "woocommerce_product_get_$prop", function ( $v, $obj = null ) use ( $id, $value ) {
			return ( $obj instanceof WC_Product && $obj->get_id() === $id ) ? $value : $v;
		} );
	}

	// 2. Post fields read by the block template (title, excerpt, description tab, reviews).
	if ( array_key_exists( 'name', $over ) ) {
		$name = $over['name'];
		fisha_studio_preview_add( 'the_title', function ( $t, $pid = 0 ) use ( $id, $name ) { return (int) $pid === $id ? esc_html( $name ) : $t; } );
		fisha_studio_preview_add( 'single_post_title', function ( $t, $post = null ) use ( $id, $name ) { return ( $post && (int) $post->ID === $id ) ? $name : $t; } );
	}
	if ( array_key_exists( 'short_description', $over ) ) {
		$short = $over['short_description'];
		fisha_studio_preview_add( 'get_the_excerpt', function ( $e, $post = null ) use ( $id, $short ) { return ( $post && (int) $post->ID === $id ) ? $short : $e; } );
	}
	if ( array_key_exists( 'description', $over ) ) {
		$long = $over['description'];
		fisha_studio_preview_add( 'the_content', function ( $c ) use ( $id, $long ) { return get_the_ID() === $id ? $long : $c; }, 1, 1 );
	}
	if ( array_key_exists( 'reviews_allowed', $over ) ) {
		$open = (bool) $over['reviews_allowed'];
		fisha_studio_preview_add( 'comments_open', function ( $o, $pid = 0 ) use ( $id, $open ) { return (int) $pid === $id ? $open : $o; } );
	}

	// 3. Post meta read with get_post_meta() by the Fisha templates (card colour, meta line, series, SEO, thumbnail).
	$meta = array();
	foreach ( fisha_studio_product_fields() as $k => $def ) {
		if ( isset( $def['meta'] ) && array_key_exists( $k, $over ) ) $meta[ $def['meta'] ] = is_bool( $over[ $k ] ) ? ( $over[ $k ] ? '1' : '' ) : $over[ $k ];
	}
	if ( array_key_exists( 'image_id', $over ) ) $meta['_thumbnail_id'] = $over['image_id'] ?: '';
	if ( array_key_exists( 'gallery_image_ids', $over ) ) $meta['_product_image_gallery'] = implode( ',', $over['gallery_image_ids'] );
	if ( $meta ) {
		fisha_studio_preview_add( 'get_post_metadata', function ( $v, $oid, $key = '' ) use ( $id, $meta ) {
			return ( (int) $oid === $id && '' !== $key && array_key_exists( $key, $meta ) ) ? array( $meta[ $key ] ) : $v;
		}, 99, 3 );
	}

	// 4. Lists (category pages, Shop, Home, related): follow draft categories; show a never-published product.
	$is_new = fisha_studio_is_new( $p );
	if ( $is_new ) {
		fisha_studio_preview_add( 'woocommerce_product_object_query_args', function ( $args ) {
			$status = $args['status'] ?? '';
			if ( 'publish' === $status || array( 'publish' ) === $status ) {
				$args['status']        = array( 'publish', 'draft' );
				$args['fisha_preview'] = 1;
			}
			return $args;
		}, 99, 1 );
	}
	fisha_studio_preview_add( 'woocommerce_product_object_query', function ( $results, $args ) use ( $id, $fields ) {
		if ( ! is_array( $results ) ) return $results;
		$match = fisha_studio_preview_category_match( $fields, $args );
		$out   = array();
		$seen  = false;
		foreach ( $results as $r ) {
			$rid = is_object( $r ) ? $r->get_id() : (int) $r;
			if ( $rid === $id ) {
				$seen = true;
				if ( false !== $match ) $out[] = $r;
				continue;
			}
			if ( ! empty( $args['fisha_preview'] ) && 'publish' !== get_post_status( $rid ) ) continue;
			$out[] = $r;
		}
		if ( ! $seen && true === $match ) array_unshift( $out, 'ids' === ( $args['return'] ?? '' ) ? $id : wc_get_product( $id ) );
		return $out;
	} );

	if ( $p->is_type( 'variable' ) ) fisha_studio_preview_install_sizes( $p, $merged, $fields );
}

add_action( 'init', function () {
	if ( empty( $_GET['fisha_preview'] ) || is_admin() || wp_doing_ajax() ) return;
	$item = fisha_studio_preview_verify( sanitize_text_field( wp_unslash( $_GET['fisha_preview'] ) ) );
	if ( ! $item || 'product' !== $item['type'] ) return;
	fisha_studio_preview_install_product( $item['id'], fisha_studio_draft_get( $item['id'] )['fields'] );
}, 20 );

add_action( 'send_headers', function () {
	if ( ! fisha_studio_preview_active() ) return;
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	do_action( 'litespeed_control_set_nocache', 'fisha studio preview' );
} );
add_filter( 'show_admin_bar', function ( $show ) { return fisha_studio_preview_active() ? false : $show; }, 20 );

/** Pages the editor can preview this product on, each with a fresh token. */
function fisha_studio_preview_targets( WC_Product $p, array $cat_ids ): array {
	$token   = fisha_studio_preview_token( get_current_user_id(), 'product', $p->get_id() );
	$targets = array( array( 'key' => 'item', 'label' => 'Product page', 'url' => get_permalink( $p->get_id() ) ) );
	foreach ( $cat_ids as $cid ) {
		$t = get_term( (int) $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$targets[] = array( 'key' => 'category', 'label' => $t->name . ' page', 'url' => get_term_link( $t ) );
			break;
		}
	}
	$targets[] = array( 'key' => 'shop', 'label' => 'Shop', 'url' => get_permalink( wc_get_page_id( 'shop' ) ) );
	$targets[] = array( 'key' => 'home', 'label' => 'Home', 'url' => home_url( '/' ) );
	foreach ( $targets as &$t ) $t['url'] = add_query_arg( 'fisha_preview', $token, $t['url'] );
	return $targets;
}

/** Preview of a product with sizes: each changed size, which sizes are offered, the price range, stock and options. */
function fisha_studio_preview_install_sizes( WC_Product $p, array $merged, array $fields ): void {
	$id    = $p->get_id();
	$sizes = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		foreach ( array_keys( fisha_studio_variation_fields() ) as $f ) $sizes[ $vid ][ $f ] = $merged[ fisha_studio_var_key( $vid, $f ) ] ?? null;
		$v = wc_get_product( $vid );
		$sizes[ $vid ]['backorders'] = $v ? $v->get_backorders( 'edit' ) : 'no';
	}
	$touched = array();
	foreach ( array_keys( $fields ) as $k ) {
		$parsed = fisha_studio_parse_var_key( (string) $k );
		if ( $parsed ) $touched[ $parsed[0] ] = true;
	}
	if ( ! $touched && ! array_key_exists( 'attributes', $fields ) ) return;

	$price_of = function ( array $x ): string {
		return fisha_studio_effective_price( array( 'regular_price' => (string) $x['regular_price'], 'sale_price' => (string) $x['sale_price'], 'date_on_sale_from' => '', 'date_on_sale_to' => '' ) );
	};
	$stock_of = function ( array $x ): string {
		return fisha_studio_effective_stock_status( array( 'manage_stock' => (bool) $x['manage_stock'], 'stock_quantity' => $x['stock_quantity'], 'stock_status' => (string) $x['stock_status'], 'backorders' => (string) $x['backorders'] ) );
	};
	$offered = array();
	foreach ( $sizes as $vid => $x ) $offered[ $vid ] = ! empty( $x['enabled'] ) && '' !== (string) $x['regular_price'];
	$on = array_keys( array_filter( $offered ) );

	// 1. Each changed size's own values.
	foreach ( array_keys( $touched ) as $vid ) {
		if ( ! isset( $sizes[ $vid ] ) ) continue;
		$x    = $sizes[ $vid ];
		$over = array(
			'status' => $x['enabled'] ? 'publish' : 'private', 'regular_price' => (string) $x['regular_price'], 'sale_price' => (string) $x['sale_price'],
			'price' => $price_of( $x ), 'sku' => (string) $x['sku'], 'manage_stock' => (bool) $x['manage_stock'], 'stock_quantity' => $x['stock_quantity'],
			'stock_status' => $stock_of( $x ), 'image_id' => (int) $x['image_id'],
		);
		foreach ( $over as $prop => $value ) {
			fisha_studio_preview_add( "woocommerce_product_variation_get_$prop", function ( $val, $obj = null ) use ( $vid, $value ) {
				return ( $obj instanceof WC_Product && $obj->get_id() === $vid ) ? $value : $val;
			} );
		}
	}
	fisha_studio_preview_add( 'woocommerce_variation_is_visible', function ( $visible, $vid = 0 ) use ( $offered ) {
		return array_key_exists( (int) $vid, $offered ) ? $offered[ (int) $vid ] : $visible;
	} );

	// 2. Which sizes are offered, and the price range.
	fisha_studio_preview_add( 'woocommerce_get_children', function ( $children, $product = null, $visible_only = false ) use ( $id, $on ) {
		return ( $visible_only && $product instanceof WC_Product && $product->get_id() === $id ) ? $on : $children;
	}, 99, 3 );
	fisha_studio_preview_add( 'woocommerce_variation_prices', function ( $prices, $product = null ) use ( $id, $on, $sizes, $price_of ) {
		if ( ! $product instanceof WC_Product || $product->get_id() !== $id ) return $prices;
		$dp  = wc_get_price_decimals();
		$out = array( 'price' => array(), 'regular_price' => array(), 'sale_price' => array() );
		foreach ( $on as $vid ) {
			$x = $sizes[ $vid ];
			$out['price'][ $vid ]         = wc_format_decimal( $price_of( $x ), $dp );
			$out['regular_price'][ $vid ] = wc_format_decimal( $x['regular_price'], $dp );
			$out['sale_price'][ $vid ]    = wc_format_decimal( '' !== (string) $x['sale_price'] ? $x['sale_price'] : $x['regular_price'], $dp );
		}
		foreach ( $out as &$list ) asort( $list );
		return $out;
	} );

	// 3. In stock when any size that is on is.
	$in = false;
	foreach ( $on as $vid ) if ( 'outofstock' !== $stock_of( $sizes[ $vid ] ) ) $in = true;
	fisha_studio_preview_add( 'woocommerce_product_get_stock_status', function ( $s, $obj = null ) use ( $id, $in ) {
		return ( $obj instanceof WC_Product && $obj->get_id() === $id ) ? ( $in ? 'instock' : 'outofstock' ) : $s;
	} );

	// 4. The options shown on the product page.
	if ( array_key_exists( 'attributes', $fields ) ) {
		$objs = fisha_studio_attribute_objects( (array) $merged['attributes'] );
		fisha_studio_preview_add( 'woocommerce_product_get_attributes', function ( $a, $obj = null ) use ( $id, $objs ) {
			return ( $obj instanceof WC_Product && $obj->get_id() === $id ) ? $objs : $a;
		} );
	}
}
