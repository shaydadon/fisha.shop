<?php
/**
 * Outgoing mail through a real mailbox (SMTP). Hostinger drops PHP mail sent from the made-up
 * wordpress@ address, so Studio invites, password resets and WooCommerce emails never arrived.
 *
 * Settings live only in the server's wp-config.php (never in git):
 *   define( 'FISHA_SMTP_USER', 'shaked@fisha.shop' );
 *   define( 'FISHA_SMTP_PASS', '…' );
 *   optional: FISHA_SMTP_HOST (smtp.hostinger.com), FISHA_SMTP_PORT (465), FISHA_SMTP_FROM_NAME (Fisha)
 * Without FISHA_SMTP_USER and FISHA_SMTP_PASS nothing changes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** The SMTP settings, from $consts (tests) or the wp-config constants; null when not set up. */
function fisha_mail_smtp_config( ?array $consts = null ): ?array {
	$get = function ( string $k ) use ( $consts ) {
		if ( null !== $consts ) return $consts[ $k ] ?? null;
		return defined( $k ) ? constant( $k ) : null;
	};
	$user = (string) $get( 'FISHA_SMTP_USER' );
	$pass = (string) $get( 'FISHA_SMTP_PASS' );
	if ( '' === $user || '' === $pass ) return null;
	$port = (int) ( $get( 'FISHA_SMTP_PORT' ) ?: 465 );
	return array(
		'host'   => (string) ( $get( 'FISHA_SMTP_HOST' ) ?: 'smtp.hostinger.com' ),
		'port'   => $port,
		'secure' => 465 === $port ? 'ssl' : 'tls',
		'user'   => $user,
		'pass'   => $pass,
		'name'   => (string) ( $get( 'FISHA_SMTP_FROM_NAME' ) ?: 'Fisha' ),
	);
}

/**
 * Send through the mailbox. The From address must be the mailbox itself or the host refuses the
 * email, so another sender address (e.g. WooCommerce's "From" setting) becomes the Reply-To.
 */
function fisha_mail_apply_smtp( $m, array $c ): void {
	$m->isSMTP();
	$m->Host       = $c['host'];
	$m->Port       = $c['port'];
	$m->SMTPAuth   = true;
	$m->Username   = $c['user'];
	$m->Password   = $c['pass'];
	$m->SMTPSecure = $c['secure'];

	$from = strtolower( (string) $m->From );
	$name = (string) $m->FromName;
	$wp_default = '' === $from || 0 === strpos( $from, 'wordpress@' );
	if ( '' === $name || 'WordPress' === $name ) $name = $c['name'];
	if ( ! $wp_default && strtolower( $c['user'] ) !== $from && ! $m->getReplyToAddresses() ) {
		$m->addReplyTo( $m->From, $name );
	}
	$m->setFrom( $c['user'], $name, false );
	$m->Sender = $c['user'];
}

add_action( 'phpmailer_init', function ( $m ) {
	$c = fisha_mail_smtp_config();
	if ( $c ) fisha_mail_apply_smtp( $m, $c );
}, 99 );
