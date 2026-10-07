<?php
/** CLI-only short-lived browser authentication and reversible settings fixture. */
if(PHP_SAPI!=='cli')exit;
define('DISABLE_WP_CRON',true);require dirname(__DIR__,4).'/wp-load.php';
use AutoDealership\Tools\FinanceConfiguration as Config;
$path=ABSPATH.'.tmp/finance-admin-browser-session.json';
if(untrailingslashit(get_option('siteurl'))!=='http://localhost/wordpress')exit(2);
if(($argv[1]??'')==='--prepare') {
 if(is_file($path))throw new RuntimeException('Clean the earlier browser session first.');
 $ids=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);$id=(int)$ids[0];$expiry=time()+900;$token=WP_Session_Tokens::get_instance($id)->create($expiry);
 $data=['id'=>$id,'token'=>$token,'option'=>get_option(Config::OPTION,false),'cookies'=>[['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$expiry,'auth',$token)],['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($id,$expiry,'logged_in',$token)]]];
 file_put_contents($path,wp_json_encode($data));echo "Temporary local browser session prepared.\n";
}elseif(($argv[1]??'')==='--cleanup') {
 if(!is_file($path))exit;$data=json_decode(file_get_contents($path),true);WP_Session_Tokens::get_instance($data['id'])->destroy($data['token']);if($data['option']===false)delete_option(Config::OPTION);else update_option(Config::OPTION,$data['option'],false);delete_transient('adc_finance_editor_'.$data['id']);unlink($path);echo "Browser session destroyed; original settings restored.\n";
}
