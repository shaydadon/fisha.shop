<?php
/**
 * Fisha Studio field schema: every product field the site reads (spec §2.3), with how to read it, write it,
 * clean it and check it. The REST API, drafts, preview and publish all work from this one list.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_product_fields(): array {
	static $fields = null;
	if ( null !== $fields ) return $fields;

	$prop = function ( string $prop, string $type, array $extra = array() ): array {
		return array_merge( array(
			'type' => $type,
			'get'  => function ( WC_Product $p ) use ( $prop ) { return $p->{"get_$prop"}( 'edit' ); },
			'set'  => function ( WC_Product $p, $v ) use ( $prop ) { $p->{"set_$prop"}( $v ); },
		), $extra );
	};
	$meta = function ( string $key, string $type, array $extra = array() ): array {
		return array_merge( array(
			'type' => $type,
			'meta' => $key,
			'get'  => function ( WC_Product $p ) use ( $key ) { return $p->get_meta( $key, true, 'edit' ); },
			'set'  => function ( WC_Product $p, $v ) use ( $key ) {
				if ( '' === $v || false === $v || null === $v ) $p->delete_meta_data( $key );
				else $p->update_meta_data( $key, true === $v ? '1' : $v );
			},
		), $extra );
	};

	$fields = array(
		// Basics
		'name'               => $prop( 'name', 'text', array( 'label' => 'Name', 'max' => 120, 'required' => true ) ),
		'slug'               => $prop( 'slug', 'slug', array( 'label' => 'Address' ) ),
		'status'             => $prop( 'status', 'enum', array( 'label' => 'Visibility', 'options' => array( 'publish', 'private', 'draft' ) ) ),
		'catalog_visibility' => $prop( 'catalog_visibility', 'enum', array( 'label' => 'Where it shows', 'options' => array( 'visible', 'catalog', 'search', 'hidden' ) ) ),
		'featured'           => $prop( 'featured', 'bool', array( 'label' => 'Featured' ) ),
		'category_ids'       => $prop( 'category_ids', 'ids', array( 'label' => 'Categories', 'taxonomy' => 'product_cat', 'required' => true ) ),
		'tag_ids'            => $prop( 'tag_ids', 'ids', array( 'label' => 'Tags', 'taxonomy' => 'product_tag' ) ),
		'short_description'  => $prop( 'short_description', 'html', array( 'label' => 'Short description', 'required' => true ) ),
		'description'        => $prop( 'description', 'html', array( 'label' => 'Full description' ) ),
		'reviews_allowed'    => $prop( 'reviews_allowed', 'bool', array( 'label' => 'Reviews' ) ),
		'menu_order'         => $prop( 'menu_order', 'int', array( 'label' => 'Order' ) ),
		// Price
		'regular_price'      => $prop( 'regular_price', 'money', array( 'label' => 'Price' ) ),
		'sale_price'         => $prop( 'sale_price', 'money', array( 'label' => 'Sale price' ) ),
		'date_on_sale_from'  => $prop( 'date_on_sale_from', 'date', array( 'label' => 'Sale starts',
			'set' => function ( WC_Product $p, $v ) { $p->set_date_on_sale_from( $v ? $v . ' 00:00:00' : null ); } ) ),
		'date_on_sale_to'    => $prop( 'date_on_sale_to', 'date', array( 'label' => 'Sale ends',
			'set' => function ( WC_Product $p, $v ) { $p->set_date_on_sale_to( $v ? $v . ' 23:59:59' : null ); } ) ),
		// Inventory
		'sku'                => $prop( 'sku', 'text', array( 'label' => 'SKU', 'max' => 64 ) ),
		'manage_stock'       => $prop( 'manage_stock', 'bool', array( 'label' => 'Track stock' ) ),
		'stock_quantity'     => $prop( 'stock_quantity', 'int', array( 'label' => 'Stock', 'nullable' => true, 'min' => 0 ) ),
		'stock_status'       => $prop( 'stock_status', 'enum', array( 'label' => 'Stock status', 'options' => array( 'instock', 'outofstock', 'onbackorder' ) ) ),
		'low_stock_amount'   => $prop( 'low_stock_amount', 'int', array( 'label' => 'Low stock alert at', 'nullable' => true, 'min' => 0 ) ),
		'backorders'         => $prop( 'backorders', 'enum', array( 'label' => 'Orders when sold out', 'options' => array( 'no', 'notify', 'yes' ) ) ),
		'sold_individually'  => $prop( 'sold_individually', 'bool', array( 'label' => 'One per order' ) ),
		// Images
		'image_id'           => $prop( 'image_id', 'attachment', array( 'label' => 'Main image', 'required' => true ) ),
		'gallery_image_ids'  => $prop( 'gallery_image_ids', 'attachments', array( 'label' => 'Gallery' ) ),
		// Shipping & tax
		'weight'             => $prop( 'weight', 'decimal', array( 'label' => 'Weight' ) ),
		'length'             => $prop( 'length', 'decimal', array( 'label' => 'Length' ) ),
		'width'              => $prop( 'width', 'decimal', array( 'label' => 'Width' ) ),
		'height'             => $prop( 'height', 'decimal', array( 'label' => 'Height' ) ),
		'shipping_class_id'  => $prop( 'shipping_class_id', 'int', array( 'label' => 'Shipping class', 'min' => 0 ) ),
		'tax_status'         => $prop( 'tax_status', 'enum', array( 'label' => 'Tax', 'options' => array( 'taxable', 'shipping', 'none' ) ) ),
		'tax_class'          => $prop( 'tax_class', 'enum', array( 'label' => 'Tax class', 'options' => function () { return array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ); } ) ),
		// Fisha design (read by fisha_product_card(), fisha_series_picker() and shop-home.php)
		'card_bg'            => $meta( '_fisha_card_bg', 'color', array( 'label' => 'Card background' ) ),
		'meta_line'          => $meta( '_fisha_colour', 'text', array( 'label' => 'Line under the name', 'max' => 80 ) ),
		'series'             => $meta( '_fisha_series', 'slug', array( 'label' => 'Design series' ) ),
		'hero_skip'          => $meta( '_fisha_hero_skip', 'bool', array( 'label' => 'Keep out of the Shop hero' ) ),
		// SEO (read by seo.php)
		'seo_title'          => $meta( 'fisha_seo_title', 'text', array( 'label' => 'SEO title', 'max' => 70 ) ),
		'seo_desc'           => $meta( 'fisha_seo_desc', 'text', array( 'label' => 'SEO description', 'max' => 170 ) ),
	);
	return $fields;
}

/** Cleans one value for a field definition. Returns [clean value, error message or null]. */
function fisha_studio_clean( array $def, $v ): array {
	$label = $def['label'] ?? 'This field';
	switch ( $def['type'] ) {
		case 'text':
			$v = trim( sanitize_text_field( (string) $v ) );
			if ( isset( $def['max'] ) && mb_strlen( $v ) > $def['max'] ) return array( $v, sprintf( '%s can be at most %d characters.', $label, $def['max'] ) );
			return array( $v, null );

		case 'html':
			static $allowed = null;
			if ( null === $allowed ) {
				$allowed = wp_kses_allowed_html( 'post' );
				foreach ( $allowed as $tag => $attrs ) {
					if ( is_array( $attrs ) ) unset( $allowed[ $tag ]['style'], $allowed[ $tag ]['class'], $allowed[ $tag ]['id'] );
				}
			}
			return array( trim( wp_kses( (string) $v, $allowed ) ), null );

		case 'slug':
			return array( sanitize_title( (string) $v ), null );

		case 'money':
		case 'decimal':
			$raw = trim( (string) $v );
			$v   = preg_replace( '/[^\d.,\-]/u', '', $raw );                // drop ₪, spaces and other symbols
			if ( '' === $v ) return '' === $raw ? array( '', null ) : array( $raw, sprintf( '%s must be a number of 0 or more.', $label ) );
			if ( preg_match( '/^\d{1,3}(,\d{3})+(\.\d+)?$/', $v ) ) $v = str_replace( ',', '', $v );  // 2,200 → 2200
			$v = str_replace( ',', '.', $v );                                  // 12,50 → 12.50
			if ( ! is_numeric( $v ) || (float) $v < 0 ) return array( $v, sprintf( '%s must be a number of 0 or more.', $label ) );
			return array( wc_format_decimal( $v, 'money' === $def['type'] ? wc_get_price_decimals() : false, true ), null );

		case 'int':
			if ( ( '' === $v || null === $v ) && ! empty( $def['nullable'] ) ) return array( null, null );
			if ( ! is_numeric( $v ) || (float) $v !== (float) (int) $v ) return array( $v, sprintf( '%s must be a whole number.', $label ) );
			$v = (int) $v;
			if ( isset( $def['min'] ) && $v < $def['min'] ) return array( $v, sprintf( '%s must be %d or more.', $label, $def['min'] ) );
			return array( $v, null );

		case 'bool':
			return array( in_array( $v, array( true, 1, '1', 'yes', 'true', 'on' ), true ), null );

		case 'enum':
			$options = is_callable( $def['options'] ) ? call_user_func( $def['options'] ) : $def['options'];
			$v       = (string) $v;
			return in_array( $v, $options, true ) ? array( $v, null ) : array( $v, sprintf( '%s has a value the shop does not know.', $label ) );

		case 'color':
			if ( '' === (string) $v ) return array( '', null );
			$c = sanitize_hex_color( (string) $v );
			return $c ? array( strtolower( $c ), null ) : array( $v, sprintf( '%s must be a colour like #efe8dc.', $label ) );

		case 'date':
			if ( $v instanceof WC_DateTime ) return array( $v->date( 'Y-m-d' ), null );
			$v = trim( (string) $v );
			if ( '' === $v ) return array( '', null );
			$d = DateTime::createFromFormat( '!Y-m-d', $v );
			return ( $d && $d->format( 'Y-m-d' ) === $v ) ? array( $v, null ) : array( $v, sprintf( '%s must be a real date.', $label ) );

		case 'attachment':
			$id = absint( $v );
			if ( $id && ! wp_attachment_is_image( $id ) ) return array( $id, sprintf( '%s must be an uploaded image.', $label ) );
			return array( $id, null );

		case 'attachments':
		case 'ids':
			if ( is_string( $v ) ) $v = '' === $v ? array() : explode( ',', $v );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
			if ( 'attachments' === $def['type'] ) {
				foreach ( $ids as $id ) if ( ! wp_attachment_is_image( $id ) ) return array( $ids, sprintf( '%s can only contain uploaded images.', $label ) );
				return array( $ids, null );
			}
			sort( $ids );
			foreach ( $ids as $id ) {
				$t = get_term( $id, $def['taxonomy'] );
				if ( ! $t || is_wp_error( $t ) ) return array( $ids, sprintf( '%s contains something that no longer exists.', $label ) );
			}
			return array( $ids, null );
	}
	return array( $v, 'Unknown field type.' );
}

/** Live values of every field, cleaned to the same shapes the Studio sends. */
function fisha_studio_product_read( WC_Product $p ): array {
	$out = array();
	foreach ( fisha_studio_product_fields() as $key => $def ) {
		$out[ $key ] = fisha_studio_clean( $def, call_user_func( $def['get'], $p ) )[0];
	}
	return $out;
}

/** Cleans the known keys of $input. Returns [values, errors] (errors keyed by field). */
function fisha_studio_sanitize( array $schema, array $input ): array {
	$values = array();
	$errors = array();
	foreach ( $input as $key => $v ) {
		if ( ! isset( $schema[ $key ] ) ) continue;
		list( $clean, $err ) = fisha_studio_clean( $schema[ $key ], $v );
		$values[ $key ] = $clean;
		if ( $err ) $errors[ $key ] = $err;
	}
	return array( $values, $errors );
}

/** Sets values on the product object (does not save). */
function fisha_studio_product_apply( WC_Product $p, array $values ): void {
	$schema = fisha_studio_product_fields();
	foreach ( $values as $key => $v ) {
		if ( isset( $schema[ $key ] ) ) call_user_func( $schema[ $key ]['set'], $p, $v );
	}
}

/** Checks a complete set of values (live merged with draft). Returns errors keyed by field. */
function fisha_studio_product_validate( array $v, WC_Product $p ): array {
	$e = array();
	foreach ( fisha_studio_product_fields() as $key => $def ) {
		if ( empty( $def['required'] ) ) continue;
		$val = $v[ $key ] ?? null;
		if ( '' === $val || null === $val || 0 === $val || array() === $val ) $e[ $key ] = sprintf( '%s is needed before publishing.', $def['label'] );
	}
	// WooCommerce's default category ("Uncategorized") on its own is a placeholder, not a choice.
	$def = (int) get_option( 'default_product_cat' );
	if ( ! isset( $e['category_ids'] ) && $def && array( $def ) === array_map( 'intval', (array) ( $v['category_ids'] ?? array() ) ) ) {
		$t = get_term( $def, 'product_cat' );
		$e['category_ids'] = sprintf( 'Pick a category other than "%s" before publishing.', ( $t && ! is_wp_error( $t ) ) ? $t->name : 'Uncategorized' );
	}
	$sale    = (string) ( $v['sale_price'] ?? '' );
	$regular = (string) ( $v['regular_price'] ?? '' );
	if ( '' !== $sale ) {
		if ( '' === $regular ) $e['sale_price'] = 'Set the normal price before a sale price.';
		elseif ( (float) $sale >= (float) $regular ) $e['sale_price'] = 'The sale price must be lower than the normal price.';
	}
	$from = (string) ( $v['date_on_sale_from'] ?? '' );
	$to   = (string) ( $v['date_on_sale_to'] ?? '' );
	if ( $from && $to && $from > $to ) $e['date_on_sale_to'] = 'The sale must end after it starts.';
	$sku = (string) ( $v['sku'] ?? '' );
	if ( '' !== $sku && ! wc_product_has_unique_sku( $p->get_id(), $sku ) ) $e['sku'] = 'Another product already uses this SKU.';
	if ( ! empty( $v['manage_stock'] ) && null === ( $v['stock_quantity'] ?? null ) ) $e['stock_quantity'] = 'Enter how many are in stock.';
	// Only a new or changed address is checked: some existing slugs predate these rules and must keep working.
	$slug   = (string) ( $v['slug'] ?? '' );
	$is_new = in_array( get_post_status( $p->get_id() ), array( 'draft', 'auto-draft', 'pending' ), true );
	if ( $is_new || $slug !== $p->get_slug( 'edit' ) ) {
		$slug_error = fisha_studio_slug_error( $slug );
		if ( $slug_error ) $e['slug'] = $slug_error;
	}
	return $e;
}

/** Drops WooCommerce's default category ("Uncategorized") from a list that also has a real category. */
function fisha_studio_drop_default_cat( array $ids ): array {
	$def = (int) get_option( 'default_product_cat' );
	if ( ! $def || count( $ids ) < 2 || ! in_array( $def, array_map( 'intval', $ids ), true ) ) return $ids;
	return array_values( array_filter( $ids, function ( $id ) use ( $def ) { return (int) $id !== $def; } ) );
}

function fisha_studio_same( $a, $b ): bool {
	return wp_json_encode( $a ) === wp_json_encode( $b );
}
