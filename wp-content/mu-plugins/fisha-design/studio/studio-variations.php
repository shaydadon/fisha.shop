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
		'stock_quantity' => $prop( 'stock_quantity', 'int', array( 'label' => 'Stock', 'nullable' => true, 'min' => 0 ) ),
		'stock_status'   => $prop( 'stock_status', 'enum', array( 'label' => 'Stock status', 'options' => array( 'instock', 'outofstock', 'onbackorder' ) ) ),
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
			'label'     => $tax ? wc_attribute_label( $a->get_name() ) : $a->get_name(),
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
				if ( '' !== $o && ! in_array( $o, $opts, true ) ) $opts[] = $o;
			}
		}
		$out[] = array(
			'name'      => $name,
			'label'     => $tax ? wc_attribute_label( $name ) : $name,
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
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) $list[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
		$out[] = array( 'id' => (int) $a->attribute_id, 'name' => $tax, 'label' => $a->attribute_label, 'terms' => $list );
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
	$label = wc_get_formatted_variation( $v, true, false );
	return '' !== $label ? $label : '#' . $v->get_id();
}

/** A plain-English name for any edited key ("M: Stock", "Price"). */
function fisha_studio_value_label( WC_Product $p, string $key ): string {
	if ( 'attributes' === $key ) return 'Sizes and options';
	$parsed = fisha_studio_parse_var_key( $key );
	if ( ! $parsed ) return fisha_studio_product_fields()[ $key ]['label'] ?? $key;
	$v = wc_get_product( $parsed[0] );
	return ( $v ? fisha_studio_variation_label( $v ) . ': ' : '' ) . fisha_studio_variation_fields()[ $parsed[1] ]['label'];
}

/* ---------- creating and removing sizes ---------- */

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
	if ( ! $is_new ) $v->update_meta_data( FISHA_STUDIO_PENDING_META, '1' );
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
	$pending = '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true );
	if ( ! $pending && ! fisha_studio_is_new( $p ) ) {
		return new WP_Error( 'fisha_studio_invalid', 'A size that is already in the shop cannot be deleted. Switch it off instead.', array( 'status' => 409 ) );
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
	foreach ( $ids as $vid ) wp_delete_post( (int) $vid, true );
}

/** Switches a never-published product between "one item" (simple) and "with sizes" (variable). @return WC_Product|WP_Error */
function fisha_studio_set_product_type( WC_Product $p, string $type ) {
	if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) return new WP_Error( 'fisha_studio_invalid', 'Unknown product type.', array( 'status' => 422 ) );
	if ( ! fisha_studio_is_new( $p ) ) return new WP_Error( 'fisha_studio_invalid', 'A product that is already in the shop keeps its type.', array( 'status' => 409 ) );
	if ( $p->get_type() === $type ) return $p;
	$id = $p->get_id();
	if ( 'simple' === $type ) {
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'fields' => 'ids' ) ) as $vid ) wp_delete_post( (int) $vid, true );
		$p->set_attributes( array() );
		$p->save();
	}
	wp_set_object_terms( $id, $type, 'product_type' );
	wc_delete_product_transients( $id );
	clean_post_cache( $id );
	if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
	return wc_get_product( $id );
}
