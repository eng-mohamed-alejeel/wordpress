<?php
/** Actual nonce-protected admin forms, using the disposable WordPress HTTP router. */
if(PHP_SAPI!=='cli'||!defined('DB_NAME')||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Database\Schema;
wp_set_current_user($admin);
$http_vehicle=VehicleService::create(array('vin'=>'TESTJRNY000000002','stock_number'=>'JOURNEY-HTTP','brand'=>'Test','model'=>'HTTP journey','model_year'=>2026,'condition'=>'new','branch_id'=>$branch['id'],'retail_price'=>10000000,'purchase_cost'=>9000000));
$wpdb->update(Schema::table('vehicles'),array('status'=>'available'),array('id'=>$http_vehicle['id']));
$user=new WP_User($sales);foreach(array('adc_create_quotes','adc_request_discounts','adc_start_sales')as$cap){$user->add_cap($cap);}
update_user_meta($sales,'locale','en_US');update_option('active_plugins',array('auto-dealership-core/auto-dealership-core.php'));
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($listener,false);fclose($listener);$port=(int)substr($address,strrpos($address,':')+1);
$log=tempnam(sys_get_temp_dir(),'adc-sales-http-');$process=null;
try{
	$process=proc_open(array(PHP_BINARY,'-d','output_buffering=0','-S','127.0.0.1:'.$port,__DIR__.'/http-router.php'),array(0=>array('pipe','r'),1=>array('file',$log,'a'),2=>array('file',$log,'a')),$pipes,ABSPATH,array_merge(getenv(),array('ADC_HTTP_TEST_CONFIG'=>ADC_TEST_HTTP_CONFIG)));
	if(!is_resource($process)){throw new RuntimeException('Cannot start HTTP form server');}fclose($pipes[0]);
	for($attempt=0;$attempt<50;++$attempt){$socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.1);if(is_resource($socket)){fclose($socket);break;}usleep(100000);}
	$request=static function(string$path,string$cookie='',?array$form=null)use($port):array{$curl=curl_init('http://127.0.0.1:'.$port.$path);$headers=array('Host: adc-verification.invalid');if($cookie){$headers[]='Cookie: '.$cookie;}$opts=array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false);if(null!==$form){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=http_build_query($form);}curl_setopt_array($curl,$opts);$response=curl_exec($curl);if(false===$response){throw new RuntimeException('HTTP request failed');}$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$size=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);return array($status,substr($response,$size),substr($response,0,$size));};
	$auth=static function(int$actor)use($request):string{$r=$request('/adc-test-session?user_id='.$actor.'&key='.hash('sha256',ADC_TEST_HTTP_CONFIG.'|session'));preg_match_all('/^Set-Cookie:\s*([^=;\r\n]+)=([^;\r\n]*)/mi',$r[2],$matches,PREG_SET_ORDER);$cookies=array();foreach($matches as$m){$cookies[]=$m[1].'='.$m[2];}if($r[0]!==200||!$cookies){throw new RuntimeException('Missing session');}return implode('; ',$cookies);};
	$sales_cookie=$auth($sales);$finance_cookie=$auth($finance);$foreign_cookie=$auth($other);
	$post=static function(string$cookie,string$operation,array$fields,bool$valid_nonce=true)use($request):array{$nonce=$request('/adc-test-nonce?action=adc_sales_journey_'.$operation,$cookie)[1];return$request('/wp-admin/admin-post.php',$cookie,array_merge($fields,array('action'=>'adc_sales_journey','operation'=>$operation,'adc_money_unit'=>'SAR','_wpnonce'=>$valid_nonce?trim($nonce):'invalid')));};
	$fields=array('customer_id'=>$customer,'vehicle_id'=>$http_vehicle['id'],'valid_until'=>$valid);
	$r=$request('/wp-admin/admin.php?page=adc-sales-journey',$sales_cookie);$check($r[0]===200&&str_contains($r[1],'Preview price and tax'),'Authenticated English staff screen renders creation form');
	$r=$post($sales_cookie,'preview',$fields,false);$check($r[0]===403,'Quotation preview rejects invalid action nonce');
	$r=$post($finance_cookie,'preview',$fields);$check($r[0]===403,'Finance role cannot preview a new sales quotation');
	$r=$post($foreign_cookie,'preview',$fields);$check($r[0]===403,'Another branch cannot preview customer and vehicle');
	$r=$post($sales_cookie,'preview',$fields);$check($r[0]===200&&str_contains($r[1],'115000.00')&&str_contains($r[1],'15000.00'),'Actual preview shows exact SAR tax and total before save');
	$create=array();preg_match_all('/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/',$r[1],$hidden,PREG_SET_ORDER);foreach($hidden as$input){$create[$input[1]]=html_entity_decode($input[2],ENT_QUOTES,'UTF-8');}
	$r=$request('/wp-admin/admin-post.php',$sales_cookie,$create);$check($r[0]===302&&str_contains($r[2],'quote_id='),'Actual save form creates quote and redirects to its details');
	preg_match('/quote_id=(\d+)/',$r[2],$match);$http_quote=(int)($match[1]??0);
	$r=$request('/wp-admin/admin-post.php',$sales_cookie,$create);preg_match('/quote_id=(\d+)/',$r[2],$match);$check($r[0]===302&&(int)($match[1]??0)===$http_quote,'Repeated HTTP save redirects to the same quote');
	$r=$request('/wp-admin/admin.php?page=adc-sales-journey&quote_id='.$http_quote,$sales_cookie);$check($r[0]===200&&str_contains($r[1],'Reserve this quotation'),'Actual quote detail exposes the next reservation action');
	$key=wp_generate_uuid4();$r=$post($sales_cookie,'reserve',array('quote_id'=>$http_quote,'idempotency_key'=>$key));$check($r[0]===302,'Actual reservation form creates commercial reservation');
	$reservation_id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Schema::table('reservations').' WHERE idempotency_key=%s',$key));
	$r=$post($sales_cookie,'reserve',array('quote_id'=>$http_quote,'idempotency_key'=>$key));$check($r[0]===302&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Schema::table('reservations').' WHERE idempotency_key=%s',$key))===1,'Repeated reservation form preserves one reservation');
	$r=$post($sales_cookie,'sale',array('quote_id'=>$http_quote,'reservation_id'=>$reservation_id));$check($r[0]===302,'Actual start sale form creates pending approval sale');
	$r=$post($sales_cookie,'sale',array('quote_id'=>$http_quote,'reservation_id'=>$reservation_id));$check($r[0]===409&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Schema::table('sales').' WHERE quotation_id=%d',$http_quote))===1,'Repeated HTTP start sale creates no duplicate');
	$r=$request('/wp-admin/admin.php?page=adc-customer-workspace&customer_id='.$customer,$sales_cookie);$check($r[0]===200&&str_contains($r[1],'Journey customer')&&str_contains($r[1],'Customer workspace'),'Actual customer workspace loads linked records');
	$r=$request('/wp-admin/admin.php?page=adc-customer-workspace&customer_id='.$customer,$foreign_cookie);$check($r[0]===404,'Direct customer URL rejects foreign branch');
	$check(!str_contains($r[1],'Journey customer')&&!str_contains($r[1],'section-quotations'),'Foreign customer response exposes neither identity nor record sections');
	$r=$request('/wp-admin/admin.php?page=adc-customer-workspace&customer_id='.PHP_INT_MAX,$sales_cookie);$check($r[0]===404,'Nonexistent customer returns 404 before the administration header');
	$r=$post($sales_cookie,'discount',array('quote_id'=>$http_quote,'amount'=>'1.001','reason'=>'Invalid precision'));$check($r[0]===400,'Actual money form rejects amounts beyond two decimal places');
	update_user_meta($sales,'locale','ar');$r=$request('/wp-admin/admin.php?page=adc-sales-journey',$sales_cookie);$check($r[0]===200&&str_contains($r[1],'دورة المبيعات')&&str_contains($r[1],'معاينة السعر والضريبة'),'Actual Arabic staff screen renders localized creation form');
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_file($log)){unlink($log);}}
