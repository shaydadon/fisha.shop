<?php
/**
 * Fisha Studio category publish, Show on site, order and delete. Publish checks the whole category, asks about values that
 * changed on the site meanwhile, then writes inside one database transaction and logs before/after. Moving a live category
 * keeps its old address (and its sub-categories' old addresses) so they redirect.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_category_missing(): WP_Error {
	return new WP_Error( 'fisha_studio_not_found', 'This category no longer exists.', array( 'status' => 404 ) );
}

/** @return array{id:int,url:string}|WP_Error */
function fisha_studio_publish_category( int $id, array $resolutions = array(), bool $change_url = false ) {
	$t = fisha_studio_category( $id );
	if ( ! $t ) return fisha_studio_category_missing();
	$is_new  = fisha_cat_is_unpublished( $t );
	$draft   = fisha_studio_draft_get( $id, 'term' );
	$before  = fisha_studio_category_values( $t );
	$changes = $draft['fields'];

	if ( ! $is_new ) {
		$conflicts = fisha_studio_category_conflicts( $t, $draft );
		$open      = fisha_studio_open_conflicts( $conflicts, $resolutions );
		if ( $open ) return new WP_Error( 'fisha_studio_conflict', 'Some details changed on the site while you were editing.', array( 'status' => 409, 'conflicts' => $open ) );
		foreach ( $conflicts as $c ) {
			if ( 'live' === fisha_studio_resolution( $c, $resolutions ) ) unset( $changes[ $c['field'] ] );
		}
		$moved_slug   = isset( $changes['slug'] ) && $changes['slug'] !== $before['slug'];
		$moved_parent = isset( $changes['parent'] ) && (int) $changes['parent'] !== (int) $before['parent'];
		if ( ( $moved_slug || $moved_parent ) && ! $change_url ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( $moved_slug ? 'slug' : 'parent' => 'This category is live. Confirm the new address to move it.' ) ) );
		}
		if ( ! $changes ) return new WP_Error( 'fisha_studio_nothing', 'There are no changes to publish.', array( 'status' => 400 ) );
	}

	$after  = array_merge( $before, $changes );
	$errors = fisha_studio_category_validate( $after, $t );
	if ( $errors ) return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) );

	$old_paths = $is_new ? array() : fisha_studio_category_paths_below( $t );
	$was_top   = 0 === (int) $before['parent'];

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$args = array();
		if ( $after['name'] !== $before['name'] ) $args['name'] = $after['name'];
		if ( (int) $after['parent'] !== (int) $before['parent'] ) $args['parent'] = (int) $after['parent'];
		if ( $after['slug'] !== $before['slug'] ) $args['slug'] = fisha_studio_unique_cat_slug( (string) $after['slug'], $id );
		if ( $args ) {
			$r = wp_update_term( $id, 'product_cat', $args );
			if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
			if ( isset( $args['parent'] ) ) update_term_meta( $id, 'order', fisha_studio_category_next_order( (int) $args['parent'], $id ) );
		}
		if ( $after['lead'] !== $before['lead'] ) fisha_studio_category_set_meta( $id, 'fisha_lead', (string) $after['lead'] );
		if ( $after['hero_ids'] !== $before['hero_ids'] ) fisha_studio_category_set_meta( $id, 'fisha_hero_ids', implode( ',', array_map( 'intval', $after['hero_ids'] ) ) );
		if ( $after['shown'] !== $before['shown'] ) update_term_meta( $id, FISHA_CAT_SHOWN, $after['shown'] ? '1' : '0' );
		clean_term_cache( $id, 'product_cat' );
		fisha_studio_category_write_auto( get_term( $id, 'product_cat' ), $after, $is_new ? null : $before );
		if ( $is_new ) {
			delete_term_meta( $id, FISHA_CAT_UNPUBLISHED );
			delete_term_meta( $id, '_fisha_studio_slug_manual' );
		} else {
			fisha_studio_category_remember_paths( $old_paths );
		}
		delete_term_meta( $id, FISHA_STUDIO_DRAFT_META );
		do_action( 'fisha_studio_before_category_commit', $id );
		if ( ! fisha_studio_log( 'category', $id, $is_new ? 'create' : 'publish', $is_new ? null : $before, fisha_studio_category_values( get_term( $id, 'product_cat' ) ) ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_term_cache( array_keys( $old_paths ) ?: array( $id ), 'product_cat' );
		wp_cache_delete( $id, 'term_meta' );
		fisha_cat_cache_reset();
		fisha_studio_log_error( 'publish category ' . $id, $e );
		return new WP_Error( 'fisha_studio_publish_failed', 'Publishing failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}

	fisha_cat_cache_reset();
	$fresh = get_term( $id, 'product_cat' );
	fisha_studio_purge_category( $fresh, array_values( $old_paths ), $was_top || 0 === (int) $fresh->parent || $after['shown'] !== $before['shown'] );
	return array( 'id' => $id, 'url' => get_term_link( $fresh ) );
}

function fisha_studio_category_discard( int $id ): void {
	delete_term_meta( $id, FISHA_STUDIO_DRAFT_META );
}

/** [term id => address path] of a category and every category below it. */
function fisha_studio_category_paths_below( WP_Term $t ): array {
	$out = array( (int) $t->term_id => fisha_cat_path( $t ) );
	foreach ( get_term_children( $t->term_id, 'product_cat' ) as $cid ) {
		$c = fisha_studio_category( (int) $cid );
		if ( $c ) $out[ (int) $cid ] = fisha_cat_path( $c );
	}
	return $out;
}

/** After a move: each category whose address changed keeps its previous one as an old path, which redirects. */
function fisha_studio_category_remember_paths( array $old ): void {
	foreach ( $old as $tid => $path ) {
		clean_term_cache( (int) $tid, 'product_cat' );
		$t = fisha_studio_category( (int) $tid );
		if ( ! $t || '' === $path || fisha_cat_path( $t ) === $path ) continue;
		if ( ! in_array( $path, get_term_meta( (int) $tid, FISHA_CAT_OLD_PATHS ), true ) ) add_term_meta( (int) $tid, FISHA_CAT_OLD_PATHS, $path );
	}
}

/**
 * Clears the page cache for a category: its page, its parents, Shop, Home and any old addresses. $everywhere when the
 * header and footer menus may change (they are on every page).
 */
function fisha_studio_purge_category( WP_Term $t, array $old_paths = array(), bool $everywhere = false ): void {
	if ( $everywhere ) {
		do_action( 'litespeed_purge_all' );
		return;
	}
	$urls = array( get_permalink( wc_get_page_id( 'shop' ) ), home_url( '/' ) );
	foreach ( array_merge( array( (int) $t->term_id ), get_ancestors( $t->term_id, 'product_cat' ) ) as $tid ) {
		$link = get_term_link( (int) $tid, 'product_cat' );
		if ( ! is_wp_error( $link ) ) $urls[] = $link;
	}
	foreach ( $old_paths as $p ) $urls[] = home_url( '/' . $p . '/' );
	foreach ( array_unique( array_filter( $urls ) ) as $url ) do_action( 'litespeed_purge_url', $url );
}

/** Show on site, at once for a published category (and out of its draft); a never-published one only changes what Publish does. @return true|WP_Error */
function fisha_studio_category_set_shown( int $id, bool $shown ) {
	$t = fisha_studio_category( $id );
	if ( ! $t ) return fisha_studio_category_missing();
	if ( fisha_cat_is_unpublished( $t ) ) {
		fisha_studio_category_edit( $t, array( 'shown' => $shown ) );
		return true;
	}
	$was = '1' === (string) get_term_meta( $id, FISHA_CAT_SHOWN, true );
	update_term_meta( $id, FISHA_CAT_SHOWN, $shown ? '1' : '0' );
	$d = fisha_studio_draft_get( $id, 'term' );
	unset( $d['fields']['shown'], $d['base']['shown'] );
	fisha_studio_draft_put( $id, $d, 'term' );
	fisha_studio_log( 'category', $id, 'shown', array( 'shown' => $was ), array( 'shown' => $shown ) );
	fisha_cat_cache_reset();
	fisha_studio_purge_category( $t, array(), true );
	return true;
}

/** Sets the order of the categories under $parent; $ids must be all of them, in the new order. @return true|WP_Error */
function fisha_studio_category_reorder( int $parent, array $ids ) {
	$kids = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false, 'fields' => 'ids' ) );
	$kids = is_wp_error( $kids ) ? array() : array_map( 'intval', $kids );
	$ids  = array_values( array_map( 'intval', $ids ) );
	$a = $kids;
	$b = $ids;
	sort( $a );
	sort( $b );
	if ( $a !== $b ) return new WP_Error( 'fisha_studio_stale', 'The categories changed meanwhile. Please reload and try again.', array( 'status' => 409 ) );
	$before = array();
	$after  = array();
	foreach ( $ids as $i => $cid ) {
		$before[ $cid ] = (int) get_term_meta( $cid, 'order', true );
		$after[ $cid ]  = $i + 1;
		update_term_meta( $cid, 'order', $i + 1 );
	}
	fisha_studio_log( 'category', $parent, 'reorder', $before, $after );
	do_action( 'litespeed_purge_all' );
	return true;
}

/** Products filed directly in this category (any status). @return int[] */
function fisha_studio_category_direct_products( int $id ): array {
	$ids = get_objects_in_term( $id, 'product_cat' );
	return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
}

/** Its products that have no other category. @return int[] */
function fisha_studio_category_orphans( int $id ): array {
	return array_values( array_filter( fisha_studio_category_direct_products( $id ), function ( $pid ) use ( $id ) {
		return array( $id ) === array_map( 'intval', (array) wp_get_object_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) ) );
	} ) );
}

/**
 * Deletes a category. Products that were only in it move to $move_to (required then); its sub-categories move up to its
 * parent; its address and old addresses redirect to the parent's page, or the Shop. @return true|WP_Error
 */
function fisha_studio_category_delete( int $id, int $move_to = 0 ) {
	$t = fisha_studio_category( $id );
	if ( ! $t ) return fisha_studio_category_missing();
	if ( (int) get_option( 'default_product_cat' ) === $id ) {
		return new WP_Error( 'fisha_studio_default_cat', 'This is the shop\'s default category, so it cannot be deleted.', array( 'status' => 400 ) );
	}
	$orphans = fisha_studio_category_orphans( $id );
	if ( $orphans ) {
		$target = fisha_studio_category( $move_to );
		if ( ! $target || $move_to === $id || in_array( $id, array_map( 'intval', get_ancestors( $move_to, 'product_cat' ) ), true ) ) {
			return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'move_to' => 'Choose where its products go.' ) ) );
		}
	}
	$was_new = fisha_cat_is_unpublished( $t );
	$before  = fisha_studio_category_values( $t );
	$paths   = fisha_studio_category_paths_below( $t );
	$own     = array_merge( array( $paths[ $id ] ), get_term_meta( $id, FISHA_CAT_OLD_PATHS ) );
	unset( $paths[ $id ] );
	$parent = (int) $t->parent;

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		foreach ( $orphans as $pid ) {
			$r = wp_set_object_terms( $pid, array( $move_to ), 'product_cat', true );
			if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
		}
		$kids = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $id, 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( is_wp_error( $kids ) ? array() : $kids as $cid ) {
			$r = wp_update_term( (int) $cid, 'product_cat', array( 'parent' => $parent ) );
			if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
		}
		$r = wp_delete_term( $id, 'product_cat' );
		if ( true !== $r ) throw new RuntimeException( is_wp_error( $r ) ? $r->get_error_message() : 'delete failed' );
		fisha_studio_category_remember_paths( $paths );
		if ( ! $was_new ) {
			$deleted = get_option( 'fisha_deleted_cat_paths', array() );
			if ( ! is_array( $deleted ) ) $deleted = array();
			foreach ( $own as $p ) {
				if ( '' !== $p ) $deleted[ $p ] = $parent;
			}
			update_option( 'fisha_deleted_cat_paths', $deleted, false );
		}
		if ( ! fisha_studio_log( 'category', $id, 'delete', array_merge( $before, array( 'products_moved' => $orphans, 'move_to' => $move_to ) ), null ) ) {
			throw new RuntimeException( 'audit log write failed: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed: ' . $wpdb->last_error );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		clean_term_cache( array_merge( array( $id ), array_keys( $paths ) ), 'product_cat' );
		foreach ( $orphans as $pid ) clean_object_term_cache( $pid, 'product' );
		wp_cache_delete( 'fisha_deleted_cat_paths', 'options' );
		fisha_cat_cache_reset();
		fisha_studio_log_error( 'delete category ' . $id, $e );
		return new WP_Error( 'fisha_studio_delete_failed', 'Deleting failed, so nothing was changed. Please try again.', array( 'status' => 500 ) );
	}
	fisha_cat_cache_reset();
	do_action( 'litespeed_purge_all' );
	return true;
}
