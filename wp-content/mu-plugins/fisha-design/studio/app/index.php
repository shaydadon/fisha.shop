<?php
/**
 * Fisha Studio app shell. The UI is plain ES modules (studio/app/js), versioned through an import map.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$fs_user   = wp_get_current_user();
$fs_config = array(
	'api'      => esc_url_raw( rest_url( FISHA_STUDIO_NS . '/' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'home'     => home_url( '/' ),
	'logout'   => wp_nonce_url( home_url( '/studio/logout/' ), 'fisha_studio_logout' ),
	'user'     => $fs_user->display_name,
	'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
	'logo'     => FISHA_DESIGN_URL . 'img/fisha-logo.png',
);
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Fisha Studio</title>
	<link rel="icon" href="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400..800&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( FISHA_STUDIO_URL . 'app/studio.css?v=' . fisha_asset_ver( 'studio/app/studio.css' ) ); ?>">
	<script type="importmap" nonce="<?php echo esc_attr( fisha_studio_csp_nonce() ); ?>"><?php echo wp_json_encode( fisha_studio_import_map(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); ?></script>
	<script type="application/json" id="fs-config"><?php echo wp_json_encode( $fs_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	<?php $fs_map = fisha_studio_import_map(); $fs_app = FISHA_STUDIO_URL . 'app/js/app.js'; ?>
	<script type="module" src="<?php echo esc_url( $fs_map['imports'][ $fs_app ] ?? $fs_app ); ?>"></script>
</head>
<body>
	<div id="fs-app" class="fs-app"><p class="fs-boot">Loading Fisha Studio…</p></div>
</body>
</html>
