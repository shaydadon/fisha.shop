<?php
fs_test( 'categories: create makes a hidden, shown-when-published category with automatic design fields', function () {
	$w = get_term_by( 'slug', 'wearables', 'product_cat' );
	$t = fs_studio_cat( 'zz-test Winter Scarves', $w->term_id );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_studio_hidden', true ), 'not published yet' );
	fs_eq( '1', get_term_meta( $t->term_id, '_fisha_studio_created', true ) );
	fs_eq( '1', get_term_meta( $t->term_id, 'fisha_shown', true ), 'Show on site defaults on' );
	fs_eq( 'zz-test-winter-scarves', $t->slug );
	fs_eq( 'zz-test Winter <span>Scarves</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
	fs_eq( 'Wearables', get_term_meta( $t->term_id, 'fisha_eyebrow', true ) );
	fs_eq( 'new', fisha_studio_category_state( $t ) );
	$v = fisha_studio_category_values( $t );
	fs_eq( array( 'name', 'slug', 'parent', 'lead', 'hero_ids', 'shown' ), array_keys( $v ) );
	fs_eq( (int) $w->term_id, $v['parent'] );
	fs_true( (int) get_term_meta( $t->term_id, 'order', true ) > 0, 'last among its siblings' );
} );

fs_test( 'categories: new names never clash, a sibling\'s name is refused', function () {
	$a = fs_studio_cat( 'zz-test Clash' );
	$b = fs_studio_cat( 'zz-test Clash' );
	fs_eq( 'zz-test Clash 2', $b->name );
	$errors = fisha_studio_category_edit( $b, array( 'name' => 'zz-test Clash' ) );
	fs_true( isset( $errors['name'] ), 'refused' );
	fs_eq( 'zz-test Clash 2', get_term( $b->term_id, 'product_cat' )->name, 'name unchanged' );
	fs_true( isset( fisha_studio_category_validate( array_merge( fisha_studio_category_values( $b ), array( 'name' => 'ZZ-TEST clash' ) ), $b )['name'] ), 'case-insensitive' );
} );

fs_test( 'categories: automatic fields follow the name, parent and intro', function () {
	$a = fisha_studio_category_auto( array( 'name' => 'Hats & Caps', 'parent' => 0, 'lead' => 'Caps with the fish. Embroidered by hand.' ) );
	fs_eq( 'Hats &amp; <span>Caps</span>', $a['fisha_h1'] );
	fs_eq( 'Fisha Shop', $a['fisha_eyebrow'] );
	fs_eq( 'Hats & Caps | Caps with the fish.', $a['fisha_seo_title'] );
	fs_eq( 'Caps with the fish. Embroidered by hand.', $a['fisha_home_line'] );
	fs_eq( '<span>Socks</span>', fisha_studio_category_auto( array( 'name' => 'Socks', 'parent' => 0, 'lead' => '' ) )['fisha_h1'] );
	$long = fisha_studio_category_auto( array( 'name' => 'Collectibles', 'parent' => 0, 'lead' => 'Hand-painted lighters and small objects carrying the little hand-drawn fish everywhere.' ) );
	fs_true( mb_strlen( $long['fisha_seo_title'] ) <= 60, 'SEO title at most 60' );
	fs_false( '|' === substr( rtrim( $long['fisha_seo_title'] ), -1 ), 'no dangling separator' );
} );

fs_test( 'categories: edits to a never-published category apply at once; the address follows the name until typed', function () {
	$t = fs_studio_cat( 'zz-test Edit Me' );
	$img = fs_image();
	$errors = fisha_studio_category_edit( $t, array( 'name' => 'zz-test Edited', 'lead' => 'A short intro.', 'hero_ids' => array( $img ), 'shown' => false ) );
	fs_eq( array(), $errors );
	$t = get_term( $t->term_id, 'product_cat' );
	fs_eq( 'zz-test Edited', $t->name );
	fs_eq( 'zz-test-edited', $t->slug, 'slug follows the name' );
	fs_eq( 'A short intro.', get_term_meta( $t->term_id, 'fisha_lead', true ) );
	fs_eq( (string) $img, get_term_meta( $t->term_id, 'fisha_hero_ids', true ) );
	fs_eq( '0', get_term_meta( $t->term_id, 'fisha_shown', true ) );
	fs_eq( 'zz-test <span>Edited</span>', get_term_meta( $t->term_id, 'fisha_h1', true ) );
	fs_eq( 'zz-test Edited | A short intro.', get_term_meta( $t->term_id, 'fisha_seo_title', true ) );
	fisha_studio_category_edit( $t, array( 'slug' => 'zz-test-my-own' ) );
	fisha_studio_category_edit( get_term( $t->term_id, 'product_cat' ), array( 'name' => 'zz-test Renamed' ) );
	fs_eq( 'zz-test-my-own', get_term( $t->term_id, 'product_cat' )->slug, 'typed address kept' );
	fs_eq( array(), fisha_studio_draft_get( $t->term_id, 'term' )['fields'], 'no draft for a never-published one' );
} );

fs_test( 'categories: a category cannot move inside itself', function () {
	$top = fs_studio_cat( 'zz-test Loop Top' );
	$kid = fs_studio_cat( 'zz-test Loop Kid', $top->term_id );
	$errors = fisha_studio_category_edit( $top, array( 'parent' => $kid->term_id ) );
	fs_true( isset( $errors['parent'] ) );
	fs_eq( 0, (int) get_term( $top->term_id, 'product_cat' )->parent );
	fs_true( isset( fisha_studio_category_edit( $top, array( 'parent' => $top->term_id ) )['parent'] ), 'not its own parent' );
} );

fs_test( 'categories: edits to a published category wait in a draft with the seen base', function () {
	$t = fs_cat( 'draft-live' );
	update_term_meta( $t->term_id, 'fisha_lead', 'Live intro.' );
	fisha_studio_category_edit( $t, array( 'lead' => 'Draft intro.', 'name' => 'zz-test draft-live renamed' ), array( 'lead' => 'Live intro.' ) );
	fs_eq( 'Live intro.', get_term_meta( $t->term_id, 'fisha_lead', true ), 'live untouched' );
	fs_eq( 'zz-test draft-live', get_term( $t->term_id, 'product_cat' )->name, 'live name untouched' );
	$d = fisha_studio_draft_get( $t->term_id, 'term' );
	fs_eq( 'Draft intro.', $d['fields']['lead'] );
	fs_eq( 'Live intro.', $d['base']['lead'] );
	fs_eq( 'changed', fisha_studio_category_state( $t ) );
	update_term_meta( $t->term_id, 'fisha_lead', 'Changed elsewhere.' );
	$c = fisha_studio_category_conflicts( $t, $d );
	fs_eq( array( 'lead' ), wp_list_pluck( $c, 'field' ) );
	fs_eq( 'Intro', $c[0]['label'] );
	fisha_studio_category_edit( $t, array( 'name' => 'zz-test draft-live' ) );
	fs_false( isset( fisha_studio_draft_get( $t->term_id, 'term' )['fields']['name'] ), 'back to live value leaves the draft' );
} );

fs_test( 'categories: validation needs a name, an intro and a hero photo unless products have photos', function () {
	$t = fs_studio_cat( 'zz-test Validate' );
	$v = fisha_studio_category_values( $t );
	$e = fisha_studio_category_validate( array_merge( $v, array( 'name' => '' ) ), $t );
	fs_true( isset( $e['name'], $e['lead'], $e['hero_ids'] ), 'name, intro and photo' );
	fs_product( array( 'category_ids' => array( $t->term_id ), 'image_id' => fs_image() ) );
	$e = fisha_studio_category_validate( array_merge( $v, array( 'lead' => 'Intro.' ) ), $t );
	fs_false( isset( $e['hero_ids'] ), 'product photo is enough' );
	fs_true( isset( fisha_studio_category_validate( array_merge( $v, array( 'lead' => 'x', 'hero_ids' => array( fs_image(), fs_image(), fs_image() ) ) ), $t )['hero_ids'] ), 'at most two' );
	fs_true( isset( fisha_studio_category_validate( array_merge( $v, array( 'lead' => 'x', 'slug' => 'studio' ) ), $t )['slug'] ), 'reserved address' );
} );
