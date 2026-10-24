<?php
/** Policy profile workflow checks; loaded only in an isolated verification database. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\PolicyProfilePage;
use AutoDealership\Admin\PolicyProfileService;

$policy_profile_before = get_option( PolicyProfileService::OPTION, null );
$policy_profile_admin = get_current_user_id();
$policy_profile_users = array();
$policy_profile_password = wp_generate_password( 40 );
$policy_review_due_date = wp_date( 'Y-m-d', current_time( 'timestamp' ) + YEAR_IN_SECONDS );
try {
	wp_set_current_user( $policy_profile_admin );
	$policy_profile_empty = PolicyProfileService::state();
	adc_check( is_array( $policy_profile_empty ) && null === $policy_profile_empty['current'] && null === $policy_profile_empty['draft'], 'Policy profile starts empty and isolated per WordPress installation.' );
	adc_check( true === PolicyProfileService::create_draft( 'Initial customer policy review' ), 'Authorized administrator creates a policy draft.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	foreach ( array( 'reviewer_a', 'reviewer_b' ) as $suffix ) {
		$user_id = wp_insert_user( array( 'user_login'=>'policy_' . $suffix . '_' . strtolower( wp_generate_password( 6, false ) ), 'user_pass'=>$policy_profile_password, 'role'=>'administrator' ) );
		if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
		$policy_profile_users[] = (int) $user_id;
	}
	[$policy_reviewer_a, $policy_reviewer_b] = $policy_profile_users;
	$policy_profile_decisions = array();
	foreach ( PolicyProfileService::decision_codes() as $code ) {
		$mode = 'P-02' === $code ? 'hybrid' : ( 'P-03' === $code ? 'external' : 'internal' );
		$policy_profile_decisions[$code] = array(
			'outcome'=>'adopt',
			'statement'=>'Synthetic approved decision for ' . $code,
			'rationale'=>'Synthetic test rationale',
			'owner_function'=>'Synthetic domain owner',
			'approval_mode'=>$mode,
			'external_reference'=>in_array( $mode, array( 'external', 'hybrid' ), true ) ? 'SYNTHETIC-REF-' . $code : '',
			'authority_reference'=>'SYNTHETIC-DELEGATION-' . $code,
			'internal_reviewer_id'=>in_array( $mode, array( 'internal', 'hybrid' ), true ) ? $policy_reviewer_a : 0,
			'external_reviewer_id'=>in_array( $mode, array( 'external', 'hybrid' ), true ) ? $policy_reviewer_b : 0,
			'conditions'=>'',
		);
	}
	adc_check( true === PolicyProfileService::save_draft( $policy_profile_decisions, $policy_profile_draft['revision'], 'Populate synthetic decision fixture', '' ), 'All eight policy decisions validate and save as a draft.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	adc_check( is_wp_error( PolicyProfileService::submit( $policy_profile_draft['revision'] ) ), 'A policy profile cannot be submitted without a review date.' );
	adc_check( is_wp_error( PolicyProfileService::save_draft( $policy_profile_decisions, $policy_profile_draft['revision'], 'Reject malformed review date', '2026-02-31' ) ), 'Malformed review dates are rejected.' );
	adc_check( true === PolicyProfileService::save_draft( $policy_profile_decisions, $policy_profile_draft['revision'], 'Set future policy review date', $policy_review_due_date ), 'A valid future review date can be saved on the draft.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	$policy_preview = PolicyProfileService::preview( $policy_profile_draft, null );
	adc_check( null === $policy_preview['baseline_number'] && 8 === count( $policy_preview['changed_decisions'] ) && $policy_review_due_date === $policy_preview['review_due_date_change']['after'] && 'not_applied' === $policy_preview['runtime_effect'], 'Initial impact preview identifies all new policy areas and review date without implying runtime enforcement.' );
	$identical_preview = PolicyProfileService::preview( $policy_profile_draft, array( 'number'=>3, 'decisions'=>$policy_profile_draft['decisions'] ) );
	adc_check( 3 === $identical_preview['baseline_number'] && array() === $identical_preview['changed_decisions'], 'Impact preview reports no differences when draft decisions match the approved baseline.' );
	$changed_preview_baseline = $policy_profile_draft['decisions'];
	$changed_preview_baseline['P-01']['statement'] = 'Previous approved statement';
	$changed_preview = PolicyProfileService::preview( $policy_profile_draft, array( 'number'=>3, 'decisions'=>$changed_preview_baseline ) );
	adc_check( array( 'statement' ) === array_keys( $changed_preview['changed_decisions']['P-01'] ), 'Impact preview pinpoints only fields that differ from the approved policy record.' );
	ob_start(); PolicyProfilePage::render(); $draft_preview_page = ob_get_clean();
	adc_check( str_contains( $draft_preview_page, 'Impact preview' ) && str_contains( $draft_preview_page, 'No runtime permissions' ), 'Draft dashboard presents impact preview with a clear non-enforcement boundary.' );
	$stale_draft_revision = $policy_profile_draft['revision'];
	$missing_external_reference = $policy_profile_decisions;
	$missing_external_reference['P-03']['external_reference'] = '';
	adc_check( true === PolicyProfileService::save_draft( $missing_external_reference, $policy_profile_draft['revision'], 'Test missing external reference', $policy_review_due_date ), 'Draft accepts edits while retaining draft-only status.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	adc_check( is_wp_error( PolicyProfileService::save_draft( $policy_profile_decisions, $stale_draft_revision, 'Reject stale concurrent draft update', $policy_review_due_date ) ), 'A stale draft revision cannot overwrite a newer edit.' );
	adc_check( is_wp_error( PolicyProfileService::submit( $policy_profile_draft['revision'] ) ), 'External approval modes cannot be submitted without a reference.' );
	$open_conditions = $policy_profile_decisions;
	$open_conditions['P-08']['conditions'] = 'Synthetic condition still open';
	adc_check( true === PolicyProfileService::save_draft( $open_conditions, $policy_profile_draft['revision'], 'Test open policy condition', $policy_review_due_date ), 'Draft records an explicitly open condition.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	adc_check( is_wp_error( PolicyProfileService::submit( $policy_profile_draft['revision'] ) ), 'Open conditions prevent a policy profile from entering review.' );
	adc_check( true === PolicyProfileService::save_draft( $policy_profile_decisions, $policy_profile_draft['revision'], 'Close synthetic policy condition', $policy_review_due_date ), 'Draft can be corrected before submission.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	adc_check( is_wp_error( PolicyProfileService::submit( 'stale-revision' ) ), 'Stale policy revisions cannot be submitted.' );
	adc_check( true === PolicyProfileService::submit( $policy_profile_draft['revision'] ), 'Complete policy draft can be submitted for independent review.' );
	$policy_profile_draft = PolicyProfileService::state()['draft'];
	adc_check( is_wp_error( PolicyProfileService::approve_internal( 'P-01', $policy_profile_draft['revision'] ) ), 'Policy author cannot approve their own profile.' );
	wp_set_current_user( $policy_reviewer_b );
	adc_check( is_wp_error( PolicyProfileService::approve_internal( 'P-01', $policy_profile_draft['revision'] ) ), 'A different administrator cannot approve a decision assigned to another reviewer.' );
	wp_set_current_user( $policy_reviewer_a );
	foreach ( array( 'P-01', 'P-02', 'P-04', 'P-05' ) as $code ) {
		adc_check( true === PolicyProfileService::approve_internal( $code, $policy_profile_draft['revision'] ), 'Independent reviewer records internal approval for ' . $code . '.' );
	}
	adc_check( is_wp_error( PolicyProfileService::verify_external( 'P-02', $policy_profile_draft['revision'] ) ) && is_wp_error( PolicyProfileService::verify_external( 'P-03', $policy_profile_draft['revision'] ) ), 'Only the designated evidence verifier can review external and hybrid approvals.' );
	wp_set_current_user( $policy_reviewer_b );
	adc_check( true === PolicyProfileService::verify_external( 'P-02', $policy_profile_draft['revision'] ), 'A separate reviewer verifies external evidence for the hybrid P-02 decision.' );
	adc_check( true === PolicyProfileService::verify_external( 'P-03', $policy_profile_draft['revision'] ), 'Assigned reviewer verifies external evidence for P-03.' );
	adc_check( is_wp_error( PolicyProfileService::publish( $policy_profile_draft['revision'] ) ), 'Incomplete approval requirements prevent profile publication.' );
	wp_set_current_user( $policy_reviewer_a );
	foreach ( array( 'P-06', 'P-07', 'P-08' ) as $code ) {
		adc_check( true === PolicyProfileService::approve_internal( $code, $policy_profile_draft['revision'] ), 'Independent reviewer records internal approval for ' . $code . '.' );
	}
	wp_set_current_user( $policy_reviewer_a );
	$expired_draft_state = PolicyProfileService::state();
	$expired_draft_state['draft']['review_due_date'] = '2000-01-01';
	update_option( PolicyProfileService::OPTION, $expired_draft_state, false );
	adc_check( is_wp_error( PolicyProfileService::publish( $policy_profile_draft['revision'] ) ), 'A fully reviewed draft cannot be published after its review date.' );
	$expired_draft_state['draft']['review_due_date'] = $policy_review_due_date;
	update_option( PolicyProfileService::OPTION, $expired_draft_state, false );
	adc_check( true === PolicyProfileService::publish( $policy_profile_draft['revision'] ), 'Fully reviewed policy profile can be published as a governance record.' );
	$policy_profile_state = PolicyProfileService::state();
	adc_check( 1 === $policy_profile_state['current']['number'] && 'published' === $policy_profile_state['current']['status'] && null === $policy_profile_state['draft'], 'Published profile becomes the current version and closes its draft.' );
	adc_check( str_contains( PolicyProfileService::review_status( $policy_profile_state['current'] ), $policy_review_due_date ), 'Published policy displays its scheduled review date.' );
	$overdue_record = $policy_profile_state['current'];
	$overdue_record['review_due_date'] = '2000-01-01';
	adc_check( str_contains( PolicyProfileService::review_status( $overdue_record ), 'overdue' ), 'Past review dates are visibly marked overdue.' );
	adc_check( 0 === (int) get_option( 'adc_sales_manager_discount_limit', 0 ), 'Publishing policy evidence does not change runtime financial thresholds.' );
	ob_start(); PolicyProfilePage::render(); $policy_profile_page = ob_get_clean();
	adc_check( str_contains( $policy_profile_page, 'does not change role grants' ) && str_contains( $policy_profile_page, 'Current approved record' ), 'Policy dashboard clearly distinguishes approved governance evidence from enforced runtime settings.' );
	$previous_record = $policy_profile_state['current'];
	$previous_record['number'] = 1;
	$policy_profile_state['current']['number'] = 2;
	$policy_profile_state['history'] = array( $previous_record );
	$policy_profile_state['next_number'] = 3;
	update_option( PolicyProfileService::OPTION, $policy_profile_state, false );
	ob_start(); PolicyProfilePage::render(); $history_page = ob_get_clean();
	adc_check( str_contains( $history_page, 'Restore as draft for re-approval' ), 'Policy history offers restoration only as a draft for fresh review.' );
	adc_check( true === PolicyProfileService::restore_previous( 1, 'Restore previous approved baseline' ), 'An available previous version can be restored into a draft.' );
	$restored_state = PolicyProfileService::state();
	$restored_draft = $restored_state['draft'];
	adc_check( 2 === $restored_state['current']['number'] && 1 === $restored_draft['restored_from'] && '' === $restored_draft['review_due_date'], 'Restoring history does not replace the current record and requires a new review date.' );
	adc_check( null === $restored_draft['decisions']['P-01']['internal_approval'] && 0 === $restored_draft['decisions']['P-01']['internal_reviewer_id'], 'Restored policy clears previous approvals and reviewer assignments.' );
	ob_start(); PolicyProfilePage::render(); $restored_page = ob_get_clean();
	adc_check( str_contains( $restored_page, 'must be reviewed and approved again' ) && str_contains( $restored_page, 'Next policy review date' ), 'Restored draft warns that approvals and date must be renewed.' );
	adc_check( is_wp_error( PolicyProfileService::restore_previous( 99, 'Restore unavailable previous baseline' ) ), 'Unavailable history versions cannot be restored.' );
	adc_check( is_wp_error( PolicyProfileService::restore_previous( 1, 'Try second restore while draft exists' ) ), 'A second history restoration cannot overwrite an existing draft.' );

	$non_admin = wp_insert_user( array( 'user_login'=>'policy_denied_' . strtolower( wp_generate_password( 8, false ) ), 'user_pass'=>$policy_profile_password, 'role'=>'subscriber' ) );
	if ( is_wp_error( $non_admin ) ) { throw new RuntimeException( $non_admin->get_error_message() ); }
	$policy_profile_users[] = (int) $non_admin;
	wp_set_current_user( $non_admin );
	adc_check( is_wp_error( PolicyProfileService::create_draft( 'Unauthorized profile draft attempt' ) ), 'Non-administrator cannot create a policy profile.' );
} finally {
	wp_set_current_user( $policy_profile_admin );
	if ( null === $policy_profile_before ) { delete_option( PolicyProfileService::OPTION ); } else { update_option( PolicyProfileService::OPTION, $policy_profile_before, false ); }
	foreach ( $policy_profile_users as $user_id ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id ); }
}
