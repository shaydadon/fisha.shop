<?php
/**
 * Fisha Studio sign-in page (no JavaScript needed). Variables: $error (string).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Sign in | Fisha Studio</title>
	<link rel="icon" href="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400..800&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( FISHA_STUDIO_URL . 'app/studio.css?v=' . fisha_asset_ver( 'studio/app/studio.css' ) ); ?>">
</head>
<body class="fs-login-page">
	<main class="fs-login">
		<img class="fs-login__logo" src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" width="120" height="85">
		<h1>Fisha Studio</h1>
		<p class="fs-login__sub">Sign in to manage the shop.</p>
		<?php if ( $error ) : ?><p class="fs-login__error" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( home_url( '/studio/login/' ) ); ?>">
			<?php wp_nonce_field( 'fisha_studio_login', '_fs_nonce', false ); ?>
			<label>Email or username<input name="log" type="text" autocomplete="username" required autofocus value="<?php echo esc_attr( wp_unslash( $_POST['log'] ?? '' ) ); ?>"></label>
			<label>Password<input name="pwd" type="password" autocomplete="current-password" required></label>
			<button class="fs-btn fs-btn--primary" type="submit">Sign in</button>
		</form>
		<a class="fs-login__forgot" href="<?php echo esc_url( wp_lostpassword_url( home_url( '/studio/login/' ) ) ); ?>">Forgot your password?</a>
	</main>
</body>
</html>
