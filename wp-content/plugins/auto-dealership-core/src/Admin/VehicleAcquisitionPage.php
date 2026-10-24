<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\VehicleAcquisitionService;
use AutoDealership\Inventory\VehicleAcquisitionDocuments;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Purchasing\SupplierService;

defined( 'ABSPATH' ) || exit;

final class VehicleAcquisitionPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_update_vehicle_acquisition', array( self::class, 'save' ) );
		add_action( 'admin_post_adc_upload_acquisition_document', array( self::class, 'upload_document' ) );
		add_action( 'admin_post_adc_migrate_acquisition_documents', array( self::class, 'migrate_documents' ) );
		add_action( 'admin_post_adc_download_acquisition_document', array( VehicleAcquisitionDocuments::class, 'download' ) );
	}

	public static function menu(): void {
		if ( current_user_can( 'adc_view_vehicle_costs' ) || current_user_can( 'adc_manage_vehicle_costs' ) ) {
			$capability = current_user_can( 'adc_view_vehicle_costs' ) ? 'adc_view_vehicle_costs' : 'adc_manage_vehicle_costs';
			add_submenu_page( 'adc-workspace', __( 'Vehicle acquisition', 'auto-dealership-core' ), __( 'Vehicle acquisition', 'auto-dealership-core' ), $capability, 'adc-vehicle-acquisition', array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_vehicle_costs' ) && ! current_user_can( 'adc_manage_vehicle_costs' ) ) { wp_die( '', '', array( 'response'=>403 ) ); }
		$vehicle_page=VehicleService::page_for_current_user(max(1,absint($_GET['paged']??1)));$vehicles=is_wp_error($vehicle_page)?array():$vehicle_page['rows'];
		$suppliers = SupplierService::all( true );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'Vehicle acquisition data', 'auto-dealership-core' ); ?></h1>
		<p><?php esc_html_e( 'All monetary values use SAR. Total cost is entered explicitly until an approved business formula is configured.', 'auto-dealership-core' ); ?></p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Acquisition data saved and audited.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['migrated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Legacy acquisition documents were copied to private storage. Public originals were retained for separate review.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php if ( is_string( $_GET['error'] ) && 'adc_acquisition_public_document_unsupported' === sanitize_key( wp_unslash( $_GET['error'] ) ) ) { esc_html_e( 'Public media-library IDs are no longer accepted for acquisition documents. Use the private upload section.', 'auto-dealership-core' ); } else { esc_html_e( 'Acquisition data could not be saved.', 'auto-dealership-core' ); } ?></p></div><?php endif; ?>
		<?php self::render_private_documents( $vehicles ); ?>
		<div class="adc-table-scroll" data-horizontal-scroll><table class="widefat striped adc-acquisition-table"><thead><tr><th><?php esc_html_e( 'Vehicle', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Current values', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Update', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $vehicles as $vehicle ) : $record = VehicleAcquisitionService::get( (int) $vehicle['id'] ); if ( is_wp_error( $record ) ) { continue; } ?><tr><td><?php echo esc_html( $vehicle['stock_number'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'brand', (string) $vehicle['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'model', (string) $vehicle['model'] ) ); ?><br><?php echo esc_html( \AutoDealership\Core\Localization::label( (string ) $vehicle['status'] ) ); ?></td><td><?php echo esc_html( (string ) ( \AutoDealership\Content\StoredTranslations::text( 'suppliers', (int) $record['supplier_id'], 'display_name', (string) $record['supplier_name'] ) ?: '—' ) ); ?><br><?php echo esc_html( sprintf( 'Purchase: %s / Additional: %s / Total: %s / Wholesale: %s', \AutoDealership\Pricing\Money::display( $record['purchase_cost'] ), \AutoDealership\Pricing\Money::display( $record['additional_cost'] ), \AutoDealership\Pricing\Money::display( $record['total_cost'] ), \AutoDealership\Pricing\Money::display( $record['wholesale_price'] ) ) ); ?></td><td><?php if ( current_user_can( 'adc_manage_vehicle_costs' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_update_vehicle_acquisition"><input type="hidden" name="adc_money_unit" value="SAR"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_update_vehicle_acquisition_' . (int) $vehicle['id'] ); ?><label class="adc-field adc-field--long-choice"><?php esc_html_e( 'المورد', 'auto-dealership-core' ); ?><select name="supplier_id" data-ab-long-options><option value="0"><?php esc_html_e( 'No supplier selected', 'auto-dealership-core' ); ?></option><?php foreach ( $suppliers as $supplier ) : ?><option value="<?php echo absint( $supplier['id'] ); ?>" <?php selected( (int) $record['supplier_id'], (int) $supplier['id'] ); ?>><?php echo esc_html( $supplier['supplier_code'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'suppliers', (int) $supplier['id'], 'display_name', (string) $supplier['display_name'] ) ); ?></option><?php endforeach; ?></select></label><label class="adc-field"><?php echo esc_html__( 'Purchase cost', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="purchase_cost" value="<?php echo esc_attr( null === $record['purchase_cost'] ? '' : \AutoDealership\Pricing\Money::decimal( $record['purchase_cost'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Purchase cost', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Additional cost', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="additional_cost" value="<?php echo esc_attr( \AutoDealership\Pricing\Money::decimal( $record['additional_cost'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Additional cost', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Total cost', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="total_cost" value="<?php echo esc_attr( null === $record['total_cost'] ? '' : \AutoDealership\Pricing\Money::decimal( $record['total_cost'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Total cost', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Wholesale price', 'auto-dealership-core' ); ?><input step="0.01" type="number" min="0" name="wholesale_price" value="<?php echo esc_attr( null === $record['wholesale_price'] ? '' : \AutoDealership\Pricing\Money::decimal( $record['wholesale_price'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Wholesale price', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Customs reference', 'auto-dealership-core' ); ?><input name="customs_reference" maxlength="100" value="<?php echo esc_attr( $record['customs_reference'] ); ?>" placeholder="<?php echo esc_attr__( 'Customs reference', 'auto-dealership-core' ); ?>"></label><label class="adc-field"><?php esc_html_e( 'تاريخ الوصول', 'auto-dealership-core' ); ?><input type="date" name="arrival_date" value="<?php echo esc_attr( (string) $record['arrival_date'] ); ?>"></label><label class="adc-field"><?php echo esc_html__( 'Internal notes', 'auto-dealership-core' ); ?><textarea name="internal_notes" maxlength="5000" rows="2" placeholder="<?php echo esc_attr__( 'Internal notes', 'auto-dealership-core' ); ?>"><?php echo esc_textarea( $record['internal_notes'] ); ?></textarea></label><label class="adc-field"><?php echo esc_html__( 'Reason for change', 'auto-dealership-core' ); ?><input name="reason" required maxlength="2000" placeholder="<?php echo esc_attr__( 'Reason for change', 'auto-dealership-core' ); ?>"></label><button class="button button-primary"><?php esc_html_e( 'Save', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
		<?php if ( ! $vehicles ) : ?><tr><td colspan="3"><?php InventoryListState::render($vehicle_page); ?></td></tr><?php endif; ?></tbody></table></div><?php InventoryListState::pagination($vehicle_page,'adc-vehicle-acquisition'); ?></div>
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
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', is_wp_error( $result ) ? $result->get_error_code() : '1', admin_url( 'admin.php?page=adc-vehicle-acquisition' ) ) );
		exit;
	}

	private static function render_private_documents( array $vehicles ): void {
		echo '<h2>' . esc_html__( 'Private acquisition documents', 'auto-dealership-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'New PDF, JPEG, PNG, or WebP evidence is stored privately and can only be downloaded by authorized staff in the vehicle branch.', 'auto-dealership-core' ) . '</p>';
		echo '<p>' . esc_html__( 'Do not add new public media-library attachment IDs here. Existing public references require review before their files can be moved safely.', 'auto-dealership-core' ) . '</p>';
		foreach ( $vehicles as $vehicle ) {
			$id = (int) $vehicle['id'];
			$record = VehicleAcquisitionService::get( $id );
			if ( is_wp_error( $record ) ) {
				echo '<p class="notice notice-error">' . esc_html__( 'Private acquisition documents could not be loaded for this vehicle.', 'auto-dealership-core' ) . '</p>';
				continue;
			}
			echo '<h3>' . esc_html( $vehicle['stock_number'] ) . '</h3>';
			if ( $record['legacy_public_document_count'] > 0 ) {
				echo '<p class="notice notice-warning">' . esc_html( sprintf( _n( '%d legacy public media attachment requires review; it was not moved or deleted.', '%d legacy public media attachments require review; they were not moved or deleted.', $record['legacy_public_document_count'], 'auto-dealership-core' ), $record['legacy_public_document_count'] ) ) . '</p>';
				if ( current_user_can( 'adc_manage_vehicle_costs' ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					echo '<input type="hidden" name="action" value="adc_migrate_acquisition_documents"><input type="hidden" name="vehicle_id" value="' . esc_attr( (string) $id ) . '">';
					wp_nonce_field( 'adc_migrate_acquisition_documents_' . $id );
					echo '<button class="button">' . esc_html__( 'Copy legacy documents to private storage', 'auto-dealership-core' ) . '</button></form>';
				}
			}
			if ( $record['migrated_public_original_count'] > 0 ) {
				echo '<p class="notice notice-warning">' . esc_html( sprintf( _n( '%d public original remains in the media library after copying; review its other uses before removing it.', '%d public originals remain in the media library after copying; review their other uses before removing them.', $record['migrated_public_original_count'], 'auto-dealership-core' ), $record['migrated_public_original_count'] ) ) . '</p>';
			}
			$documents = $record['private_documents'];
			if ( $documents ) {
				echo '<ul>';
				foreach ( $documents as $document ) {
					$url = wp_nonce_url( add_query_arg( array( 'action'=>'adc_download_acquisition_document', 'id'=>$document['id'] ), admin_url( 'admin-post.php' ) ), 'adc_download_acquisition_document_' . $document['id'] );
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $document['file_name'] ) . '</a> (' . esc_html( size_format( $document['file_size'] ) ) . ')</li>';
				}
				echo '</ul>';
			}
			if ( current_user_can( 'adc_manage_vehicle_costs' ) ) {
				echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="adc_upload_acquisition_document"><input type="hidden" name="vehicle_id" value="' . esc_attr( (string) $id ) . '">';
				wp_nonce_field( 'adc_upload_acquisition_document_' . $id );
				echo '<label>' . esc_html__( 'Upload private PDF or image', 'auto-dealership-core' ) . ' <input type="file" name="acquisition_document" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required></label> ';
				echo '<button class="button">' . esc_html__( 'Upload document', 'auto-dealership-core' ) . '</button></form>';
			}
		}
	}

	public static function upload_document(): void {
		$vehicle_id = absint( $_POST['vehicle_id'] ?? 0 );
		check_admin_referer( 'adc_upload_acquisition_document_' . $vehicle_id );
		$file = $_FILES['acquisition_document'] ?? null;
		if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_string( $file['name'] ?? null ) || ! is_int( $file['size'] ?? null ) || $file['size'] > 20971520 ) {
			$result = new \WP_Error( 'adc_acquisition_document_upload', __( 'The private acquisition document could not be processed.', 'auto-dealership-core' ) );
		} else {
			$bytes = file_get_contents( $file['tmp_name'] );
			$result = false === $bytes ? new \WP_Error( 'adc_acquisition_document_upload', __( 'The private acquisition document could not be processed.', 'auto-dealership-core' ) ) : VehicleAcquisitionDocuments::store( $vehicle_id, $file['name'], $bytes );
		}
		$url = add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', is_wp_error( $result ) ? $result->get_error_code() : '1', admin_url( 'admin.php?page=adc-vehicle-acquisition' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function migrate_documents(): void {
		$vehicle_id = absint( $_POST['vehicle_id'] ?? 0 );
		check_admin_referer( 'adc_migrate_acquisition_documents_' . $vehicle_id );
		$result = VehicleAcquisitionDocuments::migrate_legacy( $vehicle_id );
		$url = add_query_arg( is_wp_error( $result ) ? 'error' : 'migrated', is_wp_error( $result ) ? $result->get_error_code() : $result['migrated'], admin_url( 'admin.php?page=adc-vehicle-acquisition' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
