<?php
namespace AutoDealership\Portal;

use AutoDealership\Database\Schema;

defined('ABSPATH') || exit;

/** Bounded, read-only personal projections. Roles never expand account ownership. */
final class AccountOverview {
    public static function local_time(string $date):string {
        try{$value=new \DateTimeImmutable($date,wp_timezone());}catch(\Exception $error){return PortalAccess::t('راجع تفاصيل الموعد','View appointment details');}
        $day=$value->format('Y-m-d');$today=wp_date('Y-m-d');$tomorrow=(new \DateTimeImmutable('tomorrow',wp_timezone()))->format('Y-m-d');
        $label=$day===$today?PortalAccess::t('اليوم','Today'):($day===$tomorrow?PortalAccess::t('غدًا','Tomorrow'):wp_date('d/m/Y',$value->getTimestamp()));
        return $label.' · '.wp_date('H:i',$value->getTimestamp());
    }
    private static function rows(string $sql,array $args,array &$errors,string $key):array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare($sql,$args),ARRAY_A);
        if($wpdb->last_error||null===$rows){$errors[$key]=true;return array();}
        return $rows;
    }
    private static function count(string $from,array $args,array &$errors,string $key):?int {
        $rows=self::rows('SELECT COUNT(*) amount FROM '.$from,$args,$errors,$key);
        return isset($errors[$key])?null:(int)($rows[0]['amount']??0);
    }
    public static function snapshot():array {
        global $wpdb;
        $uid=get_current_user_id();$errors=array();$data=array('counts'=>array_fill_keys(array('requests','bookings','messages','favorites','purchases','documents'),null),'events'=>array(),'booking'=>null,'purchase'=>null,'errors'=>array());
        if(!$uid||!PortalAccess::ready()){$data['errors']=array('account'=>true);return $data;}
        $requests=$wpdb->prefix.'car_dealer_messages';$bookings=$wpdb->prefix.'car_dealer_bookings';
        $threads=PortalAccess::table('threads');$messages=PortalAccess::table('messages');$reads=PortalAccess::table('reads');
        $sales=Schema::table('sales');$customers=Schema::table('customers');$documents=PortalAccess::table('documents');
        $owned=" FROM $sales s JOIN $customers c ON c.id=s.customer_id WHERE c.account_user_id=%d AND c.merged_into_id IS NULL";
        $now=current_time('mysql');
        $data['counts']['requests']=self::count("$requests WHERE user_id=%d AND status IN ('new','read')",array($uid),$errors,'requests');
        $data['counts']['bookings']=self::count("$bookings WHERE user_id=%d AND status IN ('pending','confirmed') AND CONCAT(requested_date,' ',requested_time)>=%s",array($uid,$now),$errors,'bookings');
        $unread="EXISTS(SELECT 1 FROM $messages m WHERE m.thread_id=t.id AND m.visibility='public' AND m.sender_id<>%d AND m.id>COALESCE((SELECT r.last_id FROM $reads r WHERE r.thread_id=t.id AND r.user_id=%d),0))";
        $data['counts']['messages']=self::count("$threads t WHERE t.customer_id=%d AND $unread",array($uid,$uid,$uid),$errors,'messages');
        $data['counts']['favorites']=self::count(PortalAccess::table('favorites').' WHERE user_id=%d',array($uid),$errors,'favorites');
        $data['counts']['purchases']=self::count("$sales s JOIN $customers c ON c.id=s.customer_id WHERE c.account_user_id=%d AND c.merged_into_id IS NULL",array($uid),$errors,'purchases');
        $data['counts']['documents']=self::count("$documents d JOIN $sales s ON s.id=d.sale_id JOIN $customers c ON c.id=s.customer_id WHERE c.account_user_id=%d AND c.merged_into_id IS NULL AND d.status='published'",array($uid),$errors,'documents');
        $rows=self::rows("SELECT id,requested_date,requested_time,status,car_id FROM $bookings WHERE user_id=%d AND status IN ('pending','confirmed') AND CONCAT(requested_date,' ',requested_time)>=%s ORDER BY requested_date,requested_time,id LIMIT 1",array($uid,$now),$errors,'bookings');
        $data['booking']=$rows[0]??null;
        $rows=self::rows("SELECT s.id,s.status,s.created_at,q.quote_number FROM $sales s JOIN $customers c ON c.id=s.customer_id JOIN ".Schema::table('quotations')." q ON q.id=s.quotation_id WHERE c.account_user_id=%d AND c.merged_into_id IS NULL ORDER BY CASE WHEN s.status IN ('cancelled','delivered','returned') THEN 1 ELSE 0 END,s.id DESC LIMIT 1",array($uid),$errors,'purchases');
        $data['purchase']=$rows[0]??null;
        // Activity is a projection of existing records, without synthetic notifications or writes.
        foreach(array('requests'=>$requests,'bookings'=>$bookings) as $section=>$table){
            foreach(self::rows("SELECT id,status,updated_at,created_at FROM $table WHERE user_id=%d ORDER BY updated_at DESC,id DESC LIMIT 3",array($uid),$errors,$section) as $row){
                $data['events'][]=array('section'=>$section,'id'=>(int)$row['id'],'label'=>PortalAccess::t('bookings'===$section?'تحديث تجربة القيادة #':'تحديث الطلب #','bookings'===$section?'Test drive update #':'Enquiry update #').$row['id'],'status'=>$row['status'],'date'=>$row['updated_at']?:$row['created_at'],'unread'=>false,'args'=>array('request'=>(int)$row['id']));
            }
        }
        foreach(self::rows("SELECT t.id,t.status,m.created_at,$unread unread FROM $threads t JOIN $messages m ON m.id=t.last_public_id AND m.thread_id=t.id AND m.visibility='public' WHERE t.customer_id=%d ORDER BY m.created_at DESC,t.id DESC LIMIT 3",array($uid,$uid,$uid),$errors,'messages') as $row){
            $data['events'][]=array('section'=>'messages','id'=>(int)$row['id'],'label'=>PortalAccess::t('تحديث محادثتك #','Conversation update #').$row['id'],'status'=>'','date'=>$row['created_at'],'unread'=>(bool)$row['unread'],'args'=>array('thread'=>(int)$row['id']));
        }
        foreach(self::rows('SELECT s.id,s.status,s.created_at'.$owned.' ORDER BY s.id DESC LIMIT 3',array($uid),$errors,'purchases') as $row){
            $data['events'][]=array('section'=>'purchases','id'=>(int)$row['id'],'label'=>PortalAccess::t('عملية الشراء #','Purchase #').$row['id'],'status'=>$row['status'],'date'=>$row['created_at'],'unread'=>false,'args'=>array('sale'=>(int)$row['id']));
        }
        foreach(self::rows("SELECT d.id,d.sale_id,d.title,d.created_at FROM $documents d JOIN $sales s ON s.id=d.sale_id JOIN $customers c ON c.id=s.customer_id WHERE c.account_user_id=%d AND c.merged_into_id IS NULL AND d.status='published' ORDER BY d.id DESC LIMIT 3",array($uid),$errors,'documents') as $row){
            $data['events'][]=array('section'=>'documents','id'=>(int)$row['id'],'label'=>PortalAccess::t('مستند متاح: ','Document available: ').$row['title'],'status'=>'','date'=>$row['created_at'],'unread'=>false,'args'=>array('sale'=>(int)$row['sale_id']));
        }
        foreach(self::rows('SELECT r.id,r.status,r.updated_at,EXISTS(SELECT 1 FROM '.PortalAccess::table('notifications')." n WHERE n.user_id=%d AND n.kind='review' AND n.object_id=r.id AND n.read_at IS NULL) unread FROM ".PortalAccess::table('reviews').' r WHERE r.user_id=%d ORDER BY r.updated_at DESC,r.id DESC LIMIT 3',array($uid,$uid),$errors,'reviews') as $row){
            $data['events'][]=array('section'=>'reviews','id'=>(int)$row['id'],'label'=>PortalAccess::t('تحديث تقييمك #','Review update #').$row['id'],'status'=>PortalAccess::label($row['status']),'date'=>$row['updated_at'],'unread'=>(bool)$row['unread'],'args'=>array('review'=>(int)$row['id']));
        }
        usort($data['events'],static fn($a,$b)=>strcmp($b['date'],$a['date'])?:($b['id']<=>$a['id']));
        $data['events']=array_slice($data['events'],0,10);$data['errors']=$errors;
        return $data;
    }
    public static function render(array $data):void {
        $t=static fn($ar,$en)=>esc_html(PortalAccess::t($ar,$en));
        if($data['errors']){echo '<div class="adc-p-notice is-error" role="alert">'.$t('تعذر تحميل بعض معلومات حسابك. لا تعني العلامة — عدم وجود سجلات.','Some account information could not be loaded. A — does not mean there are no records.').' <a href="'.esc_url(PortalAccess::url()).'">'.$t('إعادة المحاولة','Retry').'</a></div>';}
        $unread=$data['counts']['messages'];$booking=$data['booking'];$purchase=$data['purchase'];
        if(!PortalAccess::verified()){$title=PortalAccess::t('أكّد بريدك لإكمال التواصل','Confirm your email to stay connected');$copy=PortalAccess::t('طلباتك محفوظة. تأكيد البريد يتيح إرسال الرسائل والتقييمات.','Your enquiries are saved. Confirming your email enables messaging and reviews.');$action=PortalAccess::t('تأكيد البريد','Confirm email');$url=PortalAccess::url('profile');}
        elseif($unread){$title=PortalAccess::t('لديك محادثات تنتظر قراءتك','You have conversations to read');$copy=PortalAccess::t('اطلع على الردود الجديدة وتابع مع الفريق من سجل محادثتك.','Read the latest replies and continue with the team in your conversation history.');$action=PortalAccess::t('قراءة الرسائل','Read messages');$url=PortalAccess::url('messages',array('status'=>'unread'));}
        elseif($booking){$title=PortalAccess::t('موعدك القادم','Your next appointment');$copy=PortalAccess::t('confirmed'===$booking['status']?'أكد الفريق موعدك. راجع التفاصيل قبل الزيارة.':'موعدك مطلوب وينتظر تأكيد الفريق.','confirmed'===$booking['status']?'Your appointment is confirmed. Review the details before visiting.':'Your requested appointment is awaiting team confirmation.');$action=PortalAccess::t('عرض الموعد','View appointment');$url=PortalAccess::url('bookings',array('request'=>(int)$booking['id']));}
        elseif($purchase){$title=PortalAccess::t('تابع رحلة شرائك','Follow your purchase');$copy=PortalAccess::t('اطلع على المرحلة الحالية والمبالغ المتحقق منها والمستندات المتاحة.','See the current stage, verified amounts and available documents.');$action=PortalAccess::t('عرض عملية الشراء','View purchase');$url=PortalAccess::url('purchases',array('sale'=>(int)$purchase['id']));}
        else{$title=PortalAccess::t('ابدأ خطوتك القادمة','Take your next step');$copy=PortalAccess::t('استعرض السيارات واحفظ ما يناسبك، أو ابدأ استفسارًا مع فريقنا.','Browse cars and save your choices, or start an enquiry with our team.');$action=PortalAccess::t('استعرض السيارات','Browse cars');$url=self::cars_url();}
        echo '<section class="adc-p-card adc-p-next"><span class="adc-p-eyebrow">'.$t('خطوتك التالية','Your next step').'</span><h3>'.esc_html($title).'</h3><p>'.esc_html($copy).'</p><a class="adc-p-button" href="'.esc_url($url).'">'.esc_html($action).'</a></section>';
        echo '<div class="adc-p-highlights">';
        if($booking){$date=$booking['requested_date'].' '.$booking['requested_time'];echo '<section class="adc-p-card"><h3>'.$t('أقرب تجربة قيادة','Next test drive').'</h3><p>'.esc_html(self::local_time($date)).'</p><p class="adc-p-meta">'.esc_html(wp_timezone_string()).' · '.esc_html('confirmed'===$booking['status']?PortalAccess::t('مؤكد','Confirmed'):PortalAccess::t('بانتظار التأكيد','Awaiting confirmation')).'</p><a href="'.esc_url(PortalAccess::url('bookings',array('request'=>(int)$booking['id']))).'">'.$t('التفاصيل وتعديل الموعد','Details & rescheduling').'</a></section>';}
        if($purchase){echo '<section class="adc-p-card"><h3>'.$t('آخر عملية شراء نشطة أو حديثة','Current or latest purchase').'</h3><p><bdi>'.esc_html($purchase['quote_number']).'</bdi> · '.esc_html(CustomerJourney::status($purchase['status'])).'</p><a href="'.esc_url(PortalAccess::url('purchases',array('sale'=>(int)$purchase['id']))).'">'.$t('متابعة التفاصيل','View progress').'</a></section>';}
        echo '</div><section class="adc-p-card"><h3>'.$t('مستجدات حسابك','Your account activity').'</h3><p class="adc-p-help">'.$t('أحدث السجلات وحالتها الحالية. علامة «جديد» تخص المحادثات والتقييمات غير المقروءة.','Latest records and their current status. New marks unread conversations and review updates.').'</p>';
        if(!$data['events']){PortalView::empty('لم تبدأ أي نشاط بعد. يمكنك استعراض السيارات أو بدء استفسار.','No activity yet. Browse cars or start an enquiry.');echo '<a class="adc-p-button is-secondary" href="'.esc_url(PortalAccess::url('messages')).'">'.$t('ابدأ استفسارًا','Start an enquiry').'</a>';}
        else{echo '<ol class="adc-p-activity">';foreach($data['events'] as $event){$status=in_array($event['section'],array('requests','bookings'),true)?PortalView::request_status($event['status']):('purchases'===$event['section']?CustomerJourney::status($event['status']):$event['status']);echo '<li><div><a href="'.esc_url(PortalAccess::url($event['section'],$event['args'])).'">'.esc_html($event['label']).'</a> ';if($event['unread']){echo '<span class="adc-p-unread">'.$t('جديد','New').'</span>';}if($status){echo '<span class="adc-p-meta">'.esc_html($status).'</span>';}echo '</div><time datetime="'.esc_attr(get_date_from_gmt($event['date'],'c')).'">'.esc_html(get_date_from_gmt($event['date'],'d/m/Y H:i')).'</time></li>';}echo '</ol>';}
        echo '</section><h3>'.$t('ملخص حسابك','Account summary').'</h3><div class="adc-p-stats">';
        $labels=array('requests'=>array('طلبات قيد المتابعة','Open enquiries'),'bookings'=>array('مواعيد قادمة','Upcoming appointments'),'messages'=>array('محادثات غير مقروءة','Unread conversations'),'favorites'=>array('سيارات محفوظة','Saved cars'),'purchases'=>array('عمليات شراء','Purchases'),'documents'=>array('مستندات متاحة','Available documents'));
        foreach($labels as $key=>$label){$amount=$data['counts'][$key];$filters=array('requests'=>'open','bookings'=>'upcoming','messages'=>'unread');echo '<a class="adc-p-stat" href="'.esc_url(PortalAccess::url($key,isset($filters[$key])?array('status'=>$filters[$key]):array())).'"><strong>'.esc_html(null===$amount?'—':number_format_i18n($amount)).'</strong><span>'.esc_html(PortalAccess::t(...$label)).'</span></a>';}
        echo '</div>';
    }
    public static function cars_url():string {return function_exists('car_dealer_archive_url')?car_dealer_archive_url('car'):(get_post_type_archive_link('car')?:home_url('/'));}
}
