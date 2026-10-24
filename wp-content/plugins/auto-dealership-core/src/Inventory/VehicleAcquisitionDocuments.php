<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Private acquisition evidence stored outside the public WordPress media library. */
final class VehicleAcquisitionDocuments {
	private const MAX_BYTES = 20971520;
	private const TYPES = array(
		'application/pdf' => 'pdf',
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
	);

	public static function all( int $vehicle_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_vehicle_costs' ) && ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		array_unshift( $args, $vehicle_id );
		$sql = 'SELECT d.id,d.file_name,d.mime_type,OCTET_LENGTH(d.file_bytes) file_size,d.created_at FROM ' . Schema::table( 'vehicle_acquisition_documents' ) . ' d INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=d.vehicle_id AND v.branch_id=d.branch_id WHERE d.vehicle_id=%d AND ' . $scope . ' ORDER BY d.id DESC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_acquisition_document_list_unavailable', 500 ); }
		return array_map(
			static function ( array $row ): array {
				return array( 'id'=>(int) $row['id'], 'file_name'=>$row['file_name'], 'mime_type'=>$row['mime_type'], 'file_size'=>(int) $row['file_size'], 'created_at'=>$row['created_at'] );
			},
			$rows ?: array()
		);
	}

	public static function store( int $vehicle_id, string $file_name, string $bytes ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$vehicle = VehicleAcquisitionService::get( $vehicle_id );
		if ( is_wp_error( $vehicle ) ) { return $vehicle; }
		$file_name = sanitize_file_name( $file_name );
		if ( '' === $file_name || strlen( $file_name ) > 255 || strlen( $bytes ) < 8 || strlen( $bytes ) > self::MAX_BYTES ) {
			return self::error( 'adc_acquisition_document_invalid', 400 );
		}
		$mime = self::mime_type( $bytes );
		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		$valid_extensions = 'image/jpeg' === $mime ? array( 'jpg','jpeg' ) : ( $mime && isset( self::TYPES[$mime] ) ? array( self::TYPES[$mime] ) : array() );
		if ( ! $mime || ! in_array( $extension, $valid_extensions, true ) ) {
			return self::error( 'adc_acquisition_document_invalid', 400 );
		}
		if ( ! Transaction::begin() ) { return self::error( 'adc_acquisition_document_storage', 500 ); }
		$vehicle_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d AND branch_id=%d FOR UPDATE', $vehicle_id, $vehicle['branch_id'] ) );
		if ( null === $vehicle_lock || $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_vehicle_not_found', 404 );
		}
		$document_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_acquisition_documents' ) . ' WHERE vehicle_id=%d', $vehicle_id ) );
		if ( $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_acquisition_document_storage', 500 );
		}
		if ( $document_count >= 20 ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_acquisition_document_limit', 409 );
		}
		$table = Schema::table( 'vehicle_acquisition_documents' );
		$checksum = hash( 'sha256', $bytes );
		$inserted = $wpdb->insert(
			$table,
			array( 'vehicle_id'=>$vehicle_id, 'branch_id'=>$vehicle['branch_id'], 'source_attachment_id'=>0, 'file_name'=>$file_name, 'mime_type'=>$mime, 'checksum'=>$checksum, 'file_bytes'=>$bytes, 'uploaded_by'=>get_current_user_id(), 'created_at'=>current_time( 'mysql', true ) ),
			array( '%d','%d','%d','%s','%s','%s','%s','%d','%s' )
		);
		$id = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.acquisition_document_added', 'vehicle', $vehicle_id, 'Private acquisition evidence uploaded', null, array( 'document_id'=>$id, 'mime_type'=>$mime, 'checksum'=>$checksum ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_acquisition_document_storage', 500 );
		}
		return array( 'id'=>$id, 'file_name'=>$file_name, 'mime_type'=>$mime );
	}

	public static function migrate_legacy( int $vehicle_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$vehicle = VehicleAcquisitionService::get( $vehicle_id );
		if ( is_wp_error( $vehicle ) ) { return $vehicle; }
		$table = Schema::table( 'vehicles' );
		$legacy_ids = self::decode_attachment_ids( $wpdb->get_var( $wpdb->prepare( "SELECT document_media_ids FROM $table WHERE id=%d AND branch_id=%d", $vehicle_id, $vehicle['branch_id'] ) ) );
		if ( $wpdb->last_error ) { return self::error( 'adc_acquisition_document_storage', 500 ); }
		if ( ! $legacy_ids ) { return array( 'id'=>$vehicle_id, 'migrated'=>0 ); }

		$files = array();
		foreach ( $legacy_ids as $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			$upload_dir = wp_upload_dir();
			$real_path = is_string( $path ) ? realpath( $path ) : false;
			$real_upload_dir = realpath( (string) ( $upload_dir['basedir'] ?? '' ) );
			$upload_prefix = is_string( $real_upload_dir ) ? strtolower( rtrim( $real_upload_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) : '';
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) || ! is_string( $real_path ) || '' === $upload_prefix || ! str_starts_with( strtolower( $real_path ), $upload_prefix ) || ! is_file( $real_path ) ) {
				return self::error( 'adc_acquisition_document_legacy_unavailable', 409 );
			}
			$size = filesize( $real_path );
			$bytes = false === $size || $size < 8 || $size > self::MAX_BYTES ? false : file_get_contents( $real_path );
			$name = wp_basename( $real_path );
			$mime = is_string( $bytes ) ? self::validated_mime( $name, $bytes ) : '';
			if ( ! $mime ) { return self::error( 'adc_acquisition_document_legacy_invalid', 409 ); }
			$files[] = array( 'source_attachment_id'=>$attachment_id, 'file_name'=>$name, 'mime_type'=>$mime, 'checksum'=>hash( 'sha256', $bytes ), 'file_bytes'=>$bytes );
		}

		if ( ! Transaction::begin() ) { return self::error( 'adc_acquisition_document_storage', 500 ); }
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT branch_id,document_media_ids FROM $table WHERE id=%d FOR UPDATE", $vehicle_id ), ARRAY_A );
		if ( ! $current || (int) $current['branch_id'] !== (int) $vehicle['branch_id'] || self::decode_attachment_ids( $current['document_media_ids'] ) !== $legacy_ids ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_acquisition_document_legacy_changed', 409 );
		}
		$document_table = Schema::table( 'vehicle_acquisition_documents' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $document_table WHERE vehicle_id=%d", $vehicle_id ) );
		$storage_error = (bool) $wpdb->last_error;
		if ( $storage_error || $count + count( $files ) > 20 ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( $storage_error ? 'adc_acquisition_document_storage' : 'adc_acquisition_document_limit', $storage_error ? 500 : 409 );
		}
		$new_ids = array();
		foreach ( $files as $file ) {
			$inserted = $wpdb->insert(
				$document_table,
				array_merge( array( 'vehicle_id'=>$vehicle_id, 'branch_id'=>$vehicle['branch_id'], 'uploaded_by'=>get_current_user_id(), 'created_at'=>current_time( 'mysql', true ) ), $file ),
				array( '%d','%d','%d','%s','%d','%s','%s','%s','%s' )
			);
			if ( 1 !== $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'adc_acquisition_document_storage', 500 );
			}
			$new_ids[] = (int) $wpdb->insert_id;
		}
		$updated = $wpdb->update( $table, array( 'document_media_ids'=>'[]', 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>$vehicle_id ), array( '%s','%s' ), array( '%d' ) );
		if ( 1 !== $updated || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.acquisition_documents_migrated', 'vehicle', $vehicle_id, 'Legacy evidence copied to private storage; public media originals retained for separate review', null, array( 'migrated_count'=>count( $new_ids ), 'private_document_ids'=>$new_ids, 'source_checksums'=>array_column( $files, 'checksum' ) ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_acquisition_document_storage', 500 );
		}
		return array( 'id'=>$vehicle_id, 'migrated'=>count( $new_ids ), 'private_document_ids'=>$new_ids );
	}

	public static function legacy_public_original_count( int $vehicle_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_vehicle_costs' ) && ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		array_unshift( $args, $vehicle_id );
		$sql = 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_acquisition_documents' ) . ' d INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=d.vehicle_id AND v.branch_id=d.branch_id WHERE d.vehicle_id=%d AND d.source_attachment_id>0 AND ' . $scope;
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return $wpdb->last_error ? self::error( 'adc_acquisition_document_list_unavailable', 500 ) : (int) $count;
	}

	public static function retrieve( int $document_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_vehicle_costs' ) && ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,vehicle_id,branch_id,file_name,mime_type,checksum,file_bytes FROM ' . Schema::table( 'vehicle_acquisition_documents' ) . ' WHERE id=%d', $document_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_acquisition_document_unavailable', 500 ); }
		if ( ! $row || ! BranchScope::allows( (int) $row['branch_id'] ) ) {
			return self::error( 'adc_acquisition_document_not_found', 404 );
		}
		$vehicle = VehicleAcquisitionService::get( (int) $row['vehicle_id'] );
		if ( is_wp_error( $vehicle ) || (int) $vehicle['branch_id'] !== (int) $row['branch_id'] ) { return self::error( 'adc_acquisition_document_not_found', 404 ); }
		if ( ! hash_equals( $row['checksum'], hash( 'sha256', $row['file_bytes'] ) ) || ! isset( self::TYPES[$row['mime_type']] ) ) {
			return self::error( 'adc_acquisition_document_unavailable', 500 );
		}
		$row['id'] = (int) $row['id'];
		$row['vehicle_id'] = (int) $row['vehicle_id'];
		$row['file_size'] = strlen( $row['file_bytes'] );
		return $row;
	}

	public static function download(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'adc_download_acquisition_document_' . $id );
		$document = self::retrieve( $id );
		if ( is_wp_error( $document ) ) {
			wp_die( esc_html( $document->get_error_message() ), '', array( 'response'=>(int) ( $document->get_error_data()['status'] ?? 404 ) ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $document['mime_type'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="acquisition-' . $id . '.' . self::TYPES[$document['mime_type']] . '"' );
		header( 'Content-Length: ' . $document['file_size'] );
		echo $document['file_bytes'];
		exit;
	}

	private static function mime_type( string $bytes ): string {
		if ( str_starts_with( $bytes, '%PDF-' ) ) { return 'application/pdf'; }
		$image = getimagesizefromstring( $bytes );
		return is_array( $image ) && isset( self::TYPES[$image['mime'] ?? ''] ) ? $image['mime'] : '';
	}

	private static function validated_mime( string $file_name, string $bytes ): string {
		$mime = self::mime_type( $bytes );
		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		$valid_extensions = 'image/jpeg' === $mime ? array( 'jpg','jpeg' ) : ( $mime && isset( self::TYPES[$mime] ) ? array( self::TYPES[$mime] ) : array() );
		return $mime && in_array( $extension, $valid_extensions, true ) ? $mime : '';
	}

	private static function decode_attachment_ids( $value ): array {
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) { return array(); }
		$ids = array();
		foreach ( $decoded as $id ) {
			if ( ( ! is_int( $id ) && ( ! is_string( $id ) || ! ctype_digit( $id ) ) ) || (int) $id < 1 ) { continue; }
			$ids[(int) $id] = (int) $id;
		}
		return array_values( $ids );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'The private acquisition document could not be processed.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
