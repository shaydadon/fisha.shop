<?php
/**
 * Fisha Studio "My account": change the password (this browser stays signed in, every other session ends), make new
 * backup codes (needs a code from the authenticator app), and sign out of other devices.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Sets the auth cookie for this browser; the only path allowed past the 2-step cookie guard for an owner. */
function fisha_studio_issue_session( WP_User $u ): void {
	$GLOBALS['fisha_studio_2fa_ok'] = $u->ID;
	try {
		wp_set_auth_cookie( $u->ID, false, is_ssl() );
	} finally {
		unset( $GLOBALS['fisha_studio_2fa_ok'] );
	}
	wp_set_current_user( $u->ID );
}

function fisha_studio_account_info( WP_User $u ): array {
	global $wpdb;
	$owner = fisha_studio_is_owner( $u );
	$since = $owner ? $wpdb->get_var( $wpdb->prepare( 'SELECT created FROM ' . fisha_studio_log_table() . " WHERE item_type = 'user' AND item_id = %d AND action = '2fa-setup' ORDER BY id DESC LIMIT 1", $u->ID ) ) : null;
	return array(
		'login' => $u->user_login,
		'email' => $u->user_email,
		'name'  => $u->display_name,
		'owner' => $owner,
		'twofa' => array(
			'enabled'     => $owner && fisha_studio_2fa_enabled( $u->ID ),
			'since'       => $since ? str_replace( ' ', 'T', $since ) . 'Z' : null,
			'backup_left' => $owner ? fisha_studio_2fa_backup_left( $u->ID ) : 0,
		),
	);
}

/** @return true|WP_Error */
function fisha_studio_change_password( WP_User $u, string $current, string $new, string $confirm, string $ip ) {
	$invalid = function ( array $fields ) {
		return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $fields ) );
	};
	if ( ! fisha_studio_login_reserve( $u->user_login, $ip ) ) {
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.', array( 'status' => 429 ) );
	}
	if ( ! wp_check_password( $current, $u->user_pass, $u->ID ) ) return $invalid( array( 'current' => 'That is not your current password.' ) );
	if ( mb_strlen( $new ) < 12 ) return $invalid( array( 'new' => 'Use at least 12 characters.' ) );
	if ( $new === $current ) return $invalid( array( 'new' => 'Choose a password different from the current one.' ) );
	if ( $new !== $confirm ) return $invalid( array( 'confirm' => 'The two new passwords are not the same.' ) );

	fisha_studio_login_clear( $u->user_login, $ip );
	wp_set_password( $new, $u->ID );
	WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
	clean_user_cache( $u->ID );
	fisha_studio_issue_session( get_user_by( 'id', $u->ID ) );
	fisha_studio_log( 'user', $u->ID, 'password-change', null, array( 'ip' => $ip ) );
	wp_mail( $u->user_email, 'Your Fisha Studio password was changed', "Hi {$u->display_name},\n\nThe password of your Fisha Studio account was just changed. If this was not you, contact your web developer right away.\n" );
	return true;
}

/** @return string[]|WP_Error the 10 new codes, shown once */
function fisha_studio_new_backup_codes_checked( WP_User $u, string $code, string $ip ) {
	if ( ! fisha_studio_is_owner( $u ) || ! fisha_studio_2fa_enabled( $u->ID ) ) {
		return new WP_Error( 'fisha_studio_no_2fa', '2-step sign-in is not set up for this account.', array( 'status' => 400 ) );
	}
	if ( ! fisha_studio_login_reserve( $u->user_login, $ip ) ) {
		return new WP_Error( 'fisha_studio_locked', 'Too many tries. Please wait 15 minutes and try again.', array( 'status' => 429 ) );
	}
	$code = preg_replace( '/\s+/', '', $code );
	if ( ! preg_match( '/^\d{6}$/', $code ) || ! fisha_studio_totp_verify( $u->ID, $code ) ) {
		return new WP_Error( 'fisha_studio_invalid', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => array( 'code' => 'That code is not right. Use the 6-digit code from your app.' ) ) );
	}
	fisha_studio_login_clear( $u->user_login, $ip );
	$codes = fisha_studio_2fa_new_backup_codes( $u->ID );
	fisha_studio_log( 'user', $u->ID, 'backup-codes-new', null, array( 'ip' => $ip ) );
	return $codes;
}

function fisha_studio_sign_out_others( WP_User $u ): void {
	WP_Session_Tokens::get_instance( $u->ID )->destroy_others( wp_get_session_token() );
	fisha_studio_log( 'user', $u->ID, 'sign-out-others', null, null );
}
