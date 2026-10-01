<?php
namespace AutoDealership\Content;

use AutoDealership\Audit\AuditLog;

defined( 'ABSPATH' ) || exit;

/** Applies audited post-meta changes and restores the previous state on failure. */
final class PostMetaStore {
	/**
	 * @param array<int,array{post_id:int,key:string,value:mixed,delete?:bool}> $changes
	 * @return array<int,array{post_id:int,key:string}>|\WP_Error
	 */
	public static function apply( array $changes, string $event_key, string $subject_type, int $subject_id ) {
		$snapshots = array();
		$pending   = array();
		$seen      = array();

		foreach ( $changes as $change ) {
			$post_id = absint( $change['post_id'] ?? 0 );
			$key     = isset( $change['key'] ) ? (string) $change['key'] : '';
			$delete  = ! empty( $change['delete'] );
			$value   = $change['value'] ?? '';
			$index   = $post_id . ':' . $key;

			if ( $post_id < 1 || ! preg_match( '/^_[a-z0-9_]{2,100}$/', $key ) || isset( $seen[ $index ] ) ) {
				return new \WP_Error( 'adc_content_meta_invalid', __( 'The requested content metadata change is invalid.', 'auto-dealership-core' ) );
			}
			$seen[ $index ] = true;

			$exists = metadata_exists( 'post', $post_id, $key );
			$old    = $exists ? get_post_meta( $post_id, $key, true ) : null;
			if ( ( $delete && ! $exists ) || ( ! $delete && $exists && maybe_serialize( $old ) === maybe_serialize( $value ) ) ) {
				continue;
			}

			$snapshots[ $index ] = array(
				'post_id' => $post_id,
				'key'     => $key,
				'exists'  => $exists,
				'value'   => $old,
			);
			$pending[] = array(
				'post_id' => $post_id,
				'key'     => $key,
				'value'   => $value,
				'delete'  => $delete,
			);
		}

		if ( ! $pending ) {
			return array();
		}

		$changed = array();
		foreach ( $pending as $change ) {
			$ok = $change['delete']
				? delete_post_meta( $change['post_id'], $change['key'] )
				: update_post_meta( $change['post_id'], $change['key'], $change['value'] );
			if ( false === $ok ) {
				self::restore( $snapshots );
				return new \WP_Error( 'adc_content_meta_write_failed', __( 'The dealership fields could not be saved.', 'auto-dealership-core' ) );
			}
			$changed[] = array( 'post_id' => $change['post_id'], 'key' => $change['key'] );
		}

		$audit_fields = array_map(
			static fn( array $item ): array => array( 'post_id' => $item['post_id'], 'key' => $item['key'] ),
			$changed
		);
		if ( ! AuditLog::record( $event_key, $subject_type, $subject_id, '', array( 'changed_fields' => array() ), array( 'changed_fields' => $audit_fields ) ) ) {
			self::restore( $snapshots );
			return new \WP_Error( 'adc_content_meta_audit_failed', __( 'The dealership fields were restored because the audit record could not be saved.', 'auto-dealership-core' ) );
		}

		return $changed;
	}

	/** @param array<string,array{post_id:int,key:string,exists:bool,value:mixed}> $snapshots */
	private static function restore( array $snapshots ): void {
		foreach ( array_reverse( $snapshots ) as $snapshot ) {
			if ( $snapshot['exists'] ) {
				update_post_meta( $snapshot['post_id'], $snapshot['key'], $snapshot['value'] );
			} else {
				delete_post_meta( $snapshot['post_id'], $snapshot['key'] );
			}
		}
	}
}
