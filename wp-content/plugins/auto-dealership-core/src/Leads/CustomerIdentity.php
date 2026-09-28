<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Account authentication and reviewed CRM consolidation; contact equality grants no access. */
final class CustomerIdentity {
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
		if ( $row ) {
			if ( $row['merged_into_id'] ) { return self::error( 'adc_identity_changed', 409 ); }
			// Profile values are refreshed only by an authenticated submission; consent stays explicit.
			$changes = array( 'full_name'=>$identity['name'], 'mobile'=>$identity['mobile'], 'email'=>$identity['email'], 'updated_at'=>$now );
			if ( $identity['consent_marketing'] ) { $changes['consent_marketing'] = 1; $changes['consent_at'] = $now; }
			if ( false === $wpdb->update( $table, $changes, array( 'id'=>(int) $row['id'] ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
			$fields = array();
			foreach ( $changes as $field => $value ) { if ( 'updated_at' !== $field && (string) $row[$field] !== (string) $value ) { $fields[] = $field; } }
			if ( $fields && ! AuditLog::record( 'customer.profile_refreshed', 'customer', (int) $row['id'], 'Authenticated account submission', null, array( 'fields'=>$fields ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
			return (int) $row['id'];
		}
		if ( 1 !== $wpdb->insert( $table, array( 'account_user_id'=>$user_id, 'full_name'=>$identity['name'], 'mobile'=>$identity['mobile'], 'email'=>$identity['email'], 'city'=>$identity['city'], 'consent_marketing'=>$identity['consent_marketing'] ? 1 : 0, 'consent_at'=>$identity['consent_marketing'] ? $now : null, 'created_at'=>$now, 'updated_at'=>$now ) ) ) { return self::error( 'adc_identity_unavailable', 503 ); }
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
