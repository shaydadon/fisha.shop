<?php
/**
 * Fisha Studio sizes and options (variable products). A variable product's draft uses flat keys next to the normal
 * product fields: "attributes" (the options shoppers choose, e.g. Size: S, M, L) and "var:{id}:{field}" for each
 * size's own on/off, price, sale price, SKU, stock and photo. The same draft, conflict, preview and publish code then
 * works per size.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_PENDING_META', '_fisha_studio_pending' );

function fisha_studio_variation_fields(): array {
	static $fields = null;
	if ( null !== $fields ) return $fields;
	$prop = function ( string $prop, string $type, array $extra = array() ): array {
		return array_merge( array(
			'type' => $type,
			'get'  => function ( WC_Product $v ) use ( $prop ) { return $v->{"get_$prop"}( 'edit' ); },
			'set'  => function ( WC_Product $v, $x ) use ( $prop ) { $v->{"set_$prop"}( $x ); },
		), $extra );
	};
	$fields = array(
		'enabled'        => array(
			'type'  => 'bool',
			'label' => 'On sale',
			'get'   => function ( WC_Product $v ) { return 'publish' === $v->get_status( 'edit' ); },
			'set'   => function ( WC_Product $v, $x ) { $v->set_status( $x ? 'publish' : 'private' ); },
		),
		'regular_price'  => $prop( 'regular_price', 'money', array( 'label' => 'Price' ) ),
		'sale_price'     => $prop( 'sale_price', 'money', array( 'label' => 'Sale price' ) ),
		'sku'            => $prop( 'sku', 'text', array( 'label' => 'SKU', 'max' => 64 ) ),
		'manage_stock'   => $prop( 'manage_stock', 'bool', array( 'label' => 'Count stock' ) ),
		'stock_quantity' => $prop( 'stock_quantity', 'int', array( 'label' => 'Stock', 'nullable' => true, 'min' => 0,
			'get' => function ( WC_Product $v ) { return true === $v->get_manage_stock( 'edit' ) ? $v->get_stock_quantity( 'edit' ) : null; } ) ),
		'stock_status'   => $prop( 'stock_status', 'enum', array( 'label' => 'Stock status', 'options' => array( 'instock', 'outofstock', 'onbackorder' ) ) ),
		'backorders'     => $prop( 'backorders', 'enum', array( 'label' => 'Orders when sold out', 'options' => array( 'no', 'notify', 'yes' ) ) ),
		'image_id'       => $prop( 'image_id', 'attachment', array( 'label' => 'Photo' ) ),
	);
	return $fields;
}

function fisha_studio_var_key( int $vid, string $field ): string {
	return "var:$vid:$field";
}

/** [variation id, field] for a "var:{id}:{field}" key, or null. */
function fisha_studio_parse_var_key( string $key ): ?array {
	if ( ! preg_match( '/^var:(\d+):([a-z_]+)$/', $key, $m ) ) return null;
	return isset( fisha_studio_variation_fields()[ $m[2] ] ) ? array( (int) $m[1], $m[2] ) : null;
}

/** Every size of a variable product (switched on or off), in shop order. */
function fisha_studio_variation_ids( WC_Product $p ): array {
	if ( ! $p->is_type( 'variable' ) ) return array();
	return array_map( 'intval', get_posts( array(
		'post_type' => 'product_variation', 'post_parent' => $p->get_id(), 'post_status' => array( 'publish', 'private' ),
		'numberposts' => -1, 'fields' => 'ids', 'orderby' => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
	) ) );
}

/* ---------- options ---------- */

function fisha_studio_attributes_read( WC_Product $p ): array {
	$out = array();
	foreach ( $p->get_attributes( 'edit' ) as $a ) {
		if ( ! $a instanceof WC_Product_Attribute ) continue;
		$tax  = $a->is_taxonomy();
		$opts = $tax ? array_map( 'intval', $a->get_options() ) : array_map( 'strval', $a->get_options() );
		if ( $tax ) sort( $opts );
		$out[] = array(
			'name'      => $a->get_name(),
			'label'     => $tax ? fisha_studio_plain( wc_attribute_label( $a->get_name() ) ) : $a->get_name(),
			'taxonomy'  => $tax,
			'options'   => array_values( $opts ),
			'variation' => (bool) $a->get_variation(),
			'visible'   => (bool) $a->get_visible(),
		);
	}
	return $out;
}

/** Cleans an "attributes" list sent by the Studio. Returns [list, error message or null]. */
function fisha_studio_clean_attributes( $v ): array {
	if ( ! is_array( $v ) ) return array( array(), 'The options could not be read. Please reload the page.' );
	$out   = array();
	$names = array();
	foreach ( array_values( $v ) as $a ) {
		if ( ! is_array( $a ) ) return array( $out, 'The options could not be read. Please reload the page.' );
		$tax  = ! empty( $a['taxonomy'] );
		$name = $tax ? wc_sanitize_taxonomy_name( (string) ( $a['name'] ?? '' ) ) : trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
		if ( '' === $name || ( $tax && ( 0 !== strpos( $name, 'pa_' ) || ! taxonomy_exists( $name ) ) ) ) {
			return array( $out, 'An option no longer exists. Please reload the page.' );
		}
		if ( ! $tax && mb_strlen( $name ) > 40 ) return array( $out, 'Option names can be at most 40 characters.' );
		if ( ! $tax && '' === sanitize_title( $name ) ) return array( $out, sprintf( '"%s" needs at least one letter or number.', $name ) );
		$key = strtolower( $tax ? $name : sanitize_title( $name ) );
		if ( isset( $names[ $key ] ) ) return array( $out, sprintf( '"%s" is listed twice.', $tax ? wc_attribute_label( $name ) : $name ) );
		$names[ $key ] = true;
		if ( $tax ) {
			$opts = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $a['options'] ?? array() ) ) ) ) );
			foreach ( $opts as $tid ) {
				$t = get_term( $tid, $name );
				if ( ! $t || is_wp_error( $t ) ) return array( $out, sprintf( 'A value of %s no longer exists. Please reload the page.', wc_attribute_label( $name ) ) );
			}
			sort( $opts );
		} else {
			$opts = array();
			foreach ( (array) ( $a['options'] ?? array() ) as $o ) {
				$o = trim( sanitize_text_field( (string) $o ) );
				// WooCommerce stores a product's own values joined with "|", so a value cannot contain it.
				if ( false !== strpos( $o, '|' ) ) return array( $out, sprintf( 'The value "%s" of %s cannot contain the | sign.', $o, $name ) );
				if ( '' !== $o && ! in_array( $o, $opts, true ) ) $opts[] = $o;
			}
		}
		$out[] = array(
			'name'      => $name,
			'label'     => $tax ? fisha_studio_plain( wc_attribute_label( $name ) ) : $name,
			'taxonomy'  => $tax,
			'options'   => $opts,
			'variation' => ! empty( $a['variation'] ),
			'visible'   => ! empty( $a['visible'] ),
		);
	}
	return array( $out, null );
}

/** WooCommerce attribute objects for an options list, keyed the way WooCommerce keys them. */
function fisha_studio_attribute_objects( array $list ): array {
	$objs = array();
	foreach ( array_values( $list ) as $i => $a ) {
		$o = new WC_Product_Attribute();
		if ( $a['taxonomy'] ) $o->set_id( wc_attribute_taxonomy_id_by_name( $a['name'] ) );
		$o->set_name( $a['name'] );
		$o->set_options( $a['options'] );
		$o->set_position( $i );
		$o->set_visible( (bool) $a['visible'] );
		$o->set_variation( (bool) $a['variation'] );
		$objs[ sanitize_title( $a['name'] ) ] = $o;
	}
	return $objs;
}

function fisha_studio_attributes_apply( WC_Product $p, array $list ): void {
	$p->set_attributes( fisha_studio_attribute_objects( $list ) );
}

/** The shop-wide options (Size, Shoe size…) with their values in shop order. */
function fisha_studio_attributes_catalog(): array {
	$out = array();
	foreach ( wc_get_attribute_taxonomies() as $a ) {
		$tax = wc_attribute_taxonomy_name( $a->attribute_name );
		if ( ! taxonomy_exists( $tax ) ) continue;
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'menu_order' ) );
		$list  = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) $list[] = array( 'id' => (int) $t->term_id, 'name' => fisha_studio_plain( $t->name ), 'slug' => $t->slug );
		$out[] = array( 'id' => (int) $a->attribute_id, 'name' => $tax, 'label' => fisha_studio_plain( $a->attribute_label ), 'terms' => $list );
	}
	return $out;
}

/* ---------- values across the product and its sizes ---------- */

/** Every value the Studio edits: the normal fields, plus for a variable product its options and every size key. */
function fisha_studio_product_values( WC_Product $p ): array {
	$out = fisha_studio_product_read( $p );
	if ( ! $p->is_type( 'variable' ) ) return $out;
	$out['attributes'] = fisha_studio_attributes_read( $p );
	$defs = fisha_studio_variation_fields();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		foreach ( $defs as $f => $def ) $out[ fisha_studio_var_key( $vid, $f ) ] = fisha_studio_clean( $def, call_user_func( $def['get'], $v ) )[0];
	}
	return $out;
}

/** Cleans an edit: normal fields, and for a variable product "attributes" and this product's own "var:" keys. [values, errors] */
function fisha_studio_sanitize_product_input( WC_Product $p, array $input ): array {
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), $input );
	if ( ! $p->is_type( 'variable' ) ) return array( $values, $errors );
	$kids = array_flip( fisha_studio_variation_ids( $p ) );
	$defs = fisha_studio_variation_fields();
	foreach ( $input as $k => $v ) {
		$k = (string) $k;
		if ( 'attributes' === $k ) {
			list( $clean, $err ) = fisha_studio_clean_attributes( $v );
			$values[ $k ] = $clean;
			if ( $err ) $errors[ $k ] = $err;
			continue;
		}
		$parsed = fisha_studio_parse_var_key( $k );
		if ( ! $parsed || ! isset( $kids[ $parsed[0] ] ) ) continue;
		list( $clean, $err ) = fisha_studio_clean( $defs[ $parsed[1] ], $v );
		$values[ $k ] = $clean;
		if ( $err ) $errors[ $k ] = $err;
	}
	return array( $values, $errors );
}

/** Sets values on the product object (not saved) and on its sizes (each size saved). */
function fisha_studio_product_apply_all( WC_Product $p, array $values ): void {
	$plain = array();
	$sizes = array();
	foreach ( $values as $k => $v ) {
		if ( 'attributes' === $k ) {
			fisha_studio_attributes_apply( $p, (array) $v );
			continue;
		}
		$parsed = fisha_studio_parse_var_key( (string) $k );
		if ( $parsed ) {
			$sizes[ $parsed[0] ][ $parsed[1] ] = $v;
			continue;
		}
		$plain[ $k ] = $v;
	}
	fisha_studio_product_apply( $p, $plain );
	$defs = fisha_studio_variation_fields();
	foreach ( $sizes as $vid => $fields ) {
		$var = wc_get_product( $vid );
		if ( ! $var || $var->get_parent_id() !== $p->get_id() ) continue;
		foreach ( $fields as $f => $x ) call_user_func( $defs[ $f ]['set'], $var, $x );
		$var->save();
	}
}

/* ---------- labels ---------- */

function fisha_studio_variation_label( WC_Product $v ): string {
	$label = fisha_studio_plain( wc_get_formatted_variation( $v, true, false ) );
	return '' !== $label ? $label : '#' . $v->get_id();
}

/** A plain-English name for any edited key ("M: Stock", "Price"). */
function fisha_studio_value_label( WC_Product $p, string $key ): string {
	if ( 'attributes' === $key ) return 'Sizes and options';
	if ( 'variations' === $key ) return 'Sizes';
	$parsed = fisha_studio_parse_var_key( $key );
	if ( ! $parsed ) return fisha_studio_product_fields()[ $key ]['label'] ?? $key;
	$v = wc_get_product( $parsed[0] );
	return ( $v ? fisha_studio_variation_label( $v ) . ': ' : '' ) . fisha_studio_variation_fields()[ $parsed[1] ]['label'];
}

/* ---------- creating and removing sizes ---------- */

/** Whether WooCommerce has order lines for this product (any size), or for this one size when $vid is given. */
function fisha_studio_has_orders( int $pid, int $vid = 0 ): bool {
	global $wpdb;
	$table = $wpdb->prefix . 'wc_order_product_lookup';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) return false;
	$sql = $vid
		? $wpdb->prepare( "SELECT 1 FROM $table WHERE variation_id = %d LIMIT 1", $vid )
		: $wpdb->prepare( "SELECT 1 FROM $table WHERE product_id = %d LIMIT 1", $pid );
	return (bool) $wpdb->get_var( $sql );
}

/**
 * True only for a product made in the Studio that was never published (by the Studio or in WP admin) and never sold.
 * Only such a product may have sizes deleted or its type switched; a product that was live and then moved back to
 * Draft keeps everything, because orders and links may point at it.
 */
function fisha_studio_never_live( WC_Product $p ): bool {
	$id = $p->get_id();
	if ( ! fisha_studio_is_new( $p ) ) return false;
	if ( '1' !== get_post_meta( $id, FISHA_STUDIO_CREATED_META, true ) ) return false;
	if ( metadata_exists( 'post', $id, FISHA_STUDIO_PUBLISHED_META ) ) return false;
	return ! fisha_studio_has_orders( $id );
}

/** Whether this size may be deleted: a size added in the Studio and never sold, or any size of a never-live product. */
function fisha_studio_size_deletable( WC_Product $p, int $vid ): bool {
	if ( fisha_studio_never_live( $p ) ) return true;
	return '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true ) && ! fisha_studio_has_orders( $p->get_id(), $vid );
}

function fisha_studio_combo_key( array $attrs ): string {
	ksort( $attrs );
	return wp_json_encode( array_map( 'strval', $attrs ) );
}

/** The price most sizes of this product have, as the starting price of a new size. */
function fisha_studio_default_size_price( WC_Product $p ): string {
	$counts = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$r = (string) get_post_meta( $vid, '_regular_price', true );
		if ( '' !== $r ) $counts[ $r ] = ( $counts[ $r ] ?? 0 ) + 1;
	}
	if ( ! $counts ) return '';
	arsort( $counts );
	return (string) array_key_first( $counts );
}

/**
 * Creates one size. $attrs maps option key (sanitize_title of the option name, e.g. "pa_size", "color") to the value
 * a size stores (a term slug, or the text). On a live product the size is created switched off and marked pending,
 * and the draft switches it on; on a never-published product it is switched on straight away.
 */
function fisha_studio_variation_create( WC_Product $p, array $attrs ): WC_Product_Variation {
	$is_new = fisha_studio_is_new( $p );
	$v      = new WC_Product_Variation();
	$v->set_parent_id( $p->get_id() );
	$v->set_attributes( array_map( 'strval', $attrs ) );
	$v->set_regular_price( fisha_studio_default_size_price( $p ) );
	$v->set_status( $is_new ? 'publish' : 'private' );
	$v->set_menu_order( count( fisha_studio_variation_ids( $p ) ) );
	// Starts on "Fixed amount" with no number, unless the product counts its stock as a whole.
	if ( true !== $p->get_manage_stock( 'edit' ) ) {
		$v->set_manage_stock( true );
		$v->set_backorders( 'no' );
	}
	// A product that was live and is back in Draft takes the size at once, but marks it pending so it stays deletable until publish.
	if ( ! $is_new || ! fisha_studio_never_live( $p ) ) $v->update_meta_data( FISHA_STUDIO_PENDING_META, '1' );
	$v->save();
	if ( ! $is_new ) {
		$key = fisha_studio_var_key( $v->get_id(), 'enabled' );
		fisha_studio_draft_save( wc_get_product( $p->get_id() ), array( $key => true ), array( $key => false ) );
	}
	return $v;
}

/** Creates every missing combination of the options used for sizes (at most 50 at once). @return int[]|WP_Error new size ids */
function fisha_studio_variation_generate( WC_Product $p, array $attributes ) {
	$axes = array();
	foreach ( $attributes as $a ) {
		if ( empty( $a['variation'] ) ) continue;
		$vals = array();
		foreach ( (array) $a['options'] as $o ) {
			if ( $a['taxonomy'] ) {
				$t = get_term( (int) $o, $a['name'] );
				if ( $t && ! is_wp_error( $t ) ) $vals[] = $t->slug;
			} else {
				$vals[] = (string) $o;
			}
		}
		if ( $vals ) $axes[ sanitize_title( $a['name'] ) ] = $vals;
	}
	if ( ! $axes ) return new WP_Error( 'fisha_studio_invalid', 'Choose the values of an option used for sizes first, for example S, M and L.', array( 'status' => 422 ) );
	$combos = array( array() );
	foreach ( $axes as $key => $vals ) {
		$next = array();
		foreach ( $combos as $c ) foreach ( $vals as $val ) $next[] = $c + array( $key => (string) $val );
		$combos = $next;
	}
	if ( count( $combos ) > 50 ) {
		return new WP_Error( 'fisha_studio_invalid', sprintf( 'That would make %d sizes. Up to 50 can be created at once; choose fewer values.', count( $combos ) ), array( 'status' => 422 ) );
	}
	$have = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v ) $have[ fisha_studio_combo_key( $v->get_attributes( 'edit' ) ) ] = true;
	}
	$new = array();
	foreach ( $combos as $c ) {
		if ( isset( $have[ fisha_studio_combo_key( $c ) ] ) ) continue;
		$new[] = fisha_studio_variation_create( wc_get_product( $p->get_id() ), $c )->get_id();
	}
	return $new;
}

/** Deletes a size that never went live. @return true|WP_Error */
function fisha_studio_variation_remove( WC_Product $p, int $vid ) {
	$v = wc_get_product( $vid );
	if ( ! $v || $v->get_parent_id() !== $p->get_id() ) return new WP_Error( 'fisha_studio_not_found', 'This size no longer exists.', array( 'status' => 404 ) );
	if ( ! fisha_studio_size_deletable( $p, $vid ) ) {
		return new WP_Error( 'fisha_studio_invalid', 'A size that was in the shop or has orders cannot be deleted. Switch it off instead.', array( 'status' => 409 ) );
	}
	$d      = fisha_studio_draft_get( $p->get_id() );
	$prefix = "var:$vid:";
	foreach ( array_keys( $d['fields'] ) as $k ) {
		if ( 0 === strpos( (string) $k, $prefix ) ) unset( $d['fields'][ $k ], $d['base'][ $k ] );
	}
	fisha_studio_draft_put( $p->get_id(), $d );
	wp_delete_post( $vid, true );
	return true;
}

/** Deletes the sizes added in the Studio that were never published (on Discard). */
function fisha_studio_variation_discard_pending( int $pid ): void {
	$ids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $pid, 'post_status' => array( 'publish', 'private' ), 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => FISHA_STUDIO_PENDING_META, 'meta_value' => '1' ) );
	foreach ( $ids as $vid ) {
		if ( ! fisha_studio_has_orders( $pid, (int) $vid ) ) wp_delete_post( (int) $vid, true ); // a sold size is never deleted
	}
}

/** Switches a product that was never live between "one item" (simple) and "with sizes" (variable). @return WC_Product|WP_Error */
function fisha_studio_set_product_type( WC_Product $p, string $type ) {
	if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) return new WP_Error( 'fisha_studio_invalid', 'Unknown product type.', array( 'status' => 422 ) );
	if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product can only be edited in WP admin.', array( 'status' => 400 ) );
	if ( ! fisha_studio_is_new( $p ) || ! fisha_studio_never_live( $p ) ) {
		return new WP_Error( 'fisha_studio_invalid', 'A product that has been in the shop keeps its type.', array( 'status' => 409 ) );
	}
	if ( $p->get_type() === $type ) return $p;
	$id = $p->get_id();
	if ( 'simple' === $type ) {
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'fields' => 'ids' ) ) as $vid ) wp_delete_post( (int) $vid, true );
		$p->set_attributes( array() );
		// One item starts on "Fixed amount" again.
		$p->set_manage_stock( true );
		$p->set_backorders( 'no' );
		$p->save();
	} else {
		// With sizes, stock is counted per size unless the owner chooses to count the whole product.
		$p->set_manage_stock( false );
		$p->save();
	}
	wp_set_object_terms( $id, $type, 'product_type' );
	wc_delete_product_transients( $id );
	clean_post_cache( $id );
	if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
	return wc_get_product( $id );
}

/* ---------- checks before publishing ---------- */

/** Extra checks for a product with sizes ($v = live values merged with the draft). Errors keyed by field. */
function fisha_studio_variable_validate( array $v, WC_Product $p ): array {
	$e    = array();
	$axes = array();
	foreach ( (array) ( $v['attributes'] ?? array() ) as $a ) {
		if ( empty( $a['variation'] ) ) continue;
		if ( ! $a['options'] ) {
			$e['attributes'] = sprintf( 'Pick at least one value for %s.', $a['label'] );
			continue;
		}
		$vals = array();
		foreach ( $a['options'] as $o ) {
			if ( $a['taxonomy'] ) {
				$t = get_term( (int) $o, $a['name'] );
				if ( $t && ! is_wp_error( $t ) ) $vals[] = $t->slug;
			} else {
				$vals[] = (string) $o;
			}
		}
		$axes[ sanitize_title( $a['name'] ) ] = $vals;
	}
	if ( ! $axes && ! isset( $e['attributes'] ) ) $e['attributes'] = 'Choose the option shoppers pick from, such as Size, and its values.';

	$on     = 0;
	$combos = array();
	$skus   = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$k = function ( string $f ) use ( $vid ) { return fisha_studio_var_key( $vid, $f ); };
		if ( empty( $v[ $k( 'enabled' ) ] ) ) continue;
		$on++;
		$var   = wc_get_product( $vid );
		$attrs = $var ? $var->get_attributes( 'edit' ) : array();
		foreach ( $axes as $name => $vals ) {
			$val = (string) ( $attrs[ $name ] ?? '' );
			if ( '' !== $val && ! in_array( $val, $vals, true ) ) { // '' = "any value", allowed
				$e[ $k( 'enabled' ) ] = 'This size uses a value that is no longer chosen above. Switch it off or add the value back.';
				break;
			}
		}
		$combo = fisha_studio_combo_key( array_intersect_key( $attrs, $axes ) );
		if ( isset( $combos[ $combo ] ) ) $e[ $k( 'enabled' ) ] = 'Two sizes that are on have the same values. Switch one off.';
		$combos[ $combo ] = true;
		$reg  = (string) ( $v[ $k( 'regular_price' ) ] ?? '' );
		$sale = (string) ( $v[ $k( 'sale_price' ) ] ?? '' );
		if ( '' === $reg ) $e[ $k( 'regular_price' ) ] = 'Each size that is on needs a price.';
		elseif ( '' !== $sale && (float) $sale >= (float) $reg ) $e[ $k( 'sale_price' ) ] = 'The sale price must be lower than the normal price.';
		if ( ! empty( $v[ $k( 'manage_stock' ) ] ) && null === ( $v[ $k( 'stock_quantity' ) ] ?? null ) ) $e[ $k( 'stock_quantity' ) ] = 'Enter how many are in stock.';
		$sku = (string) ( $v[ $k( 'sku' ) ] ?? '' );
		if ( '' !== $sku ) {
			if ( isset( $skus[ $sku ] ) || $sku === (string) ( $v['sku'] ?? '' ) || ! wc_product_has_unique_sku( $vid, $sku ) ) $e[ $k( 'sku' ) ] = 'Another product or size already uses this SKU.';
			$skus[ $sku ] = true;
		}
	}
	if ( ! $on ) $e['variations'] = 'Switch on at least one size and give it a price.';
	return $e;
}

/* ---------- quick edit of one size ---------- */

/** Inventory quick edit of one size: refuses if it moved since the owner's list loaded, saves at once, logs it. @return WC_Product_Variation|WP_Error */
function fisha_studio_variation_quick( int $vid, array $input, array $seen_input ) {
	$v = wc_get_product( $vid );
	$p = ( $v && $v->is_type( 'variation' ) ) ? wc_get_product( $v->get_parent_id() ) : null;
	if ( ! $p || 'trash' === get_post_status( $p->get_id() ) ) return new WP_Error( 'fisha_studio_not_found', 'This size no longer exists.', array( 'status' => 404 ) );
	if ( fisha_studio_is_new( $p ) || '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true ) ) {
		return new WP_Error( 'fisha_studio_unsupported', 'Open this product to edit it.', array( 'status' => 400 ) );
	}
	if ( fisha_studio_draft_get( $p->get_id() )['fields'] ) {
		return new WP_Error( 'fisha_studio_has_draft', 'This product has unpublished changes. Open it to review and publish them together.', array( 'status' => 409 ) );
	}
	$defs = array_intersect_key( fisha_studio_variation_fields(), array_flip( array( 'regular_price', 'stock_quantity', 'stock_status' ) ) );
	list( $values, $errors ) = fisha_studio_sanitize( $defs, $input );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', reset( $errors ), array( 'status' => 422, 'fields' => $errors ) );
	if ( ! $values ) return $v;
	// A number and a stock status together: the number wins (it is the "Fixed amount" choice).
	if ( array_key_exists( 'stock_quantity', $values ) ) unset( $values['stock_status'] );

	// Every field sent must come with the value the owner saw; without it the change cannot be checked.
	list( $seen, $seen_errors ) = fisha_studio_sanitize( $defs, $seen_input );
	$missing = array();
	foreach ( array_keys( $values ) as $f ) {
		if ( ! array_key_exists( $f, $seen ) || array_key_exists( $f, $seen_errors ) ) $missing[ $f ] = 'Please reload the list and try again.';
	}
	if ( $missing ) return new WP_Error( 'fisha_studio_invalid', 'Please reload the list and try again.', array( 'status' => 422, 'fields' => $missing ) );
	$seen = array_intersect_key( $seen, $values );

	$conflicts_of = function ( WC_Product $cur ) use ( $defs, $seen, $values ): array {
		$out = array();
		foreach ( $seen as $f => $was ) {
			$live = fisha_studio_clean( $defs[ $f ], call_user_func( $defs[ $f ]['get'], $cur ) )[0];
			if ( ! fisha_studio_same( $was, $live ) ) {
				$out[] = array( 'field' => $f, 'label' => fisha_studio_variation_label( $cur ) . ': ' . $defs[ $f ]['label'], 'base' => $was, 'live' => $live, 'mine' => $values[ $f ] );
			}
		}
		return $out;
	};
	$refused = function ( array $conflicts ) {
		return new WP_Error( 'fisha_studio_conflict', 'This changed on the site since the list was loaded, so nothing was changed.', array( 'status' => 409, 'conflicts' => $conflicts ) );
	};
	if ( $conflicts = $conflicts_of( $v ) ) return $refused( $conflicts );

	$invalid = function ( string $f, string $msg ) { return new WP_Error( 'fisha_studio_invalid', $msg, array( 'status' => 422, 'fields' => array( $f => $msg ) ) ); };
	if ( array_key_exists( 'regular_price', $values ) ) {
		if ( '' === $values['regular_price'] && 'publish' === $v->get_status( 'edit' ) ) return $invalid( 'regular_price', 'A size that is on needs a price.' );
		$sale = (string) $v->get_sale_price( 'edit' );
		if ( '' !== $sale && '' !== $values['regular_price'] && (float) $sale >= (float) $values['regular_price'] ) return $invalid( 'regular_price', 'This size has a sale price that would be higher. Open the product to change both.' );
	}
	$whole = true !== $v->get_manage_stock( 'edit' ) && true === $p->get_manage_stock( 'edit' );
	foreach ( array( 'stock_quantity', 'stock_status' ) as $f ) {
		if ( $whole && array_key_exists( $f, $values ) ) return $invalid( $f, 'Stock is kept for the whole product. Change it on the product.' );
	}
	// The stock choice: a number means "Fixed amount" (counting on, never sold beyond it); a stock status means
	// "In stock", "Made to order" or "Sold out" (counting off).
	$counting = null;
	if ( array_key_exists( 'stock_quantity', $values ) ) {
		if ( null === $values['stock_quantity'] ) return $invalid( 'stock_quantity', 'Enter how many are in stock.' );
		if ( 0 === $values['stock_quantity'] ) {
			// A fixed amount is 1 or more: 0 means Sold out (counting off).
			unset( $values['stock_quantity'] );
			$values['stock_status'] = 'outofstock';
			if ( true === $v->get_manage_stock( 'edit' ) ) $counting = false;
		} elseif ( true !== $v->get_manage_stock( 'edit' ) ) {
			$counting = true;
		}
	} elseif ( array_key_exists( 'stock_status', $values ) && true === $v->get_manage_stock( 'edit' ) ) {
		$counting = false;
	}

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		// Lock the stock row so an order placed now waits for this save; then check the seen values again.
		if ( array_key_exists( 'stock_quantity', $values ) || null !== $counting ) {
			$wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE", $vid ) );
		}
		do_action( 'fisha_studio_quick_before_recheck', $vid );
		clean_post_cache( $vid );
		wc_delete_product_transients( $vid );
		if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $vid );
		$cur       = wc_get_product( $vid );
		$conflicts = $conflicts_of( $cur );
		if ( $conflicts ) {
			$wpdb->query( 'ROLLBACK' );
			return $refused( $conflicts );
		}
		$logged = $defs + array_intersect_key( fisha_studio_variation_fields(), array_flip( array( 'manage_stock', 'backorders' ) ) );
		$live   = array();
		foreach ( $logged as $f => $def ) $live[ $f ] = fisha_studio_clean( $def, call_user_func( $def['get'], $cur ) )[0];
		if ( null !== $counting ) $cur->set_manage_stock( $counting );
		foreach ( $values as $f => $x ) call_user_func( $defs[ $f ]['set'], $cur, $x );
		// "Fixed amount" never sells beyond the count, also for a size that allowed backorders before.
		if ( true === $cur->get_manage_stock( 'edit' ) ) $cur->set_backorders( 'no' );
		$cur->save();
		WC_Product_Variable::sync( $p->get_id() );
		$fresh = wc_get_product( $vid );
		$after = array();
		foreach ( $logged as $f => $def ) $after[ $f ] = fisha_studio_clean( $def, call_user_func( $def['get'], $fresh ) )[0];
		if ( ! fisha_studio_log( 'variation', $vid, 'quick', $live, $after ) ) throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_post_cache( $vid );
		clean_post_cache( $p->get_id() );
		wc_delete_product_transients( $p->get_id() );
		fisha_studio_log_error( 'quick size ' . $vid, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Saving failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}
	fisha_studio_purge_product( wc_get_product( $p->get_id() ) );
	return wc_get_product( $vid );
}

/* ---------- pre-live data check ---------- */

/** Every published or hidden product with sizes that would not pass the publish checks: [{id, name, problems: [label => message]}]. */
function fisha_studio_check_sizes(): array {
	$out = array();
	$ids = get_posts( array(
		'post_type' => 'product', 'post_status' => array( 'publish', 'private' ), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
		'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'variable' ) ),
	) );
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p || ! $p->is_type( 'variable' ) ) continue;
		$errors = fisha_studio_variable_validate( fisha_studio_product_values( $p ), $p );
		if ( ! $errors ) continue;
		$problems = array();
		foreach ( $errors as $key => $msg ) $problems[] = fisha_studio_value_label( $p, (string) $key ) . ': ' . $msg;
		$out[] = array( 'id' => (int) $id, 'name' => $p->get_name( 'edit' ), 'problems' => $problems );
	}
	return $out;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/** wp fisha-studio check-sizes: lists live products with sizes the Studio would refuse to publish as they are. Always exits 0. */
	WP_CLI::add_command( 'fisha-studio check-sizes', function () {
		$found = fisha_studio_check_sizes();
		foreach ( $found as $row ) {
			WP_CLI::log( sprintf( '#%d %s', $row['id'], $row['name'] ) );
			foreach ( $row['problems'] as $line ) WP_CLI::log( '  - ' . $line );
		}
		WP_CLI::log( sprintf( '%d product%s with sizes need%s a look.', count( $found ), 1 === count( $found ) ? '' : 's', 1 === count( $found ) ? 's' : '' ) );
	} );
}
