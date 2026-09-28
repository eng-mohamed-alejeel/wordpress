<?php
namespace AutoDealership\Privacy;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** WordPress privacy exporter and personal-data eraser for dealership CRM data. */
final class PrivacyTools {
	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['auto-dealership-core'] = array(
			'exporter_friendly_name' => __( 'Auto Dealership CRM', 'auto-dealership-core' ),
			'callback' => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['auto-dealership-core'] = array(
			'eraser_friendly_name' => __( 'Auto Dealership CRM', 'auto-dealership-core' ),
			'callback' => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	private static function email( string $email ): string {
		return sanitize_email( $email );
	}

	private static function identity_where( string $email, string $column ): string {
		global $wpdb;
		if ( ! in_array( $column, array( 'account_user_id', 'user_id' ), true ) ) { throw new \InvalidArgumentException( 'Invalid identity column.' ); }
		$user = get_user_by( 'email', $email );
		return $user ? $wpdb->prepare( "(email=%s OR $column=%d)", $email, $user->ID ) : $wpdb->prepare( 'email=%s', $email );
	}

	private static function legacy_rows( string $email ): array {
		global $wpdb;
		$rows = array();
		foreach ( array( 'message' => 'car_dealer_messages', 'booking' => 'car_dealer_bookings' ) as $type => $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}
			$columns = 'message' === $type ? 'id,car_id,status,created_at,lead_type,subject,message,customer_reply' : 'id,car_id,status,created_at,requested_date,requested_time,customer_reply';
			$records = $wpdb->get_results( "SELECT $columns FROM $table WHERE " . self::identity_where( $email, 'user_id' ) . ' ORDER BY id ASC', ARRAY_A ) ?: array();
			foreach ( $records as $record ) {
				$record['request_type'] = $type;
				$rows[] = $record;
			}
		}
		return $rows;
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = self::email( $email );
		if ( ! is_email( $email ) ) {
			return array( 'data' => array(), 'done' => true );
		}
		$customers = $wpdb->get_results( 'SELECT id,full_name,mobile,email,city,account_user_id,consent_marketing,consent_at,created_at FROM ' . Schema::table( 'customers' ) . ' WHERE ' . self::identity_where( $email, 'account_user_id' ) . ' ORDER BY id ASC', ARRAY_A ) ?: array();
		$items = array();
		foreach ( $customers as $customer ) {
			$items[] = array( 'name'=>__( 'Linked account ID', 'auto-dealership-core' ), 'value'=>(string) ( $customer['account_user_id'] ?? '' ) );
			$items[] = array( 'name' => __( 'Name', 'auto-dealership-core' ), 'value' => $customer['full_name'] );
			$items[] = array( 'name' => __( 'Mobile', 'auto-dealership-core' ), 'value' => $customer['mobile'] );
			$items[] = array( 'name' => __( 'Email', 'auto-dealership-core' ), 'value' => $customer['email'] );
			$items[] = array( 'name' => __( 'City', 'auto-dealership-core' ), 'value' => $customer['city'] );
			$items[] = array( 'name' => __( 'Marketing consent', 'auto-dealership-core' ), 'value' => (int) $customer['consent_marketing'] ? __( 'Yes', 'auto-dealership-core' ) : __( 'No', 'auto-dealership-core' ) );
			$items[] = array( 'name' => __( 'Marketing consent recorded', 'auto-dealership-core' ), 'value' => (string) $customer['consent_at'] );
			$items[] = array( 'name' => __( 'Customer record created', 'auto-dealership-core' ), 'value' => $customer['created_at'] );
			$leads = $wpdb->get_results( $wpdb->prepare( 'SELECT id,source,stage,created_at FROM ' . Schema::table( 'leads' ) . ' WHERE customer_id = %d ORDER BY id ASC', (int) $customer['id'] ), ARRAY_A ) ?: array();
			foreach ( $leads as $lead ) {
				$items[] = array( 'name' => __( 'Lead', 'auto-dealership-core' ), 'value' => sprintf( 'ID %d; source %s; stage %s; created %s', (int) $lead['id'], $lead['source'], $lead['stage'], $lead['created_at'] ) );
				$activities = $wpdb->get_results( $wpdb->prepare( 'SELECT type,notes,created_at FROM ' . Schema::table( 'activities' ) . ' WHERE lead_id = %d ORDER BY id ASC', (int) $lead['id'] ), ARRAY_A ) ?: array();
				foreach ( $activities as $activity ) {
					$items[] = array( 'name' => __( 'Lead activity', 'auto-dealership-core' ), 'value' => sprintf( '%s (%s): %s', $activity['type'], $activity['created_at'], $activity['notes'] ) );
				}
			}
			foreach ( array( 'reservations' => 'id,vehicle_id,status,deposit_amount,payment_reference,expires_at,created_at', 'quotations' => 'id,quote_number,vehicle_id,base_amount,discount_amount,tax_amount,final_amount,valid_until,status,created_at', 'sales' => 'id,vehicle_id,status,invoice_reference,created_at,updated_at' ) as $table_name => $columns ) {
				$records = $wpdb->get_results( $wpdb->prepare( "SELECT $columns FROM " . Schema::table( $table_name ) . ' WHERE customer_id = %d ORDER BY id ASC', (int) $customer['id'] ), ARRAY_A ) ?: array();
				foreach ( $records as $record ) {
					$value = implode( '; ', array_map( static fn( $key, $value ) => $key . ': ' . (string) $value, array_keys( $record ), array_values( $record ) ) );
					if ( in_array( $table_name, array( 'reservations', 'quotations', 'sales' ), true ) ) {
						$value .= '; monetary values are stored in SAR halalas';
					}
					$items[] = array( 'name' => ucfirst( rtrim( $table_name, 's' ) ), 'value' => $value );
					if ( 'sales' === $table_name ) {
						$finance = $wpdb->get_results( $wpdb->prepare( 'SELECT provider,requested_amount,status,provider_reference,consent_at,created_at FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id = %d ORDER BY id ASC', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $finance as $request ) {
							$items[] = array( 'name' => __( 'Finance request', 'auto-dealership-core' ), 'value' => implode( '; ', array_map( static fn( $key, $value ) => $key . ': ' . (string) $value, array_keys( $request ), array_values( $request ) ) ) );
						}
					}
				}
			}
			$versions = $wpdb->get_results( $wpdb->prepare( 'SELECT quotation_id,quote_number,version,customer_name,vehicle_stock_number,vehicle_description,base_amount,discount_amount,tax_rate_bps,tax_amount,final_amount,valid_until,status,created_at FROM ' . Schema::table( 'quotation_versions' ) . ' WHERE customer_id = %d ORDER BY quotation_id,version', (int) $customer['id'] ), ARRAY_A ) ?: array();
			foreach ( $versions as $version ) {
				$items[] = array( 'name' => __( 'Quotation revision', 'auto-dealership-core' ), 'value' => implode( '; ', array_map( static fn( $key, $value ) => $key . ': ' . (string) $value, array_keys( $version ), array_values( $version ) ) ) . '; monetary values are stored in SAR halalas' );
			}
		}
		foreach ( self::legacy_rows( $email ) as $request ) {
			$items[] = array( 'name' => __( 'Legacy website request', 'auto-dealership-core' ), 'value' => sprintf( '%s #%d; status %s; vehicle %d; created %s; %s', $request['request_type'], (int) $request['id'], $request['status'], (int) $request['car_id'], $request['created_at'], sanitize_textarea_field( (string) ( $request['message'] ?? '' ) ) ) );
			if ( ! empty( $request['customer_reply'] ) ) {
				$items[] = array( 'name' => __( 'Previous customer reply', 'auto-dealership-core' ), 'value' => $request['customer_reply'] );
			}
			if ( ! empty( $request['subject'] ) ) {
				$items[] = array( 'name' => __( 'Request subject', 'auto-dealership-core' ), 'value' => $request['subject'] );
			}
			if ( ! empty( $request['requested_date'] ) || ! empty( $request['requested_time'] ) ) {
				$items[] = array( 'name' => __( 'Requested appointment', 'auto-dealership-core' ), 'value' => trim( (string) $request['requested_date'] . ' ' . (string) $request['requested_time'] ) );
			}
		}
		$subscriber_table = $wpdb->prefix . 'car_dealer_subscribers';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subscriber_table ) ) ) === $subscriber_table ) {
			$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT status,created_at FROM $subscriber_table WHERE email = %s LIMIT 1", $email ), ARRAY_A );
			if ( $subscriber ) {
				$items[] = array( 'name' => __( 'Newsletter subscription', 'auto-dealership-core' ), 'value' => sprintf( 'Status: %s; created: %s', $subscriber['status'], $subscriber['created_at'] ) );
			}
		}
		$data = $items ? array( array( 'group_id' => 'adc-customer-data', 'group_label' => __( 'Dealership customer and enquiry data', 'auto-dealership-core' ), 'item_id' => 'adc-customer-' . md5( strtolower( $email ) ), 'data' => $items ) ) : array();
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = self::email( $email );
		if ( ! is_email( $email ) ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$customer_table = Schema::table( 'customers' );
		$legacy_count = self::legacy_count( $email );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::erase_failed();
		}
		$customer_ids = $wpdb->get_col( "SELECT id FROM $customer_table WHERE " . self::identity_where( $email, 'account_user_id' ) . ' ORDER BY id ASC FOR UPDATE' );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::erase_failed(); }
		if ( $customer_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%d' ) );
			$lead_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . " WHERE customer_id IN ($placeholders)", array_map( 'intval', $customer_ids ) ) ) ?: array();
			if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::erase_failed(); }
			if ( $lead_ids ) {
				$lead_placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
				if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'activities' ) . " SET notes = %s,next_action_at = NULL WHERE lead_id IN ($lead_placeholders)", array_merge( array( '[Personal data erased]' ), array_map( 'intval', $lead_ids ) ) ) ) || false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'leads' ) . " SET lost_reason = '',next_action_at = NULL,public_payload_hash = NULL WHERE id IN ($lead_placeholders)", array_map( 'intval', $lead_ids ) ) ) ) {
					$wpdb->query( 'ROLLBACK' );
					return self::erase_failed();
				}
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'quotation_versions' ) . " SET customer_name = %s WHERE customer_id IN ($placeholders)", array_merge( array( __( 'Erased customer', 'auto-dealership-core' ) ), array_map( 'intval', $customer_ids ) ) ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::erase_failed();
			}
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE $customer_table SET full_name = %s,mobile = '',email = '',city = '',account_user_id = NULL,consent_marketing = 0,consent_at = NULL,updated_at = %s WHERE id IN ($placeholders)", array_merge( array( __( 'Erased customer', 'auto-dealership-core' ), current_time( 'mysql', true ) ), array_map( 'intval', $customer_ids ) ) ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::erase_failed();
			}
		}
		if ( ! self::erase_legacy_table( $wpdb->prefix . 'car_dealer_messages', $email, array( 'name' => __( 'Erased customer', 'auto-dealership-core' ), 'email' => '', 'phone' => '', 'subject' => '', 'message' => '', 'customer_reply' => '', 'user_id' => 0 ) ) || ! self::erase_legacy_table( $wpdb->prefix . 'car_dealer_bookings', $email, array( 'name' => __( 'Erased customer', 'auto-dealership-core' ), 'email' => '', 'phone' => '', 'customer_reply' => '', 'requested_date' => null, 'requested_time' => '', 'user_id' => 0 ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::erase_failed();
		}
		$subscribers = $wpdb->prefix . 'car_dealer_subscribers';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subscribers ) ) ) === $subscribers ) {
			if ( false === $wpdb->delete( $subscribers, array( 'email' => $email ), array( '%s' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::erase_failed();
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::erase_failed();
		}
		$removed = count( $customer_ids ) + $legacy_count;
		return array(
			'items_removed' => $removed,
			'items_retained' => count( $customer_ids ),
			'messages' => $removed ? array( __( 'Direct identifiers and enquiry text were erased. Minimal operational records remain for accounting, workflow and audit purposes.', 'auto-dealership-core' ) ) : array(),
			'done' => true,
		);
	}

	private static function legacy_count( string $email ): int {
		global $wpdb;
		$count = 0;
		foreach ( array( 'car_dealer_messages', 'car_dealer_bookings' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
				$count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE " . self::identity_where( $email, 'user_id' ) );
			}
		}
		return $count;
	}

	private static function erase_failed(): array {
		return array( 'items_removed' => 0, 'items_retained' => 0, 'messages' => array( __( 'The CRM erasure could not be completed. Retry the request after checking the database error log.', 'auto-dealership-core' ) ), 'done' => false );
	}

	private static function erase_legacy_table( string $table, string $email, array $changes ): bool {
		global $wpdb;
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( $wpdb->last_error ) { return false; }
		if ( null === $engine ) { return true; }
		if ( 'InnoDB' !== $engine ) { return false; }
		$ids = $wpdb->get_col( "SELECT id FROM $table WHERE " . self::identity_where( $email, 'user_id' ) . ' ORDER BY id ASC FOR UPDATE' );
		if ( $wpdb->last_error ) { return false; }
		foreach ( $ids as $id ) { if ( false === $wpdb->update( $table, $changes, array( 'id'=>(int) $id ) ) ) { return false; } }
		return true;
	}
}
