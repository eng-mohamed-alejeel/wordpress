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

	/** Theme CRM posts predate the core tables and need the same export/erasure boundary. */
	private static function legacy_profile_ids( string $email ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$where = $wpdb->prepare( '(m.meta_key=%s AND LOWER(m.meta_value)=LOWER(%s))', '_crm_email', $email );
		if ( $user ) { $where .= $wpdb->prepare( ' OR (m.meta_key=%s AND m.meta_value=%d)', '_crm_user_id', $user->ID ); }
		$ids = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='cd_crm' AND p.post_status='private' AND ($where) ORDER BY p.ID ASC" );
		return $wpdb->last_error ? array() : array_map( 'intval', $ids ?: array() );
	}

	private static function legacy_profile_storage_ready(): bool {
		global $wpdb;
		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->comments ) as $table ) {
			if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) { return false; }
		}
		return true;
	}

	private static function set_legacy_profile_meta( int $post_id, string $key, string $value ): bool {
		global $wpdb;
		$updated = $wpdb->update( $wpdb->postmeta, array( 'meta_value'=>$value ), array( 'post_id'=>$post_id, 'meta_key'=>$key ) );
		if ( false === $updated ) { return false; }
		if ( $updated > 0 || $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1", $post_id, $key ) ) ) { return true; }
		return 1 === $wpdb->insert( $wpdb->postmeta, array( 'post_id'=>$post_id, 'meta_key'=>$key, 'meta_value'=>$value ) );
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = self::email( $email );
		if ( ! is_email( $email ) ) {
			return array( 'data' => array(), 'done' => true );
		}
		$customers = $wpdb->get_results( 'SELECT id,full_name,mobile,email,city,account_user_id,consent_marketing,consent_at,created_at FROM ' . Schema::table( 'customers' ) . ' WHERE ' . self::identity_where( $email, 'account_user_id' ) . ' ORDER BY id ASC', ARRAY_A ) ?: array();
		$items = array();
		$account = get_user_by( 'email', $email );
		if ( $account && metadata_exists( 'user', $account->ID, 'adc_marketing_consent' ) ) {
			$items[] = array( 'name'=>__( 'Account marketing preference', 'auto-dealership-core' ), 'value'=>'1' === (string) get_user_meta( $account->ID, 'adc_marketing_consent', true ) ? __( 'Yes', 'auto-dealership-core' ) : __( 'No', 'auto-dealership-core' ) );
			$items[] = array( 'name'=>__( 'Account marketing preference recorded', 'auto-dealership-core' ), 'value'=>(string) get_user_meta( $account->ID, 'adc_marketing_consent_at', true ) );
		}
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
			foreach ( array( 'reservations' => 'id,vehicle_id,status,deposit_policy,deposit_required_amount,deposit_amount,deposit_refund_status,payment_reference,expires_at,created_at', 'quotations' => 'id,quote_number,vehicle_id,base_amount,fee_amount,promotion_code,promotion_amount,discount_amount,subtotal_amount,tax_amount,final_amount,valid_until,status,created_at', 'sales' => 'id,vehicle_id,status,invoice_reference,created_at,updated_at' ) as $table_name => $columns ) {
				$records = $wpdb->get_results( $wpdb->prepare( "SELECT $columns FROM " . Schema::table( $table_name ) . ' WHERE customer_id = %d ORDER BY id ASC', (int) $customer['id'] ), ARRAY_A ) ?: array();
				foreach ( $records as $record ) {
					$value = implode( '; ', array_map( static fn( $key, $value ) => $key . ': ' . (string) $value, array_keys( $record ), array_values( $record ) ) );
					if ( in_array( $table_name, array( 'reservations', 'quotations', 'sales' ), true ) ) {
						$value .= '; monetary values are stored in SAR halalas';
					}
					$items[] = array( 'name' => ucfirst( rtrim( $table_name, 's' ) ), 'value' => $value );
					if ( 'reservations' === $table_name ) {
						$evidence = $wpdb->get_results( $wpdb->prepare( 'SELECT amount,currency,source,reference,status,created_at,decided_at FROM ' . Schema::table( 'reservation_deposits' ) . ' WHERE reservation_id=%d ORDER BY id', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $evidence as $deposit ) {
							$items[] = array( 'name'=>__( 'Reservation deposit evidence', 'auto-dealership-core' ), 'value'=>implode( '; ', array_map( static fn( $key, $item )=>$key . ': ' . (string) $item, array_keys( $deposit ), array_values( $deposit ) ) ) . '; monetary values are stored in SAR halalas' );
						}
						$refunds = $wpdb->get_results( $wpdb->prepare( 'SELECT amount,currency,method,reference,status,created_at,decided_at FROM ' . Schema::table( 'payment_refunds' ) . ' WHERE reservation_id=%d ORDER BY id', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $refunds as $refund ) {
							$items[] = array( 'name'=>__( 'Reservation deposit refund', 'auto-dealership-core' ), 'value'=>implode( '; ', array_map( static fn( $key, $item )=>$key . ': ' . (string) $item, array_keys( $refund ), array_values( $refund ) ) ) . '; monetary values are stored in SAR halalas' );
						}
					}
					if ( 'sales' === $table_name ) {
						$finance = $wpdb->get_results( $wpdb->prepare( 'SELECT provider,requested_amount,status,provider_reference,consent_at,created_at FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id = %d ORDER BY id ASC', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $finance as $request ) {
							$items[] = array( 'name' => __( 'Finance request', 'auto-dealership-core' ), 'value' => implode( '; ', array_map( static fn( $key, $value ) => $key . ': ' . (string) $value, array_keys( $request ), array_values( $request ) ) ) );
						}
						$receipts = $wpdb->get_results( $wpdb->prepare( 'SELECT amount,currency,source,reference,status,created_at,decided_at FROM ' . Schema::table( 'payment_confirmations' ) . ' WHERE sale_id=%d ORDER BY id', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $receipts as $receipt ) {
							$items[] = array( 'name'=>__( 'Sale receipt evidence', 'auto-dealership-core' ), 'value'=>implode( '; ', array_map( static fn( $key, $item )=>$key . ': ' . (string) $item, array_keys( $receipt ), array_values( $receipt ) ) ) . '; monetary values are stored in SAR halalas' );
						}
						$refunds = $wpdb->get_results( $wpdb->prepare( 'SELECT amount,currency,method,reference,status,created_at,decided_at FROM ' . Schema::table( 'payment_refunds' ) . ' WHERE sale_id=%d ORDER BY id', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $refunds as $refund ) {
							$items[] = array( 'name'=>__( 'Sale refund evidence', 'auto-dealership-core' ), 'value'=>implode( '; ', array_map( static fn( $key, $item )=>$key . ': ' . (string) $item, array_keys( $refund ), array_values( $refund ) ) ) . '; monetary values are stored in SAR halalas' );
						}
						$documents = $wpdb->get_results( $wpdb->prepare( 'SELECT dd.document_key,dd.reference,dd.confirmed_at FROM ' . Schema::table( 'delivery_documents' ) . ' dd INNER JOIN ' . Schema::table( 'deliveries' ) . ' d ON d.id=dd.delivery_id WHERE d.sale_id=%d ORDER BY dd.id', (int) $record['id'] ), ARRAY_A ) ?: array();
						foreach ( $documents as $document ) {
							$items[] = array( 'name'=>__( 'Delivery document evidence', 'auto-dealership-core' ), 'value'=>implode( '; ', array_map( static fn( $key, $item )=>$key . ': ' . (string) $item, array_keys( $document ), array_values( $document ) ) ) );
						}
					}
				}
			}
			$versions = $wpdb->get_results( $wpdb->prepare( 'SELECT quotation_id,quote_number,version,customer_name,vehicle_stock_number,vehicle_description,base_amount,fee_amount,promotion_code,promotion_amount,discount_amount,subtotal_amount,tax_rate_bps,tax_amount,final_amount,valid_until,status,created_at FROM ' . Schema::table( 'quotation_versions' ) . ' WHERE customer_id = %d ORDER BY quotation_id,version', (int) $customer['id'] ), ARRAY_A ) ?: array();
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
		foreach ( self::legacy_profile_ids( $email ) as $profile_id ) {
			$post = get_post( $profile_id );
			if ( ! $post ) { continue; }
			$items[] = array( 'name'=>__( 'Legacy CRM profile', 'auto-dealership-core' ), 'value'=>sprintf( 'ID %d; name %s; stage %s; source %s; created %s', $profile_id, $post->post_title, (string) get_post_meta( $profile_id, '_crm_stage', true ), (string) get_post_meta( $profile_id, '_crm_source', true ), $post->post_date_gmt ) );
			$items[] = array( 'name'=>__( 'Legacy CRM contact', 'auto-dealership-core' ), 'value'=>sprintf( 'Mobile %s; email %s', (string) get_post_meta( $profile_id, '_crm_phone', true ), (string) get_post_meta( $profile_id, '_crm_email', true ) ) );
			foreach ( get_comments( array( 'post_id'=>$profile_id, 'type'=>'crm_activity', 'status'=>'approve', 'orderby'=>'comment_ID', 'order'=>'ASC' ) ) as $note ) {
				$items[] = array( 'name'=>__( 'Legacy CRM activity', 'auto-dealership-core' ), 'value'=>sprintf( '%s: %s', $note->comment_date_gmt, $note->comment_content ) );
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
		$account = get_user_by( 'email', $email );
		$preference_count = $account && ( metadata_exists( 'user', $account->ID, 'adc_marketing_consent' ) || metadata_exists( 'user', $account->ID, 'adc_marketing_consent_at' ) ) ? 1 : 0;
		if ( $preference_count && 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->usermeta ) ) ) { return self::erase_failed(); }
		$legacy_count = self::legacy_count( $email );
		$legacy_profile_ids = self::legacy_profile_ids( $email );
		if ( $wpdb->last_error ) { return self::erase_failed(); }
		if ( $legacy_profile_ids && ! self::legacy_profile_storage_ready() ) { return self::erase_failed(); }
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
		if ( $legacy_profile_ids ) {
			$ids = implode( ',', array_map( 'absint', $legacy_profile_ids ) );
			$locked = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($ids) AND post_type='cd_crm' AND post_status='private' ORDER BY ID FOR UPDATE" );
			if ( $wpdb->last_error || count( $locked ) !== count( $legacy_profile_ids )
				|| false === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_title=%s,post_modified=%s,post_modified_gmt=%s WHERE ID IN ($ids)", 'Erased customer', current_time( 'mysql' ), current_time( 'mysql', true ) ) )
				|| false === $wpdb->query( "UPDATE {$wpdb->comments} SET comment_content='[Personal data erased]',comment_author_email='',comment_author_url='',comment_author_IP='' WHERE comment_post_ID IN ($ids) AND comment_type='crm_activity'" )
				|| false === $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value='' WHERE post_id IN ($ids) AND meta_key IN ('_crm_email','_crm_phone','_crm_user_id','_crm_task','_crm_due','_crm_source')" ) ) {
				$wpdb->query( 'ROLLBACK' ); return self::erase_failed();
			}
			$now = current_time( 'mysql', true );
			foreach ( $legacy_profile_ids as $profile_id ) {
				if ( ! self::set_legacy_profile_meta( $profile_id, '_crm_privacy_erased', '1' ) || ! self::set_legacy_profile_meta( $profile_id, '_crm_retired_at', $now ) ) { $wpdb->query( 'ROLLBACK' ); return self::erase_failed(); }
			}
		}
		if ( $preference_count && false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key IN ('adc_marketing_consent','adc_marketing_consent_at')", $account->ID ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::erase_failed(); }
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
		foreach ( $legacy_profile_ids as $profile_id ) {
			clean_post_cache( $profile_id );
			$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type='crm_activity'", $profile_id ) );
			if ( $comment_ids ) { clean_comment_cache( array_map( 'intval', $comment_ids ) ); }
		}
		if ( $account ) { clean_user_cache( $account->ID ); wp_cache_delete( $account->ID, 'user_meta' ); }
		$removed = count( $customer_ids ) + $legacy_count + count( $legacy_profile_ids ) + $preference_count;
		return array(
			'items_removed' => $removed,
			'items_retained' => count( $customer_ids ) + count( $legacy_profile_ids ),
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
