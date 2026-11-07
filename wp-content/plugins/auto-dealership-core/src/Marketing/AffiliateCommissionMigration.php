<?php
namespace AutoDealership\Marketing;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Idempotent, resumable initialization of the immutable affiliate ledger. */
final class AffiliateCommissionMigration {
	private const OPTION = 'adc_affiliate_commission_migration';
	private const BATCH_SIZE = 100;
	private const LOCK = 'adc_affiliate_commission_migration';

	public static function boot(): void {
		add_action( 'adc_migrate_affiliate_commissions', array( self::class, 'run_scheduled' ) );
	}

	public static function status(): array {
		$state = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $state ) ? $state : array(), array(
			'status'=>'idle', 'last_id'=>0, 'total'=>0, 'processed'=>0, 'created'=>0,
			'skipped'=>0, 'quarantined'=>0, 'zero_balance'=>0, 'issue_count'=>0, 'error'=>'', 'started_at'=>'', 'finished_at'=>'',
		) );
	}

	public static function dry_run() {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_migration_forbidden', 'Affiliate commission migration permission is required.', array( 'status'=>403 ) );
		}
		$commissions = Schema::table( 'affiliate_commissions' );
		$entries = Schema::table( 'affiliate_commission_entries' );
	$issues = Schema::table( 'affiliate_commission_migration_issues' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $commissions c LEFT JOIN $entries e ON e.commission_id=c.id LEFT JOIN $issues i ON i.commission_id=c.id AND i.status='open' WHERE e.id IS NULL AND i.id IS NULL" );
		$quarantined = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $issues WHERE status='open'" );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_migration_read', 'Unable to inspect legacy commissions.', array( 'status'=>500 ) ); }
		return array( 'pending'=>$count, 'quarantined'=>$quarantined, 'currency'=>AffiliateCommissionService::CURRENCY );
	}

	public static function start() {
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_migration_forbidden', 'Affiliate commission migration permission is required.', array( 'status'=>403 ) );
		}
		$current = self::status();
		if ( 'running' === $current['status'] ) { return $current; }
		$preview = self::dry_run();
		if ( is_wp_error( $preview ) ) { return $preview; }
		$state = array(
			'status'=>'running', 'last_id'=>0, 'total'=>(int) $preview['pending'], 'processed'=>0,
			'created'=>0, 'skipped'=>0, 'quarantined'=>0, 'zero_balance'=>0, 'issue_count'=>(int) $preview['quarantined'], 'error'=>'',
			'started_at'=>current_time( 'mysql', true ), 'finished_at'=>'',
		);
		update_option( self::OPTION, $state, false );
		wp_schedule_single_event( time() + 5, 'adc_migrate_affiliate_commissions' );
		if ( ! AuditLog::record( 'affiliate_commission.migration_started', 'affiliate_commission_migration', 0, 'Initialize historical affiliate commission ledger', null, array( 'total'=>$state['total'], 'currency'=>AffiliateCommissionService::CURRENCY ) ) ) {
			$state['status'] = 'failed'; $state['error'] = 'audit_failed'; $state['finished_at'] = current_time( 'mysql', true ); update_option( self::OPTION, $state, false );
			wp_clear_scheduled_hook( 'adc_migrate_affiliate_commissions' );
			\AutoDealership\Operations\AdministrativeAlert::migration_failed( (string) $state['error'], $state );
			return new \WP_Error( 'adc_commission_migration_audit', 'Unable to audit migration start.', array( 'status'=>500 ) );
		}
		return $state;
	}

	public static function run_scheduled(): void {
		$state = self::status();
		if ( 'running' !== $state['status'] ) { return; }
		global $wpdb;
		$lock = substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . self::LOCK ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return; }
		try {
			$result = self::run_batch( $state );
			if ( is_wp_error( $result ) ) {
				$state['status'] = 'failed'; $state['error'] = $result->get_error_code(); $state['finished_at'] = current_time( 'mysql', true );
				update_option( self::OPTION, $state, false );
				\AutoDealership\Operations\AdministrativeAlert::migration_failed( (string) $state['error'], $state );
				return;
			}
			update_option( self::OPTION, $result, false );
			if ( 'failed' === $result['status'] ) {
				\AutoDealership\Operations\AdministrativeAlert::migration_failed( (string) $result['error'], $result );
			}
			if ( 'running' === $result['status'] ) { wp_schedule_single_event( time() + 5, 'adc_migrate_affiliate_commissions' ); }
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function run_batch( array $state ) {
		global $wpdb;
		$commissions = Schema::table( 'affiliate_commissions' );
		$entries = Schema::table( 'affiliate_commission_entries' );
		$issues = Schema::table( 'affiliate_commission_migration_issues' );
		$quotations = Schema::table( 'quotations' );
		$sales = Schema::table( 'sales' );
		$branches = Schema::table( 'branches' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*,q.id quotation_exists,s.id sale_exists,u.ID affiliate_exists,b.id branch_exists FROM $commissions c LEFT JOIN $entries e ON e.commission_id=c.id LEFT JOIN $issues i ON i.commission_id=c.id AND i.status='open' LEFT JOIN $quotations q ON q.id=c.quotation_id LEFT JOIN $sales s ON s.id=c.sale_id LEFT JOIN {$wpdb->users} u ON u.ID=c.affiliate_user_id LEFT JOIN $branches b ON b.id=c.branch_id WHERE e.id IS NULL AND i.id IS NULL AND c.id>%d ORDER BY c.id ASC LIMIT %d", (int) $state['last_id'], self::BATCH_SIZE ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_migration_read', 'Unable to read legacy commissions.' ); }
		if ( ! $rows ) {
			$state['status'] = 'complete'; $state['finished_at'] = current_time( 'mysql', true );
			if ( ! AuditLog::record( 'affiliate_commission.migration_completed', 'affiliate_commission_migration', 0, 'Historical affiliate commission ledger initialized', null, array( 'processed'=>(int) $state['processed'], 'created'=>(int) $state['created'], 'zero_balance'=>(int) $state['zero_balance'] ) ) ) {
				$state['status'] = 'failed'; $state['error'] = 'audit_failed';
			}
			return $state;
		}
		if ( ! Transaction::begin( array( $commissions, $entries, $issues ) ) ) { return new \WP_Error( 'adc_commission_migration_transaction', 'Unable to begin migration transaction.' ); }
		$created = 0; $skipped = 0; $quarantined = 0; $issue_count = 0; $zero = 0; $last_id = (int) $state['last_id'];
		foreach ( $rows as $row ) {
			$last_id = max( $last_id, (int) $row['id'] );
			$status = (string) $row['status'];
			$currency = strtoupper( (string) ( $row['currency_code'] ?: AffiliateCommissionService::CURRENCY ) );
			$row_issues = array();
			if ( empty( $row['quotation_exists'] ) ) { $row_issues['missing_quotation'] = 'Quotation reference does not resolve to a valid quotation.'; }
			if ( empty( $row['sale_exists'] ) ) { $row_issues['missing_sale'] = 'Sale reference does not resolve to a valid sale.'; }
			if ( empty( $row['affiliate_exists'] ) ) { $row_issues['missing_affiliate'] = 'Affiliate user reference does not resolve to a valid user.'; }
			if ( empty( $row['branch_exists'] ) ) { $row_issues['missing_branch'] = 'Branch reference does not resolve to a valid branch.'; }
			if ( ! AffiliateCommissionService::is_supported_currency( $currency ) ) { $row_issues['invalid_currency'] = 'Only SAR is supported by the configured single-currency ledger.'; }
			if ( ! in_array( $status, AffiliateCommissionService::statuses(), true ) ) { $row_issues['invalid_status'] = 'Commission status is not supported by the state machine.'; }
			if ( (int) $row['amount'] < 0 || ( (int) $row['amount'] < 1 && ! in_array( $status, array( 'void', 'paid_sale_returned' ), true ) ) ) { $row_issues['invalid_amount'] = 'Commission amount is negative or empty for a non-reversal status.'; }
			if ( $row_issues ) {
				foreach ( $row_issues as $issue_type => $reason ) {
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $issues (commission_id,issue_type,reason,snapshot,status,resolved_by,resolved_at,created_at,updated_at) VALUES (%d,%s,%s,%s,'open',0,NULL,%s,%s)", (int) $row['id'], $issue_type, $reason, wp_json_encode( array( 'id'=>(int) $row['id'], 'sale_id'=>(int) $row['sale_id'], 'quotation_id'=>(int) $row['quotation_id'], 'affiliate_user_id'=>(int) $row['affiliate_user_id'], 'branch_id'=>(int) $row['branch_id'], 'amount'=>(int) $row['amount'], 'currency_code'=>$currency, 'status'=>$status ), JSON_UNESCAPED_UNICODE ), current_time( 'mysql', true ), current_time( 'mysql', true ) ) );
					if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_issue_write', 'Unable to quarantine an invalid historical commission.' ); }
					++$issue_count;
				}
				++$quarantined;
				continue;
			}
			$amount = in_array( $status, array( 'void', 'paid_sale_returned' ), true ) ? 0 : (int) $row['amount'];
			$event_key = hash( 'sha256', 'legacy-opening:' . (int) $row['id'] );
			$inserted = $wpdb->insert( $entries, array(
				'commission_id'=>(int) $row['id'], 'sale_id'=>(int) $row['sale_id'], 'affiliate_user_id'=>(int) $row['affiliate_user_id'],
				'branch_id'=>(int) $row['branch_id'], 'entry_type'=>'legacy_opening', 'amount'=>$amount,
				'currency_code'=>$currency, 'event_key'=>$event_key,
				'reason'=>'Historical commission opening balance migrated without inventing prior events.', 'actor_user_id'=>0,
				'created_at'=>current_time( 'mysql', true ),
			), array( '%d','%d','%d','%d','%s','%d','%s','%s','%s','%d','%s' ) );
			if ( 1 === $inserted ) { ++$created; if ( 0 === $amount ) { ++$zero; } }
			elseif ( 0 === $inserted && ! $wpdb->last_error ) { ++$skipped; }
			else { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_write', 'Unable to write a historical ledger entry.' ); }
		}
		$batch = array( 'last_id'=>$last_id, 'count'=>count( $rows ), 'created'=>$created, 'skipped'=>$skipped, 'quarantined'=>$quarantined, 'issue_count'=>$issue_count, 'zero_balance'=>$zero );
		if ( ! Transaction::commit( static fn(): bool => AuditLog::record( 'affiliate_commission.migration_batch', 'affiliate_commission_migration', 0, 'Historical commission migration batch', null, $batch ) ) ) {
			return new \WP_Error( 'adc_commission_migration_commit', 'Unable to commit migration batch.' );
		}
		$state['last_id'] = $last_id; $state['processed'] += count( $rows ); $state['created'] += $created; $state['skipped'] += $skipped; $state['quarantined'] += $quarantined; $state['issue_count'] += $issue_count; $state['zero_balance'] += $zero;
		$state['status'] = 'running';
		return $state;
	}

	public static function issues( array $filters = array() ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_commission_migration_forbidden', 'Affiliate commission migration permission is required.', array( 'status'=>403 ) ); }
		$table = Schema::table( 'affiliate_commission_migration_issues' );
		$page = max( 1, absint( $filters['page'] ?? 1 ) ); $per_page = min( 100, max( 1, absint( $filters['per_page'] ?? 25 ) ) );
		$where = array( '1=1' ); $args = array(); $status = sanitize_key( (string) ( $filters['status'] ?? 'open' ) );
		if ( '' !== $status && in_array( $status, array( 'open', 'resolved' ), true ) ) { $where[] = 'status=%s'; $args[] = $status; }
		$issue_type = sanitize_key( (string) ( $filters['issue_type'] ?? '' ) );
		if ( '' !== $issue_type && preg_match( '/\A[a-z0-9_]{1,64}\z/', $issue_type ) ) { $where[] = 'issue_type=%s'; $args[] = $issue_type; }
		$total_sql = "SELECT COUNT(*) FROM $table WHERE " . implode( ' AND ', $where ); $total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $total_sql, $args ) : $total_sql );
		$sql = "SELECT id,commission_id,issue_type,reason,snapshot,status,resolved_by,resolved_at,created_at,updated_at FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_migration_read', 'Unable to load migration issues.', array( 'status'=>500 ) ); }
		return array( 'items'=>$items ?: array(), 'page'=>$page, 'per_page'=>$per_page, 'total'=>$total, 'pages'=>(int) ceil( $total / $per_page ) );
	}

	public static function resolve_issue( int $issue_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_commission_migration_forbidden', 'Affiliate commission migration permission is required.', array( 'status'=>403 ) ); }
		$table = Schema::table( 'affiliate_commission_migration_issues' );
		if ( ! Transaction::begin( array( $table ) ) ) { return new \WP_Error( 'adc_commission_migration_issue_transaction', 'Unable to begin migration issue review.' ); }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND status='open' FOR UPDATE", $issue_id ), ARRAY_A );
		if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_issue_not_found', 'Migration issue was not found or is already resolved.', array( 'status'=>404 ) ); }
		$commissions = Schema::table( 'affiliate_commissions' );
		$quotations = Schema::table( 'quotations' );
		$sales = Schema::table( 'sales' );
		$branches = Schema::table( 'branches' );
		$commission = $wpdb->get_row( $wpdb->prepare( "SELECT c.id,c.quotation_id,c.sale_id,c.affiliate_user_id,c.branch_id,c.amount,c.currency_code,c.status,q.id quotation_exists,s.id sale_exists,u.ID affiliate_exists,b.id branch_exists FROM $commissions c LEFT JOIN $quotations q ON q.id=c.quotation_id LEFT JOIN $sales s ON s.id=c.sale_id LEFT JOIN {$wpdb->users} u ON u.ID=c.affiliate_user_id LEFT JOIN $branches b ON b.id=c.branch_id WHERE c.id=%d FOR UPDATE", (int) $row['commission_id'] ), ARRAY_A );
		if ( ! $commission || $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_source_missing', 'The original commission record could not be loaded for review.', array( 'status'=>409 ) ); }
		$issue_type = (string) $row['issue_type'];
		$fixed = false;
		switch ( $issue_type ) {
			case 'missing_quotation': $fixed = ! empty( $commission['quotation_exists'] ); break;
			case 'missing_sale': $fixed = ! empty( $commission['sale_exists'] ); break;
			case 'missing_affiliate': $fixed = ! empty( $commission['affiliate_exists'] ); break;
			case 'missing_branch': $fixed = ! empty( $commission['branch_exists'] ); break;
			case 'invalid_currency': $fixed = AffiliateCommissionService::is_supported_currency( strtoupper( (string) ( $commission['currency_code'] ?: AffiliateCommissionService::CURRENCY ) ) ); break;
			case 'invalid_status': $fixed = in_array( (string) $commission['status'], AffiliateCommissionService::statuses(), true ); break;
			case 'invalid_amount': $fixed = (int) $commission['amount'] >= 0 && ( (int) $commission['amount'] >= 1 || in_array( (string) $commission['status'], array( 'void', 'paid_sale_returned' ), true ) ); break;
			default: $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_unknown_issue', 'This migration issue type is not supported for automatic review.', array( 'status'=>409 ) );
		}
		if ( ! $fixed ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_source_unfixed', 'Correct the original commission reference or value, then run the review again. No balance was created.', array( 'status'=>409 ) ); }
		$updated = $wpdb->update( $table, array( 'status'=>'resolved', 'resolved_by'=>get_current_user_id(), 'resolved_at'=>current_time( 'mysql', true ), 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>$issue_id ), array( '%s','%d','%s','%s' ), array( '%d' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_commission_migration_issue_write', 'Unable to resolve migration issue.', array( 'status'=>500 ) ); }
		if ( ! Transaction::commit( static fn(): bool => AuditLog::record( 'affiliate_commission.migration_issue_resolved', 'affiliate_commission_migration_issue', $issue_id, 'Historical commission migration issue resolved', $row, array( 'status'=>'resolved', 'resolved_by'=>get_current_user_id() ) ) ) ) { return new \WP_Error( 'adc_commission_migration_issue_write', 'Unable to resolve migration issue.', array( 'status'=>500 ) ); }
		return true;
	}
}
