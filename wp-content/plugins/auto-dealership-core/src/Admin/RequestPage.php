<?php
namespace AutoDealership\Admin;

use AutoDealership\Leads\RequestWorkflow;

defined( 'ABSPATH' ) || exit;

final class RequestPage {
	public static function boot(): void { add_action( 'admin_post_adc_update_request', array( self::class, 'save' ) ); }

	/** Embedded in scoped CRM history and plugin-owned request tables. */
	public static function render( int $lead_id, string $return_page = 'adc-crm' ): void {
		$request = RequestWorkflow::read( $lead_id );
		if ( is_wp_error( $request ) ) {
			if ( 'adc_request_not_found' !== $request->get_error_code() ) { echo '<p role="alert">' . esc_html( $request->get_error_message() ) . '</p>'; }
			return;
		}
		$labels = RequestWorkflow::allowed_statuses( $request['type'], $request['status'] );
		$return_page = in_array( $return_page, array( 'adc-crm', 'car-dealer-messages', 'car-dealer-bookings' ), true ) ? $return_page : 'adc-crm';
		echo '<section class="card"><h3>' . esc_html__( 'متابعة طلب العميل', 'auto-dealership-core' ) . ' #' . absint( $request['request_id'] ) . '</h3>';
		if ( ! $request['can_edit'] ) {
			echo '<p>' . esc_html( $labels[$request['status']] ?? $request['status'] ) . '</p><p>' . nl2br( esc_html( $request['customer_reply'] ) ) . '</p>';
			if ( 'booking' === $request['type'] ) { echo '<p>' . esc_html( $request['requested_date'] . ' ' . $request['requested_time'] . ' (' . wp_timezone_string() . ')' ) . '</p>'; }
			echo '</section>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adc_update_request">
			<input type="hidden" name="lead_id" value="<?php echo absint( $lead_id ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>">
			<input type="hidden" name="request_id" value="<?php echo absint( $request['request_id'] ); ?>">
			<input type="hidden" name="revision" value="<?php echo esc_attr( $request['revision'] ); ?>">
			<?php wp_nonce_field( 'adc_update_request_' . $lead_id ); ?>
			<p><label><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?> <select name="status"><?php foreach ( $labels as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $request['status'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<?php if ( 'booking' === $request['type'] ) : ?>
			<p><?php echo esc_html( sprintf( __( 'الموعد حسب توقيت الموقع: %s', 'auto-dealership-core' ), wp_timezone_string() ) ); ?></p>
			<p><label><?php esc_html_e( 'اليوم', 'auto-dealership-core' ); ?> <input type="date" name="requested_date" value="<?php echo esc_attr( $request['requested_date'] ); ?>"></label>
			<label><?php esc_html_e( 'الوقت', 'auto-dealership-core' ); ?> <input type="time" name="requested_time" value="<?php echo esc_attr( $request['requested_time'] ); ?>"></label></p>
			<?php endif; ?>
			<p><label><?php esc_html_e( 'الرد الظاهر للعميل', 'auto-dealership-core' ); ?><textarea class="widefat" name="customer_reply" maxlength="4000" rows="3"><?php echo esc_textarea( $request['customer_reply'] ); ?></textarea></label></p>
			<button class="button button-primary"><?php esc_html_e( 'حفظ وإظهار التحديث للعميل', 'auto-dealership-core' ); ?></button>
		</form></section>
		<?php
	}

	public static function save(): void {
		$lead_id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_update_request_' . $lead_id );
		$input = array();
		foreach ( array( 'status', 'customer_reply', 'requested_date', 'requested_time', 'revision' ) as $key ) {
			if ( isset( $_POST[$key] ) ) { $input[$key] = wp_unslash( $_POST[$key] ); }
		}
		$result = RequestWorkflow::update( $lead_id, $input );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link'=>true ) ); }
		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'adc-crm';
		$return_page = in_array( $return_page, array( 'adc-crm', 'car-dealer-messages', 'car-dealer-bookings' ), true ) ? $return_page : 'adc-crm';
		$args = array( 'page'=>$return_page, 'saved'=>1 );
		if ( 'adc-crm' === $return_page ) { $args['lead_id'] = $lead_id; }
		else { $args['request_id'] = absint( $_POST['request_id'] ?? 0 ); }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
