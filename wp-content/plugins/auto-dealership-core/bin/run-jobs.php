<?php
/** Host scheduler entry point. HTTP invocation is intentionally forbidden. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=realpath($argv[1]??dirname(__DIR__,4));if(!$root||!is_file($root.'/wp-load.php')){fwrite(STDERR,"WordPress root not found. Pass its absolute path as the first argument.\n");exit(2);}
define('DISABLE_WP_CRON',true);define('DOING_CRON',true);require $root.'/wp-load.php';
if(!class_exists('AutoDealership\\Operations\\JobRuntime')){fwrite(STDERR,"Activate Auto Dealership Core first.\n");exit(2);}
$result=in_array('--status',$argv,true)?\AutoDealership\Operations\JobRuntime::status():\AutoDealership\Operations\JobRuntime::run_due('cli');
echo wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit(in_array($result['status']??'ok',array('blocked','failed'),true)?1:0);
