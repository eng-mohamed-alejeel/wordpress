<?php
/**
 * Plugin Name: Auto Dealership Core
 * Description: Shared business capabilities and audit foundation for the dealership platform.
 * Version: 1.28.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: auto-dealership-core
 */

defined( 'ABSPATH' ) || exit;

define( 'ADC_VERSION', '1.28.0' );
define( 'ADC_FILE', __FILE__ );
define( 'ADC_PATH', plugin_dir_path( __FILE__ ) );

require_once ADC_PATH . 'src/Core/Capabilities.php';
require_once ADC_PATH . 'src/Core/ConfigurationService.php';
require_once ADC_PATH . 'src/Audit/AuditLog.php';
require_once ADC_PATH . 'src/Database/SchemaInspector.php';
require_once ADC_PATH . 'src/Database/Schema.php';
require_once ADC_PATH . 'src/Database/Transaction.php';
require_once ADC_PATH . 'src/Database/SchemaGuard.php';
require_once ADC_PATH . 'src/Operations/OutboxService.php';
require_once ADC_PATH . 'src/Integrations/AdapterContract.php';
require_once ADC_PATH . 'src/Integrations/AdapterReadinessContract.php';
require_once ADC_PATH . 'src/Integrations/ReconciliationContract.php';
require_once ADC_PATH . 'src/Integrations/ProviderResult.php';
require_once ADC_PATH . 'src/Integrations/IntegrationRegistry.php';
require_once ADC_PATH . 'src/Integrations/IntegrationActivation.php';
require_once ADC_PATH . 'src/Integrations/AcknowledgementService.php';
require_once ADC_PATH . 'src/Integrations/DomainEventPublisher.php';
require_once ADC_PATH . 'src/Security/BranchScope.php';
require_once ADC_PATH . 'src/Security/CustomerScope.php';
require_once ADC_PATH . 'src/Security/ClientAddress.php';
require_once ADC_PATH . 'src/Security/PublicRequestGuard.php';
require_once ADC_PATH . 'src/Security/SecurityAudit.php';
require_once ADC_PATH . 'src/Reference/ReferenceService.php';
require_once ADC_PATH . 'src/Purchasing/SupplierService.php';
require_once ADC_PATH . 'src/Inventory/VehicleService.php';
require_once ADC_PATH . 'src/Inventory/VehicleSpecifications.php';
require_once ADC_PATH . 'src/Inventory/VehicleAcquisitionService.php';
require_once ADC_PATH . 'src/Inventory/VehicleIntakeService.php';
require_once ADC_PATH . 'src/Inventory/VehicleIssueService.php';
require_once ADC_PATH . 'src/Inventory/VehicleReturnService.php';
require_once ADC_PATH . 'src/Inventory/TransferService.php';
require_once ADC_PATH . 'src/Inventory/PublicCatalog.php';
require_once ADC_PATH . 'src/Inventory/CatalogMappingService.php';
require_once ADC_PATH . 'src/Reservations/ReservationService.php';
require_once ADC_PATH . 'src/Branches/BranchService.php';
require_once ADC_PATH . 'src/Leads/LeadService.php';
require_once ADC_PATH . 'src/Leads/ContactIdentity.php';
require_once ADC_PATH . 'src/Leads/CustomerIdentity.php';
require_once ADC_PATH . 'src/Leads/PublicIntake.php';
require_once ADC_PATH . 'src/Leads/RequestWorkflow.php';
require_once ADC_PATH . 'src/Pricing/Money.php';
require_once ADC_PATH . 'src/Pricing/PricingPolicy.php';
require_once ADC_PATH . 'src/Pricing/QuoteHistory.php';
require_once ADC_PATH . 'src/Pricing/QuoteDocument.php';
require_once ADC_PATH . 'src/Sales/SalesService.php';
require_once ADC_PATH . 'src/Sales/SaleCancellationService.php';
require_once ADC_PATH . 'src/Payments/PaymentService.php';
require_once ADC_PATH . 'src/Payments/RefundService.php';
require_once ADC_PATH . 'src/Delivery/DeliveryService.php';
require_once ADC_PATH . 'src/Core/VehicleMigrationCommand.php';
require_once ADC_PATH . 'src/Core/LegacyLeadMigrationCommand.php';
require_once ADC_PATH . 'src/Migration/MigrationInventory.php';
require_once ADC_PATH . 'src/Migration/LegacyVehicleMapper.php';
require_once ADC_PATH . 'src/Core/MigrationReportCommand.php';
require_once ADC_PATH . 'src/Privacy/PrivacyTools.php';
require_once ADC_PATH . 'src/Privacy/RetentionService.php';
require_once ADC_PATH . 'src/Reports/FinancialExport.php';
require_once ADC_PATH . 'src/Reports/OperationalReport.php';
require_once ADC_PATH . 'src/Admin/SettingsPage.php';
require_once ADC_PATH . 'src/Admin/AuditPage.php';
require_once ADC_PATH . 'src/Admin/OutboxPage.php';
require_once ADC_PATH . 'src/Admin/IntegrationPage.php';
require_once ADC_PATH . 'src/Admin/SecurityPage.php';
require_once ADC_PATH . 'src/Admin/OperationsPages.php';
require_once ADC_PATH . 'src/Admin/RequestPage.php';
require_once ADC_PATH . 'src/Admin/CustomerIdentityPage.php';
require_once ADC_PATH . 'src/Admin/WorkflowPages.php';
require_once ADC_PATH . 'src/Admin/PaymentPages.php';
require_once ADC_PATH . 'src/Admin/RefundPage.php';
require_once ADC_PATH . 'src/Admin/SaleCancellationPage.php';
require_once ADC_PATH . 'src/Admin/TransferPages.php';
require_once ADC_PATH . 'src/Admin/QuotePages.php';
require_once ADC_PATH . 'src/Admin/FinancialExportPage.php';
require_once ADC_PATH . 'src/Admin/OperationalReportPage.php';
require_once ADC_PATH . 'src/Admin/ReferencePage.php';
require_once ADC_PATH . 'src/Admin/CatalogCutoverPage.php';
require_once ADC_PATH . 'src/Admin/InventoryIdentityPage.php';
require_once ADC_PATH . 'src/Admin/VehicleSpecificationsPage.php';
require_once ADC_PATH . 'src/Admin/VehicleIssuePage.php';
require_once ADC_PATH . 'src/Admin/VehicleReturnPage.php';
require_once ADC_PATH . 'src/Admin/SupplierPage.php';
require_once ADC_PATH . 'src/Admin/VehicleAcquisitionPage.php';
require_once ADC_PATH . 'src/API/ResponseContract.php';
require_once ADC_PATH . 'src/API/OpenApiSpecification.php';
require_once ADC_PATH . 'src/API/Routes.php';

register_activation_hook( ADC_FILE, array( 'AutoDealership\\Core\\Capabilities', 'activate' ) );
register_activation_hook( ADC_FILE, array( 'AutoDealership\\Database\\Schema', 'install' ) );

add_action( 'plugins_loaded', static function (): void {
	if ( get_option( 'adc_roles_version' ) !== ADC_VERSION ) {
		\AutoDealership\Core\Capabilities::activate();
		update_option( 'adc_roles_version', ADC_VERSION, false );
	}
	if ( get_option( 'adc_db_version' ) !== \AutoDealership\Database\Schema::VERSION && ! get_transient( 'adc_schema_retry_pending' ) ) {
		\AutoDealership\Database\Schema::install();
	}
} );

add_action( 'admin_notices', static function (): void {
	if ( current_user_can( 'manage_options' ) && get_option( 'adc_db_version' ) !== \AutoDealership\Database\Schema::VERSION ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'تعذر التحقق من بنية قاعدة بيانات منصة المعرض. راجع فحص الجداول والفهارس قبل تنفيذ العمليات التشغيلية.', 'auto-dealership-core' ) . '</p></div>';
	}
} );

add_action( 'rest_api_init', array( 'AutoDealership\\API\\Routes', 'register' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\API\\ResponseContract', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\API\\OpenApiSpecification', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Security\\SecurityAudit', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Leads\\CustomerIdentity', 'boot' ) );
add_action( 'wp_enqueue_scripts', array( 'AutoDealership\\Leads\\PublicIntake', 'enqueue' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Database\\SchemaGuard', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Inventory\\PublicCatalog', 'boot' ) );
add_action( 'car_dealer_engagement_created', array( 'AutoDealership\\Leads\\LeadService', 'capture_theme_request' ), 10, 2 );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\SettingsPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\AuditPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\OutboxPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\IntegrationPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\SecurityPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\OperationsPages', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\RequestPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\CustomerIdentityPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\WorkflowPages', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\PaymentPages', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\RefundPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\SaleCancellationPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\TransferPages', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\QuotePages', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\FinancialExportPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\OperationalReportPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\ReferencePage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\CatalogCutoverPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\InventoryIdentityPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\VehicleSpecificationsPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\VehicleIssuePage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\VehicleReturnPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\SupplierPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Admin\\VehicleAcquisitionPage', 'boot' ) );
add_action( 'plugins_loaded', array( 'AutoDealership\\Privacy\\PrivacyTools', 'boot' ) );
add_action( 'plugins_loaded', static function (): void {
	do_action( 'adc_integrations_register' );
}, 20 );
add_action( 'plugins_loaded', array( 'AutoDealership\\Integrations\\AcknowledgementService', 'boot' ), 21 );

add_filter( 'cron_schedules', static function ( array $schedules ): array {
	$schedules['adc_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every 5 minutes', 'auto-dealership-core' ) );
	$schedules['adc_fifteen_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes', 'auto-dealership-core' ) );
	return $schedules;
} );
add_action( 'init', static function (): void {
	if ( ! wp_next_scheduled( 'adc_expire_reservations' ) ) {
		wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'adc_fifteen_minutes', 'adc_expire_reservations' );
	}
	if ( ! wp_next_scheduled( 'adc_privacy_retention' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'adc_privacy_retention' );
	}
	if ( ! wp_next_scheduled( 'adc_process_outbox' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'adc_five_minutes', 'adc_process_outbox' );
	}
	if ( ! wp_next_scheduled( 'adc_prune_request_limits' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'adc_prune_request_limits' );
	}
} );
add_action( 'adc_expire_reservations', array( 'AutoDealership\\Reservations\\ReservationService', 'expire_due' ) );
add_action( 'adc_privacy_retention', array( 'AutoDealership\\Privacy\\RetentionService', 'run' ) );
add_action( 'adc_process_outbox', array( 'AutoDealership\\Operations\\OutboxService', 'run' ) );
add_action( 'adc_prune_request_limits', array( 'AutoDealership\\Security\\PublicRequestGuard', 'prune' ) );
register_deactivation_hook( ADC_FILE, static function (): void {
	wp_clear_scheduled_hook( 'adc_expire_reservations' );
	wp_clear_scheduled_hook( 'adc_privacy_retention' );
	wp_clear_scheduled_hook( 'adc_process_outbox' );
	wp_clear_scheduled_hook( 'adc_prune_request_limits' );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'adc migrate-vehicles', 'AutoDealership\\Core\\VehicleMigrationCommand' );
	\WP_CLI::add_command( 'adc migrate-leads', 'AutoDealership\\Core\\LegacyLeadMigrationCommand' );
	\WP_CLI::add_command( 'adc migration-report', 'AutoDealership\\Core\\MigrationReportCommand' );
}
