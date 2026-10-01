<?php
namespace AutoDealership\Integrations;

use AutoDealership\Operations\OutboxService;

defined( 'ABSPATH' ) || exit;

/** Publishes minimized domain references only when an adapter and explicit route are active. */
final class DomainEventPublisher {
	public static function publish( string $event_key, int $subject_id, int $branch_id, string $state, int $version = 1 ) {
		$definition = IntegrationRegistry::definition( $event_key );
		if ( ! $definition || $subject_id < 1 || $branch_id < 1 || $version < 1 || $version > 2147483647 || $state !== $definition['state'] ) {
			return self::error( 'adc_integration_event_invalid', 400 );
		}
		$payload = array(
			'subject_type'=>$definition['subject_type'],
			'subject_id'=>$subject_id,
			'branch_id'=>$branch_id,
			'state'=>$state,
			'version'=>$version,
		);
		$actor_id = get_current_user_id();
		if ( $actor_id > 0 ) { $payload['actor_user_id'] = $actor_id; }
		$enabled = IntegrationActivation::is_enabled( $event_key, $payload );
		if ( ! $enabled ) { return true; }
		if ( '' === IntegrationRegistry::route( $event_key ) ) {
			return self::error( 'adc_integration_route_missing', 503 );
		}
		if ( empty( IntegrationRegistry::readiness( $event_key )['ready'] ) ) {
			return self::error( 'adc_integration_not_ready', 503 );
		}
		$key = implode( ':', array( 'domain', $event_key, $subject_id, $state, 'v' . $version ) );
		return OutboxService::enqueue( $event_key, $payload, $key );
	}

	/** Boolean boundary for an owning transaction's commit callback. */
	public static function commit( string $event_key, int $subject_id, int $branch_id, string $state, int $version = 1 ): bool {
		return ! is_wp_error( self::publish( $event_key, $subject_id, $branch_id, $state, $version ) );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Domain event publication failed.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
