<?php
function fs_tmp_file( string $name, string $bytes ): array {
	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	fs_on_cleanup( function () use ( $tmp ) { @unlink( $tmp ); } );
	return array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $bytes ), 'error' => 0 );
}

fs_test( 'media: a png is stored as an image', function () {
	$id = fisha_studio_store_image( fs_tmp_file( 'zz-test-photo.png', file_get_contents( FISHA_DESIGN_DIR . 'img/fisha-logo.png' ) ) );
	fs_true( is_int( $id ), is_wp_error( $id ) ? $id->get_error_message() : '' );
	fs_on_cleanup( function () use ( $id ) { wp_delete_attachment( $id, true ); } );
	fs_true( wp_attachment_is_image( $id ) );
	fs_eq( $id, fisha_studio_image_payload( $id )['id'] );
} );

fs_test( 'media: heic rejected with a clear message', function () {
	$r = fisha_studio_store_image( fs_tmp_file( 'IMG_0001.HEIC', "\0\0\0\x18ftypheic" . str_repeat( "\0", 64 ) ) );
	fs_eq( 'fisha_studio_upload', $r->get_error_code() );
	fs_true( false !== strpos( $r->get_error_message(), 'JPG' ), 'names the formats that work' );
} );

fs_test( 'media: a script pretending to be a jpg is rejected', function () {
	$r = fisha_studio_store_image( fs_tmp_file( 'evil.jpg', '<?php echo "pwned";' ) );
	fs_true( is_wp_error( $r ) );
	fs_eq( 'fisha_studio_upload', $r->get_error_code() );
} );

fs_test( 'media: a gif renamed to .png is rejected', function () {
	$gif = "GIF89a\x01\0\x01\0\x80\0\0\0\0\0\xff\xff\xff!\xf9\x04\x01\0\0\0\0,\0\0\0\0\x01\0\x01\0\0\x02\x02D\x01\0;";
	$r   = fisha_studio_store_image( fs_tmp_file( 'x.png', $gif ) );
	fs_true( is_wp_error( $r ), 'rejected' );
	fs_eq( 'fisha_studio_upload', $r->get_error_code() );
} );

fs_test( 'media: files over 15 MB are rejected before anything is stored', function () {
	$f = fs_tmp_file( 'big.png', file_get_contents( FISHA_DESIGN_DIR . 'img/fisha-logo.png' ) );
	$f['size'] = 16 * MB_IN_BYTES;
	$r = fisha_studio_store_image( $f );
	fs_true( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '15 MB' ) );
} );

fs_test( 'media: alt text update and library listing', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$img = fs_image();
	list( $s, $d ) = fs_api( 'PUT', "/media/$img", array( 'alt' => 'A blue <b>Fisha</b>' ) );
	fs_eq( 200, $s );
	fs_eq( 'A blue Fisha', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
	list( $s, $d ) = fs_api( 'GET', '/media' );
	fs_eq( 200, $s );
	fs_true( in_array( $img, wp_list_pluck( $d['items'], 'id' ), true ), 'newest image listed' );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'zz-test', 'post_status' => 'draft' ) );
	fs_on_cleanup( function () use ( $page ) { wp_delete_post( $page, true ); } );
	list( $s ) = fs_api( 'PUT', "/media/$page", array( 'alt' => 'x' ) );
	fs_eq( 404, $s, 'only images' );
} );

fs_test( 'media: library search by title', function () {
	fs_as( FISHA_STUDIO_ROLE );
	$img   = fs_image();
	$title = 'zz-test unique ' . strtolower( wp_generate_password( 10, false ) );
	wp_update_post( array( 'ID' => $img, 'post_title' => $title ) );
	fs_image();                                                                   // a newer image that must not match
	list( $s, $d ) = fs_api( 'GET', '/media', array( 'search' => $title ) );
	fs_eq( 200, $s );
	fs_eq( array( $img ), wp_list_pluck( $d['items'], 'id' ), 'only the matching image' );
	fs_eq( 1, $d['pages'] );
} );

fs_test( 'media: a failed save shows a plain sentence and logs the WordPress detail', function () {
	$secret = 'zz-test secret /var/www/html/detail ' . wp_rand();
	$filter = function ( $file ) use ( $secret ) { $file['error'] = $secret; return $file; };
	add_filter( 'wp_handle_sideload_prefilter', $filter );
	fs_on_cleanup( function () use ( $filter ) { remove_filter( 'wp_handle_sideload_prefilter', $filter ); } );
	$r = fisha_studio_store_image( fs_tmp_file( 'zz-test-fail.png', file_get_contents( FISHA_DESIGN_DIR . 'img/fisha-logo.png' ) ) );
	fs_true( is_wp_error( $r ), 'refused' );
	fs_eq( 'The photo could not be saved. Please try again.', $r->get_error_message() );
	$log = (string) @file_get_contents( WP_CONTENT_DIR . '/fisha-studio-logs/studio.log' );
	fs_true( false !== strpos( $log, $secret ), 'detail is in the Studio log' );
} );
