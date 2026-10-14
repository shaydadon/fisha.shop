<?php
/**
 * Fisha Studio History: the audit log read as plain English for the owner. A product, category or size change, a
 * sign-in, a size list edit; each with when, who, what changed, and whether it can be restored (full snapshots only).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_SNAPSHOT_ACTIONS', array( 'create', 'publish', 'convert' ) );

/** @return array{items: array[], total: int, pages: int} */
function fisha_studio_history_query( array $args ): array {
	global $wpdb;
	$table = fisha_studio_log_table();
	$kind  = (string) ( $args['kind'] ?? 'all' );
	$per   = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
	$where = array( '1=1' );
	$vals  = array();
	if ( 'product' === $kind ) $where[] = "item_type IN ('product','variation')";
	elseif ( 'category' === $kind ) $where[] = "item_type = 'category'";
	elseif ( 'signin' === $kind ) $where[] = "item_type = 'user'";
	elseif ( 'other' === $kind ) $where[] = "item_type NOT IN ('product','variation','category','user')";
	$type = (string) ( $args['item_type'] ?? '' );
	$id   = (int) ( $args['item_id'] ?? 0 );
	if ( 'product' === $type && $id ) {
		$vids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
		$sizes = $vids ? " OR (item_type = 'variation' AND item_id IN (" . implode( ',', array_map( 'intval', $vids ) ) . '))' : '';
		$where[] = "((item_type = 'product' AND item_id = %d)$sizes)";
		$vals[]  = $id;
	} elseif ( 'category' === $type && $id ) {
		$where[] = "item_type = 'category' AND item_id = %d";
		$vals[]  = $id;
	}
	$days = (int) ( $args['days'] ?? 0 );
	if ( $days > 0 ) {
		$where[] = 'created >= %s';
		$vals[]  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}
	$sql_where = implode( ' AND ', $where );
	$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql_where", $vals ) : "SELECT COUNT(*) FROM $table WHERE $sql_where" );
	$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
	return array( 'items' => array_map( 'fisha_studio_history_entry', $rows ?: array() ), 'total' => $total, 'pages' => (int) ceil( $total / $per ) );
}

/** One log row as the owner reads it. */
function fisha_studio_history_entry( array $row ): array {
	$before = null === $row['before_json'] ? null : json_decode( (string) $row['before_json'], true );
	$after  = null === $row['after_json'] ? null : json_decode( (string) $row['after_json'], true );
	$type   = (string) $row['item_type'];
	$id     = (int) $row['item_id'];
	$lines  = fisha_studio_history_changes( $type, $id, is_array( $before ) ? $before : null, is_array( $after ) ? $after : null );
	$item   = fisha_studio_history_item( $type, $id, is_array( $after ) ? $after : ( is_array( $before ) ? $before : array() ) );
	$u      = (int) $row['user_id'] ? get_user_by( 'id', (int) $row['user_id'] ) : null;
	return array(
		'id'         => (int) $row['id'],
		'when'       => str_replace( ' ', 'T', (string) $row['created'] ) . 'Z',
		'who'        => $u ? $u->display_name : ( 'user' === $type ? 'Studio sign-in' : 'Site admin (WP-CLI)' ),
		'title'      => fisha_studio_history_title( $type, (string) $row['action'], $after ),
		'item'       => $item,
		'changes'    => array_slice( $lines, 0, 6 ),
		'more'       => max( 0, count( $lines ) - 6 ),
		'restorable' => in_array( $type, array( 'product', 'category' ), true ) && in_array( $row['action'], FISHA_STUDIO_SNAPSHOT_ACTIONS, true ) && is_array( $after ) && $item && $item['exists'],
	);
}

function fisha_studio_history_title( string $type, string $action, $after ): string {
	$map = array(
		'product'   => array( 'new' => 'Started a new product', 'create' => 'Published a new product', 'publish' => 'Published changes', 'convert' => 'Converted the sizes', 'trash' => 'Moved to the trash', 'untrash' => 'Brought back from the trash', 'duplicate' => 'Made a copy' ),
		'variation' => array( 'quick' => 'Quick edit of a size' ),
		'category'  => array( 'new' => 'Started a new category', 'create' => 'Published a new category', 'publish' => 'Published changes', 'reorder' => 'Changed the order', 'delete' => 'Deleted the category', 'duplicate' => 'Made a copy' ),
		'user'      => array( '2fa-signin' => 'Signed in', 'backup-code' => 'Signed in with a backup code', '2fa-setup' => 'Set up 2-step sign-in', '2fa-reset' => '2-step sign-in was reset', 'backup-codes-new' => 'Made new backup codes', '2fa-code-lock' => 'Too many wrong codes', 'password-change' => 'Changed the password', 'sign-out-others' => 'Signed out of other devices' ),
		'attribute' => array( 'create' => 'Added a size list', 'rename' => 'Renamed a size list', 'add value' => 'Added a value to a size list', 'rename value' => 'Renamed a size list value', 'delete value' => 'Removed a size list value', 'reorder' => 'Reordered a size list' ),
	);
	if ( 'category' === $type && 'shown' === $action ) return ! empty( $after['shown'] ) ? 'Switched Show on site on' : 'Switched Show on site off';
	return $map[ $type ][ $action ] ?? ucfirst( $action );
}

/** The thing a row is about: {type, id, name, exists} or null for sign-ins and size lists. */
function fisha_studio_history_item( string $type, int $id, array $values ): ?array {
	if ( 'product' === $type || 'variation' === $type ) {
		$pid  = 'variation' === $type ? (int) wp_get_post_parent_id( $id ) : $id;
		$post = $pid ? get_post( $pid ) : null;
		$name = $post ? fisha_studio_plain( $post->post_title ) : (string) ( $values['name'] ?? '' );
		return array( 'type' => 'product', 'id' => $pid, 'name' => '' !== $name ? $name : 'Product #' . $pid, 'exists' => $post && 'product' === $post->post_type && 'trash' !== $post->post_status );
	}
	if ( 'category' === $type ) {
		$t = fisha_studio_category( $id );
		$name = $t ? fisha_studio_plain( $t->name ) : (string) ( $values['name'] ?? 'Category #' . $id );
		return array( 'type' => 'category', 'id' => $id, 'name' => $name, 'exists' => (bool) $t );
	}
	return null;
}

/** Plain lines for what changed between two value sets ("Regular price: 119 → 109", "Photos changed"). */
function fisha_studio_history_changes( string $type, int $id, ?array $before, ?array $after ): array {
	if ( ! $before || ! $after ) return array();
	$p      = 'product' === $type ? wc_get_product( $id ) : ( 'variation' === $type ? wc_get_product( (int) wp_get_post_parent_id( $id ) ) : null );
	$cat    = 'category' === $type ? wp_list_pluck( fisha_studio_category_fields(), 'label' ) : array();
	$photos = false;
	$lines  = array();
	foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $k ) {
		$k = (string) $k;
		$a = $before[ $k ] ?? null;
		$b = $after[ $k ] ?? null;
		if ( fisha_studio_same( $a, $b ) ) continue;
		if ( preg_match( '/(^|:)(image_id|gallery_image_ids|hero_ids)$/', $k ) ) { $photos = true; continue; }
		if ( 'variation' === $type ) {
			$v     = wc_get_product( $id );
			$label = ( $v ? fisha_studio_variation_label( $v ) . ': ' : '' ) . ( fisha_studio_variation_fields()[ $k ]['label'] ?? $k );
		} elseif ( $p ) {
			$label = fisha_studio_value_label( $p, $k );
		} else {
			$label = $cat[ $k ] ?? ( fisha_studio_product_fields()[ $k ]['label'] ?? $k );
		}
		$lines[] = fisha_studio_history_short( $a ) && fisha_studio_history_short( $b )
			? sprintf( '%s: %s → %s', $label, fisha_studio_history_text( $a ), fisha_studio_history_text( $b ) )
			: $label . ' changed';
	}
	if ( $photos ) array_unshift( $lines, 'Photos changed' );
	return $lines;
}

function fisha_studio_history_short( $v ): bool {
	return ! is_array( $v ) && mb_strlen( (string) $v ) <= 40 && false === strpos( (string) $v, '<' );
}
function fisha_studio_history_text( $v ): string {
	if ( true === $v ) return 'on';
	if ( false === $v ) return 'off';
	if ( null === $v || '' === $v ) return '(empty)';
	return fisha_studio_plain( (string) $v );
}
