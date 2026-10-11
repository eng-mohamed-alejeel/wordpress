<?php
namespace AutoDealership\Admin;
use AutoDealership\Operations\ReleaseEvidence;
use AutoDealership\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class ReleaseEvidencePage {
	private static function t( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void { add_submenu_page( 'adc-workspace', self::t( 'أدلة الإطلاق', 'Release evidence' ), self::t( 'أدلة الإطلاق', 'Release evidence' ), 'manage_options', 'adc-release-evidence', array( self::class, 'render' ) ); } );
		add_action( 'admin_post_adc_release_evidence', array( self::class, 'save' ) );
	}
	public static function render(): void {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( '', '', array( 'response'=>403 ) ); }
		echo '<div class="wrap"><h1>' . esc_html( self::t( 'أدلة الإطلاق', 'Release evidence' ) ) . '</h1><p>' . esc_html( self::t( 'سجّل مرجع تقرير حقيقي وبصمته وتاريخ انتهاء صلاحيته. يتطلب الاعتماد مسؤولًا آخر راجع الدليل. اعتماد الدليل لا يفعّل تكاملًا أو يغيّر المخزون.', 'Record an actual report reference, SHA-256 and expiry date. A different administrator must review the evidence. Evidence approval does not enable integrations or change inventory.' ) ) . '</p><p><code>' . esc_html( site_url() . ' | ' . ADC_VERSION ) . '</code></p>';
		if ( isset( $_GET['result'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( 'saved' === $_GET['result'] ? self::t( 'تم الحفظ وتسجيل التدقيق.', 'Saved and audited.' ) : self::t( 'تعذر الحفظ؛ تحقق من المدخلات ومن استقلال المراجع وصلاحية الدليل.', 'Unable to save; check the inputs, independent reviewer and evidence validity.' ) ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_release_evidence"><input type="hidden" name="op" value="submit">'; wp_nonce_field( 'adc_release_evidence_submit_0' );
		echo '<p><label>' . esc_html( self::t( 'البند', 'Gate' ) ) . ' <select name="gate_key">'; foreach ( ReleaseEvidence::GATES as $gate ) { echo '<option>' . esc_html( $gate ) . '</option>'; } echo '</select></label></p>';
		foreach ( array( 'artifact_reference'=>self::t( 'مرجع التقرير دون بيانات اعتماد', 'Report reference without credentials' ), 'artifact_hash'=>'SHA-256', 'valid_until'=>self::t( 'صالح حتى (UTC)', 'Valid until (UTC)' ), 'reason'=>self::t( 'ملخص نتائج التحقق', 'Verification findings' ) ) as $key=>$label ) { echo '<p><label>' . esc_html( $label ) . ' <input required name="' . esc_attr( $key ) . '" type="' . ( 'valid_until' === $key ? 'date' : 'text' ) . '" maxlength="' . ( 'artifact_hash' === $key ? '64' : '255' ) . '" class="regular-text"></label></p>'; }
		submit_button( self::t( 'تقديم الدليل للمراجعة', 'Submit evidence for review' ) ); echo '</form>';
		$results = QueueTable::fetch( 'SELECT e.* FROM ' . Schema::table( 'release_evidence' ) . ' e WHERE 1=1 ORDER BY e.id DESC LIMIT 100', array(), array( 'e.gate_key','e.artifact_reference','e.environment_url','e.status' ) ); QueueTable::controls( 'adc-release-evidence' );
		echo '<table class="widefat striped"><caption>' . esc_html( self::t( 'سجل الأدلة ومراجعاتها', 'Evidence and review register' ) ) . '</caption><tbody>';
		foreach ( $results['rows'] as $row ) {
			echo '<tr><td>' . esc_html( $row['gate_key'] . ' | ' . $row['environment_url'] . ' | ' . $row['release_version'] ) . '</td><td>' . esc_html( $row['artifact_reference'] ) . '<br><code>' . esc_html( $row['artifact_hash'] ) . '</code></td><td>' . esc_html( $row['status'] . ' | ' . $row['valid_until'] . ' | ' . $row['reason'] ) . '</td><td>';
			if ( 'pending' === $row['status'] && (int) $row['submitter_user_id'] !== get_current_user_id() ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_release_evidence"><input type="hidden" name="op" value="review"><input type="hidden" name="id" value="' . absint( $row['id'] ) . '">'; wp_nonce_field( 'adc_release_evidence_review_' . $row['id'] );
				echo '<label>' . esc_html( self::t( 'سبب القرار بعد فحص التقرير', 'Decision reason after reviewing the report' ) ) . ' <input required name="reason"></label><button class="button" name="approved" value="1">' . esc_html( self::t( 'اعتماد', 'Approve' ) ) . '</button><button class="button" name="approved" value="0">' . esc_html( self::t( 'رفض', 'Reject' ) ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>'; QueueTable::footer( $results, 'adc-release-evidence' ); echo '</div>';
	}
	public static function save(): void {
		$input = wp_unslash( $_POST ); $op = sanitize_key( $input['op'] ?? '' ); $id = absint( $input['id'] ?? 0 ); check_admin_referer( 'adc_release_evidence_' . $op . '_' . $id );
		$result = 'submit' === $op ? ReleaseEvidence::submit( $input ) : ( 'review' === $op ? ReleaseEvidence::review( $id, '1' === (string) ( $input['approved'] ?? '' ), sanitize_textarea_field( $input['reason'] ?? '' ) ) : new \WP_Error( 'invalid' ) );
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-release-evidence', 'result'=>is_wp_error( $result ) ? 'failed' : 'saved' ), admin_url( 'admin.php' ) ) ); exit;
	}
}
