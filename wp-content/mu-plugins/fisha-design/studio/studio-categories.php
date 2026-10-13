<?php
/**
 * Fisha Studio categories: the fields the owner edits (name, address, parent, intro, hero photos, Show on site), the design
 * fields filled automatically (heading, small line above it, Google title, Home tagline), creating a category, and edits.
 * A never-published category (term meta fisha_studio_hidden = 1) takes edits at once and the public site cannot see it;
 * a published one keeps edits in a draft (term meta _fisha_studio_draft) until Publish (studio-categories-publish.php).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_studio_category( int $id ): ?WP_Term {
	$t = $id ? get_term( $id, 'product_cat' ) : null;
	return $t instanceof WP_Term ? $t : null;
}

function fisha_studio_category_fields(): array {
	static $fields = null;
	if ( null !== $fields ) return $fields;
	$meta = function ( string $key ) {
		return function ( WP_Term $t ) use ( $key ) { return (string) get_term_meta( $t->term_id, $key, true ); };
	};
	$fields = array(
		'name'     => array( 'type' => 'text', 'label' => 'Name', 'max' => 60, 'get' => function ( WP_Term $t ) { return $t->name; } ),
		'slug'     => array( 'type' => 'slug', 'label' => 'Address', 'get' => function ( WP_Term $t ) { return $t->slug; } ),
		'parent'   => array( 'type' => 'int', 'label' => 'Parent', 'min' => 0, 'get' => function ( WP_Term $t ) { return (int) $t->parent; } ),
		'lead'     => array( 'type' => 'text', 'label' => 'Intro', 'max' => 300, 'get' => $meta( 'fisha_lead' ) ),
		'hero_ids' => array( 'type' => 'attachments', 'label' => 'Hero photos', 'get' => $meta( 'fisha_hero_ids' ) ),
		'shown'    => array( 'type' => 'bool', 'label' => 'Show on site', 'get' => function ( WP_Term $t ) { return '1' === (string) get_term_meta( $t->term_id, FISHA_CAT_SHOWN, true ); } ),
	);
	return $fields;
}

/** Live values of every field, cleaned to the shapes the Studio sends. */
function fisha_studio_category_values( WP_Term $t ): array {
	$out = array();
	foreach ( fisha_studio_category_fields() as $key => $def ) $out[ $key ] = fisha_studio_clean( $def, call_user_func( $def['get'], $t ) )[0];
	return $out;
}

function fisha_studio_category_state( WP_Term $t ): string {
	if ( fisha_cat_is_unpublished( $t ) ) return 'new';
	return fisha_studio_draft_get( $t->term_id, 'term' )['fields'] ? 'changed' : 'live';
}

/** The design fields the Studio fills for a category from its name, parent and intro (spec §4). */
function fisha_studio_category_auto( array $v ): array {
	$name  = trim( (string) $v['name'] );
	$words = preg_split( '/\s+/u', $name );
	$last  = (string) array_pop( $words );
	$h1    = ( $words ? esc_html( implode( ' ', $words ) ) . ' ' : '' ) . '<span>' . esc_html( $last ) . '</span>';
	$parent = ! empty( $v['parent'] ) ? fisha_studio_category( (int) $v['parent'] ) : null;
	$lead   = trim( (string) $v['lead'] );
	$first  = preg_match( '/^.*?[.!?](?=\s|$)/u', $lead, $m ) ? $m[0] : $lead;
	$seo    = '' !== $first ? $name . ' | ' . $first : $name;
	if ( mb_strlen( $seo ) > 60 ) {
		$seo = mb_substr( $seo, 0, 60 );
		$cut = mb_strrpos( $seo, ' ' );
		if ( false !== $cut && $cut > 20 ) $seo = mb_substr( $seo, 0, $cut );
		$seo = rtrim( $seo, ' ,.;:-|' );
	}
	return array(
		'fisha_h1'        => $h1,
		'fisha_eyebrow'   => $parent ? fisha_studio_plain( $parent->name ) : 'Fisha Shop',
		'fisha_seo_title' => $seo,
		'fisha_home_line' => $lead,
	);
}

/**
 * Writes the automatic design fields. With no $old (never published) all of them; otherwise only a field whose stored
 * value still equals the automatic value for $old, so hand-written headings and taglines are never overwritten.
 */
function fisha_studio_category_write_auto( WP_Term $t, array $new, ?array $old ): void {
	$next = fisha_studio_category_auto( $new );
	$prev = null === $old ? null : fisha_studio_category_auto( $old );
	foreach ( $next as $key => $val ) {
		if ( null !== $prev && (string) get_term_meta( $t->term_id, $key, true ) !== $prev[ $key ] ) continue;
		fisha_studio_category_set_meta( $t->term_id, $key, $val );
	}
}

/** Stores a text meta value, or removes it when empty. */
function fisha_studio_category_set_meta( int $id, string $key, string $value ): void {
	if ( '' === $value ) delete_term_meta( $id, $key );
	else update_term_meta( $id, $key, wp_slash( $value ) );
}

function fisha_studio_category_slug_taken( string $slug, int $id ): bool {
	$other = get_term_by( 'slug', $slug, 'product_cat' );
	return $other instanceof WP_Term && (int) $other->term_id !== $id;
}

/** $slug, or $slug-2, -3… when another category (or the site) uses it. */
function fisha_studio_unique_cat_slug( string $slug, int $id ): string {
	$base = in_array( $slug, fisha_studio_reserved_slugs(), true ) ? $slug . '-2' : $slug;
	$try  = $base;
	for ( $n = 2; fisha_studio_category_slug_taken( $try, $id ); $n++ ) $try = $base . '-' . $n;
	return $try;
}

/** Whether another category under the same parent has this name (case does not matter). */
function fisha_studio_category_name_taken( string $name, int $parent, int $id ): bool {
	$siblings = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false ) );
	foreach ( is_wp_error( $siblings ) ? array() : $siblings as $s ) {
		if ( (int) $s->term_id !== $id && 0 === strcasecmp( fisha_studio_plain( $s->name ), trim( $name ) ) ) return true;
	}
	return false;
}

function fisha_studio_category_parent_error( WP_Term $t, int $parent ): ?string {
	if ( ! $parent ) return null;
	if ( ! fisha_studio_category( $parent ) ) return 'That parent category no longer exists.';
	if ( $parent === (int) $t->term_id || in_array( (int) $t->term_id, array_map( 'intval', get_ancestors( $parent, 'product_cat' ) ), true ) ) {
		return 'A category cannot sit inside itself.';
	}
	return null;
}

/** Whether a published product of this category (or below it) has a photo, which the page uses when there is no hero photo. */
function fisha_studio_category_has_photo_products( WP_Term $t ): bool {
	foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 20, 'category' => array( $t->slug ) ) ) as $p ) {
		if ( $p->get_image_id() ) return true;
	}
	return false;
}

/** Errors for a complete set of values (live merged with the draft), keyed by field. */
function fisha_studio_category_validate( array $v, WP_Term $t ): array {
	$e = array();
	if ( '' === trim( (string) $v['name'] ) ) $e['name'] = 'Give the category a name.';
	elseif ( fisha_studio_category_name_taken( (string) $v['name'], (int) $v['parent'], (int) $t->term_id ) ) $e['name'] = 'Another category in the same place already has this name.';
	if ( '' === trim( (string) $v['lead'] ) ) $e['lead'] = 'Write a short intro (one or two sentences).';
	if ( count( (array) $v['hero_ids'] ) > 2 ) $e['hero_ids'] = 'Use one or two hero photos.';
	elseif ( ! $v['hero_ids'] && ! fisha_studio_category_has_photo_products( $t ) ) $e['hero_ids'] = 'Add a hero photo. A category without product photos needs one.';
	$parent = fisha_studio_category_parent_error( $t, (int) $v['parent'] );
	if ( $parent ) $e['parent'] = $parent;
	$slug = fisha_studio_slug_error( (string) $v['slug'] );
	if ( $slug ) $e['slug'] = $slug;
	elseif ( '' !== (string) $v['slug'] && fisha_studio_category_slug_taken( (string) $v['slug'], (int) $t->term_id ) ) $e['slug'] = 'Another category already uses this address.';
	return $e;
}

/** One more than the highest "order" among the categories under $parent (so a category lands last). */
function fisha_studio_category_next_order( int $parent, int $except ): int {
	$max      = 0;
	$siblings = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false, 'exclude' => array( $except ) ) );
	foreach ( is_wp_error( $siblings ) ? array() : $siblings as $s ) $max = max( $max, (int) get_term_meta( $s->term_id, 'order', true ) );
	return $max + 1;
}

/** @return WP_Term|WP_Error A never-published category, last among its siblings ("New category", "New category 2"… when no name is given). */
function fisha_studio_category_create( string $name = '', int $parent = 0 ) {
	$name = trim( sanitize_text_field( $name ) );
	$base = '' !== $name ? $name : 'New category';
	$try  = $base;
	for ( $n = 2; fisha_studio_category_name_taken( $try, $parent, 0 ); $n++ ) $try = $base . ' ' . $n;
	$r = wp_insert_term( $try, 'product_cat', array( 'parent' => $parent, 'slug' => fisha_studio_unique_cat_slug( fisha_studio_slug_from_name( $try ), 0 ) ) );
	if ( is_wp_error( $r ) ) return $r;
	$id = (int) $r['term_id'];
	update_term_meta( $id, FISHA_CAT_UNPUBLISHED, '1' );
	update_term_meta( $id, FISHA_STUDIO_CREATED_META, '1' );
	update_term_meta( $id, FISHA_CAT_SHOWN, '1' );
	update_term_meta( $id, 'order', fisha_studio_category_next_order( $parent, $id ) );
	fisha_cat_cache_reset();
	$t = get_term( $id, 'product_cat' );
	fisha_studio_category_write_auto( $t, fisha_studio_category_values( $t ), null );
	return $t;
}

function fisha_studio_category_draft_save( WP_Term $t, array $values, array $seen = array() ): array {
	$id = (int) $t->term_id;
	return fisha_studio_draft_put( $id, fisha_studio_draft_merge( fisha_studio_draft_get( $id, 'term' ), fisha_studio_category_values( $t ), $values, $seen ), 'term' );
}

function fisha_studio_category_conflicts( WP_Term $t, array $draft ): array {
	$labels = wp_list_pluck( fisha_studio_category_fields(), 'label' );
	return fisha_studio_draft_conflicts( $draft, fisha_studio_category_values( $t ), function ( string $k ) use ( $labels ) { return $labels[ $k ] ?? $k; } );
}

/**
 * The one entry point for an edit from the Studio ($values and $seen already cleaned). A move inside itself is refused
 * in both cases. A published category keeps the rest in its draft; a never-published one takes it at once (its address
 * follows its name until the owner types one). @return array Field errors for values that were not applied.
 */
function fisha_studio_category_edit( WP_Term $t, array $values, array $seen = array() ): array {
	$errors = array();
	if ( array_key_exists( 'parent', $values ) && ( $err = fisha_studio_category_parent_error( $t, (int) $values['parent'] ) ) ) {
		$errors['parent'] = $err;
		unset( $values['parent'] );
	}
	if ( ! fisha_cat_is_unpublished( $t ) ) {
		if ( $values ) fisha_studio_category_draft_save( $t, $values, $seen );
		return $errors;
	}

	$id  = (int) $t->term_id;
	$old = fisha_studio_category_values( $t );
	$new = array_merge( $old, $values );
	if ( array_key_exists( 'name', $values ) ) {
		if ( '' === trim( (string) $values['name'] ) ) $errors['name'] = 'Give the category a name.';
		elseif ( fisha_studio_category_name_taken( (string) $values['name'], (int) $new['parent'], $id ) ) $errors['name'] = 'Another category in the same place already has this name.';
		if ( isset( $errors['name'] ) ) $new['name'] = $old['name'];
	}
	if ( array_key_exists( 'slug', $values ) ) {
		$err = fisha_studio_slug_error( (string) $values['slug'] );
		if ( $err ) {
			$errors['slug'] = $err;
			$new['slug']    = $old['slug'];
		} else {
			update_term_meta( $id, '_fisha_studio_slug_manual', '' !== $values['slug'] ? '1' : '' );
		}
	}
	if ( ! get_term_meta( $id, '_fisha_studio_slug_manual', true ) ) $new['slug'] = fisha_studio_slug_from_name( (string) $new['name'] );

	$args = array();
	if ( $new['name'] !== $old['name'] ) $args['name'] = $new['name'];
	if ( (int) $new['parent'] !== (int) $old['parent'] ) $args['parent'] = (int) $new['parent'];
	if ( $new['slug'] !== $old['slug'] ) $args['slug'] = fisha_studio_unique_cat_slug( (string) $new['slug'], $id );
	if ( $args ) {
		$r = wp_update_term( $id, 'product_cat', $args );
		if ( is_wp_error( $r ) ) $errors[ isset( $args['name'] ) ? 'name' : 'slug' ] = $r->get_error_message();
		elseif ( isset( $args['parent'] ) ) update_term_meta( $id, 'order', fisha_studio_category_next_order( (int) $args['parent'], $id ) );
	}
	if ( array_key_exists( 'lead', $values ) ) fisha_studio_category_set_meta( $id, 'fisha_lead', (string) $values['lead'] );
	if ( array_key_exists( 'hero_ids', $values ) ) fisha_studio_category_set_meta( $id, 'fisha_hero_ids', implode( ',', array_map( 'intval', (array) $values['hero_ids'] ) ) );
	if ( array_key_exists( 'shown', $values ) ) update_term_meta( $id, FISHA_CAT_SHOWN, $values['shown'] ? '1' : '0' );
	clean_term_cache( $id, 'product_cat' );
	fisha_cat_cache_reset();
	$fresh = get_term( $id, 'product_cat' );
	fisha_studio_category_write_auto( $fresh, fisha_studio_category_values( $fresh ), null );
	return $errors;
}
