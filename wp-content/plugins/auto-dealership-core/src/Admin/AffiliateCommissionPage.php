<?php
namespace AutoDealership\Admin;

use AutoDealership\Marketing\AffiliateCommissionService;
use AutoDealership\Marketing\AffiliateCommissionMigration;
use AutoDealership\Marketing\AffiliateCommissionHealth;
use AutoDealership\Marketing\AffiliateCommissionReconciliation;
use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

final class AffiliateCommissionPage {
	public static function boot(): void {
		add_action( 'admin_post_adc_affiliate_commission_rate', array( self::class, 'save_rate' ) );
		add_action( 'admin_post_adc_affiliate_commission_transition', array( self::class, 'transition' ) );
		add_action( 'admin_post_adc_affiliate_commission_migrate', array( self::class, 'migrate' ) );
		add_action( 'admin_post_adc_affiliate_commission_migration_resolve', array( self::class, 'resolve_migration_issue' ) );
		add_action( 'admin_post_adc_affiliate_notification_preferences', array( self::class, 'save_notification_preferences' ) );
		add_action( 'admin_post_adc_affiliate_commission_reconciliation', array( self::class, 'run_reconciliation' ) );
		add_action( 'admin_post_adc_affiliate_commission_fingerprint', array( self::class, 'save_fingerprint_setting' ) );
	}

	private static function text( string $ar, string $en ): string {
		return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar;
	}

	public static function render(): void {
		$can_manage = current_user_can( 'adc_manage_affiliate_commissions' ) || current_user_can( 'manage_options' );
		$can_view_own = current_user_can( 'adc_view_own_affiliate_commissions' );
		if ( ! $can_manage && ! $can_view_own ) { wp_die( esc_html__( 'لا تملك صلاحية عرض العمولات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		echo '<div class="wrap" dir="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl' ) . '"><h1>' . esc_html( self::text( 'صفقات المسوقين بالعمولة', 'Affiliate sales and commissions' ) ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( self::text( 'تم حفظ العملية.', 'Operation saved.' ) ) . '</p></div>'; }
		if ( $can_manage ) { self::render_management(); }
		if ( $can_view_own ) { self::render_own_sales(); }
		echo '</div>';
	}

	private static function render_management(): void {
		$amount = AffiliateCommissionService::configured_amount();
		echo '<h2>' . esc_html( self::text( 'إعداد ومراجعة المالية', 'Finance configuration and review' ) ) . '</h2>';
		echo '<p>' . esc_html( self::text( 'قيمة ثابتة لكل صفقة مكتملة. تغيير القيمة لا يؤثر على العروض التي أُنشئت سابقًا. القيمة صفر توقف ربط إحالات جديدة.', 'A fixed amount per completed sale. Changes do not affect existing quotations. Zero disables new referral attribution.' ) ) . '</p>';
		self::render_summary( AffiliateCommissionService::account_summary( 0 ), self::text( 'ملخص الفروع المتاحة', 'Accessible commission summary' ) );
		self::render_operations_health();
		self::render_migration();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0">';
		wp_nonce_field( 'adc_affiliate_commission_fingerprint' );
		echo '<input type="hidden" name="action" value="adc_affiliate_commission_fingerprint"><label><input type="checkbox" name="enabled" value="1"' . checked( AffiliateCommissionService::fingerprint_enabled(), true, false ) . '> ' . esc_html( self::text( 'تفعيل البصمة الاختيارية للحركات المالية', 'Enable optional financial movement fingerprints' ) ) . '</label> <button class="button">' . esc_html( self::text( 'حفظ إعداد البصمة', 'Save fingerprint setting' ) ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_affiliate_commission_rate' );
		echo '<input type="hidden" name="action" value="adc_affiliate_commission_rate"><input type="hidden" name="adc_money_unit" value="SAR"><label>' . esc_html( self::text( 'العمولة الثابتة (ريال)', 'Fixed commission (SAR)' ) ) . ' <input type="number" min="0" step="0.01" name="amount" value="' . esc_attr( Money::decimal( $amount ) ) . '" required></label> <button class="button button-primary">' . esc_html( self::text( 'حفظ الإعداد', 'Save setting' ) ) . '</button></form>';
		$page = max( 1, absint( $_GET['commission_page'] ?? 1 ) );
		$filters = array( 'page'=>$page, 'per_page'=>25, 'status'=>sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ), 'from'=>sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) ), 'to'=>sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) ) );
		$data = AffiliateCommissionService::list_commissions( $filters );
		if ( is_wp_error( $data ) ) { wp_die( esc_html( $data->get_error_message() ), '', array( 'response'=>(int) ( $data->get_error_data()['status'] ?? 500 ) ) ); }
		$rows = $data['items'];
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="adc-affiliate-commissions"><label>Status <select name="status"><option value="">All</option>';
		foreach ( array( 'pending_review'=>'Pending review','approved'=>'Approved','paid'=>'Paid','void'=>'Voided','paid_sale_returned'=>'Returned sale' ) as $value=>$label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['status'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label> <label>From <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '"></label> <label>To <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '"></label> <button class="button">Filter</button></form>';
		echo '<h3>' . esc_html( self::text( 'سجل الاستحقاقات', 'Commission ledger' ) ) . '</h3>';
		if ( ! $rows ) { echo '<p>' . esc_html( self::text( 'لا توجد استحقاقات حتى الآن.', 'No commission records yet.' ) ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'المسوّق', 'Affiliate' ) ) . '</th><th>' . esc_html( self::text( 'الصفقة', 'Sale' ) ) . '</th><th>' . esc_html( self::text( 'الإحالة', 'Lead' ) ) . '</th><th>' . esc_html( self::text( 'الفرع', 'Branch' ) ) . '</th><th>' . esc_html( self::text( 'العمولة', 'Commission' ) ) . '</th><th>' . esc_html( self::text( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( self::text( 'الإجراء', 'Action' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['display_name'] ?: ( '#' . $row['affiliate_user_id'] ) ) . '</td><td>#' . absint( $row['sale_id'] ) . ' / ' . esc_html( $row['quotation_id'] ) . '</td><td>#' . absint( $row['lead_id'] ) . '</td><td>#' . absint( $row['branch_id'] ) . '</td><td>' . esc_html( Money::display( (int) $row['amount'] ) ) . '</td><td>' . esc_html( self::status_label( $row['status'] ) ) . ( $row['payment_reference'] ? '<br>' . esc_html( $row['payment_reference'] ) : '' ) . '</td><td>';
			if ( 'pending_review' === $row['status'] ) {
				self::action_form( (int) $row['id'], 'approved', self::text( 'اعتماد للاستحقاق', 'Approve as payable' ) );
				self::action_form( (int) $row['id'], 'void', self::text( 'رفض', 'Void' ), true );
			} elseif ( 'approved' === $row['status'] ) {
				self::action_form( (int) $row['id'], 'paid', self::text( 'تسجيل الصرف', 'Record payout' ), false, true );
				self::action_form( (int) $row['id'], 'void', self::text( 'إلغاء الاستحقاق', 'Void payable' ), true );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $rows ) {
			echo '<h4>' . esc_html( self::text( 'مراجع الحركات المالية', 'Financial movement references' ) ) . '</h4><ul>';
			foreach ( $rows as $row ) {
				if ( ! empty( $row['entry_references'] ) ) { echo '<li><strong>#' . absint( $row['id'] ) . '</strong>: <code>' . esc_html( (string) $row['entry_references'] ) . '</code></li>'; }
			}
			echo '</ul>';
		}
		self::render_pager( $data );
	}

	private static function render_migration(): void {
		$state = AffiliateCommissionMigration::status();
		echo '<h3>' . esc_html( self::text( 'ترحيل السجل المالي التاريخي', 'Historical ledger migration' ) ) . '</h3><p>' . esc_html( self::text( 'تُعزل السجلات غير الصحيحة للمراجعة. تصحيح المرجع الأصلي مطلوب قبل إعادة الفحص، ولا يؤدي وسم السجل كمراجع إلى إنشاء رصيد.', 'Invalid records are quarantined for review. Correct the original reference before rechecking; marking a record reviewed never creates a balance.' ) ) . '</p><p>' . esc_html( self::text( 'الحالة', 'Status' ) ) . ': <strong>' . esc_html( (string) $state['status'] ) . '</strong>؛ ' . esc_html( self::text( 'تمت معالجة', 'Processed' ) ) . ' ' . absint( $state['processed'] ) . ' ' . esc_html( self::text( 'من', 'of' ) ) . ' ' . absint( $state['total'] ) . '.</p>';
		if ( 'complete' !== $state['status'] && 'running' !== $state['status'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_affiliate_commission_migrate">';
			wp_nonce_field( 'adc_affiliate_commission_migrate' );
			echo '<button class="button">' . esc_html( self::text( 'بدء أو استئناف الترحيل', 'Start or resume historical migration' ) ) . '</button></form>';
		}
		if ( 'failed' === $state['status'] && $state['error'] ) { echo '<p class="notice notice-error inline">' . esc_html( self::text( 'فشل الترحيل:', 'Migration failed:' ) ) . ' <code>' . esc_html( $state['error'] ) . '</code></p>'; }
		$issue_page = max( 1, absint( $_GET['migration_issue_page'] ?? 1 ) );
		$issue_status = sanitize_key( wp_unslash( $_GET['migration_issue_status'] ?? 'open' ) );
		$issue_type = sanitize_key( wp_unslash( $_GET['migration_issue_type'] ?? '' ) );
		$issues = AffiliateCommissionMigration::issues( array( 'status'=>$issue_status, 'issue_type'=>$issue_type, 'page'=>$issue_page, 'per_page'=>25 ) );
		if ( is_array( $issues ) ) {
			echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="adc-affiliate-commissions"><label>' . esc_html( self::text( 'حالة المراجعة', 'Review status' ) ) . ' <select name="migration_issue_status"><option value="open"' . selected( $issue_status, 'open', false ) . '>' . esc_html( self::text( 'مفتوحة', 'Open' ) ) . '</option><option value="resolved"' . selected( $issue_status, 'resolved', false ) . '>' . esc_html( self::text( 'محلولة', 'Resolved' ) ) . '</option></select></label> <label>' . esc_html( self::text( 'نوع المشكلة', 'Issue type' ) ) . ' <input name="migration_issue_type" value="' . esc_attr( $issue_type ) . '" maxlength="64"></label> <button class="button">' . esc_html( self::text( 'تصفية السجلات', 'Filter issues' ) ) . '</button></form>';
		}
		if ( is_array( $issues ) && $issues['items'] ) { echo '<h4>' . esc_html( self::text( 'سجلات تحتاج مراجعة قبل الترحيل', 'Historical records requiring review' ) ) . '</h4><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html( self::text( 'العمولة', 'Commission' ) ) . '</th><th>' . esc_html( self::text( 'النوع', 'Type' ) ) . '</th><th>' . esc_html( self::text( 'السبب', 'Reason' ) ) . '</th><th>' . esc_html( self::text( 'الإجراء', 'Action' ) ) . '</th></tr></thead><tbody>'; foreach ( $issues['items'] as $issue ) { echo '<tr><td>' . absint( $issue['id'] ) . '</td><td>#' . absint( $issue['commission_id'] ) . '</td><td>' . esc_html( $issue['issue_type'] ) . '</td><td>' . esc_html( $issue['reason'] ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_affiliate_commission_migration_resolve"><input type="hidden" name="issue_id" value="' . absint( $issue['id'] ) . '">' . wp_nonce_field( 'adc_affiliate_commission_migration_resolve_' . absint( $issue['id'] ), '_wpnonce', true, false ) . '<button class="button">' . esc_html( self::text( 'إعادة فحص وإغلاق', 'Recheck and resolve' ) ) . '</button></form></td></tr>'; } echo '</tbody></table>'; }
		if ( is_array( $issues ) ) { self::render_pager( $issues, 'migration_issue_page' ); }
	}

	private static function render_operations_health(): void {
		$health = AffiliateCommissionHealth::snapshot();
		echo '<h3>' . esc_html( self::text( 'جاهزية تشغيل العمولات', 'Affiliate operations readiness' ) ) . '</h3><p><strong>' . esc_html( 'ready' === $health['status'] ? self::text( 'جاهز', 'Ready' ) : self::text( 'يحتاج مراجعة', 'Needs attention' ) ) . '</strong></p><table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'الفحص', 'Check' ) ) . '</th><th>' . esc_html( self::text( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( self::text( 'القيمة', 'Value' ) ) . '</th></tr></thead><tbody>';
		foreach ( $health['checks'] as $check ) { echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td>' . esc_html( $check['ok'] ? self::text( 'سليم', 'OK' ) : self::text( 'يحتاج مراجعة', 'Attention' ) ) . '</td><td>' . esc_html( (string) $check['value'] ) . '</td></tr>'; }
		echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_affiliate_commission_reconciliation">' . wp_nonce_field( 'adc_affiliate_commission_reconciliation', '_wpnonce', true, false ) . '<button class="button">' . esc_html( self::text( 'تشغيل فحص التسوية الآن', 'Run reconciliation now' ) ) . '</button></form>';
	}

	private static function render_pager( array $data, string $parameter = 'commission_page' ): void {
		if ( (int) $data['pages'] < 2 ) { return; }
		$base = remove_query_arg( $parameter ); $links = array();
		if ( (int) $data['page'] > 1 ) { $links[] = '<a class="button" href="' . esc_url( add_query_arg( $parameter, (int) $data['page'] - 1, $base ) ) . '">&laquo; Previous</a>'; }
		$links[] = '<span style="padding:0 8px">Page ' . absint( $data['page'] ) . ' of ' . absint( $data['pages'] ) . ' (' . absint( $data['total'] ) . ' records)</span>';
		if ( (int) $data['page'] < (int) $data['pages'] ) { $links[] = '<a class="button" href="' . esc_url( add_query_arg( $parameter, (int) $data['page'] + 1, $base ) ) . '">Next &raquo;</a>'; }
		echo '<p class="tablenav bottom">' . implode( ' ', $links ) . '</p>';
	}

	private static function render_own_sales(): void {
		self::render_notification_preferences();
		self::render_notifications();
		$own_page = AffiliateCommissionService::own_sales_page( array( 'page'=>max( 1, absint( $_GET['referral_page'] ?? 1 ) ), 'per_page'=>25 ) );
		if ( is_wp_error( $own_page ) ) { wp_die( esc_html( $own_page->get_error_message() ), '', array( 'response'=>(int) ( $own_page->get_error_data()['status'] ?? 500 ) ) ); }
		$rows = $own_page['items'];
		echo '<h2>' . esc_html( self::text( 'صفقاتي المحالة', 'My referred sales' ) ) . '</h2>';
		echo '<p>' . esc_html( self::text( 'تُنشأ مطالبة العمولة بعد إتمام التسليم والسداد الكامل، ولا يستطيع المسوّق اعتمادها أو تسجيل صرفها.', 'A commission claim is created only after delivery and full settlement. Affiliates cannot approve or record their own payouts.' ) ) . '</p>';
		self::render_summary( AffiliateCommissionService::account_summary( get_current_user_id() ), self::text( 'ملخص حسابي', 'My account summary' ) );
		if ( ! $rows ) { echo '<p>' . esc_html( self::text( 'لا توجد صفقات مرتبطة بإحالاتك بعد.', 'No sales linked to your referrals yet.' ) ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'عرض السعر', 'Quotation' ) ) . '</th><th>' . esc_html( self::text( 'حالة البيع', 'Sale status' ) ) . '</th><th>' . esc_html( self::text( 'العمولة', 'Commission' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$sale = $row['sale_id'] ? '#' . absint( $row['sale_id'] ) . ' / ' . self::status_label( $row['sale_status'] ) : self::text( 'لم تبدأ صفقة بعد', 'No sale started' );
			$commission = $row['commission_status'] ? self::status_label( $row['commission_status'] ) . ( null !== $row['commission_amount'] ? ' / ' . Money::display( (int) $row['commission_amount'] ) : '' ) : self::text( 'قيد متابعة البيع', 'Sale in progress' );
			echo '<tr><td>' . esc_html( $row['quote_number'] ) . '</td><td>' . esc_html( $sale ) . '</td><td>' . esc_html( $commission ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_pager( $own_page, 'referral_page' );
	}

	private static function render_notifications(): void {
		$page = \AutoDealership\Operations\FollowUpNotifications::inbox_page( array( 'page'=>max( 1, absint( $_GET['notification_page'] ?? 1 ) ), 'per_page'=>25, 'source_type'=>'affiliate_commission', 'search'=>sanitize_text_field( wp_unslash( $_GET['notification_search'] ?? '' ) ), 'archived'=>!empty( $_GET['notification_archived'] ) ) );
		$alerts = is_array( $page ) ? $page['items'] : array();
		if ( ! $alerts ) { return; }
		echo '<h3>' . esc_html( self::text( 'تنبيهات العمولات', 'Commission notifications' ) ) . '</h3><form method="get"><input type="hidden" name="page" value="adc-affiliate-commissions"><input type="search" name="notification_search" value="' . esc_attr( $_GET['notification_search'] ?? '' ) . '" placeholder="' . esc_attr( self::text( 'بحث برقم العمولة', 'Search by commission number' ) ) . '"><label><input type="checkbox" name="notification_archived" value="1"' . checked( !empty( $_GET['notification_archived'] ), true, false ) . '> ' . esc_html( self::text( 'المؤرشفة', 'Archived' ) ) . '</label> <button class="button">' . esc_html( self::text( 'بحث', 'Search' ) ) . '</button></form><ul class="ul-disc">';
		foreach ( $alerts as $alert ) {
			$id = (int) $alert['id'];
			echo '<li><a href="' . esc_url( \AutoDealership\Operations\FollowUpNotifications::url( $alert ) ) . '">Commission #' . absint( $alert['source_id'] ) . '</a> — ' . esc_html( wp_date( 'Y-m-d H:i', strtotime( $alert['due_at'] . ' UTC' ) ) ) . ' <form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_ack_followup"><input type="hidden" name="id" value="' . $id . '">';
			wp_nonce_field( 'adc_ack_followup_' . $id );
			echo '<button class="button-link">Acknowledge</button></form></li>';
		}
		echo '</ul>';
		if ( is_array( $page ) ) { self::render_pager( $page, 'notification_page' ); }
	}

	private static function render_notification_preferences(): void { $preferences=\AutoDealership\Marketing\AffiliateNotificationPreferences::get(get_current_user_id()); echo '<h3>' . esc_html( self::text( 'إعدادات تنبيهات العمولات', 'Commission notification preferences' ) ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_affiliate_notification_preferences">' . wp_nonce_field( 'adc_affiliate_notification_preferences', '_wpnonce', true, false ) . '<label><input type="checkbox" name="inbox_enabled" value="1"' . checked( !empty($preferences['inbox_enabled']), true, false ) . '> ' . esc_html( self::text('تنبيهات داخلية','Inbox notifications') ) . '</label> <label><input type="checkbox" name="email_enabled" value="1"' . checked( !empty($preferences['email_enabled']), true, false ) . '> ' . esc_html( self::text('البريد الإلكتروني','Email notifications') ) . '</label> <label>' . esc_html( self::text('اللغة','Language') ) . ' <select name="language"><option value="auto"' . selected($preferences['language'],'auto',false) . '>Auto</option><option value="ar"' . selected($preferences['language'],'ar',false) . '>العربية</option><option value="en"' . selected($preferences['language'],'en',false) . '>English</option></select></label><br>'; foreach($preferences['events'] as $event=>$enabled){echo '<label><input type="checkbox" name="events['.esc_attr($event).']" value="1"'.checked(!empty($enabled),true,false).'> '.esc_html($event).'</label> ';} echo '<button class="button">' . esc_html( self::text('حفظ التفضيلات','Save preferences') ) . '</button></form>'; }

	private static function render_summary( $summary, string $title ): void {
		if ( ! is_array( $summary ) ) { return; }
		$cards = array(
			'pending_review'=>self::text( 'قيد المراجعة', 'Pending review' ),
			'approved'=>self::text( 'معتمد للصرف', 'Approved' ),
			'paid'=>self::text( 'مصروف', 'Paid' ),
			'voided'=>self::text( 'ملغى', 'Voided' ),
			'returned'=>self::text( 'مبيعات مرتجعة', 'Returned sales' ),
			'net_ledger'=>self::text( 'صافي الحركات', 'Net ledger' ),
		);
		echo '<h3>' . esc_html( $title ) . ' <small>(' . esc_html( (string) ( $summary['currency'] ?? AffiliateCommissionService::CURRENCY ) ) . ')</small></h3><div class="adc-affiliate-summary">';
		foreach ( $cards as $key=>$label ) { echo '<div class="card"><strong>' . esc_html( Money::display( (int) ( $summary[ $key ] ?? 0 ) ) ) . '</strong><br>' . esc_html( $label ) . '</div>'; }
		echo '</div>';
	}

	private static function action_form( int $id, string $target, string $label, bool $reason = false, bool $reference = false ): void {
		echo '<form class="adc-inline-action-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_affiliate_commission_' . $target . '_' . $id );
		echo '<input type="hidden" name="action" value="adc_affiliate_commission_transition"><input type="hidden" name="commission_id" value="' . $id . '"><input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
		if ( $reason ) { echo '<input type="text" name="reason" maxlength="1000" placeholder="' . esc_attr( self::text( 'سبب القرار مطلوب', 'Reason required' ) ) . '" required>'; }
		if ( $reference ) { echo '<input type="text" name="payment_reference" maxlength="190" placeholder="' . esc_attr( self::text( 'مرجع الصرف', 'Payout reference' ) ) . '" required>'; }
		echo '<button class="button">' . esc_html( $label ) . '</button></form> ';
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'pending_review'=>self::text( 'بانتظار المراجعة', 'Pending review' ),
			'approved'=>self::text( 'مستحقة للصرف', 'Approved for payout' ),
			'paid'=>self::text( 'مصروفة', 'Paid' ),
			'paid_sale_returned'=>self::text( 'مصروفة؛ البيع مرتجع ويتطلب مراجعة مالية', 'Paid; sale returned, financial review required' ),
			'void'=>self::text( 'ملغاة', 'Voided' ),
			'delivered'=>self::text( 'تم التسليم', 'Delivered' ),
		);
		return $labels[$status] ?? $status;
	}

	public static function save_rate(): void {
		check_admin_referer( 'adc_affiliate_commission_rate' );
		Money::require_sar_form();
		$amount = Money::from_sar( wp_unslash( $_POST['amount'] ?? '' ) );
		if ( null === $amount ) { wp_die( esc_html__( 'أدخل قيمة ريال صحيحة بمنزلتين عشريتين كحد أقصى.', 'auto-dealership-core' ), '', array( 'response'=>400 ) ); }
		$result = AffiliateCommissionService::set_configured_amount( $amount );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ) ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-affiliate-commissions', 'saved'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save_fingerprint_setting(): void {
		check_admin_referer( 'adc_affiliate_commission_fingerprint' );
		$result = AffiliateCommissionService::set_fingerprint_enabled( ! empty( $_POST['enabled'] ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ) ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-affiliate-commissions', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function transition(): void {
		$id = absint( $_POST['commission_id'] ?? 0 );
		$target = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) );
		check_admin_referer( 'adc_affiliate_commission_' . $target . '_' . $id );
		$result = AffiliateCommissionService::transition( $id, $target, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['payment_reference'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ) ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-affiliate-commissions', 'saved'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function migrate(): void {
		check_admin_referer( 'adc_affiliate_commission_migrate' );
		$result = AffiliateCommissionMigration::start();
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 500 ) ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-affiliate-commissions', 'migration_started'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function resolve_migration_issue(): void {
		$id = absint( $_POST['issue_id'] ?? 0 ); check_admin_referer( 'adc_affiliate_commission_migration_resolve_' . $id );
		$result = AffiliateCommissionMigration::resolve_issue( $id ); if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>400 ) ); }
		$restart = AffiliateCommissionMigration::start(); if ( is_wp_error( $restart ) ) { wp_die( esc_html( $restart->get_error_message() ), '', array( 'response'=>(int) ( $restart->get_error_data()['status'] ?? 500 ) ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=adc-affiliate-commissions&saved=1' ) ); exit;
	}

	public static function save_notification_preferences(): void {
		check_admin_referer( 'adc_affiliate_notification_preferences' );
		$events = array(); foreach ( array( 'created','approved','paid','void','returned' ) as $event ) { $events[ $event ] = ! empty( $_POST['events'][ $event ] ); }
		$result = \AutoDealership\Marketing\AffiliateNotificationPreferences::save( get_current_user_id(), array( 'inbox_enabled'=>!empty($_POST['inbox_enabled']), 'email_enabled'=>!empty($_POST['email_enabled']), 'language'=>sanitize_key(wp_unslash($_POST['language']??'auto')), 'events'=>$events ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>400 ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=adc-affiliate-commissions&saved=1' ) ); exit;
	}

	public static function run_reconciliation(): void {
		check_admin_referer( 'adc_affiliate_commission_reconciliation' );
		$result = AffiliateCommissionReconciliation::run_now();
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 500 ) ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=adc-affiliate-commissions&reconciled=1' ) ); exit;
	}
}
