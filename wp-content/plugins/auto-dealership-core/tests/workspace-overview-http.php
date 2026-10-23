<?php
/** Session-authenticated GET acceptance on a separate loopback HTTP process. */
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
update_option('active_plugins',array('auto-dealership-core/auto-dealership-core.php'));
update_user_meta($admin,'locale','ar');update_user_meta($sales,'locale','en_US');
$foreign_document=\AutoDealership\Sales\OnyxDocuments::attach($sb,'invoice','FOREIGN-DOCUMENT-SECRET',gmdate('Y-m-d'),100000,0,"%PDF-1.4\nFOREIGN_DOCUMENT_BYTES_SECRET",true);
$check(is_array($foreign_document),'Create a foreign-branch document to verify actual archive isolation');
$local_document_bytes="%PDF-1.4\nLOCAL_DOCUMENT_BYTES_SECRET";
$local_document=\AutoDealership\Sales\OnyxDocuments::attach($sa,'contract','LOCAL-DOCUMENT-SECRET',gmdate('Y-m-d'),100000,0,$local_document_bytes,true);
$check(is_array($local_document),'Create an assigned-branch document to verify authorized HTTP download');
$document_table=\AutoDealership\Database\Schema::table('onyx_documents');
$document_before=$wpdb->get_row($wpdb->prepare("SELECT * FROM $document_table WHERE id=%d",$foreign_document['id']),ARRAY_A);
$foreign_sale_before=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.\AutoDealership\Database\Schema::table('sales').' WHERE id=%d',$sb),ARRAY_A);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket){throw new RuntimeException('HTTP socket unavailable');}$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'adc-workspace-http-');$env=getenv();$env['ADC_HTTP_TEST_CONFIG']=ADC_TEST_HTTP_CONFIG;$env['ADC_THEME_TEST']='1';
$process=proc_open(array(PHP_BINARY,'-d','output_buffering=0','-S',$address,__DIR__.'/http-router.php'),array(0=>array('pipe','r'),1=>array('file',$log,'a'),2=>array('file',$log,'a')),$pipes,ABSPATH,$env,array('bypass_shell'=>true));
if(!is_resource($process)){throw new RuntimeException('Cannot start workspace test server');}fclose($pipes[0]);
$key=hash('sha256',ADC_TEST_HTTP_CONFIG.'|session');$base='http://'.$address;
$http_completed=false;
try{
 for($i=0;$i<50;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);if($ready){fclose($ready);break;}usleep(100000);}
 $get=static function($path,$cookie='')use($base){$body=file_get_contents($base.$path,false,stream_context_create(array('http'=>array('ignore_errors'=>true,'timeout'=>15,'header'=>$cookie?'Cookie: '.$cookie:''))));$headers=$http_response_header??array();return array($body,$headers);};
 $session=static function($user)use($get,$key){list($body,$headers)=$get('/adc-test-session?'.http_build_query(array('key'=>$key,'user_id'=>$user)));$cookies=array();foreach($headers as$header){if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$header,$match)){$cookies[]=$match[1];}}return implode('; ',$cookies);};
 $sales_cookie=$session($sales);$finance_cookie=$session($finance);$admin_cookie=$session($admin);$manager_cookie=$session($manager);
 $download_nonce=static function($user_cookie,$document_id)use($get){list($nonce,$headers)=$get('/adc-test-nonce?action=adc_download_onyx_document_'.$document_id,$user_cookie);return array(trim($nonce),$headers);};
 $download_path=static function($document_id,$nonce){return '/wp-admin/admin-post.php?'.http_build_query(array('action'=>'adc_download_onyx_document','id'=>$document_id,'_wpnonce'=>$nonce));};
 $upload_temp=tempnam(sys_get_temp_dir(),'adc-onyx-upload-');
 if(false===$upload_temp||false===file_put_contents($upload_temp,"%PDF-1.4\nHTTP_UPLOAD_DOCUMENT")){throw new RuntimeException('Could not create synthetic Onyx upload fixture.');}
 $upload=static function($cookie,$fields,$file_path)use($base){
  $fields['document']=new CURLFile($file_path,'application/pdf','verified-contract.pdf');
  $handle=curl_init($base.'/wp-admin/admin-post.php');curl_setopt_array($handle,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_COOKIE=>$cookie,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false));
  $response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$header_size=(int)curl_getinfo($handle,CURLINFO_HEADER_SIZE);$error=curl_error($handle);curl_close($handle);
  if(false===$response){throw new RuntimeException('Onyx multipart upload HTTP request failed: '.$error);}
  return array(substr($response,0,$header_size),substr($response,$header_size),$status);
 };
 foreach(array('workspace_branch='.$b['id'],'workspace_branch[]=1')as$filter){
  list($html,$headers)=$get('/?cd_account=dashboard&account_context=work&'.$filter,$sales_cookie);
  $check(str_contains($headers[0]??'','403')&&!str_contains($html,'FOREIGN_CUSTOMER_SECRET')&&!str_contains($html,'adc-work-indicator'),'Frontend branch denial occurs before output and reveals no work records: '.$filter);
 }
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-workspace&workspace_task=overdue',$sales_cookie);
 $check(str_contains($headers[0]??'','200')&&str_contains($html,'Operational overview')&&!str_contains($html,'FOREIGN_CUSTOMER_SECRET'),'HTTP sales workspace renders scoped English overview');
 $check(!str_contains($html,'Payments awaiting your review')&&!str_contains($html,'page=adc-profit-margin'),'HTTP sales page omits financial work and margin settings');
 list($html)=$get('/wp-admin/admin.php?page=adc-workspace&workspace_task=payments',$finance_cookie);$check(str_contains($html,'payment_id='.$payment_ids[0]),'Payment task opens the exact permitted record');
 list($html)=$get('/wp-admin/admin.php?page=adc-onyx-documents',$finance_cookie);$check(str_contains($html,'document_search')&&!str_contains($html,'FOREIGN_CUSTOMER_SECRET'),'Existing document URL now opens a searchable scoped sales index');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-profit-margin',$admin_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'adc_profit_margin'),'Administrator can open the existing margin screen and its save form');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sa,$finance_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'Back to sales')&&!str_contains($html,'name="action" value="adc_onyx_document"'),'Finance reviewer can read the archive without acquiring upload permission');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sa,$admin_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'name="action" value="adc_onyx_document"'),'Administrator can open the existing document upload form');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-profit-margin',$sales_cookie);$check(str_contains($headers[0]??'','403'),'Direct margin URL is forbidden to sales');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sb,$finance_cookie);$check(str_contains($headers[0]??'','404'),'Direct foreign-sale document URL is denied; received '.($headers[0]??'no HTTP status'));
 $check(!str_contains($html,'FOREIGN_CUSTOMER_SECRET')&&!str_contains($html,'FOREIGN-DOCUMENT-SECRET')&&!str_contains($html,'FOREIGN_DOCUMENT_BYTES_SECRET')&&!str_contains($html,'name="action" value="adc_onyx_document"')&&!str_contains($html,'Back to sales'),'Denied document response contains no foreign data, sale page or upload form');
 $check($document_before===$wpdb->get_row($wpdb->prepare("SELECT * FROM $document_table WHERE id=%d",$foreign_document['id']),ARRAY_A)&&$foreign_sale_before===$wpdb->get_row($wpdb->prepare('SELECT * FROM '.\AutoDealership\Database\Schema::table('sales').' WHERE id=%d',$sb),ARRAY_A),'Denied document GET leaves the document bytes, metadata and sale unchanged');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id='.$sb,$admin_cookie);$check(str_contains($headers[0]??'','200')&&str_contains($html,'FOREIGN-DOCUMENT-SECRET'),'Administrator retains authorized access to the same archived document');
 list($local_nonce,$local_nonce_headers)=$download_nonce($finance_cookie,$local_document['id']);
 list($downloaded_bytes,$download_headers)=$get($download_path($local_document['id'],$local_nonce),$finance_cookie);
 $check(str_contains($local_nonce_headers[0]??'','200')&&str_contains($download_headers[0]??'','200')&&str_contains(implode("\n",$download_headers),'application/pdf')&&str_contains(implode("\n",$download_headers),'attachment;')&&$downloaded_bytes===$local_document_bytes,'Same-branch finance reviewer downloads the exact archived PDF through the protected admin-post action');
 list($invalid_bytes,$invalid_headers)=$get($download_path($local_document['id'],'invalid'),$finance_cookie);
 $check(str_contains($invalid_headers[0]??'','403')&&!str_contains($invalid_bytes,'LOCAL_DOCUMENT_BYTES_SECRET'),'Onyx document download rejects an invalid action nonce without revealing file bytes');
 list($sales_nonce)=$download_nonce($sales_cookie,$local_document['id']);
 list($denied_bytes,$denied_headers)=$get($download_path($local_document['id'],$sales_nonce),$sales_cookie);
 $check(str_contains($denied_headers[0]??'','404')&&!str_contains($denied_bytes,'LOCAL_DOCUMENT_BYTES_SECRET'),'Authenticated same-branch sales user without document capability cannot download a PDF');
 list($foreign_nonce)=$download_nonce($finance_cookie,$foreign_document['id']);
 list($foreign_bytes,$foreign_headers)=$get($download_path($foreign_document['id'],$foreign_nonce),$finance_cookie);
 $check(str_contains($foreign_headers[0]??'','404')&&!str_contains($foreign_bytes,'FOREIGN_DOCUMENT_BYTES_SECRET'),'Finance reviewer cannot download an archived PDF from another branch with a valid nonce');
 list($admin_foreign_nonce)=$download_nonce($admin_cookie,$foreign_document['id']);
 list($admin_foreign_bytes,$admin_foreign_headers)=$get($download_path($foreign_document['id'],$admin_foreign_nonce),$admin_cookie);
 $check(str_contains($admin_foreign_headers[0]??'','200')&&$admin_foreign_bytes==="%PDF-1.4\nFOREIGN_DOCUMENT_BYTES_SECRET",'Global administrator retains authorized cross-branch document download');
 $upload_nonce_path='/adc-test-nonce?action=adc_onyx_document_'.$sa;
 list($manager_upload_nonce)=$get($upload_nonce_path,$manager_cookie);
 $upload_form=array('action'=>'adc_onyx_document','sale_id'=>$sa,'kind'=>'contract','reference'=>'HTTP-ONYX-UPLOAD-SAME','date'=>gmdate('Y-m-d'),'total'=>'1000.00','tax'=>'0.00','verified'=>'1','parent_id'=>'0','_wpnonce'=>trim($manager_upload_nonce));
 list($upload_headers,$upload_body,$upload_status)=$upload($manager_cookie,$upload_form,$upload_temp);
 $uploaded_document=$wpdb->get_row($wpdb->prepare("SELECT * FROM $document_table WHERE sale_id=%d AND reference=%s",$sa,'HTTP-ONYX-UPLOAD-SAME'),ARRAY_A);
 $check($upload_status===302&&str_contains($upload_headers,'saved=1')&&is_array($uploaded_document)&&$uploaded_document['file_bytes']==="%PDF-1.4\nHTTP_UPLOAD_DOCUMENT",'Authorized sales approver uploads and archives a verified PDF through the multipart admin-post action');
 $sales_upload_form=array_merge($upload_form,array('reference'=>'HTTP-ONYX-UPLOAD-DENIED','_wpnonce'=>trim($get($upload_nonce_path,$sales_cookie)[0])));
 list($sales_upload_headers,$sales_upload_body,$sales_upload_status)=$upload($sales_cookie,$sales_upload_form,$upload_temp);
 $check($sales_upload_status===302&&str_contains($sales_upload_headers,'error=adc_document_forbidden')&&null===$wpdb->get_var($wpdb->prepare("SELECT id FROM $document_table WHERE sale_id=%d AND reference=%s",$sa,'HTTP-ONYX-UPLOAD-DENIED')),'Same-branch sales user without document-verification capability cannot archive a PDF');
 $foreign_upload_form=array_merge($upload_form,array('sale_id'=>$sb,'reference'=>'HTTP-ONYX-UPLOAD-FOREIGN','_wpnonce'=>trim($get('/adc-test-nonce?action=adc_onyx_document_'.$sb,$manager_cookie)[0])));
 list($foreign_upload_headers,$foreign_upload_body,$foreign_upload_status)=$upload($manager_cookie,$foreign_upload_form,$upload_temp);
 $check($foreign_upload_status===302&&str_contains($foreign_upload_headers,'error=adc_document_forbidden')&&null===$wpdb->get_var($wpdb->prepare("SELECT id FROM $document_table WHERE sale_id=%d AND reference=%s",$sb,'HTTP-ONYX-UPLOAD-FOREIGN')),'Sales approver cannot archive a document against a foreign-branch sale');
 $invalid_upload_form=array_merge($upload_form,array('reference'=>'HTTP-ONYX-UPLOAD-BAD-NONCE','_wpnonce'=>'invalid'));
 list($invalid_upload_headers,$invalid_upload_body,$invalid_upload_status)=$upload($manager_cookie,$invalid_upload_form,$upload_temp);
 $check($invalid_upload_status===403&&null===$wpdb->get_var($wpdb->prepare("SELECT id FROM $document_table WHERE sale_id=%d AND reference=%s",$sa,'HTTP-ONYX-UPLOAD-BAD-NONCE')),'Onyx document upload rejects an invalid nonce before archiving');
 list($html,$headers)=$get('/wp-admin/admin.php?page=adc-onyx-documents&sale_id=2147483647',$finance_cookie);$check(str_contains($headers[0]??'','404'),'Nonexistent sale uses the same not-found response');
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
 $http_completed=true;
}finally{proc_terminate($process);proc_close($process);if(isset($upload_temp)&&is_string($upload_temp)&&is_file($upload_temp)){unlink($upload_temp);}if(is_file($log)){if($http_completed){unlink($log);}else{fwrite(STDERR,"Workspace HTTP failure log: $log\n");}}}
