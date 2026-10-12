<?php
fs_test( 'gates: owner is sent from wp-admin to the studio', function () {
	$owner = fs_user( FISHA_STUDIO_ROLE );
	fs_eq( home_url( '/studio/' ), fisha_studio_admin_gate_target( $owner, 'index.php' ) );
	fs_eq( home_url( '/studio/' ), fisha_studio_admin_gate_target( $owner, 'profile.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( $owner, 'admin-ajax.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( $owner, 'admin-post.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( $owner, 'async-upload.php' ) );
	fs_eq( null, fisha_studio_admin_gate_target( fs_user( 'administrator' ), 'index.php' ) );
} );

fs_test( 'gates: owner may only call studio, store and public site REST routes', function () {
	$owner = fs_user( FISHA_STUDIO_ROLE );
	fs_true( fisha_studio_rest_allowed( $owner, '/fisha-studio/v1/products' ) );
	fs_true( fisha_studio_rest_allowed( $owner, '/wc/store/v1/cart' ) );
	fs_true( fisha_studio_rest_allowed( $owner, '/fisha/v1/drop' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/wp/v2/users' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/wc/v3/products' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/fisha-studio/v1evil' ) );
	fs_false( fisha_studio_rest_allowed( $owner, '/' ) );
	fs_true( fisha_studio_rest_allowed( fs_user( 'administrator' ), '/wp/v2/users' ) );
} );

fs_test( 'gates: core REST is refused for the owner and allowed for admins', function () {
	fs_as( FISHA_STUDIO_ROLE );
	list( $status, $data ) = fs_rest( 'GET', '/wp/v2/users/me' );
	fs_eq( 403, $status );
	fs_eq( 'fisha_studio_forbidden', $data['code'] );
	fs_as( 'administrator' );
	list( $status ) = fs_rest( 'GET', '/wp/v2/users/me' );
	fs_eq( 200, $status );
} );

fs_test( 'gates: no admin bar, short sessions and studio landing for the owner', function () {
	$owner = fs_as( FISHA_STUDIO_ROLE );
	fs_false( apply_filters( 'show_admin_bar', true ), 'admin bar hidden' );
	fs_eq( 12 * HOUR_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $owner->ID, false ) );
	fs_eq( home_url( '/studio/' ), apply_filters( 'login_redirect', admin_url(), '', $owner ) );
	$admin = fs_as( 'administrator' );
	fs_eq( 2 * DAY_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $admin->ID, false ) );
	fs_eq( admin_url(), apply_filters( 'login_redirect', admin_url(), '', $admin ) );
} );
