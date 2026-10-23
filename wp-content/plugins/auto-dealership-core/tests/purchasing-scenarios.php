<?php
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Database\Schema;
use AutoDealership\Branches\BranchService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Purchasing\SupplierService;
use AutoDealership\Purchasing\PurchaseOrderService as PO;
use AutoDealership\Pricing\DocumentArchive;
use AutoDealership\Pricing\QuoteHistory;
use AutoDealership\Sales\SalesService;
global $wpdb; $admin=(int)get_user_by('login','verify_admin')->ID; wp_set_current_user($admin); $checks=0;
if(!Schema::is_ready()){throw new RuntimeException(wp_json_encode(Schema::verify()));}
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok){throw new RuntimeException($message);}++$checks;echo "PASS $message\n";};
$branch=BranchService::create(array('name'=>'Purchase test','code'=>'PO-TEST'));
$cost_manager=wp_insert_user(array('user_login'=>'cost_manager_menu','user_pass'=>wp_generate_password(),'role'=>'subscriber'));
$cost_manager_user=get_userdata($cost_manager);$cost_manager_user->add_cap('adc_manage_vehicle_costs');$cost_manager_user->add_cap('adc_view_workspace');update_user_meta($cost_manager,'adc_branch_id',$branch['id']);
wp_set_current_user($cost_manager);
global $menu,$submenu;$menu=is_array($menu)?$menu:array();$submenu=is_array($submenu)?$submenu:array();\AutoDealership\Admin\Navigation::menu();$acquisition_menu=array_values(array_filter($submenu['adc-area-purchasing']??array(),static fn($item)=>'adc-vehicle-acquisition'===$item[2]));
$check(1===count($acquisition_menu)&&'adc_manage_vehicle_costs'===$acquisition_menu[0][1],'Cost managers without the separate view capability receive the acquisition page with their managing capability.');
wp_set_current_user($admin);
$supplier=SupplierService::create(array('supplier_code'=>'PO-SUPPLIER','display_name'=>'Test supplier'));
$vehicle=VehicleService::create(array('vin'=>'TESTPURC000000001','stock_number'=>'PO-1','brand'=>'Test','model'=>'Order','model_year'=>2026,'condition'=>'new','branch_id'=>$branch['id'],'retail_price'=>20000000));
$check(is_array($vehicle)&&is_array($supplier),'Create isolated purchase reference data');
$purchaser=wp_insert_user(array('user_login'=>'po_requester','user_pass'=>wp_generate_password(),'role'=>'dealership_purchasing'));
$receiver=wp_insert_user(array('user_login'=>'po_receiver','user_pass'=>wp_generate_password(),'role'=>'dealership_purchasing'));
$manager=wp_insert_user(array('user_login'=>'po_manager','user_pass'=>wp_generate_password(),'role'=>'dealership_general_manager'));
foreach(array($purchaser,$receiver,$manager)as$user){update_user_meta($user,'adc_branch_id',$branch['id']);}
wp_set_current_user($purchaser);
$input=array('vehicle_id'=>$vehicle['id'],'supplier_id'=>$supplier['id'],'purchase_amount'=>'100000.01','additional_amount'=>'500.00','tax_rate_bps'=>'1500','request_key'=>wp_generate_uuid4(),'reason'=>'Approved supplier offer');
$order=PO::create($input);if(is_wp_error($order)){echo $order->get_error_code().' '.$wpdb->last_error."\n";}$check(is_array($order),'Purchaser creates draft without approved policy');
$replay=PO::create($input);$check(is_array($replay)&&(int)$replay['id']===(int)$order['id'],'Duplicate submission returns same purchase order');
$input['request_key']=wp_generate_uuid4();$check(is_wp_error(PO::create($input)),'Duplicate active order for vehicle rejected');
$check(is_wp_error(PO::transition($order['id'],1,'submit','Submit')),'Unapproved purchase policy blocks submission');
wp_set_current_user($admin);$policy=PO::configure(array('approved_cancellation'=>'manager_only','manager_limit'=>'50000','general_limit'=>'200000','cost_basis'=>'gross'),'Test policy approval');$check(is_array($policy),'Explicit policy stores approved limits and cost basis');
wp_set_current_user($purchaser);$pending=PO::transition($order['id'],1,'submit','Submit');$check(is_array($pending)&&'pending'===$pending['status'],'Requester submits order with immutable policy snapshot');
$check(is_wp_error(PO::transition($order['id'],1,'cancel','Stale')),'Stale revision cannot cancel order');
$requester_user=get_userdata($purchaser);$requester_user->add_cap('adc_approve_purchase_orders');$requester_user->add_cap('adc_approve_high_purchase_orders');wp_set_current_user(0);wp_set_current_user($purchaser);
$check(is_wp_error(PO::transition($order['id'],2,'approve','Own approval')),'Requester cannot approve own order even with approval capabilities');
wp_set_current_user($manager);$approved=PO::transition($order['id'],2,'approve','Independent approval');$check(is_array($approved)&&'approved'===$approved['status'],'Independent high-tier approver approves order');
wp_set_current_user($admin);$changed_policy=PO::configure(array('approved_cancellation'=>'manager_only','manager_limit'=>'0','general_limit'=>'1','cost_basis'=>'net'),'New policy for future orders');$check(is_array($changed_policy),'Policy changes apply to future submissions');
wp_set_current_user($purchaser);$check(is_wp_error(PO::transition($order['id'],3,'receive','Receive','RCPT-1')),'Requester cannot receive own order');
wp_set_current_user($receiver);
$check(is_wp_error(PO::transition($order['id'],3,'cancel','Cancel approved purchase')),'Approved-order cancellation enforces the frozen high-tier policy');
$break_audit=static function(string $sql)use($wpdb):string{return str_contains($sql,'INSERT INTO')&&str_contains($sql,Schema::table('audit_events'))?'INSERT INTO missing_adc_audit_table (id) VALUES (1)':$sql;};
$wpdb->suppress_errors(true);add_filter('query',$break_audit);$failed=PO::transition($order['id'],3,'receive','Audit failure','FAILED-RECEIPT');remove_filter('query',$break_audit);
$check(is_wp_error($failed),'Audit failure rejects commercial receipt');
$check('approved'===$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Schema::table('purchase_orders').' WHERE id=%d',$order['id']))&&null===$wpdb->get_var($wpdb->prepare('SELECT purchase_cost FROM '.Schema::table('vehicles').' WHERE id=%d',$vehicle['id'])),'Failed receipt rolls back order and vehicle acquisition together');
$received=PO::transition($order['id'],3,'receive','Receipt evidence verified','RCPT-1');$check(is_array($received)&&'received'===$received['status'],'Independent receiver records commercial receipt');
$stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('vehicles').' WHERE id=%d',$vehicle['id']),ARRAY_A);
$check((int)$stored['total_cost']===(int)$received['total_amount']&&'received'===$stored['status']&&'net'===PO::policy()['cost_basis'],'Submitted gross policy remains frozen and commercial receipt preserves inspection workflow');
$check(is_wp_error(PO::transition($order['id'],4,'cancel','Cancel received')),'Received order cannot be cancelled');
update_user_meta($receiver,'adc_branch_id',0);$check(is_wp_error(PO::get($order['id'])),'Unassigned branch user cannot read purchase order');
wp_set_current_user($admin);$now=current_time('mysql',true);$wpdb->insert(Schema::table('customers'),array('full_name'=>'Archive test','created_at'=>$now,'updated_at'=>$now));$customer=(int)$wpdb->insert_id;
$wpdb->update(Schema::table('vehicles'),array('status'=>'available'),array('id'=>$vehicle['id']));$quote=SalesService::create_quote($customer,$vehicle['id'],gmdate('Y-m-d',time()+DAY_IN_SECONDS));
$check(is_array($quote),'Create isolated quotation for archive');
add_filter('query',$break_audit);$failed_archive=DocumentArchive::issue($quote['id'],1);remove_filter('query',$break_audit);$check(is_wp_error($failed_archive)&&0===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('document_archive')),'Failed archive audit leaves no issued number');
$archive=DocumentArchive::issue($quote['id'],1);$check(is_array($archive),'Archive immutable quotation revision');
$again=DocumentArchive::issue($quote['id'],1);$check(is_array($again)&&(int)$again['id']===(int)$archive['id'],'Repeated archive issuance preserves number and record');
$snapshot=QuoteHistory::version($quote['id'],1);$check(hash_equals($archive['checksum'],DocumentArchive::checksum($snapshot)),'Archive checksum matches stored source revision');
$snapshot['final_amount']=(string)((int)$snapshot['final_amount']+1);$check(!hash_equals($archive['checksum'],DocumentArchive::checksum($snapshot)),'Checksum detects financial source alteration');
$wpdb->update(Schema::table('customers'),array('email'=>'archive-test@example.invalid'),array('id'=>$customer));$export=\AutoDealership\Privacy\PrivacyTools::export('archive-test@example.invalid');$check(str_contains(wp_json_encode($export),$archive['document_number']),'Customer privacy export includes archived document references');
$erased=\AutoDealership\Privacy\PrivacyTools::erase('archive-test@example.invalid');$redacted=QuoteHistory::version($quote['id'],1);$check(!empty($erased['items_removed'])&&'Archive test'!==$redacted['customer_name']&&hash_equals($archive['checksum'],DocumentArchive::checksum($redacted)),'Privacy erasure redacts customer name while preserving archived financial integrity');
wp_set_current_user($receiver);$check(is_wp_error(DocumentArchive::issue($quote['id'],1)),'Archive source permissions enforced');
wp_set_current_user($admin);$_GET=array('page'=>'adc-purchase-orders','id'=>$order['id']);ob_start();\AutoDealership\Admin\PurchaseOrderPage::render();$html=ob_get_clean();$check(str_contains($html,'RCPT-1')&&str_contains($html,$order['order_number']),'Purchase admin page renders scoped order and receipt evidence');
$_GET['lang']='en';ob_start();\AutoDealership\Admin\PurchaseOrderPage::render();$english=ob_get_clean();$check(str_contains($english,'Purchase orders')&&str_contains($english,'Receipt reference:'),'Purchase workspace includes English labels');unset($_GET['lang']);
$evidence_input=array('gate_key'=>'external_scheduler','artifact_reference'=>'private-reports/scheduler-test.txt','artifact_hash'=>hash('sha256','Synthetic isolated evidence'),'valid_until'=>gmdate('Y-m-d',time()+DAY_IN_SECONDS),'reason'=>'Isolated evidence workflow verification');
$evidence=\AutoDealership\Operations\ReleaseEvidence::submit($evidence_input);$check(is_array($evidence),'Release evidence is submitted pending review');
$check(is_wp_error(\AutoDealership\Operations\ReleaseEvidence::review($evidence['id'],true,'Self approval')),'Submitter cannot approve own release evidence');
$reviewer=wp_insert_user(array('user_login'=>'release_reviewer','user_pass'=>wp_generate_password(),'role'=>'administrator'));wp_set_current_user($reviewer);
$reviewed=\AutoDealership\Operations\ReleaseEvidence::review($evidence['id'],true,'Independent test review');$check(is_array($reviewed)&&'approved'===$reviewed['status'],'Independent administrator reviews evidence');
$approved_evidence=\AutoDealership\Operations\ReleaseEvidence::approved(site_url(),ADC_VERSION);$check(isset($approved_evidence['external_scheduler']),'Current environment and release recognize approved evidence');
$check(!\AutoDealership\Operations\ReleaseEvidence::approved('https://different.example.invalid',ADC_VERSION)&&!\AutoDealership\Operations\ReleaseEvidence::approved(site_url(),'different-version'),'Evidence cannot satisfy another environment or release');
$wpdb->update(Schema::table('release_evidence'),array('valid_until'=>'2000-01-01'),array('id'=>$evidence['id']));$check(!\AutoDealership\Operations\ReleaseEvidence::approved(site_url(),ADC_VERSION),'Expired evidence returns to manual verification');
wp_set_current_user($admin);add_filter('query',$break_audit);$failed_policy=PO::configure(array('approved_cancellation'=>'manager_only','manager_limit'=>'1','general_limit'=>'2','cost_basis'=>'gross'),'Audit test');remove_filter('query',$break_audit);$check(is_wp_error($failed_policy)&&'net'===PO::policy()['cost_basis'],'Policy audit failure preserves previous approved configuration');
wp_set_current_user($receiver);$check(is_wp_error(\AutoDealership\Operations\ReleaseEvidence::submit($evidence_input)),'Operational staff cannot create release sign-off');wp_set_current_user($admin);
wp_set_current_user($admin);
$race_vehicle=VehicleService::create(array('vin'=>'TESTPURC000000002','stock_number'=>'PO-RACE','brand'=>'Test','model'=>'Concurrent order','model_year'=>2026,'condition'=>'new','branch_id'=>$branch['id'],'retail_price'=>20000000));
$race_input=array('vehicle_id'=>$race_vehicle['id'],'supplier_id'=>$supplier['id'],'purchase_amount'=>'100000.01','additional_amount'=>'500.00','tax_rate_bps'=>'1500','request_key'=>wp_generate_uuid4(),'reason'=>'Concurrent isolated submission');
$barrier='adc_gate_'.bin2hex(random_bytes(8));$jobs=array();$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$barrier));
try{
	for($i=0;$i<2;++$i){$worker=proc_open(array(PHP_BINARY,__DIR__.'/database-runner.php','--worker'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>STDERR),$pipes);if(!is_resource($worker)){throw new RuntimeException('Purchase concurrency worker failed');}fwrite($pipes[0],json_encode(array('database'=>DB_NAME,'source_database'=>'','host'=>DB_HOST,'user'=>DB_USER,'password'=>DB_PASSWORD,'scenario'=>'purchase_create','actor'=>$purchaser,'barrier'=>$barrier,'input'=>$race_input)));fclose($pipes[0]);$jobs[]=array($worker,$pipes[1]);}
	usleep(500000);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$barrier));$results=array();foreach($jobs as$job){$results[]=json_decode(stream_get_contents($job[1]),true);fclose($job[1]);$check(0===proc_close($job[0]),'Concurrent purchase worker finishes successfully');}
	$check(isset($results[0]['id'],$results[1]['id'])&&(int)$results[0]['id']===(int)$results[1]['id']&&1===(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Schema::table('purchase_orders').' WHERE vehicle_id=%d',$race_vehicle['id'])),'Concurrent identical submissions produce one order and stable replay');
}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$barrier));}
require __DIR__.'/purchasing-http.php';
echo "RESULT $checks purchasing/archive/release checks passed\n";
