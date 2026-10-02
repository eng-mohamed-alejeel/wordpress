<?php
/** Repeatable local-development fixtures. Never use for production or real customer records. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) {
	fwrite( STDERR, "Usage: php development-seed.php --inspect|--apply\n" );
	exit( 1 );
}

define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';

use AutoDealership\Branches\BranchService;
use AutoDealership\Content\PostMetaStore;
use AutoDealership\Database\Schema;
use AutoDealership\Delivery\DeliveryService;
use AutoDealership\Inventory\CatalogMappingService;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Inventory\VehicleAcquisitionService;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Leads\LeadService;
use AutoDealership\Purchasing\SupplierService;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Reference\ReferenceService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Sales\SalesService;

global $wpdb;
if ( 'wp-autobrands' !== DB_NAME || 'wp_' !== $wpdb->prefix || 'http://localhost/wordpress' !== untrailingslashit( (string) get_option( 'siteurl' ) ) || ! in_array( 'auto-dealership-core/auto-dealership-core.php', (array) get_option( 'active_plugins', array() ), true ) || ! Schema::is_ready() || array() !== Schema::verify() ) {
	fwrite( STDERR, "Refusing to seed: this must be the verified local wp-autobrands development site.\n" );
	exit( 2 );
}

$table_keys = array( 'branches','brands','locations','suppliers','vehicles','customers','leads','activities','quotations','reservations','sales','finance_requests','payment_confirmations','deliveries','audit_events' );
$snapshot = static function () use ( $wpdb, $table_keys ): array {
	$counts = array();
	foreach ( $table_keys as $key ) { $counts[$key] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $key ) ); }
	$counts['car_posts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='car' AND post_status='publish'" );
	$counts['offer_posts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='car_offer' AND post_status='publish'" );
	return $counts;
};

if ( '--inspect' === $argv[1] ) {
	echo wp_json_encode( array( 'database'=>DB_NAME, 'schema'=>Schema::VERSION, 'seed_version'=>get_option( 'adc_demo_seed_version', '' ), 'counts'=>$snapshot() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	exit;
}

$baseline = $snapshot();
$admins = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) );
if ( ! $admins ) { fwrite( STDERR, "No administrator is available.\n" ); exit( 4 ); }
if ( ! get_option( 'adc_demo_seed_version' ) ) {
	foreach ( array( 'branches','brands','locations','suppliers','vehicles','customers','leads','quotations','reservations','sales','finance_requests','payment_confirmations','deliveries' ) as $key ) {
		if ( $baseline[$key] > 0 ) { fwrite( STDERR, "Refusing first seed: business table $key is not empty.\n" ); exit( 3 ); }
	}
	update_option( 'adc_demo_seed_version', '2026-10-02-in-progress', false );
}
wp_set_current_user( (int) $admins[0] );

$require_result = static function ( $result, string $label ): array {
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $label . ': ' . $result->get_error_code() ); }
	if ( ! is_array( $result ) ) { throw new RuntimeException( $label . ': unexpected result' ); }
	return $result;
};
$lookup = static function ( string $table, string $field, string $value ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( $table ) . " WHERE $field=%s LIMIT 1", $value ) );
};

try {
	$branches = array();
	foreach ( array(
		'RYD' => array( 'name'=>'فرع الرياض التجريبي', 'city'=>'الرياض', 'address'=>'موقع تجريبي — لا يمثل عنوان معرض فعليًا' ),
		'JED' => array( 'name'=>'فرع جدة التجريبي', 'city'=>'جدة', 'address'=>'موقع تجريبي — لا يمثل عنوان معرض فعليًا' ),
		'DMM' => array( 'name'=>'فرع الدمام التجريبي', 'city'=>'الدمام', 'address'=>'موقع تجريبي — لا يمثل عنوان معرض فعليًا' ),
	) as $code=>$values ) {
		$key = 'DEMO-' . $code;
		$branches[$code] = $lookup( 'branches', 'code', $key );
		if ( ! $branches[$code] ) { $branches[$code] = (int) $require_result( BranchService::create( array_merge( array( 'code'=>$key ), $values ) ), 'branch ' . $key )['id']; }
	}

	$brands = array();
	foreach ( array( 'toyota'=>array( 'تويوتا','Toyota' ), 'hyundai'=>array( 'هيونداي','Hyundai' ), 'kia'=>array( 'كيا','Kia' ), 'nissan'=>array( 'نيسان','Nissan' ), 'mazda'=>array( 'مازدا','Mazda' ) ) as $key=>$names ) {
		$brand_key = 'demo-' . $key;
		$brands[$key] = $lookup( 'brands', 'brand_key', $brand_key );
		if ( ! $brands[$key] ) { $brands[$key] = (int) $require_result( ReferenceService::create_brand( array( 'key'=>$brand_key, 'name_ar'=>$names[0], 'name_en'=>$names[1] ) ), 'brand ' . $key )['id']; }
	}
	$brand_terms = array();
	foreach ( array( 'toyota'=>'Toyota', 'hyundai'=>'Hyundai', 'kia'=>'Kia', 'nissan'=>'Nissan', 'mazda'=>'Mazda' ) as $key=>$name ) {
		$slug = 'demo-' . $key;
		$term = get_term_by( 'slug', $slug, 'car_brand' );
		if ( ! $term ) {
			$created = wp_insert_term( '[تجريبي] ' . $name, 'car_brand', array( 'slug'=>$slug ) );
			if ( is_wp_error( $created ) ) { throw new RuntimeException( 'Brand taxonomy failed: ' . $created->get_error_code() ); }
			$brand_terms[$key] = (int) $created['term_id'];
		} else { $brand_terms[$key] = (int) $term->term_id; }
	}
	$category_terms = array();
	foreach ( array( 'sedan'=>'سيدان', 'suv'=>'دفع رباعي', 'pickup'=>'بيك أب' ) as $key=>$name ) {
		$slug = 'demo-' . $key;
		$term = get_term_by( 'slug', $slug, 'car_category' );
		if ( ! $term ) {
			$created = wp_insert_term( '[تجريبي] ' . $name, 'car_category', array( 'slug'=>$slug ) );
			if ( is_wp_error( $created ) ) { throw new RuntimeException( 'Category taxonomy failed: ' . $created->get_error_code() ); }
			$category_terms[$key] = (int) $created['term_id'];
		} else { $category_terms[$key] = (int) $term->term_id; }
	}

	$locations = array();
	foreach ( $branches as $code=>$branch_id ) {
		$key = 'DEMO-' . $code . '-SHOWROOM';
		$locations[$code] = $lookup( 'locations', 'code', $key );
		if ( ! $locations[$code] ) { $locations[$code] = (int) $require_result( ReferenceService::create_location( array( 'branch_id'=>$branch_id, 'code'=>$key, 'name'=>'صالة العرض التجريبية ' . $code, 'type'=>'showroom' ) ), 'location ' . $key )['id']; }
	}

	$staff = array();
	foreach ( array(
		'sales_ryd'=>array( 'dealership_sales','RYD','مبيعات الرياض' ),
		'sales_jed'=>array( 'dealership_sales','JED','مبيعات جدة' ),
		'sales_dmm'=>array( 'dealership_sales','DMM','مبيعات الدمام' ),
		'manager_ryd'=>array( 'dealership_sales_manager','RYD','مدير مبيعات الرياض' ),
		'finance_ryd_a'=>array( 'dealership_finance','RYD','تمويل الرياض 1' ),
		'finance_ryd_b'=>array( 'dealership_finance','RYD','تمويل الرياض 2' ),
		'purchasing_ryd'=>array( 'dealership_purchasing','RYD','مشتريات الرياض' ),
		'inventory_jed'=>array( 'dealership_inventory','JED','مخزون جدة' ),
	) as $key=>$spec ) {
		$login = 'adc_demo_' . $key;
		$user = get_user_by( 'login', $login );
		if ( $user && '2026-10-02' !== get_user_meta( $user->ID, 'adc_demo_seed', true ) ) { throw new RuntimeException( 'Staff login collision: ' . $login ); }
		if ( ! $user ) {
			$id = wp_insert_user( array( 'user_login'=>$login, 'user_pass'=>wp_generate_password( 40, true, true ), 'user_email'=>$login . '@example.invalid', 'display_name'=>'[تجريبي] ' . $spec[2], 'role'=>$spec[0] ) );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Staff creation failed: ' . $id->get_error_code() ); }
			update_user_meta( (int) $id, 'adc_demo_seed', '2026-10-02' );
			update_user_meta( (int) $id, 'adc_branch_id', $branches[$spec[1]] );
			$staff[$key] = (int) $id;
		} else { $staff[$key] = (int) $user->ID; }
	}

	$suppliers = array();
	foreach ( array(
		'LOCAL'=>array( 'مورد مركبات محلي تجريبي','SA' ),
		'IMPORT'=>array( 'مورد استيراد تجريبي','AE' ),
		'TRADE'=>array( 'مورد مبادلات تجريبي','SA' ),
	) as $key=>$values ) {
		$code = 'DEMO-' . $key;
		$suppliers[$key] = $lookup( 'suppliers', 'supplier_code', $code );
		if ( ! $suppliers[$key] ) { $suppliers[$key] = (int) $require_result( SupplierService::create( array( 'supplier_code'=>$code, 'display_name'=>$values[0], 'legal_name'=>$values[0], 'country'=>$values[1], 'contact_email'=>'supplier-' . strtolower( $key ) . '@example.invalid', 'notes'=>'بيانات تطوير اصطناعية؛ لا يوجد عقد أو اعتماد مزود.' ) ), 'supplier ' . $key )['id']; }
	}

	// Real model names and plausible SAR prices; stock/VIN and commercial facts remain unmistakably synthetic.
	$vehicle_specs = array(
		array( '01','toyota','Camry','LE',2025,'RYD','sedan','hybrid',14000,129000,'silver','LOCAL','available' ),
		array( '02','toyota','Corolla','XLI',2024,'RYD','sedan','petrol',32000,95000,'white','TRADE','available' ),
		array( '03','toyota','RAV4','XLE',2025,'RYD','suv','hybrid',8000,151000,'black','IMPORT','available' ),
		array( '04','hyundai','Tucson','Smart',2025,'RYD','suv','petrol',6500,119000,'grey','LOCAL','available' ),
		array( '05','hyundai','Elantra','Comfort',2024,'JED','sedan','petrol',25000,91000,'blue','TRADE','available' ),
		array( '06','kia','Sportage','LX',2025,'JED','suv','petrol',3000,128000,'white','IMPORT','available' ),
		array( '07','kia','K5','EX',2024,'JED','sedan','petrol',19000,111000,'black','LOCAL','available' ),
		array( '08','nissan','X-Trail','SV',2025,'JED','suv','petrol',1000,139000,'silver','IMPORT','available' ),
		array( '09','nissan','Altima','S',2023,'DMM','sedan','petrol',41000,82000,'white','TRADE','available' ),
		array( '10','mazda','CX-5','Core',2025,'DMM','suv','petrol',10000,133000,'red','LOCAL','available' ),
		array( '11','mazda','Mazda 6','Core',2024,'DMM','sedan','petrol',28000,103000,'grey','TRADE','maintenance' ),
		array( '12','toyota','Hilux','GLX',2025,'DMM','pickup','diesel',0,158000,'white','IMPORT','inspection' ),
	);
	$vehicles = array(); $car_posts = array();
	foreach ( $vehicle_specs as $spec ) {
		list( $number,$brand,$model,$trim,$year,$branch,$body,$fuel,$mileage,$price_sar,$color,$supplier,$target ) = $spec;
		$stock = 'DEMO-2026-' . $number;
		$id = $lookup( 'vehicles', 'stock_number', $stock );
		if ( ! $id ) {
			$origin = in_array( $brand, array( 'hyundai','kia' ), true ) ? 'South Korea' : 'Japan';
			$result = $require_result( VehicleService::create( array( 'vin'=>'TESTCAR0000' . str_pad( $number, 6, '0', STR_PAD_LEFT ), 'stock_number'=>$stock, 'brand'=>ucfirst( $brand ), 'brand_id'=>$brands[$brand], 'model'=>$model, 'trim'=>$trim, 'model_year'=>$year, 'condition'=>$mileage ? 'used' : 'new', 'branch_id'=>$branches[$branch], 'location_id'=>$locations[$branch], 'body_type'=>$body, 'fuel_type'=>$fuel, 'transmission'=>'automatic', 'mileage'=>$mileage, 'retail_price'=>$price_sar * 100, 'minimum_price'=>( $price_sar - 7000 ) * 100, 'exterior_color'=>$color, 'interior_color'=>'black', 'origin_country'=>$origin, 'doors'=>4, 'seats'=>5, 'cylinders'=>4, 'drivetrain'=>'fwd', 'warranty'=>'وصف تجريبي؛ شروط الضمان الفعلية يحددها المعرض.' ) ), 'vehicle ' . $stock );
			$id = (int) $result['id'];
		}
		$vehicles[$number] = $id;
		$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d', $id ) );
		if ( 'received' === $status ) {
			$cost = (int) round( $price_sar * 0.76 ) * 100;
			$extra = 125000;
			$require_result( VehicleAcquisitionService::update( $id, array( 'supplier_id'=>$suppliers[$supplier], 'purchase_cost'=>$cost, 'additional_cost'=>$extra, 'total_cost'=>$cost + $extra, 'wholesale_price'=>(int) round( $price_sar * 0.84 ) * 100, 'arrival_date'=>'2026-09-20', 'customs_reference'=>'DEMO-CUSTOMS-' . $number, 'internal_notes'=>'تكاليف افتراضية لأغراض التطوير فقط.' ), 'Synthetic acquisition fixture' ), 'acquisition ' . $stock );
			$require_result( VehicleIntakeService::receive( $id, array( 'condition'=>'good', 'document_reference'=>'DEMO-RECEIPT-' . $number, 'odometer'=>$mileage ) ), 'receipt ' . $stock );
			$require_result( VehicleService::transition( $id, 'inspection', 'Development inspection fixture' ), 'inspection transition ' . $stock );
			if ( 'inspection' !== $target ) {
				$checklist = array_fill_keys( array( 'exterior','interior','engine','tires','vin' ), 'pass' );
				if ( 'maintenance' === $target ) { $checklist['tires'] = 'fail'; }
				$require_result( VehicleIntakeService::inspect( $id, array( 'checklist'=>$checklist, 'notes'=>'maintenance' === $target ? 'فحص إطارات تجريبي؛ يلزم إصلاح قبل العرض.' : '' ) ), 'checklist ' . $stock );
				if ( 'available' === $target ) { $require_result( VehicleService::transition( $id, 'available', 'Passed development inspection' ), 'availability ' . $stock ); }
			}
		}
		$slug = 'adc-demo-' . $number;
		$post = get_page_by_path( $slug, OBJECT, 'car' );
		if ( $post && '2026-10-02' !== get_post_meta( $post->ID, '_adc_demo_seed', true ) ) { throw new RuntimeException( 'Car post slug collision: ' . $slug ); }
		if ( ! $post ) {
			$post_id = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_name'=>$slug, 'post_title'=>'[تجريبي] ' . ucfirst( $brand ) . ' ' . $model . ' ' . $year, 'post_content'=>'<p>سيارة معروضة لأغراض تطوير النظام فقط. المواصفات والسعر ورقم المخزون بيانات تجريبية وليست عرض بيع فعليًا.</p>', 'meta_input'=>array( '_adc_demo_seed'=>'2026-10-02' ) ), true );
			if ( is_wp_error( $post_id ) ) { throw new RuntimeException( 'Car post failed: ' . $post_id->get_error_code() ); }
		} else { $post_id = (int) $post->ID; }
		$car_posts[$number] = (int) $post_id;
		$assigned_brand = wp_set_object_terms( (int) $post_id, array( $brand_terms[$brand] ), 'car_brand', false );
		$assigned_category = wp_set_object_terms( (int) $post_id, array( $category_terms[$body] ), 'car_category', false );
		if ( is_wp_error( $assigned_brand ) || is_wp_error( $assigned_category ) ) { throw new RuntimeException( 'Car taxonomy assignment failed: ' . $stock ); }
		$meta = array( '_car_make'=>ucfirst( $brand ), '_car_model'=>$model, '_car_year'=>(string) $year, '_car_price'=>(string) $price_sar, '_car_mileage'=>(string) $mileage, '_car_condition'=>$mileage ? 'used' : 'new', '_car_inventory_status'=>'available' === $target ? 'available' : $target );
		$changes = array();
		foreach ( $meta as $key=>$value ) { $changes[] = array( 'post_id'=>(int) $post_id, 'key'=>$key, 'value'=>$value ); }
		$require_result( PostMetaStore::apply( $changes, 'content.demo_car_meta_saved', 'car_post', (int) $post_id ), 'car meta ' . $stock );
		$require_result( CatalogMappingService::assign( $id, (int) $post_id, 'Development catalog mapping' ), 'mapping ' . $stock );
	}

	foreach ( array( array( '02',90000,95000 ), array( '08',134000,139000 ) ) as $offer ) {
		list( $number,$new_price,$old_price ) = $offer;
		$slug = 'adc-demo-offer-' . $number;
		$post = get_page_by_path( $slug, OBJECT, 'car_offer' );
		if ( $post && '2026-10-02' !== get_post_meta( $post->ID, '_adc_demo_seed', true ) ) { throw new RuntimeException( 'Offer post slug collision: ' . $slug ); }
		if ( ! $post ) {
			$post_id = wp_insert_post( array( 'post_type'=>'car_offer', 'post_status'=>'publish', 'post_name'=>$slug, 'post_title'=>'[عرض تجريبي] ' . get_the_title( $car_posts[$number] ), 'post_content'=>'<p>عرض تجريبي لتطوير الموقع فقط؛ غير صالح للبيع أو الحجز بسعر فعلي.</p>', 'meta_input'=>array( '_adc_demo_seed'=>'2026-10-02' ) ), true );
			if ( is_wp_error( $post_id ) ) { throw new RuntimeException( 'Offer post failed: ' . $post_id->get_error_code() ); }
		} else { $post_id = (int) $post->ID; }
		$require_result( PostMetaStore::apply( array( array( 'post_id'=>(int) $post_id, 'key'=>'_offer_car_id', 'value'=>(string) $car_posts[$number] ), array( 'post_id'=>(int) $post_id, 'key'=>'_offer_new_price', 'value'=>(string) $new_price ), array( 'post_id'=>(int) $post_id, 'key'=>'_offer_old_price', 'value'=>(string) $old_price ), array( 'post_id'=>(int) $post_id, 'key'=>'_offer_expires', 'value'=>gmdate( 'Y-m-d', time() + 45 * DAY_IN_SECONDS ) ) ), 'content.demo_offer_saved', 'offer_post', (int) $post_id ), 'offer meta ' . $slug );
	}

	$leads = array(); $customers = array();
	foreach ( array(
		array( '01','RYD','sales_ryd','الرياض','contacted' ), array( '02','RYD','sales_ryd','الرياض','qualified' ),
		array( '03','JED','sales_jed','جدة','contacted' ), array( '04','JED','sales_jed','جدة','new' ),
		array( '05','DMM','sales_dmm','الدمام','qualified' ), array( '06','DMM','sales_dmm','الدمام','new' ),
	) as $lead_spec ) {
		list( $number,$branch,$owner,$city,$target_stage ) = $lead_spec;
		$email = 'customer' . $number . '@example.invalid';
		$customer_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'customers' ) . ' WHERE email=%s LIMIT 1', $email ) );
		if ( $customer_id ) {
			$lead_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . ' WHERE customer_id=%d LIMIT 1', $customer_id ) );
			if ( ! $lead_id ) { throw new RuntimeException( 'Existing demonstration customer lacks lead ' . $number ); }
		} else {
			$result = $require_result( LeadService::create_public( array( 'name'=>'عميل تجريبي ' . $number, 'mobile'=>'+9665000001' . $number, 'email'=>$email, 'city'=>$city, 'branch_id'=>$branches[$branch], 'consent_marketing'=>false ), null, 'استفسار تطوير تجريبي؛ لا توجد موافقة تسويقية أو بيانات عميل حقيقي.' ), 'lead ' . $number );
			$lead_id = (int) $result['id'];
			$customer_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $lead_id ) );
		}
		$leads[$number] = $lead_id; $customers[$number] = $customer_id;
		$lead_owner = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_user_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $lead_id ) );
		if ( ! $lead_owner ) { $require_result( LeadService::assign( $lead_id, $staff[$owner] ), 'lead owner ' . $number ); }
		$current_stage = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $lead_id ) );
		if ( 'new' === $current_stage && 'new' !== $target_stage ) { $require_result( LeadService::update_stage( $lead_id, 'contacted', 'Development follow-up' ), 'lead contacted ' . $number ); $current_stage = 'contacted'; }
		if ( 'contacted' === $current_stage && 'qualified' === $target_stage ) { $require_result( LeadService::update_stage( $lead_id, 'qualified', 'Development qualification' ), 'lead qualified ' . $number ); }
	}

	$valid_until = gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS );
	$quote_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'quotations' ) . ' WHERE customer_id=%d AND vehicle_id=%d LIMIT 1', $customers['01'], $vehicles['01'] ) );
	if ( ! $quote_id ) { wp_set_current_user( $staff['sales_ryd'] ); $quote_id = (int) $require_result( SalesService::create_quote( $customers['01'], $vehicles['01'], $valid_until ), 'quote 01' )['id']; }
	$reservation_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'reservations' ) . ' WHERE customer_id=%d AND vehicle_id=%d LIMIT 1', $customers['01'], $vehicles['01'] ) );
	if ( ! $reservation_id ) { wp_set_current_user( $staff['sales_ryd'] ); $reservation_id = (int) $require_result( ReservationService::create( array( 'vehicle_id'=>$vehicles['01'], 'customer_id'=>$customers['01'], 'idempotency_key'=>wp_generate_uuid4() ) ), 'reservation 01' )['id']; }
	$sale_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sales' ) . ' WHERE quotation_id=%d LIMIT 1', $quote_id ) );
	if ( ! $sale_id ) { wp_set_current_user( $staff['sales_ryd'] ); $sale_id = (int) $require_result( SalesService::create_sale( $quote_id, $reservation_id ), 'sale 01' )['id']; }
	$finance_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d', $sale_id ) );
	if ( 0 === $finance_count ) {
		$final_amount = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT final_amount FROM ' . Schema::table( 'quotations' ) . ' WHERE id=%d', $quote_id ) );
		wp_set_current_user( $staff['finance_ryd_a'] );
		$first = $require_result( SalesService::create_finance_request( $sale_id, 'مزود تمويل تجريبي أ', $final_amount - 1500000, true, array( 'down_payment'=>1500000, 'term_months'=>48, 'monthly_payment'=>250000 ) ), 'finance attempt 1' );
		wp_set_current_user( $staff['finance_ryd_b'] );
		$require_result( SalesService::update_finance_status( (int) $first['id'], 'rejected', '', 'قرار تجريبي لأغراض اختبار تتابع المحاولات.' ), 'finance rejection' );
		wp_set_current_user( $staff['finance_ryd_a'] );
		$require_result( SalesService::create_finance_request( $sale_id, 'مزود تمويل تجريبي ب', $final_amount - 1500000, true, array( 'down_payment'=>1500000, 'term_months'=>48, 'monthly_payment'=>250000 ) ), 'finance attempt 2' );
	}

	// Second fictional journey reaches delivery preparation without claiming physical VIN verification or handover.
	$settled_quote_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'quotations' ) . ' WHERE customer_id=%d AND vehicle_id=%d LIMIT 1', $customers['02'], $vehicles['03'] ) );
	if ( ! $settled_quote_id ) { wp_set_current_user( $staff['sales_ryd'] ); $settled_quote_id = (int) $require_result( SalesService::create_quote( $customers['02'], $vehicles['03'], $valid_until ), 'quote 02' )['id']; }
	$settled_reservation_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'reservations' ) . ' WHERE customer_id=%d AND vehicle_id=%d LIMIT 1', $customers['02'], $vehicles['03'] ) );
	if ( ! $settled_reservation_id ) { wp_set_current_user( $staff['sales_ryd'] ); $settled_reservation_id = (int) $require_result( ReservationService::create( array( 'vehicle_id'=>$vehicles['03'], 'customer_id'=>$customers['02'], 'idempotency_key'=>wp_generate_uuid4() ) ), 'reservation 02' )['id']; }
	$settled_sale_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sales' ) . ' WHERE quotation_id=%d LIMIT 1', $settled_quote_id ) );
	if ( ! $settled_sale_id ) { wp_set_current_user( $staff['sales_ryd'] ); $settled_sale_id = (int) $require_result( SalesService::create_sale( $settled_quote_id, $settled_reservation_id ), 'sale 02' )['id']; }
	$settled_sale_status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'sales' ) . ' WHERE id=%d', $settled_sale_id ) );
	if ( 'pending_approval' === $settled_sale_status ) { wp_set_current_user( $staff['manager_ryd'] ); $require_result( SalesService::approve_sale( $settled_sale_id, 'DEMO-INVOICE-002' ), 'sale approval 02' ); }
	$payment_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'payment_confirmations' ) . ' WHERE source=%s AND reference=%s LIMIT 1', 'bank_transfer', 'DEMO-NO-FUNDS-002' ) );
	if ( ! $payment_id ) {
		$settled_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT final_amount FROM ' . Schema::table( 'quotations' ) . ' WHERE id=%d', $settled_quote_id ) );
		wp_set_current_user( $staff['finance_ryd_a'] );
		$payment_id = (int) $require_result( PaymentService::record( $settled_sale_id, $settled_total, 'bank_transfer', 'DEMO-NO-FUNDS-002' ), 'simulated payment 02' )['id'];
	}
	$payment_status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'payment_confirmations' ) . ' WHERE id=%d', $payment_id ) );
	if ( 'pending' === $payment_status ) { wp_set_current_user( $staff['finance_ryd_b'] ); $require_result( PaymentService::decide( $payment_id, true, 'DEMO ONLY: simulated verification; no funds were received.' ), 'simulated payment decision 02' ); }
	$delivery_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'deliveries' ) . ' WHERE sale_id=%d LIMIT 1', $settled_sale_id ) );
	if ( ! $delivery_id ) { wp_set_current_user( $staff['manager_ryd'] ); $delivery_id = (int) $require_result( DeliveryService::prepare( $settled_sale_id ), 'delivery preparation 02' )['id']; }
	wp_set_current_user( (int) $admins[0] );
	update_option( 'adc_demo_seed_version', '2026-10-02-complete', false );
	$public = PublicCatalog::catalog( array( 'per_page'=>48 ) );
	echo wp_json_encode( array( 'database'=>DB_NAME, 'seed_version'=>get_option( 'adc_demo_seed_version' ), 'before'=>$baseline, 'after'=>$snapshot(), 'public_catalog_count'=>count( $public ), 'catalog_mode'=>PublicCatalog::mode(), 'demo_sale_id'=>$sale_id, 'demo_finance_attempts'=>(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d', $sale_id ) ), 'demo_preparing_delivery_id'=>$delivery_id ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Development seed stopped: ' . $error->getMessage() . "\nRerun --apply after fixing the cause; natural keys skip already created demo records.\n" );
	exit( 5 );
}
