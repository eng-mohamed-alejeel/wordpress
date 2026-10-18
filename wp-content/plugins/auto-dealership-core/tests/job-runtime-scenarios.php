<?php
/** Disposable database only. Mail and external HTTP are blocked by database-runner. */
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Operations\JobRuntime as Jobs;
use AutoDealership\Operations\OutboxService as Outbox;
global $wpdb;
$checks=0;$check=static function($ok,$label)use(&$checks){if(!$ok){throw new RuntimeException($label);}++$checks;echo "PASS $label\n";};
Jobs::wrap();update_option('cron',array());$table=Outbox::table();$delivered=0;$operated=0;
Outbox::register_handler('email.verify',static function()use(&$delivered){++$delivered;return true;});
Outbox::register_handler('test.background',static function()use(&$operated){++$operated;return true;});
$old=Outbox::enqueue('email.verify',array(),'runtime:old')['id'];$fresh=Outbox::enqueue('email.verify',array(),'runtime:fresh')['id'];$op=Outbox::enqueue('test.background',array(),'runtime:operation')['id'];
$wpdb->query($wpdb->prepare("UPDATE $table SET created_at=%s WHERE id IN (%d,%d)",gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS),$old,$op));
$summary=Outbox::process_due(20);
$check($delivered===1&&$operated===1&&$summary['completed']===2,'fresh mail and old business work run; old mail does not');
$row=$wpdb->get_row($wpdb->prepare("SELECT status,attempts FROM $table WHERE id=%d",$old),ARRAY_A);$check($row['status']==='pending'&&(int)$row['attempts']===0,'held mail row and attempts remain untouched');
$check(Jobs::status()['held_mail']===1,'held mail is separately visible in monitoring');
$wpdb->query($wpdb->prepare("UPDATE $table SET status='failed' WHERE id=%d",$old));$result=Outbox::retry_failed($old,'review old test');$check(is_wp_error($result)&&$result->get_error_code()==='adc_outbox_review_required','ordinary retry cannot bypass old-mail protection');$wpdb->query($wpdb->prepare("UPDATE $table SET status='pending' WHERE id=%d",$old));
$calls=0;Outbox::register_handler('test.budget',static function()use(&$calls){++$calls;usleep(80000);return true;});for($i=0;$i<3;$i++){Outbox::enqueue('test.budget',array(),'runtime:budget:'.$i);}$budget=static fn()=>.05;add_filter('adc_outbox_budget_seconds',$budget);$summary=Outbox::process_due(20);remove_filter('adc_outbox_budget_seconds',$budget);$check($calls===1&&$summary['completed']===1,'time budget stops before another expensive handler');
$now=time();$due=Jobs::due(array($now-10=>array('unknown.hook'=>array(array('args'=>array(),'schedule'=>false)),'adc_process_outbox'=>array(array('args'=>array(),'schedule'=>'hourly'))),$now+600=>array('adc_mail_wake'=>array(array('args'=>array(),'schedule'=>false)))),$now);
$check(count($due)===1&&$due[0]['hook']==='adc_process_outbox','only due allowlisted jobs can run');
wp_schedule_event($now-3600,'hourly','adc_process_outbox');wp_schedule_single_event($now-5,'adc_mail_wake');$unknown=0;add_action('unknown.hook',static function()use(&$unknown){++$unknown;});wp_schedule_single_event($now-5,'unknown.hook');
$method=new ReflectionMethod(Jobs::class,'lock_name');$method->setAccessible(true);$lock=$method->invoke(null);[$host,$port]=explode(':',DB_HOST);$other=new mysqli($host,DB_USER,DB_PASSWORD,DB_NAME,(int)$port);$escaped=$other->real_escape_string($lock);$other->query("SELECT GET_LOCK('$escaped',0)");$busy=Jobs::run_due('cli');$check($busy['status']==='busy','another database connection prevents overlapping workers');$other->query("SELECT RELEASE_LOCK('$escaped')");$other->close();
$result=Jobs::run_due('cli');$check($result['status']==='ok','CLI fallback runs without HTTP');$check((int)wp_next_scheduled('adc_process_outbox')>time(),'recurring job gets a future schedule');$check(!wp_next_scheduled('adc_mail_wake'),'completed one-off wake is removed');$check($unknown===0&&wp_next_scheduled('unknown.hook'),'unrelated scheduled actions remain untouched');
$health=Jobs::status();$check($health['worker_observed']&&$health['worker_source']==='cli','health uses successful observed execution and its source');$check(Jobs::health_test()['status']==='recommended','held messages prevent a misleading all-clear');
$another=Outbox::enqueue('email.verify',array(),'runtime:dedupe')['id'];do_action_ref_array('adc_process_outbox',array());$check($wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d",$another))==='pending','native duplicate callback is suppressed after fallback completion');
$observed=get_option('adc_jobs_observed');update_option('adc_jobs_observed',array('site'=>'different-deployment','jobs'=>$observed['jobs']));$check(!Jobs::status()['worker_observed'],'copied observations cannot certify a different deployment');update_option('adc_jobs_observed',$observed);
update_option('adc_jobs_mode','server');$check(!Jobs::browser_allowed(),'server-only mode disables browser execution');update_option('adc_jobs_mode','auto');$check(!Jobs::browser_allowed(),'automatic mode respects explicit DISABLE_WP_CRON');update_option('adc_jobs_mode','browser');$check(Jobs::browser_allowed(),'administrator can explicitly enable browser fallback');
$ok=Jobs::execute('adc_content_import_cleanup',static function(){throw new RuntimeException('synthetic failure');},array('synthetic-failure'));
$check(!$ok&&Jobs::status()['jobs']['adc_content_import_cleanup']['status']==='failed','callback exception is contained and reported');
$check(Jobs::execute('adc_content_import_cleanup',static fn()=>true,array('synthetic-recovery')),'a failed callback releases its worker lock for recovery');
update_option('adc_jobs_last_browser_tick',time());$check(Jobs::run_due('browser')['status']==='throttled','multiple tabs are throttled before executing jobs');
add_filter('wp_doing_cron','__return_true');
$args=array('native-one-off-failure');Jobs::execute('adc_content_import_cleanup',static function(){throw new RuntimeException('synthetic native failure');},$args);
$check((int)wp_next_scheduled('adc_content_import_cleanup',$args)>time(),'native failed one-off is rescheduled after WordPress removed its event');
wp_clear_scheduled_hook('adc_content_import_cleanup',$args);
$other=new mysqli($host,DB_USER,DB_PASSWORD,DB_NAME,(int)$port);$other->query("SELECT GET_LOCK('$escaped',0)");$args=array('native-one-off-contention');Jobs::execute('adc_content_import_cleanup',static fn()=>true,$args);$other->query("SELECT RELEASE_LOCK('$escaped')");$other->close();
$check((int)wp_next_scheduled('adc_content_import_cleanup',$args)>time(),'native one-off survives a competing worker lock');
remove_filter('wp_doing_cron','__return_true');
foreach(array('wp_update_themes','wp_update_plugins','wp_version_check') as $hook){remove_all_actions($hook);}
$wrapped=new ReflectionProperty(Jobs::class,'wrapped');$wrapped->setAccessible(true);$wrapped->setValue(null,array());add_filter('wp_doing_ajax','__return_true');Jobs::wrap();
$check(has_action('wp_update_themes')&&has_action('wp_update_plugins')&&has_action('wp_version_check'),'AJAX fallback restores only supported core update check callbacks');
global $wp_filter;$count=count($wp_filter['wp_update_themes']->callbacks[10]);Jobs::wrap();$check(count($wp_filter['wp_update_themes']->callbacks[10])===$count,'AJAX callback registration remains idempotent');remove_filter('wp_doing_ajax','__return_true');
echo "Job runtime checks passed: $checks. Synthetic mail only; source database untouched.\n";
