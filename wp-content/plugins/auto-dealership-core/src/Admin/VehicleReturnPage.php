<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\VehicleReturnService;

defined( 'ABSPATH' ) || exit;

final class VehicleReturnPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_receive_vehicle_return', array( self::class, 'receive' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'adc-workspace', __( 'Vehicle returns', 'auto-dealership-core' ), __( 'Vehicle returns', 'auto-dealership-core' ), 'adc_process_returns', 'adc-vehicle-returns', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_process_returns' ) ) { wp_die( '', '', array( 'response' => 403 ) ); }
		$eligible = VehicleReturnService::eligible_deliveries();
		$returns = VehicleReturnService::list_for_current_user();
		$locations = VehicleReturnService::eligible_locations();
		?>
		<div class="wrap" dir="rtl">
			<h1>إرجاع المركبات</h1>
			<p>يسجّل هذا الإجراء استلام المركبة ويضع الالتزام المالي في انتظار الاسترداد. لا ينفذ استردادًا ماليًا.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_receive_vehicle_return">
				<?php wp_nonce_field( 'adc_receive_vehicle_return' ); ?>
				<select name="delivery_id" required><option value="">التسليم</option><?php foreach ( $eligible as $row ) : ?><option value="<?php echo absint( $row['id'] ); ?>"><?php echo esc_html( $row['stock_number'] . ' — ' . $row['brand'] . ' ' . $row['model'] ); ?></option><?php endforeach; ?></select>
				<select name="location_id" required><option value="">موقع الاستلام</option><?php foreach ( $locations as $location ) : ?><option value="<?php echo absint( $location['id'] ); ?>"><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select>
				<select name="condition" required><option value="good">جيدة</option><option value="damaged">متضررة</option><option value="incomplete">ناقصة</option></select>
				<input type="number" min="0" name="odometer" required placeholder="قراءة العداد">
				<input name="document_reference" required maxlength="100" placeholder="مرجع مستند الإرجاع">
				<textarea name="reason" required maxlength="2000" placeholder="سبب الإرجاع"></textarea>
				<button class="button button-primary">استلام المركبة المرتجعة</button>
			</form>
			<h2>سجل الإرجاعات</h2>
			<table class="widefat striped"><thead><tr><th>المركبة</th><th>الحالة</th><th>المستند</th><th>السبب</th><th>التاريخ</th></tr></thead><tbody><?php foreach ( $returns as $row ) : ?><tr><td><?php echo esc_html( $row['stock_number'] ); ?></td><td><?php echo esc_html( $row['financial_status'] ); ?></td><td><?php echo esc_html( $row['document_reference'] ); ?></td><td><?php echo esc_html( $row['reason'] ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr><?php endforeach; ?></tbody></table>
		</div>
		<?php
	}

	public static function receive(): void {
		check_admin_referer( 'adc_receive_vehicle_return' );
		$result = VehicleReturnService::receive( absint( $_POST['delivery_id'] ?? 0 ), array(
			'location_id' => absint( $_POST['location_id'] ?? 0 ),
			'condition' => sanitize_key( wp_unslash( $_POST['condition'] ?? '' ) ),
			'odometer' => absint( $_POST['odometer'] ?? 0 ),
			'document_reference' => sanitize_text_field( wp_unslash( $_POST['document_reference'] ?? '' ) ),
			'reason' => sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ),
		) );
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=adc-vehicle-returns' ) ) );
		exit;
	}
}
