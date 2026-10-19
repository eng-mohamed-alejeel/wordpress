<?php
/** Session-authenticated GET acceptance on a separate loopback HTTP process. */
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
update_option('active_plugins',array('auto-dealership-core/auto-dealership-core.php'));
update_user_meta($admin,'locale','ar');update_user_meta($sales,'locale','en_US');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket){throw new RuntimeException('HTTP socket unavailable');}$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'adc-workspace-http-');$env=getenv();$env['ADC_HTTP_TEST_CONFIG']=ADC_TEST_HTTP_CONFIG;$env['ADC_THEME_TEST']='1';
$process=proc_open(array(PHP_BINARY,'-S',$address,__DIR__.'/http-router.php'),array(0=>array('pipe','r'),1=>array('file',$log,'a'),2=>array('file',$log,'a')),$pipes,ABSPATH,$env,array('bypass_shell'=>true));
if(!is_resource($process)){throw new RuntimeException('Cannot start workspace test server');}fclose($pipes[0]);
$key=hash('sha256',ADC_TEST_HTTP_CONFIG.'|session');$base='http://'.$address;
try{
 for($i=0;$i<50;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);if($ready){fclose($ready);break;}usleep(100000);}
 $get=static function($path,$cookie='')use($base){$body=file_get_contents($base.$path,false,stream_context_create(array('http'=>array('ignore_errors'=>true,'timeout'=>15,'header'=>$cookie?'Cookie: '.$cookie:''))));$headers=$http_response_header??array();return array($body,$headers);};
 $session=static function($user)use($get,$key){list($body,$headers)=$get('/adc-test-session?'.http_build_query(array('key'=>$key,'user_id'=>$user)));$cookies=array();foreach($headers as$header){if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$header,$match)){$cookies[]=$match[1];}}return implode('; ',$cookies);};
 $sales_cookie=$session($sales);$finance_cookie=$session($finance);$admin_cookie=$session($admin);
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-workspace&workspace_task=overdue',$sales_cookie);
 $check(str_contains($headers[0]??'','200')&&str_contains($html,'Operational overview')&&!str_contains($html,'FOREIGN_CUSTOMER_SECRET'),'HTTP sales workspace renders scoped English overview');
 $check(!str_contains($html,'Payments awaiting your review')&&!str_contains($html,'page=adc-profit-margin'),'HTTP sales page omits financial work and margin settings');
 list($html)=$get('/wp-admin/admin.php?page=adc-workspace&workspace_task=payments',$finance_cookie);$check(str_contains($html,'payment_id='.$payment_ids[0]),'Payment task opens the exact permitted record');
 list($html)=$get('/wp-admin/admin.php?page=adc-onyx-documents',$finance_cookie);$check(str_contains($html,'document_search')&&!str_contains($html,'FOREIGN_CUSTOMER_SECRET'),'Existing document URL now opens a searchable scoped sales index');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-profit-margin',$admin_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'adc_profit_margin'),'Administrator can open the existing margin screen and its save form');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sa,$finance_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'Back to sales')&&!str_contains($html,'name="action" value="adc_onyx_document"'),'Finance reviewer can read the archive without acquiring upload permission');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sa,$admin_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'name="action" value="adc_onyx_document"'),'Administrator can open the existing document upload form');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-profit-margin',$sales_cookie);$check(str_contains($headers[0]??'','403'),'Direct margin URL is forbidden to sales');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sb,$finance_cookie);$check(str_contains($headers[0]??'','404'),'Direct foreign-sale document URL is denied');
 list($html)=$get('/wp-admin/admin.php?page=adc-approvals&section=discounts&discount_id='.$discount_ids[0],$admin_cookie);$dom=new DOMDocument();@$dom->loadHTML($html);$check((new DOMXPath($dom))->query('//table/tbody/tr')->length===1,'Discount drill-down selects a single scoped request');
 list($html)=$get('/wp-admin/admin.php?page=adc-finance&finance_id='.$finance_id,$finance_cookie);$check(str_contains($html,'Synthetic provider')&&!str_contains($html,'Own decision excluded'),'Finance drill-down selects the requested record');
 list($html)=$get('/wp-admin/admin.php?page=adc-payments&deposit_id='.$deposit,$finance_cookie);$check(str_contains($html,'OV-D-1'),'Deposit drill-down selects the requested deposit');
 foreach(array('adc-contact','adc-social-channels','adc-branch-locations','adc-platform-setup','adc-editorial-setup')as$settings_page){
  list($html,$headers)=$get('/wp-admin/admin.php?page='.$settings_page,$sales_cookie);
  $check(str_contains($headers[0]??'','403'),'Consolidated settings rejects direct sales access: '.$settings_page);
 }
 $raw=static function($path,$cookie,$form=null)use($base){
  $options=array('ignore_errors'=>true,'follow_location'=>0,'timeout'=>20,'header'=>'Cookie: '.$cookie);
  if(null!==$form){$options['method']='POST';$options['header'].="\r\nContent-Type: application/x-www-form-urlencoded";$options['content']=http_build_query($form);}
  $body=file_get_contents($base.$path,false,stream_context_create(array('http'=>$options)));return array($body,$http_response_header??array());
 };
 foreach(array(
  '/wp-admin/options-general.php?page=adc-platform-setup&section=appearance'=>'page=adc-contact',
  '/wp-admin/admin.php?page=adc-platform-setup&section=policy'=>'page=adc-settings',
  '/wp-admin/admin.php?page=adc-platform-setup&section=pages'=>'page=adc-editorial-setup',
  '/wp-admin/admin.php?page=adc-site-content&tab=readiness'=>'section=readiness',
  '/wp-admin/admin.php?page=adc-transfer-queue&status=requested'=>'page=adc-transfers',
  '/wp-admin/admin.php?page=adc-follow-ups&search=Overview'=>'workspace_search=Overview',
 )as$path=>$destination){list($html,$headers)=$raw($path,$admin_cookie);$check(str_contains($headers[0]??'','302')&&str_contains(implode("\n",$headers),$destination),'Retired GET routes to owner: '.$destination);}
 list($html)=$get('/wp-admin/admin.php?page=adc-contact',$admin_cookie);
 $dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);$nonce=$xpath->query('//input[@name="_wpnonce"]')->item(0)->getAttribute('value');
 $theme_before=get_option('car_dealer_theme_settings',array());
 $form=array('action'=>'adc_platform_setup','section'=>'appearance','operation'=>'save','_wpnonce'=>$nonce,'settings'=>array('phone'=>'+966500000002','email'=>'saved@example.invalid','address'=>'Synthetic contact','address_en'=>'Synthetic English contact'));
 list($html,$headers)=$raw('/wp-admin/admin-post.php',$admin_cookie,$form);
 wp_cache_delete('car_dealer_theme_settings','options');wp_cache_delete('alloptions','options');
 $saved=get_option('car_dealer_theme_settings',array());
 $check(str_contains($headers[0]??'','302')&&str_contains(implode("\n",$headers),'page=adc-contact')&&($saved['phone']??'')==='+966500000002','Actual nonce-protected contact POST saves and returns to canonical screen');
 $form['_wpnonce']='invalid';$form['settings']['phone']='FORBIDDEN';list($html,$headers)=$raw('/wp-admin/admin-post.php',$admin_cookie,$form);
 wp_cache_delete('car_dealer_theme_settings','options');wp_cache_delete('alloptions','options');
 $check(str_contains($headers[0]??'','403')&&get_option('car_dealer_theme_settings')['phone']==='+966500000002','Invalid contact nonce cannot mutate saved details');
 update_option('car_dealer_theme_settings',$theme_before);
 if('1'===getenv('ADC_WORKSPACE_BROWSER')){
  $browser=proc_open(array('node',__DIR__.'/browser-workspace-overview.cjs'),array(0=>array('pipe','r'),1=>STDOUT,2=>STDERR),$bp,ABSPATH,null,array('bypass_shell'=>true));
  fwrite($bp[0],wp_json_encode(array('base'=>$base,'key'=>$key,'admin'=>$admin,'sales'=>$sales,'root'=>ABSPATH)));fclose($bp[0]);$check(proc_close($browser)===0,'Real browser Arabic/English desktop/mobile workspace checks pass');
 }
}finally{proc_terminate($process);proc_close($process);if(is_file($log)){unlink($log);}}
