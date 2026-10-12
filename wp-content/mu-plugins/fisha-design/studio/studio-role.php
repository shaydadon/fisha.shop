<?php
/**
 * Fisha Studio: the "Shop owner (Studio)" role. It can manage the catalog (products, product categories, tags,
 * media uploads) and nothing else. Accounts are created with:  wp fisha-studio create-owner <email>
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_ROLE_VERSION', '1' );

function fisha_studio_caps(): array {
	$caps = array( 'read', 'upload_files', 'fisha_studio',
		'edit_products', 'edit_others_products', 'edit_published_products', 'edit_private_products',
		'publish_products', 'read_private_products',
		'delete_products', 'delete_others_products', 'delete_published_products', 'delete_private_products',
		'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms' );
	return array_fill_keys( $caps, true );
}

function fisha_studio_install_role(): void {
	if ( get_option( 'fisha_studio_role_version' ) === FISHA_STUDIO_ROLE_VERSION && get_role( FISHA_STUDIO_ROLE ) ) return;
	remove_role( FISHA_STUDIO_ROLE );
	add_role( FISHA_STUDIO_ROLE, 'Shop owner (Studio)', fisha_studio_caps() );
	$admin = get_role( 'administrator' );
	if ( $admin ) $admin->add_cap( 'fisha_studio' );
	update_option( 'fisha_studio_role_version', FISHA_STUDIO_ROLE_VERSION );
}
add_action( 'init', 'fisha_studio_install_role', 5 );

/**
 * Creates a Studio owner. Without a password, WordPress emails a "set your password" link.
 * @return int|WP_Error user id
 */
function fisha_studio_create_owner( string $email, string $login = '', string $password = '' ) {
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) return new WP_Error( 'fisha_studio_email', 'That is not a valid email address.' );
	if ( email_exists( $email ) ) return new WP_Error( 'fisha_studio_email', 'A user with this email already exists.' );
	fisha_studio_install_role();
	$login = sanitize_user( $login ?: strstr( $email, '@', true ), true );
	if ( username_exists( $login ) ) $login .= '-' . strtolower( wp_generate_password( 4, false ) );
	$id = wp_insert_user( array(
		'user_login'   => $login,
		'user_email'   => $email,
		'user_pass'    => $password ?: wp_generate_password( 32 ),
		'role'         => FISHA_STUDIO_ROLE,
		'display_name' => $login,
	) );
	if ( is_wp_error( $id ) ) return $id;
	if ( ! $password ) wp_new_user_notification( $id, null, 'user' );
	return (int) $id;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'fisha-studio create-owner', function ( $args, $assoc ) {
		if ( empty( $args[0] ) ) WP_CLI::error( 'Usage: wp fisha-studio create-owner <email> [--login=<login>] [--password=<password>]' );
		$id = fisha_studio_create_owner( $args[0], $assoc['login'] ?? '', $assoc['password'] ?? '' );
		if ( is_wp_error( $id ) ) WP_CLI::error( $id->get_error_message() );
		$u = get_user_by( 'id', $id );
		WP_CLI::success( "Created Studio owner '{$u->user_login}' (#$id)." . ( empty( $assoc['password'] ) ? ' A set-password email was sent.' : '' ) );
	} );
}
