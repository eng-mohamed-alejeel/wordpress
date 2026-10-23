<?php
namespace AutoDealership\Operations;

use AutoDealership\Core\ProductSetup;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
defined('ABSPATH') || exit;

/** Bounded, observable execution. Never relaxes REST permissions or bypasses host protection. */
final class JobRuntime {
    private static bool $locked=false;
    private static float $deadline=0;
    private static string $source='';
    private static bool $last_ok=true;
    private static array $wrapped=array();
    public const MAIL_EVENTS=array('email.verify','customer.welcome','newsletter.subscription','newsletter.expand','newsletter.deliver','portal.message','portal.review','followup.notify');
    public static function t(string $ar,string $en): string {return ProductSetup::t($ar,$en);}
    public static function callbacks(): array {return array(
        'adc_process_outbox'=>array(OutboxService::class,'run'),
        'adc_mail_wake'=>array('AutoDealership\\Leads\\NewsletterService','run_mail'),
        'adc_expire_reservations'=>array('AutoDealership\\Reservations\\ReservationService','expire_due'),
        'adc_scan_followups'=>array(FollowUpNotifications::class,'run'),
        'adc_backfill_lead_stage_history'=>array('AutoDealership\\Leads\\LeadStageHistoryBackfill','run_scheduled'),
        'adc_prune_request_limits'=>array('AutoDealership\\Security\\PublicRequestGuard','prune'),
        'adc_verification_prune'=>array('AutoDealership\\Accounts\\EmailVerification','prune'),
        'adc_access_maintenance'=>array('AutoDealership\\Security\\AccessPolicy','maintenance'),
        'adc_expire_user_permission'=>array('AutoDealership\\Security\\AccessPolicy','expire'),
        'adc_privacy_retention'=>array('AutoDealership\\Privacy\\RetentionService','run'),
        'adc_content_import_cleanup'=>array('AutoDealership\\Content\\ContentTransfer','cleanup'),
        'wp_update_themes'=>'wp_update_themes','wp_update_plugins'=>'wp_update_plugins','wp_version_check'=>'wp_version_check',
    );}
    public static function boot(): void {
        add_action('init',array(self::class,'wrap'),100);
        add_filter('adc_outbox_worker_where',array(self::class,'mail_where'));
        add_filter('adc_outbox_retry_allowed',static function($allowed,$row){return $allowed&&!(in_array($row['event_key']??'',self::MAIL_EVENTS,true)&&($row['created_at']??'9999')<gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS));},10,2);
        add_filter('adc_outbox_budget_seconds',array(self::class,'budget'));
        add_filter('adc_outbox_batch_limit',static fn($limit)=>'browser'===self::$source?min(2,$limit):$limit);
        add_action('admin_enqueue_scripts',array(self::class,'assets'));
        add_action('wp_ajax_adc_jobs_tick',array(self::class,'ajax_tick'));
        add_action('wp_ajax_adc_jobs_probe',array(self::class,'ajax_probe'));
        add_action('admin_post_adc_jobs_settings',array(self::class,'save'));
        add_action('admin_notices',array(self::class,'notice'));
        add_filter('site_status_tests',static function($tests){$tests['direct']['adc_job_runtime']=array('label'=>self::t('تشغيل مهام المنصة','Platform job execution'),'test'=>array(self::class,'health_test'));return $tests;});
        if(defined('WP_CLI')&&constant('WP_CLI')&&class_exists('WP_CLI')){
            \WP_CLI::add_command('adc jobs status',static function(){\WP_CLI::log(wp_json_encode(self::status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));});
            \WP_CLI::add_command('adc jobs run',static function(){\WP_CLI::log(wp_json_encode(self::run_due('cli'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));});
        }
    }
    public static function wrap(): void {
        foreach(self::callbacks() as $hook=>$callback){
            if(isset(self::$wrapped[$hook])){continue;}
            $priority=has_action($hook,$callback);
            // Core intentionally omits update hooks in AJAX requests. Restore only
            // these read/check callbacks, never the automatic update installer.
            if(false===$priority&&wp_doing_ajax()&&in_array($hook,array('wp_update_themes','wp_update_plugins','wp_version_check'),true)&&is_callable($callback)){$priority=10;}
            if(false===$priority){continue;}
            remove_action($hook,$callback,$priority);add_action($hook,static function(...$args)use($hook,$callback){self::execute($hook,$callback,$args);},$priority,10);self::$wrapped[$hook]=true;
        }
    }
    public static function settings(): array {return array('mode'=>in_array(get_option('adc_jobs_mode','auto'),array('auto','browser','server'),true)?get_option('adc_jobs_mode','auto'):'auto');}
    public static function save_mode(string $mode) {
        global $wpdb;
        if(!current_user_can('manage_options')){return new \WP_Error('adc_jobs_forbidden');}
        if(!in_array($mode,array('auto','browser','server'),true)){return new \WP_Error('adc_jobs_mode');}
        $option='adc_jobs_mode';
        if(!Transaction::begin()){return new \WP_Error('adc_jobs_storage');}
        wp_cache_delete($option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$option));
        if($wpdb->last_error){$wpdb->query('ROLLBACK');return new \WP_Error('adc_jobs_storage');}
        $before=maybe_unserialize($raw);
        if(!in_array($before,array('auto','browser','server'),true)){$before='auto';}
        update_option($option,$mode,false);
        $ok=!$wpdb->last_error&&$mode===get_option($option)&&Transaction::commit(static fn()=>AuditLog::record('platform.job_mode','settings',0,'Scheduled execution mode updated',array('mode'=>$before),array('mode'=>$mode)));
        if(!$ok){$wpdb->query('ROLLBACK');wp_cache_delete($option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');return new \WP_Error('adc_jobs_storage');}
        return true;
    }
    public static function mail_where(string $where): string {
        global $wpdb;
        // A long outage must not suddenly send old confirmations, welcomes or campaigns.
        return $where.$wpdb->prepare(' AND NOT (event_key IN ('.implode(',',array_fill(0,count(self::MAIL_EVENTS),'%s')).') AND created_at<%s)',array_merge(self::MAIL_EVENTS,array(gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS))));
    }
    public static function budget($seconds): float {return self::$deadline>0?max(0,min((float)$seconds,self::$deadline-microtime(true))):(float)$seconds;}
    private static function lock(): bool {global $wpdb;return '1'===(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',self::lock_name()));}
    private static function unlock(): void {global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::lock_name()));}
    private static function lock_name(): string {global $wpdb;return 'adc_jobs_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix),0,48);}
    private static function observations(): array {
        $state=get_option('adc_jobs_observed',array());return is_array($state)&&($state['site']??'')===self::site_key()?$state:array('site'=>self::site_key(),'jobs'=>array());
    }
    private static function site_key(): string {return hash('sha256',home_url('/').'|'.ABSPATH);}
    private static function record(string $hook,array $data): void {
        $state=self::observations();$state['jobs'][$hook]=$data;update_option('adc_jobs_observed',$state,false);
    }
    private static function preserve_native_retry(string $hook,array $args): void {
        // WordPress removes one-off events before invoking their callbacks.
        // A competing worker or a failed callback must not silently lose that event.
        if(wp_doing_cron()&&!wp_next_scheduled($hook,$args)){
            wp_schedule_single_event(time()+MINUTE_IN_SECONDS,$hook,$args);
        }
    }
    public static function execute(string $hook,$callback,array $args=array()): bool {
        $owner=!self::$locked;
        if($owner&&!self::lock()){self::$last_ok=false;self::preserve_native_retry($hook,$args);return false;}
        if($owner){self::$locked=true;self::$source='cli'===PHP_SAPI?'cli':(wp_doing_cron()?'wp-cron':'request');self::$deadline=microtime(true)+18;}
        $key='adc_jobs_once_'.substr(hash('sha256',$hook.'|'.wp_json_encode($args)),0,40);
        try{
            if(get_transient($key)){self::$last_ok=true;return true;}
            $started=time();self::record($hook,array('started'=>$started,'finished'=>0,'source'=>self::$source,'status'=>'running'));
            try{
                $result=call_user_func_array($callback,$args);$code=is_wp_error($result)?$result->get_error_code():'';
                if('adc_process_outbox'===$hook){$health=(array)get_option('adc_outbox_health',array());$code=$health['summary']['error']??$code;}
                self::$last_ok=''===$code;
                self::record($hook,array('started'=>$started,'finished'=>time(),'source'=>self::$source,'status'=>self::$last_ok?'ok':'failed','error'=>sanitize_key($code)));
                if(self::$last_ok){set_transient($key,1,30);}else{self::preserve_native_retry($hook,$args);}
                return self::$last_ok;
            }catch(\Throwable $error){self::$last_ok=false;self::record($hook,array('started'=>$started,'finished'=>time(),'source'=>self::$source,'status'=>'failed','error'=>'adc_job_exception'));self::preserve_native_retry($hook,$args);return false;}
        }finally{if($owner){self::$locked=false;self::$source='';self::$deadline=0;self::unlock();}}
    }
    public static function due(array $cron,int $now): array {
        $allowed=self::callbacks();$due=array();foreach($cron as $stamp=>$hooks){if((int)$stamp>$now){continue;}foreach($hooks as $hook=>$events){if(!isset($allowed[$hook])){continue;}foreach($events as $event){$due[]=array('time'=>(int)$stamp,'hook'=>$hook,'args'=>$event['args']??array(),'schedule'=>$event['schedule']??false);}}}
        $priority=array_flip(array_keys($allowed));usort($due,static fn($a,$b)=>($priority[$a['hook']]<=>$priority[$b['hook']])?:($a['time']<=>$b['time']));return $due;
    }
    public static function run_due(string $source='browser'): array {
        if(!Schema::is_ready()){return array('status'=>'blocked','error'=>'adc_jobs_schema');}
        if(!self::lock()){return array('status'=>'busy');}
        self::$locked=true;self::$source=$source;self::$deadline=microtime(true)+18;$results=array();
        try{
            if('browser'===$source){$last=(int)get_option('adc_jobs_last_browser_tick',0);if(time()-$last<45){return array('status'=>'throttled');}update_option('adc_jobs_last_browser_tick',time(),false);}
            foreach(self::due(_get_cron_array()?:array(),time()) as $event){
                if(count($results)>=('browser'===$source?2:15)||microtime(true)>=self::$deadline){break;}
                if(!has_action($event['hook'])){continue;}
                // The original timestamp/arguments remain authoritative; no arbitrary hook comes from HTTP input.
                if(!wp_get_scheduled_event($event['hook'],$event['args'],$event['time'])){continue;}
                self::$last_ok=true;do_action_ref_array($event['hook'],$event['args']);
                if(self::$last_ok){if(!wp_get_scheduled_event($event['hook'],$event['args'],$event['time'])){$results[]=array('hook'=>$event['hook'],'status'=>'ok');continue;}if($event['schedule']){$rescheduled=wp_reschedule_event($event['time'],$event['schedule'],$event['hook'],$event['args'],true);if(is_wp_error($rescheduled)||!$rescheduled){self::record($event['hook'],array('started'=>time(),'finished'=>time(),'source'=>$source,'status'=>'failed','error'=>'reschedule_failed'));$results[]=array('hook'=>$event['hook'],'status'=>'reschedule_failed');continue;}}$removed=wp_unschedule_event($event['time'],$event['hook'],$event['args'],true);$results[]=array('hook'=>$event['hook'],'status'=>is_wp_error($removed)||!$removed?'unschedule_failed':'ok');}
                else{$results[]=array('hook'=>$event['hook'],'status'=>'failed');}
            }
            $failed=array_filter($results,static fn($result)=>'ok'!==$result['status']);
            return array('status'=>$failed?'failed':'ok','jobs'=>$results);
        }finally{self::$locked=false;self::$source='';self::$deadline=0;self::unlock();}
    }
    public static function status(): array {
        global $wpdb;$state=self::observations();$next=wp_next_scheduled('adc_process_outbox');$last=$state['jobs']['adc_process_outbox']??array();$age=empty($last['finished'])?null:max(0,time()-(int)$last['finished']);
        $held=0;$oldest=null;if(Schema::is_ready()){$table=OutboxService::table();$held=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status IN ('pending','retry','processing') AND event_key IN (".implode(',',array_fill(0,count(self::MAIL_EVENTS),'%s')).') AND created_at<%s',array_merge(self::MAIL_EVENTS,array(gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS)))));$oldest=$wpdb->get_var("SELECT MIN(next_attempt_at) FROM $table WHERE status IN ('pending','retry')");}
        $due=self::due(_get_cron_array()?:array(),time());
        $failed=count(array_filter($state['jobs'],static fn($job)=>'failed'===($job['status']??'')||('running'===($job['status']??'')&&time()-(int)($job['started']??0)>300)));
        return array('mode'=>self::settings()['mode'],'cron_disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON,'worker_observed'=>$age!==null&&$age<=900&&'ok'===($last['status']??''),'worker_source'=>$last['source']??'unknown','last_worker'=>$last['finished']??null,'next_worker'=>$next?:null,'held_mail'=>$held,'failed_jobs'=>$failed,'oldest_pending'=>$oldest,'due_count'=>count($due),'jobs'=>$state['jobs'],'source_scope'=>'browser'===($last['source']??'')?'dashboard_open_only':'observed_execution');
    }
    public static function browser_allowed(): bool {return 'server'!==self::settings()['mode']&&('browser'===self::settings()['mode']||!(defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON));}
    private static function authorize(): void {if(!current_user_can('manage_options')){wp_send_json_error(array('code'=>'forbidden'),403);}if('POST'!==($_SERVER['REQUEST_METHOD']??'')){wp_send_json_error(array('code'=>'method_not_allowed'),405);}check_ajax_referer('adc_job_runtime','nonce');}
    public static function ajax_tick(): void {self::authorize();if(!self::browser_allowed()){wp_send_json_success(array('nonce'=>wp_create_nonce('adc_job_runtime'),'result'=>array('status'=>'server_mode'),'health'=>self::status()));}wp_send_json_success(array('nonce'=>wp_create_nonce('adc_job_runtime'),'result'=>self::run_due(),'health'=>self::status()));}
    public static function ajax_probe(): void {
        self::authorize();$key='adc_jobs_probe_'.get_current_user_id();if(get_transient($key)){wp_send_json_success(get_option('adc_jobs_transport',array()));}set_transient($key,1,MINUTE_IN_SECONDS);
        $results=array('checked'=>time(),'site'=>self::site_key());foreach(array('home'=>home_url('/'),'rest'=>rest_url()) as $name=>$url){$r=wp_remote_get($url,array('timeout'=>6,'redirection'=>2,'limit_response_size'=>20000));if(is_wp_error($r)){$results[$name]=array('status'=>0,'error'=>$r->get_error_code());continue;}$body=wp_remote_retrieve_body($r);$results[$name]=array('status'=>wp_remote_retrieve_response_code($r),'browser_challenge'=>(bool)preg_match('/aes\.js|toNumbers\(|requires Javascript/i',$body),'json'=>is_array(json_decode($body,true)));}
        update_option('adc_jobs_transport',$results,false);wp_send_json_success($results);
    }
    public static function assets(): void {
        if(!current_user_can('manage_options')){return;}
        wp_enqueue_script('adc-job-runtime',plugins_url('assets/js/job-runtime.js',ADC_FILE),array(),ADC_VERSION,true);
        wp_localize_script('adc-job-runtime','adcJobRuntime',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('adc_job_runtime'),'rest'=>rest_url('wp/v2/types/post?context=edit'),'restNonce'=>wp_create_nonce('wp_rest'),'enabled'=>self::browser_allowed(),'initial'=>self::status(),'strings'=>array('running'=>self::t('جارٍ فحص التشغيل…','Checking execution…'),'offline'=>self::t('تعذر الاتصال. سيعاد الفحص عند عودة الاتصال.','Connection unavailable; checks resume when connected.'),'browser'=>self::t('التشغيل البديل متاح أثناء فتح لوحة الإدارة فقط؛ يلزم Cron من الخادم للعمل المستمر.','Fallback runs while this dashboard is open. Server Cron is required for continuous execution.'),'server'=>self::t('وضع مجدول الخادم؛ التشغيل البديل متوقف.','Server scheduler mode; browser fallback is off.'),'held'=>self::t('رسائل قديمة معلّقة للمراجعة: ','Old messages held for review: '),'probe'=>self::t('نتيجة الفحص','Diagnostic result'),'last'=>self::t('آخر تشغيل للعامل: ','Last worker run: '),'notRun'=>self::t('لم يُرصد بعد','Not observed yet'),'source'=>self::t('المصدر: ','Source: '),'due'=>self::t('مهام مستحقة: ','Due jobs: '),'failed'=>self::t('تعذر إتمام الفحص.','Could not complete the check.'))));
    }
    public static function save(): void {
        if(!current_user_can('manage_options')){wp_die('Forbidden','',array('response'=>403));}
        check_admin_referer('adc_jobs_settings');
        $posted_mode=wp_unslash($_POST['mode']??'');
        $result=self::save_mode(is_string($posted_mode)?sanitize_key($posted_mode):'');
        set_transient('adc_job_settings_notice_'.get_current_user_id(),array('ok'=>!is_wp_error($result)),MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=adc-outbox'));exit;
    }
    public static function notice(): void {
        if(!current_user_can('manage_options')||!in_array($_GET['page']??'',array('adc-outbox','adc-platform-setup','adc-workspace','adc-integrations'),true)){return;}
        if('adc-outbox'===($_GET['page']??'')){
            $notice=get_transient('adc_job_settings_notice_'.get_current_user_id());delete_transient('adc_job_settings_notice_'.get_current_user_id());
            if(is_array($notice)){echo '<div class="notice '.(!empty($notice['ok'])?'notice-success':'notice-error').' is-dismissible"><p>'.esc_html(!empty($notice['ok'])?self::t('تم حفظ طريقة التشغيل.','Execution mode saved.'):self::t('تعذر حفظ طريقة التشغيل أو تسجيل التدقيق. لم يُعتمد التغيير.','Execution mode could not be saved or audited; the change was not applied.')).'</p></div>';}
        }
        $health=self::status();if(!$health['worker_observed']||$health['held_mail']||$health['failed_jobs']){echo '<div class="notice notice-warning"><p>'.esc_html(self::t('المهام الآلية تحتاج متابعة: راجع آخر تشغيل فعلي والرسائل المعلقة قبل الاعتماد على التشغيل المستمر.','Background jobs need attention: review observed execution and held messages before relying on continuous operation.')).' <a href="'.esc_url(admin_url('admin.php?page=adc-outbox')).'">'.esc_html(self::t('مراقبة التشغيل','Execution monitor')).'</a></p></div>';}
    }
    public static function panel(): void {
        if(!current_user_can('manage_options')){return;}$status=self::status();
        echo '<div class="card" style="max-width:100%;margin:16px 0;padding:16px"><h2>'.esc_html(self::t('تشغيل موثوق على الاستضافات المختلفة','Portable scheduled execution')).'</h2><p data-job-status role="status">'.esc_html(self::t('يُعرض آخر تشغيل فعلي؛ وجود موعد مجدول لا يثبت تنفيذه. التشغيل من المتصفح يحتاج بقاء لوحة الإدارة مفتوحة.','Observed runs are shown; a scheduled timestamp does not prove execution. Browser fallback requires an open dashboard.')).'</p><p>'.esc_html(self::t('رسائل أقدم من 24 ساعة معلقة للمراجعة ولن ترسل تلقائيًا: ','Messages older than 24 hours are held for review and will not be sent automatically: ').$status['held_mail']).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_jobs_settings">';wp_nonce_field('adc_jobs_settings');echo '<label>'.esc_html(self::t('طريقة التشغيل','Execution mode')).' <select name="mode">';foreach(array('auto'=>self::t('تلقائي مع مساندة من لوحة الإدارة','Automatic with dashboard fallback'),'browser'=>self::t('مساندة لوحة الإدارة حتى عند تعطيل WP-Cron','Dashboard fallback even when WP-Cron is disabled'),'server'=>self::t('مجدول الخادم فقط','Server scheduler only')) as $value=>$label){echo '<option value="'.esc_attr($value).'" '.selected($status['mode'],$value,false).'>'.esc_html($label).'</option>';}echo '</select></label> <button class="button">'.esc_html(self::t('حفظ طريقة التشغيل','Save execution mode')).'</button></form>';
        echo '<p><button type="button" class="button" data-job-probe>'.esc_html(self::t('فحص الطلبات الداخلية وREST دون إرسال بريد','Check internal requests and REST without sending mail')).'</button></p><div data-job-probe-result role="status"></div><h3>'.esc_html(self::t('للإنتاج: مجدول مستقل من لوحة الاستضافة','Production: independent hosting scheduler')).'</h3><p>'.esc_html(self::t('اختر إحدى الطريقتين المتاحتين على استضافتك كل دقيقة إلى خمس دقائق. لا تعطّل WP-Cron قبل التحقق من عمل البديل. الطلب HTTP قد يُحجب بحماية الاستضافة؛ تشغيل PHP مباشرة يتجنب الطلب الداخلي.','Use an available method every one to five minutes. Verify the replacement before disabling WP-Cron. Hosting protection may block HTTP requests; direct PHP avoids the loopback request.')).'</p><code dir="ltr" style="display:block;overflow-wrap:anywhere">'.esc_html('php '.escapeshellarg(ABSPATH.'wp-cron.php')).'</code><code dir="ltr" style="display:block;overflow-wrap:anywhere">'.esc_html('wp --path='.escapeshellarg(ABSPATH).' cron event run --due-now').'</code><p>'.esc_html(self::t('بديل PHP لمهام المنصة وفحوص التحديث فقط:','PHP fallback for platform tasks and update checks only:')).'</p><code dir="ltr" style="display:block;overflow-wrap:anywhere">'.esc_html('php '.escapeshellarg(ADC_PATH.'bin/run-jobs.php').' '.escapeshellarg(ABSPATH)).'</code></div>';
    }
    public static function health_test(): array {
        $status=self::status();$good=$status['worker_observed']&&'browser'!==$status['worker_source']&&!$status['held_mail']&&!$status['failed_jobs'];return array('label'=>self::t($good?'تم رصد تشغيل مهام المنصة':'تشغيل مهام المنصة يحتاج متابعة',$good?'Platform job execution observed':'Platform job execution needs attention'),'status'=>$good?'good':'recommended','badge'=>array('label'=>self::t('الأداء','Performance'),'color'=>'blue'),'description'=>'<p>'.esc_html(self::t('يفحص آخر تشغيل فعلي والرسائل القديمة، ولا يخفي تنبيهات WordPress أو قيود الاستضافة.','Checks actual runs and old messages without suppressing WordPress warnings or hosting limitations.')).'</p>','actions'=>'<a href="'.esc_url(admin_url('admin.php?page=adc-outbox')).'">'.esc_html(self::t('مراقبة التشغيل','Execution monitor')).'</a>','test'=>'adc_job_runtime');
    }
}
