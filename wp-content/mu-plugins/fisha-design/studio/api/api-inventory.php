<?php
/**
 * Fisha Studio REST: the Inventory screen. Every simple product and every size in one list, for a quick stock count or
 * price update; each change goes live at once and is refused if it changed on the site since the list loaded.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/inventory', array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_inventory', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
	register_rest_route( FISHA_STUDIO_NS, '/variations/(?P<vid>\d+)/quick', array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_variation_quick', 'permission_callback' => fisha_studio_permission( 'publish_products' ) ) );
} );

function fisha_studio_inventory_row( WC_Product $item, ?WC_Product $parent = null ): array {
	$p      = $parent ?: $item;
	$locked = '';
	if ( fisha_studio_is_new( $p ) ) $locked = 'Not published yet';
	elseif ( $parent && '1' === get_post_meta( $item->get_id(), FISHA_STUDIO_PENDING_META, true ) ) $locked = 'New size, not published yet';
	elseif ( fisha_studio_draft_get( $p->get_id() )['fields'] ) $locked = 'Unpublished changes';
	$manage = $parent ? true === $item->get_manage_stock( 'edit' ) : (bool) $item->get_manage_stock();
	$qty    = $manage ? $item->get_stock_quantity() : null;
	$img    = $item->get_image_id() ?: $p->get_image_id();
	return array(
		'kind'           => $parent ? 'variation' : 'product',
		'id'             => $item->get_id(),
		'product_id'     => $p->get_id(),
		'name'           => $p->get_name( 'edit' ),
		'option'         => $parent ? fisha_studio_variation_label( $item ) : '',
		'sku'            => (string) $item->get_sku( 'edit' ),
		'enabled'        => ! $parent || 'publish' === $item->get_status( 'edit' ),
		'manage_stock'   => $manage,
		'stock_quantity' => $qty,
		'stock_status'   => $item->get_stock_status( 'edit' ),
		'regular_price'  => (string) $item->get_regular_price( 'edit' ),
		'low'            => null !== $qty && $qty > 0 && $qty <= fisha_studio_low_threshold( $item ),
		'thumb'          => $img ? (string) wp_get_attachment_image_url( $img, 'thumbnail' ) : '',
		'locked'         => $locked,
		'pos'            => 0,
	);
}

function fisha_studio_rest_inventory( WP_REST_Request $r ): array {
	$args = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => array( 'title' => 'ASC' ) );
	if ( $r['category'] ) $args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'terms' => (int) $r['category'], 'include_children' => true ) );
	$rows = array();
	foreach ( ( new WP_Query( $args ) )->posts as $pid ) {
		$p = wc_get_product( (int) $pid );
		if ( ! $p ) continue;
		if ( $p->is_type( 'simple' ) ) {
			$rows[] = fisha_studio_inventory_row( $p );
		} elseif ( $p->is_type( 'variable' ) ) {
			foreach ( fisha_studio_variation_ids( $p ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) $rows[] = fisha_studio_inventory_row( $v, $p );
			}
		}
	}
	foreach ( $rows as $i => &$row ) $row['pos'] = $i;
	unset( $row );

	$search = mb_strtolower( trim( sanitize_text_field( (string) $r['search'] ) ) );
	$stock  = (string) ( $r['stock'] ?: 'all' );
	$rows   = array_values( array_filter( $rows, function ( $row ) use ( $search, $stock ) {
		if ( '' !== $search && false === mb_strpos( mb_strtolower( $row['name'] . ' ' . $row['option'] . ' ' . $row['sku'] ), $search ) ) return false;
		if ( 'low' === $stock ) return $row['low'];
		if ( in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) return $row['stock_status'] === $stock;
		return true;
	} ) );

	$sort = in_array( $r['sort'], array( 'name', 'stock', 'sku', 'price' ), true ) ? $r['sort'] : 'name';
	$dir  = 'desc' === $r['order'] ? -1 : 1;
	$rank = function ( $row ) { // counted stock, else sold out lowest, other statuses highest
		if ( null !== $row['stock_quantity'] ) return (int) $row['stock_quantity'];
		return 'outofstock' === $row['stock_status'] ? -1 : PHP_INT_MAX;
	};
	usort( $rows, function ( $a, $b ) use ( $sort, $dir, $rank ) {
		switch ( $sort ) {
			case 'stock': $c = $rank( $a ) <=> $rank( $b ); break;
			case 'sku':   $c = strnatcasecmp( $a['sku'], $b['sku'] ); break;
			case 'price': $c = (float) $a['regular_price'] <=> (float) $b['regular_price']; break;
			default:      $c = strnatcasecmp( $a['name'], $b['name'] );
		}
		return $c ? $dir * $c : $a['pos'] <=> $b['pos'];
	} );

	$per  = min( 200, max( 1, (int) ( $r['per_page'] ?: 100 ) ) );
	$page = max( 1, (int) ( $r['page'] ?: 1 ) );
	return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'pages' => (int) ceil( count( $rows ) / $per ) );
}

function fisha_studio_rest_variation_quick( WP_REST_Request $r ) {
	$b   = fisha_studio_body( $r );
	$res = fisha_studio_variation_quick( (int) $r['vid'], (array) ( $b['fields'] ?? array() ), (array) ( $b['seen'] ?? array() ) );
	if ( is_wp_error( $res ) ) return $res;
	return fisha_studio_inventory_row( $res, wc_get_product( $res->get_parent_id() ) );
}
