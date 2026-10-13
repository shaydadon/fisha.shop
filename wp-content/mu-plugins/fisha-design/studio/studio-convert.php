<?php
/**
 * Fisha Studio: converts a product with sizes made in WP admin to a normal one (wp fisha-studio convert-sizes).
 * Its own "Size" option moves to the shop-wide Size (pa_size), quotes around its own option values are removed,
 * and stock is chosen per size instead of counted for the whole product. Other options keep their values.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** The value without one pair of wrapping double quotes ('"XS"' => 'XS'); other values unchanged. */
function fisha_studio_unquote( string $v ): string {
	return ( strlen( $v ) >= 2 && '"' === $v[0] && '"' === substr( $v, -1 ) ) ? substr( $v, 1, -1 ) : $v;
}

/** The pa_size value with this name (case-insensitive), or null. */
function fisha_studio_size_term_named( string $name ): ?WP_Term {
	$terms = get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) );
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		if ( 0 === strcasecmp( $t->name, $name ) ) return $t;
	}
	return null;
}

/** "XS=S, S=M" => ['XS' => 'S', 'S' => 'M']. @return array|WP_Error */
function fisha_studio_parse_size_map( string $text ) {
	$map = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', $text ) ), 'strlen' ) as $pair ) {
		$parts = array_map( 'trim', explode( '=', $pair ) );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) return new WP_Error( 'fisha_studio_invalid', "\"$pair\" is not in the form OLD=NEW, for example XS=S." );
		$map[ $parts[0] ] = $parts[1];
	}
	return $map;
}

/** A size's stored values as text: size = "XS", color = "White". */
function fisha_studio_attrs_text( array $attrs ): string {
	$parts = array();
	foreach ( $attrs as $k => $v ) $parts[] = $k . ' = ' . $v;
	return implode( ', ', $parts );
}

function fisha_studio_convert_clear( int $id, array $vids ): void {
	foreach ( array_merge( array( $id ), $vids ) as $x ) {
		clean_post_cache( $x );
		wc_delete_product_transients( $x );
		if ( class_exists( 'WC_Cache_Helper' ) ) WC_Cache_Helper::invalidate_cache_group( 'product_' . $x );
	}
	clean_object_term_cache( $id, 'product' );
}

/**
 * Converts product $id (see the file comment). $map renames Size values before they move to the shop sizes, all at
 * once (['XS' => 'S', 'S' => 'M'] turns XS into S and S into M; matched case-insensitively, without quotes).
 * Prints nothing; returns the plan { product_id, changes: plain-English lines, applied: whether it was written }.
 * An already converted product has no changes.
 * @return array|WP_Error
 */
function fisha_studio_convert_sizes( int $id, bool $dry_run, array $map = array() ) {
	$p = wc_get_product( $id );
	if ( ! $p || ! $p->is_type( 'variable' ) ) return new WP_Error( 'fisha_studio_unsupported', "Product $id is not a product with sizes. Nothing was changed." );

	$changes  = array();
	$attrs    = $p->get_attributes( 'edit' );
	$size_key = null;
	$unquoted = array(); // attribute key => unquoted options (the product's own options only)
	foreach ( $attrs as $key => $a ) {
		if ( ! $a instanceof WC_Product_Attribute || $a->is_taxonomy() ) continue;
		$raw  = array_map( 'strval', $a->get_options() );
		$opts = array_map( 'fisha_studio_unquote', $raw );
		$unquoted[ $key ] = $opts;
		if ( null === $size_key && 0 === strcasecmp( $a->get_name(), 'size' ) ) {
			$size_key = $key;
		} elseif ( $opts !== $raw ) {
			$changes[] = sprintf( 'Option "%s": remove the quotes around its values (%s), everything else stays.', $a->get_name(), implode( ', ', $opts ) );
		}
	}
	// Without its own Size option (already converted) there is nothing to rename, so $map is not used.
	if ( null !== $size_key && ! taxonomy_exists( 'pa_size' ) ) return new WP_Error( 'fisha_studio_unsupported', 'The shop-wide Size option (pa_size) does not exist. Nothing was changed.' );

	// Size value (unquoted) => the name of the shop size it becomes; shop size name => its term (null = created).
	$target  = array();
	$targets = array();
	if ( null !== $size_key ) {
		$lower = array();
		foreach ( $map as $from => $to ) $lower[ strtolower( fisha_studio_unquote( trim( (string) $from ) ) ) ] = fisha_studio_unquote( trim( (string) $to ) );
		$used = array();
		foreach ( $unquoted[ $size_key ] as $name ) {
			$to = $lower[ strtolower( $name ) ] ?? $name;
			unset( $lower[ strtolower( $name ) ] );
			if ( isset( $used[ strtolower( $to ) ] ) ) return new WP_Error( 'fisha_studio_invalid', "Two sizes would both become \"$to\". Nothing was changed." );
			$used[ strtolower( $to ) ] = true;
			$target[ $name ] = $to;
			$t = fisha_studio_size_term_named( $to );
			$targets[ $to ] = $t;
			$from = 0 === strcasecmp( $name, $to ) ? "Size $name" : "Size $name becomes $to";
			$changes[] = $t
				? sprintf( '%s: the shop size "%s" (%s).', $from, $t->name, $t->slug )
				: sprintf( '%s: add "%s" to the shop sizes, after the last one.', $from, $to );
		}
		if ( $lower ) return new WP_Error( 'fisha_studio_invalid', sprintf( 'The product has no size %s to rename. Nothing was changed.', implode( ', ', array_keys( $lower ) ) ) );
		$changes[] = sprintf( 'Replace the product\'s own option "%s" with the shop-wide Size option (same place, same settings).', $attrs[ $size_key ]->get_name() );
	}

	// Each size's new values, worked out before any write: once the product is saved, WooCommerce reads a size through the new options.
	$vids    = fisha_studio_variation_ids( $p );
	$planned = array(); // size id => [new values, the shop size name it gets or null]
	foreach ( $vids as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		$was  = $v->get_attributes( 'edit' );
		$now  = array();
		$size = null;
		foreach ( $was as $k => $val ) {
			$val = (string) $val;
			if ( $k === $size_key ) {
				$u = fisha_studio_unquote( $val );
				if ( '' !== $u && ! isset( $target[ $u ] ) ) return new WP_Error( 'fisha_studio_invalid', "Size #$vid uses \"$u\", which is not a value of the Size option. Nothing was changed." );
				$size = '' === $u ? '' : $target[ $u ];
				$t    = '' === $size ? null : $targets[ $size ];
				$now['pa_size'] = $t ? $t->slug : ( '' === $size ? '' : sanitize_title( $size ) );
			} else {
				$now[ $k ] = isset( $unquoted[ $k ] ) ? fisha_studio_unquote( $val ) : $val;
			}
		}
		$planned[ $vid ] = array( $now, $size );
		if ( $now !== $was ) {
			$changes[] = sprintf( 'Size #%d: %s becomes %s (price %s unchanged).', $vid, fisha_studio_attrs_text( $was ), fisha_studio_attrs_text( $now ), $v->get_regular_price( 'edit' ) );
		}
	}

	// Stock: only when the Size option moves or the whole product still counts stock, so a later run never resets the owner's choices.
	$stock = null !== $size_key || true === $p->get_manage_stock( 'edit' );
	if ( $stock ) {
		if ( true === $p->get_manage_stock( 'edit' ) || 'no' !== $p->get_backorders( 'edit' ) ) {
			$changes[] = sprintf( 'Product: stop counting stock for the whole product (now %s, orders when sold out: %s); its stock status follows the sizes.',
				true === $p->get_manage_stock( 'edit' ) ? 'counting ' . (int) $p->get_stock_quantity( 'edit' ) : 'not counting', $p->get_backorders( 'edit' ) );
		}
		foreach ( $vids as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && ( false !== $v->get_manage_stock( 'edit' ) || 'instock' !== $v->get_stock_status( 'edit' ) || 'no' !== $v->get_backorders( 'edit' ) ) ) {
				$changes[] = sprintf( 'Size #%d: stock choice "In stock" (no count, no orders when sold out).', $vid );
			}
		}
	}

	$plan = array( 'product_id' => $id, 'changes' => $changes, 'applied' => false );
	if ( $dry_run || ! $changes ) return $plan;

	$before = fisha_studio_product_values( $p );
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$term_ids = array();
		foreach ( $targets as $name => $t ) {
			if ( ! $t ) {
				$max = 0;
				foreach ( get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) as $x ) $max = max( $max, (int) get_term_meta( $x->term_id, 'order', true ) );
				$r = wp_insert_term( $name, 'pa_size' );
				if ( is_wp_error( $r ) ) throw new RuntimeException( 'size value ' . $name . ': ' . $r->get_error_message() );
				update_term_meta( (int) $r['term_id'], 'order', $max + 1 );
				$t = get_term( (int) $r['term_id'], 'pa_size' );
				$targets[ $name ] = $t;
			}
			$term_ids[] = (int) $t->term_id;
		}

		$objs = array();
		foreach ( $attrs as $key => $a ) {
			if ( $key === $size_key ) {
				$o = new WC_Product_Attribute();
				$o->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
				$o->set_name( 'pa_size' );
				$o->set_options( $term_ids );
				$o->set_position( $a->get_position() );
				$o->set_visible( $a->get_visible() );
				$o->set_variation( $a->get_variation() );
				$objs['pa_size'] = $o;
			} else {
				if ( isset( $unquoted[ $key ] ) ) $a->set_options( $unquoted[ $key ] );
				$objs[ $key ] = $a;
			}
		}
		$p->set_attributes( $objs );
		if ( $stock ) {
			$p->set_manage_stock( false );
			$p->set_backorders( 'no' );
		}
		$p->save();
		if ( null !== $size_key ) wp_set_object_terms( $id, $term_ids, 'pa_size' );

		foreach ( $planned as $vid => list( $now, $size ) ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) continue;
			if ( null !== $size ) $now['pa_size'] = '' === $size ? '' : $targets[ $size ]->slug; // the real slug of a value just created
			$v->set_attributes( $now );
			if ( $stock ) {
				$v->set_manage_stock( false );
				$v->set_stock_status( 'instock' );
				$v->set_backorders( 'no' );
			}
			$v->save();
			if ( null !== $size_key ) delete_post_meta( $vid, 'attribute_' . $size_key );
		}

		WC_Product_Variable::sync( $id );
		fisha_studio_convert_clear( $id, $vids );
		if ( ! fisha_studio_log( 'product', $id, 'convert', $before, fisha_studio_product_values( wc_get_product( $id ) ) ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		fisha_studio_convert_clear( $id, $vids );
		clean_term_cache( array(), 'pa_size' );
		fisha_studio_log_error( 'convert sizes ' . $id, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'The conversion failed, so nothing was changed: ' . $e->getMessage() );
	}

	fisha_studio_convert_clear( $id, $vids );
	fisha_studio_purge_product( wc_get_product( $id ), $before['category_ids'] );
	$plan['applied'] = true;
	return $plan;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * wp fisha-studio convert-sizes <product_id> [--dry-run] [--map="XS=S,S=M"]: makes a product with sizes made in
	 * WP admin work like the others (shop-wide Size option, no quotes, stock chosen per size). --map renames Size
	 * values first, all at once. Prints the plan first; --dry-run stops there.
	 */
	WP_CLI::add_command( 'fisha-studio convert-sizes', function ( $args, $assoc ) {
		$id  = absint( $args[0] ?? 0 );
		$dry = ! empty( $assoc['dry-run'] );
		$map = fisha_studio_parse_size_map( (string) ( $assoc['map'] ?? '' ) );
		if ( is_wp_error( $map ) ) WP_CLI::error( $map->get_error_message() );
		$plan = fisha_studio_convert_sizes( $id, true, $map );
		if ( is_wp_error( $plan ) ) WP_CLI::error( $plan->get_error_message() );
		if ( ! $plan['changes'] ) {
			WP_CLI::success( "Product $id is already converted. Nothing to change." );
			return;
		}
		WP_CLI::log( "Plan for product $id:" );
		foreach ( $plan['changes'] as $line ) WP_CLI::log( '  - ' . $line );
		if ( $dry ) {
			WP_CLI::log( 'Dry run: nothing was changed.' );
			return;
		}
		$r = fisha_studio_convert_sizes( $id, false, $map );
		if ( is_wp_error( $r ) ) WP_CLI::error( $r->get_error_message() );
		WP_CLI::success( sprintf( 'Product %d converted (%d changes). The owner can now set each size\'s stock in Inventory.', $id, count( $r['changes'] ) ) );
	} );
}
