<?php
namespace AutoDealership\Admin;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Versioned per-installation policy decisions; this record does not itself change runtime policy. */
final class PolicyProfileService {
	public const OPTION = 'adc_customer_policy_profile';
	private const DECISIONS = array( 'P-01', 'P-02', 'P-03', 'P-04', 'P-05', 'P-06', 'P-07', 'P-08' );
	private const OUTCOMES = array( 'adopt', 'reject', 'defer', 'not_applicable' );
	private const MODES = array( 'internal', 'external', 'hybrid' );

	public static function decision_codes(): array {
		return self::DECISIONS;
	}

	public static function state() {
		$value = get_option( self::OPTION, null );
		if ( null === $value ) { return self::empty_state(); }
		if ( ! self::valid_state( $value ) ) { return new \WP_Error( 'adc_policy_profile_corrupt', __( 'The stored policy profile is invalid. No policy changes were applied.', 'auto-dealership-core' ) ); }
		return $value;
	}

	public static function create_draft( string $reason ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		$reason = sanitize_textarea_field( $reason );
		if ( mb_strlen( $reason ) < 5 || mb_strlen( $reason ) > 2000 ) { return self::invalid(); }
		return self::mutate(
			'policy.profile_draft_created',
			$reason,
			static function ( array $state ) use ( $reason ) {
				if ( $state['draft'] ) { return new \WP_Error( 'adc_policy_draft_exists', __( 'A policy draft already exists. Complete or discard it first.', 'auto-dealership-core' ) ); }
				$decisions = array();
				foreach ( self::DECISIONS as $code ) {
					$decisions[$code] = array(
						'outcome'=>'',
						'statement'=>'',
						'rationale'=>'',
						'owner_function'=>'',
						'approval_mode'=>'internal',
						'external_reference'=>'',
						'authority_reference'=>'',
						'internal_reviewer_id'=>0,
						'external_reviewer_id'=>0,
						'conditions'=>'',
						'internal_approval'=>null,
						'external_verification'=>null,
					);
				}
				$actor = get_current_user_id();
				$state['draft'] = array(
					'revision'=>wp_generate_uuid4(),
					'status'=>'draft',
					'created_by'=>$actor,
					'last_editor'=>$actor,
					'created_at'=>current_time( 'mysql', true ),
					'submitted_at'=>'',
					'reason'=>$reason,
					'review_due_date'=>'',
					'restored_from'=>0,
					'decisions'=>$decisions,
				);
				return $state;
			}
		);
	}

	public static function save_draft( array $input, string $revision, string $reason, string $review_due_date ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		$reason = sanitize_textarea_field( $reason );
		if ( mb_strlen( $reason ) < 5 || mb_strlen( $reason ) > 2000 ) { return self::invalid(); }
		if ( '' !== $review_due_date && ! self::valid_review_date( $review_due_date ) ) { return self::invalid(); }
		$decisions = self::sanitize_decisions( $input );
		if ( is_wp_error( $decisions ) ) { return $decisions; }
		return self::mutate(
			'policy.profile_draft_updated',
			$reason,
			static function ( array $state ) use ( $decisions, $revision, $reason, $review_due_date ) {
				$draft = $state['draft'];
				if ( ! $draft || 'draft' !== $draft['status'] ) { return new \WP_Error( 'adc_policy_not_editable', __( 'Only an editable policy draft can be changed.', 'auto-dealership-core' ) ); }
				if ( ! self::revision_matches( $draft, $revision ) ) { return self::stale(); }
				foreach ( $decisions as $code => $decision ) {
					$decision['internal_approval'] = null;
					$decision['external_verification'] = null;
					$draft['decisions'][$code] = $decision;
				}
				$draft['revision'] = wp_generate_uuid4();
				$draft['last_editor'] = get_current_user_id();
				$draft['reason'] = $reason;
				$draft['review_due_date'] = $review_due_date;
				$state['draft'] = $draft;
				return $state;
			}
		);
	}

	public static function submit( string $revision ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		return self::mutate(
			'policy.profile_submitted',
			'Submitted policy decisions for independent review.',
			static function ( array $state ) use ( $revision ) {
				$draft = $state['draft'];
				if ( ! $draft || 'draft' !== $draft['status'] ) { return new \WP_Error( 'adc_policy_not_editable', __( 'Only an editable policy draft can be submitted.', 'auto-dealership-core' ) ); }
				if ( ! self::revision_matches( $draft, $revision ) ) { return self::stale(); }
				if ( ! self::review_date_is_future( $draft['review_due_date'] ?? '' ) ) { return new \WP_Error( 'adc_policy_review_date_required', __( 'Set a valid future review date before submitting the policy profile.', 'auto-dealership-core' ) ); }
				foreach ( self::DECISIONS as $code ) {
					$item = $draft['decisions'][$code];
					if ( ! in_array( $item['outcome'], self::OUTCOMES, true ) || '' === $item['statement'] || '' === $item['rationale'] || '' === $item['owner_function'] ) { return new \WP_Error( 'adc_policy_incomplete', sprintf( __( 'Complete the decision, policy statement, rationale, and owner for %s.', 'auto-dealership-core' ), $code ) ); }
					if ( '' === $item['authority_reference'] ) { return new \WP_Error( 'adc_policy_authority_missing', sprintf( __( 'Provide the delegation or authority reference for %s.', 'auto-dealership-core' ), $code ) ); }
					if ( in_array( $item['approval_mode'], array( 'internal', 'hybrid' ), true ) && ( ! $item['internal_reviewer_id'] || ! self::eligible_reviewer( $item['internal_reviewer_id'] ) || in_array( $item['internal_reviewer_id'], array( (int) $draft['created_by'], (int) $draft['last_editor'] ), true ) ) ) { return new \WP_Error( 'adc_policy_reviewer_missing', sprintf( __( 'Assign an independent authorized internal reviewer for %s.', 'auto-dealership-core' ), $code ) ); }
					if ( in_array( $item['approval_mode'], array( 'external', 'hybrid' ), true ) && ( ! $item['external_reviewer_id'] || ! self::eligible_reviewer( $item['external_reviewer_id'] ) || in_array( $item['external_reviewer_id'], array( (int) $draft['created_by'], (int) $draft['last_editor'] ), true ) || '' === $item['external_reference'] ) ) { return new \WP_Error( 'adc_policy_evidence_missing', sprintf( __( 'Assign an independent evidence verifier and provide an external reference for %s.', 'auto-dealership-core' ), $code ) ); }
					if ( 'hybrid' === $item['approval_mode'] && (int) $item['internal_reviewer_id'] === (int) $item['external_reviewer_id'] ) { return new \WP_Error( 'adc_policy_separation', sprintf( __( 'Use different assigned reviewers for internal approval and external evidence verification for %s.', 'auto-dealership-core' ), $code ) ); }
					if ( '' !== $item['conditions'] ) { return new \WP_Error( 'adc_policy_conditions_open', sprintf( __( 'Close or remove pending conditions for %s before submission.', 'auto-dealership-core' ), $code ) ); }
				}
				$draft['status'] = 'review';
				$draft['submitted_at'] = current_time( 'mysql', true );
				$state['draft'] = $draft;
				return $state;
			}
		);
	}

	public static function approve_internal( string $code, string $revision ) {
		return self::record_review( $code, $revision, false );
	}

	public static function verify_external( string $code, string $revision ) {
		return self::record_review( $code, $revision, true );
	}

	private static function record_review( string $code, string $revision, bool $external ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		if ( ! in_array( $code, self::DECISIONS, true ) ) { return self::invalid(); }
		return self::mutate(
			$external ? 'policy.profile_external_evidence_verified' : 'policy.profile_internal_approval_recorded',
			$external ? 'Verified external approval evidence for ' . $code . '.' : 'Recorded independent in-dashboard approval for ' . $code . '.',
			static function ( array $state ) use ( $code, $revision, $external ) {
				$draft = $state['draft'];
				if ( ! $draft || 'review' !== $draft['status'] ) { return new \WP_Error( 'adc_policy_not_in_review', __( 'The policy draft is not awaiting review.', 'auto-dealership-core' ) ); }
				if ( ! self::revision_matches( $draft, $revision ) ) { return self::stale(); }
				$item = $draft['decisions'][$code];
				$mode = $item['approval_mode'];
				if ( $external ? ! in_array( $mode, array( 'external', 'hybrid' ), true ) : ! in_array( $mode, array( 'internal', 'hybrid' ), true ) ) { return new \WP_Error( 'adc_policy_review_not_required', __( 'This review method is not required for the selected decision.', 'auto-dealership-core' ) ); }
				if ( $external && '' === $item['external_reference'] ) { return new \WP_Error( 'adc_policy_evidence_missing', __( 'An external evidence reference is required.', 'auto-dealership-core' ) ); }
				$actor = get_current_user_id();
				if ( in_array( $actor, array( (int) $draft['created_by'], (int) $draft['last_editor'] ), true ) ) { return new \WP_Error( 'adc_policy_self_approval', __( 'The draft author or last editor cannot approve its policy decisions.', 'auto-dealership-core' ) ); }
				$other = $external ? $item['internal_approval'] : $item['external_verification'];
				$assigned = (int) $item[$external ? 'external_reviewer_id' : 'internal_reviewer_id'];
				if ( $assigned !== $actor ) { return new \WP_Error( 'adc_policy_wrong_reviewer', __( 'This decision is assigned to a different authorized reviewer.', 'auto-dealership-core' ) ); }
				if ( $other && (int) $other['user_id'] === $actor ) { return new \WP_Error( 'adc_policy_separation', __( 'Internal approval and external evidence verification require different reviewers.', 'auto-dealership-core' ) ); }
				$key = $external ? 'external_verification' : 'internal_approval';
				if ( $item[$key] ) { return new \WP_Error( 'adc_policy_already_reviewed', __( 'This review has already been recorded for the current draft.', 'auto-dealership-core' ) ); }
				$item[$key] = array( 'user_id'=>$actor, 'at'=>current_time( 'mysql', true ) );
				$draft['decisions'][$code] = $item;
				$state['draft'] = $draft;
				return $state;
			}
		);
	}

	public static function publish( string $revision ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		return self::mutate(
			'policy.profile_published',
			'Published an approved policy decision profile.',
			static function ( array $state ) use ( $revision ) {
				$draft = $state['draft'];
				if ( ! $draft || 'review' !== $draft['status'] ) { return new \WP_Error( 'adc_policy_not_in_review', __( 'Only a reviewed policy draft can be published.', 'auto-dealership-core' ) ); }
				if ( ! self::revision_matches( $draft, $revision ) ) { return self::stale(); }
				if ( ! self::review_date_is_future( $draft['review_due_date'] ?? '' ) ) { return new \WP_Error( 'adc_policy_review_date_expired', __( 'The review date has passed. Create and review a draft with a future review date before publishing.', 'auto-dealership-core' ) ); }
				if ( in_array( get_current_user_id(), array( (int) $draft['created_by'], (int) $draft['last_editor'] ), true ) ) { return new \WP_Error( 'adc_policy_self_approval', __( 'The draft author or last editor cannot publish the profile.', 'auto-dealership-core' ) ); }
				foreach ( $draft['decisions'] as $code => $item ) {
					if ( in_array( $item['approval_mode'], array( 'internal', 'hybrid' ), true ) && ! $item['internal_approval'] ) { return new \WP_Error( 'adc_policy_approval_missing', sprintf( __( 'Independent internal approval is missing for %s.', 'auto-dealership-core' ), $code ) ); }
					if ( in_array( $item['approval_mode'], array( 'external', 'hybrid' ), true ) && ! $item['external_verification'] ) { return new \WP_Error( 'adc_policy_evidence_unverified', sprintf( __( 'External evidence has not been independently verified for %s.', 'auto-dealership-core' ), $code ) ); }
				}
				$published = $draft;
				$published['status'] = 'published';
				$published['published_at'] = current_time( 'mysql', true );
				$published['published_by'] = get_current_user_id();
				$published['number'] = $state['next_number'];
				if ( $state['current'] ) { array_unshift( $state['history'], $state['current'] ); }
				$state['history'] = array_slice( $state['history'], 0, 10 );
				$state['current'] = $published;
				$state['draft'] = null;
				$state['next_number']++;
				return $state;
			}
		);
	}

	public static function discard_draft( string $revision, string $reason ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		$reason = sanitize_textarea_field( $reason );
		if ( mb_strlen( $reason ) < 5 || mb_strlen( $reason ) > 2000 ) { return self::invalid(); }
		return self::mutate(
			'policy.profile_draft_discarded',
			$reason,
			static function ( array $state ) use ( $revision ) {
				if ( ! $state['draft'] || ! self::revision_matches( $state['draft'], $revision ) ) { return self::stale(); }
				$state['draft'] = null;
				return $state;
			}
		);
	}

	public static function readiness( array $draft ): array {
		$pending = array();
		foreach ( $draft['decisions'] as $code => $item ) {
			if ( in_array( $item['approval_mode'], array( 'internal', 'hybrid' ), true ) && ! $item['internal_approval'] ) { $pending[] = $code . ': internal approval'; }
			if ( in_array( $item['approval_mode'], array( 'external', 'hybrid' ), true ) && ! $item['external_verification'] ) { $pending[] = $code . ': external evidence verification'; }
		}
		return $pending;
	}

	public static function restore_previous( int $record_number, string $reason ) {
		if ( ! RoleManager::authorized() ) { return self::forbidden(); }
		$reason = sanitize_textarea_field( $reason );
		if ( $record_number < 1 || mb_strlen( $reason ) < 5 || mb_strlen( $reason ) > 2000 ) { return self::invalid(); }
		return self::mutate(
			'policy.profile_previous_version_restored_to_draft',
			$reason,
			static function ( array $state ) use ( $record_number, $reason ) {
				if ( $state['draft'] ) { return new \WP_Error( 'adc_policy_draft_exists', __( 'Complete or discard the current draft before restoring a previous version.', 'auto-dealership-core' ) ); }
				$source = null;
				foreach ( $state['history'] as $record ) {
					if ( (int) ( $record['number'] ?? 0 ) === $record_number ) { $source = $record; break; }
				}
				if ( ! $source ) { return new \WP_Error( 'adc_policy_history_missing', __( 'The requested previous policy version is not available in history.', 'auto-dealership-core' ) ); }
				$decisions = $source['decisions'];
				foreach ( $decisions as &$decision ) {
					$decision['internal_reviewer_id'] = 0;
					$decision['external_reviewer_id'] = 0;
					$decision['internal_approval'] = null;
					$decision['external_verification'] = null;
				}
				unset( $decision );
				$actor = get_current_user_id();
				$state['draft'] = array(
					'revision'=>wp_generate_uuid4(),
					'status'=>'draft',
					'created_by'=>$actor,
					'last_editor'=>$actor,
					'created_at'=>current_time( 'mysql', true ),
					'submitted_at'=>'',
					'reason'=>$reason,
					'review_due_date'=>'',
					'restored_from'=>$record_number,
					'decisions'=>$decisions,
				);
				return $state;
			}
		);
	}

	public static function review_status( array $record ): string {
		$due_date = $record['review_due_date'] ?? '';
		if ( ! self::valid_review_date( $due_date ) ) { return __( 'No valid review date recorded', 'auto-dealership-core' ); }
		$today = current_time( 'Y-m-d' );
		if ( $due_date < $today ) { return sprintf( __( 'Review overdue since %s', 'auto-dealership-core' ), $due_date ); }
		if ( $due_date === $today ) { return __( 'Review due today', 'auto-dealership-core' ); }
		return sprintf( __( 'Review due by %s', 'auto-dealership-core' ), $due_date );
	}

	public static function preview( array $draft, ?array $current ): array {
		$fields = array( 'outcome', 'statement', 'rationale', 'owner_function', 'approval_mode', 'external_reference', 'authority_reference', 'conditions' );
		$changes = array();
		foreach ( self::DECISIONS as $code ) {
			$proposed = $draft['decisions'][$code];
			$baseline = $current['decisions'][$code] ?? array();
			$decision_changes = array();
			foreach ( $fields as $field ) {
				$before = $baseline[$field] ?? '';
				$after = $proposed[$field] ?? '';
				if ( $before !== $after ) {
					$decision_changes[$field] = array( 'before'=>$before, 'after'=>$after );
				}
			}
			if ( $decision_changes ) { $changes[$code] = $decision_changes; }
		}
		return array(
			'baseline_number'=>$current['number'] ?? null,
			'changed_decisions'=>$changes,
			'review_due_date_change'=>array(
				'before'=>$current['review_due_date'] ?? '',
				'after'=>$draft['review_due_date'] ?? '',
			),
			'runtime_effect'=>'not_applied',
		);
	}

	private static function sanitize_decisions( array $input ) {
		if ( array_diff( array_keys( $input ), self::DECISIONS ) || array_diff( self::DECISIONS, array_keys( $input ) ) ) { return self::invalid(); }
		$result = array();
		foreach ( self::DECISIONS as $code ) {
			$item = $input[$code];
			if ( ! is_array( $item ) ) { return self::invalid(); }
			$fields = array();
			foreach ( array( 'outcome'=>30, 'statement'=>5000, 'rationale'=>2000, 'owner_function'=>120, 'approval_mode'=>20, 'external_reference'=>255, 'authority_reference'=>255, 'conditions'=>2000 ) as $key => $max ) {
				if ( ! is_string( $item[$key] ?? null ) ) { return self::invalid(); }
				$value = sanitize_textarea_field( $item[$key] );
				if ( mb_strlen( $value ) > $max ) { return self::invalid(); }
				$fields[$key] = $value;
			}
			foreach ( array( 'internal_reviewer_id', 'external_reviewer_id' ) as $key ) {
				if ( ! is_scalar( $item[$key] ?? null ) || ! preg_match( '/\A[0-9]{1,10}\z/', (string) $item[$key] ) ) { return self::invalid(); }
				$fields[$key] = absint( $item[$key] );
			}
			if ( ! in_array( $fields['outcome'], array( '', ...self::OUTCOMES ), true ) || ! in_array( $fields['approval_mode'], self::MODES, true ) ) { return self::invalid(); }
			$fields['internal_approval'] = null;
			$fields['external_verification'] = null;
			$result[$code] = $fields;
		}
		return $result;
	}

	private static function mutate( string $event, string $reason, callable $change ) {
		global $wpdb;
		if ( ! Schema::is_ready() || ! Transaction::begin( array( $wpdb->options, AuditLog::table_name() ) ) ) { return new \WP_Error( 'adc_policy_store_unavailable', __( 'Policy decisions cannot be changed until transactional storage and audit logging are available.', 'auto-dealership-core' ) ); }
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", self::OPTION ) );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_policy_store_unavailable', __( 'The policy profile could not be read safely.', 'auto-dealership-core' ) ); }
		$before = null === $raw ? self::empty_state() : maybe_unserialize( $raw );
		if ( ! self::valid_state( $before ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_policy_profile_corrupt', __( 'The stored policy profile is invalid. No policy changes were applied.', 'auto-dealership-core' ) ); }
		$after = $change( $before );
		if ( is_wp_error( $after ) ) { $wpdb->query( 'ROLLBACK' ); return $after; }
		$before_summary = self::summary( $before );
		$after_summary = self::summary( $after );
		$after_summary['revision'] = $after['draft']['revision'] ?? $after['current']['revision'] ?? '';
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		update_option( self::OPTION, $after, false );
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, null );
		if ( $stored !== $after ) {
			$wpdb->query( 'ROLLBACK' );
			self::clear_cache();
			return new \WP_Error( 'adc_policy_store_unavailable', __( 'The policy change could not be verified after saving.', 'auto-dealership-core' ) );
		}
		if ( ! Transaction::commit( static fn(): bool => AuditLog::record( $event, 'policy_profile', 0, $reason, $before_summary, $after_summary ) ) ) {
			self::clear_cache();
			return new \WP_Error( 'adc_policy_audit_failed', __( 'The policy change was rolled back because its audit event could not be recorded.', 'auto-dealership-core' ) );
		}
		return true;
	}

	private static function summary( array $state ): array {
		$reviews = array();
		foreach ( $state['draft']['decisions'] ?? array() as $code => $item ) {
			$reviews[$code] = array(
				'outcome'=>$item['outcome'],
				'approval_mode'=>$item['approval_mode'],
				'internal_approver'=>(int) ( $item['internal_approval']['user_id'] ?? 0 ),
				'external_verifier'=>(int) ( $item['external_verification']['user_id'] ?? 0 ),
			);
		}
		return array(
			'current_number'=>$state['current']['number'] ?? 0,
			'draft_revision'=>$state['draft']['revision'] ?? '',
			'draft_status'=>$state['draft']['status'] ?? '',
			'draft_decisions'=>array_keys( $state['draft']['decisions'] ?? array() ),
			'reviews'=>$reviews,
			'current_review_due_date'=>$state['current']['review_due_date'] ?? '',
			'draft_review_due_date'=>$state['draft']['review_due_date'] ?? '',
			'draft_restored_from'=>$state['draft']['restored_from'] ?? 0,
			'next_number'=>$state['next_number'],
		);
	}

	private static function empty_state(): array {
		return array( 'schema'=>1, 'next_number'=>1, 'current'=>null, 'draft'=>null, 'history'=>array() );
	}

	private static function valid_state( mixed $state ): bool {
		if ( ! is_array( $state ) || 1 !== ( $state['schema'] ?? null ) || ! is_int( $state['next_number'] ?? null ) || $state['next_number'] < 1 || ! array_key_exists( 'draft', $state ) || ! array_key_exists( 'current', $state ) || ! is_array( $state['history'] ?? null ) ) { return false; }
		foreach ( array( 'draft', 'current' ) as $key ) {
			if ( null === $state[$key] ) { continue; }
			$record = $state[$key];
			if ( ! is_array( $record ) || ! is_string( $record['revision'] ?? null ) || ! is_array( $record['decisions'] ?? null ) || array_diff( self::DECISIONS, array_keys( $record['decisions'] ) ) ) { return false; }
			if ( 'draft' === $key && ! in_array( $record['status'] ?? '', array( 'draft', 'review' ), true ) ) { return false; }
		}
		return true;
	}

	private static function revision_matches( array $record, string $revision ): bool {
		return preg_match( '/\A[a-f0-9-]{36}\z/i', $revision ) && hash_equals( $record['revision'], $revision );
	}

	private static function valid_review_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		$errors = \DateTimeImmutable::getLastErrors();
		return $parsed && $parsed->format( 'Y-m-d' ) === $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
	}

	private static function review_date_is_future( string $date ): bool {
		return self::valid_review_date( $date ) && $date > current_time( 'Y-m-d' );
	}

	private static function eligible_reviewer( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && user_can( $user_id, 'manage_options' ) && user_can( $user_id, 'promote_users' ) && ( in_array( 'administrator', $user->roles, true ) || ( is_multisite() && is_super_admin( $user_id ) ) );
	}

	private static function clear_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'adc_policy_forbidden', __( 'Policy-profile administration requires authorized system-administrator access.', 'auto-dealership-core' ) );
	}

	private static function stale(): \WP_Error {
		return new \WP_Error( 'adc_policy_stale', __( 'The policy draft changed. Reload and review the current revision.', 'auto-dealership-core' ) );
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'adc_policy_invalid', __( 'The policy decision contains invalid or incomplete fields.', 'auto-dealership-core' ) );
	}
}
