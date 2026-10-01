<?php
namespace AutoDealership\Sales;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\CustomerScope;
use AutoDealership\Pricing\Money;
use AutoDealership\Pricing\QuoteHistory;
use AutoDealership\Pricing\PricingPolicy;
use AutoDealership\Integrations\DomainEventPublisher;

defined( 'ABSPATH' ) || exit;

/** Quotes, separation-of-duties discount review and controlled sale approval. */
final class SalesService {
	public static function create_quote( int $customer_id, int $vehicle_id, string $valid_until, string $promotion_code = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_create_reservations' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إنشاء عرض سعر.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,status,retail_price,minimum_price FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d FOR UPDATE', $vehicle_id ), ARRAY_A );
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $valid_until, new \DateTimeZone( 'UTC' ) );
		if ( ! $vehicle || ! CustomerScope::allows( $customer_id, (int) $vehicle['branch_id'] ) || 'available' !== $vehicle['status'] || ! $date || $date->format( 'Y-m-d' ) !== $valid_until || $valid_until < gmdate( 'Y-m-d' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_quote', __( 'السيارة أو العميل أو تاريخ صلاحية العرض غير صالح.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$base = Money::parse( $vehicle['retail_price'] );
		$tax_rate = Money::parse( get_option( 'adc_vat_rate_bps', 0 ) );
		try {
			if ( null === $base || null === $tax_rate ) { throw new \InvalidArgumentException(); }
			$amounts = PricingPolicy::quote( $base, 0, $tax_rate, $promotion_code );
		} catch ( \InvalidArgumentException | \OverflowException $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_price', __( 'السعر أو نسبة الضريبة غير صالحين للحساب.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( null !== $vehicle['minimum_price'] && $base - (int) $amounts['promotion_amount'] < (int) $vehicle['minimum_price'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_price_floor', __( 'The promotion would place the quotation below the approved minimum price.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$quote_number = 'Q-' . gmdate( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 8, false, false ) );
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( Schema::table( 'quotations' ), array_merge( $amounts, PricingPolicy::seller_snapshot(), array( 'quote_number' => $quote_number, 'customer_id' => $customer_id, 'vehicle_id' => $vehicle_id, 'branch_id' => (int) $vehicle['branch_id'], 'owner_user_id' => get_current_user_id(), 'valid_until' => $valid_until, 'status' => 'approved', 'version' => 1, 'created_at' => $now ) ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_quote_failed', __( 'تعذر إنشاء عرض السعر.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => QuoteHistory::capture( $id, 'quotation.created' ) && AuditLog::record( 'quotation.created', 'quotation', $id, '', null, array_merge( $amounts, array( 'vehicle_id' => $vehicle_id, 'customer_id' => $customer_id, 'version' => 1, 'currency' => 'SAR' ) ) ) ) ) {
			return new \WP_Error( 'adc_quote_failed', __( 'تعذر توثيق عرض السعر.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array_merge( $amounts, array( 'id' => $id, 'quote_number' => $quote_number, 'version' => 1, 'currency' => 'SAR', 'status' => 'approved' ) );
	}

	public static function request_discount( int $quote_id, int $amount, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_create_reservations' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية طلب الخصم.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$reason = sanitize_textarea_field( $reason );
		$tier = PricingPolicy::discount_tier( $amount );
		if ( is_wp_error( $tier ) ) { return $tier; }
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'quotations' );
		$quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d FOR UPDATE", $quote_id ), ARRAY_A );
		if ( ! $quote || (int) $quote['owner_user_id'] !== get_current_user_id() || 'approved' !== $quote['status'] || $amount <= (int) $quote['discount_amount'] || $amount >= (int) $quote['base_amount'] || '' === $reason || $quote['valid_until'] < gmdate( 'Y-m-d' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_discount', __( 'لا يمكن طلب هذا الخصم على العرض المحدد.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sales' ) . ' WHERE quotation_id = %d LIMIT 1', $quote_id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_quote_committed', __( 'لا يمكن تعديل سعر عرض مرتبط بعملية بيع.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT branch_id,retail_price,minimum_price,purchase_cost FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d FOR UPDATE', (int) $quote['vehicle_id'] ), ARRAY_A );
		if ( ! $vehicle || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $vehicle['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_discount', __( 'لا يمكن طلب هذا الخصم على العرض المحدد.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( null === $vehicle['purchase_cost'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_purchase_cost_required', __( 'Purchase cost must be recorded before a margin-based discount request can be submitted.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( $vehicle && null !== $vehicle['minimum_price'] && ( (int) $quote['base_amount'] - (int) $quote['promotion_amount'] - $amount ) < (int) $vehicle['minimum_price'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_price_floor', __( 'السعر المقترح أقل من الحد الأدنى المعتمد.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( null === $quote['tax_rate_bps'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_quote_tax_unknown', __( 'نسبة ضريبة العرض القديم غير محفوظة. أنشئ عرضًا جديدًا قبل طلب الخصم.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		try {
			PricingPolicy::quote( (int) $quote['base_amount'], $amount, (int) $quote['tax_rate_bps'], '', $quote );
		} catch ( \InvalidArgumentException | \OverflowException $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_discount', __( 'The requested discount cannot produce a valid quotation total.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$margin_before = (int) $quote['base_amount'] - (int) $quote['promotion_amount'] - (int) $vehicle['purchase_cost'];
		$margin_after = $margin_before - $amount;
		$ok = $wpdb->insert( Schema::table( 'discount_requests' ), array( 'quotation_id' => $quote_id, 'requester_user_id' => get_current_user_id(), 'requested_amount' => $amount, 'approval_tier' => $tier['tier'], 'margin_before' => $margin_before, 'margin_after' => $margin_after, 'reason' => $reason, 'status' => 'pending', 'created_at' => $now ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_discount_failed', __( 'تعذر إرسال طلب الخصم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$discount_id = (int) $wpdb->insert_id;
		$version = (int) $quote['version'] + 1;
		$updated = $wpdb->update( $table, array( 'status' => 'pending_discount', 'version' => $version ), array( 'id' => $quote_id ), array( '%s', '%d' ), array( '%d' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_discount_failed', __( 'تعذر تحديث حالة عرض السعر.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => QuoteHistory::capture( $quote_id, 'discount.requested', $reason ) && AuditLog::record( 'discount.requested', 'discount_request', $discount_id, $reason, null, array( 'quotation_id' => $quote_id, 'amount' => $amount, 'approval_tier' => $tier['tier'], 'margin_before' => $margin_before, 'margin_after' => $margin_after, 'version' => $version ) ) ) ) {
			return new \WP_Error( 'adc_discount_failed', __( 'تعذر توثيق طلب الخصم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$response = array( 'id' => $discount_id, 'status' => 'pending', 'approval_tier' => $tier['tier'], 'quotation_version' => $version );
		if ( current_user_can( 'adc_manage_pricing' ) || current_user_can( 'adc_review_discounts' ) ) {
			$response['margin_before'] = $margin_before;
			$response['margin_after'] = $margin_after;
		}
		return $response;
	}

	public static function decide_discount( int $request_id, bool $approve, string $reason = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_review_discounts' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية مراجعة الخصومات.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$requests = Schema::table( 'discount_requests' );
		$quote_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT quotation_id FROM $requests WHERE id = %d", $request_id ) );
		if ( $quote_id < 1 || ! Transaction::begin() ) {
			return new \WP_Error( 'adc_discount_decision_denied', __( 'لا يمكن اعتماد هذا الطلب أو أنه حُسم مسبقًا.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$quote_table = Schema::table( 'quotations' );
		$quote = $wpdb->get_row( $wpdb->prepare( "SELECT q.*,v.branch_id,v.minimum_price FROM $quote_table q INNER JOIN " . Schema::table( 'vehicles' ) . " v ON v.id=q.vehicle_id WHERE q.id = %d FOR UPDATE", $quote_id ), ARRAY_A );
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $requests WHERE id = %d FOR UPDATE", $request_id ), ARRAY_A );
		if ( ! $request || (int) $request['quotation_id'] !== $quote_id || 'pending' !== $request['status'] || (int) $request['requester_user_id'] === get_current_user_id() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_discount_decision_denied', __( 'لا يمكن اعتماد هذا الطلب أو أنه حُسم مسبقًا.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( $approve && 'general_manager' === $request['approval_tier'] && ! current_user_can( 'adc_approve_high_discounts' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_general_manager_required', __( 'يتطلب هذا الخصم اعتماد المدير العام.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( $approve && ! in_array( $request['approval_tier'], array( 'sales_manager', 'general_manager' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_discount_policy', __( 'The discount is outside the configured approval policy.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! $quote || 'pending_discount' !== $quote['status'] || $quote['valid_until'] < gmdate( 'Y-m-d' ) || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $quote['branch_id'] ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sales' ) . ' WHERE quotation_id = %d LIMIT 1', $quote_id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_quote_state', __( 'حالة عرض السعر لا تسمح بحسم الطلب.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$status = $approve ? 'approved' : 'rejected';
		$decision_update = $wpdb->update( $requests, array( 'status' => $status, 'approver_user_id' => get_current_user_id(), 'decided_at' => $now ), array( 'id' => $request_id ), array( '%s', '%d', '%s' ), array( '%d' ) );
		$version = (int) $quote['version'] + 1;
		if ( $approve ) {
			$discount = (int) $request['requested_amount'];
			if ( null === $quote['tax_rate_bps'] || ( null !== $quote['minimum_price'] && (int) $quote['base_amount'] - (int) $quote['promotion_amount'] - $discount < (int) $quote['minimum_price'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_quote_price_invalid', __( 'تعذر اعتماد الخصم لأن الضريبة التاريخية أو حد السعر غير صالح.', 'auto-dealership-core' ), array( 'status' => 409 ) );
			}
			try {
				$amounts = PricingPolicy::quote( (int) $quote['base_amount'], $discount, (int) $quote['tax_rate_bps'], '', $quote );
			} catch ( \InvalidArgumentException | \OverflowException $error ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_quote_price_invalid', __( 'تعذر حساب إجمالي العرض بعد الخصم.', 'auto-dealership-core' ), array( 'status' => 409 ) );
			}
			$quote_update = $wpdb->update( $quote_table, array_merge( $amounts, array( 'status' => 'approved', 'version' => $version ) ), array( 'id' => (int) $quote['id'] ) );
		} else {
			$quote_update = $wpdb->update( $quote_table, array( 'status' => 'approved', 'version' => $version ), array( 'id' => (int) $quote['id'] ), array( '%s', '%d' ), array( '%d' ) );
		}
		if ( 1 !== $decision_update || 1 !== $quote_update ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_discount_failed', __( 'تعذر حفظ قرار الخصم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$reason = sanitize_textarea_field( $reason );
		if ( ! Transaction::commit( static fn() => QuoteHistory::capture( $quote_id, 'discount.' . $status, $reason ) && AuditLog::record( 'discount.' . $status, 'discount_request', $request_id, $reason, array( 'status' => 'pending' ), array( 'status' => $status, 'approver' => get_current_user_id(), 'quotation_version' => $version ) ) ) ) {
			return new \WP_Error( 'adc_discount_failed', __( 'تعذر توثيق قرار الخصم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $request_id, 'status' => $status, 'quotation_version' => $version );
	}

	public static function create_sale( int $quote_id, int $reservation_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_create_reservations' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إنشاء عملية البيع.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT q.id,q.customer_id,q.vehicle_id,q.status,q.valid_until,q.owner_user_id,q.final_amount,v.branch_id FROM ' . Schema::table( 'quotations' ) . ' q INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=q.vehicle_id WHERE q.id = %d FOR UPDATE', $quote_id ), ARRAY_A );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id,customer_id,vehicle_id,owner_user_id,status,expires_at,deposit_required_amount,deposit_amount FROM ' . Schema::table( 'reservations' ) . ' WHERE id = %d FOR UPDATE', $reservation_id ), ARRAY_A );
		if ( ! $q || ! $r || ! CustomerScope::allows( (int) $q['customer_id'], (int) $q['branch_id'] ) || ( (int) $r['owner_user_id'] !== get_current_user_id() && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_sale_precondition', __( 'بيانات العميل والحجز غير متاحة لهذه العملية.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! $q || ! $r || 'approved' !== $q['status'] || $q['valid_until'] < gmdate( 'Y-m-d' ) || 'confirmed' !== $r['status'] || $r['expires_at'] <= current_time( 'mysql', true ) || (int) $r['deposit_amount'] < (int) $r['deposit_required_amount'] || (int) $r['deposit_amount'] > (int) $q['final_amount'] || (int) $q['customer_id'] !== (int) $r['customer_id'] || (int) $q['vehicle_id'] !== (int) $r['vehicle_id'] || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $q['branch_id'] ) || ( (int) $q['owner_user_id'] !== get_current_user_id() && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_sale_precondition', __( 'لا تتطابق بيانات العرض والحجز أو لم تعد صالحة.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( Schema::table( 'sales' ), array( 'quotation_id' => $quote_id, 'reservation_id' => $reservation_id, 'customer_id' => (int) $q['customer_id'], 'vehicle_id' => (int) $q['vehicle_id'], 'status' => 'pending_approval', 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_sale_failed', __( 'تعذر إنشاء عملية البيع.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$sale_id = (int) $wpdb->insert_id;
		$converted = $wpdb->update( Schema::table( 'reservations' ), array( 'status' => 'converted_to_sale', 'updated_at' => $now ), array( 'id' => $reservation_id, 'status' => 'confirmed' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $converted ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_sale_failed', __( 'تعذر تحويل الحجز إلى عملية بيع.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'sale.created', 'sale', $sale_id, '', null, array( 'quotation_id' => $quote_id, 'reservation_id' => $reservation_id ) ) ) ) {
			return new \WP_Error( 'adc_sale_failed', __( 'تعذر توثيق عملية البيع.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $sale_id, 'status' => 'pending_approval' );
	}

	public static function approve_sale( int $sale_id, string $invoice_reference ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_approve_sales' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية اعتماد البيع.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$invoice_reference = sanitize_text_field( $invoice_reference );
		if ( '' === $invoice_reference ) {
			return new \WP_Error( 'adc_invoice_required', __( 'مرجع الفاتورة مطلوب قبل اعتماد البيع.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$sales = Schema::table( 'sales' );
		$sale = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $sales WHERE id = %d FOR UPDATE", $sale_id ), ARRAY_A );
		if ( ! $sale || 'pending_approval' !== $sale['status'] || (int) $sale['owner_user_id'] === get_current_user_id() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_sale_approval_denied', __( 'لا يمكن اعتماد هذه العملية أو أنها حُسمت مسبقًا.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$finance_pending = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'finance_requests' ) . " WHERE sale_id = %d AND status IN ('submitted','under_review') LIMIT 1", $sale_id ) );
		if ( $finance_pending ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_finance_pending', __( 'يجب حسم طلب التمويل قبل اعتماد البيع.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$branch_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT branch_id FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d', (int) $sale['vehicle_id'] ) );
		if ( ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( $branch_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_forbidden_branch', __( 'لا تملك صلاحية لهذا الفرع.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$sale_update = $wpdb->update( $sales, array( 'status' => 'approved', 'invoice_reference' => $invoice_reference, 'approved_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => $sale_id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => 'sold', 'updated_at' => $now ), array( 'id' => (int) $sale['vehicle_id'], 'branch_id' => $branch_id, 'status' => 'reserved' ), array( '%s', '%s' ), array( '%d', '%d', '%s' ) );
		$movement = 1 === $vehicle_update ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $sale['vehicle_id'], 'from_branch_id' => $branch_id, 'to_branch_id' => $branch_id, 'from_status' => 'reserved', 'to_status' => 'sold', 'actor_user_id' => get_current_user_id(), 'reason' => 'Sale #' . $sale_id . ' approved', 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		if ( false === $sale_update || 1 !== $vehicle_update || false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_sale_approval_failed', __( 'تعذر حفظ اعتماد البيع.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.status_changed', 'vehicle', (int) $sale['vehicle_id'], 'Sale approved', array( 'status' => 'reserved' ), array( 'status' => 'sold' ) ) && AuditLog::record( 'sale.approved', 'sale', $sale_id, 'Sales approval', array( 'status' => 'pending_approval' ), array( 'status' => 'approved', 'approver' => get_current_user_id() ) ) && DomainEventPublisher::commit( 'sale.approved', $sale_id, $branch_id, 'approved' ) ) ) {
			return new \WP_Error( 'adc_sale_approval_failed', __( 'تعذر توثيق اعتماد البيع.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $sale_id, 'status' => 'approved' );
	}

	public static function create_finance_request( int $sale_id, string $provider, int $amount, bool $consent, array $terms = array() ) {
		global $wpdb;
		$provider = sanitize_text_field( $provider );
		$down_payment = absint( $terms['down_payment'] ?? 0 );
		$term_months = absint( $terms['term_months'] ?? 0 );
		$monthly_payment = absint( $terms['monthly_payment'] ?? 0 );
		if ( ! current_user_can( 'adc_manage_finance' ) || ! $consent || $amount <= 0 || '' === $provider || mb_strlen( $provider ) > 100 || $term_months > 120 || ( ( $term_months > 0 ) !== ( $monthly_payment > 0 ) ) ) {
			return new \WP_Error( 'adc_finance_denied', __( 'الصلاحية أو الموافقة أو المبلغ غير صالح.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT s.id,s.status,q.final_amount,v.branch_id FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.id = %d FOR UPDATE', $sale_id ), ARRAY_A );
		if ( ! $sale || 'pending_approval' !== $sale['status'] || $amount > (int) $sale['final_amount'] || $down_payment > (int) $sale['final_amount'] || $amount + $down_payment > (int) $sale['final_amount'] || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $sale['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_finance_sale_state', __( 'لا يمكن تقديم طلب تمويل لهذه العملية.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$previous = $wpdb->get_row( $wpdb->prepare( 'SELECT id,attempt_number,status FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d ORDER BY attempt_number DESC,id DESC LIMIT 1 FOR UPDATE', $sale_id ), ARRAY_A );
		if ( $previous && in_array( $previous['status'], array( 'submitted','under_review','approved' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_finance_attempt_open', __( 'The current finance attempt must reach a final rejected or cancelled state before another provider is tried.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		$attempt_number = $previous ? (int) $previous['attempt_number'] + 1 : 1;
		if ( $attempt_number > 100 ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_finance_attempt_limit', __( 'The finance attempt history requires administrative review.', 'auto-dealership-core' ), array( 'status'=>409 ) ); }
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( Schema::table( 'finance_requests' ), array( 'sale_id'=>$sale_id, 'attempt_number'=>$attempt_number, 'previous_request_id'=>$previous ? (int) $previous['id'] : 0, 'provider'=>$provider, 'requested_amount'=>$amount, 'down_payment'=>$down_payment, 'term_months'=>$term_months, 'monthly_payment'=>$monthly_payment, 'requested_by'=>get_current_user_id(), 'status'=>'submitted', 'consent_at'=>$now, 'submitted_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now ), array( '%d','%d','%d','%s','%d','%d','%d','%d','%d','%s','%s','%s','%s','%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_finance_failed', __( 'تعذر حفظ طلب التمويل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'finance.submitted', 'finance_request', $id, 'Customer finance consent recorded', null, array( 'sale_id'=>$sale_id, 'provider'=>$provider, 'amount'=>$amount, 'down_payment'=>$down_payment, 'term_months'=>$term_months, 'monthly_payment'=>$monthly_payment, 'attempt_number'=>$attempt_number, 'previous_request_id'=>$previous ? (int) $previous['id'] : 0 ) ) && DomainEventPublisher::commit( 'finance.submitted', $id, (int) $sale['branch_id'], 'submitted' ) ) ) {
			return new \WP_Error( 'adc_finance_failed', __( 'تعذر توثيق طلب التمويل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id'=>$id, 'status'=>'submitted', 'attempt_number'=>$attempt_number, 'previous_request_id'=>$previous ? (int) $previous['id'] : 0 );
	}

	public static function update_finance_status( int $request_id, string $status, string $provider_reference = '', string $decision_reason = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_finance' ) || ! in_array( $status, array( 'under_review', 'approved', 'rejected' ), true ) ) {
			return new \WP_Error( 'adc_finance_forbidden', __( 'لا تملك صلاحية تحديث حالة التمويل.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$provider_reference = sanitize_text_field( $provider_reference );
		$decision_reason = sanitize_textarea_field( $decision_reason );
		if ( ( 'approved' === $status && '' === $provider_reference ) || mb_strlen( $decision_reason ) > 2000 ) {
			return new \WP_Error( 'adc_finance_reference_required', __( 'مرجع جهة التمويل مطلوب عند الاعتماد.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'finance_requests' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT f.status,f.requested_by,v.branch_id FROM ' . $table . ' f INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=f.sale_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE f.id = %d FOR UPDATE', $request_id ), ARRAY_A );
		if ( ! $row || ! in_array( $row['status'], array( 'submitted', 'under_review' ), true ) || (int) $row['requested_by'] === get_current_user_id() || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_finance_state', __( 'حالة طلب التمويل لا تسمح بالتحديث.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( $table, array( 'status'=>$status, 'provider_reference'=>$provider_reference, 'decision_reason'=>$decision_reason, 'decided_at'=>in_array( $status, array( 'approved','rejected' ), true ) ? $now : null, 'updated_at'=>$now ), array( 'id'=>$request_id, 'status'=>$row['status'] ), array( '%s','%s','%s','%s','%s' ), array( '%d','%s' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_finance_update_failed', __( 'تعذر حفظ قرار التمويل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'finance.' . $status, 'finance_request', $request_id, $decision_reason, array( 'status'=>$row['status'] ), array( 'status'=>$status, 'provider_reference_present'=>'' !== $provider_reference ) ) && DomainEventPublisher::commit( 'finance.' . $status, $request_id, (int) $row['branch_id'], $status ) ) ) {
			return new \WP_Error( 'adc_finance_update_failed', __( 'تعذر توثيق قرار التمويل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $request_id, 'status' => $status );
	}

	public static function finance_history( int $sale_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_finance' ) && ! current_user_can( 'adc_manage_finance' ) ) {
			return new \WP_Error( 'adc_finance_forbidden', __( 'Finance viewing permission is required.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT s.id,v.branch_id FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.id=%d', $sale_id ), ARRAY_A );
		if ( ! $sale || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $sale['branch_id'] ) ) {
			return new \WP_Error( 'adc_finance_not_found', __( 'Finance history is unavailable in your branch scope.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,sale_id,attempt_number,previous_request_id,provider,requested_amount,down_payment,term_months,monthly_payment,requested_by,status,provider_reference,decision_reason,consent_at,submitted_at,decided_at,created_at,updated_at FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d ORDER BY attempt_number ASC,id ASC LIMIT 100', $sale_id ), ARRAY_A ) ?: array();
		foreach ( $rows as &$row ) {
			foreach ( array( 'id','sale_id','attempt_number','previous_request_id','requested_amount','down_payment','term_months','monthly_payment','requested_by' ) as $field ) { $row[$field] = (int) $row[$field]; }
		}
		unset( $row );
		return $rows;
	}
}
