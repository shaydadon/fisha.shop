<?php
/**
 * Fisha Studio sign-in page (no JavaScript needed, except to draw the setup QR code).
 * Variables: $step ('password' | 'code' | 'backup' | 'setup' | 'codes' | 'done'), $error, $notice (strings),
 * $setup (array: key, uri, login) on the setup step, $codes (string[]) on the codes step (empty after a reload).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$fs_action = esc_url( home_url( '/studio/login/' ) );
$fs_titles = array(
	'password' => 'Sign in',
	'code'     => '2-step sign-in',
	'backup'   => '2-step sign-in',
	'setup'    => 'Set up 2-step sign-in',
	'codes'    => 'Save your backup codes',
	'done'     => 'You are signed in',
);
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $fs_titles[ $step ] ); ?> | Fisha Studio</title>
	<link rel="icon" href="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400..800&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( FISHA_STUDIO_URL . 'app/studio.css?v=' . fisha_asset_ver( 'studio/app/studio.css' ) ); ?>">
<?php if ( 'setup' === $step ) : ?>
	<script src="<?php echo esc_url( FISHA_STUDIO_URL . 'app/vendor/qrcode-generator-1.4.4.min.js' ); ?>" defer></script>
	<script src="<?php echo esc_url( FISHA_STUDIO_URL . 'app/js/setup-2fa.js?v=' . fisha_asset_ver( 'studio/app/js/setup-2fa.js' ) ); ?>" defer></script>
<?php endif; ?>
</head>
<body class="fs-login-page">
	<main class="fs-login<?php echo in_array( $step, array( 'setup', 'codes' ), true ) ? ' fs-login--wide' : ''; ?>">
		<img class="fs-login__logo" src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" alt="" width="120" height="85">
		<h1><?php echo esc_html( 'password' === $step ? 'Fisha Studio' : $fs_titles[ $step ] ); ?></h1>
		<?php if ( 'password' === $step ) : ?>
			<p class="fs-login__sub">Sign in to manage the shop.</p>
		<?php endif; ?>
		<?php if ( $error ) : ?><p class="fs-login__error" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>

		<?php if ( 'password' === $step ) : ?>
			<form method="post" action="<?php echo $fs_action; ?>">
				<?php wp_nonce_field( 'fisha_studio_login', '_fs_nonce', false ); ?>
				<input type="hidden" name="fs_step" value="password">
				<label>Email or username<input name="log" type="text" autocomplete="username" required autofocus value="<?php echo esc_attr( wp_unslash( $_POST['log'] ?? '' ) ); ?>"></label>
				<label>Password<input name="pwd" type="password" autocomplete="current-password" required></label>
				<button class="fs-btn fs-btn--primary" type="submit">Sign in</button>
			</form>
			<a class="fs-login__forgot" href="<?php echo esc_url( wp_lostpassword_url( home_url( '/studio/login/' ) ) ); ?>">Forgot your password?</a>

		<?php elseif ( 'code' === $step || 'backup' === $step ) : ?>
			<p class="fs-login__sub"><?php echo 'code' === $step ? 'Enter the 6-digit code from your authenticator app.' : 'Enter one of your backup codes. Each code works once.'; ?></p>
			<form method="post" action="<?php echo $fs_action; ?>">
				<?php wp_nonce_field( 'fisha_studio_2fa', '_fs_nonce', false ); ?>
				<input type="hidden" name="fs_step" value="code">
				<input type="hidden" name="fs_mode" value="<?php echo esc_attr( $step ); ?>">
				<?php if ( 'code' === $step ) : ?>
					<label>Code<input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus class="fs-login__code"></label>
				<?php else : ?>
					<label>Backup code<input name="code" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="xxxx-xxxx" maxlength="12" required autofocus class="fs-login__code"></label>
				<?php endif; ?>
				<button class="fs-btn fs-btn--primary" type="submit">Sign in</button>
			</form>
			<p class="fs-login__links">
				<?php if ( 'code' === $step ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'backup', '1', home_url( '/studio/login/' ) ) ); ?>">Use a backup code</a>
				<?php else : ?>
					<a href="<?php echo $fs_action; ?>">Use the code from the app</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( 'restart', '1', home_url( '/studio/login/' ) ) ); ?>">Start again</a>
			</p>

		<?php elseif ( 'setup' === $step ) : ?>
			<p class="fs-login__sub">To keep the shop safe, signing in now needs a code from an authenticator app on your phone (for example Google Authenticator or Microsoft Authenticator), as well as your password.</p>
			<ol class="fs-login__steps">
				<li>Open the authenticator app and choose to add an account.</li>
				<li>Scan this QR code. If you cannot scan it, type the key below instead.</li>
				<li>Enter the 6-digit code the app shows.</li>
			</ol>
			<div class="fs-login__qr" id="fs-qr" data-otpauth="<?php echo esc_attr( $setup['uri'] ); ?>">
				<noscript>The QR code needs JavaScript. Type the key below into the app instead.</noscript>
			</div>
			<p class="fs-login__key">Key: <code><?php echo esc_html( implode( ' ', str_split( $setup['key'], 4 ) ) ); ?></code><br><small>Account: <?php echo esc_html( $setup['login'] ); ?>, time based</small></p>
			<form method="post" action="<?php echo $fs_action; ?>">
				<?php wp_nonce_field( 'fisha_studio_2fa', '_fs_nonce', false ); ?>
				<input type="hidden" name="fs_step" value="code">
				<label>Code from the app<input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required class="fs-login__code"></label>
				<button class="fs-btn fs-btn--primary" type="submit">Turn on 2-step sign-in</button>
			</form>
			<p class="fs-login__links"><a href="<?php echo esc_url( add_query_arg( 'restart', '1', home_url( '/studio/login/' ) ) ); ?>">Start again</a></p>

		<?php elseif ( 'codes' === $step ) : ?>
			<?php if ( $codes ) : ?>
				<p class="fs-login__sub">2-step sign-in is on. If you lose your phone, each of these codes lets you sign in once instead of an app code. Keep them somewhere safe, like a password manager or on paper. They will not be shown again.</p>
				<ul class="fs-login__codes">
					<?php foreach ( $codes as $c ) : ?><li><code><?php echo esc_html( $c ); ?></code></li><?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="fs-login__sub">2-step sign-in is on. Your backup codes were shown once and cannot be shown again. If you did not save them, ask your web developer to make new ones.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo $fs_action; ?>">
				<?php wp_nonce_field( 'fisha_studio_2fa', '_fs_nonce', false ); ?>
				<input type="hidden" name="fs_step" value="saved">
				<button class="fs-btn fs-btn--primary" type="submit">I have saved these codes</button>
			</form>

		<?php elseif ( 'done' === $step ) : ?>
			<p class="fs-login__notice" role="status"><?php echo esc_html( $notice ); ?></p>
			<a class="fs-btn fs-btn--primary" href="<?php echo esc_url( home_url( '/studio/' ) ); ?>">Continue to Studio</a>
		<?php endif; ?>
	</main>
</body>
</html>
