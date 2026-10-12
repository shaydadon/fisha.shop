<?php
fs_test( 'audit: table exists and rows round-trip', function () {
	global $wpdb;
	fs_eq( fisha_studio_log_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', fisha_studio_log_table() ) ) );
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', fisha_studio_log_table() ) );
	fs_eq( 'InnoDB', $engine, 'needed for publish transactions' );
	$p = fs_product();
	fs_as( FISHA_STUDIO_ROLE );
	$id = fisha_studio_log( 'product', $p->get_id(), 'publish', array( 'name' => 'Old' ), array( 'name' => 'New' ) );
	fs_true( $id > 0, 'insert id' );
	$rows = fisha_studio_log_for( 'product', $p->get_id() );
	fs_eq( 1, count( $rows ) );
	fs_eq( 'Old', $rows[0]['before']['name'] );
	fs_eq( 'New', $rows[0]['after']['name'] );
	fs_eq( get_current_user_id(), (int) $rows[0]['user_id'] );
} );

fs_test( 'audit: prune removes rows older than a year', function () {
	global $wpdb;
	$p = fs_product();
	$old = fisha_studio_log( 'product', $p->get_id(), 'publish', null, array() );
	$wpdb->update( fisha_studio_log_table(), array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
	$new = fisha_studio_log( 'product', $p->get_id(), 'publish', null, array() );
	do_action( 'fisha_studio_prune' );
	fs_eq( array( $new ), array_map( 'intval', wp_list_pluck( fisha_studio_log_for( 'product', $p->get_id() ), 'id' ) ) );
} );

fs_test( 'audit: error log folder is closed to the web', function () {
	fisha_studio_log_error( 'test', new RuntimeException( 'zz-test error' ) );
	$dir = WP_CONTENT_DIR . '/fisha-studio-logs';
	fs_true( file_exists( "$dir/.htaccess" ) && false !== strpos( file_get_contents( "$dir/.htaccess" ), 'Require all denied' ), '.htaccess denies' );
	fs_true( false !== strpos( file_get_contents( "$dir/studio.log" ), 'zz-test error' ), 'message written' );
} );
