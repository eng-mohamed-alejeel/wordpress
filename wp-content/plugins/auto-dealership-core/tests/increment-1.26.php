<?php
/** Integration activation, acknowledgements and reconciliation acceptance for 1.26. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\IntegrationPage;
use AutoDealership\Database\Schema;
use AutoDealership\Integrations\AcknowledgementService;
use AutoDealership\Integrations\AdapterContract;
use AutoDealership\Integrations\AdapterReadinessContract;
use AutoDealership\Integrations\DomainEventPublisher;
use AutoDealership\Integrations\IntegrationActivation;
use AutoDealership\Integrations\IntegrationRegistry;
use AutoDealership\Integrations\ProviderResult;
use AutoDealership\Integrations\ReconciliationContract;
use AutoDealership\Operations\OutboxService;

$integration_126_previous_user = get_current_user_id();
$integration_126_option_sentinel = new stdClass();
$integration_126_option_before = get_option( IntegrationActivation::OPTION, $integration_126_option_sentinel );
$integration_126_outbox = Schema::table( 'outbox' );
$integration_126_receipts = Schema::table( 'integration_receipts' );

try {
	AcknowledgementService::boot();
	// Prove that the additive schema creates the receipt ledger without replacing outbox data.
	$upgrade_event = OutboxService::enqueue( 'test.integration_upgrade', array( 'subject_type'=>'sale', 'subject_id'=>126001 ), 'integration-upgrade-126001' );
	$wpdb->query( "DROP TABLE `$integration_126_receipts`" );
	update_option( 'adc_db_version', '1.14.0', false );
	Schema::install();
	adc_check( Schema::is_ready() && array() === Schema::verify() && (int) $upgrade_event['id'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $integration_126_outbox WHERE id=%d", $upgrade_event['id'] ) ), 'The additive acknowledgement-ledger upgrade preserves existing outbox events.' );

	$invalid_result_rejected = false;
	try { ProviderResult::pending( 'unsafe reference with spaces' ); } catch ( InvalidArgumentException $error ) { $invalid_result_rejected = true; }
	adc_check( $invalid_result_rejected, 'Provider results reject unsafe opaque references before persistence.' );

	$partial = new class implements AdapterContract, AdapterReadinessContract {
		public function id(): string { return 'partial-readiness'; }
		public function events(): array { return array( 'sale.approved' ); }
		public function environment(): string { return 'sandbox'; }
		public function readiness_checks(): array { return array( 'endpoint'=>true ); }
		public function deliver( string $event_key, array $payload, array $event ) { return ProviderResult::pending( 'partial-' . (int) $event['id'] ); }
	};
	IntegrationRegistry::register( $partial );
	$partial_readiness = IntegrationRegistry::readiness( 'sale.approved' );
	adc_check( ! $partial_readiness['ready'] && in_array( 'credentials', $partial_readiness['missing'], true ) && in_array( 'reconciliation', $partial_readiness['missing'], true ), 'The readiness gate identifies every missing provider control without exposing configuration values.' );

	$integration_manager = $make_user( 'integration_manager_126', 'dealership_general_manager', $branch_a['id'] );
	wp_set_current_user( $sales_a );
	$denied_activation = IntegrationActivation::set_enabled( 'sale.approved', true, 'Unauthorized provider activation' );
	adc_check( is_wp_error( $denied_activation ) && 'adc_integration_forbidden' === $denied_activation->get_error_code(), 'Ordinary sales staff cannot activate integration routes.' );

	wp_set_current_user( $integration_manager );
	$blocked_activation = IntegrationActivation::set_enabled( 'sale.approved', true, 'Attempt before readiness is complete' );
	adc_check( is_wp_error( $blocked_activation ) && 'adc_integration_not_ready' === $blocked_activation->get_error_code(), 'A registered but incomplete adapter cannot be activated.' );
	update_option( IntegrationActivation::OPTION, array( 'sale.approved' ), false );
	$runtime_block = DomainEventPublisher::publish( 'sale.approved', 126002, (int) $branch_a['id'], 'approved' );
	adc_check( is_wp_error( $runtime_block ) && 'adc_integration_not_ready' === $runtime_block->get_error_code(), 'The runtime publisher blocks a directly tampered activation option when readiness is incomplete.' );
	update_option( IntegrationActivation::OPTION, array(), false );

	$ready = new class implements AdapterContract, AdapterReadinessContract, ReconciliationContract {
		public array $delivered = array();
		public array $reconciled = array();
		public function id(): string { return 'finance-ready'; }
		public function events(): array { return array( 'finance.submitted', 'finance.under_review' ); }
		public function environment(): string { return 'sandbox'; }
		public function readiness_checks(): array { return array_fill_keys( IntegrationRegistry::READINESS_CHECKS, true ); }
		public function deliver( string $event_key, array $payload, array $event ) {
			$this->delivered[] = (int) $event['id'];
			return ProviderResult::pending( 'finance-' . (int) $payload['subject_id'], 'provider_processing' );
		}
		public function reconcile( string $event_key, string $remote_reference, array $receipt ) {
			$this->reconciled[] = (int) $receipt['id'];
			return ProviderResult::accepted( $remote_reference, 'provider_confirmed' );
		}
	};
	$ready_registration = IntegrationRegistry::register( $ready );
	adc_check( is_array( $ready_registration ) && IntegrationRegistry::readiness( 'finance.submitted' )['ready'] && IntegrationRegistry::readiness( 'finance.submitted' )['reconciliation'], 'A complete adapter reports a safe ready state and reconciliation support.' );

	$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Schema::table( 'audit_events' ) . " WHERE event_key='integration.route_enabled'" );
	$enabled = IntegrationActivation::set_enabled( 'finance.submitted', true, 'Sandbox contract and reconciliation checks completed' );
	adc_check( is_array( $enabled ) && IntegrationActivation::configured( 'finance.submitted' ) && $audit_before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Schema::table( 'audit_events' ) . " WHERE event_key='integration.route_enabled'" ), 'An authorized manager activates a ready route with an audit record.' );

	add_filter( 'query', $break_audit );
	$activation_rollback = IntegrationActivation::set_enabled( 'finance.under_review', true, 'This activation must roll back with audit failure' );
	remove_filter( 'query', $break_audit );
	adc_check( is_wp_error( $activation_rollback ) && ! IntegrationActivation::configured( 'finance.under_review' ), 'Activation audit failure restores the exact prior route policy.' );
	adc_check( is_array( IntegrationActivation::set_enabled( 'finance.under_review', true, 'Sandbox acknowledgement path verified') ), 'A second ready route can be enabled independently.' );

	$published = DomainEventPublisher::publish( 'finance.submitted', 126003, (int) $branch_a['id'], 'submitted' );
	$delivered = OutboxService::process_due( 10 );
	$receipt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $integration_126_receipts WHERE outbox_id=%d", $published['id'] ), ARRAY_A );
	adc_check( 1 === $delivered['completed'] && $receipt && 'pending' === $receipt['status'] && 'finance-ready' === $receipt['adapter_id'], 'Structured delivery creates one durable pending provider receipt before the outbox event completes.' );

	wp_set_current_user( $sales_a );
	adc_check( is_wp_error( AcknowledgementService::counts() ) && is_wp_error( AcknowledgementService::request_reconciliation( (int) $receipt['id'], 'Unauthorized reconciliation request' ) ), 'Sales staff cannot inspect or request provider reconciliation.' );
	wp_set_current_user( $integration_manager );
	$queued_reconciliation = AcknowledgementService::request_reconciliation( (int) $receipt['id'], 'Provider acknowledgement has remained pending' );
	$reconciled = OutboxService::process_due( 10 );
	$receipt_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $integration_126_receipts WHERE id=%d", $receipt['id'] ) );
	adc_check( is_array( $queued_reconciliation ) && 1 === $reconciled['completed'] && 'accepted' === $receipt_status && array( (int) $receipt['id'] ) === $ready->reconciled, 'Operator reconciliation is queued, polled asynchronously and records the accepted acknowledgement.' );

	$early = AcknowledgementService::receive_acknowledgement( 'finance-ready', 'finance.under_review', ProviderResult::accepted( 'finance-126004', 'webhook_accepted' ) );
	$unmatched_counts = AcknowledgementService::counts();
	adc_check( is_array( $early ) && !$early['matched'] && $unmatched_counts['unmatched'] >= 1, 'A verified acknowledgement that arrives before delivery is retained as an unmatched operational exception.' );
	$early_published = DomainEventPublisher::publish( 'finance.under_review', 126004, (int) $branch_a['id'], 'under_review' );
	OutboxService::process_due( 10 );
	$linked = $wpdb->get_row( $wpdb->prepare( "SELECT id,outbox_id,status FROM $integration_126_receipts WHERE adapter_id='finance-ready' AND reference_hash=%s", hash( 'sha256', 'finance-126004' ) ), ARRAY_A );
	adc_check( (int) $early_published['id'] === (int) $linked['outbox_id'] && 'accepted' === $linked['status'], 'Later delivery links the early acknowledgement without downgrading its accepted state.' );

	$duplicate = AcknowledgementService::receive_acknowledgement( 'finance-ready', 'finance.under_review', ProviderResult::accepted( 'finance-126004', 'webhook_accepted' ) );
	$conflicting = AcknowledgementService::receive_acknowledgement( 'finance-ready', 'finance.under_review', ProviderResult::rejected( 'finance-126004', 'provider_rejected' ) );
	adc_check( is_array( $duplicate ) && !$duplicate['created'] && is_wp_error( $conflicting ) && 'mismatch' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $integration_126_receipts WHERE id=%d", $linked['id'] ) ), 'Duplicate acknowledgements are idempotent while contradictory final acknowledgements become visible mismatches.' );

	$visible = AcknowledgementService::records( '', 1, 50 );
	$encoded_visible = wp_json_encode( $visible );
	adc_check( is_array( $visible ) && str_contains( $encoded_visible, 'sha256:' ) && ! str_contains( $encoded_visible, 'finance-126004' ), 'Operator queries expose a reference fingerprint and never the full provider reference.' );

	ob_start(); IntegrationPage::render(); $integration_html = ob_get_clean();
	adc_check( str_contains( $integration_html, 'finance.submitted' ) && str_contains( $integration_html, 'sha256:' ) && ! str_contains( $integration_html, 'finance-126004' ), 'The restricted integration workspace shows readiness and reconciliation metadata without secrets or full references.' );

	$disabled = IntegrationActivation::set_enabled( 'finance.submitted', false, 'Sandbox route closed after acceptance verification' );
	adc_check( is_array( $disabled ) && ! IntegrationActivation::configured( 'finance.submitted' ), 'Each route can be disabled independently through the audited central gate.' );
} finally {
	wp_set_current_user( $integration_126_previous_user );
	$integration_126_option_before === $integration_126_option_sentinel ? delete_option( IntegrationActivation::OPTION ) : update_option( IntegrationActivation::OPTION, $integration_126_option_before, false );
	$wpdb->query( "DELETE FROM $integration_126_receipts WHERE adapter_id IN ('finance-ready','partial-readiness')" );
	$wpdb->query( "DELETE FROM $integration_126_outbox WHERE event_key IN ('test.integration_upgrade','finance.submitted','finance.under_review','integration.reconcile')" );
}
