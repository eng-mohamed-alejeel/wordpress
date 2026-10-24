<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Admin workflow for per-installation policy evidence and independent review. */
final class PolicyProfilePage {
	public static function boot(): void {
		add_action( 'admin_post_adc_policy_profile', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! RoleManager::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_policy_profile' );
		$input = wp_unslash( $_POST );
		$operation = is_string( $input['operation'] ?? null ) ? sanitize_key( $input['operation'] ) : '';
		$revision = is_string( $input['revision'] ?? null ) ? $input['revision'] : '';
		$reason = is_string( $input['reason'] ?? null ) ? $input['reason'] : '';
		$result = false;
		switch ( $operation ) {
			case 'create':
				$result = PolicyProfileService::create_draft( $reason );
				break;
			case 'save':
				$decisions = is_array( $input['decisions'] ?? null ) ? $input['decisions'] : array();
				$review_due_date = is_string( $input['review_due_date'] ?? null ) ? $input['review_due_date'] : '';
				$result = PolicyProfileService::save_draft( $decisions, $revision, $reason, $review_due_date );
				break;
			case 'restore':
				$record_number = is_scalar( $input['record_number'] ?? null ) && preg_match( '/\A[0-9]{1,10}\z/', (string) $input['record_number'] ) ? (int) $input['record_number'] : 0;
				$result = PolicyProfileService::restore_previous( $record_number, $reason );
				break;
			case 'submit':
				$result = PolicyProfileService::submit( $revision );
				break;
			case 'approve':
				$code = is_string( $input['code'] ?? null ) ? sanitize_text_field( $input['code'] ) : '';
				$result = PolicyProfileService::approve_internal( $code, $revision );
				break;
			case 'verify':
				$code = is_string( $input['code'] ?? null ) ? sanitize_text_field( $input['code'] ) : '';
				$result = PolicyProfileService::verify_external( $code, $revision );
				break;
			case 'publish':
				$result = PolicyProfileService::publish( $revision );
				break;
			case 'discard':
				$result = PolicyProfileService::discard_draft( $revision, $reason );
				break;
			default:
				wp_die( esc_html__( 'Invalid policy-profile action.', 'auto-dealership-core' ), '', array( 'response'=>400 ) );
		}
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>400, 'back_link'=>true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-policy-profile', 'updated'=>'1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! RoleManager::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$state = PolicyProfileService::state();
		if ( is_wp_error( $state ) ) { wp_die( esc_html( $state->get_error_message() ), '', array( 'response'=>500 ) ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Company policy profile', 'auto-dealership-core' ) . '</h1>';
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'This page records this WordPress installation’s approved policy decisions. Publishing this record does not change role grants, financial thresholds, exports, reports, or retention settings. Each policy must be mapped to a supported server-side setting and tested before operational activation.', 'auto-dealership-core' ) . '</p></div>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Policy-profile record updated.', 'auto-dealership-core' ) . '</p></div>'; }
		self::current_record( $state['current'] );
		if ( $state['draft'] ) { self::draft( $state['draft'], $state['current'] ); }
		else { self::create_form(); }
		if ( $state['history'] ) { self::history( $state['history'], (bool) $state['draft'] ); }
		echo '</div>';
	}

	private static function current_record( ?array $record ): void {
		echo '<h2>' . esc_html__( 'Current approved record', 'auto-dealership-core' ) . '</h2>';
		if ( ! $record ) { echo '<p>' . esc_html__( 'No policy profile has been approved for this installation.', 'auto-dealership-core' ) . '</p>'; return; }
		echo '<p>' . esc_html( sprintf( __( 'Record %1$d — published %2$s by user #%3$d.', 'auto-dealership-core' ), (int) $record['number'], $record['published_at'], (int) $record['published_by'] ) ) . '</p>';
		echo '<p>' . esc_html( PolicyProfileService::review_status( $record ) ) . '</p>';
		self::decision_table( $record['decisions'] );
	}

	private static function create_form(): void {
		echo '<h2>' . esc_html__( 'Start a policy review', 'auto-dealership-core' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_policy_profile' );
		echo '<input type="hidden" name="action" value="adc_policy_profile"><input type="hidden" name="operation" value="create">';
		echo '<p><label>' . esc_html__( 'Review purpose', 'auto-dealership-core' ) . '<br><textarea class="large-text" required minlength="5" maxlength="2000" name="reason" rows="2"></textarea></label></p>';
		submit_button( __( 'Create draft', 'auto-dealership-core' ) );
		echo '</form>';
	}

	private static function draft( array $draft, ?array $current ): void {
		echo '<h2>' . esc_html__( 'Policy draft', 'auto-dealership-core' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( __( 'Status: %1$s — created by user #%2$d; last edited by user #%3$d.', 'auto-dealership-core' ), $draft['status'], (int) $draft['created_by'], (int) $draft['last_editor'] ) ) . '</p>';
		if ( ! empty( $draft['restored_from'] ) ) { echo '<p>' . esc_html( sprintf( __( 'Restored from approved record %d. This is a new draft and must be reviewed and approved again.', 'auto-dealership-core' ), (int) $draft['restored_from'] ) ) . '</p>'; }
		self::impact_preview( PolicyProfileService::preview( $draft, $current ) );
		if ( 'draft' === $draft['status'] ) { self::edit_form( $draft ); return; }
		self::review_table( $draft );
	}

	private static function impact_preview( array $preview ): void {
		echo '<h3>' . esc_html__( 'Impact preview', 'auto-dealership-core' ) . '</h3>';
		$baseline = null === $preview['baseline_number']
			? __( 'No previously approved profile', 'auto-dealership-core' )
			: sprintf( __( 'Compared with approved profile %d', 'auto-dealership-core' ), (int) $preview['baseline_number'] );
		echo '<p>' . esc_html( $baseline ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This preview describes changes to the governance record only. No runtime permissions, financial thresholds, reports, exports, or retention settings are changed or activated.', 'auto-dealership-core' ) . '</p></div>';
		$date_change = $preview['review_due_date_change'];
		if ( $date_change['before'] !== $date_change['after'] ) {
			echo '<p><strong>' . esc_html__( 'Review due date:', 'auto-dealership-core' ) . '</strong> ' . esc_html( $date_change['before'] ?: '—' ) . ' &rarr; ' . esc_html( $date_change['after'] ?: '—' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Review due date:', 'auto-dealership-core' ) . '</strong> ' . esc_html( $date_change['after'] ?: '—' ) . '</p>';
		}
		$changes = $preview['changed_decisions'];
		if ( ! $changes ) { echo '<p>' . esc_html__( 'No policy decision differences from the approved profile.', 'auto-dealership-core' ) . '</p>'; return; }
		$labels = array(
			'outcome'=>__( 'Decision', 'auto-dealership-core' ),
			'statement'=>__( 'Policy statement', 'auto-dealership-core' ),
			'rationale'=>__( 'Rationale', 'auto-dealership-core' ),
			'owner_function'=>__( 'Owner function', 'auto-dealership-core' ),
			'approval_mode'=>__( 'Approval method', 'auto-dealership-core' ),
			'external_reference'=>__( 'External evidence reference', 'auto-dealership-core' ),
			'authority_reference'=>__( 'Authority reference', 'auto-dealership-core' ),
			'conditions'=>__( 'Conditions', 'auto-dealership-core' ),
		);
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Decision area', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Differences', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $changes as $code => $fields ) {
			echo '<tr><th scope="row">' . esc_html( $code . ' — ' . self::title( $code ) ) . '</th><td><details><summary>' . esc_html( implode( ', ', array_map( static fn( $field ) => $labels[$field] ?? $field, array_keys( $fields ) ) ) ) . '</summary><ul>';
			foreach ( $fields as $field => $change ) {
				$before = '' === $change['before'] ? '—' : $change['before'];
				$after = '' === $change['after'] ? '—' : $change['after'];
				echo '<li><strong>' . esc_html( $labels[$field] ?? $field ) . ':</strong> ' . esc_html( $before ) . ' &rarr; ' . esc_html( $after ) . '</li>';
			}
			echo '</ul></details></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function edit_form( array $draft ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_policy_profile' );
		echo '<input type="hidden" name="action" value="adc_policy_profile"><input type="hidden" name="operation" value="save"><input type="hidden" name="revision" value="' . esc_attr( $draft['revision'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Complete all eight decision areas. Decisions are governance records only; unsupported settings must not be treated as operationally enforced.', 'auto-dealership-core' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Next policy review date', 'auto-dealership-core' ) . '<br><input type="date" required name="review_due_date" value="' . esc_attr( $draft['review_due_date'] ?? '' ) . '"></label></p>';
		foreach ( $draft['decisions'] as $code => $item ) {
			echo '<fieldset class="widefat" style="padding:12px;margin:12px 0"><legend><strong>' . esc_html( $code . ' — ' . self::title( $code ) ) . '</strong></legend>';
			echo '<p><label>' . esc_html__( 'Decision', 'auto-dealership-core' ) . ' <select required name="decisions[' . esc_attr( $code ) . '][outcome]">';
			foreach ( array( ''=>'—', 'adopt'=>'Adopt the documented policy', 'reject'=>'Reject the proposed policy', 'defer'=>'Defer the decision', 'not_applicable'=>'Not applicable' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $item['outcome'], $value, false ) . '>' . esc_html__( $label, 'auto-dealership-core' ) . '</option>'; }
			echo '</select></label></p>';
			self::textarea( $code, 'statement', __( 'Decision text / selected policy', 'auto-dealership-core' ), $item['statement'], 5000 );
			self::textarea( $code, 'rationale', __( 'Business rationale', 'auto-dealership-core' ), $item['rationale'], 2000 );
			echo '<p><label>' . esc_html__( 'Owner function (not a personal credential)', 'auto-dealership-core' ) . '<br><input class="regular-text" required maxlength="120" name="decisions[' . esc_attr( $code ) . '][owner_function]" value="' . esc_attr( $item['owner_function'] ) . '"></label></p>';
			echo '<p><label>' . esc_html__( 'Approval evidence', 'auto-dealership-core' ) . ' <select name="decisions[' . esc_attr( $code ) . '][approval_mode]">';
			foreach ( array( 'internal'=>'Independent in-dashboard approval', 'external'=>'External approval reference', 'hybrid'=>'Both internal and external approval' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $item['approval_mode'], $value, false ) . '>' . esc_html__( $label, 'auto-dealership-core' ) . '</option>'; }
			echo '</select></label></p>';
			self::reviewer_select( $code, 'internal_reviewer_id', __( 'Assigned internal reviewer', 'auto-dealership-core' ), (int) $item['internal_reviewer_id'] );
			self::reviewer_select( $code, 'external_reviewer_id', __( 'Assigned external-evidence verifier', 'auto-dealership-core' ), (int) $item['external_reviewer_id'] );
			echo '<p><label>' . esc_html__( 'Delegation / authority reference', 'auto-dealership-core' ) . '<br><input class="large-text" required maxlength="255" name="decisions[' . esc_attr( $code ) . '][authority_reference]" value="' . esc_attr( $item['authority_reference'] ) . '"></label></p>';
			echo '<p><label>' . esc_html__( 'External approval reference (required for external/hybrid)', 'auto-dealership-core' ) . '<br><input class="large-text" maxlength="255" name="decisions[' . esc_attr( $code ) . '][external_reference]" value="' . esc_attr( $item['external_reference'] ) . '"></label></p>';
			self::textarea( $code, 'conditions', __( 'Open conditions (must be closed before submission)', 'auto-dealership-core' ), $item['conditions'], 2000 );
			echo '</fieldset>';
		}
		echo '<p><label>' . esc_html__( 'Change reason', 'auto-dealership-core' ) . '<br><textarea required minlength="5" maxlength="2000" name="reason" class="large-text" rows="2"></textarea></label></p>';
		submit_button( __( 'Save draft', 'auto-dealership-core' ) );
		echo '</form>';
		self::action_form( 'submit', $draft['revision'], __( 'Submit for independent review', 'auto-dealership-core' ) );
		self::reason_action_form( 'discard', $draft['revision'], __( 'Discard this draft', 'auto-dealership-core' ) );
	}

	private static function review_table( array $draft ): void {
		echo '<p>' . esc_html__( 'The draft is locked while under review. Each selected review method must be completed by an administrator other than the author and last editor. Hybrid decisions require different internal and evidence reviewers.', 'auto-dealership-core' ) . '</p>';
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Decision', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Policy', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Review', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Actions', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $draft['decisions'] as $code => $item ) {
			$mode = $item['approval_mode'];
			echo '<tr><td>' . esc_html( $code . ' — ' . self::title( $code ) . ' (' . $item['outcome'] . ')' ) . '</td><td>' . esc_html( $item['statement'] ) . '<br><small>' . esc_html__( 'Owner function:', 'auto-dealership-core' ) . ' ' . esc_html( $item['owner_function'] ) . '; ' . esc_html__( 'Authority reference:', 'auto-dealership-core' ) . ' ' . esc_html( $item['authority_reference'] ) . '</small></td><td>' . esc_html( self::review_status( $item ) ) . '</td><td>';
			if ( in_array( $mode, array( 'internal', 'hybrid' ), true ) && ! $item['internal_approval'] ) {
				if ( get_current_user_id() === (int) $item['internal_reviewer_id'] ) { self::code_action_form( 'approve', $draft['revision'], $code, __( 'Approve internally', 'auto-dealership-core' ) ); }
				else { echo esc_html( sprintf( __( 'Internal reviewer: user #%d', 'auto-dealership-core' ), (int) $item['internal_reviewer_id'] ) ); }
			}
			if ( in_array( $mode, array( 'external', 'hybrid' ), true ) && ! $item['external_verification'] ) {
				if ( get_current_user_id() === (int) $item['external_reviewer_id'] ) { self::code_action_form( 'verify', $draft['revision'], $code, __( 'Verify external reference', 'auto-dealership-core' ) ); }
				else { echo esc_html( sprintf( __( 'Evidence verifier: user #%d', 'auto-dealership-core' ), (int) $item['external_reviewer_id'] ) ); }
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		$pending = PolicyProfileService::readiness( $draft );
		if ( $pending ) { echo '<p>' . esc_html__( 'Pending:', 'auto-dealership-core' ) . ' ' . esc_html( implode( ', ', $pending ) ) . '</p>'; }
		self::action_form( 'publish', $draft['revision'], __( 'Publish approved governance record', 'auto-dealership-core' ), ! $pending );
		self::reason_action_form( 'discard', $draft['revision'], __( 'Discard this draft', 'auto-dealership-core' ) );
	}

	private static function history( array $history, bool $has_draft ): void {
		echo '<h2>' . esc_html__( 'Previous approved records', 'auto-dealership-core' ) . '</h2><ul>';
		foreach ( $history as $record ) {
			echo '<li>' . esc_html( sprintf( __( 'Record %1$d — %2$s — published by user #%3$d. %4$s', 'auto-dealership-core' ), (int) $record['number'], $record['published_at'], (int) $record['published_by'], PolicyProfileService::review_status( $record ) ) );
			if ( ! $has_draft ) { self::restore_form( (int) $record['number'] ); }
			echo '</li>';
		}
		echo '</ul>';
		if ( $has_draft ) { echo '<p>' . esc_html__( 'Discard or complete the current draft before restoring a previous version.', 'auto-dealership-core' ) . '</p>'; }
	}

	private static function restore_form( int $record_number ): void {
		echo '<form style="display:inline-block;margin:4px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_policy_profile' );
		echo '<input type="hidden" name="action" value="adc_policy_profile"><input type="hidden" name="operation" value="restore"><input type="hidden" name="record_number" value="' . absint( $record_number ) . '">';
		echo '<label>' . esc_html__( 'Reason for restoring as draft', 'auto-dealership-core' ) . ' <input name="reason" required minlength="5" maxlength="2000"></label> ';
		submit_button( __( 'Restore as draft for re-approval', 'auto-dealership-core' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function decision_table( array $decisions ): void {
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Decision', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Approved policy record', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Evidence', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $decisions as $code => $item ) { echo '<tr><td>' . esc_html( $code . ' — ' . self::title( $code ) . ' (' . $item['outcome'] . ')' ) . '</td><td>' . esc_html( $item['statement'] ) . '<br><small>' . esc_html__( 'Rationale:', 'auto-dealership-core' ) . ' ' . esc_html( $item['rationale'] ) . '</small></td><td>' . esc_html( self::review_status( $item ) ) . ( $item['external_reference'] ? '<br>' . esc_html( $item['external_reference'] ) : '' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}

	private static function review_status( array $item ): string {
		$parts = array();
		if ( $item['internal_approval'] ) { $parts[] = sprintf( __( 'Internal approval by user #%d', 'auto-dealership-core' ), (int) $item['internal_approval']['user_id'] ); }
		if ( $item['external_verification'] ) { $parts[] = sprintf( __( 'External evidence verified by user #%d', 'auto-dealership-core' ), (int) $item['external_verification']['user_id'] ); }
		return $parts ? implode( '; ', $parts ) : __( 'Pending', 'auto-dealership-core' );
	}

	private static function textarea( string $code, string $key, string $label, string $value, int $max ): void {
		echo '<p><label>' . esc_html( $label ) . '<br><textarea class="large-text" required maxlength="' . absint( $max ) . '" name="decisions[' . esc_attr( $code ) . '][' . esc_attr( $key ) . ']" rows="3">' . esc_textarea( $value ) . '</textarea></label></p>';
	}

	private static function reviewer_select( string $code, string $field, string $label, int $selected ): void {
		echo '<p><label>' . esc_html( $label ) . ' <select name="decisions[' . esc_attr( $code ) . '][' . esc_attr( $field ) . ']"><option value="0">—</option>';
		foreach ( get_users( array( 'role'=>'administrator', 'orderby'=>'ID', 'order'=>'ASC', 'number'=>500 ) ) as $user ) {
			if ( ! user_can( $user, 'manage_options' ) || ! user_can( $user, 'promote_users' ) ) { continue; }
			echo '<option value="' . absint( $user->ID ) . '" ' . selected( $selected, $user->ID, false ) . '>' . esc_html( $user->display_name . ' (#' . $user->ID . ')' ) . '</option>';
		}
		echo '</select></label></p>';
	}

	private static function action_form( string $operation, string $revision, string $label, bool $enabled = true ): void {
		echo '<form style="display:inline-block;margin:4px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_policy_profile' );
		echo '<input type="hidden" name="action" value="adc_policy_profile"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="revision" value="' . esc_attr( $revision ) . '">';
		submit_button( $label, 'primary', 'submit', false, $enabled ? array() : array( 'disabled'=>true ) );
		echo '</form>';
	}

	private static function code_action_form( string $operation, string $revision, string $code, string $label ): void {
		echo '<form style="display:inline-block;margin:2px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_policy_profile' );
		echo '<input type="hidden" name="action" value="adc_policy_profile"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="revision" value="' . esc_attr( $revision ) . '"><input type="hidden" name="code" value="' . esc_attr( $code ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function reason_action_form( string $operation, string $revision, string $label ): void {
		echo '<form style="display:inline-block;margin:4px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_policy_profile' );
		echo '<input type="hidden" name="action" value="adc_policy_profile"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="revision" value="' . esc_attr( $revision ) . '"><label>' . esc_html__( 'Reason', 'auto-dealership-core' ) . ' <input name="reason" required minlength="5" maxlength="2000"></label> ';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function title( string $code ): string {
		$titles = array( 'P-01'=>'Roles and access', 'P-02'=>'Financial approvals', 'P-03'=>'Marketing and lead ownership', 'P-04'=>'CRM exports', 'P-05'=>'Performance reports', 'P-06'=>'Audit and retention', 'P-07'=>'Suppliers and customer portal', 'P-08'=>'Effective access and exceptions' );
		return __( $titles[$code] ?? $code, 'auto-dealership-core' );
	}
}
