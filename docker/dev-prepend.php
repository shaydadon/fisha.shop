<?php
/**
 * Local dev only (loaded via php.ini auto_prepend_file).
 * OPcache runs with validate_timestamps=0 because every file check on the Windows-shared folder costs ~1ms
 * (≈3,500 checks per page). Instead we re-check only the files we actually edit:
 * opcache_invalidate($file, false) recompiles a script only if it changed on disk.
 */
if ( function_exists( 'opcache_invalidate' ) ) {
	$fisha_root = '/var/www/html';
	$fisha_watch = array_merge(
		array( $fisha_root . '/wp-config.php', $fisha_root . '/docker/wp-config-docker.php' ),
		glob( $fisha_root . '/wp-content/mu-plugins/*.php' ) ?: array(),
		glob( $fisha_root . '/wp-content/mu-plugins/fisha-design/*.php' ) ?: array(),
		glob( $fisha_root . '/wp-content/mu-plugins/fisha-design/templates/*.php' ) ?: array()
	);
	// the script being requested directly (e.g. a fisha-tasks/*.php helper) is always re-checked
	if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) $fisha_watch[] = $_SERVER['SCRIPT_FILENAME'];
	foreach ( $fisha_watch as $fisha_f ) @opcache_invalidate( $fisha_f, false );
	unset( $fisha_root, $fisha_watch, $fisha_f );
}
