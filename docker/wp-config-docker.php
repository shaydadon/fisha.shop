<?php
// Loaded by wp-config.php only inside the local Docker container.
define( 'DB_NAME',     getenv( 'FISHA_DB_NAME' ) ?: 'fisha' );
define( 'DB_USER',     getenv( 'FISHA_DB_USER' ) ?: 'fisha' );
define( 'DB_PASSWORD', getenv( 'FISHA_DB_PASSWORD' ) ?: 'fisha' );
define( 'DB_HOST',     getenv( 'FISHA_DB_HOST' ) ?: 'db' );

$fisha_url = getenv( 'FISHA_URL' ) ?: 'http://localhost:8484';
define( 'WP_HOME',    $fisha_url );
define( 'WP_SITEURL', $fisha_url );

define( 'WP_CACHE', false );               // no LiteSpeed cache locally
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
