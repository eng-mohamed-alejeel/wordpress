<?php
namespace AutoDealership\API;

use AutoDealership\Branches\BranchService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Inventory\VehicleSpecifications;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Inventory\VehicleIssueService;
use AutoDealership\Inventory\VehicleReturnService;
use AutoDealership\Inventory\VehicleAcquisitionService;
use AutoDealership\Purchasing\SupplierService;
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
use AutoDealership\Security\PublicRequestGuard;

defined( 'ABSPATH' ) || exit;

/** Versioned REST boundary. All writes delegate to validated services. */
final class Routes {
	/** Explicit anonymous surface. Tests fail when registration and this inventory diverge. */
	public const PUBLIC_ENDPOINTS = array(
		'GET /branches' => 'public_read',
		'GET /vehicles' => 'public_read',
		'POST /leads'   => 'intake',
	);

	public static function public_endpoints(): array { return self::PUBLIC_ENDPOINTS; }

	/** Registers the compatibility contract and the enveloped v2 contract together. */
	private static function route( string $route, array $args ): void {
		if ( isset( $args['callback'] ) ) { $args = CurrencyContract::endpoint( $args ); }
		else { foreach ( $args as $index=>$endpoint ) { if ( is_array( $endpoint ) && isset( $endpoint['callback'] ) ) { $args[$index] = CurrencyContract::endpoint( $endpoint ); } } }
		foreach ( array( 'auto-dealership/v1', 'auto-dealership/v2' ) as $namespace ) {
			register_rest_route( $namespace, $route, $args );
		}
	}

	public static function register(): void {
		self::route( '/customers/(?P<id>\d+)/duplicates', array(
			'methods'=>'GET', 'permission_callback'=>static fn() => current_user_can( 'manage_options' ),
			'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::candidates( (int) $r['id'] ) ),
			'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ) ),
		) );
		self::route( '/customers/merge', array(
			array( 'methods'=>'GET', 'permission_callback'=>static fn() => current_user_can( 'manage_options' ),
				'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::preview( (int) $r['source_id'], (int) $r['target_id'] ) ),
				'args'=>array( 'source_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ), 'target_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ) ) ),
			array( 'methods'=>'POST', 'permission_callback'=>static fn() => current_user_can( 'manage_options' ),
				'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::merge( (int) $r['source_id'], (int) $r['target_id'], (string) $r['revision'], (string) $r['evidence'], true === $r['verified'] ) ),
				'args'=>array( 'source_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ), 'target_id'=>array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ), 'revision'=>array( 'type'=>'string', 'required'=>true, 'pattern'=>'^[a-f0-9]{64}$' ), 'evidence'=>array( 'type'=>'string', 'required'=>true, 'maxLength'=>120 ), 'verified'=>array( 'type'=>'boolean', 'required'=>true ) ) ),
		) );
		self::route( '/account/preferences', array(
			array( 'methods'=>'GET', 'permission_callback'=>'is_user_logged_in', 'callback'=>static fn() => rest_ensure_response( CustomerIdentity::current_preferences() ) ),
			array( 'methods'=>'POST', 'permission_callback'=>'is_user_logged_in',
				'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( CustomerIdentity::update_preferences( true === $r['consent_marketing'] ) ),
				'args'=>array( 'consent_marketing'=>array( 'type'=>'boolean', 'required'=>true ) ) ),
		) );
		self::route( '/vehicles/(?P<id>\d+)/specifications', array(
			'methods' => 'PATCH',
			'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ),
			'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( VehicleSpecifications::update( (int) $r['id'], (array) $r['specifications'], (string) $r['reason'] ) ),
			'args' => array(
				'id' => array( 'type'=>'integer', 'minimum'=>1, 'required'=>true ),
				'reason' => array( 'type'=>'string', 'minLength'=>1, 'maxLength'=>2000, 'required'=>true ),
				'specifications' => array( 'type'=>'object', 'required'=>true ),
			),
		) );
		self::route( '/vehicles/(?P<id>\d+)/acquisition', array(
			array( 'methods'=>'GET', 'permission_callback'=>static fn()=>current_user_can( 'adc_view_vehicle_costs' ) || current_user_can( 'adc_manage_vehicle_costs' ), 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( VehicleAcquisitionService::get( absint( $r['id'] ) ) ), 'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ) ) ),
			array( 'methods'=>'PATCH', 'permission_callback'=>static fn()=>current_user_can( 'adc_manage_vehicle_costs' ), 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( VehicleAcquisitionService::update( absint( $r['id'] ), (array) $r['acquisition'], (string) $r['reason'] ) ), 'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'acquisition'=>array( 'type'=>'object','required'=>true ), 'reason'=>array( 'type'=>'string','minLength'=>1,'maxLength'=>2000,'required'=>true ) ) ),
		) );
		self::route( '/suppliers', array(
			array( 'methods'=>'GET', 'permission_callback'=>static fn()=>current_user_can( 'adc_view_suppliers' ) || current_user_can( 'adc_manage_suppliers' ), 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( SupplierService::all( (bool) $r['active_only'] ) ), 'args'=>array( 'active_only'=>array( 'type'=>'boolean','default'=>false ) ) ),
			array( 'methods'=>'POST', 'permission_callback'=>static fn()=>current_user_can( 'adc_manage_suppliers' ), 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( SupplierService::create( $r->get_params() ) ) ),
		) );
		self::route( '/sales/(?P<id>\d+)/payments', array(
			'methods' => 'POST',
			'permission_callback' => static fn() => current_user_can( 'adc_record_payments' ),
			'callback' => static fn( \WP_REST_Request $request ) => rest_ensure_response( PaymentService::record( (int) $request['id'], (int) $request['amount'], (string) $request['source'], (string) $request['reference'] ) ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'amount' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'source' => array( 'type' => 'string', 'enum' => array( 'cash_receipt', 'bank_transfer', 'finance_disbursement' ), 'required' => true ), 'reference' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'required' => true ) ),
		) );
		self::route( '/payments/(?P<id>\d+)/decision', array(
			'methods' => 'POST',
			'permission_callback' => static fn() => current_user_can( 'adc_verify_payments' ),
			'callback' => static fn( \WP_REST_Request $request ) => rest_ensure_response( PaymentService::decide( (int) $request['id'], (bool) $request['approve'], (string) $request['reason'] ) ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'approve' => array( 'type' => 'boolean', 'required' => true ), 'reason' => array( 'type' => 'string', 'minLength' => 1, 'required' => true ) ),
		) );
		self::route( '/returns/(?P<id>\d+)/refunds', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_record_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::request(absint($r['id']),absint($r['amount']),(string)$r['method'],(string)$r['reference'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'amount'=>array('type'=>'integer','minimum'=>1,'required'=>true),'method'=>array('type'=>'string','enum'=>array('cash_refund','bank_transfer','finance_reversal'),'required'=>true),'reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true)) ) );
		self::route( '/refunds/(?P<id>\d+)/decision', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_verify_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::decide(absint($r['id']),(bool)$r['approve'],(string)$r['reason'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'approve'=>array('type'=>'boolean','required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'required'=>true)) ) );
		self::route( '/sales/(?P<id>\d+)/cancellation', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_cancel_sales'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(SaleCancellationService::cancel(absint($r['id']),(string)$r['reason'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'maxLength'=>2000,'required'=>true)) ) );
		self::route( '/cancellations/(?P<id>\d+)/refunds', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_record_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::request_cancellation(absint($r['id']),absint($r['amount']),(string)$r['method'],(string)$r['reference'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'amount'=>array('type'=>'integer','minimum'=>1,'required'=>true),'method'=>array('type'=>'string','enum'=>array('cash_refund','bank_transfer','finance_reversal'),'required'=>true),'reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true)) ) );
		self::route( '/reservations/(?P<id>\d+)/refunds', array( 'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('adc_record_refunds'),'callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(RefundService::request_reservation(absint($r['id']),absint($r['amount']),(string)$r['method'],(string)$r['reference'])),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'amount'=>array('type'=>'integer','minimum'=>1,'required'=>true),'method'=>array('type'=>'string','enum'=>array('cash_refund','bank_transfer','finance_reversal'),'required'=>true),'reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true)) ) );
		self::route( '/branches', array(
			array( 'methods' => 'GET', 'callback' => static fn() => rest_ensure_response( BranchService::public_list() ), 'permission_callback' => static fn() => PublicRequestGuard::permission( 'public_read' ) ),
			array( 'methods' => 'POST', 'callback' => array( self::class, 'create_branch' ), 'permission_callback' => static fn() => current_user_can( 'manage_options' ), 'args' => array( 'code' => array( 'required' => true, 'type' => 'string' ), 'name' => array( 'required' => true, 'type' => 'string' ), 'city' => array( 'type' => 'string' ), 'address' => array( 'type' => 'string' ) ) ),
		) );
		self::route( '/vehicles', array(
			array( 'methods' => 'GET', 'callback' => array( self::class, 'catalog' ), 'permission_callback' => static fn() => PublicRequestGuard::permission( 'public_read' ), 'args' => self::catalog_args() ),
			array( 'methods' => 'POST', 'callback' => array( self::class, 'create_vehicle' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ) ),
		) );
		self::route( '/vehicles/(?P<id>\d+)/status', array(
			'methods' => 'POST', 'callback' => array( self::class, 'transition_vehicle' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'status' => array( 'type' => 'string', 'required' => true ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		self::route( '/vehicles/(?P<id>\d+)/receipt', array( 'methods' => 'POST', 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( VehicleIntakeService::receive( absint( $r['id'] ), $r->get_params() ) ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id' => array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'odometer'=>array('type'=>'integer','minimum'=>0), 'condition'=>array('type'=>'string','enum'=>array('good','damaged','incomplete'),'required'=>true), 'document_reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true), 'notes'=>array('type'=>'string'), 'evidence_media_ids'=>array('type'=>'array','maxItems'=>10,'items'=>array('type'=>'integer','minimum'=>1)) ) ) );
		self::route( '/vehicles/(?P<id>\d+)/inspection', array( 'methods' => 'POST', 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( VehicleIntakeService::inspect( absint( $r['id'] ), $r->get_params() ) ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id'=>array('type'=>'integer','minimum'=>1,'required'=>true), 'checklist'=>array('type'=>'object','required'=>true), 'notes'=>array('type'=>'string'), 'evidence_media_ids'=>array('type'=>'array','maxItems'=>10,'items'=>array('type'=>'integer','minimum'=>1)) ) ) );
		self::route( '/vehicles/(?P<id>\d+)/issues', array( 'methods'=>'POST','callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(VehicleIssueService::open(absint($r['id']),(string)$r['type'],(string)$r['reason'],absint($r['assigned_user_id']),(string)$r['review_at'])),'permission_callback'=>static fn()=>current_user_can('adc_manage_inventory'),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'type'=>array('type'=>'string','enum'=>array('hold','maintenance'),'required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'required'=>true),'assigned_user_id'=>array('type'=>'integer','minimum'=>0),'review_at'=>array('type'=>'string')) ) );
		self::route( '/vehicle-issues/(?P<id>\d+)/resolve', array( 'methods'=>'POST','callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(VehicleIssueService::resolve(absint($r['id']),(string)$r['resolution'])),'permission_callback'=>static fn()=>current_user_can('adc_manage_inventory'),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'resolution'=>array('type'=>'string','minLength'=>1,'required'=>true)) ) );
		self::route( '/deliveries/(?P<id>\d+)/return', array( 'methods'=>'POST','callback'=>static fn(\WP_REST_Request $r)=>rest_ensure_response(VehicleReturnService::receive(absint($r['id']),$r->get_params())),'permission_callback'=>static fn()=>current_user_can('adc_process_returns'),'args'=>array('id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'location_id'=>array('type'=>'integer','minimum'=>1,'required'=>true),'condition'=>array('type'=>'string','enum'=>array('good','damaged','incomplete'),'required'=>true),'odometer'=>array('type'=>'integer','minimum'=>0,'required'=>true),'document_reference'=>array('type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true),'reason'=>array('type'=>'string','minLength'=>1,'maxLength'=>2000,'required'=>true)) ) );
		self::route( '/vehicles/(?P<id>\d+)/location', array(
			'methods' => 'POST', 'callback' => array( self::class, 'move_vehicle_location' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'location_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'reason' => array( 'type' => 'string', 'minLength' => 1, 'required' => true ) ),
		) );
		self::route( '/vehicles/(?P<id>\d+)/vin', array(
			'methods' => 'POST', 'callback' => array( self::class, 'change_vehicle_vin' ), 'permission_callback' => static fn() => current_user_can( 'adc_change_vehicle_vin' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'vin' => array( 'type' => 'string', 'minLength' => 17, 'maxLength' => 17, 'required' => true ), 'reason' => array( 'type' => 'string', 'minLength' => 1, 'required' => true ) ),
		) );
		self::route( '/vehicles/(?P<id>\d+)/transfer', array(
			'methods' => 'POST', 'callback' => array( self::class, 'transfer_vehicle' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'target_branch_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		self::route( '/transfers/(?P<id>\d+)/decision', array( 'methods' => 'POST', 'callback' => array( self::class, 'decide_transfer' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'approve' => array( 'type' => 'boolean', 'required' => true ) ) ) );
		self::route( '/transfers/(?P<id>\d+)/dispatch', array( 'methods' => 'POST', 'callback' => array( self::class, 'dispatch_transfer' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ) ) );
		self::route( '/transfers/(?P<id>\d+)/receipt', array( 'methods' => 'POST', 'callback' => array( self::class, 'receive_transfer' ), 'permission_callback' => static fn() => current_user_can( 'adc_transfer_inventory' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ) ) );
		self::route( '/reservations', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_reservation' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_reservations' ), 'args' => array( 'vehicle_id' => array( 'type' => 'integer', 'required' => true ), 'customer_id' => array( 'type' => 'integer', 'required' => true ), 'idempotency_key' => array( 'type' => 'string', 'required' => true ), 'deposit_amount' => array( 'type' => 'integer', 'minimum' => 0 ) ),
		) );
		self::route( '/reservations/(?P<id>\d+)/cancel', array(
			'methods' => 'POST', 'callback' => array( self::class, 'cancel_reservation' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_reservations' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		self::route( '/reservations/(?P<id>\d+)/deposit', array(
			'methods'=>'POST', 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( ReservationService::record_deposit( absint( $r['id'] ), absint( $r['amount'] ), (string) $r['source'], (string) $r['reference'] ) ),
			'permission_callback'=>static fn()=>current_user_can( 'adc_record_payments' ),
			'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'amount'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'source'=>array( 'type'=>'string','enum'=>array( 'bank_transfer','cash','card','finance' ),'required'=>true ), 'reference'=>array( 'type'=>'string','minLength'=>1,'maxLength'=>100,'required'=>true ) ),
		) );
		self::route( '/reservation-deposits/(?P<id>\d+)/decision', array(
			'methods'=>'POST', 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( ReservationService::decide_deposit( absint( $r['id'] ), (bool) $r['approve'], (string) $r->get_param( 'reason' ) ) ),
			'permission_callback'=>static fn()=>current_user_can( 'adc_verify_payments' ),
			'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'approve'=>array( 'type'=>'boolean','required'=>true ), 'reason'=>array( 'type'=>'string','maxLength'=>2000 ) ),
		) );
		self::route( '/reservation-deposits', array(
			'methods'=>'GET', 'callback'=>static fn()=>rest_ensure_response( ReservationService::deposit_queue() ),
			'permission_callback'=>static fn()=>current_user_can( 'adc_view_finance' ) || current_user_can( 'adc_record_payments' ) || current_user_can( 'adc_verify_payments' ),
		) );
		self::route( '/quotations', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_quote' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'customer_id' => array( 'type' => 'integer', 'required' => true ), 'vehicle_id' => array( 'type' => 'integer', 'required' => true ), 'valid_until' => array( 'type' => 'string', 'required' => true, 'format' => 'date' ), 'promotion_code'=>array( 'type'=>'string','maxLength'=>64 ) ),
		) );
		self::route( '/quotations/(?P<id>\d+)/discounts', array(
			'methods' => 'POST', 'callback' => array( self::class, 'request_discount' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'amount' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'reason' => array( 'type' => 'string', 'required' => true ) ),
		) );
		self::route( '/quotations/(?P<id>\d+)/versions', array(
			'methods' => 'GET', 'callback' => array( self::class, 'quote_versions' ), 'permission_callback' => array( QuoteHistory::class, 'can_read' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ) ),
		) );
		self::route( '/discounts/(?P<id>\d+)/decision', array(
			'methods' => 'POST', 'callback' => array( self::class, 'decide_discount' ), 'permission_callback' => static fn() => current_user_can( 'adc_review_discounts' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'approve' => array( 'type' => 'boolean', 'required' => true ), 'reason' => array( 'type' => 'string' ) ),
		) );
		self::route( '/sales', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_sale' ), 'permission_callback' => static fn() => current_user_can( 'adc_create_reservations' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'quotation_id' => array( 'type' => 'integer', 'required' => true ), 'reservation_id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		self::route( '/sales/(?P<id>\d+)/approval', array(
			'methods' => 'POST', 'callback' => array( self::class, 'approve_sale' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_sales' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'invoice_reference' => array( 'type' => 'string', 'required' => true ) ),
		) );
		self::route( '/finance-requests', array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_finance_request' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_finance' ), 'args' => array( 'sale_id'=>array( 'type'=>'integer','required'=>true,'minimum'=>1 ), 'provider'=>array( 'type'=>'string','required'=>true,'minLength'=>1,'maxLength'=>100 ), 'amount'=>array( 'type'=>'integer','required'=>true,'minimum'=>1 ), 'down_payment'=>array( 'type'=>'integer','minimum'=>0,'default'=>0 ), 'term_months'=>array( 'type'=>'integer','minimum'=>0,'maximum'=>120,'default'=>0 ), 'monthly_payment'=>array( 'type'=>'integer','minimum'=>0,'default'=>0 ), 'consent'=>array( 'type'=>'boolean','required'=>true ) ),
		) );
		self::route( '/sales/(?P<id>\d+)/finance-requests', array(
			'methods'=>'GET', 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( SalesService::finance_history( absint( $r['id'] ) ) ), 'permission_callback'=>static fn()=>current_user_can( 'adc_view_finance' ) || current_user_can( 'adc_manage_finance' ), 'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ) ),
		) );
		self::route( '/finance-requests/(?P<id>\d+)/status', array(
			'methods' => 'POST', 'callback' => array( self::class, 'update_finance_status' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_finance' ), 'args' => array( 'id'=>array( 'type'=>'integer','required'=>true,'minimum'=>1 ), 'status'=>array( 'type'=>'string','required'=>true,'enum'=>array( 'under_review','approved','rejected' ) ), 'provider_reference'=>array( 'type'=>'string','maxLength'=>100 ), 'decision_reason'=>array( 'type'=>'string','maxLength'=>2000 ) ),
		) );
		self::route( '/sales/(?P<id>\d+)/delivery', array(
			'methods' => 'POST', 'callback' => array( self::class, 'prepare_delivery' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_delivery' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		self::route( '/deliveries/(?P<id>\d+)/vin', array(
			'methods' => 'POST', 'callback' => array( self::class, 'confirm_delivery_vin' ), 'permission_callback' => static fn() => current_user_can( 'adc_confirm_vehicle_vin' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'vin' => array( 'type' => 'string', 'required' => true ) ),
		) );
		self::route( '/deliveries/(?P<id>\d+)/documents', array(
			array( 'methods'=>'GET', 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( DeliveryService::checklist( absint( $r['id'] ) ) ), 'permission_callback'=>static fn()=>current_user_can( 'adc_approve_delivery' ) || current_user_can( 'adc_confirm_vehicle_vin' ), 'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ) ) ),
			array( 'methods'=>'POST', 'callback'=>static fn( \WP_REST_Request $r )=>rest_ensure_response( DeliveryService::record_document( absint( $r['id'] ), (string) $r['document_key'], (string) $r['reference'] ) ), 'permission_callback'=>static fn()=>current_user_can( 'adc_approve_delivery' ) || current_user_can( 'adc_confirm_vehicle_vin' ), 'args'=>array( 'id'=>array( 'type'=>'integer','minimum'=>1,'required'=>true ), 'document_key'=>array( 'type'=>'string','enum'=>array( 'invoice','customer_identity','vehicle_registration','insurance','handover_form','finance_clearance' ),'required'=>true ), 'reference'=>array( 'type'=>'string','minLength'=>1,'maxLength'=>190,'required'=>true ) ) ),
		) );
		self::route( '/deliveries/(?P<id>\d+)/approval', array(
			'methods' => 'POST', 'callback' => array( self::class, 'approve_delivery' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_delivery' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		self::route( '/deliveries/(?P<id>\d+)/release', array(
			'methods' => 'POST', 'callback' => array( self::class, 'release_delivery' ), 'permission_callback' => static fn() => current_user_can( 'adc_approve_delivery' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		self::route( '/leads', array(
			array( 'methods' => 'POST', 'callback' => array( self::class, 'create_public_lead' ), 'permission_callback' => array( self::class, 'public_intake_permission' ), 'args' => array(
				'name'=>array( 'type'=>'string', 'required'=>true ), 'mobile'=>array( 'type'=>'string', 'required'=>true ),
				'email'=>array( 'type'=>'string' ), 'city'=>array( 'type'=>'string' ), 'branch_id'=>array( 'type'=>'integer', 'minimum'=>0 ),
				'source'=>array( 'type'=>'string' ), 'consent_marketing'=>array( 'type'=>'boolean' ),
				'message'=>array( 'type'=>'string', 'maxLength'=>4000 ), 'request_kind'=>array( 'type'=>'string' ),
				'car_id'=>array( 'type'=>'integer', 'minimum'=>0 ), 'date'=>array( 'type'=>'string' ), 'time'=>array( 'type'=>'string' ),
				'website'=>array( 'type'=>'string' ), 'idempotency_key'=>array( 'type'=>'string', 'maxLength'=>36 ),
			) ),
			array( 'methods' => 'GET', 'callback' => array( self::class, 'list_leads' ), 'permission_callback' => static fn() => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'page' => array( 'type' => 'integer', 'minimum' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ) ) ),
		) );
		self::route( '/leads/(?P<id>\d+)/stage', array(
			'methods' => 'POST', 'callback' => array( self::class, 'update_lead_stage' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'stage' => array( 'type' => 'string', 'required' => true ), 'reason' => array( 'type' => 'string' ) ),
		) );
		self::route( '/leads/(?P<id>\d+)/request', array(
			array( 'methods'=>'GET', 'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( RequestWorkflow::read( (int) $r['id'] ) ),
				'permission_callback'=>static fn() => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ),
				'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ) ) ),
			array( 'methods'=>'PATCH', 'callback'=>static fn( \WP_REST_Request $r ) => rest_ensure_response( RequestWorkflow::update( (int) $r['id'], $r->get_params() ) ),
				'permission_callback'=>static fn() => current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ),
				'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ), 'revision'=>array( 'type'=>'string', 'required'=>true, 'pattern'=>'^[a-f0-9]{64}$' ), 'status'=>array( 'type'=>'string' ), 'customer_reply'=>array( 'type'=>'string', 'maxLength'=>4000 ), 'requested_date'=>array( 'type'=>'string' ), 'requested_time'=>array( 'type'=>'string' ) ) ),
		) );
		self::route( '/bookings/(?P<id>\d+)/cancel', array(
			'methods'=>'POST', 'permission_callback'=>'is_user_logged_in', 'args'=>array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ) ),
			'callback'=>static function ( \WP_REST_Request $r ) {
				$lead_id = RequestWorkflow::linked_lead( 'booking', (int) $r['id'] );
				if ( is_wp_error( $lead_id ) ) { return $lead_id; }
				if ( ! $lead_id ) { return new \WP_Error( 'adc_request_not_found', __( 'الطلب غير موجود أو خارج نطاق صلاحيتك.', 'auto-dealership-core' ), array( 'status'=>404 ) ); }
				return rest_ensure_response( RequestWorkflow::update( $lead_id, array(), true ) );
			},
		) );
		self::route( '/leads/(?P<id>\d+)/assignment', array(
			'methods' => 'POST', 'callback' => array( self::class, 'assign_lead' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'staff_id' => array( 'type' => 'integer', 'required' => true ) ),
		) );
		self::route( '/leads/(?P<id>\d+)/activities', array(
			'methods' => 'GET', 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( LeadService::activity_history( (int) $r['id'], (int) ( $r['page'] ?? 1 ), (int) ( $r['per_page'] ?? 50 ) ) ),
			'permission_callback' => static fn() => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ),
			'args' => array( 'id'=>array( 'type'=>'integer', 'minimum'=>1 ), 'page'=>array( 'type'=>'integer', 'minimum'=>1 ), 'per_page'=>array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>100 ) ),
		) );
		self::route( '/leads/(?P<id>\d+)/activities', array(
			'methods' => 'POST', 'callback' => array( self::class, 'add_activity' ), 'permission_callback' => static fn() => current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ), 'args' => array( 'id' => array( 'type' => 'integer', 'required' => true ), 'type' => array( 'type' => 'string', 'required' => true ), 'notes' => array( 'type' => 'string', 'required' => true ), 'next_action_at' => array( 'type' => 'string' ) ),
		) );
	}

	public static function catalog( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = PublicCatalog::normalize_filters( $request->get_params() );
		$page = $filters['page'];
		$per_page = $filters['per_page'];
		$total = PublicCatalog::catalog_total( $filters );
		return rest_ensure_response( array( 'items' => PublicCatalog::catalog( $filters ), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => (int) ceil( $total / $per_page ), 'filters' => $filters ) );
	}

	private static function catalog_args(): array {
		$text = array( 'type' => 'string', 'maxLength' => 120 );
		$positive = array( 'type' => 'integer', 'minimum' => 0 );
		$year = array( 'type' => 'integer', 'minimum' => 1900, 'maximum' => 2200 );
		return array(
			'page' => array( 'type' => 'integer', 'minimum' => 1 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 48 ),
			'brand' => $text, 'model' => $text, 'trim' => $text, 'body_type' => $text,
			'fuel_type' => $text, 'transmission' => $text, 'engine_size' => $text,
			'drivetrain' => $text, 'exterior_color' => $text, 'interior_color' => $text,
			'condition' => array( 'type' => 'string', 'enum' => array( 'new', 'used' ) ),
			'model_year' => $year, 'min_year' => $year, 'max_year' => $year,
			'min_price' => $positive, 'max_price' => $positive, 'min_mileage' => $positive,
			'max_mileage' => $positive, 'branch_id' => $positive,
			'search' => array( 'type' => 'string', 'maxLength' => 120 ),
			'sort' => array( 'type' => 'string', 'enum' => array( 'newest', 'price_asc', 'price_desc', 'year_desc', 'mileage_asc' ) ),
		);
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

	/** Intake consumes its policy after validation, immediately before persistence. */
	public static function public_intake_permission(): bool { return true; }

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
		return rest_ensure_response( SalesService::create_quote( absint( $request['customer_id'] ), absint( $request['vehicle_id'] ), (string) $request['valid_until'], (string) $request->get_param( 'promotion_code' ) ) );
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
		return rest_ensure_response( SalesService::create_finance_request( absint( $request['sale_id'] ), (string) $request['provider'], absint( $request['amount'] ), (bool) $request['consent'], array( 'down_payment'=>absint( $request['down_payment'] ), 'term_months'=>absint( $request['term_months'] ), 'monthly_payment'=>absint( $request['monthly_payment'] ) ) ) );
	}

	public static function update_finance_status( \WP_REST_Request $request ) {
		return rest_ensure_response( SalesService::update_finance_status( absint( $request['id'] ), (string) $request['status'], (string) $request->get_param( 'provider_reference' ), (string) $request->get_param( 'decision_reason' ) ) );
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
