<?php
namespace AutoDealership\Marketing;

defined( 'ABSPATH' ) || exit;

/** Per-user notification choices with safe defaults for existing accounts. */
final class AffiliateNotificationPreferences {
	private const META = 'adc_affiliate_notification_preferences';
	private const EVENTS = array( 'created','approved','paid','void','returned' );

	public static function defaults(): array {
		return array( 'inbox_enabled'=>true, 'email_enabled'=>true, 'language'=>'auto', 'events'=>array_fill_keys( self::EVENTS, true ) );
	}

	public static function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		$defaults = self::defaults();
		if ( ! is_array( $stored ) ) { return $defaults; }
		$events = is_array( $stored['events'] ?? null ) ? $stored['events'] : array();
		$defaults['inbox_enabled'] = ! empty( $stored['inbox_enabled'] );
		$defaults['email_enabled'] = ! empty( $stored['email_enabled'] );
		$defaults['language'] = in_array( $stored['language'] ?? 'auto', array( 'auto','ar','en' ), true ) ? $stored['language'] : 'auto';
		foreach ( self::EVENTS as $event ) { $defaults['events'][ $event ] = array_key_exists( $event, $events ) ? ! empty( $events[ $event ] ) : true; }
		return $defaults;
	}

	public static function save( int $user_id, array $input ) {
		if ( $user_id < 1 ) { return new \WP_Error( 'adc_affiliate_preferences_user', 'Invalid notification user.', array( 'status'=>400 ) ); }
		$events = array();
		foreach ( array_keys( self::defaults()['events'] ) as $event ) { $events[ $event ] = ! empty( $input['events'][ $event ] ); }
		$value = array( 'inbox_enabled'=>! empty( $input['inbox_enabled'] ), 'email_enabled'=>! empty( $input['email_enabled'] ), 'language'=>in_array( $input['language'] ?? 'auto', array( 'auto','ar','en' ), true ) ? $input['language'] : 'auto', 'events'=>$events );
		if ( ! update_user_meta( $user_id, self::META, $value ) && get_user_meta( $user_id, self::META, true ) !== $value ) {
			return new \WP_Error( 'adc_affiliate_preferences_storage', 'Unable to save notification preferences.', array( 'status'=>500 ) );
		}
		return self::get( $user_id );
	}

	public static function accepts( int $user_id, string $event, string $channel = 'inbox' ): bool {
		$preferences = self::get( $user_id );
		if ( empty( $preferences['events'][ $event ] ) ) { return false; }
		return 'email' === $channel ? ! empty( $preferences['email_enabled'] ) : ! empty( $preferences['inbox_enabled'] );
	}

	public static function language( int $user_id ): string {
		$language = self::get( $user_id )['language'];
		return 'auto' === $language ? \AutoDealership\Leads\NewsletterService::language( (string) get_user_meta( $user_id, 'adc_preferred_language', true ) ) : $language;
	}
}
