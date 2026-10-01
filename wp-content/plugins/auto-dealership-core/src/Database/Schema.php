<?php
namespace AutoDealership\Database;

defined( 'ABSPATH' ) || exit;

/** Installs versioned operational tables using WordPress dbDelta. */
final class Schema {
	public const VERSION = '1.15.0';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'adc_' . $name;
	}

	/** Canonical DDL is also the verification contract. */
	public static function definitions(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$tables  = array(
			"CREATE TABLE " . self::table( 'brands' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				brand_key varchar(64) NOT NULL,
				name_ar varchar(120) NOT NULL,
				name_en varchar(120) NOT NULL DEFAULT '',
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY brand_key (brand_key),
				KEY active_name (active,name_ar)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'branches' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(32) NOT NULL,
				name varchar(190) NOT NULL,
				city varchar(100) NOT NULL DEFAULT '',
				address text NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY active_city (active,city)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'locations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				branch_id bigint(20) unsigned NOT NULL,
				code varchar(64) NOT NULL,
				name varchar(190) NOT NULL,
				location_type varchar(24) NOT NULL DEFAULT 'showroom',
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY branch_active (branch_id,active)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicles' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vin varchar(32) NOT NULL,
				stock_number varchar(64) NOT NULL,
				brand varchar(100) NOT NULL,
				brand_id bigint(20) unsigned NOT NULL DEFAULT 0,
				model varchar(120) NOT NULL,
				trim_name varchar(120) NOT NULL DEFAULT '',
				model_year smallint(5) unsigned NOT NULL,
				condition_key varchar(16) NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'received',
				body_type varchar(40) NOT NULL DEFAULT '',
				fuel_type varchar(40) NOT NULL DEFAULT '',
				transmission varchar(40) NOT NULL DEFAULT '',
				exterior_color varchar(80) NOT NULL DEFAULT '',
				interior_color varchar(80) NOT NULL DEFAULT '',
				engine_size varchar(40) NOT NULL DEFAULT '',
				drivetrain varchar(8) NOT NULL DEFAULT '',
				doors tinyint(3) unsigned NULL,
				seats tinyint(3) unsigned NULL,
				horsepower smallint(5) unsigned NULL,
				warranty text NULL,
				interior_features text NULL,
				exterior_features text NULL,
				safety_features text NULL,
				mileage int(10) unsigned NOT NULL DEFAULT 0,
				retail_price bigint(20) unsigned NOT NULL DEFAULT 0,
				minimum_price bigint(20) unsigned NULL,
				purchase_cost bigint(20) unsigned NULL,
				currency char(3) NOT NULL DEFAULT 'SAR',
				public_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY vin (vin),
				UNIQUE KEY stock_number (stock_number),
				KEY branch_status (branch_id,status),
				KEY brand_id (brand_id),
				KEY location_id (location_id),
				KEY catalog (status,brand,model_year),
				KEY public_post_id (public_post_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicle_movements' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vehicle_id bigint(20) unsigned NOT NULL,
				from_branch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				to_branch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				from_location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				to_location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				from_status varchar(32) NOT NULL,
				to_status varchar(32) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reason text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY vehicle_date (vehicle_id,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicle_receipts' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vehicle_id bigint(20) unsigned NOT NULL,
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				received_by bigint(20) unsigned NOT NULL,
				odometer int(10) unsigned NOT NULL DEFAULT 0,
				condition_key varchar(24) NOT NULL,
				document_reference varchar(100) NOT NULL DEFAULT '',
				notes text NOT NULL,
				evidence_media_ids text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY vehicle_id (vehicle_id),
				KEY location_date (location_id,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicle_inspections' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vehicle_id bigint(20) unsigned NOT NULL,
				inspector_user_id bigint(20) unsigned NOT NULL,
				status varchar(16) NOT NULL,
				checklist longtext NOT NULL,
				notes text NOT NULL,
				evidence_media_ids text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY vehicle_date (vehicle_id,created_at),
				KEY status_date (status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicle_issues' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vehicle_id bigint(20) unsigned NOT NULL,
				inspection_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cancellation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				inspection_baseline_id bigint(20) unsigned NOT NULL DEFAULT 0,
				issue_type varchar(20) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'open',
				reason text NOT NULL,
				assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				review_at datetime NULL,
				resolution text NOT NULL,
				opened_by bigint(20) unsigned NOT NULL,
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				resolved_at datetime NULL,
				PRIMARY KEY  (id),
				KEY vehicle_status (vehicle_id,status),
				KEY assigned_review (assigned_user_id,status,review_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicle_returns' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sale_id bigint(20) unsigned NOT NULL,
				delivery_id bigint(20) unsigned NOT NULL,
				vehicle_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				inspection_baseline_id bigint(20) unsigned NOT NULL DEFAULT 0,
				condition_key varchar(24) NOT NULL,
				odometer int(10) unsigned NOT NULL DEFAULT 0,
				document_reference varchar(100) NOT NULL DEFAULT '',
				reason text NOT NULL,
				financial_status varchar(24) NOT NULL DEFAULT 'pending_refund',
				received_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sale_id (sale_id),
				UNIQUE KEY delivery_id (delivery_id),
				KEY vehicle_date (vehicle_id,created_at),
				KEY branch_financial (branch_id,financial_status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'vehicle_transfers' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vehicle_id bigint(20) unsigned NOT NULL,
				from_branch_id bigint(20) unsigned NOT NULL,
				to_branch_id bigint(20) unsigned NOT NULL,
				requested_by bigint(20) unsigned NOT NULL,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				dispatched_by bigint(20) unsigned NOT NULL DEFAULT 0,
				received_by bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(24) NOT NULL DEFAULT 'requested',
				reason text NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY vehicle_status (vehicle_id,status),
				KEY source_queue (from_branch_id,status,created_at),
				KEY destination_queue (to_branch_id,status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'customers' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				account_user_id bigint(20) unsigned NULL,
				merged_into_id bigint(20) unsigned NULL,
				full_name varchar(190) NOT NULL,
				mobile varchar(32) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				consent_marketing tinyint(1) NOT NULL DEFAULT 0,
				consent_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY mobile (mobile),
				UNIQUE KEY account_user_id (account_user_id),
				KEY merged_into_id (merged_into_id),
				KEY email (email)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'leads' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(40) NOT NULL DEFAULT 'website',
				stage varchar(32) NOT NULL DEFAULT 'new',
				lost_reason text NOT NULL,
				next_action_at datetime NULL,
				legacy_request_type varchar(16) NULL,
				legacy_request_id bigint(20) unsigned NULL,
				public_request_key char(64) NULL,
				public_payload_hash char(64) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY customer_id (customer_id),
				UNIQUE KEY legacy_request (legacy_request_type,legacy_request_id),
				UNIQUE KEY public_request_key (public_request_key),
				KEY pipeline (branch_id,stage,owner_user_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'activities' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(32) NOT NULL,
				notes text NOT NULL,
				next_action_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY lead_date (lead_id,created_at),
				KEY next_action_at (next_action_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'reservations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vehicle_id bigint(20) unsigned NOT NULL,
				customer_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				owner_user_id bigint(20) unsigned NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'confirmed',
				deposit_policy varchar(100) NOT NULL DEFAULT 'none',
				deposit_required_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				deposit_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				deposit_refund_status varchar(24) NOT NULL DEFAULT 'no_refund_due',
				payment_reference varchar(100) NOT NULL DEFAULT '',
				expires_at datetime NOT NULL,
				idempotency_key char(36) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY vehicle_status (vehicle_id,status),
				KEY expiry (status,expires_at),
				KEY customer_id (customer_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'quotations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quote_number varchar(40) NOT NULL,
				customer_id bigint(20) unsigned NOT NULL,
				vehicle_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_user_id bigint(20) unsigned NOT NULL,
				base_amount bigint(20) unsigned NOT NULL,
				fee_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				promotion_code varchar(64) NOT NULL DEFAULT '',
				promotion_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				subtotal_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_rate_bps smallint(5) unsigned NULL,
				tax_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				final_amount bigint(20) unsigned NOT NULL,
				valid_until date NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'draft',
				version int(10) unsigned NOT NULL DEFAULT 1,
				seller_name varchar(190) NOT NULL DEFAULT '',
				seller_tax_number varchar(60) NOT NULL DEFAULT '',
				seller_address text NOT NULL,
				seller_phone varchar(60) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY quote_number (quote_number),
				KEY customer_id (customer_id),
				KEY vehicle_id (vehicle_id),
				KEY branch_id (branch_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'quotation_versions' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quotation_id bigint(20) unsigned NOT NULL,
				quote_number varchar(40) NOT NULL,
				customer_id bigint(20) unsigned NOT NULL,
				vehicle_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_user_id bigint(20) unsigned NOT NULL,
				customer_name varchar(190) NOT NULL DEFAULT '',
				vehicle_stock_number varchar(80) NOT NULL DEFAULT '',
				vehicle_description varchar(255) NOT NULL DEFAULT '',
				version int(10) unsigned NOT NULL,
				base_amount bigint(20) unsigned NOT NULL,
				fee_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				promotion_code varchar(64) NOT NULL DEFAULT '',
				promotion_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_amount bigint(20) unsigned NOT NULL,
				subtotal_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_rate_bps smallint(5) unsigned NULL,
				tax_amount bigint(20) unsigned NOT NULL,
				final_amount bigint(20) unsigned NOT NULL,
				currency char(3) NOT NULL DEFAULT 'SAR',
				valid_until date NOT NULL,
				status varchar(24) NOT NULL,
				seller_name varchar(190) NOT NULL DEFAULT '',
				seller_tax_number varchar(60) NOT NULL DEFAULT '',
				seller_address text NOT NULL,
				seller_phone varchar(60) NOT NULL DEFAULT '',
				event_key varchar(40) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reason text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY quotation_version (quotation_id,version),
				KEY customer_id (customer_id),
				KEY branch_id (branch_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'discount_requests' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quotation_id bigint(20) unsigned NOT NULL,
				requester_user_id bigint(20) unsigned NOT NULL,
				approver_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				requested_amount bigint(20) unsigned NOT NULL,
				approval_tier varchar(24) NOT NULL DEFAULT '',
				margin_before bigint(20) NULL,
				margin_after bigint(20) NULL,
				reason text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				decided_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY quotation_id (quotation_id),
				KEY approval_queue (status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'sales' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quotation_id bigint(20) unsigned NOT NULL,
				reservation_id bigint(20) unsigned NOT NULL,
				customer_id bigint(20) unsigned NOT NULL,
				vehicle_id bigint(20) unsigned NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'pending_approval',
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				invoice_reference varchar(100) NOT NULL DEFAULT '',
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_at),
				KEY vehicle_id (vehicle_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'delivery_documents' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				delivery_id bigint(20) unsigned NOT NULL,
				document_key varchar(40) NOT NULL,
				reference varchar(190) NOT NULL,
				confirmed_by bigint(20) unsigned NOT NULL,
				confirmed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY delivery_document (delivery_id,document_key),
				KEY confirmed_at (confirmed_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'finance_requests' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sale_id bigint(20) unsigned NOT NULL,
				provider varchar(100) NOT NULL DEFAULT '',
				requested_amount bigint(20) unsigned NOT NULL,
				requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(24) NOT NULL DEFAULT 'submitted',
				provider_reference varchar(100) NOT NULL DEFAULT '',
				consent_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY sale_id (sale_id),
				KEY status_created (status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'payment_confirmations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sale_id bigint(20) unsigned NOT NULL,
				amount bigint(20) unsigned NOT NULL,
				currency char(3) NOT NULL DEFAULT 'SAR',
				source varchar(32) NOT NULL,
				reference varchar(100) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				recorded_by bigint(20) unsigned NOT NULL,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decision_reason text NOT NULL,
				created_at datetime NOT NULL,
				decided_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_reference (source,reference),
				KEY sale_status (sale_id,status)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'reservation_deposits' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				reservation_id bigint(20) unsigned NOT NULL,
				amount bigint(20) unsigned NOT NULL,
				currency char(3) NOT NULL DEFAULT 'SAR',
				source varchar(32) NOT NULL,
				reference varchar(100) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				recorded_by bigint(20) unsigned NOT NULL,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decision_reason text NOT NULL,
				created_at datetime NOT NULL,
				decided_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY reservation_id (reservation_id),
				UNIQUE KEY source_reference (source,reference),
				KEY status_created (status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'payment_refunds' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				return_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cancellation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sale_id bigint(20) unsigned NOT NULL,
				amount bigint(20) unsigned NOT NULL,
				currency char(3) NOT NULL DEFAULT 'SAR',
				method varchar(32) NOT NULL,
				reference varchar(100) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				requested_by bigint(20) unsigned NOT NULL,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decision_reason text NOT NULL,
				created_at datetime NOT NULL,
				decided_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY method_reference (method,reference),
				KEY return_status (return_id,status),
				KEY cancellation_status (cancellation_id,status),
				KEY reservation_status (reservation_id,status),
				KEY sale_status (sale_id,status)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'sale_cancellations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sale_id bigint(20) unsigned NOT NULL,
				reservation_id bigint(20) unsigned NOT NULL,
				delivery_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vehicle_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				previous_sale_status varchar(32) NOT NULL,
				verified_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				financial_status varchar(24) NOT NULL DEFAULT 'no_refund_due',
				reason text NOT NULL,
				cancelled_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sale_id (sale_id),
				KEY branch_financial (branch_id,financial_status,created_at)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'deliveries' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sale_id bigint(20) unsigned NOT NULL,
				vehicle_id bigint(20) unsigned NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'preparing',
				vin_confirmed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				delivered_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sale_id (sale_id),
				KEY vehicle_id (vehicle_id)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'outbox' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_key varchar(100) NOT NULL,
				idempotency_key char(64) NULL,
				payload longtext NOT NULL,
				payload_hash char(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime NOT NULL,
				locked_at datetime NULL,
				lock_token char(36) NULL,
				completed_at datetime NULL,
				failed_at datetime NULL,
				last_error varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY pending (completed_at,next_attempt_at),
				KEY queue (status,next_attempt_at,id),
				KEY lock_token (lock_token)
			) $collate ENGINE=InnoDB",
			"CREATE TABLE " . self::table( 'integration_receipts' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				outbox_id bigint(20) unsigned NULL,
				adapter_id varchar(64) NOT NULL,
				event_key varchar(100) NOT NULL,
				remote_reference varchar(190) NOT NULL,
				reference_hash char(64) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				acknowledged_at datetime NULL,
				last_checked_at datetime NULL,
				last_error varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY outbox_id (outbox_id),
				UNIQUE KEY adapter_reference (adapter_id,reference_hash),
				KEY status_updated (status,updated_at),
				KEY event_status (event_key,status)
			) $collate ENGINE=InnoDB",
		);

		$tables[] = \AutoDealership\Audit\AuditLog::definition();
		return $tables;
	}

	/** Empty means that every declared table, column and index matches its contract. */
	public static function verify(): array {
		return SchemaInspector::verify( self::definitions() );
	}

	public static function is_ready(): bool {
		return self::VERSION === get_option( 'adc_db_version' ) && ! get_option( 'adc_schema_issues', array() );
	}

	public static function install(): void {
		global $wpdb;
		$lock = 'adc_schema_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( self::definitions() as $sql ) {
				dbDelta( $sql );
			}
			$issues = self::verify();
			if ( ! $issues && ! \AutoDealership\Pricing\QuoteHistory::backfill() ) {
				$issues[] = 'quotation_versions:backfill_failed';
			}
			if ( $issues ) {
				update_option( 'adc_schema_issues', $issues, false );
				set_transient( 'adc_schema_retry_pending', 1, 300 );
				// Never leave a previously successful version marker on a failed repair.
				delete_option( 'adc_db_version' );
				error_log( 'Auto Dealership Core: schema verification failed; review administrator diagnostics.' );
				return;
			}
			delete_option( 'adc_schema_issues' );
			delete_transient( 'adc_schema_retry_pending' );
			update_option( 'adc_db_version', self::VERSION, false );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
