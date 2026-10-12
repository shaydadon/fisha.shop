<?php
/**
 * Fisha Studio audit log: who changed what and when, with the values before and after (kept 365 days).
 * Also the Studio's error log, in a folder the web server refuses to serve.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FISHA_STUDIO_LOG_DB', 1 );

function fisha_studio_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'fisha_studio_log';
}

function fisha_studio_install_log(): void {
	if ( (int) get_option( 'fisha_studio_log_db', 0 ) >= FISHA_STUDIO_LOG_DB ) return;
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . fisha_studio_log_table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_type varchar(20) NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL,
  before_json longtext NULL,
  after_json longtext NULL,
  PRIMARY KEY  (id),
  KEY item (item_type,item_id),
  KEY created (created)
) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
	update_option( 'fisha_studio_log_db', FISHA_STUDIO_LOG_DB );
}
add_action( 'init', 'fisha_studio_install_log', 6 );

function fisha_studio_log( string $type, int $id, string $action, ?array $before, ?array $after ): int {
	global $wpdb;
	$ok = $wpdb->insert( fisha_studio_log_table(), array(
		'created'     => current_time( 'mysql', true ),
		'user_id'     => get_current_user_id(),
		'item_type'   => $type,
		'item_id'     => $id,
		'action'      => $action,
		'before_json' => null === $before ? null : wp_json_encode( $before ),
		'after_json'  => null === $after ? null : wp_json_encode( $after ),
	) );
	return $ok ? (int) $wpdb->insert_id : 0;
}

function fisha_studio_log_for( string $type, int $id, int $limit = 20 ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . fisha_studio_log_table() . ' WHERE item_type = %s AND item_id = %d ORDER BY id DESC LIMIT %d', $type, $id, $limit
	), ARRAY_A );
	foreach ( $rows as &$r ) {
		$r['before'] = null === $r['before_json'] ? null : json_decode( $r['before_json'], true );
		$r['after']  = null === $r['after_json'] ? null : json_decode( $r['after_json'], true );
		unset( $r['before_json'], $r['after_json'] );
	}
	return $rows;
}

add_action( 'fisha_studio_prune', function () {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . fisha_studio_log_table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) );
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fisha_studio_prune' ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fisha_studio_prune' );
} );

function fisha_studio_log_error( string $context, Throwable $e ): void {
	$dir = WP_CONTENT_DIR . '/fisha-studio-logs';
	if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
	if ( ! file_exists( "$dir/.htaccess" ) ) file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
	if ( ! file_exists( "$dir/index.php" ) ) file_put_contents( "$dir/index.php", "<?php // Silence.\n" );
	error_log( gmdate( 'c' ) . " [$context] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", 3, "$dir/studio.log" );
}
