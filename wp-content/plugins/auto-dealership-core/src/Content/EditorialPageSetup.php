<?php
namespace AutoDealership\Content;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Explicit, idempotent creation of theme-provided editorial page blueprints. */
final class EditorialPageSetup {
	private const MAX_BLUEPRINTS = 20;
	private const MAX_CONTENT_BYTES = 100000;

	/** @return array<string,array{slug:string,title:string,content:string,template:string}> */
	public static function blueprints(): array {
		$provided = apply_filters( 'adc_editorial_page_blueprints', array() );
		if ( ! is_array( $provided ) ) {
			return array();
		}

		$blueprints = array();
		foreach ( array_slice( $provided, 0, self::MAX_BLUEPRINTS, true ) as $key => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $definition['slug'] ?? $key ) );
			$title = sanitize_text_field( (string) ( $definition['title'] ?? '' ) );
			$content = (string) ( $definition['content'] ?? '' );
			$template = self::template( (string) ( $definition['template'] ?? '' ) );
			if ( '' === $slug || '' === $title || strlen( $content ) > self::MAX_CONTENT_BYTES ) {
				continue;
			}
			$blueprints[ $slug ] = array(
				'slug' => $slug,
				'title' => $title,
				'content' => wp_kses_post( $content ),
				'template' => $template,
			);
		}
		return $blueprints;
	}

	/** @return array<int,array<string,mixed>> */
	public static function report(): array {
		$rows = array();
		foreach ( self::blueprints() as $blueprint ) {
			$page = get_page_by_path( $blueprint['slug'], OBJECT, 'page' );
			$rows[] = array(
				'slug' => $blueprint['slug'],
				'title' => $blueprint['title'],
				'template' => $blueprint['template'],
				'exists' => $page instanceof \WP_Post,
				'post_id' => $page instanceof \WP_Post ? (int) $page->ID : 0,
				'post_status' => $page instanceof \WP_Post ? (string) $page->post_status : '',
				'current_title' => $page instanceof \WP_Post ? (string) $page->post_title : '',
			);
		}
		return $rows;
	}

	/**
	 * Create selected missing pages as drafts. Existing pages are never changed.
	 *
	 * @param array<int,string> $requested_slugs Requested blueprint slugs.
	 * @return array{created:array<string,int>,preserved:array<string,int>}|\WP_Error
	 */
	public static function create_missing( array $requested_slugs, string $reason ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_editorial_forbidden', __( 'Administrator access is required.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$reason = sanitize_textarea_field( $reason );
		$reason_length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason ) : strlen( $reason );
		if ( $reason_length < 5 || $reason_length > 500 ) {
			return new \WP_Error( 'adc_editorial_reason', __( 'Enter a setup reason between 5 and 500 characters.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}

		$blueprints = self::blueprints();
		$requested = array_values( array_unique( array_filter( array_map( 'sanitize_title', $requested_slugs ) ) ) );
		if ( ! $blueprints || ! $requested || count( $requested ) > self::MAX_BLUEPRINTS ) {
			return new \WP_Error( 'adc_editorial_selection', __( 'Select at least one available page definition.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		foreach ( $requested as $slug ) {
			if ( ! isset( $blueprints[ $slug ] ) ) {
				return new \WP_Error( 'adc_editorial_unknown', __( 'The selected page definition is not available.', 'auto-dealership-core' ), array( 'status' => 400 ) );
			}
		}

		global $wpdb;
		$lock_name = self::lock_name();
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
		if ( ! $locked ) {
			return new \WP_Error( 'adc_editorial_busy', __( 'Another page setup operation is running. Try again shortly.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}

		$transaction_open = false;
		try {
			if ( ! Transaction::begin() ) {
				return new \WP_Error( 'adc_editorial_unavailable', __( 'The audited setup service is not ready.', 'auto-dealership-core' ), array( 'status' => 503 ) );
			}
			$transaction_open = true;
			$created = array();
			$preserved = array();

			foreach ( $requested as $slug ) {
				$existing = get_page_by_path( $slug, OBJECT, 'page' );
				if ( $existing instanceof \WP_Post ) {
					$preserved[ $slug ] = (int) $existing->ID;
					continue;
				}
				$definition = $blueprints[ $slug ];
				$post_id = wp_insert_post(
					array(
						'post_type' => 'page',
						'post_status' => 'draft',
						'post_name' => $slug,
						'post_title' => $definition['title'],
						'post_content' => $definition['content'],
						'post_author' => get_current_user_id(),
					),
					true
				);
				if ( is_wp_error( $post_id ) || $post_id < 1 ) {
					$wpdb->query( 'ROLLBACK' );
					$transaction_open = false;
					return new \WP_Error( 'adc_editorial_create_failed', __( 'A selected page could not be created. No pages were saved.', 'auto-dealership-core' ), array( 'status' => 500 ) );
				}
				$inserted = get_post( $post_id );
				if ( ! $inserted instanceof \WP_Post || 'page' !== $inserted->post_type || 'draft' !== $inserted->post_status || $slug !== $inserted->post_name ) {
					$wpdb->query( 'ROLLBACK' );
					$transaction_open = false;
					return new \WP_Error( 'adc_editorial_contract_failed', __( 'A selected page did not retain its required draft identity. No pages were saved.', 'auto-dealership-core' ), array( 'status' => 409 ) );
				}
				if ( '' !== $definition['template'] ) {
					$template_saved = update_post_meta( $post_id, '_wp_page_template', $definition['template'] );
					if ( false === $template_saved && $definition['template'] !== get_post_meta( $post_id, '_wp_page_template', true ) ) {
						$wpdb->query( 'ROLLBACK' );
						$transaction_open = false;
						return new \WP_Error( 'adc_editorial_template_failed', __( 'The page template could not be assigned. No pages were saved.', 'auto-dealership-core' ), array( 'status' => 500 ) );
					}
				}
				$created[ $slug ] = (int) $post_id;
			}

			if ( ! $created ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction_open = false;
				return array( 'created' => array(), 'preserved' => $preserved );
			}

			$audit_pages = array();
			foreach ( $created as $slug => $post_id ) {
				$audit_pages[] = array( 'post_id' => $post_id, 'slug' => $slug, 'status' => 'draft' );
			}
			$committed = Transaction::commit(
				static fn() => AuditLog::record(
					'content.editorial_pages_created',
					'editorial_setup',
					(int) reset( $created ),
					$reason,
					null,
					array( 'pages' => $audit_pages )
				)
			);
			$transaction_open = false;
			if ( ! $committed ) {
				return new \WP_Error( 'adc_editorial_audit_failed', __( 'The setup could not be audited. No pages were saved.', 'auto-dealership-core' ), array( 'status' => 500 ) );
			}
			return array( 'created' => $created, 'preserved' => $preserved );
		} finally {
			if ( $transaction_open ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	private static function template( string $template ): string {
		$template = trim( str_replace( '\\', '/', $template ) );
		if ( '' === $template ) {
			return '';
		}
		return basename( $template ) === $template && preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.php$/', $template ) ? $template : '';
	}

	private static function lock_name(): string {
		global $wpdb;
		return 'adc_editorial_' . md5( (string) $wpdb->prefix . home_url( '/' ) );
	}
}
