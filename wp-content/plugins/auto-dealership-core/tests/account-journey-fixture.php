<?php
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
$admin = get_current_user_id();
$branch_b = AutoDealership\Branches\BranchService::create( array( 'code'=>'BROWSER', 'name'=>'Browser fixture branch' ) );
register_post_type( 'car', array( 'public'=>true ) );
$crm_car = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_title'=>'سيارة الاختبار' ) );
update_post_meta( $crm_car, '_car_inventory_status', 'available' );
$catalog_post_id = $crm_car;
$journey_vehicle = AutoDealership\Inventory\VehicleService::create( array( 'vin'=>'TESTBRW0000000001', 'stock_number'=>'HTTP-201', 'brand'=>'Synthetic', 'model'=>'Catalog HTTP', 'model_year'=>2026, 'condition'=>'new', 'branch_id'=>$branch_b['id'], 'retail_price'=>12000000, 'mileage'=>240, 'body_type'=>'suv', 'fuel_type'=>'hybrid', 'transmission'=>'automatic', 'engine_size'=>'2.0 L', 'drivetrain'=>'awd', 'exterior_color'=>'Silver', 'interior_color'=>'Black', 'seats'=>5 ) );
if ( is_wp_error( $journey_vehicle ) ) { throw new RuntimeException( 'Journey vehicle fixture failed: ' . $journey_vehicle->get_error_code() ); }
$wpdb->update( AutoDealership\Database\Schema::table( 'vehicles' ), array( 'public_post_id'=>$catalog_post_id, 'status'=>'available' ), array( 'id'=>$journey_vehicle['id'] ) );
$public_catalog_price = AutoDealership\Pricing\PublicVehiclePrice::calculate( 12000000, $catalog_post_id )['final_amount'];
update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
function adc_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; }
require __DIR__ . '/account-journey.php';
