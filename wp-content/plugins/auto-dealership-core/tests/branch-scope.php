<?php
/** Offline authorization regression tests; never boot WordPress or connect to a database. */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
require ABSPATH . 'wp-includes/class-wp-error.php';

$test_user = 0;
$test_caps = array();
$test_branches = array();
$test_branch_lists = array();
$test_active_branches = array( 7, 8 );
function get_current_user_id() { return $GLOBALS['test_user']; }
function user_can( $id, $cap ) { return in_array( $cap, $GLOBALS['test_caps'][ $id ] ?? array(), true ); }
function current_user_can( $cap, ...$args ) { return user_can( get_current_user_id(), $cap ); }
function get_user_meta( $id, $key, $single = false ) { return 'adc_branch_ids' === $key ? ( $GLOBALS['test_branch_lists'][ $id ] ?? '' ) : ( $GLOBALS['test_branches'][ $id ] ?? '' ); }
function __( $text, $domain = '' ) { return $text; }
function do_action( ...$args ) {}
function sanitize_key( $text ) { return $text; }
function sanitize_textarea_field( $text ) { return $text; }
function wp_unslash( $text ) { return $text; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid-' . $action; }
function update_user_meta( ...$args ) { throw new RuntimeException( 'Unexpected branch assignment write.' ); }
function get_option( $key, $default = false ) {
	if ( 'adc_db_version' === $key ) { return AutoDealership\Database\Schema::VERSION; }
	if ( 'adc_schema_issues' === $key ) { return array(); }
	return $default;
}

/** Query recorder. Result filtering is not simulated; assertions inspect SQL and arguments. */
final class AuthorizationDatabase {
	public $prefix = 'wp_';
	public $users = 'wp_users';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	public $last_error = '';
	public $last_query = '';
	public $last_args = array();
	public $insert_id = 0;
	public $suppress_errors = false;
	public $row = null;
	public $col = array();
	public $writes = 0;
	/**
	 * Signatures and members mirror wpdb (optional query/output parameters, string for
	 * prepare(), array|null for get_results(), the global table-name properties) so
	 * callers that rely on the real surface are not reported against this recorder.
	 */
	public function prepare( $sql, ...$args ): string {
		$this->last_query = $sql;
		$this->last_args = count( $args ) === 1 && is_array( $args[0] ) ? $args[0] : $args;
		return $sql;
	}
	public function get_row( $sql = null, $format = OBJECT, $y = 0 ) { return $this->row; }
	public function get_col( $sql = null, $x = 0 ) { return $this->col; }
	public function get_var( $sql = null, $x = 0, $y = 0 ) { return in_array( (int) ( $this->last_args[0] ?? 0 ), $GLOBALS['test_active_branches'], true ) ? '1' : null; }
	public function get_results( $sql = null, $format = OBJECT ): ?array { return array(); }
	public function get_charset_collate(): string { return $this->charset_collate; }
	public function esc_like( $text ): string { return addcslashes( (string) $text, '_%\\' ); }
	public function query( $sql ) { return true; }
	public function insert( ...$args ) { ++$this->writes; throw new RuntimeException( 'Unexpected write.' ); }
	public function replace( ...$args ) { ++$this->writes; throw new RuntimeException( 'Unexpected write.' ); }
	public function update( ...$args ) { ++$this->writes; throw new RuntimeException( 'Unexpected write.' ); }
	public function delete( ...$args ) { ++$this->writes; throw new RuntimeException( 'Unexpected write.' ); }
}
$wpdb = new AuthorizationDatabase();
require __DIR__ . '/../src/Database/Schema.php';
require __DIR__ . '/../src/Database/Transaction.php';
require __DIR__ . '/../src/Security/BranchScope.php';
require __DIR__ . '/../src/Inventory/VehicleService.php';
require __DIR__ . '/../src/Inventory/VehicleSpecifications.php';
require __DIR__ . '/../src/Leads/LeadService.php';
require __DIR__ . '/../src/Admin/SettingsPage.php';
require __DIR__ . '/../src/Admin/WorkflowPages.php';

use AutoDealership\Security\BranchScope;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Leads\LeadService;
use AutoDealership\Admin\SettingsPage;

$checks = 0;
function check( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
}

check( ! BranchScope::allows( 0 ) && ! BranchScope::allows( 7 ), 'Anonymous access must fail.' );
check( null === VehicleService::get( 1, true ), 'Private flag cannot authorize an anonymous vehicle read.' );
$test_user = 1;
$test_caps[1] = array( 'manage_options', 'edit_user' );
check( BranchScope::allows( 0 ) && BranchScope::allows( 99 ), 'Administrator can triage unassigned and all-branch data.' );
check( ! BranchScope::allows( -1 ), 'Negative branch IDs are invalid even for administrators.' );

$test_user = 2;
$test_caps[2] = array( 'adc_manage_branch_leads', 'adc_view_branch_leads', 'adc_view_inventory' );
foreach ( array( '', 0, -1, '7invalid', '7.5', array( 7 ), true, '999999999999999999999999' ) as $invalid ) {
	$test_branches[2] = $invalid;
	check( 0 === BranchScope::assigned_branch(), 'Invalid branch metadata must not become a valid branch.' );
	check( ! BranchScope::allows( 0 ) && ! BranchScope::allows( 7 ), 'Invalid assignment must deny access.' );
}
$test_branches[2] = '7';
check( BranchScope::allows( 7 ) && ! BranchScope::allows( 8 ), 'Manager is restricted to the assigned branch.' );
$test_branch_lists[2] = array( '7', '8', '8', 'invalid' );
check( array( 7, 8 ) === BranchScope::assigned_branches() && BranchScope::allows( 7 ) && BranchScope::allows( 8 ), 'Validated multi-branch metadata grants each active assigned branch once.' );
check( array( 'branch_id IN (%d,%d)', array( 7, 8 ) ) === BranchScope::predicate( 'branch_id' ), 'Multi-branch query scope uses a prepared IN predicate.' );
$test_active_branches = array( 7 );
BranchScope::clear_cache();
check( ! BranchScope::allows( 8 ) && array( 'branch_id = %d', array( 7 ) ) === BranchScope::predicate( 'branch_id' ), 'Inactive secondary assignment is removed from direct and query scope.' );
$test_active_branches = array( 7, 8 );
BranchScope::clear_cache();
$test_branch_lists[2] = array();
check( BranchScope::can_manage_lead( array( 'branch_id' => 7, 'owner_user_id' => 3 ) ), 'Manager can manage another owner in their branch.' );
check( ! BranchScope::can_manage_lead( array( 'branch_id' => 8, 'owner_user_id' => 3 ) ), 'Manager cannot manage another branch.' );
$test_branches[2] = 0;
LeadService::list_for_current_user();
check( str_contains( $wpdb->last_query, 'WHERE 1 = 0' ), 'Unassigned manager must not list branch-zero intake.' );
VehicleService::list_for_current_user();
check( str_contains( $wpdb->last_query, 'WHERE 1 = 0' ), 'Unassigned inventory user must not list branch-zero stock.' );
$queue_scope = new ReflectionMethod( AutoDealership\Admin\WorkflowPages::class, 'branch_sql' );
$queue_scope->setAccessible( true );
check( array( ' AND 1 = 0', array() ) === $queue_scope->invoke( null ), 'Approval/finance/delivery queues deny unassigned users.' );

$test_user = 3;
$test_caps[3] = array( 'adc_manage_own_leads', 'adc_view_own_leads' );
$test_branches[3] = 7;
$own_lead = array( 'id' => 1, 'branch_id' => 7, 'owner_user_id' => 3, 'stage' => 'new' );
check( BranchScope::can_manage_lead( $own_lead ), 'Sales can manage their own same-branch lead.' );
check( ! BranchScope::can_manage_lead( array_merge( $own_lead, array( 'owner_user_id' => 2 ) ) ), 'Sales cannot manage another owner.' );
$test_active_branches = array();
BranchScope::clear_cache();
check( ! BranchScope::allows( 7 ) && array( '1 = 0', array() ) === BranchScope::predicate( 'branch_id' ), 'Inactive assigned branch revokes direct and query scope.' );
$test_active_branches = array( 7, 8 );
BranchScope::clear_cache();
$test_branches[3] = 8;
check( ! BranchScope::can_manage_lead( $own_lead ), 'Moving staff revokes their old-branch lead access.' );
LeadService::list_for_current_user();
check( str_contains( $wpdb->last_query, 'l.branch_id = %d AND l.owner_user_id = %d' ) && array( 8, 3, 20, 0 ) === $wpdb->last_args, 'Owned lead lists require both branch and owner.' );
$wpdb->row = $own_lead;
$result = LeadService::update_stage( 1, 'contacted' );
check( $result instanceof WP_Error && 'adc_lead_not_found' === $result->get_error_code(), 'Old-branch stage change is denied by the service.' );
$result = LeadService::add_activity( 1, 'call', 'Test' );
check( $result instanceof WP_Error && 'adc_lead_not_found' === $result->get_error_code(), 'Old-branch activity creation is denied by the service.' );
check( 0 === $wpdb->writes, 'Rejected requests never write.' );
check( null === VehicleService::get( 1, true ), 'A branch assignment and private flag do not grant inventory read.' );

$test_user = 4;
$test_caps[4] = array( 'adc_view_finance' );
$test_branches[4] = 7;
$wpdb->row = null;
VehicleService::get( 1, true );
check( ! str_contains( $wpdb->last_query, ',purchase_cost' ) && ! str_contains( $wpdb->last_query, ',vin' ), 'Finance read alone grants neither vehicle costs nor inventory VIN access.' );
check( str_contains( $wpdb->last_query, 'AND branch_id = %d' ) && array( 1, 7 ) === $wpdb->last_args, 'Direct finance vehicle reads are branch scoped.' );
$test_caps[4][] = 'adc_view_vehicle_costs';
VehicleService::get( 1, true );
check( str_contains( $wpdb->last_query, ',purchase_cost' ) && ! str_contains( $wpdb->last_query, ',vin' ), 'Explicit cost capability grants costs without granting inventory VIN access.' );
check( str_contains( $wpdb->last_query, 'AND branch_id = %d' ) && array( 1, 7 ) === $wpdb->last_args, 'Cost-authorized finance reads retain branch scope.' );
$test_branches[4] = 0;
VehicleService::get( 1, true );
check( str_contains( $wpdb->last_query, 'AND 1 = 0' ), 'Cost capability cannot bypass a missing branch assignment.' );
$test_branches[4] = 7;
$test_caps[4] = array( 'adc_view_finance' );
VehicleService::get( 1, true );
check( ! str_contains( $wpdb->last_query, ',purchase_cost' ), 'Revoking the cost capability immediately removes cost fields.' );
$test_user = 2;
$test_branches[2] = 7;
VehicleService::get( 1, true );
check( str_contains( $wpdb->last_query, ',vin' ) && ! str_contains( $wpdb->last_query, ',purchase_cost' ), 'Inventory reader cannot retrieve purchase costs.' );
try {
	BranchScope::predicate( 'v.branch_id OR 1=1' );
	throw new RuntimeException( 'Unsafe column accepted.' );
} catch ( InvalidArgumentException $expected ) {
	check( true, 'SQL column validation rejects unsafe identifiers.' );
}

$test_user = 1;
foreach ( array(
	array( 'adc_branch_id' => '0' ),
	array( 'adc_branch_id' => '0', 'adc_branch_nonce' => 'wrong' ),
	array( 'adc_branch_id' => array( '0' ), 'adc_branch_nonce' => 'valid-adc_assign_branch_2' ),
	array( 'adc_branch_id' => '-7', 'adc_branch_nonce' => 'valid-adc_assign_branch_2' ),
	array( 'adc_branch_id' => '7invalid', 'adc_branch_nonce' => 'valid-adc_assign_branch_2' ),
	array( 'adc_branch_id' => '0', 'adc_branch_nonce' => 'valid-adc_assign_branch_3' ),
) as $post ) {
	$_POST = $post;
	SettingsPage::save_branch( 2 );
	check( true, 'Invalid/missing nonce or malformed assignment cannot write metadata.' );
}
echo 'PASS: ' . $checks . " offline authorization regression checks. No database was used.\n";
