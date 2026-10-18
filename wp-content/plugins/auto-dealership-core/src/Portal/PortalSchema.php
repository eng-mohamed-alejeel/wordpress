<?php
namespace AutoDealership\Portal;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

final class PortalSchema {
	public static function definitions(string $collate): array {
		return array(
			'CREATE TABLE '.Schema::table('portal_favorites')." (
			user_id bigint(20) unsigned NOT NULL,
			car_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (user_id,car_id),
			KEY car_id (car_id)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_presence')." (
			user_id bigint(20) unsigned NOT NULL,
			session_key char(64) NOT NULL,
			thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
			available tinyint(1) NOT NULL DEFAULT 0,
			typing_until bigint(20) unsigned NOT NULL DEFAULT 0,
			typing_seq bigint(20) unsigned NOT NULL DEFAULT 0,
			seen_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (user_id,session_key),
			KEY available_seen (available,seen_at),
			KEY thread_seen (thread_id,seen_at)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_documents')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sale_id bigint(20) unsigned NOT NULL,
			kind varchar(16) NOT NULL,
			title varchar(190) NOT NULL,
			reference varchar(100) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'published',
			content_hash char(64) NOT NULL,
			file_bytes longblob NOT NULL,
			file_size int(10) unsigned NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sale_status (sale_id,status,id)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_threads')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			branch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			context_key char(64) NOT NULL,
			kind varchar(16) NOT NULL DEFAULT 'general',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			car_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'staff',
			last_public_id bigint(20) unsigned NOT NULL DEFAULT 0,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY context_key (context_key),
			KEY customer_updated (customer_id,updated_at),
			KEY owner_status (owner_id,status),
			KEY branch_owner (branch_id,owner_id,status,updated_at)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_messages')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sender_kind varchar(16) NOT NULL,
			sender_label varchar(120) NOT NULL,
			visibility varchar(12) NOT NULL DEFAULT 'public',
			body text NOT NULL,
			request_key char(64) NOT NULL,
			body_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_key (request_key),
			KEY thread_messages (thread_id,visibility,id),
			KEY sender_id (sender_id)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_reads')." (
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			last_id bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (thread_id,user_id)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_notifications')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(24) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			event_key char(64) NOT NULL,
			read_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_event (user_id,event_key),
			KEY user_unread (user_id,read_at,id)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_reviews')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			branch_id bigint(20) unsigned NOT NULL,
			context_key char(64) NOT NULL,
			thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
			current_version bigint(20) unsigned NOT NULL DEFAULT 0,
			public_version bigint(20) unsigned NOT NULL DEFAULT 0,
			consent tinyint(1) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'pending',
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY context_key (context_key),
			KEY customer_updated (user_id,updated_at),
			KEY branch_status (branch_id,status,id),
			KEY public_version (public_version)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_review_versions')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			review_id bigint(20) unsigned NOT NULL,
			rating tinyint(3) unsigned NOT NULL,
			display_name varchar(100) NOT NULL,
			body text NOT NULL,
			locale varchar(2) NOT NULL,
			content_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY review_versions (review_id,id)
			) $collate ENGINE=InnoDB",
			'CREATE TABLE '.Schema::table('portal_review_actions')." (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			review_id bigint(20) unsigned NOT NULL,
			version_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL,
			action varchar(16) NOT NULL,
			reason varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY review_actions (review_id,id)
			) $collate ENGINE=InnoDB"
		);
	}
}
