<?php
/**
 * Fisha Studio REST: products. List with filters, create (as a hidden draft), read, autosave a draft, discard,
 * publish, quick price/stock edit from the list, and move to trash.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	$id = '/products/(?P<id>\d+)';
	register_rest_route( FISHA_STUDIO_NS, '/products', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_products_list', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_create', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id, array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_products_get', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/draft', array(
		array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_products_draft', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
		array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_products_discard', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/publish', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_publish', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/quick', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_quick', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/trash', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_trash', 'permission_callback' => fisha_studio_permission( 'delete_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/type', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_products_type', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/variations/generate', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_sizes_generate', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, $id . '/variations/(?P<vid>\d+)', array( 'methods' => 'DELETE', 'callback' => 'fisha_studio_rest_sizes_delete', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
} );

/** @return WC_Product|WP_Error */
function fisha_studio_rest_product( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	$p  = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
	return $p ?: new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
}

function fisha_studio_product_state( WC_Product $p ): string {
	if ( fisha_studio_is_new( $p ) ) return 'new';
	return fisha_studio_draft_get( $p->get_id() )['fields'] ? 'changed' : 'live';
}

function fisha_studio_product_payload( WC_Product $p ): array {
	$id     = $p->get_id();
	$live   = fisha_studio_product_values( $p );
	$draft  = fisha_studio_draft_get( $id );
	$is_new = fisha_studio_is_new( $p );
	if ( $is_new ) $live['status'] = fisha_studio_target_status( $p );
	$values = array_merge( $live, $draft['fields'] );
	$images = array();
	$image_ids = array_merge( array( (int) $values['image_id'] ), $values['gallery_image_ids'] );
	foreach ( $values as $k => $x ) {
		if ( $x && preg_match( '/^var:\d+:image_id$/', (string) $k ) ) $image_ids[] = (int) $x;
	}
	foreach ( array_unique( $image_ids ) as $aid ) {
		$img = fisha_studio_image_payload( (int) $aid );
		if ( $img ) $images[ $aid ] = $img;
	}
	return array(
		'id'           => $id,
		'type'         => $p->get_type(),
		'editable'     => $p->is_type( array( 'simple', 'variable' ) ),
		'state'        => fisha_studio_product_state( $p ),
		'values'       => $values,
		'live'         => $live,
		'changed'      => array_keys( $draft['fields'] ),
		'url'          => $is_new ? '' : get_permalink( $id ),
		'product_base' => trailingslashit( home_url( wc_get_permalink_structure()['product_rewrite_slug'] ) ),
		'previews'     => fisha_studio_preview_targets( $p, $values['category_ids'] ),
		'images'       => (object) $images,
		'conflicts'    => $is_new ? array() : fisha_studio_conflicts( $p, $draft ),
		'variations'   => fisha_studio_variations_payload( $p ),
		// Sizes can be deleted and the type switched only while the product has never been live (and never sold).
		'restructurable' => fisha_studio_never_live( $p ),
	);
}

function fisha_studio_low_threshold( WC_Product $p ): int {
	return '' !== (string) $p->get_low_stock_amount() ? (int) $p->get_low_stock_amount() : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
}

/** "S 3 · M 0 · L 2" when sizes count stock, else "3 of 4 in stock". */
function fisha_studio_sizes_stock_text( WC_Product $p ): string {
	$parts   = array();
	$counted = false;
	$in      = 0;
	$n       = 0;
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || 'publish' !== $v->get_status( 'edit' ) ) continue;
		$n++;
		if ( $v->is_in_stock() ) $in++;
		if ( true === $v->get_manage_stock( 'edit' ) ) {
			$counted = true;
			$parts[] = fisha_studio_variation_label( $v ) . ' ' . (int) $v->get_stock_quantity();
		}
	}
	if ( ! $n ) return 'No sizes on';
	return $counted ? implode( ' · ', $parts ) : sprintf( '%d of %d in stock', $in, $n );
}

function fisha_studio_variations_payload( WC_Product $p ): array {
	$out = array();
	foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) continue;
		$out[] = array(
			'id'        => $vid,
			'label'     => fisha_studio_variation_label( $v ),
			'attrs'     => (object) $v->get_attributes( 'edit' ),
			'pending'   => '1' === get_post_meta( $vid, FISHA_STUDIO_PENDING_META, true ),
			'deletable' => fisha_studio_size_deletable( $p, $vid ),
		);
	}
	return $out;
}

function fisha_studio_product_row( WC_Product $p ): array {
	$sizes = $p->is_type( 'variable' );
	// A product with sizes can count its stock as a whole (then the sizes do not count their own).
	$qty   = true === $p->get_manage_stock( 'edit' ) ? $p->get_stock_quantity() : null;
	$low   = null !== $qty && $qty > 0 && $qty <= fisha_studio_low_threshold( $p );
	if ( $sizes ) {
		foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && 'publish' === $v->get_status( 'edit' ) && true === $v->get_manage_stock( 'edit' ) ) {
				$q = (int) $v->get_stock_quantity();
				if ( $q > 0 && $q <= fisha_studio_low_threshold( $v ) ) $low = true;
			}
		}
	}
	$names = array();
	foreach ( $p->get_category_ids() as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) $names[] = fisha_studio_plain( $t->name );
	}
	return array(
		'id'             => $p->get_id(),
		'name'           => fisha_studio_plain( $p->get_name( 'edit' ) ),
		'sku'            => $p->get_sku( 'edit' ),
		'type'           => $p->get_type(),
		'editable'       => $p->is_type( array( 'simple', 'variable' ) ),
		'sizes'          => $sizes,
		'stock_text'     => $sizes ? fisha_studio_sizes_stock_text( $p ) : '',
		'state'          => fisha_studio_product_state( $p ),
		'hidden'         => 'private' === get_post_status( $p->get_id() ),
		'regular_price'  => (string) $p->get_regular_price( 'edit' ),
		'sale_price'     => (string) $p->get_sale_price( 'edit' ),
		'price_text'     => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
		'manage_stock'   => (bool) $p->get_manage_stock(),
		'counted'        => true === $p->get_manage_stock( 'edit' ),
		'stock_quantity' => $qty,
		'stock_status'   => $p->get_stock_status( 'edit' ),
		'low'            => (bool) $low,
		'thumb'          => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
		'categories'     => $names,
	);
}

function fisha_studio_rest_products_list( WP_REST_Request $r ): array {
	$args   = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
	$search = trim( sanitize_text_field( (string) $r['search'] ) );
	if ( '' !== $search ) $args['s'] = $search;
	if ( $r['category'] ) $args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'terms' => (int) $r['category'], 'include_children' => true ) );
	$ids = ( new WP_Query( $args ) )->posts;
	if ( '' !== $search && ( $by_sku = (int) wc_get_product_id_by_sku( $search ) ) ) {
		if ( 'product_variation' === get_post_type( $by_sku ) ) $by_sku = (int) wp_get_post_parent_id( $by_sku );
		$ok = $by_sku && 'product' === get_post_type( $by_sku ) && in_array( get_post_status( $by_sku ), array( 'publish', 'private', 'draft' ), true ) && ! in_array( $by_sku, $ids, true );
		if ( $ok && $r['category'] ) {
			$want = (int) $r['category'];
			$ok   = false;
			foreach ( (array) wp_get_post_terms( $by_sku, 'product_cat', array( 'fields' => 'ids' ) ) as $cid ) {
				if ( (int) $cid === $want || in_array( $want, array_map( 'intval', get_ancestors( $cid, 'product_cat', 'taxonomy' ) ), true ) ) { $ok = true; break; }
			}
		}
		if ( $ok ) array_unshift( $ids, $by_sku );
	}

	$state = (string) ( $r['state'] ?: 'all' );
	$stock = (string) ( $r['stock'] ?: 'all' );
	$rows  = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p ) continue;
		$row = fisha_studio_product_row( $p );
		if ( 'hidden' === $state && ! $row['hidden'] ) continue;
		if ( in_array( $state, array( 'live', 'changed', 'new' ), true ) && $row['state'] !== $state ) continue;
		if ( 'low' === $stock && ! $row['low'] ) continue;
		if ( in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) && $row['stock_status'] !== $stock ) continue;
		$rows[] = $row;
	}
	$per  = min( 100, max( 1, (int) ( $r['per_page'] ?: 50 ) ) );
	$page = max( 1, (int) ( $r['page'] ?: 1 ) );
	return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'pages' => (int) ceil( count( $rows ) / $per ) );
}

function fisha_studio_rest_products_create( WP_REST_Request $r ) {
	$body = fisha_studio_body( $r );
	$name = trim( sanitize_text_field( (string) ( $body['name'] ?? '' ) ) );
	if ( mb_strlen( $name ) > 120 ) {
		return new WP_Error( 'fisha_studio_invalid', 'A name can be at most 120 characters.', array( 'status' => 422, 'fields' => array( 'name' => 'A name can be at most 120 characters.' ) ) );
	}
	$p = fisha_studio_product_create( $name, 'variable' === ( $body['type'] ?? '' ) ? 'variable' : 'simple' );
	fisha_studio_log( 'product', $p->get_id(), 'new', null, array( 'name' => $name, 'type' => $p->get_type() ) );
	return new WP_REST_Response( fisha_studio_product_payload( $p ), 201 );
}

function fisha_studio_rest_products_get( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	return is_wp_error( $p ) ? $p : fisha_studio_product_payload( $p );
}

function fisha_studio_rest_products_draft( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) return new WP_Error( 'fisha_studio_unsupported', 'This kind of product can only be edited in WP admin.', array( 'status' => 400 ) );
	$body = fisha_studio_body( $r );
	list( $values, $errors ) = fisha_studio_sanitize_product_input( $p, (array) ( $body['fields'] ?? array() ) );
	$values = array_diff_key( $values, $errors );
	// A SKU already in use is reported on its own key and the rest of the batch is still saved.
	$errors += fisha_studio_taken_skus( $p, $values );
	$values  = array_diff_key( $values, $errors );
	if ( $values ) {
		try {
			fisha_studio_product_edit( $p, $values, fisha_studio_clean_seen( $body['seen'] ?? array(), $p ) );
		} catch ( WC_Data_Exception $e ) {
			// A never-published product takes edits directly, so WooCommerce can refuse a value (a SKU already in use).
			$key = 'sku';
			foreach ( array_keys( $values ) as $k ) {
				if ( 'sku' === $k || preg_match( '/^var:\d+:sku$/', (string) $k ) ) { $key = (string) $k; break; }
			}
			$errors[ $key ] = false !== strpos( (string) $e->getErrorCode(), 'sku' ) ? 'Another product or size already uses this SKU.' : $e->getMessage();
		}
	}
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'field_errors' => (object) $errors ) );
}

/** Errors for the SKUs in an edit ("sku", "var:{id}:sku") that another product or size uses, or that repeat within the edit. */
function fisha_studio_taken_skus( WC_Product $p, array $values ): array {
	$errors = array();
	$batch  = array();
	$keys   = array_keys( $values );
	usort( $keys, function ( $a, $b ) { return ( 'sku' === $b ) <=> ( 'sku' === $a ); } ); // the product's own SKU first
	foreach ( $keys as $k ) {
		$k      = (string) $k;
		$parsed = fisha_studio_parse_var_key( $k );
		if ( 'sku' !== $k && ! ( $parsed && 'sku' === $parsed[1] ) ) continue;
		$sku = (string) $values[ $k ];
		if ( '' === $sku ) continue;
		$owner = $parsed ? $parsed[0] : $p->get_id();
		if ( isset( $batch[ $sku ] ) || ! wc_product_has_unique_sku( $owner, $sku ) ) {
			$errors[ $k ] = 'Another product or size already uses this SKU.';
			continue;
		}
		$batch[ $sku ] = true;
	}
	return $errors;
}

function fisha_studio_rest_products_discard( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	fisha_studio_draft_discard( $p->get_id() );
	return fisha_studio_product_payload( wc_get_product( $p->get_id() ) );
}

function fisha_studio_rest_products_publish( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_publish_product( $p->get_id(), is_array( $b['resolutions'] ?? null ) ? $b['resolutions'] : array(), ! empty( $b['change_url'] ) );
	if ( is_wp_error( $res ) ) return $res;
	$fresh    = wc_get_product( $p->get_id() );
	$warnings = array();
	$cats     = $fresh->get_category_ids();
	$public   = array_filter( $cats, function ( $cid ) { $t = fisha_studio_category( (int) $cid ); return $t && ! fisha_cat_is_unpublished( $t ); } );
	if ( $cats && ! $public ) $warnings[] = 'Its category is not published yet, so no category page lists this product until you publish the category.';
	return array_merge( fisha_studio_product_payload( $fresh ), array( 'published_url' => $res['url'], 'warnings' => $warnings ) );
}

function fisha_studio_rest_products_quick( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$body = fisha_studio_body( $r );
	$sent = (array) ( $body['fields'] ?? array() );
	// A product with sizes whose stock is counted as a whole: only that stock count can be changed here.
	$whole = $p->is_type( 'variable' ) && true === $p->get_manage_stock( 'edit' );
	if ( ( ! $p->is_type( 'simple' ) && ! $whole ) || fisha_studio_is_new( $p ) ) return new WP_Error( 'fisha_studio_unsupported', 'Open this product to edit it.', array( 'status' => 400 ) );
	if ( $whole && array( 'stock_quantity' ) !== array_keys( $sent ) ) {
		return new WP_Error( 'fisha_studio_unsupported', 'Only the stock count of this product can be changed here. Open it to change the rest.', array( 'status' => 400 ) );
	}
	if ( fisha_studio_draft_get( $p->get_id() )['fields'] ) {
		return new WP_Error( 'fisha_studio_has_draft', 'This product has unpublished changes. Open it to review and publish them together.', array( 'status' => 409 ) );
	}
	$input = array_intersect_key( $sent, array_flip( array( 'regular_price', 'stock_quantity', 'stock_status' ) ) );
	list( $values, $errors ) = fisha_studio_sanitize( fisha_studio_product_fields(), $input );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', reset( $errors ), array( 'status' => 422, 'fields' => $errors ) );
	// The stock choice: a number means "Fixed amount" (counting on, never sold beyond it); a stock status means
	// "In stock", "Made to order" or "Sold out" (counting off).
	if ( array_key_exists( 'stock_quantity', $values ) ) {
		unset( $values['stock_status'] );
		if ( true !== $p->get_manage_stock( 'edit' ) ) $values += array( 'manage_stock' => true, 'backorders' => 'no' );
	} elseif ( array_key_exists( 'stock_status', $values ) && true === $p->get_manage_stock( 'edit' ) ) {
		$values['manage_stock'] = false;
	}
	// Quick edit publishes at once, so refuse when a sent field changed on the site since the list was loaded (an order).
	$seen      = array_intersect_key( fisha_studio_clean_seen( $body['seen'] ?? array() ), $values );
	$live      = fisha_studio_product_read( $p );
	$schema    = fisha_studio_product_fields();
	$conflicts = array();
	foreach ( $seen as $k => $was ) {
		if ( ! fisha_studio_same( $was, $live[ $k ] ) ) {
			$conflicts[] = array( 'field' => $k, 'label' => $schema[ $k ]['label'] ?? $k, 'base' => $was, 'live' => $live[ $k ], 'mine' => $values[ $k ] );
		}
	}
	if ( $conflicts ) return new WP_Error( 'fisha_studio_conflict', 'This changed on the site since the list was loaded, so nothing was changed.', array( 'status' => 409, 'conflicts' => $conflicts ) );
	$values = fisha_studio_zero_is_sold_out( $p, $values ); // 0 under Fixed amount means Sold out
	fisha_studio_draft_save( $p, $values, $seen );
	$res = fisha_studio_publish_product( $p->get_id() );
	if ( is_wp_error( $res ) ) {
		fisha_studio_draft_discard( $p->get_id() );
		if ( 'fisha_studio_nothing' !== $res->get_error_code() ) {
			$fields = $res->get_error_data()['fields'] ?? array();
			return $fields ? new WP_Error( $res->get_error_code(), reset( $fields ), $res->get_error_data() ) : $res;
		}
	}
	return fisha_studio_product_row( wc_get_product( $p->get_id() ) );
}

function fisha_studio_rest_products_trash( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	if ( ! current_user_can( 'delete_post', $p->get_id() ) ) return new WP_Error( 'fisha_studio_forbidden', 'You do not have access to this.', array( 'status' => 403 ) );
	$res = fisha_studio_trash_product( $p->get_id() );
	return is_wp_error( $res ) ? $res : array( 'trashed' => true );
}

function fisha_studio_rest_products_type( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	$res = fisha_studio_set_product_type( $p, (string) ( fisha_studio_body( $r )['type'] ?? '' ) );
	return is_wp_error( $res ) ? $res : fisha_studio_product_payload( $res );
}

/** @return WC_Product|WP_Error a product with sizes */
function fisha_studio_rest_variable( WP_REST_Request $r ) {
	$p = fisha_studio_rest_product( $r );
	if ( is_wp_error( $p ) ) return $p;
	return $p->is_type( 'variable' ) ? $p : new WP_Error( 'fisha_studio_invalid', 'Switch on "Comes in sizes or options" first.', array( 'status' => 409 ) );
}

function fisha_studio_rest_sizes_generate( WP_REST_Request $r ) {
	$p = fisha_studio_rest_variable( $r );
	if ( is_wp_error( $p ) ) return $p;
	$values = array_merge( fisha_studio_product_values( $p ), fisha_studio_draft_get( $p->get_id() )['fields'] );
	$new    = fisha_studio_variation_generate( $p, (array) ( $values['attributes'] ?? array() ) );
	if ( is_wp_error( $new ) ) return $new;
	return array_merge( fisha_studio_product_payload( wc_get_product( $p->get_id() ) ), array( 'created' => count( $new ) ) );
}

function fisha_studio_rest_sizes_delete( WP_REST_Request $r ) {
	$p = fisha_studio_rest_variable( $r );
	if ( is_wp_error( $p ) ) return $p;
	$res = fisha_studio_variation_remove( $p, (int) $r['vid'] );
	return is_wp_error( $res ) ? $res : fisha_studio_product_payload( wc_get_product( $p->get_id() ) );
}
