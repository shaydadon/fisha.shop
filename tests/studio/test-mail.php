<?php
fs_test( 'mail: without SMTP settings in wp-config nothing changes', function () {
	fs_eq( null, fisha_mail_smtp_config( array() ) );
	fs_eq( null, fisha_mail_smtp_config( array( 'FISHA_SMTP_USER' => 'shop@example.com' ) ), 'a password is required too' );
	if ( ! defined( 'FISHA_SMTP_USER' ) ) fs_eq( null, fisha_mail_smtp_config(), 'this machine has no SMTP settings' );
} );

fs_test( 'mail: settings default to Hostinger SSL and the Fisha name', function () {
	$c = fisha_mail_smtp_config( array( 'FISHA_SMTP_USER' => 'shop@example.com', 'FISHA_SMTP_PASS' => 'x' ) );
	fs_eq( 'smtp.hostinger.com', $c['host'] );
	fs_eq( 465, $c['port'] );
	fs_eq( 'ssl', $c['secure'] );
	fs_eq( 'Fisha', $c['name'] );
	$c = fisha_mail_smtp_config( array( 'FISHA_SMTP_USER' => 'shop@example.com', 'FISHA_SMTP_PASS' => 'x', 'FISHA_SMTP_HOST' => 'mail.example.com', 'FISHA_SMTP_PORT' => '587', 'FISHA_SMTP_FROM_NAME' => 'Shop' ) );
	fs_eq( 'mail.example.com', $c['host'] );
	fs_eq( 587, $c['port'] );
	fs_eq( 'tls', $c['secure'], 'port 587 uses STARTTLS' );
	fs_eq( 'Shop', $c['name'] );
} );

fs_test( 'mail: every email goes out through the mailbox, other senders become Reply-To', function () {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$c = fisha_mail_smtp_config( array( 'FISHA_SMTP_USER' => 'shop@example.com', 'FISHA_SMTP_PASS' => 'secret' ) );

	$m = new PHPMailer\PHPMailer\PHPMailer( true );
	$m->setFrom( 'orders@other.example', 'Fisha Orders' );
	fisha_mail_apply_smtp( $m, $c );
	fs_eq( 'smtp', $m->Mailer );
	fs_eq( 'smtp.hostinger.com', $m->Host );
	fs_eq( 465, $m->Port );
	fs_true( $m->SMTPAuth, 'SMTP login is on' );
	fs_eq( 'shop@example.com', $m->Username );
	fs_eq( 'secret', $m->Password );
	fs_eq( 'ssl', $m->SMTPSecure );
	fs_eq( 'shop@example.com', $m->From );
	fs_eq( 'shop@example.com', $m->Sender );
	fs_eq( 'Fisha Orders', $m->FromName, 'a sender name set by WooCommerce is kept' );
	fs_eq( array( 'orders@other.example' ), array_column( $m->getReplyToAddresses(), 0 ) );

	$m = new PHPMailer\PHPMailer\PHPMailer( true );
	$m->setFrom( 'wordpress@example.com', 'WordPress' );
	fisha_mail_apply_smtp( $m, $c );
	fs_eq( 'Fisha', $m->FromName, 'the WordPress default name becomes Fisha' );
	fs_eq( array(), $m->getReplyToAddresses(), 'no Reply-To for the WordPress default sender' );

	$m = new PHPMailer\PHPMailer\PHPMailer( true );
	$m->setFrom( 'orders@other.example', 'Orders' );
	$m->addReplyTo( 'customer@example.org' );
	fisha_mail_apply_smtp( $m, $c );
	fs_eq( array( 'customer@example.org' ), array_column( $m->getReplyToAddresses(), 0 ), 'an existing Reply-To is kept' );
} );

fs_test( 'mail: dev site sends owner and admin emails to them, everything else to the site admin', function () {
	$owner = fs_user( FISHA_STUDIO_ROLE );
	$admin = get_option( 'admin_email' );
	$out   = fisha_dev_mail_redirect( array( 'to' => $owner->user_email, 'subject' => 'Set your password' ) );
	fs_eq( $owner->user_email, $out['to'], 'owner email is delivered to the owner' );
	fs_eq( 'Set your password', $out['subject'] );

	$out = fisha_dev_mail_redirect( array( 'to' => array( 'Owner <' . strtoupper( $owner->user_email ) . '>' ), 'subject' => 'Hi' ) );
	fs_eq( array( 'Owner <' . strtoupper( $owner->user_email ) . '>' ), $out['to'], 'name form and case do not matter' );

	$out = fisha_dev_mail_redirect( array( 'to' => 'customer@example.org', 'subject' => 'Your order' ) );
	fs_eq( $admin, $out['to'] );
	fs_eq( '[DEV → customer@example.org] Your order', $out['subject'] );

	$out = fisha_dev_mail_redirect( array( 'to' => array( $owner->user_email, 'customer@example.org' ), 'subject' => 'Both' ) );
	fs_eq( $admin, $out['to'], 'one customer in the list sends the whole email to the admin' );

	$cust = fs_user( 'customer' );
	$out  = fisha_dev_mail_redirect( array( 'to' => $cust->user_email, 'subject' => 'Account' ) );
	fs_eq( $admin, $out['to'], 'customer accounts are not let through' );
} );
