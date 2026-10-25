<?php
/** Read-only SQL baseline for selected admin renderers; never writes to the source database. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
if ( ! defined( 'WP_ADMIN' ) ) { define( 'WP_ADMIN', true ); }
if ( ! defined( 'ABSPATH' ) ) { require dirname( __DIR__, 4 ) . '/wp-load.php'; }
if ( ! function_exists( 'get_users' ) ) { require_once ABSPATH . 'wp-admin/includes/admin.php'; }

global $wpdb, $wp_version;
$admins = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) );
if ( ! $admins ) { throw new RuntimeException( 'No administrator is available for the read-only baseline.' ); }
wp_set_current_user( (int) $admins[0] );

$routes = array(
	'workspace'            => array( 'page'=>'adc-workspace', 'callback'=>array( \AutoDealership\Admin\WorkspacePage::class, 'render' ) ),
	'area_reports'         => array( 'page'=>'adc-area-reports', 'callback'=>array( \AutoDealership\Admin\OperationsPages::class, 'render_area_reports' ) ),
	'crm_reports'          => array( 'page'=>'adc-crm-reports', 'callback'=>array( \AutoDealership\Admin\AreaReportsPage::class, 'render' ) ),
	'operational_reports'  => array( 'page'=>'adc-operational-reports', 'callback'=>array( \AutoDealership\Admin\OperationalReportPage::class, 'render' ) ),
	'crm'                  => array( 'page'=>'adc-crm', 'callback'=>array( \AutoDealership\Admin\OperationsPages::class, 'render_leads' ) ),
	'inventory'            => array( 'page'=>'adc-inventory', 'callback'=>array( \AutoDealership\Admin\OperationsPages::class, 'render' ) ),
	'approvals'            => array( 'page'=>'adc-approvals', 'section'=>'discounts', 'callback'=>array( \AutoDealership\Admin\WorkflowPages::class, 'approvals' ) ),
	'finance'              => array( 'page'=>'adc-finance', 'callback'=>array( \AutoDealership\Admin\WorkflowPages::class, 'finance' ) ),
	'outbox'               => array( 'page'=>'adc-outbox', 'callback'=>array( \AutoDealership\Admin\OutboxPage::class, 'render' ) ),
	'onyx_inventory'       => array( 'page'=>'adc-onyx', 'callback'=>array( \AutoDealership\Admin\OnyxInventoryPage::class, 'render' ) ),
	'access_review'        => array( 'page'=>'adc-access-review', 'callback'=>array( \AutoDealership\Admin\AccessReviewPage::class, 'render' ) ),
	'settings'             => array( 'page'=>'adc-settings', 'section'=>'pricing', 'callback'=>array( \AutoDealership\Admin\SettingsPage::class, 'render' ) ),
	'platform_readiness'   => array( 'page'=>'adc-platform-setup', 'section'=>'readiness', 'callback'=>array( \AutoDealership\Core\ProductSetup::class, 'render' ) ),
);

$normalise = static function ( string $sql ): string {
	$sql = preg_replace( "/'(?:''|\\\\.|[^'])*'/", "'?'", $sql ) ?? $sql;
	$sql = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql ) ?? $sql;
	$sql = preg_replace( '/\s+/', ' ', trim( $sql ) ) ?? trim( $sql );
	return $sql;
};

$tables_for = static function ( string $sql ) use ( $normalise, $wpdb ): array {
	$found = array();
	if ( preg_match_all( '/\b(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches ) ) {
		foreach ( $matches[1] as $name ) {
			if ( str_starts_with( $name, $wpdb->prefix ) || str_starts_with( $name, 'wp_' ) ) { $found[] = $name; }
		}
	}
	return array_values( array_unique( $found ) );
};

$table_stats = array();
$stats = $wpdb->get_results( "SHOW TABLE STATUS LIKE '" . esc_sql( $wpdb->prefix ) . "%'", ARRAY_A ) ?: array();
foreach ( $stats as $row ) {
	$table_stats[] = array(
		'name'          => (string) ( $row['Name'] ?? '' ),
		'rows_estimate' => (int) ( $row['Rows'] ?? 0 ),
		'data_bytes'    => (int) ( $row['Data_length'] ?? 0 ),
		'index_bytes'   => (int) ( $row['Index_length'] ?? 0 ),
		'engine'        => (string) ( $row['Engine'] ?? '' ),
	);
}
usort( $table_stats, static fn( array $a, array $b ): int => ( $b['data_bytes'] + $b['index_bytes'] ) <=> ( $a['data_bytes'] + $a['index_bytes'] ) );

$profiles = array();
foreach ( $routes as $key => $route ) {
	$_GET = array( 'page'=>$route['page'] );
	if ( isset( $route['section'] ) ) { $_GET['section'] = $route['section']; }
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . rawurlencode( $route['page'] );
	$GLOBALS['pagenow'] = 'admin.php';
	$wpdb->queries = array();
	$wpdb->num_queries = 0;
	$started = microtime( true );
	$render_error = '';
	$blocked_write = false;
	$guard = static function ( string $query ) use ( &$blocked_write ): string {
		if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|RENAME)\b/i', $query ) ) {
			$blocked_write = true;
			throw new RuntimeException( 'Read-only guard blocked a write query.' );
		}
		return $query;
	};
	add_filter( 'query', $guard, PHP_INT_MAX );
	ob_start();
	try { call_user_func( $route['callback'] ); } catch ( Throwable $error ) { $render_error = $error->getMessage(); }
	if ( ob_get_level() ) { ob_end_clean(); }
	remove_filter( 'query', $guard, PHP_INT_MAX );

	$queries = array();
	$by_shape = array();
	$by_table = array();
	$total_query_ms = 0.0;
	foreach ( (array) $wpdb->queries as $item ) {
		$query = is_array( $item ) ? (string) ( $item[0] ?? '' ) : '';
		$elapsed = is_array( $item ) ? (float) ( $item[1] ?? 0 ) : 0.0;
		$shape = $normalise( $query );
		$total_query_ms += $elapsed * 1000;
		$by_shape[ $shape ] = ( $by_shape[ $shape ] ?? 0 ) + 1;
		foreach ( $tables_for( $query ) as $table ) { $by_table[ $table ] = ( $by_table[ $table ] ?? 0 ) + 1; }
		$queries[] = array( 'ms'=>round( $elapsed * 1000, 3 ), 'shape'=>$shape );
	}
	usort( $queries, static fn( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
	arsort( $by_table );
	arsort( $by_shape );
	$profiles[ $key ] = array(
		'page'              => $route['page'],
		'query_count'       => count( $queries ),
		'total_query_ms'    => round( $total_query_ms, 3 ),
		'render_wall_ms'    => round( ( microtime( true ) - $started ) * 1000, 3 ),
		'write_blocked'     => $blocked_write,
		'render_error'      => $render_error ? 'renderer failed' : '',
		'table_query_count' => $by_table,
		'repeated_shapes'   => array_slice( $by_shape, 0, 10, true ),
		'slowest_queries'   => array_slice( $queries, 0, 10 ),
	);
}

$output = getenv( 'ADC_ADMIN_QUERY_OUTPUT_FILE' ) ?: dirname( __DIR__, 4 ) . '/docs/artifacts/admin-dashboard-performance-baseline-1.66.0/query-baseline.json';
$directory = dirname( $output );
if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) { throw new RuntimeException( 'Cannot create query baseline directory.' ); }
$payload = array(
	'schema'       => 'adc-admin-query-baseline-1',
	'generated_at' => gmdate( 'c' ),
	'mode'         => 'source-read-only',
	'php'          => PHP_VERSION,
	'wordpress'    => (string) $wp_version,
	'database'     => array( 'engine'=>sanitize_text_field( (string) $wpdb->get_var( 'SELECT VERSION()' ) ), 'prefix'=> $wpdb->prefix ),
	'actor'        => array( 'role'=>'administrator', 'user_id_hash'=>hash( 'sha256', (string) get_current_user_id() ) ),
	'guard'        => array( 'writes_blocked_during_render'=>true, 'raw_sql_persisted'=>false ),
	'table_stats'  => array_slice( $table_stats, 0, 40 ),
	'pages'        => $profiles,
);
file_put_contents( $output, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL, LOCK_EX );
echo 'Admin query baseline written: ' . $output . PHP_EOL;
foreach ( $profiles as $key => $profile ) { echo $key . ': ' . $profile['query_count'] . ' queries, ' . $profile['total_query_ms'] . 'ms SQL, ' . $profile['render_wall_ms'] . "ms render\n"; }
