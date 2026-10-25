<?php
/** Read-only EXPLAIN report for the selected admin renderers. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
if ( ! defined( 'WP_ADMIN' ) ) { define( 'WP_ADMIN', true ); }
if ( ! defined( 'ABSPATH' ) ) { require dirname( __DIR__, 4 ) . '/wp-load.php'; }
if ( ! function_exists( 'get_users' ) ) { require_once ABSPATH . 'wp-admin/includes/admin.php'; }

global $wpdb, $wp_version;
$admins = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) );
if ( ! $admins ) { throw new RuntimeException( 'No administrator is available for the read-only EXPLAIN report.' ); }
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

$write_guard = static function ( string $query ) use ( &$blocked_write ): string {
	if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|RENAME)\b/i', $query ) ) {
		$blocked_write = true;
		throw new RuntimeException( 'Read-only guard blocked a write query.' );
	}
	return $query;
};

$captured = array();
$render_errors = array();
$write_observed = false;
foreach ( $routes as $key => $route ) {
	$_GET = array( 'page'=>$route['page'] );
	if ( isset( $route['section'] ) ) { $_GET['section'] = $route['section']; }
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . rawurlencode( $route['page'] );
	$GLOBALS['pagenow'] = 'admin.php';
	$wpdb->queries = array();
	$wpdb->num_queries = 0;
	$blocked_write = false;
	add_filter( 'query', $write_guard, PHP_INT_MAX );
	ob_start();
	try { call_user_func( $route['callback'] ); } catch ( Throwable $error ) { $render_errors[ $key ] = 'renderer failed'; }
	if ( ob_get_level() ) { ob_end_clean(); }
	remove_filter( 'query', $write_guard, PHP_INT_MAX );
	$write_observed = $write_observed || $blocked_write;
	foreach ( (array) $wpdb->queries as $item ) {
		$query = is_array( $item ) ? trim( (string) ( $item[0] ?? '' ) ) : '';
		$elapsed = is_array( $item ) ? (float) ( $item[1] ?? 0 ) : 0.0;
		if ( '' !== $query ) { $captured[] = array( 'route'=>$key, 'query'=>$query, 'ms'=>round( $elapsed * 1000, 3 ), 'shape'=>$normalise( $query ) ); }
	}
}

$groups = array();
foreach ( $captured as $item ) {
	$shape = $item['shape'];
	if ( ! isset( $groups[ $shape ] ) ) {
		$groups[ $shape ] = array( 'shape'=>$shape, 'count'=>0, 'total_ms'=>0.0, 'max_ms'=>0.0, 'routes'=>array(), 'query'=>$item['query'] );
	}
	$groups[ $shape ]['count']++;
	$groups[ $shape ]['total_ms'] += $item['ms'];
	$groups[ $shape ]['max_ms'] = max( $groups[ $shape ]['max_ms'], $item['ms'] );
	$groups[ $shape ]['routes'][ $item['route'] ] = true;
	if ( $item['ms'] >= $groups[ $shape ]['max_ms'] ) { $groups[ $shape ]['query'] = $item['query']; }
}

$target_tables = array( 'adc_onyx_stock', 'adc_catalog_models', 'adc_vehicles', 'adc_branches', 'adc_leads', 'adc_activities', 'adc_lead_stage_history', 'adc_portal_reviews', 'adc_portal_review_versions', 'adc_audit_events', 'options', 'posts', 'postmeta' );
$ranked = array_values( $groups );
usort( $ranked, static function ( array $a, array $b ) use ( $target_tables ): int {
	$target_score = static function ( array $row ) use ( $target_tables ): int {
		$score = 0;
		foreach ( $target_tables as $table ) { if ( false !== stripos( $row['shape'], $table ) ) { $score += 100000; } }
		return $score;
	};
	return ( $target_score( $b ) + ( $b['max_ms'] * 100 ) + ( $b['count'] * 2 ) ) <=> ( $target_score( $a ) + ( $a['max_ms'] * 100 ) + ( $a['count'] * 2 ) );
} );
$candidates = array_slice( $ranked, 0, 30 );

$plans = array();
foreach ( $candidates as $candidate ) {
	$query = $candidate['query'];
	if ( ! preg_match( '/^\s*(?:SELECT|WITH)\b/i', $query ) || str_contains( $query, ';' ) ) { continue; }
	$plan_rows = $wpdb->get_results( 'EXPLAIN ' . $query, ARRAY_A );
	$plan = array();
	if ( is_array( $plan_rows ) && $plan_rows ) {
		foreach ( $plan_rows as $row ) {
			$plan[] = array(
				'table' => (string) ( $row['table'] ?? '' ),
				'type' => (string) ( $row['type'] ?? '' ),
				'possible_keys' => (string) ( $row['possible_keys'] ?? '' ),
				'key' => (string) ( $row['key'] ?? '' ),
				'key_len' => (string) ( $row['key_len'] ?? '' ),
				'ref' => (string) ( $row['ref'] ?? '' ),
				'rows' => isset( $row['rows'] ) ? (int) $row['rows'] : null,
				'filtered' => isset( $row['filtered'] ) ? (float) $row['filtered'] : null,
				'extra' => (string) ( $row['Extra'] ?? '' ),
			);
		}
	}
	$plans[] = array(
		'shape' => $candidate['shape'],
		'count' => $candidate['count'],
		'total_ms' => round( $candidate['total_ms'], 3 ),
		'max_ms' => round( $candidate['max_ms'], 3 ),
		'routes' => array_keys( $candidate['routes'] ),
		'explain_status' => $plan ? 'ok' : 'failed',
		'plan' => $plan,
	);
}

$index_inventory = array();
foreach ( $target_tables as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$rows = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table ) . '`', ARRAY_A );
	if ( ! is_array( $rows ) || ! $rows ) { continue; }
	$index_inventory[ $table ] = array();
	foreach ( $rows as $row ) {
		$index_inventory[ $table ][] = array(
			'key' => (string) ( $row['Key_name'] ?? '' ),
			'non_unique' => isset( $row['Non_unique'] ) ? (int) $row['Non_unique'] : null,
			'sequence' => isset( $row['Seq_in_index'] ) ? (int) $row['Seq_in_index'] : null,
			'column' => (string) ( $row['Column_name'] ?? '' ),
			'cardinality' => isset( $row['Cardinality'] ) ? (int) $row['Cardinality'] : null,
		);
	}
}

$output = getenv( 'ADC_ADMIN_QUERY_EXPLAIN_OUTPUT_FILE' ) ?: dirname( __DIR__, 4 ) . '/docs/artifacts/admin-dashboard-performance-baseline-1.66.0/query-explain.json';
$directory = dirname( $output );
if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) { throw new RuntimeException( 'Cannot create EXPLAIN report directory.' ); }
$payload = array(
	'schema' => 'adc-admin-query-explain-1',
	'generated_at' => gmdate( 'c' ),
	'mode' => 'source-read-only',
	'php' => PHP_VERSION,
	'wordpress' => (string) $wp_version,
	'database' => array( 'engine'=>(string) $wpdb->get_var( 'SELECT VERSION()' ), 'prefix'=>$wpdb->prefix ),
	'guard' => array( 'writes_blocked_during_render'=>true, 'write_observed'=>$write_observed, 'raw_sql_persisted'=>false ),
	'render_errors' => $render_errors,
	'candidate_count' => count( $plans ),
	'plans' => $plans,
	'index_inventory' => $index_inventory,
);
file_put_contents( $output, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL, LOCK_EX );
echo 'Admin EXPLAIN report written: ' . $output . PHP_EOL;
echo 'Candidates: ' . count( $plans ) . '; write observed: ' . ( $write_observed ? 'yes' : 'no' ) . PHP_EOL;
foreach ( $plans as $plan ) { echo $plan['explain_status'] . ': ' . $plan['max_ms'] . 'ms; ' . substr( $plan['shape'], 0, 100 ) . PHP_EOL; }
