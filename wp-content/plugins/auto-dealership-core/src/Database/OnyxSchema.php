<?php
namespace AutoDealership\Database;
defined( 'ABSPATH' ) || exit;
final class OnyxSchema {
	public static function definitions( string $collate ): array {
		$imports=Schema::table('onyx_imports');$stock=Schema::table('onyx_stock');$models=Schema::table('catalog_models');
		return array(
			"CREATE TABLE ".Schema::table('supplier_name_keys')." (
				name_key char(64) NOT NULL,
				supplier_id bigint(20) unsigned NULL,
				PRIMARY KEY  (name_key),
				KEY supplier_id (supplier_id)
			) $collate ENGINE=InnoDB;",
			"CREATE TABLE $imports (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_key char(36) NOT NULL,
				file_hash char(64) NOT NULL,
				file_name varchar(190) NOT NULL,
				import_mode varchar(16) NOT NULL,
				baseline_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'preview',
				rows_json longtext NOT NULL,
				summary_json longtext NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				applied_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_key (request_key),
				KEY status_created (status,created_at)
			) $collate ENGINE=InnoDB;",
			"CREATE TABLE $models (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				model_key char(64) NOT NULL,
				brand varchar(100) NOT NULL,
				model varchar(120) NOT NULL,
				trim_name varchar(120) NOT NULL DEFAULT '',
				model_year smallint(5) unsigned NULL,
				public_post_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY model_key (model_key),
				UNIQUE KEY public_post_id (public_post_id)
			) $collate ENGINE=InnoDB;",
			"CREATE TABLE $stock (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_identifier varchar(32) NOT NULL,
				model_id bigint(20) unsigned NOT NULL,
				vehicle_id bigint(20) unsigned NULL,
				brand varchar(100) NOT NULL,
				model varchar(120) NOT NULL,
				trim_name varchar(120) NOT NULL DEFAULT '',
				model_year smallint(5) unsigned NOT NULL,
				exterior_color varchar(80) NOT NULL DEFAULT '',
				interior_color varchar(80) NOT NULL DEFAULT '',
				listed_price bigint(20) unsigned NOT NULL DEFAULT 0,
				supplier_name varchar(190) NOT NULL DEFAULT '',
				supplier_id bigint(20) unsigned NULL,
				warehouse_name varchar(190) NOT NULL DEFAULT '',
				notes text NOT NULL,
				stock_age int(10) unsigned NULL,
				present tinyint(1) NOT NULL DEFAULT 1,
				last_import_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_identifier (source_identifier),
				UNIQUE KEY vehicle_id (vehicle_id),
				KEY model_present (model_id,present),
				KEY supplier_id (supplier_id),
				KEY last_import_id (last_import_id)
			) $collate ENGINE=InnoDB;"
		);
	}
}
