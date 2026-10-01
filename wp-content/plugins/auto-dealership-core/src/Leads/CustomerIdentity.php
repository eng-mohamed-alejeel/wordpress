<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Account authentication and reviewed CRM consolidation; contact equality grants no access. */
final class CustomerIdentity {
	private const PREFERENCE_META = 'adc_marketing_consent';
	private const PREFERENCE_AT_META = 'adc_marketing_consent_at';
	private static bool $profile_sync_suspended = false;

	public static function boot(): void {
		add_action( 'profile_update', array( self::class, 'profile_updated' ), 20, 2 );
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'profile_meta_updated' ), 20, 4 );
		}
		add_action( 'wp_login', array( self::class, 'account_login' ), 20, 2 );
	}

	public static function profile_updated( int $user_id, $old_user_data = null ): void {
		if ( ! self::$profile_sync_suspended ) { self::sync_account_profile( $user_id ); }
	}
	public static function account_login( string $login, \WP_User $user ): void {
		if ( ! self::$profile_sync_suspended ) { self::sync_account_profile( (int) $user->ID ); }
	}
	public static function profile_meta_updated( $meta_id, int $user_id, string $key, $value = null ): void {
		if ( ! self::$profile_sync_suspended && 'car_dealer_phone' === $key ) { self::sync_account_profile( $user_id ); }
	}

	/** Runs account-table changes without recursively starting profile synchronization. */
	public static function without_profile_sync( callable $callback ) {
		$before = self::$profile_sync_suspended;
		self::$profile_sync_suspended = true;
		try {
			return $callback();
		} finally {
			self::$profile_sync_suspended = $before;
		}
	}

	/** Return the current account's explicit marketing preference without exposing another account. */
	public static function current_preferences() {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return self::error( 'adc_identity_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,consent_marketing,consent_at FROM ' . Schema::table( 'customers' ) . ' WHERE account_user_id=%d AND merged_into_id IS NULL', $user_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$has_meta = metadata_exists( 'user', $user_id, self::PREFERENCE_META );
		$enabled = $row ? (bool) $row['consent_marketing'] : ( $has_meta && '1' === (string) get_user_meta( $user_id, self::PREFERENCE_META, true ) );
		$recorded_at = $row ? (string) $row['consent_at'] : (string) get_user_meta( $user_id, self::PREFERENCE_AT_META, true );
		return array( 'consent_marketing'=>$enabled, 'recorded_at'=>$recorded_at, 'linked_customer_id'=>(int) ( $row['id'] ?? 0 ) );
	}

	/** Store opt-in or opt-out explicitly for the account and its linked CRM identity. */
	public static function update_preferences( bool $marketing ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Transaction::begin() ) { return self::error( $user_id ? 'adc_identity_unavailable' : 'adc_identity_forbidden', $user_id ? 503 : 403 ); }
		foreach ( array( $wpdb->users, $wpdb->usermeta ) as $account_table ) {
			if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $account_table ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_unavailable', 503 ); }
		}
		$user = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $user_id ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,consent_marketing,consent_at FROM ' . Schema::table( 'customers' ) . ' WHERE account_user_id=%d AND merged_into_id IS NULL FOR UPDATE', $user_id ), ARRAY_A );
		if ( $wpdb->last_error || ! $user ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_unavailable', 503 ); }
		$before = $row ? (bool) $row['consent_marketing'] : ( metadata_exists( 'user', $user_id, self::PREFERENCE_META ) && '1' === (string) get_user_meta( $user_id, self::PREFERENCE_META, true ) );
		$now = current_time( 'mysql', true );
		$recorded_at = $marketing ? $now : '';
		update_user_meta( $user_id, self::PREFERENCE_META, $marketing ? '1' : '0' );
		update_user_meta( $user_id, self::PREFERENCE_AT_META, $recorded_at );
		if ( (string) get_user_meta( $user_id, self::PREFERENCE_META, true ) !== ( $marketing ? '1' : '0' ) || (string) get_user_meta( $user_id, self::PREFERENCE_AT_META, true ) !== $recorded_at ) {
			$wpdb->query( 'ROLLBACK' ); self::clean_account_cache( $user_id ); return self::error( 'adc_identity_unavailable', 503 );
		}
		if ( $row && false === $wpdb->update( Schema::table( 'customers' ), array( 'consent_marketing'=>$marketing ? 1 : 0, 'consent_at'=>$marketing ? $now : null, 'updated_at'=>$now ), array( 'id'=>(int) $row['id'] ) ) ) {
			$wpdb->query( 'ROLLBACK' ); self::clean_account_cache( $user_id ); return self::error( 'adc_identity_unavailable', 503 );
		}
		$subject_type = $row ? 'customer' : 'account'; $subject_id = $row ? (int) $row['id'] : $user_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'customer.preference_changed', $subject_type, $subject_id, 'Customer account preference', array( 'consent_marketing'=>$before ), array( 'consent_marketing'=>$marketing ) ) ) ) {
			self::clean_account_cache( $user_id ); return self::error( 'adc_identity_unavailable', 503 );
		}
		self::clean_account_cache( $user_id );
		do_action( 'adc_customer_preferences_updated', $user_id, (int) ( $row['id'] ?? 0 ), $marketing );
		return array( 'consent_marketing'=>$marketing, 'recorded_at'=>$recorded_at, 'linked_customer_id'=>(int) ( $row['id'] ?? 0 ) );
	}

	/** Atomically updates the signed-in account profile and any linked core customer. */
	public static function update_account_profile( int $user_id, string $name, string $mobile ) {
		global $wpdb;
		if ( $user_id < 1 || get_current_user_id() !== $user_id ) { return self::error( 'adc_identity_forbidden', 403 ); }
		$name = sanitize_text_field( $name );
		$mobile = ContactIdentity::normalize_mobile( $mobile, false );
		$name_length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( '' === $name || $name_length > 250 || is_wp_error( $mobile ) ) { return self::error( 'adc_identity_invalid', 400 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		foreach ( array( $wpdb->users, $wpdb->usermeta ) as $table ) {
			if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
		}
		if ( ! Transaction::begin() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$user = $wpdb->get_row( $wpdb->prepare( "SELECT ID,display_name,user_email FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $user_id ), ARRAY_A );
		$customer = $wpdb->get_row( $wpdb->prepare( 'SELECT id,merged_into_id,full_name,mobile,email FROM ' . Schema::table( 'customers' ) . ' WHERE account_user_id=%d FOR UPDATE', $user_id ), ARRAY_A );
		if ( $wpdb->last_error || ! $user || ( $customer && $customer['merged_into_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_unavailable', 503 ); }
		$old_mobile = (string) get_user_meta( $user_id, 'car_dealer_phone', true );
		$email = strtolower( sanitize_email( $user['user_email'] ) );
		$fields = array();
		if ( (string) $user['display_name'] !== $name ) { $fields[] = 'display_name'; }
		if ( $old_mobile !== $mobile ) { $fields[] = 'phone'; }
		if ( $customer && (string) $customer['full_name'] !== $name ) { $fields[] = 'customer_full_name'; }
		if ( $customer && (string) $customer['mobile'] !== $mobile ) { $fields[] = 'customer_mobile'; }
		if ( $customer && strtolower( (string) $customer['email'] ) !== $email ) { $fields[] = 'customer_email'; }
		$fields = array_values( array_unique( $fields ) );
		if ( ! $fields ) { $wpdb->query( 'ROLLBACK' ); return array( 'updated'=>false, 'linked_customer_id'=>(int) ( $customer['id'] ?? 0 ) ); }

		$ok = true;
		if ( in_array( 'display_name', $fields, true ) ) {
			$ok = 1 === $wpdb->update( $wpdb->users, array( 'display_name'=>$name ), array( 'ID'=>$user_id ), array( '%s' ), array( '%d' ) );
		}
		if ( $ok && in_array( 'phone', $fields, true ) ) {
			$meta_result = self::without_profile_sync( static fn() => update_user_meta( $user_id, 'car_dealer_phone', $mobile ) );
			$ok = false !== $meta_result && (string) get_user_meta( $user_id, 'car_dealer_phone', true ) === $mobile;
		}
		if ( $ok && $customer ) {
			$ok = false !== $wpdb->update(
				Schema::table( 'customers' ),
				array( 'full_name'=>$name, 'mobile'=>$mobile, 'email'=>$email, 'updated_at'=>current_time( 'mysql', true ) ),
				array( 'id'=>(int) $customer['id'] )
			);
		}
		$subject_type = $customer ? 'customer' : 'account';
		$subject_id = $customer ? (int) $customer['id'] : $user_id;
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			self::clean_account_cache( $user_id );
			return self::error( 'adc_identity_unavailable', 503 );
		}
		$committed = Transaction::commit( static fn() => AuditLog::record( 'customer.account_profile_updated', $subject_type, $subject_id, '', array( 'changed_fields'=>array() ), array( 'changed_fields'=>$fields ) ) );
		if ( ! $committed ) {
			self::clean_account_cache( $user_id );
			return self::error( 'adc_identity_unavailable', 503 );
		}
		self::clean_account_cache( $user_id );
		if ( $customer ) { do_action( 'adc_customer_profile_synced', $user_id, (int) $customer['id'] ); }
		return array( 'updated'=>true, 'linked_customer_id'=>(int) ( $customer['id'] ?? 0 ), 'fields'=>$fields );
	}

	/** Immediately refresh an existing account-linked customer; never creates or claims one. */
	public static function sync_account_profile( int $user_id ) {
		global $wpdb;
		if ( $user_id < 1 || ! Schema::is_ready() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$user = get_userdata( $user_id );
		if ( ! $user ) { return self::error( 'adc_identity_not_found', 404 ); }
		$mobile = ContactIdentity::normalize_mobile( (string) get_user_meta( $user_id, 'car_dealer_phone', true ), false );
		$name = sanitize_text_field( $user->display_name ); $email = strtolower( sanitize_email( $user->user_email ) );
		if ( is_wp_error( $mobile ) || '' === $name || ! is_email( $email ) ) { return self::error( 'adc_identity_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$table = Schema::table( 'customers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,merged_into_id,full_name,mobile,email FROM $table WHERE account_user_id=%d FOR UPDATE", $user_id ), ARRAY_A );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_unavailable', 503 ); }
		if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return array( 'linked'=>false ); }
		if ( $row['merged_into_id'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_changed', 409 ); }
		$next = array( 'full_name'=>$name, 'mobile'=>$mobile, 'email'=>$email ); $fields = array();
		foreach ( $next as $field=>$value ) { if ( (string) $row[$field] !== (string) $value ) { $fields[] = $field; } }
		if ( ! $fields ) { $wpdb->query( 'ROLLBACK' ); do_action( 'adc_customer_profile_synced', $user_id, (int) $row['id'] ); return array( 'linked'=>true, 'customer_id'=>(int) $row['id'], 'fields'=>array() ); }
		$next['updated_at'] = current_time( 'mysql', true );
		if ( false === $wpdb->update( $table, $next, array( 'id'=>(int) $row['id'] ) ) || ! Transaction::commit( static fn() => AuditLog::record( 'customer.profile_refreshed', 'customer', (int) $row['id'], 'WordPress account profile update', null, array( 'fields'=>$fields ) ) ) ) {
			return self::error( 'adc_identity_unavailable', 503 );
		}
		do_action( 'adc_customer_profile_synced', $user_id, (int) $row['id'] );
		return array( 'linked'=>true, 'customer_id'=>(int) $row['id'], 'fields'=>$fields );
	}

	private static function account_preference( int $user_id, bool $submitted ): array {
		if ( ! metadata_exists( 'user', $user_id, self::PREFERENCE_META ) ) { return array( $submitted, $submitted ? current_time( 'mysql', true ) : null, false ); }
		$enabled = '1' === (string) get_user_meta( $user_id, self::PREFERENCE_META, true );
		$at = $enabled ? (string) get_user_meta( $user_id, self::PREFERENCE_AT_META, true ) : null;
		if ( $enabled && ! preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $at ) ) { $at = current_time( 'mysql', true ); }
		return array( $enabled, $enabled ? $at : null, true );
	}

	private static function clean_account_cache( int $user_id ): void {
		clean_user_cache( $user_id );
		wp_cache_delete( $user_id, 'user_meta' );
	}

	/** Called only inside the intake transaction for its server-owned account identity. */
	public static function account_customer( array $identity ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return self::error( 'adc_identity_forbidden', 403 ); }
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->users ) );
		if ( 'InnoDB' !== $engine ) { return self::error( 'adc_identity_unavailable', 503 ); }
		// Serializes first-contact creation across concurrent forms for the same account.
		$user = $wpdb->get_row( $wpdb->prepare( "SELECT ID,display_name,user_email FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $user_id ), ARRAY_A );
		if ( $wpdb->last_error || ! $user ) { return self::error( 'adc_identity_unavailable', 503 ); }
		if ( sanitize_text_field( $user['display_name'] ) !== $identity['name'] || strtolower( $user['user_email'] ) !== $identity['email'] ) { return self::error( 'adc_identity_changed', 409 ); }
		$table = Schema::table( 'customers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE account_user_id=%d FOR UPDATE", $user_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$now = current_time( 'mysql', true );
		list( $consent, $consent_at, $has_preference ) = self::account_preference( $user_id, (bool) $identity['consent_marketing'] );
		if ( $row ) {
			if ( $row['merged_into_id'] ) { return self::error( 'adc_identity_changed', 409 ); }
			// Profile values are refreshed only by an authenticated submission; consent stays explicit.
			$changes = array( 'full_name'=>$identity['name'], 'mobile'=>$identity['mobile'], 'email'=>$identity['email'], 'updated_at'=>$now );
			if ( $has_preference ) { $changes['consent_marketing'] = $consent ? 1 : 0; $changes['consent_at'] = $consent_at; }
			elseif ( $consent ) { $changes['consent_marketing'] = 1; $changes['consent_at'] = $consent_at; }
			if ( false === $wpdb->update( $table, $changes, array( 'id'=>(int) $row['id'] ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
			$fields = array();
			foreach ( $changes as $field => $value ) { if ( 'updated_at' !== $field && (string) $row[$field] !== (string) $value ) { $fields[] = $field; } }
			if ( $fields && ! AuditLog::record( 'customer.profile_refreshed', 'customer', (int) $row['id'], 'Authenticated account submission', null, array( 'fields'=>$fields ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
			return (int) $row['id'];
		}
		if ( 1 !== $wpdb->insert( $table, array( 'account_user_id'=>$user_id, 'full_name'=>$identity['name'], 'mobile'=>$identity['mobile'], 'email'=>$identity['email'], 'city'=>$identity['city'], 'consent_marketing'=>$consent ? 1 : 0, 'consent_at'=>$consent_at, 'created_at'=>$now, 'updated_at'=>$now ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$id = (int) $wpdb->insert_id;
		if ( ! AuditLog::record( 'customer.account_linked', 'customer', $id, 'Authenticated account submission', null, array( 'account_user_id'=>$user_id ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
		return $id;
	}

	public static function candidates( int $source_id ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'adc_identity_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$table = Schema::table( 'customers' );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND merged_into_id IS NULL", $source_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
		if ( ! $source ) { return self::error( 'adc_identity_not_found', 404 ); }
		if ( '' === $source['email'] || '' === $source['mobile'] ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,full_name,email,mobile,account_user_id FROM $table WHERE id<>%d AND merged_into_id IS NULL AND email=%s AND mobile=%s ORDER BY id ASC LIMIT 50", $source_id, $source['email'], $source['mobile'] ), ARRAY_A );
		return $wpdb->last_error ? self::error( 'adc_identity_unavailable', 503 ) : $rows;
	}

	/** Preview and execution share every eligibility check; execution uses current locking reads. */
	public static function preview( int $source_id, int $target_id, bool $lock = false ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'adc_identity_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		if ( $source_id < 1 || $target_id < 1 || $source_id === $target_id ) { return self::error( 'adc_identity_invalid', 400 ); }
		$suffix = $lock ? ' FOR UPDATE' : '';
		$table = Schema::table( 'customers' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE id IN (%d,%d) ORDER BY id ASC$suffix", $source_id, $target_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
		if ( count( $rows ) !== 2 ) { return self::error( 'adc_identity_not_found', 404 ); }
		$map = array_column( $rows, null, 'id' ); $source = $map[$source_id]; $target = $map[$target_id];
		// Keep a linked account as the destination; never merge two authenticated accounts.
		if ( $source['merged_into_id'] || $target['merged_into_id'] || $source['account_user_id'] || '' === $source['email'] || '' === $source['mobile'] || strtolower( $source['email'] ) !== strtolower( $target['email'] ) || $source['mobile'] !== $target['mobile'] ) { return self::error( 'adc_identity_conflict', 409 ); }
		$children = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE merged_into_id=%d LIMIT 1$suffix", $source_id ) );
		if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
		if ( $children ) { return self::error( 'adc_identity_conflict', 409 ); }
		// Documents and financial references are immutable in this bounded CRM consolidation.
		foreach ( array( 'reservations','quotations','quotation_versions','sales' ) as $name ) {
			$reference = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( $name ) . " WHERE customer_id=%d LIMIT 1$suffix", $source_id ) );
			if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
			if ( $reference ) { return self::error( 'adc_identity_operational', 409 ); }
		}
		$leads = $wpdb->get_results( $wpdb->prepare( 'SELECT id,customer_id,branch_id,owner_user_id,stage,updated_at,legacy_request_type,legacy_request_id FROM ' . Schema::table( 'leads' ) . " WHERE customer_id IN (%d,%d) ORDER BY id ASC LIMIT 501$suffix", $source_id, $target_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_identity_unavailable', 503 ); }
		if ( count( $leads ) > 500 ) { return self::error( 'adc_identity_conflict', 409 ); }
		$scopes = array( $source_id=>array(), $target_id=>array() ); $move = array(); $owners = array(); $engines = array();
		foreach ( $leads as $lead ) {
			$scopes[(int) $lead['customer_id']][ $lead['branch_id'] . ':' . $lead['owner_user_id'] ] = true;
			if ( (int) $lead['customer_id'] === $source_id ) { $move[] = (int) $lead['id']; }
			if ( ! $lead['legacy_request_id'] ) { continue; }
			$type = $lead['legacy_request_type'];
			if ( ! in_array( $type, array( 'message','booking' ), true ) ) { return self::error( 'adc_identity_conflict', 409 ); }
			$legacy = $wpdb->prefix . 'car_dealer_' . ( 'booking' === $type ? 'bookings' : 'messages' );
			if ( ! isset( $engines[$type] ) ) { $engines[$type] = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $legacy ) ); }
			if ( 'InnoDB' !== $engines[$type] ) { return self::error( 'adc_identity_unavailable', 503 ); }
			$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $legacy WHERE id=%d$suffix", $lead['legacy_request_id'] ) );
			if ( $wpdb->last_error || null === $owner ) { return self::error( 'adc_identity_unavailable', 503 ); }
			if ( $owner && (int) $owner !== (int) $target['account_user_id'] ) { return self::error( 'adc_identity_conflict', 409 ); }
			$owners[] = array( $type, (int) $lead['legacy_request_id'], (int) $owner );
		}
		$a = array_keys( $scopes[$source_id] ); $b = array_keys( $scopes[$target_id] ); sort( $a ); sort( $b );
		if ( ! $move || $a !== $b ) { return self::error( 'adc_identity_scope', 409 ); }
		$payload = wp_json_encode( array( $rows, $leads, $owners ) );
		if ( false === $payload ) { return self::error( 'adc_identity_unavailable', 503 ); }
		return array( 'source'=>$source, 'target'=>$target, 'lead_ids'=>$move, 'revision'=>hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) );
	}

	public static function merge( int $source_id, int $target_id, string $revision, string $evidence, bool $verified ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'adc_identity_forbidden', 403 ); }
		$evidence = sanitize_text_field( $evidence );
		if ( ! $verified || '' === trim( $evidence ) || mb_strlen( $evidence ) > 120 || ! preg_match( '/\A[a-f0-9]{64}\z/', $revision ) ) { return self::error( 'adc_identity_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_identity_unavailable', 503 ); }
		$preview = self::preview( $source_id, $target_id, true );
		if ( is_wp_error( $preview ) ) { $wpdb->query( 'ROLLBACK' ); return $preview; }
		if ( ! hash_equals( $preview['revision'], $revision ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_stale', 409 ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( Schema::table( 'leads' ), array( 'customer_id'=>$target_id, 'updated_at'=>$now ), array( 'customer_id'=>$source_id ) );
		$consent = ! empty( $preview['source']['consent_marketing'] ) && ! empty( $preview['target']['consent_marketing'] );
		if ( count( $preview['lead_ids'] ) !== $updated || false === $wpdb->update( Schema::table( 'customers' ), array( 'consent_marketing'=>$consent ? 1 : 0, 'consent_at'=>$consent ? $preview['target']['consent_at'] : null, 'updated_at'=>$now ), array( 'id'=>$target_id ) ) || 1 !== $wpdb->update( Schema::table( 'customers' ), array( 'merged_into_id'=>$target_id, 'full_name'=>'Merged customer', 'mobile'=>'', 'email'=>'', 'city'=>'', 'consent_marketing'=>0, 'consent_at'=>null, 'updated_at'=>$now ), array( 'id'=>$source_id ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_unavailable', 503 ); }
		foreach ( $preview['lead_ids'] as $lead_id ) {
			if ( 1 !== $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>get_current_user_id(), 'type'=>'note', 'notes'=>"Customer profile consolidated: #$source_id -> #$target_id", 'created_at'=>$now ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_identity_unavailable', 503 ); }
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'customer.merged', 'customer', $target_id, $evidence, array( 'source_id'=>$source_id ), array( 'target_id'=>$target_id, 'lead_ids'=>$preview['lead_ids'], 'identity_reviewed'=>true ) ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
		return array( 'source_id'=>$source_id, 'target_id'=>$target_id, 'moved_leads'=>count( $preview['lead_ids'] ) );
	}

	private static function error( string $code, int $status ): \WP_Error {
		$messages = array( 'adc_identity_conflict'=>'الملفان غير متوافقين للدمج. أبقِ الملف المرتبط بالحساب وجهةً للدمج، وتحقق من تطابق البريد والجوال.', 'adc_identity_operational'=>'للملف المصدر حجوزات أو مستندات تشغيلية؛ لا يمكن دمجه بهذه الأداة.', 'adc_identity_scope'=>'يجب أن يتطابق نطاق الفروع والموظفين للملفين.', 'adc_identity_stale'=>'تغيرت البيانات. أعد المعاينة قبل الدمج.', 'adc_identity_invalid'=>'أكمل معاينة الملفين وتأكيد مراجعة الهوية ومرجع الإثبات.', 'adc_identity_not_found'=>'ملف العميل غير موجود.' );
		return new \WP_Error( $code, __( $messages[$code] ?? 'تعذر إتمام العملية. تحقق من الصلاحيات والبيانات وحاول مجددًا.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
