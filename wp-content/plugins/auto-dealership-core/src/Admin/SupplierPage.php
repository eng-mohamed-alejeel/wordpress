<?php
namespace AutoDealership\Admin;

use AutoDealership\Purchasing\SupplierService;

defined( 'ABSPATH' ) || exit;

final class SupplierPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_create_supplier', array( self::class, 'create' ) );
		add_action( 'admin_post_adc_set_supplier_status', array( self::class, 'status' ) );
	}

	public static function menu(): void {
		if ( current_user_can( 'adc_view_suppliers' ) || current_user_can( 'adc_manage_suppliers' ) ) {
			add_submenu_page( 'adc-workspace', __( 'Suppliers', 'auto-dealership-core' ), __( 'Suppliers', 'auto-dealership-core' ), 'adc_view_suppliers', 'adc-suppliers', array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_suppliers' ) && ! current_user_can( 'adc_manage_suppliers' ) ) { wp_die( '', '', array( 'response'=>403 ) ); }
		$rows = SupplierService::all();
		?>
		<div class="wrap" dir="rtl"><h1><?php esc_html_e( 'Supplier directory', 'auto-dealership-core' ); ?></h1>
		<p><?php esc_html_e( 'This directory prepares verified supplier references. Purchase-order states and approval limits remain disabled until the business policy is approved.', 'auto-dealership-core' ); ?></p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Supplier change saved and audited.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Supplier change could not be saved.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( current_user_can( 'adc_manage_suppliers' ) ) : ?>
		<form class="card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_create_supplier"><?php wp_nonce_field( 'adc_create_supplier' ); ?>
		<h2><?php esc_html_e( 'Add supplier', 'auto-dealership-core' ); ?></h2>
		<p><input name="supplier_code" maxlength="64" required placeholder="SUPPLIER-CODE"> <input name="display_name" maxlength="190" required placeholder="Display name"> <input name="legal_name" maxlength="190" placeholder="Legal name"></p>
		<p><input name="country" maxlength="80" placeholder="Country"> <input name="tax_number" maxlength="100" placeholder="Tax number"></p>
		<p><input name="contact_name" maxlength="190" placeholder="Contact name"> <input name="contact_email" type="email" maxlength="190" placeholder="Email"> <input name="contact_phone" maxlength="40" placeholder="Phone"></p>
		<p><textarea name="notes" maxlength="4000" rows="3" class="large-text" placeholder="Internal notes"></textarea></p><?php submit_button( __( 'Create supplier', 'auto-dealership-core' ) ); ?>
		</form><?php endif; ?>
		<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'Code / name', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Country', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Contact', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Status', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : ?><tr><td><?php echo absint( $row['id'] ); ?></td><td><code><?php echo esc_html( $row['supplier_code'] ); ?></code><br><?php echo esc_html( $row['display_name'] ); ?></td><td><?php echo esc_html( $row['country'] ); ?></td><td><?php echo esc_html( implode( ' — ', array_filter( array( $row['contact_name'] ?? '', $row['contact_email'] ?? '', $row['contact_phone'] ?? '' ) ) ) ); ?></td><td><?php echo $row['active'] ? esc_html__( 'Active', 'auto-dealership-core' ) : esc_html__( 'Inactive', 'auto-dealership-core' ); ?><?php if ( current_user_can( 'adc_manage_suppliers' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_set_supplier_status"><input type="hidden" name="id" value="<?php echo absint( $row['id'] ); ?>"><input type="hidden" name="active" value="<?php echo $row['active'] ? '0' : '1'; ?>"><?php wp_nonce_field( 'adc_set_supplier_status_' . (int) $row['id'] ); ?><input name="reason" maxlength="1000" required placeholder="Reason"><button class="button"><?php echo $row['active'] ? esc_html__( 'Deactivate', 'auto-dealership-core' ) : esc_html__( 'Activate', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
		<?php if ( ! $rows ) : ?><tr><td colspan="5"><?php esc_html_e( 'No suppliers have been entered. The empty state is expected until approved business data is available.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table></div>
		<?php
	}

	public static function create(): void {
		check_admin_referer( 'adc_create_supplier' );
		self::redirect( SupplierService::create( wp_unslash( $_POST ) ) );
	}

	public static function status(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_set_supplier_status_' . $id );
		self::redirect( SupplierService::set_active( $id, '1' === (string) ( $_POST['active'] ?? '' ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) ) );
	}

	private static function redirect( $result ): void {
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=adc-suppliers' ) ) );
		exit;
	}
}
