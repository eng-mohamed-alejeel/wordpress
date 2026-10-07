<?php
namespace AutoDealership\Integrations;

use AutoDealership\Operations\OutboxService;

defined( 'ABSPATH' ) || exit;

/** Validates provider adapters and binds each approved domain event to one runtime handler. */
final class IntegrationRegistry {
	public const READINESS_CHECKS = array( 'credentials', 'endpoint', 'authentication', 'timeouts', 'idempotency', 'acknowledgements', 'reconciliation' );
	public const EVENTS = array(
		'reservation.confirmed' => array( 'subject_type'=>'reservation', 'state'=>'confirmed' ),
		'sale.approved'         => array( 'subject_type'=>'sale', 'state'=>'approved' ),
		'finance.submitted'     => array( 'subject_type'=>'finance_request', 'state'=>'submitted' ),
		'finance.under_review'  => array( 'subject_type'=>'finance_request', 'state'=>'under_review' ),
		'finance.approved'      => array( 'subject_type'=>'finance_request', 'state'=>'approved' ),
		'finance.rejected'      => array( 'subject_type'=>'finance_request', 'state'=>'rejected' ),
		'payment.verified'      => array( 'subject_type'=>'payment', 'state'=>'verified' ),
		'delivery.released'     => array( 'subject_type'=>'delivery', 'state'=>'delivered' ),
	);

	private static array $adapters = array();
	private static array $routes = array();

	public static function register( AdapterContract $adapter ) {
		try {
			$declared_id = $adapter->id();
			$declared_events = $adapter->events();
		} catch ( \Throwable ) {
			return self::error( 'adc_integration_adapter_invalid' );
		}
		$id = sanitize_key( $declared_id );
		if ( ! preg_match( '/\A[a-z][a-z0-9_.-]{1,63}\z/', $id ) || $id !== $declared_id || ! $declared_events || count( $declared_events ) > 20 ) {
			return self::error( 'adc_integration_adapter_invalid' );
		}
		foreach ( $declared_events as $event_key ) {
			if ( ! is_string( $event_key ) ) { return self::error( 'adc_integration_adapter_invalid' ); }
		}
		$events = array_values( array_unique( $declared_events ) );
		if ( isset( self::$adapters[ $id ] ) && self::$adapters[ $id ] !== $adapter ) {
			return self::error( 'adc_integration_adapter_conflict', 409 );
		}
		foreach ( $events as $event_key ) {
			if ( ! is_string( $event_key ) || ! isset( self::EVENTS[ $event_key ] ) ) {
				return self::error( 'adc_integration_event_unsupported' );
			}
			if ( isset( self::$routes[ $event_key ] ) && self::$routes[ $event_key ] !== $id ) {
				return self::error( 'adc_integration_route_conflict', 409 );
			}
		}
		self::$adapters[ $id ] = $adapter;
		foreach ( $events as $event_key ) {
			self::$routes[ $event_key ] = $id;
			OutboxService::register_handler( $event_key, static function ( array $payload, array $event ) use ( $adapter, $event_key, $id ) {
				$result = $adapter->deliver( $event_key, $payload, $event );
				if ( $result instanceof ProviderResult ) {
					$recorded = AcknowledgementService::record_delivery( $id, $event_key, $event, $result );
					return is_wp_error( $recorded ) ? $recorded : true;
				}
				if ( true === $result && $adapter instanceof AdapterReadinessContract ) {
					return new \WP_Error( 'adc_integration_receipt_required', __( 'The ready provider adapter must return an acknowledgement result.', 'auto-dealership-core' ), array( 'status'=>503 ) );
				}
				return $result;
			} );
		}
		return array( 'adapter_id'=>$id, 'events'=>$events );
	}

	public static function route( string $event_key ): string {
		return self::$routes[ $event_key ] ?? '';
	}

	public static function adapter( string $adapter_id ): ?AdapterContract {
		return self::$adapters[ $adapter_id ] ?? null;
	}

	/** Returns only safe booleans and machine keys; adapters cannot expose values here. */
	public static function readiness( string $event_key ): array {
		$id = self::route( $event_key );
		$result = array( 'ready'=>false, 'adapter_id'=>$id, 'environment'=>'', 'checks'=>array(), 'missing'=>self::READINESS_CHECKS, 'reconciliation'=>false );
		$adapter = $id ? self::adapter( $id ) : null;
		if ( ! $adapter instanceof AdapterReadinessContract ) { return $result; }
		try {
			$environment = sanitize_key( $adapter->environment() );
			$declared = $adapter->readiness_checks();
		} catch ( \Throwable ) {
			return $result;
		}
		if ( ! in_array( $environment, array( 'sandbox','production' ), true ) || ! is_array( $declared ) ) { return $result; }
		$checks = array();
		foreach ( self::READINESS_CHECKS as $check ) {
			$checks[ $check ] = true === ( $declared[ $check ] ?? false );
			if ( 'reconciliation' === $check ) { $checks[ $check ] = $checks[ $check ] && $adapter instanceof ReconciliationContract; }
		}
		$missing = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
		return array(
			'ready'=>! $missing, 'adapter_id'=>$id, 'environment'=>$environment, 'checks'=>$checks,
			'missing'=>$missing, 'reconciliation'=>$adapter instanceof ReconciliationContract,
		);
	}

	public static function catalogue(): array {
		$catalogue = array();
		foreach ( self::EVENTS as $event_key=>$definition ) {
			$readiness = self::readiness( $event_key );
			$catalogue[] = array_merge( array( 'event_key'=>$event_key, 'subject_type'=>$definition['subject_type'], 'state'=>$definition['state'], 'enabled'=>IntegrationActivation::configured( $event_key ) ), $readiness );
		}
		return $catalogue;
	}

	public static function definition( string $event_key ): ?array {
		return self::EVENTS[ $event_key ] ?? null;
	}

	private static function error( string $code, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, __( 'Integration adapter registration failed.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
