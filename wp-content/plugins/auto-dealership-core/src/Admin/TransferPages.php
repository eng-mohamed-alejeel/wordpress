<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\TransferService;
use AutoDealership\Inventory\VehicleService;

defined( 'ABSPATH' ) || exit;

/** Destination approval, source dispatch and receiving branch acknowledgement. */
final class TransferPages {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_transfer_decision', array( self::class, 'decision' ) );
		add_action( 'admin_post_adc_transfer_dispatch', array( self::class, 'dispatch' ) );
		add_action( 'admin_post_adc_transfer_receipt', array( self::class, 'receipt' ) );
	}

	public static function menu(): void {
		if ( current_user_can( 'adc_transfer_inventory' ) ) {
			add_submenu_page( 'adc-workspace', __( 'Transfer Queue', 'auto-dealership-core' ), __( 'Transfer Queue', 'auto-dealership-core' ), 'adc_transfer_inventory', 'adc-transfer-queue', array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_transfer_inventory' ) ) { wp_die( esc_html__( 'Transfer permission required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		global $wpdb;
		list( $source, $source_args ) = \AutoDealership\Security\BranchScope::predicate( 't.from_branch_id' );
		list( $destination, $destination_args ) = \AutoDealership\Security\BranchScope::predicate( 't.to_branch_id' );
		$scope = 'WHERE (' . $source . ' OR ' . $destination . ')';
		$scope_args = array_merge( $source_args, $destination_args );
		$scope = $scope_args ? $wpdb->prepare( $scope, $scope_args ) : $scope;
		$rows = $wpdb->get_results( 'SELECT t.*,v.vin,v.brand,v.model,s.name source_name,d.name destination_name FROM ' . Schema::table( 'vehicle_transfers' ) . ' t INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=t.vehicle_id INNER JOIN ' . Schema::table( 'branches' ) . ' s ON s.id=t.from_branch_id INNER JOIN ' . Schema::table( 'branches' ) . ' d ON d.id=t.to_branch_id ' . $scope . ' ORDER BY t.id DESC LIMIT 200', ARRAY_A ) ?: array();
		?>
		<div class="wrap" dir="rtl"><h1><?php esc_html_e( 'Transfer Queue', 'auto-dealership-core' ); ?></h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Transfer step saved.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Transfer step failed. Check its status and your branch assignment.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th>ID</th><th>VIN</th><th><?php esc_html_e( 'Vehicle', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Route', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Reason', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Status', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Action', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : ?><tr><td><?php echo absint( $row['id'] ); ?></td><td><code><?php echo esc_html( $row['vin'] ); ?></code></td><td><?php echo esc_html( $row['brand'] . ' ' . $row['model'] ); ?></td><td><?php echo esc_html( $row['source_name'] . ' → ' . $row['destination_name'] ); ?></td><td><?php echo esc_html( $row['reason'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td>
		<?php if ( 'requested' === $row['status'] && (int) $row['requested_by'] !== get_current_user_id() && VehicleService::user_can_access_branch( (int) $row['to_branch_id'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_transfer_decision"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_transfer_decision_' . (int) $row['id'] ); ?><button class="button button-primary" name="approve" value="1"><?php esc_html_e( 'Approve', 'auto-dealership-core' ); ?></button> <button class="button" name="approve" value="0"><?php esc_html_e( 'Reject', 'auto-dealership-core' ); ?></button></form>
		<?php elseif ( 'approved' === $row['status'] && (int) $row['requested_by'] === get_current_user_id() ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_transfer_dispatch"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_transfer_dispatch_' . (int) $row['id'] ); ?><button class="button button-primary"><?php esc_html_e( 'Mark dispatched', 'auto-dealership-core' ); ?></button></form>
		<?php elseif ( 'dispatched' === $row['status'] && (int) $row['requested_by'] !== get_current_user_id() && (int) $row['dispatched_by'] !== get_current_user_id() && VehicleService::user_can_access_branch( (int) $row['to_branch_id'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_transfer_receipt"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_transfer_receipt_' . (int) $row['id'] ); ?><button class="button button-primary"><?php esc_html_e( 'Confirm receipt', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
		<?php if ( ! $rows ) : ?><tr><td colspan="7"><?php esc_html_e( 'No transfer requests in your branch scope.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table></div>
		<?php
	}

	public static function decision(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_transfer_decision_' . $id );
		self::redirect( TransferService::decide( $id, '1' === (string) ( $_POST['approve'] ?? '' ) ) );
	}

	public static function dispatch(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_transfer_dispatch_' . $id );
		self::redirect( TransferService::dispatch( $id ) );
	}

	public static function receipt(): void {
		$id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_transfer_receipt_' . $id );
		self::redirect( TransferService::receive( $id ) );
	}

	private static function redirect( $result ): void {
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=adc-transfer-queue' ) ) );
		exit;
	}
}
