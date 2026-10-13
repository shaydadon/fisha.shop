<?php
function fs_ready_cat( string $name, int $parent = 0 ): WP_Term {
	$t = fs_studio_cat( $name, $parent );
	fisha_studio_category_edit( $t, array( 'lead' => 'A short intro. Second sentence.', 'hero_ids' => array( fs_image() ) ) );
	return get_term( $t->term_id, 'product_cat' );
}
function fs_keep_deleted_paths(): void {
	$before = get_option( 'fisha_deleted_cat_paths', null );
	fs_on_cleanup( function () use ( $before ) { null === $before ? delete_option( 'fisha_deleted_cat_paths' ) : update_option( 'fisha_deleted_cat_paths', $before, false ); } );
}

fs_test( 'categories publish: a new category goes live and is logged', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Pub New' );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	fs_eq( '', (string) get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'published' );
	fs_eq( home_url( '/product-category/zz-test-pub-new/' ), $r['url'] );
	fs_eq( 'live', fisha_studio_category_state( get_term( $t->term_id, 'product_cat' ) ) );
	fs_eq( 'create', fisha_studio_log_for( 'category', $t->term_id, 1 )[0]['action'] );
} );

fs_test( 'categories publish: missing intro or photo is refused with field errors, nothing written', function () {
	$t = fs_studio_cat( 'zz-test Pub Bad' );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_true( is_wp_error( $r ) );
	fs_eq( 422, $r->get_error_data()['status'] );
	fs_true( isset( $r->get_error_data()['fields']['lead'], $r->get_error_data()['fields']['hero_ids'] ) );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'still not published' );
} );

fs_test( 'categories publish: draft changes go live; a moved value asks first', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Pub Draft' );
	fisha_studio_publish_category( $t->term_id );
	$t = get_term( $t->term_id, 'product_cat' );
	fisha_studio_category_edit( $t, array( 'lead' => 'New intro.' ), array( 'lead' => 'A short intro. Second sentence.' ) );
	update_term_meta( $t->term_id, 'fisha_lead', 'Someone else.' );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_eq( 409, $r->get_error_data()['status'] );
	$r = fisha_studio_publish_category( $t->term_id, array( 'lead' => array( 'choice' => 'mine', 'live' => 'Someone else.' ) ) );
	fs_false( is_wp_error( $r ) );
	fs_eq( 'New intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
	fs_eq( array(), fisha_studio_draft_get( $t->term_id, 'term' )['fields'], 'draft cleared' );
	fs_eq( 400, fisha_studio_publish_category( $t->term_id )->get_error_data()['status'], 'nothing left to publish' );
} );

fs_test( 'categories publish: renaming a live category keeps hand-written design fields', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_cat( 'pub-hand' );
	update_term_meta( $t->term_id, 'fisha_lead', 'Hand intro.' );
	update_term_meta( $t->term_id, 'fisha_hero_ids', (string) fs_image() );
	update_term_meta( $t->term_id, 'fisha_h1', 'Fisha <span>Hand</span>' );
	update_term_meta( $t->term_id, 'fisha_seo_title', 'Hand written title' );
	fisha_studio_category_edit( $t, array( 'name' => 'zz-test pub-hand renamed' ) );
	fs_false( is_wp_error( fisha_studio_publish_category( $t->term_id ) ) );
	fs_eq( 'zz-test pub-hand renamed', get_term( $t->term_id, 'product_cat' )->name );
	fs_eq( 'Fisha <span>Hand</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
	fs_eq( 'Hand written title', get_term_meta( $t->term_id, 'fisha_seo_title', true ) );
	fs_eq( 'zz-test-pub-hand', get_term( $t->term_id, 'product_cat' )->slug, 'address unchanged by a rename' );
} );

fs_test( 'categories publish: an automatic heading follows a rename', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Auto Old' );
	fisha_studio_publish_category( $t->term_id );
	fisha_studio_category_edit( get_term( $t->term_id, 'product_cat' ), array( 'name' => 'zz-test Auto New' ) );
	fisha_studio_publish_category( $t->term_id );
	fs_eq( 'zz-test Auto <span>New</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
} );

fs_test( 'categories publish: moving a live category remembers old paths of it and its children', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$a = fs_ready_cat( 'zz-test Move A' );   fisha_studio_publish_category( $a->term_id );
	$b = fs_ready_cat( 'zz-test Move B' );   fisha_studio_publish_category( $b->term_id );
	$k = fs_ready_cat( 'zz-test Move Kid', $a->term_id ); fisha_studio_publish_category( $k->term_id );
	$old_a = fisha_cat_path( get_term( $a->term_id, 'product_cat' ) );
	$old_k = fisha_cat_path( get_term( $k->term_id, 'product_cat' ) );
	fisha_studio_category_edit( get_term( $a->term_id, 'product_cat' ), array( 'parent' => $b->term_id ) );
	$r = fisha_studio_publish_category( $a->term_id );
	fs_eq( 422, $r->get_error_data()['status'], 'needs the owner to confirm the new address' );
	fs_true( isset( $r->get_error_data()['fields']['parent'] ) );
	fs_false( is_wp_error( fisha_studio_publish_category( $a->term_id, array(), true ) ) );
	fs_eq( 'product-category/zz-test-move-b/zz-test-move-a', fisha_cat_path( get_term( $a->term_id, 'product_cat' ) ) );
	fs_eq( array( $old_a ), get_term_meta( $a->term_id, 'fisha_old_paths' ) );
	fs_eq( array( $old_k ), get_term_meta( $k->term_id, 'fisha_old_paths' ), 'child too' );
	fs_eq( get_term_link( $k->term_id, 'product_cat' ), fisha_cat_redirect_target( $old_k ) );
} );

fs_test( 'categories publish: a failure rolls everything back', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_ready_cat( 'zz-test Pub Fail' );
	$boom = function () { throw new RuntimeException( 'forced' ); };
	add_action( 'fisha_studio_before_category_commit', $boom );
	fs_on_cleanup( function () use ( $boom ) { remove_action( 'fisha_studio_before_category_commit', $boom ); } );
	$r = fisha_studio_publish_category( $t->term_id );
	fs_eq( 500, $r->get_error_data()['status'] );
	clean_term_cache( $t->term_id, 'product_cat' );
	wp_cache_delete( $t->term_id, 'term_meta' );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'still not published' );
} );

fs_test( 'categories: Show on site switches a live category at once and drops it from the draft', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$t = fs_cat( 'shown-switch' );
	fisha_studio_category_edit( $t, array( 'shown' => false, 'lead' => 'Draft intro.' ) );
	fs_true( true === fisha_studio_category_set_shown( $t->term_id, false ) );
	fs_eq( '0', get_term_meta( $t->term_id, 'fisha_shown', true ) );
	$d = fisha_studio_draft_get( $t->term_id, 'term' );
	fs_false( isset( $d['fields']['shown'] ), 'shown left the draft' );
	fs_true( isset( $d['fields']['lead'] ), 'other edits kept' );
	fs_eq( 'shown', fisha_studio_log_for( 'category', $t->term_id, 1 )[0]['action'] );
} );

fs_test( 'categories: reorder needs every child and numbers them from 1', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$top = fs_cat( 'order-top' );
	$a = fs_cat( 'order-a', $top->term_id ); $b = fs_cat( 'order-b', $top->term_id ); $c = fs_cat( 'order-c', $top->term_id );
	fs_eq( 409, fisha_studio_category_reorder( $top->term_id, array( $a->term_id, $b->term_id ) )->get_error_data()['status'], 'missing one' );
	fs_true( true === fisha_studio_category_reorder( $top->term_id, array( $c->term_id, $a->term_id, $b->term_id ) ) );
	fs_eq( array( '1', '2', '3' ), array( get_term_meta( $c->term_id, 'order', true ), get_term_meta( $a->term_id, 'order', true ), get_term_meta( $b->term_id, 'order', true ) ) );
} );

fs_test( 'categories delete: products keep other categories, orphans move, children move up', function () {
	fs_as( FISHA_STUDIO_ROLE );
	fs_keep_deleted_paths();
	$top   = fs_cat( 'del-top' );
	$gone  = fs_cat( 'del-gone', $top->term_id );
	$kid   = fs_cat( 'del-kid', $gone->term_id );
	$other = fs_cat( 'del-other' );
	$to    = fs_cat( 'del-to' );
	$only  = fs_product( array( 'category_ids' => array( $gone->term_id ) ) );
	$both  = fs_product( array( 'category_ids' => array( $gone->term_id, $other->term_id ) ) );
	$inkid = fs_product( array( 'category_ids' => array( $kid->term_id ) ) );
	$path  = fisha_cat_path( $gone );
	fs_eq( 422, fisha_studio_category_delete( $gone->term_id )->get_error_data()['status'], 'must choose where products go' );
	fs_eq( array( $only->get_id() ), fisha_studio_category_orphans( $gone->term_id ) );
	fs_true( true === fisha_studio_category_delete( $gone->term_id, $to->term_id ) );
	fs_false( (bool) get_term( $gone->term_id, 'product_cat' ), 'deleted' );
	fs_eq( array( $to->term_id ), wp_get_object_terms( $only->get_id(), 'product_cat', array( 'fields' => 'ids' ) ), 'orphan moved' );
	fs_eq( array( $other->term_id ), wp_get_object_terms( $both->get_id(), 'product_cat', array( 'fields' => 'ids' ) ), 'other category kept, no Uncategorized' );
	fs_eq( array( $kid->term_id ), wp_get_object_terms( $inkid->get_id(), 'product_cat', array( 'fields' => 'ids' ) ) );
	fs_eq( (int) $top->term_id, (int) get_term( $kid->term_id, 'product_cat' )->parent, 'child moved up' );
	fs_eq( get_term_link( $top ), fisha_cat_redirect_target( $path ), 'old address goes to the parent page' );
	fs_eq( 'delete', fisha_studio_log_for( 'category', $gone->term_id, 1 )[0]['action'] );
} );

fs_test( 'categories delete: the default category is refused; an empty never-published one leaves no redirect', function () {
	fs_as( FISHA_STUDIO_ROLE );
	fs_keep_deleted_paths();
	fs_eq( 400, fisha_studio_category_delete( (int) get_option( 'default_product_cat' ) )->get_error_data()['status'] );
	$t = fs_studio_cat( 'zz-test Del New' );
	$path = fisha_cat_path( $t );
	fs_true( true === fisha_studio_category_delete( $t->term_id ) );
	fs_false( array_key_exists( $path, (array) get_option( 'fisha_deleted_cat_paths', array() ) ) );
} );
