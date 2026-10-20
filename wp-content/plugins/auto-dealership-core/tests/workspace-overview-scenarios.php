<?php
/** Populated read-only dashboard acceptance on the disposable server only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Admin\{WorkspaceOverview as Overview, WorkspaceOverviewView, Navigation, WorkflowPages};
use AutoDealership\Database\Schema;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Branches\BranchService;
use AutoDealership\Sales\OnyxDocuments;
global $wpdb;
$checks=0;
$check=static function($ok,$message)use(&$checks){if(!$ok){throw new RuntimeException($message);}++$checks;};
$insert=static function($name,$data)use($wpdb){if(1!==$wpdb->insert(Schema::table($name),$data)){throw new RuntimeException('Fixture '.$name.': '.$wpdb->last_error);}return(int)$wpdb->insert_id;};
$admin=get_current_user_id();
$a=BranchService::create(array('code'=>'OV-A','name'=>'Overview branch A'));
$b=BranchService::create(array('code'=>'OV-B','name'=>'Foreign branch B'));
$check(is_array($a)&&is_array($b),'Create disposable branches');
$staff=static function($name,$caps,$branch)use($check){$id=wp_insert_user(array('user_login'=>$name,'user_pass'=>wp_generate_password(32),'role'=>'subscriber'));$check(!is_wp_error($id),'Create synthetic user');$u=get_userdata($id);foreach(array_merge(array('adc_view_workspace'),$caps)as$cap){$u->add_cap($cap);} $result=ConfigurationService::assign_branches($id,$branch,array($branch));$check(!is_wp_error($result),'Assign scoped branch');return$id;};
$sales=$staff('overview_sales',array('adc_view_own_leads'),$a['id']);
$other=$staff('overview_other',array('adc_view_own_leads'),$a['id']);
$manager=$staff('overview_manager',array('adc_view_branch_leads','adc_review_discounts','adc_approve_sales'),$a['id']);
$finance=$staff('overview_finance',array('adc_view_finance','adc_verify_payments','adc_manage_finance'),$a['id']);
$delivery=$staff('overview_delivery',array('adc_approve_delivery'),$a['id']);
$unassigned=wp_insert_user(array('user_login'=>'overview_unassigned','user_pass'=>wp_generate_password(32),'role'=>'subscriber'));get_userdata($unassigned)->add_cap('adc_view_workspace');get_userdata($unassigned)->add_cap('adc_view_own_leads');
$now=current_time('mysql',true);$past=gmdate('Y-m-d H:i:s',time()-3600);$future=gmdate('Y-m-d H:i:s',time()+3600);
$c=$insert('customers',array('full_name'=>'Overview <script>alert(1)</script> client','created_at'=>$now,'updated_at'=>$now));
$foreign=$insert('customers',array('full_name'=>'FOREIGN_CUSTOMER_SECRET','created_at'=>$now,'updated_at'=>$now));
for($i=0;$i<33;$i++){$insert('leads',array('customer_id'=>$i<30?$c:$foreign,'branch_id'=>$i<30?$a['id']:$b['id'],'owner_user_id'=>$i<25?$sales:$other,'lost_reason'=>'','next_action_at'=>$past,'created_at'=>$now,'updated_at'=>$now));}
$old=$insert('leads',array('customer_id'=>$c,'branch_id'=>$a['id'],'owner_user_id'=>$sales,'lost_reason'=>'','next_action_at'=>$future,'created_at'=>gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS),'updated_at'=>$now));
$vehicle=static function($n,$branch)use($insert,$now){return$insert('vehicles',array('vin'=>'1M8GDM9AX'.str_pad((string)$n,8,'0',STR_PAD_LEFT),'stock_number'=>'OV-'.$n,'brand'=>'Fixture','model'=>'Overview','model_year'=>2026,'condition_key'=>'new','branch_id'=>$branch,'status'=>'reserved','gallery_media_ids'=>'','document_media_ids'=>'','internal_notes'=>'','created_at'=>$now,'updated_at'=>$now));};
$va=$vehicle(1,$a['id']);$vb=$vehicle(2,$b['id']);
$quote=static function($n,$v,$branch,$customer,$status='approved')use($insert,$now,$other){return$insert('quotations',array('quote_number'=>'OV-Q-'.$n,'customer_id'=>$customer,'vehicle_id'=>$v,'branch_id'=>$branch,'owner_user_id'=>$other,'base_amount'=>100000,'final_amount'=>100000,'valid_until'=>gmdate('Y-m-d',time()+DAY_IN_SECONDS),'status'=>$status,'seller_address'=>'','created_at'=>$now));};
$qa=$quote(1,$va,$a['id'],$c);$qb=$quote(2,$vb,$b['id'],$foreign);
$discount_ids=array();
foreach(array(array($other,'sales_manager',$va,$a['id'],$c),array($manager,'sales_manager',$va,$a['id'],$c),array($other,'general_manager',$va,$a['id'],$c),array($other,'sales_manager',$vb,$b['id'],$foreign))as$i=>$d){$q=$quote(10+$i,$d[2],$d[3],$d[4],'pending_discount');$discount_ids[]=$insert('discount_requests',array('quotation_id'=>$q,'requester_user_id'=>$d[0],'requested_amount'=>100,'approval_tier'=>$d[1],'reason'=>'Synthetic review','created_at'=>$now));}
$sale=static function($q,$v,$customer,$owner,$status='pending_approval')use($insert,$now){return$insert('sales',array('quotation_id'=>$q,'reservation_id'=>0,'customer_id'=>$customer,'vehicle_id'=>$v,'owner_user_id'=>$owner,'status'=>$status,'created_at'=>$now,'updated_at'=>$now));};
$sa=$sale($qa,$va,$c,$other);$selfsale=$sale($qa,$va,$c,$finance);$sb=$sale($qb,$vb,$foreign,$other);$approved=$sale($qa,$va,$c,$other,'approved');
$payment_ids=array();foreach(array(array($sa,$other),array($sa,$finance),array($selfsale,$other),array($sb,$other))as$i=>$p){$payment_ids[]=$insert('payment_confirmations',array('sale_id'=>$p[0],'amount'=>100,'source'=>'cash_receipt','reference'=>'OV-P-'.$i,'recorded_by'=>$p[1],'decision_reason'=>'','created_at'=>$now));}
$reservation=$insert('reservations',array('vehicle_id'=>$va,'customer_id'=>$c,'branch_id'=>$a['id'],'owner_user_id'=>$sales,'expires_at'=>$future,'idempotency_key'=>wp_generate_uuid4(),'created_at'=>$now,'updated_at'=>$now));
$expired=$insert('reservations',array('vehicle_id'=>$va,'customer_id'=>$c,'branch_id'=>$a['id'],'owner_user_id'=>$sales,'expires_at'=>$past,'idempotency_key'=>wp_generate_uuid4(),'created_at'=>$now,'updated_at'=>$now));
$deposit=$insert('reservation_deposits',array('reservation_id'=>$reservation,'amount'=>100,'source'=>'cash_receipt','reference'=>'OV-D-1','recorded_by'=>$other,'decision_reason'=>'','created_at'=>$now));
$finance_id=$insert('finance_requests',array('sale_id'=>$sa,'provider'=>'Synthetic provider','requested_amount'=>100,'requested_by'=>$other,'decision_reason'=>'','created_at'=>$now,'updated_at'=>$now));
$insert('finance_requests',array('sale_id'=>$sa,'provider'=>'Own decision excluded','requested_amount'=>100,'requested_by'=>$finance,'decision_reason'=>'','created_at'=>$now,'updated_at'=>$now));
$did=$insert('deliveries',array('sale_id'=>$approved,'vehicle_id'=>$va,'created_at'=>$now,'updated_at'=>$now));
$_GET=array();wp_set_current_user($sales);$data=Overview::snapshot();
$check(!is_wp_error($data)&&25===$data['queues']['overdue']['count']&&25===$data['queues']['new_leads']['count'],'Sales sees own records only, not branch colleagues');
$check(count($data['rows'])===20&&$data['pages']===2,'First drill-down page is bounded to twenty');
$check(!isset($data['queues']['payments'])&&!isset($data['queues']['discounts']),'Sales sees no financial or approval counters');
$check(1===$data['queues']['reservations']['count']&&1===$data['queues']['expired_reservations']['count'],'Expiring and already expired reservations are separate actionable queues');
$_GET=array('workspace_task'=>'overdue','workspace_page'=>2);$next=Overview::snapshot();
$check(count($next['rows'])===5&&!array_intersect(array_column($data['rows'],'id'),array_column($next['rows'],'id')),'Second page reaches remaining rows without duplicates');
$_GET=array('workspace_task'=>'new_leads','workspace_period'=>'week');$check(26===Overview::snapshot()['queues']['new_leads']['count'],'Period filter reaches older owned leads');
$_GET=array('workspace_branch'=>$b['id']);$check(is_wp_error(Overview::snapshot()),'Branch parameter cannot widen access');
$_GET=array('workspace_task'=>'payments');$check(is_wp_error(Overview::snapshot()),'Queue parameter cannot reveal unauthorized counters');
$_GET=array('workspace_task'=>'overdue');wp_set_current_user($manager);$data=Overview::snapshot();
$check(30===$data['queues']['overdue']['count'],'Manager sees permitted branch, not foreign branch');
$check(1===$data['queues']['discounts']['count'],'Discount queue excludes own requests and higher approval tier');
$check(1===$data['queues']['reservations']['count'],'Reservation scope follows branch-manager access');
$_GET=array('workspace_task'=>'payments');wp_set_current_user($finance);$data=Overview::snapshot();
$check(1===$data['queues']['payments']['count']&&(int)$data['rows'][0]['id']===$payment_ids[0],'Payment reviewer excludes own recording and owned sales');
$check(1===$data['queues']['deposits']['count']&&1===$data['queues']['finance']['count'],'Independent deposit and finance work is visible');
$check(!isset($data['queues']['new_leads']),'Finance-only role cannot infer CRM counters');
wp_get_current_user()->add_cap('adc_view_own_leads');$_GET=array();$hybrid=Overview::snapshot();$check(0===$hybrid['queues']['overdue']['count']&&0===$hybrid['queues']['new_leads']['count'],'Finance plus own-leads permission does not grant branch-wide CRM access');wp_get_current_user()->remove_cap('adc_view_own_leads');
$check(!str_contains(wp_json_encode($data),'FOREIGN_CUSTOMER_SECRET')&&!str_contains(wp_json_encode($data),'requested_amount'),'View model contains neither foreign identity nor money fields');
$_GET=array();wp_set_current_user($delivery);$check(1===Overview::snapshot()['queues']['delivery']['count'],'Delivery role sees in-progress delivery without financial data');
wp_set_current_user($unassigned);$data=Overview::snapshot();$check(0===$data['queues']['overdue']['count']&&array()===$data['branches'],'Unassigned staff fails closed');
wp_set_current_user($sales);wp_get_current_user()->remove_cap('adc_view_own_leads');$check(array()===Overview::snapshot()['queues'],'Revoked capability takes effect without cached dashboard data');wp_get_current_user()->add_cap('adc_view_own_leads');
wp_set_current_user($admin);$_GET=array('workspace_branch'=>$a['id'],'workspace_task'=>'overdue');$before=$wpdb->num_queries;$data=Overview::snapshot();$query_count=$wpdb->num_queries-$before;
$check(30===$data['queues']['overdue']['count']&&$query_count<=32,'Filtered administrator overview has matching count and bounded cold query budget: '.wp_json_encode(array('count'=>$data['queues']['overdue']['count'],'queries'=>$query_count)));
require_once ABSPATH.'wp-admin/includes/template.php';$_SERVER['REQUEST_URI']='/wp-admin/admin.php?page=adc-workspace';
ob_start();WorkspaceOverviewView::render();$html=ob_get_clean();
$check(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>alert(1)</script>'),'Task labels are escaped');
$check(str_contains($html,'workspace_page')&&str_contains($html,'workspace_branch'),'Paging links preserve overview filters');
$groups=Navigation::groups();$items=array();foreach($groups as$g){foreach($g['items']as$item){$items[$item['slug']][]=array($g['id'],$item);}}
$check(count($items['adc-profit-margin'])===1&&$items['adc-profit-margin'][0][0]==='settings','Profit-margin destination has one canonical settings entry');
$check(count($items['adc-onyx-documents'])===1&&$items['adc-onyx-documents'][0][0]==='finance','Onyx documents have one canonical finance entry');
wp_set_current_user($sales);$check(!Navigation::allowed($items['adc-profit-margin'][0][1])&&!Navigation::allowed($items['adc-onyx-documents'][0][1]),'Unauthorized role sees neither financial document nor margin entry');
wp_set_current_user($finance);$check(Navigation::allowed($items['adc-onyx-documents'][0][1])&&!Navigation::allowed($items['adc-profit-margin'][0][1]),'Finance can open documents without acquiring pricing-settings permission');
$list=OnyxDocuments::sales_page();$check(!is_wp_error($list)&&3===$list['total']&&!str_contains(wp_json_encode($list),'FOREIGN_CUSTOMER_SECRET'),'Document sales picker respects branch scope');
$check(0===OnyxDocuments::sales_page(1,"' OR 1=1 --")['total'],'Document search cannot widen SQL scope');
$check(0===OnyxDocuments::sales_page(1,'100%_')['total'],'Document search escapes literal wildcard characters');
for($i=0;$i<21;$i++){$sale($qa,$va,$c,$other);}
$list=OnyxDocuments::sales_page(2);$check(24===$list['total']&&4===count($list['rows'])&&2===$list['pages'],'Document sales picker reaches records beyond the first page');
$check(2===OnyxDocuments::sales_page(9999)['page'],'Document page outside the range clamps safely');
wp_set_current_user($sales);$check(is_wp_error(OnyxDocuments::sales_page()),'Direct document-list access requires document permission');

// Real menu registration must agree with the card inventory and preserve slugs.
wp_set_current_user($admin);require_once ABSPATH.'wp-admin/includes/plugin.php';
$GLOBALS['menu']=array();$GLOBALS['submenu']=array();$GLOBALS['admin_page_hooks']=array();Navigation::menu();
$slugs=static fn($parent)=>array_column($GLOBALS['submenu'][$parent]??array(),2);
$check(1===count(array_keys($slugs('adc-area-finance'),'adc-onyx-documents',true))&&1===count(array_keys($slugs('adc-area-settings'),'adc-profit-margin',true)),'Canonical menu has exactly one entry for each moved screen');
$check(false!==has_action('load-'.get_plugin_page_hookname('adc-onyx-documents','adc-area-finance'),array(OnyxDocuments::class,'validate_request')),'Document record guard runs before the real WordPress admin header');
$check(false!==has_action('load-'.get_plugin_page_hookname('adc-purchase-orders','adc-area-purchasing'),array(\AutoDealership\Admin\PurchaseOrderPage::class,'validate_request')),'Purchase record guard runs before the real WordPress admin header');
wp_set_current_user($admin);$_GET=array('workspace_task'=>'overdue');
$table=Schema::table('leads');$wpdb->query("RENAME TABLE $table TO {$table}_unavailable");$wpdb->suppress_errors(true);
try{$data=Overview::snapshot();$check(null===$data['queues']['overdue']['count']&&null===$data['queues']['new_leads']['count'],'Failed query is not reported as zero');ob_start();WorkspaceOverviewView::render();$failed=ob_get_clean();$check(str_contains($failed,'role="alert"'),'Queue failure is visible as an alert');}finally{$wpdb->query("RENAME TABLE {$table}_unavailable TO $table");$wpdb->suppress_errors(false);}
$_GET=array('workspace_task'=>'overdue');wp_set_current_user(0);$check(is_wp_error(Overview::snapshot()),'Anonymous overview access is denied');wp_set_current_user($admin);
// Local-day boundaries must be converted to UTC rather than using server midnight.
$old_zone=get_option('timezone_string');update_option('timezone_string','Asia/Riyadh');
$midnight=current_datetime()->setTime(0,0)->setTimezone(new DateTimeZone('UTC'));
foreach(array(-1,0)as$offset){$insert('leads',array('customer_id'=>$c,'branch_id'=>$a['id'],'owner_user_id'=>$sales,'lost_reason'=>'','next_action_at'=>$future,'created_at'=>$midnight->modify(($offset>=0?'+':'').$offset.' seconds')->format('Y-m-d H:i:s'),'updated_at'=>$now));}
wp_set_current_user($sales);$_GET=array('workspace_task'=>'new_leads','workspace_period'=>'today');$local=Overview::snapshot();$check(26===$local['queues']['new_leads']['count'],'Local day includes exact Riyadh midnight and excludes the second before it');
update_option('timezone_string',$old_zone);wp_set_current_user($admin);
wp_set_current_user($sales);$_GET=array('workspace_task'=>'overdue','workspace_search'=>'Overview');$searched=Overview::snapshot();
$check(25===$searched['queues']['overdue']['count']&&20===count($searched['rows'])&&$searched['rows'][0]['owner_id']==$sales&&$searched['rows'][0]['customer_id']==$c,'Moved follow-up list preserves name search, responsible owner and customer context');
$_GET['workspace_page']=2;$check(5===count(Overview::snapshot()['rows']),'Follow-up name search reaches the remaining page');
ob_start();WorkspaceOverviewView::render();$searched_html=ob_get_clean();
$check(str_contains($searched_html,'workspace_search=Overview')&&str_contains($searched_html,'name="workspace_search"')&&str_contains($searched_html,'customer_id='.$c),'Search persists in navigation and customer workspace links remain available');
foreach(array('FOREIGN_CUSTOMER_SECRET',"' OR 1=1 --",'Overview%_')as$needle){$_GET=array('workspace_task'=>'overdue','workspace_search'=>$needle);$result=Overview::snapshot();$check(0===$result['queues']['overdue']['count']&&!$result['rows'],'Follow-up search preserves branch/owner restrictions and literal matching: '.$needle);}
wp_set_current_user($admin);$_GET=array();
require __DIR__.'/admin-structure-scenarios.php';
require __DIR__.'/workspace-overview-http.php';
echo "PASS: $checks isolated workspace overview checks; maximum observed snapshot queries: $query_count.\n";
