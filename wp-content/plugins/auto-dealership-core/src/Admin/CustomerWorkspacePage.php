<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;
use AutoDealership\Pricing\QuoteHistory;
use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/** Customer timeline exposes only individually authorized records and finance sections. */
final class CustomerWorkspacePage {
	private static function text( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	private static function scope( string $alias, string $branch ): array {
		list( $sql, $args ) = BranchScope::predicate( $branch );
		if ( ! BranchScope::is_global() && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_finance' ) ) { $sql .= ' AND ' . $alias . '.owner_user_id=%d'; $args[] = get_current_user_id(); }
		return array( $sql, $args );
	}
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void { if ( QuoteHistory::can_read() ) { add_submenu_page( 'adc-quotes', self::text( 'ملف العميل', 'Customer workspace' ), self::text( 'ملف العميل', 'Customer workspace' ), 'read', 'adc-customer-workspace', array( self::class, 'render' ) ); } } );
	}
	public static function render(): void {
		if ( ! QuoteHistory::can_read() ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		global $wpdb;
		$id = absint( $_GET['customer_id'] ?? 0 );
		echo '<div class="wrap"><h1>' . esc_html( self::text( 'ملف العميل', 'Customer workspace' ) ) . '</h1>';
		$sections = array(
			'leads'=>array( self::text( 'الفرص والمتابعات', 'Leads and follow-ups' ), 'l', 'l.branch_id', 'SELECT l.id,l.stage status,l.next_action_at,l.created_at FROM ' . Schema::table( 'leads' ) . ' l WHERE l.customer_id=%d', 'l' ),
			'quotations'=>array( self::text( 'عروض الأسعار', 'Quotations' ), 'q', 'q.branch_id', 'SELECT q.id,q.quote_number,q.status,q.final_amount,q.valid_until FROM ' . Schema::table( 'quotations' ) . ' q WHERE q.customer_id=%d', 'q' ),
			'reservations'=>array( self::text( 'الحجوزات', 'Reservations' ), 'r', 'r.branch_id', 'SELECT r.id,r.status,r.expires_at,r.deposit_required_amount,r.deposit_amount,r.deposit_refund_status FROM ' . Schema::table( 'reservations' ) . ' r WHERE r.customer_id=%d', 'r' ),
			'sales'=>array( self::text( 'المبيعات', 'Sales' ), 's', 'v.branch_id', 'SELECT s.id,s.quotation_id,s.status,s.created_at FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.customer_id=%d', 's' ),
			'deliveries'=>array( self::text( 'التسليم', 'Delivery' ), 's', 'v.branch_id', 'SELECT d.id,d.status,d.delivered_at FROM ' . Schema::table( 'deliveries' ) . ' d INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=d.sale_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.customer_id=%d', 'd' ),
		);
		if ( current_user_can( 'adc_view_finance' ) ) { $sections['payments'] = array( self::text( 'المدفوعات', 'Payments' ), 's', 'v.branch_id', 'SELECT p.id,p.amount,p.status,p.reference,p.created_at FROM ' . Schema::table( 'payment_confirmations' ) . ' p INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=p.sale_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.customer_id=%d', 'p' ); }
		if ( ! $id ) {
			$source = current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ) ? 'leads' : 'quotations';
			list( $scope, $args ) = self::scope( 'l', 'l.branch_id' );
			$sql = 'SELECT DISTINCT c.id,c.full_name FROM ' . Schema::table( 'customers' ) . ' c INNER JOIN ' . Schema::table( $source ) . ' l ON l.customer_id=c.id WHERE ' . $scope . ' ORDER BY c.id DESC LIMIT 100';
			$page = QueueTable::fetch( $sql, $args, array( 'c.full_name' ) ); QueueTable::controls( 'adc-customer-workspace' );
			foreach ( $page['rows'] as $c ) { echo '<p><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>$c['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $c['full_name'] ) . '</a></p>'; }
			QueueTable::footer( $page, 'adc-customer-workspace' ); echo '</div>'; return;
		}
		$results = array(); $visible = false;
		foreach ( $sections as $key => $section ) {
			if ( 'leads' === $key && ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { continue; }
			list( $scope, $args ) = self::scope( $section[1], $section[2] );
			$results[$key] = $wpdb->get_results( $wpdb->prepare( $section[3] . ' AND ' . $scope . ' ORDER BY ' . $section[4] . '.id DESC LIMIT 100', array_merge( array( $id ), $args ) ), ARRAY_A ) ?: array();
			if ( $wpdb->last_error ) { wp_die( 'Unable to load customer records', '', array( 'response'=>500 ) ); }
			$visible = $visible || (bool) $results[$key];
		}
		if ( ! $visible ) { wp_die( 'Customer unavailable', '', array( 'response'=>404 ) ); }
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT full_name FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d', $id ) );
		echo '<h2>' . esc_html( $name ) . '</h2>';
		foreach ( $results as $key => $rows ) {
			echo '<h2>' . esc_html( $sections[$key][0] ) . '</h2><p>' . esc_html( self::text( 'أحدث 100 سجل ضمن صلاحياتك.', 'Most recent 100 records within your permissions.' ) ) . '</p><table class="widefat striped">';
			$labels = array( 'id'=>array( 'الرقم', 'ID' ), 'stage'=>array( 'المرحلة', 'Stage' ), 'status'=>array( 'الحالة', 'Status' ), 'quote_number'=>array( 'رقم العرض', 'Quote number' ), 'quotation_id'=>array( 'العرض', 'Quotation' ), 'next_action_at'=>array( 'المتابعة UTC', 'Follow-up UTC' ), 'created_at'=>array( 'الإنشاء UTC', 'Created UTC' ), 'valid_until'=>array( 'الصلاحية UTC', 'Validity UTC' ), 'expires_at'=>array( 'الانتهاء UTC', 'Expiry UTC' ), 'final_amount'=>array( 'الإجمالي', 'Total' ), 'deposit_required_amount'=>array( 'العربون المطلوب', 'Required deposit' ), 'deposit_amount'=>array( 'العربون المثبت', 'Verified deposit' ), 'deposit_refund_status'=>array( 'الاسترداد', 'Refund' ), 'amount'=>array( 'المبلغ', 'Amount' ), 'reference'=>array( 'المرجع', 'Reference' ), 'delivered_at'=>array( 'التسليم UTC', 'Delivered UTC' ) );
			if ( $rows ) { echo '<thead><tr>'; foreach ( array_keys( $rows[0] ) as $field ) { $label = $labels[$field] ?? array( $field, $field ); echo '<th>' . esc_html( self::text( $label[0], $label[1] ) ) . '</th>'; } echo '</tr></thead>'; } echo '<tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $row as $field => $value ) {
					if ( 'id' === $field && 'quotations' === $key ) { echo '<td><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-sales-journey', 'quote_id'=>$value ), admin_url( 'admin.php' ) ) ) . '">#' . absint( $value ) . '</a></td>'; }
					elseif ( 'id' === $field && 'leads' === $key ) { echo '<td><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-crm', 'lead_id'=>$value ), admin_url( 'admin.php' ) ) ) . '">#' . absint( $value ) . '</a></td>'; }
					else { echo '<td>' . esc_html( str_ends_with( $field, 'amount' ) ? Money::display( $value ) : ( 'status' === $field ? \AutoDealership\Core\Localization::label( (string) $value ) : (string) $value ) ) . '</td>'; }
				}
				echo '</tr>';
			}
			if ( ! $rows ) { echo '<tr><td>' . esc_html( self::text( 'لا توجد سجلات ضمن نطاقك.', 'No records within your scope.' ) ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
