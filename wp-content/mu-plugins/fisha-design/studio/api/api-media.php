<?php
/**
 * Fisha Studio REST: product photos. Only JPG, PNG and WebP up to 15 MB; the type is checked from the file's
 * content, not its name. Alt text is saved straight to the image (it is not part of a product draft).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_MAX_UPLOAD', 15 * MB_IN_BYTES );

function fisha_studio_image_mimes(): array {
	return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
}

function fisha_studio_image_payload( int $id ): ?array {
	if ( ! $id || ! wp_attachment_is_image( $id ) ) return null;
	return array(
		'id'     => $id,
		'thumb'  => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
		'medium' => (string) wp_get_attachment_image_url( $id, 'medium' ),
		'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'name'   => get_the_title( $id ),
	);
}

/** Stores an uploaded/temporary file as a media library image. @return int|WP_Error */
function fisha_studio_store_image( array $file ) {
	if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! file_exists( $file['tmp_name'] ) ) {
		return new WP_Error( 'fisha_studio_upload', 'The photo did not arrive. Please try again.', array( 'status' => 422 ) );
	}
	if ( (int) ( $file['size'] ?? 0 ) > FISHA_STUDIO_MAX_UPLOAD ) {
		return new WP_Error( 'fisha_studio_upload', 'This photo is larger than 15 MB. Please use a smaller one.', array( 'status' => 422 ) );
	}
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], fisha_studio_image_mimes() );
	if ( empty( $check['type'] ) || ! in_array( $check['type'], fisha_studio_image_mimes(), true ) ) {
		return new WP_Error( 'fisha_studio_upload', 'Only JPG, PNG or WebP photos can be uploaded. On an iPhone, set Settings > Camera > Formats to "Most Compatible", or share the photo as JPG.', array( 'status' => 422 ) );
	}
	// Explicit content check: the file must really be a JPG, PNG or WebP image, whatever its name says.
	$real = wp_get_image_mime( $file['tmp_name'] );
	if ( ! $real || ! in_array( $real, array_values( fisha_studio_image_mimes() ), true ) ) {
		return new WP_Error( 'fisha_studio_upload', 'Only JPG, PNG or WebP photos can be uploaded. On an iPhone, set Settings > Camera > Formats to "Most Compatible", or share the photo as JPG.', array( 'status' => 422 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$id = media_handle_sideload( array( 'name' => $check['proper_filename'] ?: $file['name'], 'tmp_name' => $file['tmp_name'] ), 0 );
	if ( is_wp_error( $id ) ) return new WP_Error( 'fisha_studio_upload', 'The photo could not be saved. ' . $id->get_error_message(), array( 'status' => 422 ) );
	return (int) $id;
}

add_action( 'rest_api_init', function () {
	register_rest_route( FISHA_STUDIO_NS, '/media', array(
		array( 'methods' => 'GET', 'callback' => 'fisha_studio_rest_media_list', 'permission_callback' => fisha_studio_permission( 'upload_files' ) ),
		array( 'methods' => 'POST', 'callback' => 'fisha_studio_rest_media_upload', 'permission_callback' => fisha_studio_permission( 'upload_files' ) ),
	) );
	register_rest_route( FISHA_STUDIO_NS, '/media/(?P<id>\d+)', array( 'methods' => 'PUT', 'callback' => 'fisha_studio_rest_media_alt', 'permission_callback' => fisha_studio_permission( 'upload_files' ) ) );
} );

function fisha_studio_rest_media_list( WP_REST_Request $r ): array {
	$q = new WP_Query( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'fields' => 'ids',
		'posts_per_page' => 40, 'paged' => max( 1, (int) $r['page'] ), 'orderby' => 'date', 'order' => 'DESC',
	) );
	return array( 'items' => array_values( array_filter( array_map( 'fisha_studio_image_payload', array_map( 'intval', $q->posts ) ) ) ), 'pages' => (int) $q->max_num_pages );
}

function fisha_studio_rest_media_upload( WP_REST_Request $r ) {
	$files = $r->get_file_params();
	$file  = $files['file'] ?? null;
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'fisha_studio_upload', 'The photo did not arrive. Please try again.', array( 'status' => 422 ) );
	}
	$id = fisha_studio_store_image( $file );
	return is_wp_error( $id ) ? $id : fisha_studio_image_payload( $id );
}

function fisha_studio_rest_media_alt( WP_REST_Request $r ) {
	$id = (int) $r['id'];
	if ( ! wp_attachment_is_image( $id ) ) return new WP_Error( 'fisha_studio_not_found', 'This image no longer exists.', array( 'status' => 404 ) );
	$alt = mb_substr( trim( sanitize_text_field( (string) ( fisha_studio_body( $r )['alt'] ?? '' ) ) ), 0, 200 );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return fisha_studio_image_payload( $id );
}
