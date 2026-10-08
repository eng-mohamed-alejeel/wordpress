<?php
/** Authenticated production admin-post handlers against disposable records only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Database\Schema;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Reference\ReferenceService;
use AutoDealership\Inventory\VehicleService;
wp_set_current_user($admin);
$foreign = wp_insert_user(array('user_login'=>'queue_foreign_inventory','user_pass'=>wp_generate_password(),'role'=>'dealership_inventory'));
ConfigurationService::assign_branches($foreign,$b['id'],array($b['id']));
$brand=ReferenceService::create_brand(array('key'=>'queue-http','name_ar'=>'HTTP fixture','name_en'=>'HTTP fixture'));
$location=ReferenceService::create_location(array('branch_id'=>$a['id'],'code'=>'HTTP-A','name'=>'HTTP yard','type'=>'yard'));
$location2=ReferenceService::create_location(array('branch_id'=>$a['id'],'code'=>'HTTP-A2','name'=>'HTTP warehouse','type'=>'warehouse'));
$vehicle=VehicleService::create(array('vin'=>'1M8GDM9AXKP880001','stock_number'=>'HTTP-INTAKE','brand'=>'HTTP fixture','brand_id'=>$brand['id'],'model'=>'HTTP model','model_year'=>2026,'condition'=>'new','branch_id'=>$a['id'],'location_id'=>$location['id'],'retail_price'=>1000000));
$check(is_array($vehicle),'Create operational vehicle for admin form tests');$id=$vehicle['id'];
update_option('active_plugins',array('auto-dealership-core/auto-dealership-core.php'));
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($listener,false);fclose($listener);$port=(int)substr($address,strrpos($address,':')+1);
$log=tempnam(sys_get_temp_dir(),'adc-dash-http-');$process=null;
try {
 $process=proc_open(array(PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/http-router.php'),array(0=>array('pipe','r'),1=>array('file',$log,'a'),2=>array('file',$log,'a')),$pipes,ABSPATH,array_merge(getenv(),array('ADC_HTTP_TEST_CONFIG'=>ADC_TEST_HTTP_CONFIG)));
 if(!is_resource($process))throw new RuntimeException('Cannot start isolated form server');fclose($pipes[0]);
 for($attempt=0;$attempt<50;++$attempt){$socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.1);if(is_resource($socket)){fclose($socket);break;}usleep(100000);}
 $request=static function(string $path,string $cookie='',?array $form=null)use($port):array{
  $curl=curl_init('http://127.0.0.1:'.$port.$path);$headers=array('Host: adc-verification.invalid');if($cookie)$headers[]='Cookie: '.$cookie;
  $opts=array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false);
  if(null!==$form){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=http_build_query($form);}curl_setopt_array($curl,$opts);$response=curl_exec($curl);if(false===$response)throw new RuntimeException('HTTP form request failed');$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$size=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);return array($status,substr($response,$size),substr($response,0,$size));
 };
 $auth=static function(int $actor)use($request):string{$r=$request('/adc-test-session?user_id='.$actor.'&key='.hash('sha256',ADC_TEST_HTTP_CONFIG.'|session'));preg_match_all('/^Set-Cookie:\s*([^=;\r\n]+)=([^;\r\n]*)/mi',$r[2],$matches,PREG_SET_ORDER);$cookies=array();foreach($matches as$m)$cookies[]=$m[1].'='.$m[2];if($r[0]!==200||!$cookies)throw new RuntimeException('No test session');return implode('; ',$cookies);};
 $cookies=array('admin'=>$auth($admin),'inventory'=>$auth($inventory),'foreign'=>$auth($foreign),'finance'=>$auth($finance));
 $post=static function(string $actor,string $action,array $fields,bool $valid=true)use($cookies,$request):array{$cookie=$cookies[$actor];$nonce=$request('/adc-test-nonce?action='.rawurlencode($action.'_'.$fields['id']),$cookie)[1];return $request('/wp-admin/admin-post.php',$cookie,array_merge($fields,array('action'=>$action,'_wpnonce'=>$valid?trim($nonce):'invalid')));};
 $receipts=Schema::table('vehicle_receipts');$vehicles=Schema::table('vehicles');
 $receiptCount=static fn():int=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $receipts WHERE vehicle_id=%d",$id));
 $fields=array('id'=>$id,'condition'=>'good','document_reference'=>'HTTP-RECEIPT','odometer'=>10);
 $r=$post('inventory','adc_record_vehicle_receipt',$fields,false);$check($r[0]===403&&$receiptCount()===0,'Invalid receipt nonce leaves no receipt');
 foreach(array('foreign','finance')as$actor){$r=$post($actor,'adc_record_vehicle_receipt',$fields);$check(str_contains($r[2],'error=1')&&$receiptCount()===0,"$actor cannot record receipt outside scope or role");}
 $r=$post('inventory','adc_record_vehicle_receipt',$fields);$check($r[0]===302&&str_contains($r[2],'section=receipt')&&$receiptCount()===1,'Inventory receipt form persists evidence and returns to receipt tab');
 $r=$post('inventory','adc_transition_vehicle',array('id'=>$id,'status'=>'inspection','reason'=>'HTTP inspection stage'));$check($r[0]===302&&'inspection'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$id)),'Inventory form transitions a received vehicle to inspection');
 $r=$post('inventory','adc_record_vehicle_inspection',array('id'=>$id,'checklist'=>array_fill_keys(array('exterior','interior','engine','tires','vin'),'pass')));
 $check($r[0]===302&&str_contains($r[2],'section=inspection')&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Schema::table('vehicle_inspections').' WHERE vehicle_id=%d',$id))===1,'Inspection form saves checklist and returns to inspection tab');
 $move=array('id'=>$id,'location_id'=>$location2['id'],'reason'=>'HTTP physical move');$r=$post('foreign','adc_move_vehicle_location',$move);$check(str_contains($r[2],'error=1')&&(int)$wpdb->get_var($wpdb->prepare("SELECT location_id FROM $vehicles WHERE id=%d",$id))===$location['id'],'Foreign branch cannot move the vehicle');
 $r=$post('inventory','adc_move_vehicle_location',$move);$check($r[0]===302&&str_contains($r[2],'section=locations')&&(int)$wpdb->get_var($wpdb->prepare("SELECT location_id FROM $vehicles WHERE id=%d",$id))===$location2['id'],'Location form saves movement and returns to locations tab');
 $vin=array('id'=>$id,'vin'=>'1M8GDM9AXKP880002','reason'=>'HTTP documented correction');$r=$post('inventory','adc_change_vehicle_vin',$vin);$check(str_contains($r[2],'error=1')&&'1M8GDM9AXKP880001'===$wpdb->get_var($wpdb->prepare("SELECT vin FROM $vehicles WHERE id=%d",$id)),'Inventory role cannot perform elevated VIN correction');
 $r=$post('admin','adc_change_vehicle_vin',$vin);$check($r[0]===302&&str_contains($r[2],'page=adc-vehicle-vin')&&$vin['vin']===$wpdb->get_var($wpdb->prepare("SELECT vin FROM $vehicles WHERE id=%d",$id)),'Elevated VIN form saves correction and returns to its dedicated page');
 $check(''===$wpdb->last_error,'HTTP form fixtures finish without database errors');
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_file($log))unlink($log);}
