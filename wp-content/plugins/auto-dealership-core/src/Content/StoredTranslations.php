<?php
namespace AutoDealership\Content;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Core\Localization;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Approved copies of business display text. Never changes operational source values. */
final class StoredTranslations {
	public const FIELDS = array(
		'branches' => array( 'name', 'city', 'address' ),
		'locations' => array( 'name' ),
		'vehicles' => array( 'brand', 'model', 'trim_name', 'exterior_color', 'interior_color', 'origin_country', 'warranty', 'interior_features', 'exterior_features', 'safety_features', 'internal_notes' ),
		'suppliers' => array( 'display_name', 'notes' ),
		'finance_requests' => array( 'provider' ),
		'vehicle_inspections' => array( 'notes' ),
		'vehicle_receipts' => array( 'notes' ),
		'vehicle_issues' => array( 'reason', 'resolution' ),
		'vehicle_movements' => array( 'reason' ),
	);

	private static function key( string $type, int $id ): string { return 'adc_translation_' . $type . '_' . $id; }
	public static function fingerprint( string $source ): string { return hash( 'sha256', $source ); }
	public static function copies( string $type, int $id ): array {
		$value = get_option( self::key( $type, $id ), array() );
		return is_array( $value ) ? $value : array();
	}

	public static function approved( string $type, int $id, string $field, string $source, string $language ): string {
		if ( ! isset( self::FIELDS[$type] ) || ! in_array( $field, self::FIELDS[$type], true ) ) { return ''; }
		$copy = self::copies( $type, $id )[$language][$field] ?? array();
		$text = (string) ( $copy['text'] ?? '' );
		if ( 'en' === $language && preg_match( '/\p{Arabic}/u', $text ) ) { return ''; }
		return ( $copy['source_hash'] ?? '' ) === self::fingerprint( $source ) ? $text : '';
	}

	/** Called only at display boundaries, never before business rules or form saves. */
	public static function text( string $type, int $id, string $field, string $source, bool $public = false ): string {
		$language = Localization::language();
		$copy = self::approved( $type, $id, $field, $source, $language );
		if ( '' !== $copy ) { return $copy; }
		if ( in_array( $field, array( 'brand', 'model', 'trim_name', 'exterior_color', 'interior_color', 'origin_country', 'city' ), true ) ) {
			$label = Localization::label( $source );
			if ( $label !== $source ) { return $label; }
		}
		if ( $public && 'en' === $language && preg_match( '/\p{Arabic}/u', $source ) ) { return 'branches' === $type && 'name' === $field ? 'Branch #' . $id : ''; }
		return $source;
	}

	public static function row( string $type, array $row, bool $public = false ): array {
		foreach ( self::FIELDS[$type] ?? array() as $field ) {
			if ( isset( $row[$field] ) ) { $row[$field] = self::text( $type, (int) $row['id'], $field, (string) $row[$field], $public ); }
		}
		return $row;
	}

	/** Administrator review uses originals, including notes that must never become public. */
	public static function records( string $type, int $page = 1 ): array {
		if ( ! current_user_can( 'manage_options' ) || ! isset( self::FIELDS[$type] ) ) { return array(); }
		global $wpdb;
		$fields = implode( ',', self::FIELDS[$type] );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id,' . $fields . ' FROM ' . Schema::table( $type ) . ' ORDER BY id DESC LIMIT 25 OFFSET %d', ( max( 1, $page ) - 1 ) * 25 ), ARRAY_A ) ?: array();
	}

	/** Lock the original record, reject stale reviews, save copies and audit atomically. */
	public static function save( string $type, int $id, string $language, array $input, array $hashes ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( self::FIELDS[$type] ) || $id < 1 || ! in_array( $language, array( 'ar', 'en' ), true ) ) { return new \WP_Error( 'adc_translation_forbidden', __( 'Administrator access is required.', 'auto-dealership-core' ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_translation_transaction', __( 'The translation could not be saved.', 'auto-dealership-core' ) ); }
		global $wpdb;
		$source = $wpdb->get_row( $wpdb->prepare( 'SELECT id,' . implode( ',', self::FIELDS[$type] ) . ' FROM ' . Schema::table( $type ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $source ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_translation_missing', __( 'The original record was not found.', 'auto-dealership-core' ) ); }
		$key = self::key( $type, $id );
		// Bypass an earlier object-cache read after obtaining the record lock.
		wp_cache_delete( $key, 'options' );
		$copies = self::copies( $type, $id );
		$changed = array();
		foreach ( $input as $field => $value ) {
			if ( ! $source || ! in_array( $field, self::FIELDS[$type], true ) || ! is_string( $value ) || ! is_string( $hashes[$field] ?? null ) || ! hash_equals( self::fingerprint( (string) $source[$field] ), $hashes[$field] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_translation_stale', __( 'The original text changed. Reload the page before reviewing its translation.', 'auto-dealership-core' ) );
			}
			$text = sanitize_textarea_field( $value );
			if ( 'en' === $language && preg_match( '/\p{Arabic}/u', $text ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_translation_language', __( 'English translations must contain English copy. The original Arabic text is preserved separately.', 'auto-dealership-core' ) ); }
			if ( strlen( $text ) > 30000 ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_translation_length', __( 'The translation is too long.', 'auto-dealership-core' ) ); }
			$next = '' === trim( $text ) ? null : array( 'source_hash' => $hashes[$field], 'text' => $text );
			if ( ( $copies[$language][$field] ?? null ) === $next ) { continue; }
			if ( null === $next ) { unset( $copies[$language][$field] ); } else { $copies[$language][$field] = $next; }
			$changed[] = $field;
		}
		if ( ! $changed ) { $wpdb->query( 'ROLLBACK' ); return true; }
		$ok = update_option( $key, $copies, false );
		$committed = $ok && Transaction::commit( static fn() => AuditLog::record( 'content.translation_saved', $type, $id, '', null, array( 'language' => $language, 'fields' => $changed ) ) );
		if ( ! $committed ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' );
			return new \WP_Error( 'adc_translation_failed', __( 'The translation could not be saved.', 'auto-dealership-core' ) );
		}
		return true;
	}
}
