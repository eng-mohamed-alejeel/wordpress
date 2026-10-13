<?php
namespace AutoDealership\Admin;
use AutoDealership\Purchasing\PurchaseOrderService;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;
use AutoDealership\Pricing\Money;
defined( 'ABSPATH' ) || exit;

final class PurchaseOrderPage {
	private static function t( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	private static function state( string $state ): string { $names = array( 'draft'=>'مسودة', 'pending'=>'بانتظار الاعتماد', 'approved'=>'معتمد', 'received'=>'تم الاستلام التجاري', 'rejected'=>'مرفوض', 'cancelled'=>'ملغى' ); return self::t( $names[$state] ?? $state, ucfirst( $state ) ); }
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void { add_submenu_page( 'adc-workspace', self::t( 'أوامر الشراء', 'Purchase orders' ), self::t( 'أوامر الشراء', 'Purchase orders' ), 'adc_view_purchase_orders', 'adc-purchase-orders', array( self::class, 'render' ) ); } );
		add_action( 'admin_post_adc_purchase_order', array( self::class, 'save' ) );
	}
	private static function form( string $op, int $id = 0, int $version = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_purchase_order"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="version" value="' . $version . '">';
		wp_nonce_field( 'adc_purchase_order_' . $op . '_' . $id );
	}
	private static function field( string $name, string $label, string $type = 'text', string $value = '' ): void { echo '<p><label>' . esc_html( $label ) . ' <input required name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"></label></p>'; }
	public static function render(): void {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_purchase_orders' ) ) { wp_die( '', '', array( 'response'=>403 ) ); }
		echo '<div class="wrap" dir="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl' ) . '"><h1>' . esc_html( self::t( 'أوامر الشراء', 'Purchase orders' ) ) . '</h1><p>' . esc_html( self::t( 'لكل أمر مركبة محددة. الاستلام التجاري يسجل التكلفة؛ الاستلام الفعلي والفحص يتمان في إدارة المخزون.', 'Each order covers one identified vehicle. Commercial receipt records costs; physical intake and inspection continue in inventory.' ) ) . '</p>';
		if ( isset( $_GET['result'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( 'saved' === $_GET['result'] ? self::t( 'تم الحفظ وتسجيل التدقيق.', 'Saved and audited.' ) : self::t( 'تعذر التنفيذ؛ تحقق من السياسة والصلاحية والحالة وتحديث الصفحة.', 'Could not execute; check policy, permission and state, then reload.' ) ) . '</p></div>'; }
		$policy = PurchaseOrderService::policy();
		echo '<p>' . esc_html( empty( $policy['version'] ) ? self::t( 'لم تعتمد سياسة الشراء بعد؛ يمكن إنشاء المسودات فقط.', 'Purchase policy is not approved; only drafts can be created.' ) : self::t( 'السياسة المعتمدة:', 'Approved policy:' ) . ' ' . $policy['version'] . ' | ' . Money::display( $policy['manager_limit'] ) . ' / ' . Money::display( $policy['general_limit'] ) . ' | ' . $policy['cost_basis'] ) . '</p>';
		if ( current_user_can( 'manage_options' ) ) {
			self::form( 'policy' ); echo '<h2>' . esc_html( self::t( 'اعتماد سياسة الشراء', 'Approve purchase policy' ) ) . '</h2>';
			self::field( 'manager_limit', self::t( 'حد المدير بالريال', 'Manager limit in SAR' ) ); self::field( 'general_limit', self::t( 'الحد الأعلى بالريال', 'Maximum limit in SAR' ) );
			echo '<select required name="cost_basis" aria-label="' . esc_attr( self::t( 'قاعدة احتساب التكلفة', 'Acquisition cost basis' ) ) . '"><option value="">' . esc_html( self::t( 'قاعدة احتساب التكلفة', 'Acquisition cost basis' ) ) . '</option><option value="net">' . esc_html( self::t( 'بدون الضريبة', 'Exclude tax' ) ) . '</option><option value="gross">' . esc_html( self::t( 'شاملة الضريبة', 'Include tax' ) ) . '</option></select>';
			echo '<p><label>' . esc_html( self::t( 'إلغاء الأمر بعد الاعتماد', 'Cancellation after approval' ) ) . ' <select required name="approved_cancellation"><option value=""></option><option value="manager_only">' . esc_html( self::t( 'صاحب صلاحية الاعتماد العالي فقط', 'High-tier approver only' ) ) . '</option><option value="authorised_staff">' . esc_html( self::t( 'كل صاحب صلاحية إلغاء الطلب', 'Any staff with cancellation permission' ) ) . '</option></select></label></p>';
			self::field( 'reason', self::t( 'مرجع وسبب اعتماد السياسة', 'Policy approval reference and reason' ) ); submit_button( self::t( 'اعتماد السياسة', 'Approve policy' ) ); echo '</form>';
		}
		if ( current_user_can( 'adc_create_purchase_orders' ) ) {
			list( $scope, $args ) = BranchScope::predicate( 'branch_id' );
			$vehicles = $wpdb->get_results( $args ? $wpdb->prepare( 'SELECT id,stock_number FROM ' . Schema::table( 'vehicles' ) . " WHERE status IN ('ordered','in_transit','received','inspection') AND $scope ORDER BY id DESC LIMIT 500", $args ) : 'SELECT id,stock_number FROM ' . Schema::table( 'vehicles' ) . " WHERE status IN ('ordered','in_transit','received','inspection') AND $scope ORDER BY id DESC LIMIT 500", ARRAY_A );
			$suppliers = $wpdb->get_results( 'SELECT id,display_name FROM ' . Schema::table( 'suppliers' ) . ' WHERE active=1 ORDER BY display_name LIMIT 500', ARRAY_A );
			self::form( 'create' ); echo '<h2>' . esc_html( self::t( 'إنشاء مسودة', 'Create draft' ) ) . '</h2><input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
			foreach ( array( 'vehicle_id'=>array( self::t( 'المركبة', 'Vehicle' ), $vehicles, 'stock_number' ), 'supplier_id'=>array( self::t( 'المورد', 'Supplier' ), $suppliers, 'display_name' ) ) as $name=>$data ) {
				echo '<p><label>' . esc_html( $data[0] ) . ' <select required name="' . esc_attr( $name ) . '"><option value=""></option>'; foreach ( $data[1] ?: array() as $row ) { echo '<option value="' . absint( $row['id'] ) . '">' . esc_html( $row[$data[2]] ) . '</option>'; } echo '</select></label></p>';
			}
			self::field( 'purchase_amount', self::t( 'سعر الشراء بالريال', 'Purchase price in SAR' ) ); self::field( 'additional_amount', self::t( 'التكاليف الإضافية بالريال', 'Additional costs in SAR' ), 'text', '0' ); self::field( 'tax_percent', self::t( 'نسبة الضريبة (%)', 'Tax rate (%)' ), 'text', '0' ); self::field( 'reason', self::t( 'السبب', 'Reason' ) ); submit_button( self::t( 'حفظ المسودة', 'Save draft' ) ); echo '</form>';
		}
		list( $scope, $args ) = BranchScope::predicate( 'p.branch_id' );
		$status = sanitize_key( $_GET['status'] ?? '' ); if ( in_array( $status, array( 'draft','pending','approved','received','cancelled','rejected' ), true ) ) { $scope .= ' AND p.status=%s'; $args[] = $status; }
		$results = QueueTable::fetch( 'SELECT p.*,v.stock_number,s.display_name supplier_name FROM ' . Schema::table( 'purchase_orders' ) . ' p JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=p.vehicle_id JOIN ' . Schema::table( 'suppliers' ) . " s ON s.id=p.supplier_id WHERE $scope ORDER BY p.id DESC LIMIT 100", $args, array( 'p.order_number','v.stock_number','s.display_name','p.status' ) );
		QueueTable::controls( 'adc-purchase-orders', '', array( 'status'=>array( 'label'=>'Status', 'options'=>array( ''=>'All', 'draft'=>'draft', 'pending'=>'pending', 'approved'=>'approved', 'received'=>'received', 'cancelled'=>'cancelled', 'rejected'=>'rejected' ) ) ) ); echo '<table class="widefat striped"><caption>' . esc_html( self::t( 'الأوامر: الرقم، المركبة والمورد، الحالة، الإجمالي', 'Orders: number, vehicle and supplier, state, total' ) ) . '</caption><tbody>';
		foreach ( $results['rows'] as $row ) { echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-purchase-orders','id'=>$row['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $row['order_number'] ) . '</a></td><td>' . esc_html( $row['stock_number'] . ' / ' . $row['supplier_name'] ) . '</td><td>' . esc_html( self::state( $row['status'] ) ) . '</td><td>' . esc_html( Money::display( $row['total_amount'] ) ) . '</td></tr>'; }
		if ( ! $results['rows'] ) { echo '<tr><td>' . esc_html( self::t( 'لا توجد أوامر ضمن نطاق البحث والصلاحية.', 'No orders match your search and access scope.' ) ) . '</td></tr>'; }
		echo '</tbody></table>'; QueueTable::footer( $results, 'adc-purchase-orders', '', array( 'status'=>$status ) );
		$id = absint( $_GET['id'] ?? 0 );
		if ( $id ) {
			$row = PurchaseOrderService::get( $id ); if ( is_wp_error( $row ) ) { wp_die( '', '', array( 'response'=>404 ) ); }
			echo '<p>' . esc_html( self::t( 'مرجع الاستلام:', 'Receipt reference:' ) . ' ' . $row['receipt_reference'] . ' | ' . self::t( 'المنشئ / المعتمد / المستلم:', 'Requester / approver / receiver:' ) . ' ' . $row['requester_user_id'] . ' / ' . $row['approver_user_id'] . ' / ' . $row['receiver_user_id'] . ' | ' . $row['updated_at'] . ' UTC' ) . '</p>';
			echo '<h2>' . esc_html( $row['order_number'] ) . '</h2><p>' . esc_html( self::t( 'الحالة / الإصدار:', 'State / version:' ) . ' ' . $row['status'] . ' / ' . $row['version'] . ' | ' . $row['reason'] ) . '</p><p>' . esc_html( Money::display( $row['purchase_amount'] ) . ' + ' . Money::display( $row['additional_amount'] ) . ' + ' . Money::display( $row['tax_amount'] ) . ' = ' . Money::display( $row['total_amount'] ) ) . '</p>';
			foreach ( array( 'submit'=>array('adc_create_purchase_orders','تقديم للاعتماد','Submit'), 'approve'=>array('adc_approve_purchase_orders','اعتماد','Approve'), 'reject'=>array('adc_approve_purchase_orders','رفض','Reject'), 'receive'=>array('adc_receive_purchase_orders','تسجيل الاستلام التجاري','Record commercial receipt'), 'cancel'=>array('adc_cancel_purchase_orders','إلغاء','Cancel') ) as $op=>$action ) {
				$states = array( 'submit'=>array('draft'), 'approve'=>array('pending'), 'reject'=>array('pending'), 'receive'=>array('approved'), 'cancel'=>array('draft','pending','approved') );
				if ( ! current_user_can( $action[0] ) || ! in_array( $row['status'], $states[$op], true ) ) { continue; } self::form( $op, $id, (int) $row['version'] ); self::field( 'reason', self::t( 'السبب', 'Reason' ) ); if ( 'receive' === $op ) { self::field( 'reference', self::t( 'مرجع الاستلام', 'Receipt reference' ) ); } submit_button( self::t( $action[1], $action[2] ), 'secondary' ); echo '</form>';
			}
		}
		echo '</div>';
	}
	public static function save(): void {
		$input = wp_unslash( $_POST ); $op = sanitize_key( $input['op'] ?? '' ); $id = absint( $input['id'] ?? 0 );
		check_admin_referer( 'adc_purchase_order_' . $op . '_' . $id );
		if ( 'create' === $op ) { $input['tax_rate_bps'] = Money::from_sar( $input['tax_percent'] ?? '' ); }
		$result = 'policy' === $op ? PurchaseOrderService::configure( $input, sanitize_textarea_field( $input['reason'] ?? '' ) ) : ( 'create' === $op ? PurchaseOrderService::create( $input ) : PurchaseOrderService::transition( $id, absint( $input['version'] ?? 0 ), $op, sanitize_textarea_field( $input['reason'] ?? '' ), sanitize_text_field( $input['reference'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-purchase-orders', 'id'=>is_wp_error( $result ) ? $id : ( $result['id'] ?? 0 ), 'result'=>is_wp_error( $result ) ? 'failed' : 'saved' ), admin_url( 'admin.php' ) ) ); exit;
	}
}
