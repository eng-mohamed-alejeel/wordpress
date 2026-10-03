<?php
/** Read-only, CLI-only release gate. It does not activate modules or change site data. */
if ( PHP_SAPI !== 'cli' || 2 !== $argc || '--inspect' !== $argv[1] ) {
	fwrite( STDERR, "Usage: php tools/release-preflight.php --inspect\n" );
	exit( 1 );
}
define( 'SHORTINIT', true );
require dirname( __DIR__ ) . '/wp-load.php';
global $wpdb, $wp_version;
$wpdb->suppress_errors( true );
$checks = array();
$add = static function ( string $id, string $status, string $summary, array $metrics = array() ) use ( &$checks ): void {
	$checks[] = array( 'id' => $id, 'status' => $status, 'summary' => $summary, 'metrics' => $metrics );
};
$option = static function ( string $name ) use ( $wpdb ): ?string {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $name ) );
	return null === $value ? null : (string) $value;
};
$stored_array = static function ( ?string $value ): ?array {
	if ( null === $value ) { return array(); }
	if ( ! preg_match( '/\Aa:\d+:\{/', $value ) ) { return null; }
	$result = @unserialize( $value, array( 'allowed_classes' => false ) );
	return is_array( $result ) ? $result : null;
};
$read_version = static function ( string $file, string $pattern ): string {
	$source = is_file( $file ) ? file_get_contents( $file ) : false;
	return is_string( $source ) && preg_match( $pattern, $source, $match ) ? trim( $match[1] ) : '';
};
$count = static function ( string $sql ) use ( $wpdb ): int {
	$value = $wpdb->get_var( $sql );
	return null === $value ? -1 : (int) $value;
};

$plugin_file = ABSPATH . 'wp-content/plugins/auto-dealership-core/auto-dealership-core.php';
$theme_file = ABSPATH . 'wp-content/themes/car-dealer/style.css';
$schema_file = ABSPATH . 'wp-content/plugins/auto-dealership-core/src/Database/Schema.php';
$plugin_version = $read_version( $plugin_file, '/^\s*\*\s*Version:\s*([^\r\n]+)/m' );
$theme_version = $read_version( $theme_file, '/^Version:\s*([^\r\n]+)/m' );
$schema_version = $read_version( $schema_file, '/public const VERSION\s*=\s*[\'\"]([^\'\"]+)[\'\"]/' );
$active = $stored_array( $option( 'active_plugins' ) );
$plugin_active = is_array( $active ) && in_array( 'auto-dealership-core/auto-dealership-core.php', $active, true );
$theme_active = 'car-dealer' === $option( 'stylesheet' ) && 'car-dealer' === $option( 'template' );
$add( 'release_pair', $plugin_active && $theme_active && '' !== $plugin_version && $plugin_version === $theme_version ? 'pass' : 'fail', 'Expected plugin and theme must be active, readable and on the same release version.', array( 'plugin_version' => $plugin_version, 'theme_version' => $theme_version ) );
$installed_schema = $option( 'adc_db_version' );
$add( 'schema_marker', '' !== $schema_version && $schema_version === $installed_schema ? 'pass' : 'fail', 'Installed schema marker must match the release contract.', array( 'expected' => $schema_version, 'installed' => $installed_schema ) );
require_once ABSPATH . 'wp-content/plugins/auto-dealership-core/src/Audit/AuditLog.php';
require_once ABSPATH . 'wp-content/plugins/auto-dealership-core/src/Database/SchemaInspector.php';
require_once $schema_file;
$schema_issues = \AutoDealership\Database\Schema::verify();
$add( 'schema_verification', array() === $schema_issues ? 'pass' : 'fail', 'All declared tables, columns, indexes and InnoDB engines must match the plugin contract.', array( 'issue_count' => count( $schema_issues ), 'issues' => array_slice( $schema_issues, 0, 20 ) ) );

$home = $option( 'home' ) ?? '';
$siteurl = $option( 'siteurl' ) ?? '';
$home_parts = parse_url( $home );
$site_parts = parse_url( $siteurl );
$origin = static function ( $parts ): string {
	if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) { return 'invalid'; }
	return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
};
$https = is_array( $home_parts ) && is_array( $site_parts ) && 'https' === strtolower( $home_parts['scheme'] ?? '' ) && 'https' === strtolower( $site_parts['scheme'] ?? '' ) && strtolower( $home_parts['host'] ?? '' ) === strtolower( $site_parts['host'] ?? '' ) && ! in_array( strtolower( $home_parts['host'] ?? '' ), array( 'localhost', '127.0.0.1' ), true ) && ! str_ends_with( strtolower( $home_parts['host'] ?? '' ), '.invalid' );
$add( 'public_origin', $https ? 'pass' : 'fail', 'Public home and WordPress URLs must use the intended HTTPS origin.', array( 'home_origin' => $origin( $home_parts ), 'site_origin' => $origin( $site_parts ) ) );

$privacy_id = (int) ( $option( 'wp_page_for_privacy_policy' ) ?? 0 );
$privacy_row = $privacy_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT post_status,post_content FROM {$wpdb->posts} WHERE ID=%d AND post_type='page'", $privacy_id ), ARRAY_A ) : null;
$terms_row = $wpdb->get_row( "SELECT post_status,post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name IN ('terms','terms-and-conditions') ORDER BY ID ASC LIMIT 1", ARRAY_A );
$legal_text_complete = static function ( $row ): bool {
	if ( ! is_array( $row ) || 'publish' !== ( $row['post_status'] ?? '' ) ) { return false; }
	$content = (string) ( $row['post_content'] ?? '' );
	return strlen( trim( strip_tags( $content ) ) ) >= 300 && ! preg_match( '/\[\[|localhost|example\.(?:com|org|net|invalid)|النص المقترح|suggested text/i', $content );
};
$privacy_ready = $legal_text_complete( $privacy_row );
$terms_ready = $legal_text_complete( $terms_row );
$add( 'legal_pages', $privacy_ready && $terms_ready ? 'pass' : 'fail', 'Privacy and terms pages must be published with substantive, non-placeholder text.', array( 'privacy_published' => is_array( $privacy_row ) && 'publish' === $privacy_row['post_status'], 'terms_published' => is_array( $terms_row ), 'privacy_text_complete' => $privacy_ready, 'terms_text_complete' => $terms_ready ) );
$add( 'legal_approval', 'manual', 'Record the business/legal approval and effective date of both published texts.' );

$placeholder_pages = $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND (post_content LIKE %s OR post_content LIKE %s)", '%localhost%', '%example.invalid%' ) );
$add( 'editorial_placeholders', 0 === $placeholder_pages ? 'pass' : 'fail', 'Published pages must not link to local or example-only destinations.', array( 'affected_page_count' => $placeholder_pages ) );

$vehicles = $wpdb->prefix . 'adc_vehicles';
$branches = $wpdb->prefix . 'adc_branches';
$customers = $wpdb->prefix . 'adc_customers';
$suppliers = $wpdb->prefix . 'adc_suppliers';
$sales = $wpdb->prefix . 'adc_sales';
$vehicle_count = $count( "SELECT COUNT(*) FROM $vehicles" );
$demo_vehicle_count = $count( $wpdb->prepare( "SELECT COUNT(*) FROM $vehicles WHERE stock_number LIKE %s OR stock_number LIKE %s", 'DEMO-%', 'AB-DEMO-%' ) );
$demo_customer_count = $count( $wpdb->prepare( "SELECT COUNT(*) FROM $customers WHERE email LIKE %s", '%@example.invalid' ) );
$demo_supplier_count = $count( $wpdb->prepare( "SELECT COUNT(*) FROM $suppliers WHERE supplier_code LIKE %s", 'DEMO-%' ) );
$demo_posts = $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('car','car_offer') AND post_status='publish' AND (post_name LIKE %s OR post_name LIKE %s)", 'adc-demo-%', 'ab-demo-%' ) );
$demo_staff = $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", 'adc_demo_%' ) );
$demo_sales = $count( $wpdb->prepare( "SELECT COUNT(*) FROM $sales s INNER JOIN $vehicles v ON v.id=s.vehicle_id WHERE v.stock_number LIKE %s OR v.stock_number LIKE %s", 'DEMO-%', 'AB-DEMO-%' ) );
$demo_marker = $option( 'adc_demo_seed_version' );
$has_demo = null !== $demo_marker || $demo_vehicle_count > 0 || $demo_customer_count > 0 || $demo_supplier_count > 0 || $demo_posts > 0 || $demo_staff > 0 || $demo_sales > 0;
$add( 'development_data', ! $has_demo && $vehicle_count >= 0 ? 'pass' : 'fail', 'Development fixtures must be retired or explicitly excluded from the public release.', array( 'demo_marker_present' => null !== $demo_marker, 'demo_vehicles' => $demo_vehicle_count, 'demo_sales' => $demo_sales, 'demo_customers' => $demo_customer_count, 'demo_suppliers' => $demo_supplier_count, 'demo_posts' => $demo_posts, 'demo_staff' => $demo_staff ) );
$add( 'business_data_approval', 'manual', 'Record approved branches, inventory, offers, prices, contacts, consent, retention, roles and first-release policy decisions; no synthetic record counts as approval.' );

$mode = $option( 'adc_public_catalog_mode' ) ?: 'compatibility';
$mapped = $count( "SELECT COUNT(*) FROM $vehicles WHERE public_post_id>0" );
$unmapped_posts = $count( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='car' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM $vehicles v WHERE v.public_post_id=p.ID)" );
$duplicate_mappings = $count( "SELECT COUNT(*) FROM (SELECT public_post_id FROM $vehicles WHERE public_post_id>0 GROUP BY public_post_id HAVING COUNT(*)>1) d" );
$invalid_mappings = $count( "SELECT COUNT(*) FROM $vehicles v LEFT JOIN {$wpdb->posts} p ON p.ID=v.public_post_id WHERE v.public_post_id>0 AND (p.ID IS NULL OR p.post_type<>'car' OR p.post_status<>'publish')" );
$public_eligible = $count( "SELECT COUNT(*) FROM $vehicles v INNER JOIN $branches b ON b.id=v.branch_id AND b.active=1 INNER JOIN {$wpdb->posts} p ON p.ID=v.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE v.status='available'" );
$mapping_ready = $vehicle_count > 0 && $mapped > 0 && 0 === $unmapped_posts && 0 === $duplicate_mappings && 0 === $invalid_mappings && $public_eligible > 0;
$add( 'catalog_reconciliation', $mapping_ready ? 'pass' : 'fail', 'Published vehicle mappings and eligible public rows must reconcile.', array( 'vehicles' => $vehicle_count, 'mapped' => $mapped, 'unmapped_published_posts' => $unmapped_posts, 'duplicate_mappings' => $duplicate_mappings, 'invalid_mappings' => $invalid_mappings, 'eligible_rows' => $public_eligible ) );
$add( 'catalog_cutover', 'authoritative' === $mode && $mapping_ready && ! $has_demo ? 'pass' : 'fail', 'The reviewed real inventory must be the active public catalog authority.', array( 'mode' => $mode ) );

$enabled_events = $stored_array( $option( 'adc_integration_enabled_events' ) );
$add( 'provider_safety', is_array( $enabled_events ) && array() === $enabled_events ? 'pass' : 'fail', 'Provider routes must remain disabled until their contracts and staging evidence are approved.', array( 'enabled_event_count' => is_array( $enabled_events ) ? count( $enabled_events ) : -1 ) );
$add( 'provider_acceptance', 'manual', 'Decide which providers belong to this release; attach approved contracts and staging reconciliation before enabling any route.' );

$uploads = realpath( ABSPATH . 'wp-content/uploads' );
$media = array( 'referenced' => 0, 'present' => 0, 'missing' => 0, 'unsafe' => 0 );
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s", '_wp_attached_file' ) ) as $relative ) {
	++$media['referenced'];
	if ( ! is_string( $relative ) || preg_match( '#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#', $relative ) || preg_match( '#^(?:[A-Za-z]:|[/\\\\])#', $relative ) ) { ++$media['unsafe']; continue; }
	$path = realpath( ABSPATH . 'wp-content/uploads/' . $relative );
	if ( $path && $uploads && str_starts_with( strtolower( str_replace( '\\', '/', $path ) ), strtolower( str_replace( '\\', '/', $uploads ) ) . '/' ) && is_file( $path ) ) { ++$media['present']; } else { ++$media['missing']; }
}
$add( 'media_references', 0 === $media['missing'] && 0 === $media['unsafe'] ? 'pass' : 'fail', 'Referenced local media must exist under uploads.', $media );

$config_index_tracked = null;
$config_head_tracked = null;
if ( is_dir( ABSPATH . '.git' ) ) {
	$process = proc_open( array( 'git', 'ls-files', '--error-unmatch', '--', 'wp-config.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH );
	if ( is_resource( $process ) ) {
		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
		stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		$config_index_tracked = 0 === proc_close( $process );
	}
	$process = proc_open( array( 'git', 'ls-tree', '--name-only', 'HEAD', '--', 'wp-config.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH );
	if ( is_resource( $process ) ) {
		fclose( $pipes[0] );
		$listed = trim( stream_get_contents( $pipes[1] ) ); fclose( $pipes[1] );
		stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		$config_head_tracked = 0 === proc_close( $process ) ? 'wp-config.php' === $listed : null;
	}
}
$config_safe = false === $config_index_tracked && false === $config_head_tracked;
$config_status = true === $config_index_tracked || true === $config_head_tracked ? 'fail' : ( $config_safe ? 'pass' : 'manual' );
$add( 'config_version_control', $config_status, 'The release commit and index must exclude the live configuration and secrets.', array( 'index_tracked' => $config_index_tracked, 'head_tracked' => $config_head_tracked ) );
$add( 'historical_secret_review', 'manual', 'Review and rotate any live credentials or salts previously committed to repository history.' );
$add( 'external_scheduler', 'manual', 'Verify a real scheduler, mail delivery, queue health and alert routing on staging.' );
$add( 'security_review', 'manual', 'Attach proxy/WAF, security-header, dependency, upload, access-control and manual security review evidence.' );
$add( 'accessibility_performance', 'manual', 'Attach human Arabic/English accessibility review and production-like browser/load/cache measurements.' );
$add( 'recovery_offsite', 'manual', 'Attach encrypted off-site backup, separate-host restore and recovery-time/data-loss evidence.' );
$add( 'deployment_approval', 'manual', 'Record release artifact, maintenance window, rollback trigger, operators and technical/business sign-off.' );

$totals = array( 'pass' => 0, 'fail' => 0, 'manual' => 0 );
foreach ( $checks as $check ) { ++$totals[ $check['status'] ]; }
echo json_encode( array( 'generated_at_utc' => gmdate( 'c' ), 'wordpress_version' => $wp_version, 'database_server' => (string) $wpdb->get_var( 'SELECT VERSION()' ), 'checks' => $checks, 'totals' => $totals, 'release_ready' => 0 === $totals['fail'] && 0 === $totals['manual'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
exit( $totals['fail'] > 0 ? 2 : ( $totals['manual'] > 0 ? 3 : 0 ) );
