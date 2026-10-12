<?php
fs_test( 'boot: studio module is loaded', function () {
	fs_true( defined( 'FISHA_STUDIO_DIR' ), 'FISHA_STUDIO_DIR is defined' );
	fs_eq( 'fisha-studio/v1', FISHA_STUDIO_NS );
	fs_eq( 'fisha_owner', FISHA_STUDIO_ROLE );
} );

fs_test( 'boot: studio routes are recognised', function () {
	fs_eq( 'app', fisha_studio_route_for_path( 'studio' ) );
	fs_eq( 'app', fisha_studio_route_for_path( '/studio/' ) );
	fs_eq( 'app', fisha_studio_route_for_path( 'studio/products' ) );
	fs_eq( 'login', fisha_studio_route_for_path( 'studio/login' ) );
	fs_eq( 'logout', fisha_studio_route_for_path( 'studio/logout/' ) );
	fs_eq( null, fisha_studio_route_for_path( 'studios' ) );
	fs_eq( null, fisha_studio_route_for_path( 'product/studio' ) );
	fs_eq( null, fisha_studio_route_for_path( '' ) );
} );
