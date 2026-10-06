<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Leads\CustomerIdentity;

defined( 'ABSPATH' ) || exit;

final class CustomerIdentityPage {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			add_submenu_page(
				'adc-settings',
				__( 'مراجعة ملفات العملاء', 'auto-dealership-core' ),
				__( 'مراجعة ملفات العملاء', 'auto-dealership-core' ),
				'manage_options',
				'adc-customer-identities',
				array( self::class, 'render' )
			);
		} );
		add_action( 'admin_post_adc_merge_customers', array( self::class, 'save' ) );
	}

	public static function link_for_lead( int $lead_id ): void {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $lead_id ) );
		if ( $id ) { echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page'=>'adc-customer-identities', 'source_id'=>$id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'مراجعة ملف العميل والتكرار', 'auto-dealership-core' ) . ' #' . $id . '</a></p>'; }
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'ليست لديك صلاحية.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$source = absint( $_GET['source_id'] ?? 0 ); $target = absint( $_GET['target_id'] ?? 0 );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'مراجعة ملفات العملاء', 'auto-dealership-core' ); ?></h1>
		<p><?php esc_html_e( 'تطابق البريد والجوال يرشح الملفات للمراجعة. أكّد الهوية من دليل مستقل قبل الدمج، وأبقِ الملف المرتبط بالحساب وجهةً للدمج.', 'auto-dealership-core' ); ?></p>
		<?php if ( isset( $_GET['merged'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'تم دمج الملفات وتوثيق العملية.', 'auto-dealership-core' ) . '</p></div>'; } ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="adc-customer-identities">
		<label><?php esc_html_e( 'رقم ملف المصدر', 'auto-dealership-core' ); ?> <input name="source_id" type="number" min="1" required value="<?php echo $source ?: ''; ?>"></label>
		<label><?php esc_html_e( 'رقم الملف الذي سيبقى', 'auto-dealership-core' ); ?> <input name="target_id" type="number" min="1" value="<?php echo $target ?: ''; ?>"></label>
		<button class="button"><?php esc_html_e( 'بحث / معاينة', 'auto-dealership-core' ); ?></button></form>
		<?php
		if ( $source && ! $target ) {
			$rows = CustomerIdentity::candidates( $source );
			if ( is_wp_error( $rows ) ) { self::error( $rows ); }
			else {
				echo '<h2>' . esc_html__( 'ملفات مرشحة للمراجعة', 'auto-dealership-core' ) . '</h2><ul>';
				foreach ( $rows as $row ) {
					$url = add_query_arg( array( 'page'=>'adc-customer-identities', 'source_id'=>$source, 'target_id'=>$row['id'] ), admin_url( 'admin.php' ) );
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( '#' . $row['id'] . ' — ' . $row['full_name'] ) . '</a></li>';
				}
				echo '</ul>';
				if ( ! $rows ) { echo '<p>' . esc_html__( 'لا توجد ملفات تطابق البريد والجوال المسجلين.', 'auto-dealership-core' ) . '</p>'; }
			}
		}
		if ( $source && $target ) {
			$preview = CustomerIdentity::preview( $source, $target );
			if ( is_wp_error( $preview ) ) { self::error( $preview ); }
			else {
				echo '<h2>' . esc_html__( 'معاينة الدمج', 'auto-dealership-core' ) . '</h2><table class="widefat"><thead><tr><th>' . esc_html__( 'البيان', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المصدر', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الملف الذي سيبقى', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
				foreach ( array( 'id'=>'الرقم', 'full_name'=>'الاسم', 'email'=>'البريد', 'mobile'=>'الجوال', 'account_user_id'=>'الحساب المرتبط' ) as $field => $label ) { echo '<tr><th>' . esc_html__( $label, 'auto-dealership-core' ) . '</th><td>' . esc_html( $preview['source'][$field] ?? '—' ) . '</td><td>' . esc_html( $preview['target'][$field] ?? '—' ) . '</td></tr>'; }
				echo '</tbody></table><p>' . esc_html( sprintf( __( 'ستُنقل %d فرصة إلى الملف الذي سيبقى، وتُحذف بيانات الاتصال من المصدر مع إبقاء مرجع الدمج. لا تتوفر عملية تراجع تلقائية.', 'auto-dealership-core' ), count( $preview['lead_ids'] ) ) ) . '</p>';
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adc_merge_customers"><input type="hidden" name="source_id" value="<?php echo $source; ?>"><input type="hidden" name="target_id" value="<?php echo $target; ?>">
				<input type="hidden" name="revision" value="<?php echo esc_attr( $preview['revision'] ); ?>"><?php wp_nonce_field( 'adc_merge_customers_' . $source . '_' . $target ); ?>
				<p><label><?php esc_html_e( 'مرجع مراجعة الهوية (رقم إجراء داخلي، دون بيانات شخصية)', 'auto-dealership-core' ); ?> <input name="evidence" maxlength="120" required></label></p>
				<p><label><input type="checkbox" name="verified" value="1" required> <?php esc_html_e( 'راجعت الهوية بدليل مستقل وأؤكد أن الملفين للشخص نفسه.', 'auto-dealership-core' ); ?></label></p>
				<button class="button button-primary"><?php esc_html_e( 'تنفيذ الدمج الموثّق', 'auto-dealership-core' ); ?></button></form>
				<?php
			}
		}
		echo '</div>';
	}

	private static function error( \WP_Error $error ): void { echo '<p role="alert">' . esc_html( $error->get_error_message() ) . '</p>'; }
	public static function save(): void {
		$source = absint( $_POST['source_id'] ?? 0 ); $target = absint( $_POST['target_id'] ?? 0 );
		check_admin_referer( 'adc_merge_customers_' . $source . '_' . $target );
		$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? wp_unslash( $_POST['revision'] ) : '';
		$evidence = isset( $_POST['evidence'] ) && is_string( $_POST['evidence'] ) ? wp_unslash( $_POST['evidence'] ) : '';
		$result = CustomerIdentity::merge( $source, $target, $revision, $evidence, '1' === ( $_POST['verified'] ?? '' ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link'=>true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-customer-identities', 'merged'=>1 ), admin_url( 'admin.php' ) ) ); exit;
	}
}
