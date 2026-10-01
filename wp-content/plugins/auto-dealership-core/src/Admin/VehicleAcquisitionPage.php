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
		<div class="wrap" dir="rtl"><h1><?php esc_html_e( 'Vehicle acquisition data', 'auto-dealership-core' ); ?></h1>
		<p><?php esc_html_e( 'All monetary values use SAR halalas. Total cost is entered explicitly until an approved business formula is configured.', 'auto-dealership-core' ); ?></p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Acquisition data saved and audited.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Acquisition data could not be saved.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Vehicle', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Current values', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Update', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $vehicles as $vehicle ) : $record = VehicleAcquisitionService::get( (int) $vehicle['id'] ); if ( is_wp_error( $record ) ) { continue; } ?><tr><td><?php echo esc_html( $vehicle['stock_number'] . ' — ' . $vehicle['brand'] . ' ' . $vehicle['model'] ); ?><br><?php echo esc_html( $vehicle['status'] ); ?></td><td><?php echo esc_html( (string) ( $record['supplier_name'] ?: '—' ) ); ?><br><?php echo esc_html( sprintf( 'Purchase: %s / Additional: %s / Total: %s / Wholesale: %s', $record['purchase_cost'] ?? '—', $record['additional_cost'], $record['total_cost'] ?? '—', $record['wholesale_price'] ?? '—' ) ); ?></td><td><?php if ( current_user_can( 'adc_manage_vehicle_costs' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_update_vehicle_acquisition"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_update_vehicle_acquisition_' . (int) $vehicle['id'] ); ?><select name="supplier_id"><option value="0"><?php esc_html_e( 'No supplier selected', 'auto-dealership-core' ); ?></option><?php foreach ( $suppliers as $supplier ) : ?><option value="<?php echo absint( $supplier['id'] ); ?>" <?php selected( (int) $record['supplier_id'], (int) $supplier['id'] ); ?>><?php echo esc_html( $supplier['supplier_code'] . ' — ' . $supplier['display_name'] ); ?></option><?php endforeach; ?></select><input type="number" min="0" name="purchase_cost" value="<?php echo esc_attr( null === $record['purchase_cost'] ? '' : (string) $record['purchase_cost'] ); ?>" placeholder="Purchase cost"><input type="number" min="0" name="additional_cost" value="<?php echo esc_attr( (string) $record['additional_cost'] ); ?>" placeholder="Additional cost"><input type="number" min="0" name="total_cost" value="<?php echo esc_attr( null === $record['total_cost'] ? '' : (string) $record['total_cost'] ); ?>" placeholder="Total cost"><input type="number" min="0" name="wholesale_price" value="<?php echo esc_attr( null === $record['wholesale_price'] ? '' : (string) $record['wholesale_price'] ); ?>" placeholder="Wholesale price"><input name="customs_reference" maxlength="100" value="<?php echo esc_attr( $record['customs_reference'] ); ?>" placeholder="Customs reference"><input type="date" name="arrival_date" value="<?php echo esc_attr( (string) $record['arrival_date'] ); ?>"><textarea name="internal_notes" maxlength="5000" rows="2" placeholder="Internal notes"><?php echo esc_textarea( $record['internal_notes'] ); ?></textarea><input name="document_media_ids" placeholder="Existing image/PDF attachment IDs, comma separated"><input name="reason" required maxlength="2000" placeholder="Reason for change"><button class="button button-primary"><?php esc_html_e( 'Save', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
		<?php if ( ! $vehicles ) : ?><tr><td colspan="3"><?php esc_html_e( 'No vehicles are available in your branch scope.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table></div>
		<?php
	}

	public static function save(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_update_vehicle_acquisition_' . $id );
		$input = array();
		foreach ( array( 'supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price','customs_reference','arrival_date','internal_notes' ) as $field ) { if ( array_key_exists( $field, $_POST ) ) { $input[$field] = wp_unslash( $_POST[$field] ); } }
		if ( isset( $_POST['document_media_ids'] ) && is_string( $_POST['document_media_ids'] ) && '' !== trim( $_POST['document_media_ids'] ) ) {
			$input['document_media_ids'] = array_map( 'trim', explode( ',', wp_unslash( $_POST['document_media_ids'] ) ) );
		}
		$result = VehicleAcquisitionService::update( $id, $input, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=adc-vehicle-acquisition' ) ) );
		exit;
	}
}
