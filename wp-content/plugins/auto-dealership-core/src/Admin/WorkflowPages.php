<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Delivery\DeliveryService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Sales\SalesService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Admin approval, finance and delivery queues backed by domain services. */
final class WorkflowPages {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		foreach ( array( 'adc_decide_discount', 'adc_approve_sale', 'adc_set_finance_status', 'adc_create_finance_request', 'adc_prepare_delivery', 'adc_confirm_delivery_vin', 'adc_record_delivery_document', 'adc_approve_delivery', 'adc_release_delivery' ) as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, $action ) );
		}
	}

	public static function menu(): void {
		if ( current_user_can( 'adc_review_discounts' ) || current_user_can( 'adc_approve_sales' ) ) {
			add_menu_page( __( 'Approvals', 'auto-dealership-core' ), __( 'Approvals', 'auto-dealership-core' ), 'adc_view_workspace', 'adc-approvals', array( self::class, 'approvals' ), 'dashicons-yes-alt', 60 );
		}
		if ( current_user_can( 'adc_view_finance' ) || current_user_can( 'adc_manage_finance' ) ) {
			add_menu_page( __( 'Finance', 'auto-dealership-core' ), __( 'Finance', 'auto-dealership-core' ), 'adc_view_workspace', 'adc-finance', array( self::class, 'finance' ), 'dashicons-money-alt', 61 );
		}
		if ( current_user_can( 'adc_approve_delivery' ) || current_user_can( 'adc_confirm_vehicle_vin' ) ) {
			add_menu_page( __( 'Delivery', 'auto-dealership-core' ), __( 'Delivery', 'auto-dealership-core' ), 'adc_view_workspace', 'adc-delivery', array( self::class, 'delivery' ), 'dashicons-car', 62 );
		}
	}

	private static function branch_sql( string $vehicle_alias = 'v' ): array {
		list( $scope, $args ) = BranchScope::predicate( $vehicle_alias . '.branch_id' );
		return array( ' AND ' . $scope, $args );
	}

	private static function queue_notice(): void {
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'تم حفظ القرار.', 'auto-dealership-core' ) . '</p></div>'; }
		if ( isset( $_GET['error'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'تعذر تنفيذ العملية. تحقق من الصلاحيات وحالة سير العمل.', 'auto-dealership-core' ) . '</p></div>'; }
	}

	private static function rows( string $sql, array $args = array() ): array {
		global $wpdb;
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public static function approvals(): void {
		if ( ! current_user_can( 'adc_review_discounts' ) && ! current_user_can( 'adc_approve_sales' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية الاعتمادات.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		global $wpdb;
		list( $scope, $scope_args ) = self::branch_sql();
		?>
		<div class="wrap" dir="rtl"><h1><?php esc_html_e( 'الاعتمادات', 'auto-dealership-core' ); ?></h1><?php self::queue_notice(); ?>
		<?php if ( current_user_can( 'adc_review_discounts' ) ) : ?>
			<h2><?php esc_html_e( 'طلبات الخصم', 'auto-dealership-core' ); ?></h2>
			<?php
			$discount_sql = 'SELECT d.id,d.requested_amount,d.approval_tier,d.margin_before,d.margin_after,d.reason,d.requester_user_id,q.quote_number,q.base_amount,c.full_name,v.brand,v.model FROM ' . Schema::table( 'discount_requests' ) . ' d INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=d.quotation_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=q.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=q.vehicle_id WHERE d.status = \'pending\'' . $scope . ' ORDER BY d.created_at ASC LIMIT 100';
			$discounts = self::rows( $discount_sql, $scope_args );
			?>
			<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'العميل / السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'عرض السعر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الخصم (هللة)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Approval tier', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Margin before / after', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'السبب', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'القرار', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $discounts as $row ) : ?><tr><td><?php echo absint( $row['id'] ); ?></td><td><?php echo esc_html( $row['full_name'] . ' — ' . $row['brand'] . ' ' . $row['model'] ); ?></td><td><?php echo esc_html( $row['quote_number'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['requested_amount'] ) ); ?></td><td><?php echo esc_html( $row['approval_tier'] ); ?></td><td><?php echo null === $row['margin_before'] ? esc_html__( 'Unknown legacy cost', 'auto-dealership-core' ) : esc_html( number_format_i18n( (int) $row['margin_before'] ) . ' / ' . number_format_i18n( (int) $row['margin_after'] ) ); ?></td><td><?php echo esc_html( $row['reason'] ); ?></td><td><?php foreach ( array( 'approve', 'reject' ) as $decision ) : if ( 'approve' === $decision && 'general_manager' === $row['approval_tier'] && ! current_user_can( 'adc_approve_high_discounts' ) ) { continue; } ?><form style="display:inline-block" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_decide_discount"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>"><?php wp_nonce_field( 'adc_decide_discount_' . (int) $row['id'] ); ?><input name="reason" placeholder="<?php esc_attr_e( 'ملاحظة القرار', 'auto-dealership-core' ); ?>"><button class="button <?php echo 'approve' === $decision ? 'button-primary' : ''; ?>"><?php echo 'approve' === $decision ? esc_html__( 'اعتماد', 'auto-dealership-core' ) : esc_html__( 'رفض', 'auto-dealership-core' ); ?></button></form><?php endforeach; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $discounts ) : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد طلبات خصم معلّقة في نطاقك.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
		<?php endif; ?>
		<?php if ( current_user_can( 'adc_approve_sales' ) ) : ?>
			<h2><?php esc_html_e( 'عمليات البيع بانتظار الاعتماد', 'auto-dealership-core' ); ?></h2>
			<?php
			$sales_sql = 'SELECT s.id,s.owner_user_id,q.quote_number,q.final_amount,c.full_name,v.brand,v.model FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=s.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.status = \'pending_approval\'' . $scope . ' ORDER BY s.created_at ASC LIMIT 100';
			$sales = self::rows( $sales_sql, $scope_args );
			?>
			<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'العميل / السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'عرض السعر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الإجمالي (هللة)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'مرجع الفاتورة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $sales as $row ) : ?><tr><td><?php echo absint( $row['id'] ); ?></td><td><?php echo esc_html( $row['full_name'] . ' — ' . $row['brand'] . ' ' . $row['model'] ); ?></td><td><?php echo esc_html( $row['quote_number'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['final_amount'] ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_approve_sale"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_approve_sale_' . (int) $row['id'] ); ?><input name="invoice_reference" required><button class="button button-primary"><?php esc_html_e( 'اعتماد البيع', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?>
			<?php if ( ! $sales ) : ?><tr><td colspan="5"><?php esc_html_e( 'لا توجد عمليات بيع معلّقة في نطاقك.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
		<?php endif; ?></div>
		<?php
	}

	public static function finance(): void {
		if ( ! current_user_can( 'adc_view_finance' ) && ! current_user_can( 'adc_manage_finance' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض طلبات التمويل.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		global $wpdb;
		list( $scope, $scope_args ) = self::branch_sql();
		$finance_sql = 'SELECT f.id,f.sale_id,f.attempt_number,f.previous_request_id,f.provider,f.requested_amount,f.down_payment,f.term_months,f.monthly_payment,f.status,f.provider_reference,f.decision_reason,f.requested_by,s.vehicle_id,c.full_name,v.brand,v.model FROM ' . Schema::table( 'finance_requests' ) . ' f INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=f.sale_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=s.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE 1=1' . $scope . ' ORDER BY f.created_at DESC LIMIT 100';
		$requests = self::rows( $finance_sql, $scope_args );
		$sales = array();
		if ( current_user_can( 'adc_manage_finance' ) ) {
			$pending_sales = 'SELECT s.id,s.customer_id,q.final_amount,c.full_name,v.brand,v.model FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=s.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.status = \'pending_approval\'' . $scope . ' ORDER BY s.id DESC LIMIT 100';
			$sales = self::rows( $pending_sales, $scope_args );
		}
		?>
		<div class="wrap" dir="rtl"><h1><?php esc_html_e( 'طلبات التمويل', 'auto-dealership-core' ); ?></h1><?php self::queue_notice(); ?>
			<?php if ( current_user_can( 'adc_manage_finance' ) ) : ?><h2><?php esc_html_e( 'إرسال طلب تمويل بموافقة العميل', 'auto-dealership-core' ); ?></h2>
			<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'العميل / السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المبلغ الأقصى (هللة)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'طلب التمويل', 'auto-dealership-core' ); ?></th></tr></thead><tbody><?php foreach ( $sales as $sale ) : ?><tr><td><?php echo absint( $sale['id'] ); ?></td><td><?php echo esc_html( $sale['full_name'] . ' — ' . $sale['brand'] . ' ' . $sale['model'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $sale['final_amount'] ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_create_finance_request"><input type="hidden" name="sale_id" value="<?php echo absint( $sale['id'] ); ?>"><?php wp_nonce_field( 'adc_create_finance_request_' . (int) $sale['id'] ); ?><input name="provider" placeholder="<?php esc_attr_e( 'جهة التمويل', 'auto-dealership-core' ); ?>" required><input name="amount" type="number" min="1" max="<?php echo absint( $sale['final_amount'] ); ?>" required><input name="down_payment" type="number" min="0" value="0" placeholder="Down payment (halalas)"><input name="term_months" type="number" min="0" max="120" value="0" placeholder="Term months"><input name="monthly_payment" type="number" min="0" value="0" placeholder="Monthly payment (halalas)"><label><input name="consent" type="checkbox" value="1" required><?php esc_html_e( 'تم توثيق موافقة العميل', 'auto-dealership-core' ); ?></label><button class="button"><?php esc_html_e( 'إرسال', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?><?php if ( ! $sales ) : ?><tr><td colspan="4"><?php esc_html_e( 'لا توجد عمليات بيع مؤهلة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table><?php endif; ?>
			<h2><?php esc_html_e( 'الطلبات القائمة', 'auto-dealership-core' ); ?></h2><table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'العميل / السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الجهة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المبلغ (هللة)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $requests as $request ) : ?><tr><td><?php echo absint( $request['id'] ); ?></td><td><?php echo esc_html( $request['full_name'] . ' — ' . $request['brand'] . ' ' . $request['model'] ); ?></td><td><?php echo esc_html( $request['provider'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $request['requested_amount'] ) ); ?></td><td><?php echo esc_html( $request['status'] ); ?></td><td><?php if ( current_user_can( 'adc_manage_finance' ) && in_array( $request['status'], array( 'submitted', 'under_review' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_set_finance_status"><input type="hidden" name="id" value="<?php echo absint( $request['id'] ); ?>"><?php wp_nonce_field( 'adc_set_finance_status_' . (int) $request['id'] ); ?><select name="status"><option value="under_review">under_review</option><option value="approved">approved</option><option value="rejected">rejected</option></select><input name="provider_reference" placeholder="<?php esc_attr_e( 'مرجع المزود', 'auto-dealership-core' ); ?>"><input name="decision_reason" maxlength="2000" placeholder="Decision reason"><button class="button"><?php esc_html_e( 'حفظ القرار', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $requests ) : ?><tr><td colspan="6"><?php esc_html_e( 'لا توجد طلبات تمويل.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
		</div>
		<?php
	}

	public static function delivery(): void {
		if ( ! current_user_can( 'adc_approve_delivery' ) && ! current_user_can( 'adc_confirm_vehicle_vin' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية مهام التسليم.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		global $wpdb;
		list( $scope, $scope_args ) = self::branch_sql();
		$approved_sales = array();
		if ( current_user_can( 'adc_approve_delivery' ) ) {
			$sql = 'SELECT s.id,c.full_name,v.brand,v.model FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=s.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.status = \'approved\'' . $scope . ' AND NOT EXISTS (SELECT d.id FROM ' . Schema::table( 'deliveries' ) . ' d WHERE d.sale_id=s.id) ORDER BY s.id DESC LIMIT 100';
			$approved_sales = self::rows( $sql, $scope_args );
		}
		$delivery_sql = 'SELECT d.id,d.sale_id,d.vehicle_id,d.status,d.vin_confirmed_by,d.approved_by,v.vin,v.branch_id,v.brand,v.model,c.full_name FROM ' . Schema::table( 'deliveries' ) . ' d INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=d.vehicle_id INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=d.sale_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=s.customer_id WHERE 1=1' . $scope . ' ORDER BY d.id DESC LIMIT 100';
		$deliveries = self::rows( $delivery_sql, $scope_args );
		?>
		<div class="wrap" dir="rtl"><h1><?php esc_html_e( 'تجهيز وتسليم المركبات', 'auto-dealership-core' ); ?></h1><?php self::queue_notice(); ?>
			<?php if ( current_user_can( 'adc_approve_delivery' ) ) : ?><h2><?php esc_html_e( 'مبيعات جاهزة للتجهيز', 'auto-dealership-core' ); ?></h2><table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'العميل / السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الإجراء', 'auto-dealership-core' ); ?></th></tr></thead><tbody><?php foreach ( $approved_sales as $sale ) : ?><tr><td><?php echo absint( $sale['id'] ); ?></td><td><?php echo esc_html( $sale['full_name'] . ' — ' . $sale['brand'] . ' ' . $sale['model'] ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_prepare_delivery"><input type="hidden" name="sale_id" value="<?php echo absint( $sale['id'] ); ?>"><?php wp_nonce_field( 'adc_prepare_delivery_' . (int) $sale['id'] ); ?><button class="button"><?php esc_html_e( 'بدء التجهيز', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?><?php if ( ! $approved_sales ) : ?><tr><td colspan="3"><?php esc_html_e( 'لا توجد مبيعات جاهزة للتجهيز.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table><?php endif; ?>
			<h2><?php esc_html_e( 'Required delivery documents', 'auto-dealership-core' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Delivery', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Checklist', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Record evidence', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php $document_rows = 0; foreach ( $deliveries as $delivery ) : $checklist = DeliveryService::checklist( (int) $delivery['id'] ); if ( is_wp_error( $checklist ) || ! $checklist['items'] ) { continue; } ++$document_rows; ?><tr><td><?php echo absint( $delivery['id'] ); ?></td><td><ul><?php foreach ( $checklist['items'] as $item ) : ?><li><?php echo $item['complete'] ? '✓ ' : '○ '; echo esc_html( $item['label'] ); ?><?php if ( $item['complete'] ) : ?> — <?php echo esc_html( $item['evidence']['reference'] ); ?><?php endif; ?></li><?php endforeach; ?></ul></td><td><?php if ( in_array( $delivery['status'], array( 'preparing','vin_confirmed' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_record_delivery_document"><input type="hidden" name="id" value="<?php echo absint( $delivery['id'] ); ?>"><?php wp_nonce_field( 'adc_record_delivery_document_' . (int) $delivery['id'] ); ?><select name="document_key"><?php foreach ( $checklist['items'] as $item ) : ?><option value="<?php echo esc_attr( $item['key'] ); ?>"><?php echo esc_html( $item['label'] ); ?></option><?php endforeach; ?></select> <input name="reference" maxlength="190" required placeholder="External reference"> <button class="button"><?php esc_html_e( 'Record document', 'auto-dealership-core' ); ?></button></form><?php else : esc_html_e( 'Documents are locked in this state.', 'auto-dealership-core' ); endif; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $document_rows ) : ?><tr><td colspan="3"><?php esc_html_e( 'No configured document requirements apply to current deliveries.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
			<h2><?php esc_html_e( 'عمليات التسليم', 'auto-dealership-core' ); ?></h2><table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'العميل / السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'VIN', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الإجراء', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $deliveries as $row ) : ?><tr><td><?php echo absint( $row['id'] ); ?></td><td><?php echo esc_html( $row['full_name'] . ' — ' . $row['brand'] . ' ' . $row['model'] ); ?></td><td><?php echo current_user_can( 'adc_view_inventory' ) || current_user_can( 'adc_confirm_vehicle_vin' ) || current_user_can( 'manage_options' ) ? esc_html( $row['vin'] ) : esc_html__( 'محجوب', 'auto-dealership-core' ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td>
			<?php if ( current_user_can( 'adc_confirm_vehicle_vin' ) && 'preparing' === $row['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_confirm_delivery_vin"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_confirm_delivery_vin_' . (int) $row['id'] ); ?><label><?php esc_html_e( 'أدخل VIN بعد مطابقته ميدانيًا', 'auto-dealership-core' ); ?> <input name="vin" required></label><button class="button"><?php esc_html_e( 'تأكيد VIN', 'auto-dealership-core' ); ?></button></form><?php endif; ?>
			<?php if ( current_user_can( 'adc_approve_delivery' ) && 'vin_confirmed' === $row['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_approve_delivery"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_approve_delivery_' . (int) $row['id'] ); ?><button class="button button-primary"><?php esc_html_e( 'اعتماد التسليم', 'auto-dealership-core' ); ?></button></form><?php endif; ?>
			<?php if ( current_user_can( 'adc_approve_delivery' ) && 'approved' === $row['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_release_delivery"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_release_delivery_' . (int) $row['id'] ); ?><button class="button button-primary"><?php esc_html_e( 'إطلاق المركبة', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $deliveries ) : ?><tr><td colspan="5"><?php esc_html_e( 'لا توجد عمليات تسليم.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
		</div>
		<?php
	}

	private static function redirect( string $page, $result ): void {
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	public static function adc_decide_discount(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_decide_discount_' . $id );
		$result = SalesService::decide_discount( $id, 'approve' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::redirect( 'adc-approvals', $result );
	}

	public static function adc_approve_sale(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_approve_sale_' . $id );
		$result = SalesService::approve_sale( $id, sanitize_text_field( wp_unslash( $_POST['invoice_reference'] ?? '' ) ) );
		self::redirect( 'adc-approvals', $result );
	}

	public static function adc_set_finance_status(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_set_finance_status_' . $id );
		$result = SalesService::update_finance_status( $id, sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['provider_reference'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['decision_reason'] ?? '' ) ) );
		self::redirect( 'adc-finance', $result );
	}

	public static function adc_create_finance_request(): void {
		$id = absint( $_POST['sale_id'] ?? 0 ); check_admin_referer( 'adc_create_finance_request_' . $id );
		$result = SalesService::create_finance_request( $id, sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ), absint( $_POST['amount'] ?? 0 ), isset( $_POST['consent'] ), array( 'down_payment'=>absint( $_POST['down_payment'] ?? 0 ), 'term_months'=>absint( $_POST['term_months'] ?? 0 ), 'monthly_payment'=>absint( $_POST['monthly_payment'] ?? 0 ) ) );
		self::redirect( 'adc-finance', $result );
	}

	public static function adc_prepare_delivery(): void {
		$id = absint( $_POST['sale_id'] ?? 0 ); check_admin_referer( 'adc_prepare_delivery_' . $id );
		self::redirect( 'adc-delivery', DeliveryService::prepare( $id ) );
	}

	public static function adc_confirm_delivery_vin(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_confirm_delivery_vin_' . $id );
		self::redirect( 'adc-delivery', DeliveryService::confirm_vin( $id, sanitize_text_field( wp_unslash( $_POST['vin'] ?? '' ) ) ) );
	}

	public static function adc_record_delivery_document(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_record_delivery_document_' . $id );
		self::redirect( 'adc-delivery', DeliveryService::record_document( $id, sanitize_key( wp_unslash( $_POST['document_key'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) ) ) );
	}

	public static function adc_approve_delivery(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_approve_delivery_' . $id );
		self::redirect( 'adc-delivery', DeliveryService::approve( $id ) );
	}

	public static function adc_release_delivery(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_release_delivery_' . $id );
		self::redirect( 'adc-delivery', DeliveryService::release( $id ) );
	}
}
