<?php
namespace AutoDealership\Admin;

use AutoDealership\API\Routes;
use AutoDealership\Security\PublicRequestGuard;

defined( 'ABSPATH' ) || exit;

/** Read-only security posture for the deliberately public application surface. */
final class SecurityPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'adc-audit', __( 'Public API Security', 'auto-dealership-core' ), __( 'Public API Security', 'auto-dealership-core' ), 'adc_view_audit', 'adc-public-security', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_audit' ) ) {
			wp_die( esc_html__( 'You are not allowed to view public API security.', 'auto-dealership-core' ), '', array( 'response'=>403 ) );
		}
		$summary = PublicRequestGuard::summary();
		if ( is_wp_error( $summary ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Public API Security', 'auto-dealership-core' ) . '</h1><div class="notice notice-error"><p>' . esc_html( $summary->get_error_code() ) . '</p></div></div>';
			return;
		}
		$next = wp_next_scheduled( 'adc_prune_request_limits' );
		$cleanup_result = empty( $summary['health']['finished_at'] ) ? 'not_run' : ( $summary['health']['error'] ?? 'ok' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Public API Security', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'Read-only operational metadata. Client addresses, bucket fingerprints and trusted network rules are never displayed.', 'auto-dealership-core' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Method and route', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Policy', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Limit', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Window (seconds)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Active buckets', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( Routes::public_endpoints() as $endpoint => $policy_key ) : $policy = $summary['policies'][ $policy_key ]; ?>
				<tr><td><code><?php echo esc_html( $endpoint ); ?></code></td><td><code><?php echo esc_html( $policy_key ); ?></code></td><td><?php echo absint( $policy['limit'] ); ?></td><td><?php echo absint( $policy['window'] ); ?></td><td><?php echo absint( $summary['active_buckets'][ $policy_key ] ?? 0 ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<h2><?php esc_html_e( 'Runtime status', 'auto-dealership-core' ); ?></h2>
			<dl>
				<dt><?php esc_html_e( 'Trusted proxy rules configured', 'auto-dealership-core' ); ?></dt><dd><?php echo absint( $summary['trusted_proxy_rules'] ); ?></dd>
				<dt><?php esc_html_e( 'Next bounded cleanup (UTC)', 'auto-dealership-core' ); ?></dt><dd><?php echo esc_html( $next ? gmdate( 'Y-m-d H:i:s', $next ) : __( 'Not scheduled', 'auto-dealership-core' ) ); ?></dd>
				<dt><?php esc_html_e( 'Last cleanup (UTC)', 'auto-dealership-core' ); ?></dt><dd><?php echo esc_html( $summary['health']['finished_at'] ?? __( 'Not run yet', 'auto-dealership-core' ) ); ?></dd>
				<dt><?php esc_html_e( 'Last cleanup result', 'auto-dealership-core' ); ?></dt><dd><code><?php echo esc_html( $cleanup_result ); ?></code></dd>
			</dl>
		</div>
		<?php
	}
}
