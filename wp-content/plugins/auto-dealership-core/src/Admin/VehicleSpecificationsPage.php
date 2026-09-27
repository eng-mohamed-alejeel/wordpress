<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleSpecifications;

defined( 'ABSPATH' ) || exit;

final class VehicleSpecificationsPage {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			add_submenu_page( 'adc-workspace', __( 'مواصفات المركبات', 'auto-dealership-core' ), __( 'مواصفات المركبات', 'auto-dealership-core' ), 'adc_manage_inventory', 'adc-vehicle-specifications', array( self::class, 'render' ) );
		} );
		add_action( 'admin_post_adc_save_vehicle_specifications', array( self::class, 'save' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { wp_die( esc_html__( 'غير مسموح.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$id = absint( $_GET['vehicle_id'] ?? 0 );
		$vehicle = $id ? VehicleService::get( $id ) : null;
		$labels = array( 'exterior_color'=>'اللون الخارجي', 'interior_color'=>'اللون الداخلي', 'engine_size'=>'سعة المحرك (مع الوحدة)', 'drivetrain'=>'نظام الدفع', 'doors'=>'عدد الأبواب', 'seats'=>'عدد المقاعد', 'horsepower'=>'القوة بالحصان', 'warranty'=>'الضمان', 'interior_features'=>'التجهيزات الداخلية', 'exterior_features'=>'التجهيزات الخارجية', 'safety_features'=>'تجهيزات السلامة' );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'مواصفات المركبات', 'auto-dealership-core' ); ?></h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ المواصفات.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر الحفظ. راجع القيم وحالة المركبة وصلاحية الوصول.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<form method="get"><input type="hidden" name="page" value="adc-vehicle-specifications"><label for="adc-spec-id"><?php esc_html_e( 'معرّف المركبة', 'auto-dealership-core' ); ?></label> <input id="adc-spec-id" name="vehicle_id" type="number" min="1" required value="<?php echo $id ?: ''; ?>"> <button class="button"><?php esc_html_e( 'عرض', 'auto-dealership-core' ); ?></button></form>
		<?php if ( $id && ! $vehicle ) : ?><p><?php esc_html_e( 'المركبة غير موجودة أو خارج نطاق الوصول.', 'auto-dealership-core' ); ?></p><?php endif; ?>
		<?php if ( $vehicle ) : ?>
		<h2><?php echo esc_html( $vehicle['stock_number'] . ' — ' . $vehicle['brand'] . ' ' . $vehicle['model'] ); ?></h2>
		<p><?php esc_html_e( 'تظهر هذه المواصفات للعملاء عند إتاحة المركبة ونشر صفحتها. تُقفل التعديلات أثناء الحجز والبيع والتسليم.', 'auto-dealership-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="adc_save_vehicle_specifications"><input type="hidden" name="id" value="<?php echo $id; ?>">
		<?php wp_nonce_field( 'adc_save_vehicle_specifications_' . $id ); ?>
		<table class="form-table"><tbody>
		<?php foreach ( $labels as $field => $label ) : $value = $vehicle[$field] ?? ''; ?>
		<tr><th><label for="adc-spec-<?php echo esc_attr( $field ); ?>"><?php echo esc_html__( $label, 'auto-dealership-core' ); ?></label></th><td>
		<?php if ( 'drivetrain' === $field ) : ?>
		<select id="adc-spec-drivetrain" name="specifications[drivetrain]"><?php foreach ( array( ''=>'غير محدد', 'fwd'=>'دفع أمامي', 'rwd'=>'دفع خلفي', 'awd'=>'دفع كلي', '4wd'=>'دفع رباعي' ) as $key => $name ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html__( $name, 'auto-dealership-core' ); ?></option><?php endforeach; ?></select>
		<?php elseif ( isset( VehicleSpecifications::NUMBER_LIMITS[$field] ) ) : ?>
		<input id="adc-spec-<?php echo esc_attr( $field ); ?>" name="specifications[<?php echo esc_attr( $field ); ?>]" type="number" min="1" max="<?php echo (int) VehicleSpecifications::NUMBER_LIMITS[$field]; ?>" step="1" value="<?php echo esc_attr( $value ); ?>">
		<?php elseif ( VehicleSpecifications::TEXT_LIMITS[$field] > 100 ) : ?>
		<textarea class="large-text" rows="3" id="adc-spec-<?php echo esc_attr( $field ); ?>" name="specifications[<?php echo esc_attr( $field ); ?>]" maxlength="<?php echo (int) VehicleSpecifications::TEXT_LIMITS[$field]; ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php else : ?>
		<input class="regular-text" id="adc-spec-<?php echo esc_attr( $field ); ?>" name="specifications[<?php echo esc_attr( $field ); ?>]" maxlength="<?php echo (int) VehicleSpecifications::TEXT_LIMITS[$field]; ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endif; ?></td></tr><?php endforeach; ?>
		<tr><th><label for="adc-spec-reason"><?php esc_html_e( 'سبب التعديل', 'auto-dealership-core' ); ?></label></th><td><textarea id="adc-spec-reason" name="reason" required maxlength="2000" class="large-text"></textarea></td></tr>
		</tbody></table><?php submit_button( __( 'حفظ المواصفات', 'auto-dealership-core' ) ); ?></form><?php endif; ?></div>
		<?php
	}

	public static function save(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_save_vehicle_specifications_' . $id );
		$input = isset( $_POST['specifications'] ) && is_array( $_POST['specifications'] ) ? wp_unslash( $_POST['specifications'] ) : array();
		$reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '';
		$result = VehicleSpecifications::update( $id, $input, $reason );
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-vehicle-specifications', 'vehicle_id'=>$id, is_wp_error( $result ) ? 'error' : 'saved'=>'1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
