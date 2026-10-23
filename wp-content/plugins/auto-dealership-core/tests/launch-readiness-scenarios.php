<?php
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}

use AutoDealership\Database\Schema;
use AutoDealership\Pricing\PublicVehiclePrice as Price;
use AutoDealership\Pricing\PricingPolicy;
use AutoDealership\Pricing\ProfitMargin;
use AutoDealership\Sales\SalesService;
use AutoDealership\Sales\OnyxDocuments;
use AutoDealership\Operations\FollowUpNotifications as Alerts;
use AutoDealership\Operations\OutboxService;

global $wpdb;
$launch_checks=0;
$assert=static function(bool $ok,string $label)use(&$launch_checks):void{if(!$ok){throw new RuntimeException($label);}++$launch_checks;echo "PASS $label\n";};
$admin=(int)get_user_by('login','verify_admin')->ID;wp_set_current_user($admin);
$assert(Schema::verify()===array(),'Launch schema verifies financial snapshots, private PDFs and staff inbox');
$branch=\AutoDealership\Branches\BranchService::create(array('code'=>'LAUNCH-A','name'=>'Launch A'));
$other_branch=\AutoDealership\Branches\BranchService::create(array('code'=>'LAUNCH-B','name'=>'Launch B'));
$staff=wp_insert_user(array('user_login'=>'launch_sales','user_pass'=>wp_generate_password(),'role'=>'dealership_sales'));
$manager=wp_insert_user(array('user_login'=>'launch_manager','user_pass'=>wp_generate_password(),'role'=>'dealership_sales_manager'));
$foreign=wp_insert_user(array('user_login'=>'launch_foreign','user_pass'=>wp_generate_password(),'role'=>'dealership_sales_manager'));
foreach(array($staff,$manager) as $user){update_user_meta($user,'adc_branch_id',$branch['id']);}update_user_meta($foreign,'adc_branch_id',$other_branch['id']);
$now=current_time('mysql',true);
$wpdb->insert(Schema::table('customers'),array('full_name'=>'Launch customer','created_at'=>$now,'updated_at'=>$now));$customer=(int)$wpdb->insert_id;
$wpdb->insert(Schema::table('leads'),array('customer_id'=>$customer,'branch_id'=>$branch['id'],'owner_user_id'=>$staff,'lost_reason'=>'','created_at'=>$now,'updated_at'=>$now));$lead=(int)$wpdb->insert_id;
$vehicle=\AutoDealership\Inventory\VehicleService::create(array('vin'=>'TESTLNCH998877661','stock_number'=>'LAUNCH-1','brand'=>'Test','model'=>'Launch','model_year'=>2026,'condition'=>'new','branch_id'=>$branch['id'],'retail_price'=>8000000,'purchase_cost'=>7000000));
$assert(is_array($vehicle),'Create genuine service fixture for launch pricing and sale');
$post=wp_insert_post(array('post_type'=>'car','post_status'=>'draft','post_title'=>'Launch price'));
update_post_meta($post,'_car_plate_type','private_transport');
$wpdb->update(Schema::table('vehicles'),array('status'=>'available','public_post_id'=>$post),array('id'=>$vehicle['id']));
update_option('adc_vat_rate_bps',1500);update_option('adc_pricing_fee_amount',3000);
foreach(array('before_tax','after_tax') as $stage){
	$policy=ProfitMargin::save(array('type'=>'fixed','value'=>'1500','stage'=>$stage,'revision'=>(string)ProfitMargin::policy()['revision']));$assert(is_array($policy),'Save shared pricing policy '.$stage);
	foreach(array(1,101,12345,8000000) as $net){
		$price=Price::calculate($net,$post);$quote=PricingPolicy::vehicle_quote($vehicle['id'],$net);
		$assert($quote['final_amount']===$price['final_amount']&&$quote['tax_amount']===$price['tax_amount'],'Catalogue and quotation agree including fees, VAT and plates '.$stage.' '.$net);
		$assert($price['tax_amount']===\AutoDealership\Pricing\Money::calculate($price['taxable_amount'],0,1500)['tax_amount'],'Margin contributes to taxable subtotal '.$stage.' '.$net);
		$assert((int)$wpdb->get_var('SELECT '.Price::sql((string)$net,(string)$post))===$price['final_amount'],'Displayed price SQL agrees with shared arithmetic '.$stage.' '.$net);
	}
}
wp_set_current_user($staff);
$quote=SalesService::create_quote($customer,$vehicle['id'],gmdate('Y-m-d',time()+7*DAY_IN_SECONDS));
$assert(is_array($quote)&&$quote['plate_amount']===90000&&$quote['source_base_amount']===8000000,'New commercial quotation persists margin and plate snapshots');
$saved=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('quotations').' WHERE id=%d',$quote['id']),ARRAY_A);
wp_set_current_user($admin);ProfitMargin::save(array('type'=>'fixed','value'=>'9000','stage'=>'before_tax','revision'=>(string)ProfitMargin::policy()['revision']));update_post_meta($post,'_car_plate_type','commercial_transport');update_option('adc_pricing_fee_amount',99999);
$recalculated=PricingPolicy::quote((int)$saved['base_amount'],0,(int)$saved['tax_rate_bps'],'',$saved);
$assert($recalculated['final_amount']===(int)$saved['final_amount']&&$recalculated['plate_amount']===90000,'Frozen quotation ignores later margin, plate and fee policy changes');
$discounted=PricingPolicy::quote((int)$saved['base_amount'],10000,(int)$saved['tax_rate_bps'],'',$saved);
$assert($discounted['plate_amount']===90000&&$discounted['final_amount']===(int)$saved['final_amount']-11500,'Discount reprices VAT while preserving original plate and margin policy');
$history=\AutoDealership\Pricing\QuoteHistory::versions($quote['id']);$assert((int)$history[0]['plate_amount']===90000,'Immutable quote history captures complete pricing snapshot');
$legacy=array('id'=>'7','customer_name'=>'Old','base_amount'=>'100');$with_defaults=$legacy+array('source_base_amount'=>'0','margin_amount'=>'0','margin_stage'=>'','margin_revision'=>'0','plate_amount'=>'0');
$assert(\AutoDealership\Pricing\DocumentArchive::checksum($legacy)===\AutoDealership\Pricing\DocumentArchive::checksum($with_defaults),'Migration leaves previously issued archive checksums intact');
wp_set_current_user($staff);update_option('adc_reservation_deposit_type','none');
$reservation=\AutoDealership\Reservations\ReservationService::create(array('vehicle_id'=>$vehicle['id'],'customer_id'=>$customer,'idempotency_key'=>wp_generate_uuid4()));
$sale=SalesService::create_sale($quote['id'],$reservation['id']);$assert(is_array($sale),'Create sale against immutable quote and matching reservation');
wp_set_current_user($manager);
$blocked=SalesService::approve_sale($sale['id'],'ONYX-LAUNCH-1');$assert(is_wp_error($blocked)&&$blocked->get_error_code()==='adc_onyx_invoice_required','Sale cannot approve a reference without matching source PDF');
$invoice=adc_fixture_onyx_invoice($sale['id'],'ONYX-LAUNCH-1');
$assert($invoice['id']>0,'Independent authorized manager archives matching Onyx invoice');
$repeat=adc_fixture_onyx_invoice($sale['id'],'onyx-launch-1');$assert($repeat['existing']&&$repeat['id']===$invoice['id'],'Invoice replay normalizes reference and returns original archive');
$pdf=$wpdb->get_var($wpdb->prepare('SELECT file_bytes FROM '.Schema::table('onyx_documents').' WHERE id=%d',$invoice['id']));
$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'invoice','OTHER',gmdate('Y-m-d'),1,0,$pdf,true)),'Invoice amount mismatch rejects original file binding');
$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'contract','BAD',gmdate('Y-m-d'),100,0,'<html>file</html>',true)),'Archive rejects non-PDF content');
$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'contract','UNVERIFIED',gmdate('Y-m-d'),100,0,$pdf,false)),'Original document requires explicit staff verification');
$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'contract','HUGE',gmdate('Y-m-d'),100,0,'%PDF-'.str_repeat('x',OnyxDocuments::MAX_BYTES),true)),'Archive enforces maximum file size');
$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'credit_note','NO-PARENT',gmdate('Y-m-d'),100,0,$pdf,true,999999)),'Credit note must reference this sale invoice');
$credit=OnyxDocuments::attach($sale['id'],'credit_note','CREDIT-1',gmdate('Y-m-d'),100,0,$pdf,true,$invoice['id']);$assert(is_array($credit),'Credit note links to the original invoice without financial mutation');
$assert(OnyxDocuments::attach($sale['id'],'credit_note','CREDIT-1',gmdate('Y-m-d'),100,0,$pdf,true,$invoice['id'])['existing'],'Credit note replay does not consume remaining invoice amount twice');
$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'credit_note','EXCESS',gmdate('Y-m-d'),(int)$saved['final_amount'],0,$pdf,true,$invoice['id'])),'Cumulative credits cannot exceed invoice total');
$wpdb->update(Schema::table('onyx_documents'),array('file_bytes'=>$pdf.'changed'),array('id'=>$invoice['id']));$assert(is_wp_error(SalesService::approve_sale($sale['id'],'ONYX-LAUNCH-1')),'Sale approval rejects a corrupted archived invoice');
$wpdb->update(Schema::table('onyx_documents'),array('file_bytes'=>$pdf),array('id'=>$invoice['id']));
wp_set_current_user($foreign);$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'contract','FOREIGN',gmdate('Y-m-d'),100,0,$pdf,true)),'Foreign branch cannot archive another branch document');
wp_set_current_user($staff);$assert(is_wp_error(OnyxDocuments::attach($sale['id'],'contract','STAFF',gmdate('Y-m-d'),100,0,$pdf,true)),'Sales owner lacks authority to verify official documents');
wp_set_current_user($manager);$assert(is_array(SalesService::approve_sale($sale['id'],'ONYX-LAUNCH-1')),'Verified invoice enables independent sale approval');

Alerts::boot();
update_option('adc_followup_email_enabled',false);wp_cache_delete('adc_followup_email_enabled','options');wp_cache_delete('alloptions','options');
wp_set_current_user($staff);$assert(is_wp_error(Alerts::email_policy(true,false)),'Staff cannot enable workflow email');
wp_set_current_user($admin);$inbox_policy=Alerts::email_policy(false,false);$assert(is_array($inbox_policy),'Administrator explicitly saves inbox-only delivery'.(is_wp_error($inbox_policy)?': '.$inbox_policy->get_error_code():''));
$assert(is_wp_error(Alerts::email_policy(true,true)),'Stale email setting cannot overwrite the current policy');
$due=gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS);$wpdb->update(Schema::table('leads'),array('next_action_at'=>$due),array('id'=>$lead));
$scan=Alerts::scan();$assert(is_array($scan),'Scheduled scan creates durable staff reminders');
$table=Schema::table('followup_notifications');
$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE source_type='lead' AND source_id=%d",$lead),ARRAY_A);
$owner=array_values(array_filter($rows,static fn($r)=>(int)$r['recipient_id']===$staff));$managers=array_values(array_filter($rows,static fn($r)=>(int)$r['recipient_id']===$manager));
$assert(count($owner)===1&&count($managers)===1&&(int)$managers[0]['level']===1,'Overdue lead notifies its owner and escalates to its branch manager');
$assert(!array_filter($rows,static fn($r)=>(int)$r['recipient_id']===$foreign),'Escalation excludes foreign branch managers');
Alerts::scan();$assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE source_type='lead' AND source_id=%d",$lead))===count($rows),'Repeated scan does not duplicate reminders');
wp_set_current_user($foreign);$assert(is_wp_error(Alerts::acknowledge((int)$owner[0]['id'])),'Another recipient cannot acknowledge a staff alert');
wp_set_current_user($staff);$assert(is_array(Alerts::acknowledge((int)$owner[0]['id'])),'Assigned staff acknowledgement persists with audit');
$assert(is_array(Alerts::acknowledge((int)$owner[0]['id'])),'Acknowledgement replay is idempotent');
$assert($wpdb->get_var($wpdb->prepare('SELECT stage FROM '.Schema::table('leads').' WHERE id=%d',$lead))==='new','Acknowledgement does not resolve the underlying lead');
$delivery=Alerts::deliver(array('subject_id'=>(int)$managers[0]['id']));$assert($delivery===true,'Internal inbox delivery completes independently of email configuration');
$wpdb->update(Schema::table('leads'),array('next_action_at'=>gmdate('Y-m-d H:i:s',time()-3600)),array('id'=>$lead));Alerts::scan();
$fresh=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_type='lead' AND source_id=%d AND recipient_id=%d ORDER BY id DESC LIMIT 1",$lead,$staff));
update_option('adc_followup_email_enabled',true);remove_filter('pre_wp_mail','__return_true');$fail_mail=static fn()=>false;add_filter('pre_wp_mail',$fail_mail);
$assert(is_wp_error(Alerts::deliver(array('subject_id'=>$fresh))),'Email rejection remains retryable and is not reported as delivery');
$assert($wpdb->get_var($wpdb->prepare("SELECT delivered_at FROM $table WHERE id=%d",$fresh))===null,'Failed email leaves delivery unconfirmed');
remove_filter('pre_wp_mail',$fail_mail);add_filter('pre_wp_mail','__return_true');update_option('adc_followup_email_enabled',false);
$wpdb->update(Schema::table('leads'),array('stage'=>'won'),array('id'=>$lead));$assert(Alerts::deliver(array('subject_id'=>$fresh))===true,'Resolved lead suppresses stale queued notification');
$assert($wpdb->get_var($wpdb->prepare("SELECT resolved_at FROM $table WHERE id=%d",$fresh))!==null,'Suppressed notification records resolution');

wp_set_current_user($admin);
$prototype=$saved;unset($prototype['id']);
for($i=1;$i<=105;++$i){$prototype['quote_number']='PAGE-'.$i;$wpdb->insert(Schema::table('quotations'),$prototype);}
$_GET=array('page'=>'adc-customer-workspace','customer_id'=>$customer,'quotations_paged'=>3);ob_start();\AutoDealership\Admin\CustomerWorkspacePage::render();$html=ob_get_clean();
$assert(str_contains($html,'PAGE-1')&&!str_contains($html,'PAGE-105'),'Customer section pagination reaches records beyond the former 100 record cap');
$assert(str_contains($html,'quotations_paged=')&&str_contains($html,'section-quotations'),'Customer sections retain independent pagination links');
$assert(str_contains($html,'adc-sales-journey')&&str_contains($html,'quote_id='),'Customer history includes direct quotation detail links');
wp_set_current_user($foreign);$assert(Alerts::inbox()===array(),'Foreign branch staff cannot discover inbox records');
$_GET=array();wp_set_current_user($admin);update_option('adc_pricing_fee_amount',0);update_option('adc_vat_rate_bps',0);ProfitMargin::save(array('type'=>'none','value'=>'0','stage'=>'before_tax','revision'=>(string)ProfitMargin::policy()['revision']));
echo 'Launch readiness checks passed: '.$launch_checks."\n";
