<?php
namespace AutoDealership\Admin;

use AutoDealership\Branches\BranchService;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Restricted configuration and branch assignment screens. */
final class SettingsPage {
	private static array $pending_branches = array();
	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', static function (): void {
			if ( 'adc-settings' === ( $_GET['page'] ?? '' ) ) { wp_enqueue_script( 'adc-currency-settings', plugins_url( 'assets/js/currency-settings.js', __DIR__ . '/../../auto-dealership-core.php' ), array(), '1.0.0', true ); }
		} );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_save_settings', array( self::class, 'save' ) );
		add_action( 'admin_post_adc_employee_commission_status', array( self::class, 'employee_commission_status' ) );
		add_action( 'admin_post_adc_save_lead_goals', array( self::class, 'save_lead_goals' ) );
		add_action( 'admin_post_adc_create_branch', array( self::class, 'create_branch' ) );
		add_action( 'show_user_profile', array( self::class, 'branch_field' ) );
		add_action( 'edit_user_profile', array( self::class, 'branch_field' ) );
		add_action( 'user_profile_update_errors', array( self::class, 'validate_branch' ), 30, 3 );
		add_action( 'profile_update', array( self::class, 'save_branch' ), 30 );
	}

	public static function menu(): void {
		add_menu_page( __( 'Dealership Core', 'auto-dealership-core' ), __( 'Dealership Core', 'auto-dealership-core' ), 'manage_options', 'adc-settings', array( self::class, 'render' ), 'dashicons-admin-multisite', 58 );
	}

	public static function sections(): array {
		return array(
			'goals' => array( 'أهداف المبيعات الشهرية', array() ),
			'pricing' => array( 'التسعير والضرائب', array( 'vat_rate_bps', 'vat_percent', 'sales_manager_discount_limit', 'general_manager_discount_limit', 'pricing_fee_amount', 'promotion_code', 'promotion_type', 'promotion_value', 'promotion_starts_at', 'promotion_ends_at', 'seller_name', 'seller_tax_number', 'seller_phone', 'seller_address' ) ),
			'reservations' => array( 'الحجز والعربون', array( 'reservation_hours', 'reservation_deposit_type', 'reservation_deposit_value', 'reservation_refund_hours' ) ),
			'branches' => array( 'فروع التشغيل', array( 'default_branch_id' ) ),
			'crm' => array( 'توزيع العملاء المحتملين', array( 'lead_assignment_mode', 'lead_daily_cap', 'lead_claim_timeout_minutes', 'lead_archive_days', 'lead_stale_days' ) ),
			'delivery' => array( 'سياسة التسليم', array( 'delivery_required_documents' ) ),
			'privacy' => array( 'الاحتفاظ بالبيانات', array( 'privacy_retention_days' ) ),
			'catalog' => array( 'مصدر الكتالوج', array( 'public_catalog_mode' ) ),
		);
	}

	private static function section(): string {
		$value = sanitize_key( wp_unslash( $_GET['section'] ?? 'pricing' ) );
		return array_key_exists( $value, self::sections() ) ? $value : 'pricing';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية الوصول.', 'auto-dealership-core' ) );
		}
		$section = self::section();
		$fields = self::sections()[$section][1];
		$rate = min( 10000, absint( get_option( 'adc_vat_rate_bps', 0 ) ) );
		$hours = min( 168, max( 1, absint( get_option( 'adc_reservation_hours', 24 ) ) ) );
		$discount = absint( get_option( 'adc_sales_manager_discount_limit', 0 ) );
		$general_discount = absint( get_option( 'adc_general_manager_discount_limit', PHP_INT_MAX ) );
		$fee = absint( get_option( 'adc_pricing_fee_amount', 0 ) );
		$promotion_type = sanitize_key( (string) get_option( 'adc_promotion_type', 'none' ) );
		$deposit_type = sanitize_key( (string) get_option( 'adc_reservation_deposit_type', 'none' ) );
		$required_documents = (array) get_option( 'adc_delivery_required_documents', array() );
		$document_labels = array( 'invoice'=>'Invoice', 'customer_identity'=>'Customer identity', 'vehicle_registration'=>'Vehicle registration', 'insurance'=>'Insurance', 'handover_form'=>'Signed handover form', 'finance_clearance'=>'Finance clearance' );
		$branches = BranchService::public_list();
		$default_branch = absint( get_option( 'adc_default_branch_id', 0 ) );
		$lead_assignment_mode = sanitize_key( (string) get_option( 'adc_lead_assignment_mode', 'manual' ) );
		$lead_claim_timeout = min( 1440, max( 5, absint( get_option( 'adc_lead_claim_timeout_minutes', 30 ) ) ) );
		$lead_daily_cap = min( 500, absint( get_option( 'adc_lead_daily_cap', 0 ) ) );
		$lead_archive_days = min( 3650, absint( get_option( 'adc_lead_archive_days', 30 ) ) );
		$lead_stale_days = min( 365, max( 1, absint( get_option( 'adc_lead_stale_days', 7 ) ) ) );
		$employee_commission_type = sanitize_key( (string) get_option( 'adc_employee_commission_type', 'none' ) );
		$employee_commission_value = absint( get_option( 'adc_employee_commission_value', 0 ) );
		$retention_days = absint( get_option( 'adc_privacy_retention_days', 0 ) );
		$catalog_mode = PublicCatalog::mode();
		$catalog_readiness = PublicCatalog::readiness();
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<h1><?php esc_html_e( 'إعدادات منصة المعرض', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'القيم المالية بالريال السعودي. راجع إعدادات الضرائب وسياسات الحجز والخصومات مع مسؤول الشركة قبل التشغيل.', 'auto-dealership-core' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ الإعدادات.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر حفظ الإعدادات وتوثيقها؛ أُعيدت القيم السابقة.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['branch_created'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم إنشاء الفرع.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['branch_error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر إنشاء الفرع. تحقق من صحة الرمز وعدم تكراره.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'أقسام الإعدادات', 'auto-dealership-core' ); ?>"><?php foreach ( self::sections() as $key => $definition ) : ?><a class="nav-tab <?php echo $key === $section ? 'nav-tab-active' : ''; ?>" <?php if ( $key === $section ) { echo 'aria-current="page"'; } ?> href="<?php echo esc_url( add_query_arg( array( 'page' => 'adc-settings', 'section' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( $definition[0], 'auto-dealership-core' ); ?></a><?php endforeach; ?></nav>
			<h2><?php echo esc_html__( self::sections()[$section][0], 'auto-dealership-core' ); ?></h2>
			<?php if ( 'goals' === $section ) { self::render_sales_goals_page(); echo '</div>'; return; } ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_save_settings"><input type="hidden" name="settings_section" value="<?php echo esc_attr( $section ); ?>"><input type="hidden" name="adc_money_unit" value="SAR">
				<?php wp_nonce_field( 'adc_save_settings' ); ?>
				<table class="form-table"><tbody>
					<?php if ( in_array( 'vat_rate_bps', $fields, true ) ) : ?>
					<tr><th scope="row"><?php echo esc_html( \AutoDealership\Core\Localization::language()==='en'?'Vehicle profit margin':'هامش ربح السيارات' ); ?></th><td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=adc-profit-margin')); ?>"><?php echo esc_html( \AutoDealership\Core\Localization::language()==='en'?'Manage margin policy':'إدارة سياسة الهامش' ); ?></a></td></tr>
					<tr><th scope="row"><label for="adc_vat_rate_bps"><?php esc_html_e( 'نسبة الضريبة (%)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_vat_rate_bps" name="vat_percent" type="number" min="0" max="100" step="0.01" value="<?php echo esc_attr( rtrim( rtrim( \AutoDealership\Pricing\Money::decimal( $rate ), '0' ), '.' ) ); ?>"><p class="description"><?php echo esc_html__( 'أدخل النسبة مباشرة، مثل 15 لضريبة 15%. تضاف تلقائيًا إلى أسعار السيارات المنشورة.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'reservation_hours', $fields, true ) ) : ?>
						<tr><th scope="row"><label for="adc_reservation_hours"><?php esc_html_e( 'مدة الحجز بالساعات', 'auto-dealership-core' ); ?></label></th><td><input id="adc_reservation_hours" name="reservation_hours" type="number" min="1" max="168" value="<?php echo esc_attr( $hours ); ?>"></td></tr>
						<tr><th scope="row"><label for="adc_reservation_refund_hours"><?php echo esc_html( 'en' === \AutoDealership\Core\Localization::language() ? 'Deposit refund processing target (hours)' : 'مهلة معالجة استرداد العربون (ساعات)' ); ?></label></th><td><input id="adc_reservation_refund_hours" name="reservation_refund_hours" type="number" min="1" max="720" value="<?php echo esc_attr( get_option( 'adc_reservation_refund_hours', 72 ) ); ?>"><p><?php echo esc_html( 'en' === \AutoDealership\Core\Localization::language() ? 'Measured from cancellation or expiry; review the approved agreement before publication.' : 'تُحسب من الإلغاء أو الانتهاء؛ راجع الاتفاق المعتمد قبل النشر.' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'sales_manager_discount_limit', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_discount_limit"><?php esc_html_e( 'أعلى خصم لمدير المبيعات (ريال)', 'auto-dealership-core' ); ?></label></th><td><input step="0.01" id="adc_discount_limit" name="sales_manager_discount_limit" type="number" min="0" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $discount ) ); ?>"><p class="description"><?php echo esc_html__( 'أي خصم أعلى من الحد يتطلب صلاحية المدير العام. صفر يحول كل طلب خصم للمدير العام.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'default_branch_id', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_default_branch_id"><?php esc_html_e( 'الفرع الافتراضي للطلبات الواردة', 'auto-dealership-core' ); ?></label></th><td><select id="adc_default_branch_id" name="default_branch_id"><option value="0"><?php esc_html_e( 'بدون فرع — يتطلب إسنادًا يدويًا', 'auto-dealership-core' ); ?></option><?php foreach ( $branches as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $default_branch, (int) $branch['id'] ); ?>><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'city', (string) $branch['city'] ) ); ?></option><?php endforeach; ?></select></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'lead_assignment_mode', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_lead_assignment_mode"><?php esc_html_e( 'طريقة إسناد الفرص', 'auto-dealership-core' ); ?></label></th><td><select id="adc_lead_assignment_mode" name="lead_assignment_mode"><option value="manual" <?php selected( $lead_assignment_mode, 'manual' ); ?>><?php esc_html_e( 'استلام ذاتي: الموظف يستلم من قائمة الفرع المشتركة (ويمكن للمدير الإسناد)', 'auto-dealership-core' ); ?></option><option value="manager" <?php selected( $lead_assignment_mode, 'manager' ); ?>><?php esc_html_e( 'إسناد عبر مدير المبيعات فقط', 'auto-dealership-core' ); ?></option><option value="round_robin" <?php selected( $lead_assignment_mode, 'round_robin' ); ?>><?php esc_html_e( 'توزيع آلي متوازن حسب عدد فرص كل موظف اليوم', 'auto-dealership-core' ); ?></option></select><p class="description"><?php esc_html_e( 'في التوزيع الآلي تُسند الفرصة إلى الموظف الأقل فرصًا اليوم داخل الفرع ثم الأقدم استلامًا، وتبقى الفرص الزائدة في القائمة حتى يتوفر موظف دون الحد اليومي. يستطيع مدير المبيعات الإسناد يدويًا في كل الأوضاع.', 'auto-dealership-core' ); ?></p></td></tr>
					<tr><th scope="row"><label for="adc_lead_daily_cap"><?php esc_html_e( 'الحد الأقصى للفرص الجديدة لكل موظف يوميًا', 'auto-dealership-core' ); ?></label></th><td><input id="adc_lead_daily_cap" name="lead_daily_cap" type="number" min="0" max="500" value="<?php echo esc_attr( $lead_daily_cap ); ?>"><p class="description"><?php esc_html_e( 'يُطبَّق على التوزيع الآلي والاستلام الذاتي؛ صفر يعني بلا حد. اليوم بحسب توقيت الموقع.', 'auto-dealership-core' ); ?></p>
					<?php
					$workload_staff = get_users( array( 'role' => 'dealership_sales', 'number' => 100, 'orderby' => 'display_name' ) );
					if ( $workload_staff ) :
						?>
						<table class="widefat striped adc-settings-cap-table"><thead><tr><th><?php esc_html_e( 'موظف المبيعات', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'فرص اليوم', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
						<?php foreach ( $workload_staff as $workload_user ) : ?><tr><td><?php echo esc_html( $workload_user->display_name ); ?></td><td><?php echo esc_html( number_format_i18n( \AutoDealership\Leads\LeadService::leads_received_today( (int) $workload_user->ID ) ) . ( $lead_daily_cap ? ' / ' . number_format_i18n( $lead_daily_cap ) : '' ) ); ?></td></tr><?php endforeach; ?>
						</tbody></table>
					<?php endif; ?></td></tr>
					<tr><th scope="row"><label for="adc_lead_claim_timeout_minutes"><?php esc_html_e( 'مهلة المطالبة بالدقائق', 'auto-dealership-core' ); ?></label></th><td><input id="adc_lead_claim_timeout_minutes" name="lead_claim_timeout_minutes" type="number" min="5" max="1440" value="<?php echo esc_attr( $lead_claim_timeout ); ?>"><p class="description"><?php esc_html_e( 'تُعاد الفرصة المسندة إلى القائمة المشتركة إذا لم يُسجّل الموظف أول تواصل قبل انتهاء المهلة؛ من 5 إلى 1440 دقيقة.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'employee_commission_type', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_employee_commission_type"><?php esc_html_e( 'عمولة موظف المبيعات', 'auto-dealership-core' ); ?></label></th><td><select id="adc_employee_commission_type" name="employee_commission_type"><option value="none" <?php selected( $employee_commission_type, 'none' ); ?>><?php esc_html_e( 'معطلة', 'auto-dealership-core' ); ?></option><option value="fixed" <?php selected( $employee_commission_type, 'fixed' ); ?>><?php esc_html_e( 'مبلغ ثابت لكل بيع', 'auto-dealership-core' ); ?></option><option value="percentage" <?php selected( $employee_commission_type, 'percentage' ); ?>><?php esc_html_e( 'نسبة من مبلغ البيع', 'auto-dealership-core' ); ?></option></select> <input id="adc_employee_commission_value" name="employee_commission_value" type="number" min="0" max="100000000" step="<?php echo 'percentage' === $employee_commission_type ? '1' : '0.01'; ?>" value="<?php echo esc_attr( 'fixed' === $employee_commission_type ? \AutoDealership\Pricing\Money::decimal( $employee_commission_value ) : $employee_commission_value ); ?>"><p class="description"><?php esc_html_e( 'المبلغ بالريال، أو النسبة بنقاط الأساس؛ 1500 تعني 15%. تحفظ العمولة وقت إغلاق البيع ولا تتغير بتغيير الإعداد لاحقًا.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'lead_archive_days', $fields, true ) ) : ?><tr><th scope="row"><label for="adc_lead_archive_days"><?php esc_html_e( 'مدة أرشفة الفرص المغلقة (يوم)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_lead_archive_days" name="lead_archive_days" type="number" min="0" max="3650" value="<?php echo esc_attr( $lead_archive_days ); ?>"><p class="description"><?php esc_html_e( 'تُخفى الفرص الناجحة والخاسرة الأقدم من هذه المدة من العرض الافتراضي، مع إمكانية عرضها من فلتر «المؤرشفة». الصفر يعطل الأرشفة.', 'auto-dealership-core' ); ?></p></td></tr><?php endif; ?>
					<?php if ( in_array( 'lead_stale_days', $fields, true ) ) : ?><tr><th scope="row"><label for="adc_lead_stale_days"><?php esc_html_e( 'مدة اعتبار الفرصة راكدة (يوم)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_lead_stale_days" name="lead_stale_days" type="number" min="1" max="365" value="<?php echo esc_attr( $lead_stale_days ); ?>"><p class="description"><?php esc_html_e( 'يرسل النظام تنبيهًا عند عدم وجود نشاط أو متابعة للفرصة خلال هذه المدة، مع تصعيد التنبيه للمدير.', 'auto-dealership-core' ); ?></p></td></tr><?php endif; ?>
					<?php if ( in_array( 'privacy_retention_days', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_privacy_retention_days"><?php esc_html_e( 'مدة الاحتفاظ ببيانات الهوية (يوم)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_privacy_retention_days" name="privacy_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( $retention_days ); ?>"><p class="description"><?php esc_html_e( '0 يعطل الإخفاء المجدول. أي قيمة مفعلة يجب أن تكون بين 30 و3650 يومًا وبعد اعتماد السياسة النظامية.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'public_catalog_mode', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_public_catalog_mode"><?php esc_html_e( 'مصدر الكتالوج العام', 'auto-dealership-core' ); ?></label></th><td><select id="adc_public_catalog_mode" name="public_catalog_mode"><option value="compatibility" <?php selected( $catalog_mode, PublicCatalog::MODE_COMPATIBILITY ); ?>><?php esc_html_e( 'توافق تدريجي مع بيانات القالب', 'auto-dealership-core' ); ?></option><option value="models" <?php selected( $catalog_mode, PublicCatalog::MODE_MODELS ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::language() === 'en' ? 'Grouped brand/model stock' : 'مخزون مجمع حسب الماركة والطراز' ); ?></option><option value="authoritative" <?php selected( $catalog_mode, PublicCatalog::MODE_AUTHORITATIVE ); ?> <?php disabled( ! $catalog_readiness['ready'] && PublicCatalog::MODE_AUTHORITATIVE !== $catalog_mode ); ?>><?php esc_html_e( 'المخزون التشغيلي المعتمد', 'auto-dealership-core' ); ?></option></select><p class="description"><?php esc_html_e( 'فعّل المصدر التشغيلي بعد ربط السيارات المنشورة والتحقق من الترحيل. في هذا الوضع لا تظهر أي سيارة غير مرتبطة أو غير متاحة أو تابعة لفرع غير نشط.', 'auto-dealership-core' ); ?></p><p class="description"><?php printf( esc_html__( 'جاهزية التحويل: سيارات تشغيلية %1$d، مؤهلة للنشر %2$d، منشورات سيارات %3$d، منشورات غير مرتبطة %4$d، خرائط مكررة %5$d، روابط غير صالحة %6$d.', 'auto-dealership-core' ), absint( $catalog_readiness['operational'] ), absint( $catalog_readiness['eligible'] ), absint( $catalog_readiness['published_posts'] ), absint( $catalog_readiness['unmapped_published_posts'] ), absint( $catalog_readiness['duplicate_mappings'] ), absint( $catalog_readiness['invalid_mappings'] ) ); ?></p><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=adc-catalog-cutover' ) ); ?>"><?php esc_html_e( 'فتح مساحة مطابقة الكتالوج', 'auto-dealership-core' ); ?></a></p></td></tr>
					<?php endif; ?>

					<?php if ( in_array( 'general_manager_discount_limit', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_general_discount_limit"><?php esc_html_e( 'General manager discount ceiling (SAR)', 'auto-dealership-core' ); ?></label></th><td><input step="0.01" id="adc_general_discount_limit" name="general_manager_discount_limit" type="number" min="0" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $general_discount ) ); ?>"></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'pricing_fee_amount', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_pricing_fee_amount"><?php esc_html_e( 'Fixed quote fee (SAR)', 'auto-dealership-core' ); ?></label></th><td><input step="0.01" id="adc_pricing_fee_amount" name="pricing_fee_amount" type="number" min="0" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $fee ) ); ?>"></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'promotion_code', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Active promotion', 'auto-dealership-core' ); ?></th><td><input name="promotion_code" aria-label="<?php esc_attr_e( 'Promotion code', 'auto-dealership-core' ); ?>" maxlength="64" placeholder="<?php echo esc_attr__( 'code', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_promotion_code', '' ) ); ?>"> <select name="promotion_type" aria-label="<?php esc_attr_e( 'نوع العرض الترويجي', 'auto-dealership-core' ); ?>"><?php foreach ( array( 'none','fixed','percentage' ) as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $promotion_type, $type ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $type ) ); ?></option><?php endforeach; ?></select> <input step="0.01" data-currency-value name="promotion_value" aria-label="<?php esc_attr_e( 'قيمة العرض الترويجي', 'auto-dealership-core' ); ?>" type="number" min="0" value="<?php echo esc_attr( ( 'fixed' === $promotion_type ? \AutoDealership\Pricing\Money::decimal( (string) get_option( 'adc_promotion_value', 0 ) ) : absint( get_option( 'adc_promotion_value', 0 ) ) ) ); ?>"><p><label><?php esc_html_e( 'Starts', 'auto-dealership-core' ); ?> <input name="promotion_starts_at" type="date" value="<?php echo esc_attr( get_option( 'adc_promotion_starts_at', '' ) ); ?>"></label> <label><?php esc_html_e( 'Ends', 'auto-dealership-core' ); ?> <input name="promotion_ends_at" type="date" value="<?php echo esc_attr( get_option( 'adc_promotion_ends_at', '' ) ); ?>"></label></p><p class="description"><?php esc_html_e( 'Fixed amounts use SAR; percentage values use basis points; 1500 means 15%.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'reservation_deposit_type', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Reservation deposit policy', 'auto-dealership-core' ); ?></th><td><select name="reservation_deposit_type"><?php foreach ( array( 'none','fixed','percentage' ) as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $deposit_type, $type ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $type ) ); ?></option><?php endforeach; ?></select> <input step="0.01" data-currency-value name="reservation_deposit_value" type="number" min="0" value="<?php echo esc_attr( ( 'fixed' === $deposit_type ? \AutoDealership\Pricing\Money::decimal( (string) get_option( 'adc_reservation_deposit_value', 0 ) ) : absint( get_option( 'adc_reservation_deposit_value', 0 ) ) ) ); ?>"><p class="description"><?php esc_html_e( 'Fixed amounts use SAR; percentage values use basis points.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'seller_name', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Seller identity on quotations', 'auto-dealership-core' ); ?></th><td><p><input class="regular-text" name="seller_name" aria-label="<?php esc_attr_e( 'Name', 'auto-dealership-core' ); ?>" placeholder="<?php echo esc_attr__( 'Name', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_seller_name', get_bloginfo( 'name' ) ) ); ?>"></p><p><input class="regular-text" name="seller_tax_number" aria-label="<?php esc_attr_e( 'Tax number', 'auto-dealership-core' ); ?>" placeholder="<?php echo esc_attr__( 'Tax number', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_seller_tax_number', '' ) ); ?>"></p><p><input class="regular-text" name="seller_phone" aria-label="<?php esc_attr_e( 'Phone', 'auto-dealership-core' ); ?>" placeholder="<?php echo esc_attr__( 'Phone', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_seller_phone', '' ) ); ?>"></p><textarea class="large-text" name="seller_address" aria-label="<?php esc_attr_e( 'Address', 'auto-dealership-core' ); ?>" rows="2" placeholder="<?php echo esc_attr__( 'Address', 'auto-dealership-core' ); ?>"><?php echo esc_textarea( get_option( 'adc_seller_address', '' ) ); ?></textarea></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'delivery_required_documents', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Required delivery documents', 'auto-dealership-core' ); ?></th><td><?php foreach ( $document_labels as $key=>$label ) : ?><label class="adc-check"><input type="checkbox" name="delivery_required_documents[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $required_documents, true ) ); ?>> <?php echo esc_html__( $label, 'auto-dealership-core' ); ?></label><?php endforeach; ?><p class="description"><?php esc_html_e( 'Selected documents block delivery approval and release until evidence references are recorded.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
				</tbody></table>
				<?php submit_button( __( 'حفظ الإعدادات', 'auto-dealership-core' ) ); ?>
			</form>
			<?php if ( 'branches' === $section ) : ?>
			<hr><h2><?php esc_html_e( 'إضافة فرع', 'auto-dealership-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_create_branch">
				<?php wp_nonce_field( 'adc_create_branch' ); ?>
				<p><label><?php esc_html_e( 'رمز الفرع', 'auto-dealership-core' ); ?> <input name="code" maxlength="32" required></label></p>
				<p><label><?php esc_html_e( 'اسم الفرع', 'auto-dealership-core' ); ?> <input name="name" maxlength="190" required></label></p>
				<p><label><?php esc_html_e( 'المدينة', 'auto-dealership-core' ); ?> <input name="city" maxlength="100"></label></p>
				<p><label><?php esc_html_e( 'العنوان', 'auto-dealership-core' ); ?> <textarea name="address" rows="3"></textarea></label></p>
				<?php submit_button( __( 'إنشاء الفرع', 'auto-dealership-core' ), 'secondary' ); ?>
			</form>
			<h2><?php esc_html_e( 'الفروع النشطة', 'auto-dealership-core' ); ?></h2>
			<ul><?php foreach ( $branches as $branch ) : ?><li><?php echo esc_html( $branch['code'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) . ' (' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'city', (string) $branch['city'] ) . ' )' ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_employee_commissions_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية الوصول.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$type = sanitize_key( (string) get_option( 'adc_employee_commission_type', 'none' ) ); $value = absint( get_option( 'adc_employee_commission_value', 0 ) );
		echo '<div class="wrap" dir="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl' ) . '"><h1>' . esc_html__( 'عمولات موظفي المبيعات', 'auto-dealership-core' ) . '</h1><p>' . esc_html__( 'إدارة قاعدة العمولة ومراجعة واعتماد وصرف استحقاقات موظفي المبيعات.', 'auto-dealership-core' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_save_settings"><input type="hidden" name="settings_section" value="crm"><input type="hidden" name="adc_money_unit" value="SAR">'; wp_nonce_field( 'adc_save_settings' );
		echo '<table class="form-table"><tr><th><label for="adc_employee_commission_type">' . esc_html__( 'قاعدة العمولة', 'auto-dealership-core' ) . '</label></th><td><select id="adc_employee_commission_type" name="employee_commission_type"><option value="none" ' . selected( $type, 'none', false ) . '>' . esc_html__( 'معطلة', 'auto-dealership-core' ) . '</option><option value="fixed" ' . selected( $type, 'fixed', false ) . '>' . esc_html__( 'مبلغ ثابت لكل بيع', 'auto-dealership-core' ) . '</option><option value="percentage" ' . selected( $type, 'percentage', false ) . '>' . esc_html__( 'نسبة من مبلغ البيع', 'auto-dealership-core' ) . '</option></select> <input name="employee_commission_value" type="number" min="0" step="' . ( 'percentage' === $type ? '1' : '0.01' ) . '" value="' . esc_attr( 'fixed' === $type ? \AutoDealership\Pricing\Money::decimal( $value ) : $value ) . '"><p class="description">' . esc_html__( 'النسبة بنقاط الأساس؛ 1500 تعني 15%. تحفظ العمولة عند إغلاق البيع.', 'auto-dealership-core' ) . '</p></td></tr></table>'; submit_button( __( 'حفظ سياسة العمولة', 'auto-dealership-core' ) ); echo '</form>';
		self::render_employee_commissions(); echo '</div>';
	}

	public static function render_sales_goals_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية الوصول.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		?><p><?php esc_html_e( 'حدد قيمة المبيعات المستهدفة لكل موظف. يظهر الإنجاز تلقائيًا في لوحة CRM ويُحتسب من المبيعات المغلقة في الشهر الحالي.', 'auto-dealership-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_save_lead_goals"><?php wp_nonce_field( 'adc_save_lead_goals' ); ?>
		<table class="widefat striped adc-settings-goal-table"><thead><tr><th><?php esc_html_e( 'الموظف', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الهدف الشهري (SAR)', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php $goal_staff = get_users( array( 'role' => 'dealership_sales', 'number' => 100, 'orderby' => 'display_name' ) ); foreach ( $goal_staff as $goal_user ) : ?><tr><td><?php echo esc_html( $goal_user->display_name ); ?></td><td><input type="number" min="0" step="0.01" name="goals[<?php echo absint( $goal_user->ID ); ?>]" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( absint( get_user_meta( $goal_user->ID, 'adc_monthly_sales_goal', true ) ) ) ); ?>"></td></tr><?php endforeach; ?>
		<?php if ( ! $goal_staff ) : ?><tr><td colspan="2"><?php esc_html_e( 'لا يوجد موظفو مبيعات حاليًا.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
		<?php submit_button( __( 'حفظ الأهداف', 'auto-dealership-core' ), 'primary' ); ?></form><?php
	}

	private static function render_employee_commissions(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT s.id,s.owner_user_id,s.sale_amount,s.employee_commission_amount,s.employee_commission_status,s.created_at,u.display_name,v.stock_number FROM ' . \AutoDealership\Database\Schema::table( 'sales' ) . ' s LEFT JOIN ' . $wpdb->users . ' u ON u.ID=s.owner_user_id LEFT JOIN ' . \AutoDealership\Database\Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.employee_commission_amount>0 ORDER BY s.id DESC LIMIT 200', ARRAY_A ) ?: array();
		echo '<hr><h2>' . esc_html__( 'سجل عمولات موظفي المبيعات', 'auto-dealership-core' ) . '</h2><p class="description">' . esc_html__( 'تُحسب العمولة عند إغلاق البيع وفق الإعداد الحالي، وتبقى قيمة البيع القديمة ثابتة عند تغيير الإعداد.', 'auto-dealership-core' ) . '</p>';
		if ( ! $rows ) { echo '<p>' . esc_html__( 'لا توجد عمولات مسجلة حتى الآن.', 'auto-dealership-core' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'البيع', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الموظف', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'السيارة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المبلغ', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'العمولة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الحالة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الإجراء', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		$labels = array( 'pending_review'=>'بانتظار المراجعة', 'approved'=>'معتمدة للصرف', 'paid'=>'مصروفة', 'void'=>'ملغاة', 'disabled'=>'معطلة' );
		foreach ( $rows as $row ) {
			$status = (string) $row['employee_commission_status'];
			echo '<tr><td>#' . absint( $row['id'] ) . '</td><td>' . esc_html( $row['display_name'] ?: '#' . $row['owner_user_id'] ) . '</td><td>' . esc_html( $row['stock_number'] ?: '—' ) . '</td><td>' . esc_html( \AutoDealership\Pricing\Money::display( (int) $row['sale_amount'] ) ) . '</td><td>' . esc_html( \AutoDealership\Pricing\Money::display( (int) $row['employee_commission_amount'] ) ) . '</td><td>' . esc_html( $labels[$status] ?? $status ) . '</td><td>';
			if ( 'pending_review' === $status ) { self::commission_action( (int) $row['id'], 'approved', 'اعتماد العمولة' ); self::commission_action( (int) $row['id'], 'void', 'إلغاء العمولة', true ); }
			elseif ( 'approved' === $status ) { self::commission_action( (int) $row['id'], 'paid', 'تسجيل الصرف', false, true ); self::commission_action( (int) $row['id'], 'void', 'إلغاء العمولة', true ); }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function commission_action( int $sale_id, string $target, string $label, bool $reason = false, bool $reference = false ): void {
		echo '<form class="adc-inline-action-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adc_employee_commission_' . $target . '_' . $sale_id ); echo '<input type="hidden" name="action" value="adc_employee_commission_status"><input type="hidden" name="sale_id" value="' . absint( $sale_id ) . '"><input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
		if ( $reason ) { echo '<input type="text" name="reason" maxlength="500" required placeholder="' . esc_attr__( 'السبب', 'auto-dealership-core' ) . '">'; }
		if ( $reference ) { echo '<input type="text" name="payment_reference" maxlength="100" required placeholder="' . esc_attr__( 'مرجع الصرف', 'auto-dealership-core' ) . '">'; }
		echo '<button class="button">' . esc_html__( $label, 'auto-dealership-core' ) . '</button></form> ';
	}

	public static function employee_commission_status(): void {
		$sale_id = absint( $_POST['sale_id'] ?? 0 ); $target = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) ); check_admin_referer( 'adc_employee_commission_' . $target . '_' . $sale_id );
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $target, array( 'approved','paid','void' ), true ) ) { wp_die( esc_html__( 'عملية العمولة غير مسموحة.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT employee_commission_status FROM ' . \AutoDealership\Database\Schema::table( 'sales' ) . ' WHERE id=%d', $sale_id ), ARRAY_A ); $expected = 'approved' === $target ? 'pending_review' : ( 'paid' === $target ? 'approved' : ( in_array( (string) ( $row['employee_commission_status'] ?? '' ), array( 'pending_review','approved' ), true ) ? (string) $row['employee_commission_status'] : '' ) );
		if ( ! $row || '' === $expected || (string) $row['employee_commission_status'] !== $expected || ( 'paid' === $target && '' === trim( (string) ( $_POST['payment_reference'] ?? '' ) ) ) ) { wp_die( esc_html__( 'حالة العمولة لا تسمح بهذا الإجراء.', 'auto-dealership-core' ), '', array( 'response'=>409 ) ); }
		$changes = array( 'employee_commission_status'=>$target, 'updated_at'=>current_time( 'mysql', true ) ); $formats = array( '%s','%s' ); if ( 'paid' === $target ) { $changes['employee_commission_reference'] = sanitize_text_field( wp_unslash( $_POST['payment_reference'] ?? '' ) ); $formats[] = '%s'; }
		$ok = $wpdb->update( \AutoDealership\Database\Schema::table( 'sales' ), $changes, array( 'id'=>$sale_id, 'employee_commission_status'=>$expected ), $formats, array( '%d','%s' ) );
		if ( 1 !== $ok || ! \AutoDealership\Audit\AuditLog::record( 'employee_commission.' . $target, 'sale', $sale_id, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? $_POST['payment_reference'] ?? '' ) ), array( 'status'=>$expected ), array( 'status'=>$target ) ) ) { wp_die( esc_html__( 'تعذر حفظ قرار العمولة.', 'auto-dealership-core' ), '', array( 'response'=>500 ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-employee-commissions','updated'=>'1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function save(): void {
		\AutoDealership\Pricing\Money::require_sar_form();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية حفظ الإعدادات.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_save_settings' );
		$section = isset( $_POST['settings_section'] ) ? sanitize_key( wp_unslash( $_POST['settings_section'] ) ) : '';
		if ( $section && ! isset( self::sections()[$section] ) ) { wp_die( esc_html__( 'قسم إعدادات غير صالح.', 'auto-dealership-core' ), '', array( 'response' => 400 ) ); }
		$input = wp_unslash( $_POST );
		if ( ( ! $section || 'pricing' === $section ) && ! isset( $input['vat_percent'] ) ) { wp_die( esc_html__( 'Reload the settings form before entering the VAT percentage.', 'auto-dealership-core' ), '', array( 'response'=>400 ) ); }
		if ( $section ) {
			$input = array_intersect_key( $input, array_flip( self::sections()[$section][1] ) );
			if ( 'delivery' === $section ) { $input['delivery_required_documents'] = $input['delivery_required_documents'] ?? array(); }
		}
		foreach ( array( 'promotion','reservation_deposit' ) as $policy ) {
			if ( 'percentage' === ( $_POST[$policy . '_type'] ?? '' ) && null === \AutoDealership\Pricing\Money::parse( wp_unslash( $_POST[$policy . '_value'] ?? '' ) ) ) {
				wp_die( esc_html__( 'Percentage values use basis points; 1500 means 15%.', 'auto-dealership-core' ), '', array( 'response'=>400 ) );
			}
		}
		$values = \AutoDealership\Pricing\Money::form_values( $input, array_merge( array( 'sales_manager_discount_limit','general_manager_discount_limit','pricing_fee_amount' ), 'fixed' === ( $_POST['promotion_type'] ?? '' ) ? array( 'promotion_value' ) : array(), 'fixed' === ( $_POST['reservation_deposit_type'] ?? '' ) ? array( 'reservation_deposit_value' ) : array(), 'fixed' === ( $_POST['employee_commission_type'] ?? '' ) ? array( 'employee_commission_value' ) : array() ) );
		$result = $section ? ConfigurationService::update_section( $values, self::sections()[$section][1] ) : ConfigurationService::update( $values );
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'updated', '1', add_query_arg( 'section', $section ?: 'pricing', admin_url( 'admin.php?page=adc-settings' ) ) ) );
		exit;
	}

	public static function save_lead_goals(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية حفظ الأهداف.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_save_lead_goals' );
		$goals = isset( $_POST['goals'] ) && is_array( $_POST['goals'] ) ? wp_unslash( $_POST['goals'] ) : array();
		foreach ( get_users( array( 'role' => 'dealership_sales', 'number' => 100, 'fields' => array( 'ID' ) ) ) as $staff ) {
			$user_id = (int) $staff->ID; $value = isset( $goals[ $user_id ] ) ? \AutoDealership\Pricing\Money::from_sar( $goals[ $user_id ] ) : 0;
			update_user_meta( $user_id, 'adc_monthly_sales_goal', max( 0, (int) $value ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-settings', 'section' => 'crm', 'updated' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function create_branch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية إنشاء الفروع.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_create_branch' );
		$result = BranchService::create( array( 'code' => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ), 'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 'city' => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ), 'address' => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ) ) );
		$notice = is_wp_error( $result ) ? 'branch_error' : 'branch_created';
		wp_safe_redirect( add_query_arg( array( $notice => '1', 'section' => 'branches' ), admin_url( 'admin.php?page=adc-settings' ) ) );
		exit;
	}

	public static function branch_field( \WP_User $user ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$branches = BranchService::public_list();
		$current = BranchScope::assigned_branch( (int) $user->ID );
		$assigned = BranchScope::assigned_branches( (int) $user->ID );
		if ( ! BranchScope::is_global( $user->ID ) && $user->has_cap( 'adc_view_workspace' ) && ! array_filter( $assigned, array( BranchScope::class, 'is_active' ) ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No active branch assigned. Branch operations will be unavailable.', 'auto-dealership-core' ) . '</p></div>'; }
		wp_nonce_field( 'adc_assign_branch_' . (int) $user->ID, 'adc_branch_nonce' );
		echo '<input type="hidden" name="adc_branch_scope_present" value="1">';
		echo '<div><label for="adc_branch_ids"><strong>' . esc_html__( 'Allowed branches', 'auto-dealership-core' ) . '</strong></label><br><select name="adc_branch_ids[]" id="adc_branch_ids" multiple size="' . esc_attr( (string) min( 10, max( 3, count( $branches ) ) ) ) . '">';
		foreach ( $branches as $branch ) {
			echo '<option value="' . absint( $branch['id'] ) . '" ' . selected( in_array( (int) $branch['id'], $assigned, true ), true, false ) . '>' . esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'city', (string) $branch['city'] ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Select every allowed active branch. The assigned branch below is the primary branch and must be selected here too. Role capabilities still control permitted actions.', 'auto-dealership-core' ) . '</p></div>';
		?>
		<h2><?php esc_html_e( 'Dealership access', 'auto-dealership-core' ); ?></h2>
		<table class="form-table"><tr><th><label for="adc_branch_id"><?php esc_html_e( 'Assigned branch', 'auto-dealership-core' ); ?></label></th><td><select name="adc_branch_id" id="adc_branch_id"><option value="0"><?php esc_html_e( 'No branch', 'auto-dealership-core' ); ?></option><?php foreach ( $branches as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $current, (int) $branch['id'] ); ?>><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'city', (string) $branch['city'] ) ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Only administrators can assign staff branch scope.', 'auto-dealership-core' ); ?></p></td></tr></table>
		<?php
		echo '<p><label>' . esc_html__( 'Branch change reason', 'auto-dealership-core' ) . '<br><textarea name="adc_branch_reason" maxlength="2000" rows="2" class="large-text"></textarea></label></p>';
	}

	public static function validate_branch( \WP_Error $errors, bool $update, $data ): void {
		$user_id = (int) ( $data->ID ?? 0 );
		unset( self::$pending_branches[$user_id] );
		if ( ! isset( $_POST['adc_branch_scope_present'] ) ) { return; }
		$invalid = static function () use ( $errors ): void { $errors->add( 'adc_invalid_branch', __( 'Every assigned branch must be active and valid.', 'auto-dealership-core' ) ); };
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['adc_branch_id'], $_POST['adc_branch_scope_present'] ) ) {
			$invalid(); return;
		}
		if ( ! isset( $_POST['adc_branch_nonce'] ) || ! is_string( $_POST['adc_branch_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['adc_branch_nonce'] ), 'adc_assign_branch_' . $user_id ) || ! is_string( $_POST['adc_branch_id'] ) ) {
			$invalid(); return;
		}
		$branch_id = filter_var( wp_unslash( $_POST['adc_branch_id'] ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $branch_id ) {
			$invalid(); return;
		}
		$raw_branches = $_POST['adc_branch_ids'] ?? array();
		if ( ! is_array( $raw_branches ) ) { $invalid(); return; }
		$branch_ids = array();
		foreach ( wp_unslash( $raw_branches ) as $value ) {
			if ( ! is_string( $value ) ) { $invalid(); return; }
			$valid = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $valid || ! \AutoDealership\Inventory\VehicleService::branch_exists( (int) $valid ) ) { $invalid(); return; }
			$branch_ids[] = (int) $valid;
		}
		if ( count( $branch_ids ) > 25 || ( 0 === $branch_id && $branch_ids ) || ( $branch_id > 0 && ! in_array( $branch_id, $branch_ids, true ) ) ) { $invalid(); return; }
		$reason = $_POST['adc_branch_reason'] ?? '';
		if ( ! is_string( $reason ) || mb_strlen( $reason ) > 2000 ) { $errors->add( 'adc_branch_reason', __( 'A change reason is required.', 'auto-dealership-core' ) ); return; }
		$normalized = $branch_id ? array_merge( array( $branch_id ), array_values( array_diff( array_unique( $branch_ids ), array( $branch_id ) ) ) ) : array();
		if ( ( $branch_id !== BranchScope::assigned_branch( $user_id ) || $normalized !== BranchScope::assigned_branches( $user_id ) ) && '' === trim( sanitize_textarea_field( wp_unslash( $reason ) ) ) ) { $errors->add( 'adc_branch_reason', __( 'A change reason is required.', 'auto-dealership-core' ) ); return; }
		if ( ! $errors->has_errors() ) { self::$pending_branches[$user_id] = array( $branch_id, $branch_ids, sanitize_textarea_field( wp_unslash( $reason ) ) ); }
	}

	public static function save_branch( int $user_id ): void {
		if ( ! isset( self::$pending_branches[$user_id] ) ) { return; }
		$input = self::$pending_branches[$user_id]; unset( self::$pending_branches[$user_id] );
		$result = ConfigurationService::assign_branches( $user_id, $input[0], $input[1], $input[2] );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500, 'back_link' => true ) ); }
	}
}
