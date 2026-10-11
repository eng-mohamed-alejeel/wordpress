<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Pricing\Money;
use AutoDealership\Pricing\PricingPolicy;
use AutoDealership\Pricing\QuoteHistory;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Sales\SalesService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Staff entry points reuse transactional services; reads apply branch and ownership scope. */
final class SalesJourneyPage {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			if ( QuoteHistory::can_read() ) {
				add_submenu_page( 'adc-quotes', self::text( 'دورة المبيعات', 'Sales journey' ), self::text( 'دورة المبيعات', 'Sales journey' ), 'read', 'adc-sales-journey', array( self::class, 'render' ) );
			}
		} );
		add_action( 'admin_post_adc_sales_journey', array( self::class, 'submit' ) );
	}

	private static function text( string $ar, string $en ): string {
		return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar;
	}

	private static function scope( string $alias, bool $manage = false ): array {
		list( $sql, $args ) = BranchScope::predicate( $alias . '.branch_id' );
		if ( ! BranchScope::is_global() && ! current_user_can( $manage ? 'adc_manage_branch_leads' : 'adc_view_branch_leads' ) && ! ( ! $manage && current_user_can( 'adc_view_finance' ) ) ) {
			$sql .= ' AND ' . $alias . '.owner_user_id=%d';
			$args[] = get_current_user_id();
		}
		return array( $sql, $args );
	}

	private static function rows( string $sql, array $args = array() ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		if ( $wpdb->last_error ) { wp_die( esc_html( self::text( 'تعذر تحميل البيانات. أعد المحاولة.', 'Unable to load data. Please retry.' ) ), '', array( 'response' => 500 ) ); }
		return $rows ?: array();
	}

	private static function form( string $operation, array $fields = array() ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_sales_journey_' . $operation );
		foreach ( array_merge( array( 'action'=>'adc_sales_journey', 'operation'=>$operation, 'adc_money_unit'=>'SAR' ), $fields ) as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
	}

	private static function input( string $name, string $label, string $type = 'text', string $value = '' ): void {
		echo '<p><label>' . esc_html( $label ) . ' <input required name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0.01" step="0.01"' : '' ) . '></label></p>';
	}

	private static function button( string $ar, string $en ): void {
		echo '<button type="submit" class="button button-primary">' . esc_html( self::text( $ar, $en ) ) . '</button>';
		echo '</form>';
	}

	public static function render(): void {
		if ( ! QuoteHistory::can_read() ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		echo '<div class="wrap" dir="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl' ) . '"><h1>' . esc_html( self::text( 'دورة المبيعات', 'Sales journey' ) ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( self::text( 'تم حفظ العملية.', 'Operation saved.' ) ) . '</p></div>'; }
		Navigation::tabs( 'adc-sales-journey', array( 'create'=>self::text( 'إنشاء عرض', 'Create quote' ), 'reservations'=>self::text( 'الحجوزات التجارية', 'Commercial reservations' ) ), sanitize_key( $_GET['section'] ?? 'create' ) );
		$quote_id = absint( $_GET['quote_id'] ?? 0 );
		if ( $quote_id ) { self::quote( $quote_id ); }
		elseif ( 'reservations' === ( $_GET['section'] ?? '' ) ) { self::reservations(); }
		elseif ( current_user_can( 'adc_create_quotes' ) || current_user_can( 'manage_options' ) ) { self::create_form(); }
		echo '</div>';
	}

	private static function create_form(): void {
		$customer_search = mb_substr( sanitize_text_field( wp_unslash( $_GET['customer_search'] ?? '' ) ), 0, 100 );
		$vehicle_search = mb_substr( sanitize_text_field( wp_unslash( $_GET['vehicle_search'] ?? '' ) ), 0, 100 );
		global $wpdb;
		echo '<form method="get"><input type="hidden" name="page" value="adc-sales-journey"><label>' . esc_html( self::text( 'بحث العميل', 'Customer search' ) ) . ' <input name="customer_search" value="' . esc_attr( $customer_search ) . '"></label> <label>' . esc_html( self::text( 'بحث المركبة / رقم المخزون', 'Vehicle / Stock number search' ) ) . ' <input name="vehicle_search" value="' . esc_attr( $vehicle_search ) . '"></label> <button class="button">' . esc_html( self::text( 'بحث', 'Search' ) ) . '</button></form>';
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		if ( '' !== $vehicle_search ) { $scope .= ' AND (v.stock_number LIKE %s OR v.brand LIKE %s OR v.model LIKE %s)'; $like = '%' . $wpdb->esc_like( $vehicle_search ) . '%'; $args = array_merge( $args, array( $like, $like, $like ) ); }
		$scope.=' AND '.\AutoDealership\Inventory\OnyxOperationalPolicy::predicate('v');
		$vehicles = self::rows( 'SELECT v.id,v.stock_number,v.brand,v.model FROM ' . Schema::table( 'vehicles' ) . " v WHERE v.status='available' AND " . $scope . ' ORDER BY v.id DESC LIMIT 200', $args );
		if ( BranchScope::is_global() ) {
			$customers = self::rows( 'SELECT c.id,c.full_name FROM ' . Schema::table( 'customers' ) . ' c WHERE c.merged_into_id IS NULL AND c.full_name LIKE %s ORDER BY c.id DESC LIMIT 200', array( '%' . $wpdb->esc_like( $customer_search ) . '%' ) );
		} else {
			list( $scope, $args ) = self::scope( 'l', true );
			if ( '' !== $customer_search ) { $scope .= ' AND c.full_name LIKE %s'; $args[] = '%' . $wpdb->esc_like( $customer_search ) . '%'; }
			$customers = self::rows( 'SELECT DISTINCT c.id,c.full_name FROM ' . Schema::table( 'customers' ) . ' c INNER JOIN ' . Schema::table( 'leads' ) . ' l ON l.customer_id=c.id WHERE c.merged_into_id IS NULL AND ' . $scope . ' ORDER BY c.id DESC LIMIT 200', $args );
		}
		self::form( 'preview' );
		echo '<p>' . esc_html( self::text( 'تظهر حتى 200 نتيجة لكل اختيار. استخدم البحث للوصول إلى السجلات الأقدم.', 'Up to 200 matching choices are shown. Use search to find older records.' ) ) . '</p>';
		foreach ( array( 'customer_id'=>array( self::text( 'العميل', 'Customer' ), $customers ), 'vehicle_id'=>array( self::text( 'المركبة', 'Vehicle' ), $vehicles ) ) as $name => $data ) {
			echo '<p><label>' . esc_html( $data[0] ) . ' <select required name="' . esc_attr( $name ) . '"><option value="">—</option>';
			foreach ( $data[1] as $row ) { echo '<option value="' . absint( $row['id'] ) . '">' . esc_html( $row['full_name'] ?? ( $row['stock_number'] . ' ' . $row['brand'] . ' ' . $row['model'] ) ) . '</option>'; }
			echo '</select></label></p>';
		}
		self::input( 'valid_until', self::text( 'صالح حتى (UTC)', 'Valid until (UTC)' ), 'date', gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) );
		echo '<p><label>' . esc_html( self::text( 'رمز العرض الترويجي', 'Promotion code' ) ) . ' <input name="promotion_code" maxlength="100"></label></p>';
		self::button( 'معاينة السعر والضريبة', 'Preview price and tax' );
	}

	private static function quote( int $id ): void {
		$versions = QuoteHistory::versions( $id );
		if ( is_wp_error( $versions ) || ! $versions ) { wp_die( 'Quote unavailable', '', array( 'response'=>404 ) ); }
		$q = $versions[0];
		echo '<h2>' . esc_html( $q['quote_number'] . ' — ' . $q['customer_name'] . ' — ' . $q['vehicle_description'] ) . '</h2><p>' . esc_html( Money::display( $q['final_amount'] ) . ' / ' . \AutoDealership\Core\Localization::label( $q['status'] ) . ' / ' . $q['valid_until'] ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page'=>'adc-quotes', 'quote_id'=>$id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::text( 'النسخ والطباعة', 'Versions and print' ) ) . '</a></p>';
		$discounts = self::rows( 'SELECT d.status,d.requested_amount,d.reason,(SELECT a.reason FROM ' . \AutoDealership\Audit\AuditLog::table_name() . " a WHERE a.subject_type='discount_request' AND a.subject_id=d.id AND a.event_key IN ('discount.approved','discount.rejected') ORDER BY a.id DESC LIMIT 1) decision_reason FROM " . Schema::table( 'discount_requests' ) . ' d WHERE d.quotation_id=%d ORDER BY d.id DESC LIMIT 50', array( $id ) );
		foreach ( $discounts as $d ) { echo '<p>' . esc_html( self::text( 'طلب الخصم', 'Discount request' ) . ': ' . Money::display( $d['requested_amount'] ) . ' / ' . \AutoDealership\Core\Localization::label( $d['status'] ) . ' / ' . $d['reason'] . ' / ' . $d['decision_reason'] ) . '</p>'; }
		$committed = (bool) self::rows( 'SELECT id FROM ' . Schema::table( 'sales' ) . ' WHERE quotation_id=%d LIMIT 1', array( $id ) );
		if ( ! $committed && 'approved' === $q['status'] && ( current_user_can( 'adc_request_discounts' ) || current_user_can( 'manage_options' ) ) ) {
			self::form( 'discount', array( 'quote_id'=>$id ) ); self::input( 'amount', self::text( 'الخصم المطلوب (ريال)', 'Requested discount (SAR)' ), 'number' ); self::input( 'reason', self::text( 'السبب', 'Reason' ) ); self::button( 'طلب الخصم', 'Request discount' );
		}
		list( $scope, $args ) = self::scope( 'r', true );
		$matches = self::rows( 'SELECT r.id,r.status,r.expires_at,r.deposit_required_amount,r.deposit_amount FROM ' . Schema::table( 'reservations' ) . ' r WHERE r.customer_id=%d AND r.vehicle_id=%d AND ' . $scope . ' ORDER BY r.id DESC LIMIT 50', array_merge( array( (int) $q['customer_id'], (int) $q['vehicle_id'] ), $args ) );
		$active = array_filter( $matches, static fn( array $r ): bool => in_array( $r['status'], array( 'confirmed', 'converted_to_sale' ), true ) );
		if ( ! $committed && ! $active && 'approved' === $q['status'] && $q['valid_until'] >= gmdate( 'Y-m-d' ) && ( current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_reservations' ) ) ) {
			self::form( 'reserve', array( 'quote_id'=>$id, 'idempotency_key'=>wp_generate_uuid4() ) ); self::button( 'إنشاء حجز لهذا العرض', 'Reserve this quotation' );
		}
		list( $sale_scope, $sale_args ) = self::scope( 's' );
		$sales = self::rows( 'SELECT s.id,s.status FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.quotation_id=%d AND ' . str_replace( 's.branch_id', 'v.branch_id', $sale_scope ) . ' ORDER BY s.id DESC LIMIT 50', array_merge( array( $id ), $sale_args ) );
		foreach ( $sales as $sale ) { echo '<p>' . esc_html( self::text( 'عملية البيع', 'Sale' ) . ' #' . $sale['id'] . ' / ' . \AutoDealership\Core\Localization::label( $sale['status'] ) ) . '</p>'; }
		foreach ( array( 'adc_view_finance'=>array( 'adc-payments', 'السداد والعربون', 'Payments and deposits' ), 'adc_approve_sales'=>array( 'adc-approvals&section=sales', 'اعتماد البيع', 'Sale approval' ), 'adc_approve_delivery'=>array( 'adc-delivery', 'التسليم', 'Delivery' ) ) as $cap => $link ) { if ( current_user_can( $cap ) ) { echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $link[0] ) ) . '">' . esc_html( self::text( $link[1], $link[2] ) ) . '</a></p>'; } }
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>$q['customer_id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::text( 'ملف العميل', 'Customer workspace' ) ) . '</a></p>';
		foreach ( $matches as $r ) {
			echo '<p>' . esc_html( self::text( 'الحجز', 'Reservation' ) . ' #' . $r['id'] . ' / ' . $r['status'] . ' / UTC ' . $r['expires_at'] . ' / ' . Money::display( $r['deposit_amount'] ) . ' / ' . Money::display( $r['deposit_required_amount'] ) ) . '</p>';
			if ( 'confirmed' === $r['status'] && $r['expires_at'] > current_time( 'mysql', true ) && 'approved' === $q['status'] && $q['valid_until'] >= gmdate( 'Y-m-d' ) && (int) $r['deposit_amount'] >= (int) $r['deposit_required_amount'] && ( current_user_can( 'adc_start_sales' ) || current_user_can( 'manage_options' ) ) ) {
				self::form( 'sale', array( 'quote_id'=>$id, 'reservation_id'=>$r['id'] ) ); self::button( 'بدء البيع وإرساله للاعتماد', 'Start sale and submit for approval' );
			}
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=adc-sales-journey&section=reservations' ) ) . '">' . esc_html( self::text( 'متابعة الحجز والعربون', 'Track reservation and deposit' ) ) . '</a></p>';
	}

	private static function reservations(): void {
		list( $scope, $args ) = self::scope( 'r' );
		$reservation_id = absint( $_GET['reservation_id'] ?? 0 );
		if ( $reservation_id ) { $scope .= ' AND r.id=%d'; $args[] = $reservation_id; }
		$sql = 'SELECT r.*,c.full_name,v.stock_number FROM ' . Schema::table( 'reservations' ) . ' r INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=r.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=r.vehicle_id WHERE ' . $scope . ' ORDER BY r.id DESC LIMIT 100';
		$page = QueueTable::fetch( $sql, $args, array( 'c.full_name', 'v.stock_number', 'r.status' ) );
		QueueTable::controls( 'adc-sales-journey', 'reservations' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'الحجز / العميل / المركبة', 'Reservation / Customer / Vehicle' ) ) . '</th><th>' . esc_html( self::text( 'الحالة / الانتهاء UTC', 'Status / Expiry UTC' ) ) . '</th><th>' . esc_html( self::text( 'العربون المثبت / المطلوب', 'Verified / Required deposit' ) ) . '</th><th>' . esc_html( self::text( 'الإجراء', 'Action' ) ) . '</th></tr></thead><tbody>';
		foreach ( $page['rows'] as $r ) {
			echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-sales-journey', 'section'=>'reservations', 'reservation_id'=>$r['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( '#' . $r['id'] . ' ' . $r['full_name'] . ' ' . $r['stock_number'] ) . '</a></td><td>' . esc_html( \AutoDealership\Core\Localization::label( $r['status'] ) . ' / ' . $r['expires_at'] . ' / ' . \AutoDealership\Core\Localization::label( $r['deposit_refund_status'] ) ) . '</td><td>' . esc_html( Money::display( $r['deposit_amount'] ) . ' / ' . Money::display( $r['deposit_required_amount'] ) ) . '</td><td>';
			if ( 'confirmed' === $r['status'] && current_user_can( 'adc_manage_reservations' ) ) { self::form( 'cancel', array( 'reservation_id'=>$r['id'] ) ); self::input( 'reason', self::text( 'سبب الإلغاء', 'Cancellation reason' ) ); self::button( 'إلغاء الحجز', 'Cancel reservation' ); }
			if ( 'confirmed' === $r['status'] && current_user_can( 'adc_record_payments' ) ) { self::form( 'deposit', array( 'reservation_id'=>$r['id'] ) ); self::input( 'amount', self::text( 'العربون (ريال)', 'Deposit (SAR)' ), 'number' ); self::input( 'reference', self::text( 'مرجع الإيصال', 'Receipt reference' ) ); echo '<select name="source"><option value="bank_transfer">' . esc_html( self::text( 'تحويل بنكي', 'Bank transfer' ) ) . '</option><option value="cash">' . esc_html( self::text( 'نقدي', 'Cash' ) ) . '</option></select>'; self::button( 'تسجيل العربون للمراجعة', 'Record deposit for review' ); }
			echo '</td></tr>';
		}
		if ( ! $page['rows'] ) { echo '<tr><td colspan="4">' . esc_html( self::text( 'لا توجد نتائج ضمن نطاق وصولك.', 'No results within your access scope.' ) ) . '</td></tr>'; }
		echo '</tbody></table>'; QueueTable::footer( $page, 'adc-sales-journey', 'reservations' );
		if ( $reservation_id ) {
			if ( ! $page['rows'] ) { wp_die( 'Reservation unavailable', '', array( 'response'=>404 ) ); }
			$r = $page['rows'][0];
			list( $quote_scope, $quote_args ) = self::scope( 'q' );
			$quotes = self::rows( 'SELECT q.id,q.quote_number,q.status FROM ' . Schema::table( 'quotations' ) . ' q WHERE q.customer_id=%d AND q.vehicle_id=%d AND ' . $quote_scope . ' ORDER BY q.id DESC LIMIT 50', array_merge( array( $r['customer_id'], $r['vehicle_id'] ), $quote_args ) );
			echo '<h2>' . esc_html( self::text( 'العروض المطابقة', 'Matching quotations' ) ) . '</h2>';
			foreach ( $quotes as $quote ) { echo '<p><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-sales-journey', 'quote_id'=>$quote['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $quote['quote_number'] . ' / ' . \AutoDealership\Core\Localization::label( $quote['status'] ) ) . '</a></p>'; }
			if ( 'confirmed' === $r['status'] && $r['expires_at'] <= current_time( 'mysql', true ) ) { echo '<p>' . esc_html( self::text( 'انتهت المهلة. الحجوزات ذات الدفعات تتطلب مراجعة المسؤول وإلغاءً صريحًا وتسوية مالية قبل تحرير المركبة.', 'The deadline has passed. Reservations with payment evidence require manager review, explicit cancellation and financial settlement before vehicle release.' ) ) . '</p>'; }
			if ( current_user_can( 'adc_view_finance' ) ) {
				foreach ( array( 'reservation_deposits'=>self::text( 'إيصالات العربون', 'Deposit receipts' ), 'payment_refunds'=>self::text( 'سجل الاسترداد', 'Refund history' ) ) as $table => $title ) {
					$history = self::rows( 'SELECT id,amount,reference,status,decision_reason,created_at,decided_at FROM ' . Schema::table( $table ) . ' WHERE reservation_id=%d ORDER BY id DESC LIMIT 100', array( $reservation_id ) );
					echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped"><tbody>';
					foreach ( $history as $entry ) { echo '<tr><td>#' . absint( $entry['id'] ) . '</td><td>' . esc_html( Money::display( $entry['amount'] ) ) . '</td><td>' . esc_html( $entry['reference'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( $entry['status'] ) ) . '</td><td>' . esc_html( $entry['decision_reason'] ) . '</td><td>' . esc_html( $entry['created_at'] . ' / ' . $entry['decided_at'] ) . '</td></tr>'; }
					echo '</tbody></table>';
				}
				foreach ( array( 'adc-payments'=>self::text( 'مراجعة الدفعات', 'Payment review' ), 'adc-refunds'=>self::text( 'متابعة الاسترداد', 'Refund tracking' ) ) as $slug => $label ) { echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a></p>'; }
			}
		}
	}

	public static function submit(): void {
		foreach ( $_POST as $value ) { if ( ! is_scalar( $value ) ) { wp_die( 'Invalid form input', '', array( 'response'=>400 ) ); } }
		$op = sanitize_key( $_POST['operation'] ?? '' );
		if ( ! in_array( $op, array( 'preview', 'create', 'discount', 'reserve', 'sale', 'cancel', 'deposit' ), true ) ) { wp_die( 'Invalid operation', '', array( 'response'=>400 ) ); }
		check_admin_referer( 'adc_sales_journey_' . $op );
		if ( ! QuoteHistory::can_read() ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		$p = wp_unslash( $_POST ); $id = absint( $p['quote_id'] ?? 0 );
		if ( 'create' === $op && ( ! wp_is_uuid( $p['idempotency_key'] ?? '' ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $p['preview_signature'] ?? '' ) ) ) { wp_die( 'Preview this quotation before saving', '', array( 'response'=>400 ) ); }
		if ( in_array( $op, array( 'reserve', 'discount', 'sale' ), true ) ) {
			$versions = QuoteHistory::versions( $id );
			if ( is_wp_error( $versions ) || ! $versions ) { wp_die( 'Quote unavailable', '', array( 'response'=>404 ) ); }
			$q = $versions[0];
		}
		if ( 'preview' === $op ) { nocache_headers(); self::preview( $p ); exit; }
		if ( 'create' === $op ) { $result = SalesService::create_quote( absint( $p['customer_id'] ?? 0 ), absint( $p['vehicle_id'] ?? 0 ), sanitize_text_field( $p['valid_until'] ?? '' ), sanitize_key( $p['promotion_code'] ?? '' ), sanitize_text_field( $p['idempotency_key'] ?? '' ), sanitize_text_field( $p['preview_signature'] ?? '' ) ); if ( ! is_wp_error( $result ) ) { $id = $result['id']; } }
		elseif ( 'discount' === $op || 'deposit' === $op ) {
			Money::require_sar_form(); $amount = Money::from_sar( $p['amount'] ?? '' );
			if ( null === $amount ) { wp_die( 'Invalid SAR amount', '', array( 'response'=>400 ) ); }
			$result = 'discount' === $op ? SalesService::request_discount( $id, $amount, sanitize_textarea_field( $p['reason'] ?? '' ) ) : ReservationService::record_deposit( absint( $p['reservation_id'] ?? 0 ), $amount, sanitize_key( $p['source'] ?? '' ), sanitize_text_field( $p['reference'] ?? '' ) );
		}
		elseif ( 'reserve' === $op ) { $result = ReservationService::create( array( 'customer_id'=>$q['customer_id'], 'vehicle_id'=>$q['vehicle_id'], 'deposit_amount'=>0, 'idempotency_key'=>$p['idempotency_key'] ?? '' ) ); }
		elseif ( 'sale' === $op ) { $result = SalesService::create_sale( $id, absint( $p['reservation_id'] ?? 0 ) ); }
		else { $result = ReservationService::cancel( absint( $p['reservation_id'] ?? 0 ), sanitize_textarea_field( $p['reason'] ?? '' ) ); }
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link'=>true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-sales-journey', 'quote_id'=>$id, 'section'=>$id ? 'create' : 'reservations', 'saved'=>1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	private static function preview( array $p ): void {
		global $wpdb;
		if ( ! current_user_can( 'adc_create_quotes' ) && ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,retail_price,minimum_price,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d', absint( $p['vehicle_id'] ?? 0 ) ), ARRAY_A );
		$source=$v?\AutoDealership\Inventory\OnyxOperationalPolicy::current((int)$v['id']):null;if(is_wp_error($source)){wp_die(esc_html($source->get_error_message()),'',array('response'=>409));}if($source){$v['retail_price']=$source['listed_price'];}
		if ( ! $v || 'available' !== $v['status'] || ! \AutoDealership\Security\CustomerScope::allows( absint( $p['customer_id'] ?? 0 ), (int) $v['branch_id'] ) ) { wp_die( 'Selection unavailable', '', array( 'response'=>403 ) ); }
		try { $amounts = PricingPolicy::quote( (int) $v['retail_price'], 0, (int) get_option( 'adc_vat_rate_bps', 0 ), sanitize_key( $p['promotion_code'] ?? '' ) ); }
		catch ( \InvalidArgumentException | \OverflowException $e ) { wp_die( esc_html( self::text( 'تعذر احتساب السعر وفق السياسة الحالية.', 'Unable to calculate current pricing policy.' ) ), '', array( 'response'=>400 ) ); }
		if ( null !== $v['minimum_price'] && $amounts['base_amount'] - $amounts['promotion_amount'] < (int) $v['minimum_price'] ) { wp_die( 'Price below approved minimum', '', array( 'response'=>409 ) ); }
		echo '<!doctype html><html lang="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'en' : 'ar' ) . '"><meta charset="utf-8"><title>' . esc_html( self::text( 'معاينة عرض السعر', 'Quotation preview' ) ) . '</title><body><h1>' . esc_html( self::text( 'معاينة عرض السعر', 'Quotation preview' ) ) . '</h1><dl>';
		foreach ( array( 'base_amount'=>self::text( 'السعر الأساسي', 'Base price' ), 'fee_amount'=>self::text( 'الرسوم', 'Fees' ), 'promotion_amount'=>self::text( 'العرض الترويجي', 'Promotion' ), 'subtotal_amount'=>self::text( 'قبل الضريبة', 'Subtotal' ), 'tax_amount'=>self::text( 'الضريبة', 'Tax' ), 'final_amount'=>self::text( 'الإجمالي', 'Total' ) ) as $field => $label ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( Money::display( $amounts[$field] ) ) . '</dd>'; }
		echo '</dl><p>' . esc_html( self::text( 'يعاد التحقق من السعر والتوفر عند الحفظ.', 'Pricing and availability are revalidated on save.' ) ) . '</p>';
		self::form( 'create', array( 'customer_id'=>absint( $p['customer_id'] ), 'vehicle_id'=>$v['id'], 'idempotency_key'=>wp_generate_uuid4(), 'preview_signature'=>hash_hmac( 'sha256', wp_json_encode( $amounts ), wp_salt( 'nonce' ) ), 'valid_until'=>sanitize_text_field( $p['valid_until'] ?? '' ), 'promotion_code'=>sanitize_key( $p['promotion_code'] ?? '' ) ) ); self::button( 'حفظ عرض السعر', 'Save quotation' ); echo '</body></html>';
	}
}
