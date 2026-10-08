<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Reference\ReferenceService;

defined( 'ABSPATH' ) || exit;

/** Physical location moves and elevated VIN corrections. */
final class InventoryIdentityPage {
	public static function boot(): void { add_action( 'admin_menu', array( self::class, 'menu' ) ); add_action( 'admin_post_adc_record_vehicle_receipt', array( self::class, 'receipt' ) ); add_action( 'admin_post_adc_record_vehicle_inspection', array( self::class, 'inspection' ) ); }
	public static function menu(): void {
		if ( current_user_can( 'adc_manage_inventory' ) || current_user_can( 'adc_change_vehicle_vin' ) ) { add_submenu_page( 'adc-workspace', __( 'Locations and VIN', 'auto-dealership-core' ), __( 'Locations and VIN', 'auto-dealership-core' ), 'adc_view_inventory', 'adc-inventory-identity', array( self::class, 'render' ) ); }
	}
	public static function render(): void {
		$vin_page = 'adc-vehicle-vin' === ( $_GET['page'] ?? '' ) || ( ! current_user_can( 'adc_manage_inventory' ) && current_user_can( 'adc_change_vehicle_vin' ) );
		$capability = $vin_page ? 'adc_change_vehicle_vin' : 'adc_manage_inventory';
		if ( ! current_user_can( $capability ) || ( ! current_user_can( 'adc_view_inventory' ) && ! current_user_can( 'manage_options' ) ) ) { wp_die( esc_html__( 'Inventory access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$tabs = array( 'receipt' => 'استلام المركبات', 'inspection' => 'فحص المركبات', 'locations' => 'حركة المواقع' );
		$section = sanitize_key( wp_unslash( $_GET['section'] ?? 'receipt' ) );
		if ( ! isset( $tabs[$section] ) ) { $section = 'receipt'; }
		if ( $vin_page ) { $section = 'vin'; }
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$vehicles = VehicleService::list_for_current_user( $page, 'receipt' === $section ? 'received' : ( 'inspection' === $section ? 'inspection' : '' ) );
		$locations = 'locations' === $section ? ReferenceService::locations() : array();
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
		<h1><?php echo esc_html__( $vin_page ? 'تصحيح رقم الهيكل VIN' : 'استلام وفحص المركبات وحركة المواقع', 'auto-dealership-core' ); ?></h1>
		<?php if ( ! $vin_page ) { Navigation::tabs( 'adc-inventory-identity', $tabs, $section ); } ?>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ التغيير.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر تنفيذ العملية.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( $vin_page ) : ?><p><?php esc_html_e( 'تصحيح رقم الهيكل يتطلب سببًا موثقًا ولا يسمح به بعد بدء البيع أو التسليم.', 'auto-dealership-core' ); ?></p><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'المركبة', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( $tabs[$section] ?? 'VIN', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php $shown = 0; foreach ( $vehicles as $vehicle ) : ?>
		<?php if ( ( 'receipt' === $section && 'received' !== $vehicle['status'] ) || ( 'inspection' === $section && 'inspection' !== $vehicle['status'] ) ) { continue; } ++$shown; ?>
		<tr><td><?php echo esc_html( $vehicle['stock_number'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'brand', (string) $vehicle['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'model', (string) $vehicle['model'] ) . ' (' . \AutoDealership\Core\Localization::label( (string ) $vehicle['status'] ) . ')' ); ?></td><td>
		<?php if ( in_array( $section, array( 'receipt', 'inspection' ), true ) ) : ?><?php if ( current_user_can( 'adc_manage_inventory' ) && 'received' === $vehicle['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_record_vehicle_receipt"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_record_vehicle_receipt_' . (int) $vehicle['id'] ); ?><input name="document_reference" required placeholder="<?php echo esc_attr__( 'مرجع مستند الاستلام', 'auto-dealership-core' ); ?>"><input name="odometer" type="number" min="0" value="0"><select name="condition"><option value="good"><?php echo esc_html__( 'good', 'auto-dealership-core' ); ?></option><option value="damaged"><?php echo esc_html__( 'damaged', 'auto-dealership-core' ); ?></option><option value="incomplete"><?php echo esc_html__( 'incomplete', 'auto-dealership-core' ); ?></option></select><input name="notes" placeholder="<?php echo esc_attr__( 'ملاحظات', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'توثيق الاستلام', 'auto-dealership-core' ); ?></button></form><?php elseif ( current_user_can( 'adc_manage_inventory' ) && 'inspection' === $vehicle['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_record_vehicle_inspection"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_record_vehicle_inspection_' . (int) $vehicle['id'] ); ?><?php foreach ( array('exterior','interior','engine','tires','vin') as $check ) : ?><label><?php echo esc_html( \AutoDealership\Core\Localization::label( $check ) ); ?> <select name="checklist[<?php echo esc_attr( $check ); ?>]"><option value="pass"><?php echo esc_html__( 'pass', 'auto-dealership-core' ); ?></option><option value="fail"><?php echo esc_html__( 'fail', 'auto-dealership-core' ); ?></option></select></label> <?php endforeach; ?><input name="notes" placeholder="<?php echo esc_attr__( 'ملاحظات الإخفاق', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'حفظ الفحص', 'auto-dealership-core' ); ?></button></form><?php endif; ?>
		<?php elseif ( 'locations' === $section ) : ?><?php if ( current_user_can( 'adc_manage_inventory' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_move_vehicle_location"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_move_vehicle_location_' . (int) $vehicle['id'] ); ?><select name="location_id" required><option value=""><?php esc_html_e( 'اختر الموقع', 'auto-dealership-core' ); ?></option><?php foreach ( $locations as $location ) : if ( empty( $location['active'] ) || (int) $location['branch_id'] !== (int) $vehicle['branch_id'] ) { continue; } ?><option value="<?php echo absint( $location['id'] ); ?>" <?php selected( (int) $vehicle['location_id'], (int) $location['id'] ); ?>><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'locations', (int) $location['id'], 'name', (string) $location['name'] ) . ' (' . \AutoDealership\Core\Localization::label( (string ) $location['location_type'] ) . ')' ); ?></option><?php endforeach; ?></select><input name="reason" required maxlength="500" placeholder="<?php echo esc_attr__( 'سبب النقل', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'نقل', 'auto-dealership-core' ); ?></button></form><?php endif; ?>
		<?php else : ?><code><?php echo esc_html( $vehicle['vin'] ); ?></code><?php if ( current_user_can( 'adc_change_vehicle_vin' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_change_vehicle_vin"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_change_vehicle_vin_' . (int) $vehicle['id'] ); ?><input name="vin" minlength="17" maxlength="17" required placeholder="<?php echo esc_attr__( 'VIN الجديد', 'auto-dealership-core' ); ?>"><input name="reason" required maxlength="500" placeholder="<?php echo esc_attr__( 'سبب التصحيح', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'تصحيح', 'auto-dealership-core' ); ?></button></form><?php endif; ?>
		<?php endif; ?></td></tr><?php endforeach; ?>
		<?php if ( ! $shown ) : ?><tr><td colspan="2"><?php esc_html_e( 'لا توجد مركبات في هذه الصفحة لهذه المرحلة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php Navigation::pagination( $vin_page ? 'adc-vehicle-vin' : 'adc-inventory-identity', $section, $page, count( $vehicles ) ); ?>
		</div>
		<?php
	}

	/**
	 * @param mixed $result
	 */
	private static function redirect( $result ): void { wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', add_query_arg( 'section', 'adc_record_vehicle_inspection' === ( $_POST['action'] ?? '' ) ? 'inspection' : 'receipt', admin_url( 'admin.php?page=adc-inventory-identity' ) ) ) ); exit; }
	public static function receipt(): void { $id=absint($_POST['id']??0); check_admin_referer('adc_record_vehicle_receipt_'.$id); self::redirect(VehicleIntakeService::receive($id,array('document_reference'=>wp_unslash($_POST['document_reference']??''),'odometer'=>absint($_POST['odometer']??0),'condition'=>wp_unslash($_POST['condition']??''),'notes'=>wp_unslash($_POST['notes']??'')))); }
	public static function inspection(): void { $id=absint($_POST['id']??0); check_admin_referer('adc_record_vehicle_inspection_'.$id); self::redirect(VehicleIntakeService::inspect($id,array('checklist'=>is_array($_POST['checklist']??null)?wp_unslash($_POST['checklist']):array(),'notes'=>wp_unslash($_POST['notes']??'')))); }
}
