<?php
/** Populated paging/search fixtures, only in the guarded disposable runner. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Database\Schema;
use AutoDealership\Admin\QueueTable;
use AutoDealership\Admin\WorkflowPages;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleIssueService;
use AutoDealership\Branches\BranchService;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Security\BranchScope;
global $wpdb;
$checks = 0;
$check = static function( bool $ok, string $message ) use (&$checks): void { if(!$ok)throw new RuntimeException($message); ++$checks; echo "PASS $message\n"; };
$insert = static function( string $table, array $values ) use ($wpdb): int { if(1!==$wpdb->insert(Schema::table($table),$values))throw new RuntimeException('Fixture insert failed: '.$table); return (int)$wpdb->insert_id; };
$admin = get_current_user_id(); $now = '2026-10-07 12:00:00';
$a = BranchService::create(array('code'=>'QUEUE-A','name'=>'Queue branch A'));
$b = BranchService::create(array('code'=>'QUEUE-B','name'=>'Queue branch B'));
$check(is_array($a)&&is_array($b),'Create two isolated branches');
$inventory = wp_insert_user(array('user_login'=>'queue_inventory','user_pass'=>wp_generate_password(),'role'=>'dealership_inventory'));
$finance = wp_insert_user(array('user_login'=>'queue_finance','user_pass'=>wp_generate_password(),'role'=>'dealership_finance'));
$manager = wp_insert_user(array('user_login'=>'queue_manager','user_pass'=>wp_generate_password(),'role'=>'dealership_sales_manager'));
foreach(array($inventory,$finance,$manager) as $id) { $check(!is_wp_error($id)&&!is_wp_error(ConfigurationService::assign_branches($id,$a['id'],array($a['id']))),'Assign scoped real staff user'); }
for($i=1;$i<=122;++$i) {
 $branch=$i<=121?$a['id']:$b['id']; $name=$i<=121?'Queue customer':'Other branch secret';
 $vehicle=$insert('vehicles',array('vin'=>'1M8GDM9AX'.str_pad((string)$i,8,'0',STR_PAD_LEFT),'stock_number'=>'QUEUE-'.$i,'brand'=>'Fixture','model'=>'QueueModel','model_year'=>2026,'condition_key'=>'new','branch_id'=>$branch,'status'=>'hold','gallery_media_ids'=>'','document_media_ids'=>'','internal_notes'=>'','created_at'=>$now,'updated_at'=>$now));
 $customer=$insert('customers',array('full_name'=>$name,'created_at'=>$now,'updated_at'=>$now));
 $quote=$insert('quotations',array('quote_number'=>'QUEUE-Q-'.$i,'customer_id'=>$customer,'vehicle_id'=>$vehicle,'branch_id'=>$branch,'owner_user_id'=>$manager,'base_amount'=>100000,'final_amount'=>100000,'valid_until'=>'2026-12-01','seller_address'=>'','created_at'=>$now));
 $sale=$insert('sales',array('quotation_id'=>$quote,'reservation_id'=>0,'customer_id'=>$customer,'vehicle_id'=>$vehicle,'status'=>'pending_approval','created_at'=>$now,'updated_at'=>$now));
 $insert('finance_requests',array('sale_id'=>$sale,'provider'=>$i===121?'Needle 100%_ provider':'Queue provider','requested_amount'=>100000,'decision_reason'=>'','created_at'=>$now,'updated_at'=>$now));
 $insert('discount_requests',array('quotation_id'=>$quote,'requester_user_id'=>$manager,'requested_amount'=>100,'reason'=>'Queue discount','created_at'=>$now));
 $insert('vehicle_issues',array('vehicle_id'=>$vehicle,'issue_type'=>'hold','reason'=>$i===121?'Needle 100%_ reason':'Queue hold','resolution'=>'','created_at'=>$now));
}
$tables=array(); foreach(array('finance_requests','sales','customers','vehicles') as $name){$tables[$name]=Schema::table($name);}
$readFinance=static function()use($tables){ list($scope,$args)=BranchScope::predicate('v.branch_id'); return QueueTable::fetch("SELECT f.id,c.full_name FROM {$tables['finance_requests']} f INNER JOIN {$tables['sales']} s ON s.id=f.sale_id INNER JOIN {$tables['customers']} c ON c.id=s.customer_id INNER JOIN {$tables['vehicles']} v ON v.id=s.vehicle_id WHERE $scope ORDER BY f.created_at DESC,f.id DESC LIMIT 100",$args,array('c.full_name','f.provider','v.vin')); };
wp_set_current_user($finance); $seen=array();
foreach(array(1=>50,2=>50,3=>21)as$page=>$count){$_GET=array('queue_page'=>$page);$r=$readFinance();$check($r['total']===121&&count($r['rows'])===$count,"Finance page $page has expected rows and scoped total");$seen=array_merge($seen,array_column($r['rows'],'id'));}
$check(count(array_unique($seen))===121,'Stable paging covers every record once with tied timestamps');
$_GET=array('search'=>'100%_');$r=$readFinance();$check($r['total']===1,'Search treats percent and underscore literally');
$_GET=array('search'=>"' OR 1=1 --");$check($readFinance()['total']===0,'SQL-like search cannot widen the queue');
$_GET=array('search'=>'Other branch');$check($readFinance()['total']===0,'Search does not expose another branch');
$_GET=array('search'=>'1M8GDM9AX');$check($readFinance()['total']===0,'Finance user without inventory permission cannot search private VIN');
$_GET=array('queue_page'=>9999);$check($readFinance()['page']===3,'Out-of-range page clamps to the last valid page');
wp_set_current_user($inventory);
foreach(array(1=>50,2=>50,3=>21)as$page=>$count){$r=VehicleIssueService::open_page('hold',$page);$check($r['total']===121&&count($r['rows'])===$count,"Hold page $page uses the correct branch scope");}
$check(VehicleIssueService::open_page('hold',1,'100%_')['total']===1,'Issue search finds a record beyond the old first page');
$check(VehicleIssueService::open_page('maintenance')['total']===0,'Issue type filter keeps holds out of maintenance');
$check(VehicleIssueService::open_page('hold',1,'Other branch')['total']===0,'Issue search remains scoped');
require_once ABSPATH.'wp-admin/includes/template.php';
wp_set_current_user($manager);
foreach(array('discounts','sales')as$section){$_GET=array('page'=>'adc-approvals','section'=>$section,'queue_page'=>3);ob_start();WorkflowPages::approvals();$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);$check($xpath->query('//table/tbody/tr')->length===21&&!str_contains($html,'Other branch secret'),"Actual $section renderer reaches page three without cross-branch data");}
wp_set_current_user($finance);$_GET=array('page'=>'adc-finance','search'=>'100%_');ob_start();WorkflowPages::finance();$html=ob_get_clean();$check(str_contains($html,'Needle 100%_ provider')&&!str_contains($html,'Other branch secret'),'Actual finance renderer applies search');
$check(''===$wpdb->last_error,'Populated queries and renderers finish without a database error');
wp_set_current_user($admin);
require __DIR__.'/dashboard-actions-http.php';
echo "Completed $checks isolated dashboard queue checks.\n";
