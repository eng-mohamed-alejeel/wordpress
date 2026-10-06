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
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<h1><?php echo esc_html__( 'إرجاع المركبات', 'auto-dealership-core' ); ?></h1>
			<p><?php echo esc_html__( 'يسجّل هذا الإجراء استلام المركبة ويضع الالتزام المالي في انتظار الاسترداد. لا ينفذ استردادًا ماليًا.', 'auto-dealership-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_receive_vehicle_return">
				<?php wp_nonce_field( 'adc_receive_vehicle_return' ); ?>
				<select name="delivery_id" required><option value=""><?php echo esc_html__( 'التسليم', 'auto-dealership-core' ); ?></option><?php foreach ( $eligible as $row ) : ?><option value="<?php echo absint( $row['id'] ); ?>"><?php echo esc_html( $row['stock_number'] . ' — ' . $row['brand'] . ' ' . $row['model'] ); ?></option><?php endforeach; ?></select>
				<select name="location_id" required><option value=""><?php echo esc_html__( 'موقع الاستلام', 'auto-dealership-core' ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo absint( $location['id'] ); ?>"><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select>
				<select name="condition" required><option value="good"><?php echo esc_html__( 'جيدة', 'auto-dealership-core' ); ?></option><option value="damaged"><?php echo esc_html__( 'متضررة', 'auto-dealership-core' ); ?></option><option value="incomplete"><?php echo esc_html__( 'ناقصة', 'auto-dealership-core' ); ?></option></select>
				<input type="number" min="0" name="odometer" required placeholder="<?php echo esc_attr__( 'قراءة العداد', 'auto-dealership-core' ); ?>">
				<input name="document_reference" required maxlength="100" placeholder="<?php echo esc_attr__( 'مرجع مستند الإرجاع', 'auto-dealership-core' ); ?>">
				<textarea name="reason" required maxlength="2000" placeholder="<?php echo esc_attr__( 'سبب الإرجاع', 'auto-dealership-core' ); ?>"></textarea>
				<button class="button button-primary"><?php echo esc_html__( 'استلام المركبة المرتجعة', 'auto-dealership-core' ); ?></button>
			</form>
			<h2><?php echo esc_html__( 'سجل الإرجاعات', 'auto-dealership-core' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'المركبة', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'المستند', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'السبب', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'التاريخ', 'auto-dealership-core' ); ?></th></tr></thead><tbody><?php foreach ( $returns as $row ) : ?><tr><td><?php echo esc_html( $row['stock_number'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $row['financial_status'] ) ); ?></td><td><?php echo esc_html( $row['document_reference'] ); ?></td><td><?php echo esc_html( $row['reason'] ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr><?php endforeach; ?></tbody></table>
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
