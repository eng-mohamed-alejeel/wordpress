<?php
namespace AutoDealership\Integrations;

use AutoDealership\Audit\AuditLog;

defined( 'ABSPATH' ) || exit;

/** Central fail-closed activation policy. Provider code may veto, but cannot self-enable a route. */
final class IntegrationActivation {
	public const OPTION = 'adc_integration_enabled_events';

	public static function enabled_events(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) { return array(); }
		$events = array();
		foreach ( $stored as $event_key ) {
			if ( is_string( $event_key ) && isset( IntegrationRegistry::EVENTS[ $event_key ] ) ) { $events[ $event_key ] = $event_key; }
		}
		ksort( $events );
		return array_values( $events );
	}

	public static function configured( string $event_key ): bool {
		return in_array( $event_key, self::enabled_events(), true );
	}

	public static function is_enabled( string $event_key, array $payload ): bool {
		if ( ! self::configured( $event_key ) ) { return false; }
		return true === apply_filters( 'adc_integration_event_enabled', true, $event_key, $payload );
	}

	public static function set_enabled( string $event_key, bool $enabled, string $reason ) {
		if ( ! current_user_can( 'adc_manage_integrations' ) ) { return self::error( 'adc_integration_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( ! isset( IntegrationRegistry::EVENTS[ $event_key ] ) || mb_strlen( trim( $reason ) ) < 5 || mb_strlen( $reason ) > 500 ) {
			return self::error( 'adc_integration_activation_input', 400 );
		}
		$before_exists = false !== get_option( self::OPTION, false );
		$before = self::enabled_events();
		$after = $before;
		if ( $enabled ) {
			$readiness = IntegrationRegistry::readiness( $event_key );
			if ( empty( $readiness['ready'] ) ) { return self::error( 'adc_integration_not_ready', 409 ); }
			$after[] = $event_key;
		} else {
			$after = array_values( array_diff( $after, array( $event_key ) ) );
		}
		$after = array_values( array_unique( $after ) );
		sort( $after );
		if ( $before === $after ) { return array( 'updated'=>false, 'event_key'=>$event_key, 'enabled'=>$enabled ); }
		update_option( self::OPTION, $after, false );
		$verified = self::enabled_events() === $after;
		$audited = $verified && AuditLog::record(
			$enabled ? 'integration.route_enabled' : 'integration.route_disabled',
			'integration_route',
			0,
			$reason,
			array( 'event_key'=>$event_key, 'enabled'=>in_array( $event_key, $before, true ) ),
			array( 'event_key'=>$event_key, 'enabled'=>$enabled )
		);
		if ( ! $audited ) {
			$before_exists ? update_option( self::OPTION, $before, false ) : delete_option( self::OPTION );
			return self::error( 'adc_integration_activation_failed', 500 );
		}
		return array( 'updated'=>true, 'event_key'=>$event_key, 'enabled'=>$enabled );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Integration activation failed.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
