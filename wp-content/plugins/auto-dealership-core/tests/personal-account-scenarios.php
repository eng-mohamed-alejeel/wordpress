<?php
/** Included by the isolated portal suite; exercises real roles and overlapping branch rights. */
use AutoDealership\Portal\{AccountOverview,AccountFilters,PortalAccess as Access,Conversations as Chat,CustomerJourney as Journey,PortalView};
use AutoDealership\Database\Schema;
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
$personalBefore=$_GET;$personalGlobal=$GLOBALS['adc_portal_personal_request']??false;$_GET=array();
$paActors=array($make('personal_customer','car_dealer_customer',0,true),$make('personal_staff','dealership_sales',$branch,true),$make('personal_admin','administrator',0,true));
$paFixtures=array();
foreach($paActors as $actor){
    wp_set_current_user($actor);Access::request_scope(false);$marker='ACCOUNT_ONLY_'.$actor;
    $wpdb->insert($wpdb->prefix.'car_dealer_messages',array('user_id'=>$actor,'name'=>$marker,'email'=>get_userdata($actor)->user_email,'subject'=>$marker,'message'=>$marker,'customer_reply'=>'','created_at'=>$now,'updated_at'=>$now));$request=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'car_dealer_bookings',array('user_id'=>$actor,'car_id'=>0,'name'=>$marker,'email'=>get_userdata($actor)->user_email,'requested_date'=>wp_date('Y-m-d',time()+5*DAY_IN_SECONDS),'requested_time'=>'13:00','customer_reply'=>'','created_at'=>$now,'updated_at'=>$now));$booking=(int)$wpdb->insert_id;
    $thread=Chat::ensure('general',0,$marker,$branch);$check(is_int($thread),'personal role fixture can create its own conversation');
    $wpdb->insert(Access::table('messages'),array('thread_id'=>$thread,'sender_id'=>0,'sender_kind'=>'staff','sender_label'=>'Adviser','body'=>$marker,'request_key'=>hash('sha256',$marker),'body_hash'=>hash('sha256',$marker),'created_at'=>$now));$public=(int)$wpdb->insert_id;
    $wpdb->update(Access::table('threads'),array('last_public_id'=>$public),array('id'=>$thread));
    $wpdb->insert(Schema::table('customers'),array('account_user_id'=>$actor,'full_name'=>$marker,'mobile'=>'050'.str_pad((string)$actor,7,'0',STR_PAD_LEFT),'created_at'=>$now,'updated_at'=>$now));$cid=(int)$wpdb->insert_id;
    $wpdb->insert(Schema::table('quotations'),array('quote_number'=>$marker,'customer_id'=>$cid,'vehicle_id'=>0,'branch_id'=>$branch,'owner_user_id'=>$sales,'base_amount'=>100000,'final_amount'=>115000,'valid_until'=>wp_date('Y-m-d',time()+DAY_IN_SECONDS),'status'=>'issued','seller_address'=>'','created_at'=>$now));$qid=(int)$wpdb->insert_id;
    $wpdb->insert(Schema::table('sales'),array('quotation_id'=>$qid,'customer_id'=>$cid,'vehicle_id'=>0,'owner_user_id'=>$sales,'status'=>'pending_approval','created_at'=>$now,'updated_at'=>$now));$sid=(int)$wpdb->insert_id;
    $wpdb->insert(Access::table('documents'),array('sale_id'=>$sid,'kind'=>'invoice','title'=>$marker,'reference'=>$marker,'content_hash'=>hash('sha256',$xBytes),'file_bytes'=>$xBytes,'file_size'=>strlen($xBytes),'created_by'=>$admin,'created_at'=>$now));$doc=(int)$wpdb->insert_id;
    $check(!$wpdb->last_error&&$doc>0,'personal role fixtures create owned sale and private document');
    $paFixtures[$actor]=compact('thread','request','booking','sid','doc','marker');
}
foreach($paActors as $actor){
    wp_set_current_user($actor);$_GET=array('cd_account'=>'dashboard','lang'=>'en');Access::request_scope(false);$own=$paFixtures[$actor];
    $personalQueries=array();$capturePersonal=static function($sql)use(&$personalQueries){$personalQueries[]=$sql;return $sql;};add_filter('query',$capturePersonal);$beforeQueries=$wpdb->num_queries;try{$snapshot=AccountOverview::snapshot();}finally{remove_filter('query',$capturePersonal);}
    $check(!$snapshot['errors']&&$snapshot['counts']===array('requests'=>1,'bookings'=>1,'messages'=>1,'favorites'=>0,'purchases'=>1,'documents'=>1),'customer/staff/admin summary counts are strictly personal: '.$actor.' '.wp_json_encode($snapshot['errors']));
    $dataQueries=array_filter($personalQueries,static fn($sql)=>!str_contains($sql,$wpdb->options));
    $check(count($dataQueries)<=14&&$wpdb->num_queries-$beforeQueries<=20,'personal summary uses at most 14 data queries plus 6 uncached platform options: '.($wpdb->num_queries-$beforeQueries));
    $check(!array_filter($personalQueries,static fn($sql)=>preg_match('/\A\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER)\b/i',$sql)),'personal overview remains read-only for every role');
    $check((int)$snapshot['booking']['id']===$own['booking']&&(int)$snapshot['purchase']['id']===$own['sid'],'next appointment and purchase belong to the signed-in account');
    $check(count(Chat::list(1,'',-1,0,true))===1&&count(Chat::list(1,'unread',-1,0,true))===1,'personal and unread conversation lists match personal counter for every role');
    foreach($paFixtures as $otherActor=>$otherFixture){if($otherActor===$actor){continue;}
        $check($error(Chat::detail($otherFixture['thread']))&&$error(Chat::send($otherFixture['thread'],'forged',wp_generate_uuid4())),'personal page cannot read or reply to another account despite elevated role');
        $check(Journey::document($otherFixture['doc'],true)===null,'personal document guard cannot use administrative fallback');
        $check(\AutoDealership\Accounts\CustomerRequestView::page('messages',1,array('request'=>$otherFixture['request']))['items']===array(),'direct request filter preserves ownership');
        $_GET['portal']='purchases';$_GET['sale']=(string)$otherFixture['sid'];ob_start();PortalView::render();$html=ob_get_clean();$check(!str_contains($html,$otherFixture['marker']),'direct purchase filter preserves ownership');unset($_GET['sale']);
    }
    $_GET['portal']='overview';ob_start();PortalView::render();$html=ob_get_clean();
    if($actor===$paActors[0]){$check(str_contains($html,'Read messages')&&str_contains($html,'adc-p-nav-count')&&str_contains($html,$own['marker']),'customer overview displays actionable own records and unread badge');}
    else{$check(str_contains($html,'My account settings')&&str_contains($html,'name="display_name"')&&!str_contains($html,$own['marker'])&&!str_contains($html,'adc-p-stat')&&!str_contains($html,'adc-p-nav-count'),'staff/admin settings omit customer records and counters');}
    $check(Chat::read($own['thread'],(int)Chat::row($own['thread'])['last_public_id'])===true&&AccountOverview::snapshot()['counts']['messages']===0&&Chat::list(1,'unread',-1,0,true)===array(),'reading own conversation updates summary and filtered list consistently');
}
$empty=$make('personal_empty_admin','administrator',0,true);wp_set_current_user($empty);$_GET=array('cd_account'=>'dashboard','portal'=>'overview','lang'=>'en');Access::request_scope(false);$snapshot=AccountOverview::snapshot();
$check(!$snapshot['errors']&&array_sum($snapshot['counts'])===0&&$snapshot['events']===array(),'administrator with no personal records has a genuinely empty account');
ob_start();PortalView::render();$html=ob_get_clean();$check(str_contains($html,'My account settings')&&!str_contains($html,'Browse cars')&&!str_contains($html,'ACCOUNT_ONLY_'),'empty elevated account shows settings without customer next actions or tenant data');
$fault=static fn($sql)=>str_contains($sql,'SELECT COUNT(*) amount FROM '.$wpdb->prefix.'car_dealer_messages')?'SELECT missing_account_column FROM '.$wpdb->prefix.'car_dealer_messages':$sql;
$previousErrors=$wpdb->suppress_errors(true);add_filter('query',$fault);try{$snapshot=AccountOverview::snapshot();}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previousErrors);}
$check(null===$snapshot['counts']['requests']&&isset($snapshot['errors']['requests'])&&0===$snapshot['counts']['documents'],'query failure is unknown, never a successful zero, and other widgets remain independent');
$_GET=array('status'=>'upcoming');$filters=AccountFilters::values(array('upcoming'=>'Upcoming'));$filters['request']=$paFixtures[$paActors[0]]['booking'];wp_set_current_user($paActors[0]);
$check(count(\AutoDealership\Accounts\CustomerRequestView::page('bookings',1,$filters)['items'])===1,'upcoming drilldown includes the same owned appointment as the counter');
$_GET=array('search'=>'%_','from'=>'2026-02-31','to'=>'2026-10-19','status'=>'arbitrary','user_id'=>(string)$paActors[1]);$filters=AccountFilters::values(array('open'=>'Open'));
$check(''===$filters['from']&&''===$filters['status']&&!isset($filters['user_id']),'filter parser rejects invalid calendar dates, states and actor parameters');
$check(\AutoDealership\Accounts\CustomerRequestView::page('messages',1,$filters)['items']===array(),'search treats SQL wildcard characters literally');
$_GET=array();Access::request_scope(true);wp_set_current_user($paActors[2]);$check(! $error(Chat::detail($paFixtures[$paActors[0]]['thread']))&&count(Chat::list())>1,'separate administrator workspace retains authorized operational access');
$selfThread=$paFixtures[$paActors[1]]['thread'];$check($error(Chat::change($selfThread,'assign',(int)Chat::row($selfThread)['revision'],$paActors[1])),'a staff personal conversation cannot be assigned back to the same account as adviser');
$_GET=$personalBefore;$GLOBALS['adc_portal_personal_request']=$personalGlobal;wp_set_current_user($customer);
