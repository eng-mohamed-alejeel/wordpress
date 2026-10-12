<?php
namespace AutoDealership\Pricing;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
defined( 'ABSPATH' ) || exit;

/** Archive references immutable revisions; no additional customer identity is duplicated. */
final class DocumentArchive {
	public const TEMPLATE = 'quotation-2';
	public static function issue( int $id, int $version ) {
		global $wpdb;
		$quote = QuoteHistory::version( $id, $version );
		if ( is_wp_error( $quote ) ) { return $quote; }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_archive_database', 'Database unavailable.' ); }
		$source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'quotation_versions' ) . ' WHERE quotation_id=%d AND version=%d FOR UPDATE', $id, $version ), ARRAY_A );
		$table = Schema::table( 'document_archive' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE quotation_id=%d AND source_version=%d AND template_version=%s", $id, $version, self::TEMPLATE ), ARRAY_A );
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return $existing; }
		if ( ! $source ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_archive_source', 'Source unavailable.' ); }
		$row = array( 'document_number'=>'DOC-Q-' . $id . '-V' . $version . '-T2', 'quotation_id'=>$id, 'source_version'=>$version, 'template_version'=>self::TEMPLATE, 'checksum'=>self::checksum( $source ), 'actor_user_id'=>get_current_user_id(), 'created_at'=>current_time( 'mysql', true ) );
		if ( 1 !== $wpdb->insert( $table, $row ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_archive_database', 'Archive failed.' ); }
		$archive_id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'document.issued', 'document', $archive_id, 'Quotation revision archived', null, $row ) ) ) { return new \WP_Error( 'adc_archive_database', 'Archive failed.' ); }
		return array( 'id'=>$archive_id ) + $row;
	}
	public static function checksum( array $source ): string {
		// Privacy erasure can redact the documentary name without corrupting financial evidence.
		unset( $source['customer_name'] );
		// Migration defaults must not invalidate previously issued document checksums.
		if ( empty($source['margin_stage']) ) { foreach(array('source_base_amount','margin_amount','margin_stage','margin_revision','plate_amount') as $field){unset($source[$field]);} }
		ksort( $source );
		return hash( 'sha256', wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
	public static function boot(): void { add_action( 'admin_post_adc_archive_quote', array( self::class, 'download' ) ); }
	public static function download(): void {
		$id = absint( $_GET['quote_id'] ?? 0 ); $version = absint( $_GET['version'] ?? 0 );
		check_admin_referer( 'adc_archive_quote_' . $id . '_' . $version );
		$archive = self::issue( $id, $version ); $quote = QuoteHistory::version( $id, $version );
		if ( is_wp_error( $archive ) || is_wp_error( $quote ) || ! hash_equals( $archive['checksum'], self::checksum( $quote ) ) ) { wp_die( 'Document unavailable or integrity verification failed.', '', array( 'response'=>409 ) ); }
		nocache_headers(); header( 'Content-Type: text/html; charset=UTF-8' ); header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $archive['document_number'] . '.html"' );
		$html = ArchivedQuotationTemplateV2::render( $quote );
		$metadata = '<aside style="padding:16px">' . esc_html( $archive['document_number'] . ' | ' . $archive['template_version'] . ' | SHA-256: ' . $archive['checksum'] ) . '</aside>';
		echo str_replace( '</body>', $metadata . '</body>', $html ); // All dynamic values escaped by the document renderer.
		exit;
	}
}
