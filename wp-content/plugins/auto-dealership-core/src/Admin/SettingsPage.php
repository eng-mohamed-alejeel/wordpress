<?php
namespace AutoDealership\Admin;

use AutoDealership\Branches\BranchService;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Restricted configuration and branch assignment screens. */
final class SettingsPage {
	public static function boot(): void {
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

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية الوصول.', 'auto-dealership-core' ) );
		}
		$rate = min( 10000, absint( get_option( 'adc_vat_rate_bps', 0 ) ) );
		$hours = min( 168, max( 1, absint( get_option( 'adc_reservation_hours', 24 ) ) ) );
		$discount = absint( get_option( 'adc_sales_manager_discount_limit', 0 ) );
		$branches = BranchService::public_list();
		$default_branch = absint( get_option( 'adc_default_branch_id', 0 ) );
		$retention_days = absint( get_option( 'adc_privacy_retention_days', 0 ) );
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'إعدادات منصة المعرض', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'القيم المالية بالهللات السعودية. راجع إعدادات الضرائب وسياسات الحجز والخصومات مع مسؤول الشركة قبل التشغيل.', 'auto-dealership-core' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ الإعدادات.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر حفظ الإعدادات وتوثيقها؛ أُعيدت القيم السابقة.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['branch_created'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم إنشاء الفرع.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['branch_error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر إنشاء الفرع. تحقق من صحة الرمز وعدم تكراره.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_save_settings">
				<?php wp_nonce_field( 'adc_save_settings' ); ?>
				<table class="form-table"><tbody>
					<tr><th scope="row"><label for="adc_vat_rate_bps"><?php esc_html_e( 'نسبة الضريبة (نقاط أساس)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_vat_rate_bps" name="vat_rate_bps" type="number" min="0" max="10000" value="<?php echo esc_attr( $rate ); ?>"><p class="description">1500 تعني 15%. القيمة الافتراضية صفر حتى يعتمدها مسؤول الشركة.</p></td></tr>
					<tr><th scope="row"><label for="adc_reservation_hours"><?php esc_html_e( 'مدة الحجز بالساعات', 'auto-dealership-core' ); ?></label></th><td><input id="adc_reservation_hours" name="reservation_hours" type="number" min="1" max="168" value="<?php echo esc_attr( $hours ); ?>"></td></tr>
					<tr><th scope="row"><label for="adc_discount_limit"><?php esc_html_e( 'أعلى خصم لمدير المبيعات (هللة)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_discount_limit" name="sales_manager_discount_limit" type="number" min="0" value="<?php echo esc_attr( $discount ); ?>"><p class="description">أي خصم أعلى من الحد يتطلب صلاحية المدير العام. صفر يحول كل طلب خصم للمدير العام.</p></td></tr>
					<tr><th scope="row"><label for="adc_default_branch_id"><?php esc_html_e( 'الفرع الافتراضي للطلبات الواردة', 'auto-dealership-core' ); ?></label></th><td><select id="adc_default_branch_id" name="default_branch_id"><option value="0"><?php esc_html_e( 'بدون فرع — يتطلب إسنادًا يدويًا', 'auto-dealership-core' ); ?></option><?php foreach ( $branches as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $default_branch, (int) $branch['id'] ); ?>><?php echo esc_html( $branch['name'] . ' — ' . $branch['city'] ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th scope="row"><label for="adc_privacy_retention_days"><?php esc_html_e( 'مدة الاحتفاظ ببيانات الهوية (يوم)', 'auto-dealership-core' ); ?></label></th><td><input id="adc_privacy_retention_days" name="privacy_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( $retention_days ); ?>"><p class="description"><?php esc_html_e( '0 يعطل الإخفاء المجدول. أي قيمة مفعلة يجب أن تكون بين 30 و3650 يومًا وبعد اعتماد السياسة النظامية.', 'auto-dealership-core' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'الفروع', 'auto-dealership-core' ); ?></th><td><?php echo esc_html( (string) count( $branches ) ); ?> — <?php esc_html_e( 'تُدار الفروع عبر واجهة REST.', 'auto-dealership-core' ); ?></td></tr>
				</tbody></table>
				<?php submit_button( __( 'حفظ الإعدادات', 'auto-dealership-core' ) ); ?>
			</form>
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
			<ul><?php foreach ( $branches as $branch ) : ?><li><?php echo esc_html( $branch['code'] . ' — ' . $branch['name'] . ' (' . $branch['city'] . ')' ); ?></li><?php endforeach; ?></ul>
		</div>
		<?php
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية حفظ الإعدادات.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_save_settings' );
		$result = ConfigurationService::update( $_POST );
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'updated', '1', admin_url( 'admin.php?page=adc-settings' ) ) );
		exit;
	}

	public static function create_branch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية إنشاء الفروع.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_create_branch' );
		$result = BranchService::create( array( 'code' => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ), 'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 'city' => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ), 'address' => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ) ) );
		$notice = is_wp_error( $result ) ? 'branch_error' : 'branch_created';
		wp_safe_redirect( add_query_arg( $notice, '1', admin_url( 'admin.php?page=adc-settings' ) ) );
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
			echo '<option value="' . absint( $branch['id'] ) . '" ' . selected( in_array( (int) $branch['id'], $assigned, true ), true, false ) . '>' . esc_html( $branch['name'] . ' — ' . $branch['city'] ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Select every allowed active branch. The assigned branch below is the primary branch and must be selected here too. Role capabilities still control permitted actions.', 'auto-dealership-core' ) . '</p></div>';
		?>
		<h2><?php esc_html_e( 'Dealership access', 'auto-dealership-core' ); ?></h2>
		<table class="form-table"><tr><th><label for="adc_branch_id"><?php esc_html_e( 'Assigned branch', 'auto-dealership-core' ); ?></label></th><td><select name="adc_branch_id" id="adc_branch_id"><option value="0"><?php esc_html_e( 'No branch', 'auto-dealership-core' ); ?></option><?php foreach ( $branches as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $current, (int) $branch['id'] ); ?>><?php echo esc_html( $branch['name'] . ' — ' . $branch['city'] ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Only administrators can assign staff branch scope.', 'auto-dealership-core' ); ?></p></td></tr></table>
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
