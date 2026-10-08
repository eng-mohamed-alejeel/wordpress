<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\VehicleAcquisitionService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Purchasing\SupplierService;

defined( 'ABSPATH' ) || exit;

final class VehicleAcquisitionPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_update_vehicle_acquisition', array( self::class, 'save' ) );
	}

	public static function menu(): void {
		if ( current_user_can( 'adc_view_vehicle_costs' ) || current_user_can( 'adc_manage_vehicle_costs' ) ) {
			add_submenu_page( 'adc-workspace', __( 'Vehicle acquisition', 'auto-dealership-core' ), __( 'Vehicle acquisition', 'auto-dealership-core' ), 'adc_view_vehicle_costs', 'adc-vehicle-acquisition', array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_vehicle_costs' ) && ! current_user_can( 'adc_manage_vehicle_costs' ) ) { wp_die( '', '', array( 'response'=>403 ) ); }
		$vehicles = VehicleService::list_for_current_user( max( 1, absint( $_GET['paged'] ?? 1 ) ) );
		$suppliers = SupplierService::all( true );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'Vehicle acquisition data', 'auto-dealership-core' ); ?></h1>
		<p><?php esc_html_e( 'All monetary values use SAR. Total cost is entered explicitly until an approved business formula is configured.', 'auto-dealership-core' ); ?></p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Acquisition data saved and audited.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Acquisition data could not be saved.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Vehicle', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Current values', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Update', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $vehicles as $vehicle ) : $record = VehicleAcquisitionService::get( (int) $vehicle['id'] ); if ( is_wp_error( $record ) ) { continue; } ?><tr><td><?php echo esc_html( $vehicle['stock_number'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'brand', (string) $vehicle['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'model', (string) $vehicle['model'] ) ); ?><br><?php echo esc_html( \AutoDealership\Core\Localization::label( (string ) $vehicle['status'] ) ); ?></td><td><?php echo esc_html( (string ) ( \AutoDealership\Content\StoredTranslations::text( 'suppliers', (int) $record['supplier_id'], 'display_name', (string) $record['supplier_name'] ) ?: '—' ) ); ?><br><?php echo esc_html( sprintf( 'Purchase: %s / Additional: %s / Total: %s / Wholesale: %s', \AutoDealership\Pricing\Money::display( $record['purchase_cost'] ), \AutoDealership\Pricing\Money::display( $record['additional_cost'] ), \AutoDealership\Pricing\Money::display( $record['total_cost'] ), \AutoDealership\Pricing\Money::display( $record['wholesale_price'] ) ) ); ?></td><td><?php if ( current_user_can( 'adc_manage_vehicle_costs' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_update_vehicle_acquisition"><input type="hidden" name="adc_money_unit" value="SAR"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_update_vehicle_acquisition_' . (int) $vehicle['id'] ); ?><label class="adc-field"><?php esc_html_e( 'المورد', 'auto-dealership-core' ); ?><select name="supplier_id"><option value="0"><?php esc_html_e( 'No supplier selected', 'auto-dealership-core' ); ?></option><?php foreach ( $suppliers as $supplier ) : ?><option value="<?php echo absint( $supplier['id'] ); ?>" <?php selected( (int) $record['supplier_id'], (int) $supplier['id'] ); ?>><?php echo esc_html( $supplier['supplier_code'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'suppliers', (int) $supplier['id'], 'display_name', (string) $supplier['display_name'] ) ); ?></option><?php endforeach; ?></select></label><label class="adc-field"><?php echo esc_html__( 'Purchase cost', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="purchase_cost" value="<?php echo esc_attr( null === $record['purchase_cost'] ? '' : \AutoDealership\Pricing\Money::decimal( $record['purchase_cost'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Purchase cost', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Additional cost', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="additional_cost" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $record['additional_cost'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Additional cost', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Total cost', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="total_cost" value="<?php echo esc_attr( null === $record['total_cost'] ? '' : \AutoDealership\Pricing\Money::decimal( $record['total_cost'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Total cost', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Wholesale price', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="wholesale_price" value="<?php echo esc_attr( null === $record['wholesale_price'] ? '' : \AutoDealership\Pricing\Money::decimal( $record['wholesale_price'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Wholesale price', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Customs reference', 'auto-dealership-core' ); ?><input name="customs_reference" maxlength="100" value="<?php echo esc_attr( $record['customs_reference'] ); ?>" placeholder="<?php echo esc_attr__( 'Customs reference', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php esc_html_e( 'تاريخ الوصول', 'auto-dealership-core' ); ?><input type="date" name="arrival_date" value="<?php echo esc_attr( (string) $record['arrival_date'] ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Internal notes', 'auto-dealership-core' ); ?><textarea name="internal_notes" maxlength="5000" rows="2" placeholder="<?php echo esc_attr__( 'Internal notes', 'auto-dealership-core' ); ?>"><?php echo esc_textarea( $record['internal_notes'] ); ?></textarea></label><label class="adc-field"><?php echo esc_html__( 'Existing image/PDF attachment IDs, comma separated', 'auto-dealership-core' ); ?><input name="document_media_ids" placeholder="<?php echo esc_attr__( 'Existing image/PDF attachment IDs, comma separated', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Reason for change', 'auto-dealership-core' ); ?><input name="reason" required maxlength="2000" placeholder="<?php echo esc_attr__( 'Reason for change', 'auto-dealership-core' ); ?>"></label><button class="button button-primary"><?php esc_html_e( 'Save', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
		<?php if ( ! $vehicles ) : ?><tr><td colspan="3"><?php esc_html_e( 'No vehicles are available in your branch scope.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table></div>
		<?php
	}

	public static function save(): void {
		\AutoDealership\Pricing\Money::require_sar_form();
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_update_vehicle_acquisition_' . $id );
		$input = array();
		foreach ( array( 'supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price','customs_reference','arrival_date','internal_notes' ) as $field ) { if ( array_key_exists( $field, $_POST ) ) { $input[$field] = wp_unslash( $_POST[$field] ); } }
		if ( isset( $_POST['document_media_ids'] ) && is_string( $_POST['document_media_ids'] ) && '' !== trim( $_POST['document_media_ids'] ) ) {
			$input['document_media_ids'] = array_map( 'trim', explode( ',', wp_unslash( $_POST['document_media_ids'] ) ) );
		}
		$result = VehicleAcquisitionService::update( $id, \AutoDealership\Pricing\Money::form_values( $input, array( 'purchase_cost','additional_cost','total_cost','wholesale_price' ), array( 'purchase_cost','total_cost','wholesale_price' ) ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=adc-vehicle-acquisition' ) ) );
		exit;
	}
}
