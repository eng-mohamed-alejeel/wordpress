<?php
namespace AutoDealership\API;

use AutoDealership\Branches\BranchService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleSpecifications;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Inventory\VehicleIssueService;
use AutoDealership\Inventory\VehicleReturnService;
use AutoDealership\Leads\LeadService;
use AutoDealership\Leads\RequestWorkflow;
use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Sales\SalesService;
use AutoDealership\Sales\SaleCancellationService;
use AutoDealership\Delivery\DeliveryService;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Payments\RefundService;
use AutoDealership\Pricing\QuoteHistory;

defined( 'ABSPATH' ) || exit;

/** Versioned REST boundary. All writes delegate to validated services. */
final class Routes {
	public static function register(): void {
		register_rest_route( 'auto-dealership/v1', '/customers/(?P<id>\d+)/duplicates', array(
			'methods'=>'GET', 'permission_callback'=>static fn() => current_user_can( 'manage_options' ),
			'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::candidates( (int) $r['id'] ) ),
			'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/customers/merge', array(
			array( 'methods'=>'GET', 'permission_callback'=>static fn() => current_user_can( 'manage_options' ),
				'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::preview( (int) $r['source_id'], (int) $r['target_id'] ) ),
				'args'=>array( 'source_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ), 'target_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ) ) ),
			array( 'methods'=>'POST', 'permission_callback'=>static fn() => current_user_can( 'manage_options' ),
				'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::merge( (int) $r['source_id'], (int) $r['target_id'], (string) $r['revision'], (string) $r['evidence'], true === $r['verified'] ) ),
				'args'=>array( 'source_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ), 'target_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ), 'revision'=>array( 'type'=>'string', 'required'=>true, 'pattern'=>'^[a-f0-9]{64}$' ), 'evidence'=>array( 'type'=>'string', 'required'=>true, 'maxLength'=>120 ), 'verified'=>array( 'type'=>'boolean', 'required'=>true ) ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/specifications', array(
			'methods' => 'PATCH',
			'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ),
			'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( VehicleSpecifications::update( (int) $r['id'], (array) $r['specifications'], (string) $r['reason'] ) ),
			'args' => array(
				'id' => array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ),
				'reason' => array( 'type'=>'string', 'minLength'=>1, 'maxLength'=>2000, 'required'=>true ),
				'specifications' => array( 'type'=>'object', 'required'=>true ),
			),
		) );
		register_rest_route( 'auto-dealership/v1', '/sales/(?P<id>\d+)/payments', array(
			'methods' => 'POST',
			'permission_callback' => static fn() => current_user_can( 'adc_record_payments' ),
			'callback' => static fn( \WP_REST_Request $request ) => rest_ensure_response( PaymentService::record( (int) $request['id'], (int) $request['amount'], (string) $request['source'], (string) $request['reference'] ) ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'amount' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'source' => array( 'type' => 'string', 'enum' => array( 'cash_receipt', 'bank_transfer', 'finance_disbursement' ), 'required' => true ), 'reference' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/payments/(?P<id>\d+)/decision', array(
			'methods' => 'POST',
			'permission_callback' => static fn() => current_user_can( 'adc_verify_payments' ),
			'callback' => static fn( \WP_REST_Request $request ) => rest_ensure_response( PaymentService::decide( (int) $request['id'], (bool) $request['approve'], (string) $request['reason'] ) ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'approve' => array( 'type' => 'boolean', 'required' => true ), 'reason' => array( 'type' => 'string', 'minLength' => 1, 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/returns/(?P<id>\d+)/refunds', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_record_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::request(absint($r['id']),absint($r['amount']),(string)$r['method'],(string)$r['reference'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'amount'=>array('type'=>'integer','minimum'=>1,'required'=>true),'method'=>array('type'=>'string','enum'=>array('cash_refund','bank_transfer','finance_reversal'),'required'=>true),'reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true)) ) );
		register_rest_route( 'auto-dealership/v1', '/refunds/(?P<id>\d+)/decision', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_verify_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::decide(absint($r['id']),(bool)$r['approve'],(string)$r['reason'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'approve'=>array('type'=>'boolean','required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'required'=>true)) ) );
		register_rest_route( 'auto-dealership/v1', '/sales/(?P<id>\d+)/cancellation', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_cancel_sales'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(SaleCancellationService::cancel(absint($r['id']),(string)$r['reason'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'maxLength'=>2000,'required'=>true)) ) );
		register_rest_route( 'auto-dealership/v1', '/cancellations/(?P<id>\d+)/refunds', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_record_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::request_cancellation(absint($r['id']),absint($r['amount']),(string)$r['method'],(string)$r['reference'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'amount'=>array('type'=>'integer','minimum'=>1,'required'=>true),'method'=>array('type'=>'string','enum'=>array('cash_refund','bank_transfer','finance_reversal'),'required'=>true),'reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true)) ) );
		register_rest_route( 'auto-dealership/v1', '/branches', array(
			array( 'methods' => 'GET', 'callback' => static fn() => rest_ensure_response( BranchService::public_list() ), 'permission_callback' => '__return_true' ),
			array( 'methods' => 'POST', 'callback' => array( self::class, 'create_branch' ), 'permission_callback' => static fn() => current_user_can( 'manage_options' ), 'args' => array( 'code' => array( 'required' => true, 'type' => 'string' ), 'name' => array( 'required' => true, 'type' => 'string' ), 'city' => array( 'type' => 'string' ), 'address' => array( 'type' => 'string' ) ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/vehicles', array(
			array( 'methods' => 'GET', 'callback' => array( self::class, 'catalog' ), 'permission_callback' => '__return_true', 'args' => array( 'page' => array( 'type' => 'integer', 'minimum' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 48 ), 'brand' => array( 'type' => 'string' ), 'model_year' => array( 'type' => 'integer' ), 'body_type' => array( 'type' => 'string' ), 'fuel_type' => array( 'type' => 'string' ) ) ),
			array( 'methods' => 'POST', 'callback' => array( self::class, 'create_vehicle' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/status', array(
			'methods' => 'POST', 'callback' => array( self::class, 'transition_vehicle' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'status' => array( 'type' => 'string', 'required' => true ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/receipt', array( 'methods' => 'POST', 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( VehicleIntakeService::receive( absint( $r['id'] ), $r->get_params() ) ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id' => array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'odometer'=>array('type'=>'integer','minimum'=>0), 'condition'=>array('type'=>'string','enum'=>array('good','damaged','incomplete'),'required'=>true), 'document_reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true), 'notes'=>array('type'=>'string'), 'evidence_media_ids'=>array('type'=>'array','maxItems'=>10,'items'=>array('type'=>'integer','minimum'=>1)) ) ) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/inspection', array( 'methods' => 'POST', 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( VehicleIntakeService::inspect( absint( $r['id'] ), $r->get_params() ) ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id'=>array('type'=>'integer','minimum'=>1,'required'=>true), 'checklist'=>array('type'=>'object','required'=>true), 'notes'=>array('type'=>'string'), 'evidence_media_ids'=>array('type'=>'array','maxItems'=>10,'items'=>array('type'=>'integer','minimum'=>1)) ) ) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/issues', array( 'methods'=>'POST','callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(VehicleIssueService::open(absint($r['id']),(string)$r['type'],(string)$r['reason'],absint($r['assigned_user_id']),(string)$r['review_at'])),'permission_callback'=>static fn()=>current_user_can('adc_manage_inventory'),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'type'=>array('type'=>'string','enum'=>array('hold','maintenance'),'required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'required'=>true),'assigned_user_id'=>array('type'=>'integer','minimum'=>0),'review_at'=>array('type'=>'string')) ) );
		register_rest_route( 'auto-dealership/v1', '/vehicle-issues/(?P<id>\d+)/resolve', array( 'methods'=>'POST','callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(VehicleIssueService::resolve(absint($r['id']),(string)$r['resolution'])),'permission_callback'=>static fn()=>current_user_can('adc_manage_inventory'),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'resolution'=>array('type'=>'string','minLength'=>1,'required'=>true)) ) );
		register_rest_route( 'auto-dealership/v1', '/deliveries/(?P<id>\d+)/return', array( 'methods'=>'POST','callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(VehicleReturnService::receive(absint($r['id']),$r->get_params())),'permission_callback'=>static fn()=>current_user_can('adc_process_returns'),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'location_id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'condition'=>array('type'=>'string','enum'=>array('good','damaged','incomplete'),'required'=>true),'odometer'=>array('type'=>'integer','minimum'=>0,'required'=>true),'document_reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'maxLength'=>2000,'required'=>true)) ) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/location', array(
			'methods' => 'POST', 'callback' => array( self::class, 'move_vehicle_location' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'location_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'reason' => array( 'type' => 'string', 'minLength' => 1, 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/vin', array(
			'methods' => 'POST', 'callback' => array( self::class, 'change_vehicle_vin' ), 'permission_callback' => static fn() => current_user_can( 'adc_change_vehicle_vin' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'vin' => array( 'type' => 'string', 'minLength' => 17, 'maxLength' => 17, 'required' => true ), 'reason' => array( 'type' => 'string', 'minLength' => 1, 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/vehicles/(?P<id>\d+)/transfer', array(
			'methods' => 'POST', 'callback' => array( self::class, 'transfer_vehicle' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'target_branch_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/transfers/(?P<id>\d+)/decision', array( 'methods' => 'POST', 'callback' => array( self::class, 'decide_transfer' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'approve' => array( 'type' => 'boolean', 'required' => true ) ) ) );
		register_rest_route( 'auto-dealership/v1', '/transfers/(?P<id>\d+)/dispatch', array( 'methods' => 'POST', 'callback' => array( self::class, 'dispatch_transfer' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ) ) );
		register_rest_route( 'auto-dealership/v1', '/transfers/(?P<id>\d+)/receipt', array( 'methods' => 'POST', 'callback' => array( self::class, 'receive_transfer' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ) ) );
		register_rest_route( 'auto-dealership/v1', '/reservations', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_reservation' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_reservations' ), 'args' => array( 'vehicle_id' => array( 'type' => 'integer', 'required' => true ), 'customer_id' => array( 'type' => 'integer', 'required' => true ), 'idempotency_key' => array( 'type' => 'string', 'required' => true ), 'deposit_amount' => array( 'type' => 'integer', 'minimum' => 0 ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/reservations/(?P<id>\d+)/cancel', array(
			'methods' => 'POST', 'callback' => array( self::class, 'cancel_reservation' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_reservations' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/quotations', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_quote' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'customer_id' => array( 'type' => 'integer', 'required' => true ), 'vehicle_id' => array( 'type' => 'integer', 'required' => true ), 'valid_until' => array( 'type' => 'string', 'required' => true, 'format' => 'date' ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/quotations/(?P<id>\d+)/discounts', array(
			'methods' => 'POST', 'callback' => array( self::class, 'request_discount' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'amount' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/quotations/(?P<id>\d+)/versions', array(
			'methods' => 'GET', 'callback' => array( self::class, 'quote_versions' ), 'permission_callback' => array( QuoteHistory::class, 'can_read' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/discounts/(?P<id>\d+)/decision', array(
			'methods' => 'POST', 'callback' => array( self::class, 'decide_discount' ), 'permission_callback' => static fn() => current_user_can( 'adc_review_discounts' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'approve' => array( 'type' => 'boolean', 'required' => true ), 'reason' => array( 'type' => 'string' ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/sales', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_sale' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'quotation_id' => array( 'type' => 'integer', 'required' => true ), 'reservation_id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/sales/(?P<id>\d+)/approval', array(
			'methods' => 'POST', 'callback' => array( self::class, 'approve_sale' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_sales' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'invoice_reference' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/finance-requests', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_finance_request' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_finance' ), 'args' => array( 'sale_id' => array( 'type' => 'integer', 'required' => true ), 'provider' => array( 'type' => 'string', 'required' => true ), 'amount' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'consent' => array( 'type' => 'boolean', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/finance-requests/(?P<id>\d+)/status', array(
			'methods' => 'POST', 'callback' => array( self::class, 'update_finance_status' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_finance' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'status' => array( 'type' => 'string', 'required' => true ), 'provider_reference' => array( 'type' => 'string' ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/sales/(?P<id>\d+)/delivery', array(
			'methods' => 'POST', 'callback' => array( self::class, 'prepare_delivery' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_delivery' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/deliveries/(?P<id>\d+)/vin', array(
			'methods' => 'POST', 'callback' => array( self::class, 'confirm_delivery_vin' ), 'permission_callback' => static fn() => current_user_can( 'adc_confirm_vehicle_vin' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'vin' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/deliveries/(?P<id>\d+)/approval', array(
			'methods' => 'POST', 'callback' => array( self::class, 'approve_delivery' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_delivery' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/deliveries/(?P<id>\d+)/release', array(
			'methods' => 'POST', 'callback' => array( self::class, 'release_delivery' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_delivery' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/leads', array(
			array( 'methods' => 'POST', 'callback' => array( self::class, 'create_public_lead' ), 'permission_callback' => '__return_true', 'args' => array(
				'name'=>array( 'type'=>'string', 'required'=>true ), 'mobile'=>array( 'type'=>'string', 'required'=>true ),
				'email'=>array( 'type'=>'string' ), 'city'=>array( 'type'=>'string' ), 'branch_id'=>array( 'type'=>'integer', 'minimum'=>0 ),
				'source'=>array( 'type'=>'string' ), 'consent_marketing'=>array( 'type'=>'boolean' ),
				'message'=>array( 'type'=>'string', 'maxLength'=>4000 ), 'request_kind'=>array( 'type'=>'string' ),
				'car_id'=>array( 'type'=>'integer', 'minimum'=>0 ), 'date'=>array( 'type'=>'string' ), 'time'=>array( 'type'=>'string' ),
				'website'=>array( 'type'=>'string' ), 'idempotency_key'=>array( 'type'=>'string', 'maxLength'=>36 ),
			) ),
			array( 'methods' => 'GET', 'callback' => array( self::class, 'list_leads' ), 'permission_callback' => static fn() => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'page' => array( 'type' => 'integer', 'minimum' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ) ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/leads/(?P<id>\d+)/stage', array(
			'methods' => 'POST', 'callback' => array( self::class, 'update_lead_stage' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'stage' => array( 'type' => 'string', 'required' => true ), 'reason' => array( 'type' => 'string' ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/leads/(?P<id>\d+)/request', array(
			array( 'methods'=>'GET', 'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( RequestWorkflow::read( (int) $r['id'] ) ),
				'permission_callback'=>static fn() => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ),
				'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ) ) ),
			array( 'methods'=>'PATCH', 'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( RequestWorkflow::update( (int) $r['id'], $r->get_params() ) ),
				'permission_callback'=>static fn() => current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ),
				'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ), 'revision'=>array( 'type'=>'string', 'required'=>true, 'pattern'=>'^[a-f0-9]{64}$' ), 'status'=>array( 'type'=>'string' ), 'customer_reply'=>array( 'type'=>'string', 'maxLength'=>4000 ), 'requested_date'=>array( 'type'=>'string' ), 'requested_time'=>array( 'type'=>'string' ) ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/bookings/(?P<id>\d+)/cancel', array(
			'methods'=>'POST', 'permission_callback'=>'is_user_logged_in', 'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ) ),
			'callback'=>static function ( \WP_REST_Request $r ) {
				$lead_id = RequestWorkflow::linked_lead( 'booking', (int) $r['id'] );
				if ( is_wp_error( $lead_id ) ) { return $lead_id; }
				if ( ! $lead_id ) { return new \WP_Error( 'adc_request_not_found', __( 'الطلب غير موجود أو خارج نطاق صلاحيتك.', 'auto-dealership-core' ), array( 'status'=>404 ) ); }
				return rest_ensure_response( RequestWorkflow::update( $lead_id, array(), true ) );
			},
		) );
		register_rest_route( 'auto-dealership/v1', '/leads/(?P<id>\d+)/assignment', array(
			'methods' => 'POST', 'callback' => array( self::class, 'assign_lead' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'staff_id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/leads/(?P<id>\d+)/activities', array(
			'methods' => 'GET', 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( LeadService::activity_history( (int) $r['id'], (int) ( $r['page'] ?? 1 ), (int) ( $r['per_page'] ?? 50 ) ) ),
			'permission_callback' => static fn() => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ),
			'args' => array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ), 'page'=>array( 'type'=>'integer', 'minimum'=>1 ), 'per_page'=>array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>100 ) ),
		) );
		register_rest_route( 'auto-dealership/v1', '/leads/(?P<id>\d+)/activities', array(
			'methods' => 'POST', 'callback' => array( self::class, 'add_activity' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'type' => array( 'type' => 'string', 'required' => true ), 'notes' => array( 'type' => 'string', 'required' => true ), 'next_action_at' => array( 'type' => 'string' ) ),
		) );
	}

	public static function catalog( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = $request->get_params();
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 48, max( 1, absint( $request->get_param( 'per_page' ) ?: 12 ) ) );
		$total = VehicleService::catalog_total( $filters );
		return rest_ensure_response( array( 'items' => VehicleService::catalog( $filters ), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => (int) ceil( $total / $per_page ) ) );
	}

	public static function create_branch( \WP_REST_Request $request ) {
		return rest_ensure_response( BranchService::create( $request->get_params() ) );
	}

	public static function create_vehicle( \WP_REST_Request $request ) {
		return rest_ensure_response( VehicleService::create( $request->get_params() ) );
	}

	public static function transition_vehicle( \WP_REST_Request $request ) {
		return rest_ensure_response( VehicleService::transition( absint( $request['id'] ), (string) $request['status'], (string) $request['reason'] ) );
	}

	public static function move_vehicle_location( \WP_REST_Request $request ) { return rest_ensure_response( VehicleService::move_location( absint( $request['id'] ), absint( $request['location_id'] ), (string) $request['reason'] ) ); }
	public static function change_vehicle_vin( \WP_REST_Request $request ) { return rest_ensure_response( VehicleService::change_vin( absint( $request['id'] ), (string) $request['vin'], (string) $request['reason'] ) ); }

	public static function transfer_vehicle( \WP_REST_Request $request ) {
		return rest_ensure_response( VehicleService::transfer( absint( $request['id'] ), absint( $request['target_branch_id'] ), (string) $request['reason'] ) );
	}

	public static function decide_transfer( \WP_REST_Request $request ) {
		return rest_ensure_response( \AutoDealership\Inventory\TransferService::decide( absint( $request['id'] ), (bool) $request['approve'] ) );
	}

	public static function dispatch_transfer( \WP_REST_Request $request ) {
		return rest_ensure_response( \AutoDealership\Inventory\TransferService::dispatch( absint( $request['id'] ) ) );
	}

	public static function receive_transfer( \WP_REST_Request $request ) {
		return rest_ensure_response( \AutoDealership\Inventory\TransferService::receive( absint( $request['id'] ) ) );
	}

	public static function create_reservation( \WP_REST_Request $request ) {
		return rest_ensure_response( ReservationService::create( $request->get_params() ) );
	}

	public static function cancel_reservation( \WP_REST_Request $request ) {
		return rest_ensure_response( ReservationService::cancel( absint( $request['id'] ), (string) $request['reason'] ) );
	}

	public static function create_public_lead( \WP_REST_Request $request ) {
		return rest_ensure_response( \AutoDealership\Leads\PublicIntake::submit( $request->get_params() ) );
	}

	public static function list_leads( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( LeadService::list_for_current_user( absint( $request->get_param( 'page' ) ) ?: 1, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
	}

	public static function update_lead_stage( \WP_REST_Request $request ) {
		return rest_ensure_response( LeadService::update_stage( absint( $request['id'] ), (string) $request['stage'], (string) $request->get_param( 'reason' ) ) );
	}

	public static function assign_lead( \WP_REST_Request $request ) {
		return rest_ensure_response( LeadService::assign( absint( $request['id'] ), absint( $request['staff_id'] ) ) );
	}

	public static function add_activity( \WP_REST_Request $request ) {
		return rest_ensure_response( LeadService::add_activity( absint( $request['id'] ), sanitize_key( (string) $request['type'] ), (string) $request['notes'], (string) $request->get_param( 'next_action_at' ) ) );
	}

	public static function create_quote( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::create_quote( absint( $request['customer_id'] ), absint( $request['vehicle_id'] ), (string) $request['valid_until'] ) );
	}

	public static function request_discount( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::request_discount( absint( $request['id'] ), absint( $request['amount'] ), (string) $request['reason'] ) );
	}

	public static function quote_versions( \WP_REST_Request $request ) {
		return rest_ensure_response( QuoteHistory::versions( absint( $request['id'] ), max( 1, absint( $request->get_param( 'page' ) ) ) ) );
	}

	public static function decide_discount( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::decide_discount( absint( $request['id'] ), (bool) $request['approve'], (string) $request->get_param( 'reason' ) ) );
	}

	public static function create_sale( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::create_sale( absint( $request['quotation_id'] ), absint( $request['reservation_id'] ) ) );
	}

	public static function approve_sale( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::approve_sale( absint( $request['id'] ), (string) $request['invoice_reference'] ) );
	}

	public static function create_finance_request( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::create_finance_request( absint( $request['sale_id'] ), (string) $request['provider'], absint( $request['amount'] ), (bool) $request['consent'] ) );
	}

	public static function update_finance_status( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::update_finance_status( absint( $request['id'] ), (string) $request['status'], (string) $request->get_param( 'provider_reference' ) ) );
	}

	public static function prepare_delivery( \WP_REST_Request $request ) {
		return rest_ensure_response( DeliveryService::prepare( absint( $request['id'] ) ) );
	}

	public static function confirm_delivery_vin( \WP_REST_Request $request ) {
		return rest_ensure_response( DeliveryService::confirm_vin( absint( $request['id'] ), (string) $request['vin'] ) );
	}

	public static function approve_delivery( \WP_REST_Request $request ) {
		return rest_ensure_response( DeliveryService::approve( absint( $request['id'] ) ) );
	}

	public static function release_delivery( \WP_REST_Request $request ) {
		return rest_ensure_response( DeliveryService::release( absint( $request['id'] ) ) );
	}
}
