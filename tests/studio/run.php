<?php
/**
 * Fisha Studio integration tests, run inside the Docker wpcli container by tests/studio/run.sh.
 * Each test-*.php file registers tests with fs_test(). Fixtures are named "zz-test …" and removed after every test.
 */
if ( ! defined( 'ABSPATH' ) ) exit( "Run through tests/studio/run.sh\n" );

$GLOBALS['fs_filter'] = isset( $args[0] ) ? (string) $args[0] : '';
require __DIR__ . '/helpers.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $fs_file ) {
	echo basename( $fs_file ) . "\n";
	require $fs_file;
}
$fs_r = $GLOBALS['fs_results'];
echo "\n{$fs_r['pass']} passed, {$fs_r['fail']} failed\n";
if ( $fs_r['fail'] ) WP_CLI::halt( 1 );
