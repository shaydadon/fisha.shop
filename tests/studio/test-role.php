<?php
fs_test( 'role: owner has catalog caps only', function () {
	$role = get_role( FISHA_STUDIO_ROLE );
	fs_true( $role, 'role exists' );
	foreach ( array( 'read', 'upload_files', 'edit_products', 'edit_others_products', 'edit_published_products', 'publish_products', 'delete_products', 'delete_published_products', 'manage_product_terms', 'assign_product_terms', 'fisha_studio' ) as $cap ) {
		fs_true( $role->has_cap( $cap ), "has $cap" );
	}
	foreach ( array( 'manage_options', 'edit_posts', 'edit_pages', 'manage_woocommerce', 'edit_users', 'list_users', 'edit_shop_orders', 'install_plugins', 'activate_plugins', 'edit_theme_options', 'unfiltered_html', 'view_admin_dashboard' ) as $cap ) {
		fs_false( $role->has_cap( $cap ), "must not have $cap" );
	}
} );

fs_test( 'role: administrators can open the studio', function () {
	fs_true( get_role( 'administrator' )->has_cap( 'fisha_studio' ) );
} );

fs_test( 'role: owner can edit and trash products made by someone else', function () {
	$p    = fs_product();
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'zz-test page', 'post_status' => 'publish' ) );
	fs_on_cleanup( function () use ( $page ) { wp_delete_post( $page, true ); } );
	fs_as( FISHA_STUDIO_ROLE );
	fs_true( current_user_can( 'edit_post', $p->get_id() ), 'edit_post' );
	fs_true( current_user_can( 'delete_post', $p->get_id() ), 'delete_post' );
	fs_false( current_user_can( 'edit_post', $page ), 'cannot edit a normal page' );
} );

fs_test( 'role: create owner makes a fisha_owner user and refuses duplicates', function () {
	$email = 'zz-test-owner-' . strtolower( wp_generate_password( 5, false ) ) . '@example.test';
	$id = fisha_studio_create_owner( $email, '', wp_generate_password( 20 ) );
	fs_true( is_int( $id ), 'returns a user id' );
	fs_on_cleanup( function () use ( $id ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id ); } );
	fs_eq( array( FISHA_STUDIO_ROLE ), array_values( get_user_by( 'id', $id )->roles ) );
	fs_true( is_wp_error( fisha_studio_create_owner( $email ) ), 'duplicate email refused' );
	fs_true( is_wp_error( fisha_studio_create_owner( 'not-an-email' ) ), 'bad email refused' );
} );
