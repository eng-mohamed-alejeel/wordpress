<?php
namespace AutoDealership\Database;
defined( 'ABSPATH' ) || exit;

final class ExtensionSchema {
	public static function definitions( string $collate ): array {
		$orders = Schema::table( 'purchase_orders' );
		$documents = Schema::table( 'document_archive' );
		$evidence = Schema::table( 'release_evidence' );
		$affiliate_commissions = Schema::table( 'affiliate_commissions' );
		return array(
			"CREATE TABLE $orders (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_number varchar(64) NOT NULL,
				vehicle_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				supplier_id bigint(20) unsigned NOT NULL,
				requester_user_id bigint(20) unsigned NOT NULL,
				approver_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				receiver_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(24) NOT NULL DEFAULT 'draft',
				version int(10) unsigned NOT NULL DEFAULT 1,
				purchase_amount bigint(20) unsigned NOT NULL,
				additional_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_rate_bps int(10) unsigned NOT NULL DEFAULT 0,
				tax_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				total_amount bigint(20) unsigned NOT NULL,
				policy_snapshot longtext NULL,
				receipt_reference varchar(190) NOT NULL DEFAULT '',
				reason text NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_number (order_number),
				KEY branch_status (branch_id,status),
				KEY vehicle_status (vehicle_id,status)
			) $collate ENGINE=InnoDB;",
			"CREATE TABLE $documents (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				document_number varchar(64) NOT NULL,
				quotation_id bigint(20) unsigned NOT NULL,
				source_version int(10) unsigned NOT NULL,
				template_version varchar(24) NOT NULL,
				checksum char(64) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY document_number (document_number),
				UNIQUE KEY source_revision (quotation_id,source_version,template_version)
			) $collate ENGINE=InnoDB;",
			"CREATE TABLE $evidence (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				gate_key varchar(64) NOT NULL,
				environment_url varchar(255) NOT NULL,
				release_version varchar(32) NOT NULL,
				artifact_reference varchar(255) NOT NULL,
				artifact_hash char(64) NOT NULL,
				submitter_user_id bigint(20) unsigned NOT NULL,
				reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(24) NOT NULL DEFAULT 'pending',
				valid_until date NOT NULL,
				reason text NOT NULL,
				created_at datetime NOT NULL,
				reviewed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY gate_environment (gate_key,status,valid_until)
			) $collate ENGINE=InnoDB;",
			"CREATE TABLE $affiliate_commissions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sale_id bigint(20) unsigned NOT NULL,
				quotation_id bigint(20) unsigned NOT NULL,
				lead_id bigint(20) unsigned NOT NULL,
				affiliate_user_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				amount bigint(20) unsigned NOT NULL,
				currency_code char(3) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'pending_review',
				payment_reference varchar(190) NOT NULL DEFAULT '',
				payment_reference_hash char(64) NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NULL,
				paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sale_id (sale_id),
				KEY affiliate_status (affiliate_user_id,status),
				KEY affiliate_created (affiliate_user_id,created_at),
				KEY branch_created (branch_id,created_at),
				KEY currency_status_created (currency_code,status,created_at),
				KEY status_created (status,created_at),
				KEY branch_status (branch_id,status),
				KEY report_created_status_branch (created_at,status,branch_id),
				KEY report_affiliate_created_status (affiliate_user_id,created_at,status),
				KEY report_branch_created_status (branch_id,created_at,status),
				UNIQUE KEY payment_reference_hash (payment_reference_hash)
			) $collate ENGINE=InnoDB;"
			,
			"CREATE TABLE " . Schema::table( 'affiliate_commission_entries' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				commission_id bigint(20) unsigned NOT NULL,
				sale_id bigint(20) unsigned NOT NULL,
				affiliate_user_id bigint(20) unsigned NOT NULL,
				branch_id bigint(20) unsigned NOT NULL,
				entry_type varchar(32) NOT NULL,
				amount bigint(20) NOT NULL,
				currency_code char(3) NOT NULL DEFAULT '',
				event_key char(64) NOT NULL,
				reverses_entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entry_reference varchar(40) NOT NULL DEFAULT '',
				fingerprint char(64) NULL,
				reason text NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY commission_created (commission_id,created_at),
				KEY reversal_source (reverses_entry_id),
				KEY affiliate_created (affiliate_user_id,created_at),
				KEY type_created (entry_type,created_at),
				KEY entry_reference (entry_reference),
				KEY fingerprint (fingerprint)
			) $collate ENGINE=InnoDB;"
			,
			"CREATE TABLE " . Schema::table( 'affiliate_commission_migration_issues' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				commission_id bigint(20) unsigned NOT NULL,
				issue_type varchar(40) NOT NULL,
				reason text NOT NULL,
				snapshot longtext NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'open',
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				resolved_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY commission_issue (commission_id,issue_type),
				KEY issue_status_created (status,created_at),
				KEY issue_commission (commission_id,status),
				KEY issue_type_status (issue_type,status,id)
			) $collate ENGINE=InnoDB;"
		);
	}
}
