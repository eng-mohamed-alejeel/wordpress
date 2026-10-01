<?php
/** Provider-neutral integration contracts and transactional domain producers for 1.25. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Database\Schema;
use AutoDealership\Integrations\AdapterContract;
use AutoDealership\Integrations\AdapterReadinessContract;
use AutoDealership\Integrations\DomainEventPublisher;
use AutoDealership\Integrations\IntegrationActivation;
use AutoDealership\Integrations\IntegrationRegistry;
use AutoDealership\Integrations\ProviderResult;
use AutoDealership\Integrations\ReconciliationContract;
use AutoDealership\Operations\OutboxService;
use AutoDealership\Reservations\ReservationService;

$integration_previous_user = get_current_user_id();
$integration_outbox = Schema::table( 'outbox' );
$integration_enable = static fn( $enabled, string $event_key ) => 'reservation.confirmed' === $event_key ? true : $enabled;
$integration_option_sentinel = new stdClass();
$integration_option_before = get_option( IntegrationActivation::OPTION, $integration_option_sentinel );

try {
	$invalid_adapter = new class implements AdapterContract {
		public function id(): string { return 'invalid-fixture'; }
		public function events(): array { return array( 'customer.private_payload' ); }
		public function deliver( string $event_key, array $payload, array $event ) { return true; }
	};
	$invalid_registration = IntegrationRegistry::register( $invalid_adapter );
	adc_check( is_wp_error( $invalid_registration ) && 'adc_integration_event_unsupported' === $invalid_registration->get_error_code(), 'Integration registry rejects events outside the approved domain catalogue.' );

	$invalid_event = DomainEventPublisher::publish( 'unknown.event', 1, (int) $branch_a['id'], 'unknown' );
	adc_check( is_wp_error( $invalid_event ) && 'adc_integration_event_invalid' === $invalid_event->get_error_code(), 'Domain publisher rejects unknown events and invalid state contracts.' );

	$before_disabled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $integration_outbox" );
	$disabled = DomainEventPublisher::publish( 'reservation.confirmed', 700001, (int) $branch_a['id'], 'confirmed' );
	adc_check( true === $disabled && $before_disabled === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $integration_outbox" ), 'Approved domain events remain disabled by default and create no queue data.' );

	$enable_missing = static fn( $enabled, string $event_key ) => 'sale.approved' === $event_key ? true : $enabled;
	update_option( IntegrationActivation::OPTION, array( 'sale.approved' ), false );
	add_filter( 'adc_integration_event_enabled', $enable_missing, 10, 3 );
	$missing_route = DomainEventPublisher::publish( 'sale.approved', 700002, (int) $branch_a['id'], 'approved' );
	remove_filter( 'adc_integration_event_enabled', $enable_missing, 10 );
	adc_check( is_wp_error( $missing_route ) && 'adc_integration_route_missing' === $missing_route->get_error_code(), 'Explicit activation fails closed when no adapter owns the event route.' );

	$adapter = new class implements AdapterContract, AdapterReadinessContract, ReconciliationContract {
		public array $deliveries = array();
		public function id(): string { return 'acceptance-adapter'; }
		public function events(): array { return array( 'reservation.confirmed' ); }
		public function environment(): string { return 'sandbox'; }
		public function readiness_checks(): array { return array_fill_keys( IntegrationRegistry::READINESS_CHECKS, true ); }
		public function deliver( string $event_key, array $payload, array $event ) {
			$this->deliveries[] = array( 'event_key'=>$event_key, 'payload'=>$payload, 'event_id'=>(int) $event['id'] );
			return ProviderResult::accepted( 'acceptance-' . (int) $event['id'] );
		}
		public function reconcile( string $event_key, string $remote_reference, array $receipt ) { return ProviderResult::accepted( $remote_reference ); }
	};
	$registered = IntegrationRegistry::register( $adapter );
	adc_check( is_array( $registered ) && 'acceptance-adapter' === IntegrationRegistry::route( 'reservation.confirmed' ), 'A valid adapter owns its declared event route at runtime.' );

	$conflicting_adapter = new class implements AdapterContract {
		public function id(): string { return 'conflicting-adapter'; }
		public function events(): array { return array( 'reservation.confirmed' ); }
		public function deliver( string $event_key, array $payload, array $event ) { return true; }
	};
	$conflict = IntegrationRegistry::register( $conflicting_adapter );
	adc_check( is_wp_error( $conflict ) && 'adc_integration_route_conflict' === $conflict->get_error_code(), 'A domain event cannot be claimed by two adapters.' );

	update_option( IntegrationActivation::OPTION, array( 'reservation.confirmed' ), false );
	add_filter( 'adc_integration_event_enabled', $integration_enable, 10, 3 );
	$queued = DomainEventPublisher::publish( 'reservation.confirmed', 700003, (int) $branch_a['id'], 'confirmed' );
	$replayed = DomainEventPublisher::publish( 'reservation.confirmed', 700003, (int) $branch_a['id'], 'confirmed' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $integration_outbox WHERE id=%d", $queued['id'] ), ARRAY_A );
	adc_check( is_array( $queued ) && $queued['created'] && is_array( $replayed ) && ! $replayed['created'] && $queued['id'] === $replayed['id'], 'Enabled publication is durable and idempotent for the same domain version.' );
	adc_check( str_contains( $row['payload'], 'reservation' ) && ! preg_match( '/email|mobile|phone|name|vin|reference|amount/i', $row['payload'] ), 'Domain event payload contains minimized identifiers and state without identity or financial values.' );

	$processed = OutboxService::process_due( 1 );
	adc_check( 1 === $processed['completed'] && 1 === count( $adapter->deliveries ) && 'reservation.confirmed' === $adapter->deliveries[0]['event_key'], 'Registered adapter receives the minimized event through the existing outbox worker.' );

	wp_set_current_user( $sales_a );
	$integration_vehicle = $make_vehicle( '125' );
	$integration_reservation = ReservationService::create( array( 'vehicle_id'=>$integration_vehicle, 'customer_id'=>$customer_a, 'idempotency_key'=>wp_generate_uuid4() ) );
	$service_event = null;
	foreach ( $wpdb->get_results( "SELECT status,payload FROM $integration_outbox WHERE event_key='reservation.confirmed' ORDER BY id DESC", ARRAY_A ) ?: array() as $candidate ) {
		$decoded = json_decode( $candidate['payload'], true );
		if ( is_array( $decoded ) && is_array( $integration_reservation ) && (int) ( $decoded['subject_id'] ?? 0 ) === (int) $integration_reservation['id'] ) { $service_event = $candidate; break; }
	}
	adc_check( is_array( $integration_reservation ) && $service_event && 'pending' === $service_event['status'], 'Reservation service commits its enabled domain event in the owning business transaction.' );

	$failed_vehicle = $make_vehicle( '126' );
	$break_outbox = static function ( string $query ) use ( $integration_outbox ): string {
		return str_starts_with( $query, 'INSERT INTO `' . $integration_outbox . '`' ) ? str_replace( '`' . $integration_outbox . '`', '`missing_adc_integration_outbox`', $query ) : $query;
	};
	add_filter( 'query', $break_outbox );
	$failed_reservation = ReservationService::create( array( 'vehicle_id'=>$failed_vehicle, 'customer_id'=>$customer_a, 'idempotency_key'=>wp_generate_uuid4() ) );
	remove_filter( 'query', $break_outbox );
	adc_check( is_wp_error( $failed_reservation ) && 'available' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d', $failed_vehicle ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . ' WHERE vehicle_id=%d', $failed_vehicle ) ), 'Enabled outbox persistence failure rolls the business mutation and audit back together.' );
} finally {
	remove_filter( 'adc_integration_event_enabled', $integration_enable, 10 );
	$integration_option_before === $integration_option_sentinel ? delete_option( IntegrationActivation::OPTION ) : update_option( IntegrationActivation::OPTION, $integration_option_before, false );
	wp_set_current_user( $integration_previous_user );
	$wpdb->query( 'DELETE FROM ' . Schema::table( 'integration_receipts' ) );
	$wpdb->query( "DELETE FROM $integration_outbox WHERE event_key IN ('reservation.confirmed','sale.approved')" );
}
