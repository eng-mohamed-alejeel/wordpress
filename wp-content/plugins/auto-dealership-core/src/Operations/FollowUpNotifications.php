<?php
namespace AutoDealership\Operations;

use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;
use AutoDealership\Audit\AuditLog;

defined('ABSPATH') || exit;

/** Durable staff inbox with explicit acknowledgement and permission-checked delivery. */
final class FollowUpNotifications {
	public static function label(string $type): string {
		$labels=array('lead'=>array('متابعة فرصة','Lead follow-up'),'discount'=>array('اعتماد خصم','Discount approval'),'sale'=>array('اعتماد بيع','Sale approval'),'reservation'=>array('حجز منتهي','Expired reservation'),'refund'=>array('استرداد متأخر','Overdue refund'),'outbox'=>array('مهمة آلية متعثرة','Failed background job'));
		return $labels[$type][\AutoDealership\Core\Localization::language()==='en'?1:0]??$type;
	}
	public static function url(array $row): string {
		$targets=array('lead'=>array('page'=>'adc-crm','lead_id'=>$row['source_id']),'discount'=>array('page'=>'adc-approvals','section'=>'discounts'),'sale'=>array('page'=>'adc-onyx-documents','sale_id'=>$row['source_id']),'reservation'=>array('page'=>'adc-sales-journey','section'=>'reservations','reservation_id'=>$row['source_id']),'refund'=>array('page'=>'adc-refunds'),'outbox'=>array('page'=>'adc-outbox'));
		return add_query_arg($targets[$row['source_type']]??array('page'=>'adc-follow-ups'),admin_url('admin.php'));
	}
	public static function boot(): void {
		OutboxService::register_handler('followup.notify',array(self::class,'deliver'));
		add_action('adc_scan_followups',array(self::class,'run'));
		add_action('admin_post_adc_ack_followup',array(self::class,'submit_acknowledgement'));
		add_action('admin_post_adc_followup_email',array(self::class,'submit_email_policy'));
	}
	public static function email_policy(bool $enabled,bool $previous) {
		global $wpdb;
		if(!current_user_can('manage_options')){return new \WP_Error('adc_followup_forbidden');}
		add_option('adc_followup_email_enabled',false,'',false);
		if(!Transaction::begin()){return new \WP_Error('adc_followup_storage');}
		$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'adc_followup_email_enabled'));
		$old=(bool)maybe_unserialize($raw);
		if($wpdb->last_error||$old!==$previous){$wpdb->query('ROLLBACK');return new \WP_Error('adc_followup_stale');}
		$ok=false!==$wpdb->update($wpdb->options,array('option_value'=>$enabled?'1':''),array('option_name'=>'adc_followup_email_enabled'))&&Transaction::commit(static fn()=>AuditLog::record('followup.email_policy_changed','configuration',0,'Workflow email policy',array('enabled'=>$old),array('enabled'=>$enabled)));
		if(!$ok){$wpdb->query('ROLLBACK');}
		wp_cache_delete('adc_followup_email_enabled','options');wp_cache_delete('alloptions','options');
		return $ok?array('enabled'=>$enabled):new \WP_Error('adc_followup_storage');
	}
	public static function submit_email_policy(): void {
		check_admin_referer('adc_followup_email');
		$result=self::email_policy(($_POST['enabled']??'0')==='1',($_POST['previous']??'0')==='1');
		wp_safe_redirect(add_query_arg(is_wp_error($result)?'email_error':'email_saved',1,admin_url('admin.php?page=adc-follow-ups')));exit;
	}

	/** Definitions are code-owned; financial documents and customer names never enter mail. */
	private static function sources(): array {
		$now=current_time('mysql',true);
		$approval=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS);
		$refund=gmdate('Y-m-d H:i:s',time()-min(720,max(1,absint(get_option('adc_reservation_refund_hours',72))))*HOUR_IN_SECONDS);
		return array(
			'lead'=>array('SELECT l.id,l.branch_id,l.owner_user_id,l.next_action_at due_at FROM '.Schema::table('leads')." l WHERE l.stage NOT IN ('won','lost') AND l.next_action_at<=%s",array($now),'adc_view_own_leads','adc_view_branch_leads'),
			'discount'=>array('SELECT d.id,q.branch_id,0 owner_user_id,d.created_at due_at FROM '.Schema::table('discount_requests').' d INNER JOIN '.Schema::table('quotations')." q ON q.id=d.quotation_id WHERE d.status='pending' AND d.created_at<=%s",array($approval),'adc_review_discounts','adc_review_discounts'),
			'sale'=>array('SELECT s.id,v.branch_id,0 owner_user_id,s.created_at due_at FROM '.Schema::table('sales').' s INNER JOIN '.Schema::table('vehicles')." v ON v.id=s.vehicle_id WHERE s.status='pending_approval' AND s.created_at<=%s",array($approval),'adc_approve_sales','adc_approve_sales'),
			'reservation'=>array('SELECT r.id,r.branch_id,r.owner_user_id,r.expires_at due_at FROM '.Schema::table('reservations')." r WHERE r.status='confirmed' AND r.expires_at<=%s",array($now),'adc_view_own_leads','adc_view_branch_leads'),
			'refund'=>array('SELECT r.id,r.branch_id,0 owner_user_id,r.expires_at due_at FROM '.Schema::table('reservations')." r WHERE r.deposit_refund_status IN ('pending_refund','partially_refunded') AND EXISTS (SELECT 1 FROM ".Schema::table('audit_events')." a WHERE a.subject_type='reservation' AND a.subject_id=r.id AND a.event_key IN ('reservation.cancelled','reservation.expired') AND a.created_at<=%s)",array($refund),'adc_view_finance','adc_view_finance'),
			'outbox'=>array('SELECT o.id,0 branch_id,0 owner_user_id,COALESCE(o.locked_at,o.created_at) due_at FROM '.Schema::table('outbox')." o WHERE o.event_key<>'followup.notify' AND (o.status='failed' OR (o.status='processing' AND o.locked_at<=%s))",array(gmdate('Y-m-d H:i:s',time()-OutboxService::LEASE_SECONDS)),'adc_view_outbox','adc_view_outbox'),
		);
	}

	private static function allowed(array $row,int $user_id,bool $manager=false): bool {
		$source=self::sources()[$row['source_type']]??null;
		if(!$source||!BranchScope::allows((int)$row['branch_id'],$user_id)){return false;}
		if(user_can($user_id,'manage_options')){return true;}
		if($manager||!(int)$row['owner_user_id']){return user_can($user_id,$source[3]);}
		return (int)$row['owner_user_id']===$user_id&&user_can($user_id,$source[2]);
	}

	public static function run(): void {
		$result=self::scan();
		update_option('adc_followup_health',array('finished_at'=>current_time('mysql',true),'summary'=>is_wp_error($result)?array('error'=>$result->get_error_code()):$result),false);
	}

	public static function scan() {
		global $wpdb;
		if(!Schema::is_ready()){return new \WP_Error('adc_followup_schema');}
		$users=get_users(array('fields'=>'ID'));
		$summary=array('queued'=>0,'existing'=>0,'unassigned'=>0);
		$cursors=get_option('adc_followup_cursors',array());
		foreach(self::sources() as $type=>$source){
			$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM ('.$source[0].') due_work WHERE id>%d ORDER BY id LIMIT 200',array_merge($source[1],array((int)($cursors[$type]??0)))),ARRAY_A);
			if($wpdb->last_error){return new \WP_Error('adc_followup_read');}
			foreach($rows as $work){
				$work['source_type']=$type;
				$escalated=strtotime($work['due_at'].' UTC')<=time()-DAY_IN_SECONDS;
				$recipients=array();
				foreach($users as $user){
					$user=(int)$user;
					if(self::allowed($work,$user)&&(!(int)$work['owner_user_id']||(int)$work['owner_user_id']===$user)){$recipients[$user]=0;}
					if($escalated&&self::allowed($work,$user,true)&&$user!==(int)$work['owner_user_id']){$recipients[$user]=1;}
				}
				if(!$recipients){++$summary['unassigned'];}
				foreach($recipients as $recipient=>$level){
					if(!Transaction::begin()){return new \WP_Error('adc_followup_storage');}
					$key=hash('sha256',implode('|',array($type,$work['id'],$work['due_at'],$recipient,$level)));
					$table=Schema::table('followup_notifications');
					$insert=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (notification_key,source_type,source_id,branch_id,owner_user_id,recipient_id,level,due_at,created_at) VALUES (%s,%s,%d,%d,%d,%d,%d,%s,%s)",$key,$type,$work['id'],$work['branch_id'],$work['owner_user_id'],$recipient,$level,$work['due_at'],current_time('mysql',true)));
					if(false===$insert){$wpdb->query('ROLLBACK');return new \WP_Error('adc_followup_storage');}
					if(0===$insert){$wpdb->query('ROLLBACK');++$summary['existing'];continue;}
					$id=(int)$wpdb->insert_id;
					$queued=OutboxService::enqueue('followup.notify',array('subject_type'=>'followup_notification','subject_id'=>$id,'recipient_type'=>'user','recipient_id'=>$recipient,'branch_id'=>(int)$work['branch_id']),$key);
					if(is_wp_error($queued)||!Transaction::commit(static fn()=>AuditLog::record('followup.queued','followup_notification',$id,'Scheduled workflow reminder',null,array('recipient_id'=>$recipient,'level'=>$level)))){$wpdb->query('ROLLBACK');return new \WP_Error('adc_followup_storage');}
					++$summary['queued'];
				}
			}
			$cursors[$type]=count($rows)===200?(int)end($rows)['id']:0;
		}
		update_option('adc_followup_cursors',$cursors,false);
		return $summary;
	}

	private static function current(array $row) {
		global $wpdb;
		$source=self::sources()[$row['source_type']]??null;
		if(!$source){return false;}
		$work=$wpdb->get_row($wpdb->prepare('SELECT * FROM ('.$source[0].') due_work WHERE id=%d',array_merge($source[1],array((int)$row['source_id']))),ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_followup_read');}
		return $work&&$work['due_at']===$row['due_at']&&(int)$work['branch_id']===(int)$row['branch_id']&&(int)$work['owner_user_id']===(int)$row['owner_user_id'];
	}

	public static function deliver(array $payload) {
		global $wpdb;
		$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('followup_notifications').' WHERE id=%d',(int)($payload['subject_id']??0)),ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_followup_read');}
		if(!$row){return new \WP_Error('adc_outbox_payload');}
		if($row['delivered_at']||$row['acknowledged_at']){return true;}
		$current=self::current($row);if(is_wp_error($current)){return $current;}
		if(!$current||!self::allowed($row,(int)$row['recipient_id'],(bool)$row['level'])){return false!==$wpdb->update(Schema::table('followup_notifications'),array('resolved_at'=>current_time('mysql',true)),array('id'=>$row['id']));}
		$channel='inbox';
		if(get_option('adc_followup_email_enabled',false)){
			$user=get_userdata((int)$row['recipient_id']);
			if(!$user||!is_email($user->user_email)){return new \WP_Error('adc_followup_recipient');}
			if(!wp_mail($user->user_email,'متابعة عمل مستحقة / Due workflow follow-up','توجد متابعة مستحقة ضمن صلاحياتك. افتح لوحة المتابعات للاطلاع والإقرار. / Review and acknowledge your due work: '.admin_url('admin.php?page=adc-follow-ups'))){return new \WP_Error('adc_followup_mail');}
			$channel='email';
		}
		return false!==$wpdb->update(Schema::table('followup_notifications'),array('delivered_at'=>current_time('mysql',true),'delivery_channel'=>$channel),array('id'=>$row['id']));
	}

	public static function acknowledge(int $id) {
		global $wpdb;
		if(!Transaction::begin()){return new \WP_Error('adc_followup_storage');}
		$table=Schema::table('followup_notifications');
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id),ARRAY_A);
		if(!$row||(int)$row['recipient_id']!==get_current_user_id()||!self::allowed($row,get_current_user_id(),(bool)$row['level'])){$wpdb->query('ROLLBACK');return new \WP_Error('adc_followup_forbidden');}
		if($row['acknowledged_at']){$wpdb->query('ROLLBACK');return array('acknowledged'=>true);}
		$ok=1===$wpdb->update($table,array('acknowledged_at'=>current_time('mysql',true)),array('id'=>$id))&&Transaction::commit(static fn()=>AuditLog::record('followup.acknowledged','followup_notification',$id,'Staff acknowledged reminder',null,array('recipient_id'=>get_current_user_id())));
		if(!$ok){$wpdb->query('ROLLBACK');return new \WP_Error('adc_followup_storage');}
		return array('acknowledged'=>true);
	}

	public static function submit_acknowledgement(): void {
		$id=absint($_POST['id']??0);check_admin_referer('adc_ack_followup_'.$id);
		$result=self::acknowledge($id);
		wp_safe_redirect(add_query_arg(is_wp_error($result)?'ack_error':'acknowledged',1,admin_url('admin.php?page=adc-follow-ups')));exit;
	}

	public static function inbox(): array {
		global $wpdb;
		$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Schema::table('followup_notifications').' WHERE recipient_id=%d AND acknowledged_at IS NULL AND resolved_at IS NULL ORDER BY id DESC LIMIT 100',get_current_user_id()),ARRAY_A)?:array();
		$result=array();foreach($rows as $row){if(!self::allowed($row,get_current_user_id(),(bool)$row['level'])){continue;}$current=self::current($row);if(is_wp_error($current)){wp_die('Unable to load notifications','',array('response'=>503));}if($current){$result[]=$row;}}
		return $result;
	}
}
