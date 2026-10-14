<?php
/**
 * Fisha Studio REST: the Dashboard (counts that link to the products list, what waits to be published, recently
 * edited items, latest activity).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/dashboard', array( 'methods' => 'GET', 'callback' => 'fisha_studio_dashboard', 'permission_callback' => fisha_studio_permission( 'edit_products' ) ) );
} );

function fisha_studio_dashboard(): array {
	global $wpdb;
	$counts  = array( 'live' => 0, 'sold_out' => 0, 'low' => 0, 'drafts' => 0 );
	$waiting = array();
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p ) continue;
		$row = fisha_studio_product_row( $p );
		if ( 'new' === $row['state'] ) $counts['drafts']++;
		elseif ( ! $row['hidden'] ) {
			$counts['live']++;
			if ( 'outofstock' === $row['stock_status'] ) $counts['sold_out']++;
			if ( $row['low'] ) $counts['low']++;
		}
		if ( 'live' !== $row['state'] ) {
			$d = fisha_studio_draft_get( (int) $id );
			$t = $d['updated'] ?: (int) get_post_modified_time( 'U', true, (int) $id );
			$waiting[] = array( 'kind' => 'product', 'id' => (int) $id, 'name' => $row['name'] ?: 'Untitled', 'state' => $row['state'], 'ts' => $t );
		}
	}
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		$state = fisha_studio_category_state( $t );
		if ( 'live' === $state ) continue;
		$waiting[] = array( 'kind' => 'category', 'id' => (int) $t->term_id, 'name' => fisha_studio_plain( $t->name ), 'state' => $state, 'ts' => (int) fisha_studio_draft_get( (int) $t->term_id, 'term' )['updated'] );
	}
	usort( $waiting, function ( $a, $b ) { return $b['ts'] <=> $a['ts']; } );
	$waiting = array_map( function ( $w ) {
		$w['updated'] = $w['ts'] ? gmdate( 'Y-m-d\TH:i:s\Z', $w['ts'] ) : null;
		unset( $w['ts'] );
		return $w;
	}, array_slice( $waiting, 0, 20 ) );

	$rows = $wpdb->get_results( 'SELECT item_type, item_id, MAX(id) AS last, MAX(created) AS created FROM ' . fisha_studio_log_table() . " WHERE item_type IN ('product','category') AND action IN ('create','publish','convert') GROUP BY item_type, item_id ORDER BY last DESC LIMIT 50", ARRAY_A );
	$recent = array();
	foreach ( $rows ?: array() as $r ) {
		$item = fisha_studio_history_item( $r['item_type'], (int) $r['item_id'], array() );
		if ( ! $item || ! $item['exists'] ) continue;
		$recent[] = array( 'kind' => $item['type'], 'id' => $item['id'], 'name' => $item['name'], 'when' => str_replace( ' ', 'T', $r['created'] ) . 'Z' );
		if ( count( $recent ) >= 10 ) break;
	}
	return array( 'counts' => $counts, 'waiting' => $waiting, 'recent' => $recent, 'activity' => fisha_studio_history_query( array( 'per_page' => 15 ) )['items'] );
}
