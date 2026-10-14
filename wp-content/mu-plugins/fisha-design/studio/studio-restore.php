<?php
/**
 * Fisha Studio restore: an older published version of a product or category goes back into its draft (the owner then
 * previews and publishes as usual), and products from the WordPress trash come back as drafts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** @return array{skipped: string[]}|WP_Error */
function fisha_studio_restore( string $type, int $id, int $log_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . fisha_studio_log_table() . ' WHERE id = %d', $log_id ), ARRAY_A );
	$snapshot = $row && null !== $row['after_json'] ? json_decode( (string) $row['after_json'], true ) : null;
	if ( ! $row || $row['item_type'] !== $type || (int) $row['item_id'] !== $id || ! in_array( $row['action'], FISHA_STUDIO_SNAPSHOT_ACTIONS, true ) || ! is_array( $snapshot ) ) {
		return new WP_Error( 'fisha_studio_not_restorable', 'This entry cannot be restored.', array( 'status' => 400 ) );
	}
	if ( 'product' === $type ) {
		$p = ( 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) ? wc_get_product( $id ) : null;
		if ( ! $p ) return new WP_Error( 'fisha_studio_not_found', 'This product no longer exists.', array( 'status' => 404 ) );
		$live = fisha_studio_product_values( $p );
	} else {
		$t = fisha_studio_category( $id );
		if ( ! $t ) return fisha_studio_category_missing();
		$live = fisha_studio_category_values( $t );
	}

	$skipped = array();
	$photos  = 0;
	$values  = array();
	foreach ( $snapshot as $k => $v ) {
		$k = (string) $k;
		if ( ! array_key_exists( $k, $live ) ) continue;
		if ( preg_match( '/(^|:)image_id$/', $k ) && $v && ! wp_attachment_is_image( (int) $v ) ) { $photos++; continue; }
		if ( preg_match( '/(^|:)(gallery_image_ids|hero_ids)$/', $k ) && is_array( $v ) ) {
			$keep = array_values( array_filter( $v, function ( $a ) { return wp_attachment_is_image( (int) $a ); } ) );
			$photos += count( $v ) - count( $keep );
			$v = $keep;
		}
		if ( 'category_ids' === $k && is_array( $v ) ) {
			$keep = array_values( array_filter( $v, function ( $c ) { return (bool) fisha_studio_category( (int) $c ); } ) );
			if ( count( $keep ) < count( $v ) ) $skipped[] = 'A category from that version no longer exists.';
			$v = $keep;
		}
		if ( 'parent' === $k && (int) $v && ! fisha_studio_category( (int) $v ) ) {
			$skipped[] = 'The parent category from that version no longer exists.';
			continue;
		}
		if ( 'attributes' === $k && ! fisha_studio_restore_options_exist( (array) $v ) ) {
			$skipped[] = 'The size options from that version no longer exist, so they were left as they are.';
			continue;
		}
		$values[ $k ] = $v;
	}
	$gone_sizes = fisha_studio_restore_size_count( $snapshot, $live );
	if ( $gone_sizes ) array_unshift( $skipped, sprintf( _n( '%d size from that version no longer exists.', '%d sizes from that version no longer exist.', $gone_sizes ), $gone_sizes ) );
	if ( $photos ) $skipped[] = sprintf( _n( '%d photo from that version no longer exists.', '%d photos from that version no longer exist.', $photos ), $photos );

	$changes = array();
	foreach ( $values as $k => $v ) {
		if ( ! fisha_studio_same( $v, $live[ $k ] ) ) $changes[ $k ] = $v;
	}
	if ( ! $changes ) return new WP_Error( 'fisha_studio_same', 'This version is the same as the live one.', array( 'status' => 400 ) );
	$seen = array_intersect_key( $live, $changes );
	if ( 'product' === $type ) {
		list( $clean, $errors ) = fisha_studio_sanitize_product_input( $p, $changes );
		fisha_studio_product_edit( $p, array_diff_key( $clean, $errors ), $seen );
	} else {
		list( $clean, $errors ) = fisha_studio_sanitize( fisha_studio_category_fields(), $changes );
		fisha_studio_category_edit( $t, array_diff_key( $clean, $errors ), $seen );
	}
	return array( 'skipped' => $skipped );
}

/** How many different sizes of the snapshot are gone. */
function fisha_studio_restore_size_count( array $snapshot, array $live ): int {
	$gone = array();
	foreach ( array_keys( $snapshot ) as $k ) {
		if ( preg_match( '/^var:(\d+):/', (string) $k, $m ) && ! array_key_exists( (string) $k, $live ) ) $gone[ $m[1] ] = true;
	}
	return count( $gone );
}

/** Whether every shop-wide option value a snapshot's options use still exists (local options always do). */
function fisha_studio_restore_options_exist( array $list ): bool {
	foreach ( $list as $a ) {
		if ( empty( $a['taxonomy'] ) ) continue;
		foreach ( (array) ( $a['options'] ?? array() ) as $term_id ) {
			$t = get_term( (int) $term_id, (string) $a['name'] );
			if ( ! $t || is_wp_error( $t ) ) return false;
		}
	}
	return true;
}

/** Products moved to the trash in the last 30 days, newest first. */
function fisha_studio_trash_list(): array {
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'trash', 'numberposts' => 100, 'fields' => 'ids', 'meta_key' => '_wp_trash_meta_time', 'orderby' => 'meta_value_num', 'order' => 'DESC' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$when = (int) get_post_meta( $id, '_wp_trash_meta_time', true );
		if ( $when < time() - 30 * DAY_IN_SECONDS ) continue;
		$thumb = get_post_thumbnail_id( $id );
		$out[] = array( 'id' => (int) $id, 'name' => fisha_studio_plain( get_the_title( $id ) ) ?: 'Product #' . $id, 'deleted' => gmdate( 'Y-m-d\TH:i:s\Z', $when ), 'thumb' => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'thumbnail' ) : '' );
	}
	return $out;
}

/** Brings a trashed product (and its sizes) back as a draft. @return WC_Product|WP_Error */
function fisha_studio_untrash_product( int $id ) {
	if ( 'product' !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
		return new WP_Error( 'fisha_studio_not_found', 'This product is not in the trash.', array( 'status' => 404 ) );
	}
	$force = function ( $status, $post_id ) use ( $id ) { return (int) $post_id === $id ? 'draft' : $status; };
		// Only the product itself goes to draft; its sizes (untrashed by WooCommerce through the same filter) keep their own status.
	add_filter( 'wp_untrash_post_status', $force, 10, 2 );
	$ok = wp_untrash_post( $id );
	remove_filter( 'wp_untrash_post_status', $force );
	if ( ! $ok ) return new WP_Error( 'fisha_studio_untrash_failed', 'The product could not be brought back. Please try again.', array( 'status' => 500 ) );
	clean_post_cache( $id );
	wc_delete_product_transients( $id );
	fisha_studio_log( 'product', $id, 'untrash', null, array( 'status' => 'draft' ) );
	return wc_get_product( $id );
}
