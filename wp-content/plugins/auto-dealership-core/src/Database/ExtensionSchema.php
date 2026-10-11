<?php
namespace AutoDealership\Database;
defined( 'ABSPATH' ) || exit;

final class ExtensionSchema {
	public static function definitions( string $collate ): array {
		$orders = Schema::table( 'purchase_orders' );
		$documents = Schema::table( 'document_archive' );
		$evidence = Schema::table( 'release_evidence' );
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
			) $collate ENGINE=InnoDB;"
		);
	}
}
