<?php
namespace AutoDealership\Admin;

use AutoDealership\Branches\BranchService;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Restricted configuration and branch assignment screens. */
final class SettingsPage {
	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', static function (): void {
			if ( 'adc-settings' === ( $_GET['page'] ?? '' ) ) { wp_enqueue_script( 'adc-currency-settings', plugins_url( 'assets/js/currency-settings.js', __DIR__ . '/../../auto-dealership-core.php' ), array(), '1.0.0', true ); }
		} );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_save_settings', array( self::class, 'save' ) );
		add_action( 'admin_post_adc_create_branch', array( self::class, 'create_branch' ) );
		add_action( 'show_user_profile', array( self::class, 'branch_field' ) );
		add_action( 'edit_user_profile', array( self::class, 'branch_field' ) );
		add_action( 'personal_options_update', array( self::class, 'save_branch' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_branch' ) );
	}

	public static function menu(): void {
		add_menu_page( __( 'Dealership Core', 'auto-dealership-core' ), __( 'Dealership Core', 'auto-dealership-core' ), 'manage_options', 'adc-settings', array( self::class, 'render' ), 'dashicons-admin-multisite', 58 );
	}

	public static function sections(): array {
		return array(
			'pricing' => array( 'التسعير والضرائب', array( 'vat_rate_bps', 'sales_manager_discount_limit', 'general_manager_discount_limit', 'pricing_fee_amount', 'promotion_code', 'promotion_type', 'promotion_value', 'promotion_starts_at', 'promotion_ends_at', 'seller_name', 'seller_tax_number', 'seller_phone', 'seller_address' ) ),
			'reservations' => array( 'الحجز والعربون', array( 'reservation_hours', 'reservation_deposit_type', 'reservation_deposit_value' ) ),
			'branches' => array( 'الفروع', array( 'default_branch_id' ) ),
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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_save_settings"><input type="hidden" name="settings_section" value="<?php echo esc_attr( $section ); ?>"><input type="hidden" name="adc_money_unit" value="SAR">
				<?php wp_nonce_field( 'adc_save_settings' ); ?>
				<table class="form-table"><tbody>
					<?php if ( in_array( 'vat_rate_bps', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_vat_rate_bps"><?php esc_html_e( 'نسبة الضريبة (نقاط أساس)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_vat_rate_bps" name="vat_rate_bps" type="number" min="0" max="10000" value="<?php echo esc_attr( $rate ); ?>"><p class="description"><?php echo esc_html__( '1500 تعني 15%. القيمة الافتراضية صفر حتى يعتمدها مسؤول الشركة.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'reservation_hours', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_reservation_hours"><?php esc_html_e( 'مدة الحجز بالساعات', 'auto-dealership-core' ); ?></label></th><td><input id="adc_reservation_hours" name="reservation_hours" type="number" min="1" max="168" value="<?php echo esc_attr( $hours ); ?>"></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'sales_manager_discount_limit', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_discount_limit"><?php esc_html_e( 'أعلى خصم لمدير المبيعات (ريال)', 'auto-dealership-core' ); ?></label></th><td><input step="0.01" id="adc_discount_limit" name="sales_manager_discount_limit" type="number" min="0" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $discount ) ); ?>"><p class="description"><?php echo esc_html__( 'أي خصم أعلى من الحد يتطلب صلاحية المدير العام. صفر يحول كل طلب خصم للمدير العام.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'default_branch_id', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_default_branch_id"><?php esc_html_e( 'الفرع الافتراضي للطلبات الواردة', 'auto-dealership-core' ); ?></label></th><td><select id="adc_default_branch_id" name="default_branch_id"><option value="0"><?php esc_html_e( 'بدون فرع — يتطلب إسنادًا يدويًا', 'auto-dealership-core' ); ?></option><?php foreach ( $branches as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $default_branch, (int) $branch['id'] ); ?>><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'city', (string) $branch['city'] ) ); ?></option><?php endforeach; ?></select></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'privacy_retention_days', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_privacy_retention_days"><?php esc_html_e( 'مدة الاحتفاظ ببيانات الهوية (يوم)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_privacy_retention_days" name="privacy_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( $retention_days ); ?>"><p class="description"><?php esc_html_e( '0 يعطل الإخفاء المجدول. أي قيمة مفعلة يجب أن تكون بين 30 و3650 يومًا وبعد اعتماد السياسة النظامية.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'public_catalog_mode', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_public_catalog_mode"><?php esc_html_e( 'مصدر الكتالوج العام', 'auto-dealership-core' ); ?></label></th><td><select id="adc_public_catalog_mode" name="public_catalog_mode"><option value="compatibility" <?php selected( $catalog_mode, PublicCatalog::MODE_COMPATIBILITY ); ?>><?php esc_html_e( 'توافق تدريجي مع بيانات القالب', 'auto-dealership-core' ); ?></option><option value="authoritative" <?php selected( $catalog_mode, PublicCatalog::MODE_AUTHORITATIVE ); ?> <?php disabled( ! $catalog_readiness['ready'] && PublicCatalog::MODE_AUTHORITATIVE !== $catalog_mode ); ?>><?php esc_html_e( 'المخزون التشغيلي المعتمد', 'auto-dealership-core' ); ?></option></select><p class="description"><?php esc_html_e( 'فعّل المصدر التشغيلي بعد ربط السيارات المنشورة والتحقق من الترحيل. في هذا الوضع لا تظهر أي سيارة غير مرتبطة أو غير متاحة أو تابعة لفرع غير نشط.', 'auto-dealership-core' ); ?></p><p class="description"><?php printf( esc_html__( 'جاهزية التحويل: سيارات تشغيلية %1$d، مؤهلة للنشر %2$d، منشورات سيارات %3$d، منشورات غير مرتبطة %4$d، خرائط مكررة %5$d، روابط غير صالحة %6$d.', 'auto-dealership-core' ), absint( $catalog_readiness['operational'] ), absint( $catalog_readiness['eligible'] ), absint( $catalog_readiness['published_posts'] ), absint( $catalog_readiness['unmapped_published_posts'] ), absint( $catalog_readiness['duplicate_mappings'] ), absint( $catalog_readiness['invalid_mappings'] ) ); ?></p><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=adc-catalog-cutover' ) ); ?>"><?php esc_html_e( 'فتح مساحة مطابقة الكتالوج', 'auto-dealership-core' ); ?></a></p></td></tr>
					<?php endif; ?>

					<?php if ( in_array( 'general_manager_discount_limit', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_general_discount_limit"><?php esc_html_e( 'General manager discount ceiling (SAR)', 'auto-dealership-core' ); ?></label></th><td><input step="0.01" id="adc_general_discount_limit" name="general_manager_discount_limit" type="number" min="0" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $general_discount ) ); ?>"></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'pricing_fee_amount', $fields, true ) ) : ?>
					<tr><th scope="row"><label for="adc_pricing_fee_amount"><?php esc_html_e( 'Fixed quote fee (SAR)', 'auto-dealership-core' ); ?></label></th><td><input step="0.01" id="adc_pricing_fee_amount" name="pricing_fee_amount" type="number" min="0" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $fee ) ); ?>"></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'promotion_code', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Active promotion', 'auto-dealership-core' ); ?></th><td><input name="promotion_code" maxlength="64" placeholder="<?php echo esc_attr__( 'code', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_promotion_code', '' ) ); ?>"> <select name="promotion_type"><?php foreach ( array( 'none','fixed','percentage' ) as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $promotion_type, $type ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $type ) ); ?></option><?php endforeach; ?></select> <input step="0.01" data-currency-value name="promotion_value" type="number" min="0" value="<?php echo esc_attr( ( 'fixed' === $promotion_type ? \AutoDealership\Pricing\Money::decimal( (string) get_option( 'adc_promotion_value', 0 ) ) : absint( get_option( 'adc_promotion_value', 0 ) ) ) ); ?>"><p><label><?php esc_html_e( 'Starts', 'auto-dealership-core' ); ?> <input name="promotion_starts_at" type="date" value="<?php echo esc_attr( get_option( 'adc_promotion_starts_at', '' ) ); ?>"></label> <label><?php esc_html_e( 'Ends', 'auto-dealership-core' ); ?> <input name="promotion_ends_at" type="date" value="<?php echo esc_attr( get_option( 'adc_promotion_ends_at', '' ) ); ?>"></label></p><p class="description"><?php esc_html_e( 'Fixed amounts use SAR; percentage values use basis points; 1500 means 15%.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'reservation_deposit_type', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Reservation deposit policy', 'auto-dealership-core' ); ?></th><td><select name="reservation_deposit_type"><?php foreach ( array( 'none','fixed','percentage' ) as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $deposit_type, $type ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $type ) ); ?></option><?php endforeach; ?></select> <input step="0.01" data-currency-value name="reservation_deposit_value" type="number" min="0" value="<?php echo esc_attr( ( 'fixed' === $deposit_type ? \AutoDealership\Pricing\Money::decimal( (string) get_option( 'adc_reservation_deposit_value', 0 ) ) : absint( get_option( 'adc_reservation_deposit_value', 0 ) ) ) ); ?>"><p class="description"><?php esc_html_e( 'Fixed amounts use SAR; percentage values use basis points.', 'auto-dealership-core' ); ?></p></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'seller_name', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Seller identity on quotations', 'auto-dealership-core' ); ?></th><td><p><input class="regular-text" name="seller_name" placeholder="<?php echo esc_attr__( 'Name', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_seller_name', get_bloginfo( 'name' ) ) ); ?>"></p><p><input class="regular-text" name="seller_tax_number" placeholder="<?php echo esc_attr__( 'Tax number', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_seller_tax_number', '' ) ); ?>"></p><p><input class="regular-text" name="seller_phone" placeholder="<?php echo esc_attr__( 'Phone', 'auto-dealership-core' ); ?>" value="<?php echo esc_attr( get_option( 'adc_seller_phone', '' ) ); ?>"></p><textarea class="large-text" name="seller_address" rows="2" placeholder="<?php echo esc_attr__( 'Address', 'auto-dealership-core' ); ?>"><?php echo esc_textarea( get_option( 'adc_seller_address', '' ) ); ?></textarea></td></tr>
					<?php endif; ?>
					<?php if ( in_array( 'delivery_required_documents', $fields, true ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Required delivery documents', 'auto-dealership-core' ); ?></th><td><?php foreach ( $document_labels as $key=>$label ) : ?><label style="display:block"><input type="checkbox" name="delivery_required_documents[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $required_documents, true ) ); ?>> <?php echo esc_html__( $label, 'auto-dealership-core' ); ?></label><?php endforeach; ?><p class="description"><?php esc_html_e( 'Selected documents block delivery approval and release until evidence references are recorded.', 'auto-dealership-core' ); ?></p></td></tr>
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

	public static function save(): void {
		\AutoDealership\Pricing\Money::require_sar_form();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية حفظ الإعدادات.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_save_settings' );
		$section = isset( $_POST['settings_section'] ) ? sanitize_key( wp_unslash( $_POST['settings_section'] ) ) : '';
		if ( $section && ! isset( self::sections()[$section] ) ) { wp_die( esc_html__( 'قسم إعدادات غير صالح.', 'auto-dealership-core' ), '', array( 'response' => 400 ) ); }
		$input = wp_unslash( $_POST );
		if ( $section ) {
			$input = array_intersect_key( $input, array_flip( self::sections()[$section][1] ) );
			if ( 'delivery' === $section ) { $input['delivery_required_documents'] = $input['delivery_required_documents'] ?? array(); }
		}
		foreach ( array( 'promotion','reservation_deposit' ) as $policy ) {
			if ( 'percentage' === ( $_POST[$policy . '_type'] ?? '' ) && null === \AutoDealership\Pricing\Money::parse( wp_unslash( $_POST[$policy . '_value'] ?? '' ) ) ) {
				wp_die( esc_html__( 'Percentage values use basis points; 1500 means 15%.', 'auto-dealership-core' ), '', array( 'response'=>400 ) );
			}
		}
		$values = \AutoDealership\Pricing\Money::form_values( $input, array_merge( array( 'sales_manager_discount_limit','general_manager_discount_limit','pricing_fee_amount' ), 'fixed' === ( $_POST['promotion_type'] ?? '' ) ? array( 'promotion_value' ) : array(), 'fixed' === ( $_POST['reservation_deposit_type'] ?? '' ) ? array( 'reservation_deposit_value' ) : array() ) );
		$result = $section ? ConfigurationService::update_section( $values, self::sections()[$section][1] ) : ConfigurationService::update( $values );
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'updated', '1', add_query_arg( 'section', $section ?: 'pricing', admin_url( 'admin.php?page=adc-settings' ) ) ) );
		exit;
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
	}

	public static function save_branch( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['adc_branch_id'], $_POST['adc_branch_scope_present'] ) ) {
			return;
		}
		if ( ! isset( $_POST['adc_branch_nonce'] ) || ! is_string( $_POST['adc_branch_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['adc_branch_nonce'] ), 'adc_assign_branch_' . $user_id ) || ! is_string( $_POST['adc_branch_id'] ) ) {
			return;
		}
		$branch_id = filter_var( wp_unslash( $_POST['adc_branch_id'] ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $branch_id ) {
			return;
		}
		$raw_branches = $_POST['adc_branch_ids'] ?? array();
		if ( ! is_array( $raw_branches ) ) { return; }
		$branch_ids = array();
		foreach ( wp_unslash( $raw_branches ) as $value ) {
			if ( ! is_string( $value ) ) { return; }
			$valid = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $valid ) { return; }
			$branch_ids[] = (int) $valid;
		}
		ConfigurationService::assign_branches( $user_id, $branch_id, $branch_ids );
	}
}
