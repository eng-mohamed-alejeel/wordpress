<?php
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
$admin = get_current_user_id();
$branch_b = AutoDealership\Branches\BranchService::create( array( 'code'=>'BROWSER', 'name'=>'Browser fixture branch' ) );
register_post_type( 'car', array( 'public'=>true ) );
$crm_car = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_title'=>'سيارة الاختبار' ) );
update_post_meta( $crm_car, '_car_inventory_status', 'available' );
update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
function adc_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; }
require __DIR__ . '/account-journey.php';
