<?php
namespace AutoDealership\Portal;
defined('ABSPATH') || exit;

/** Whitelisted personal GET filters. No actor or ownership parameter is accepted. */
final class AccountFilters {
    public static function context():string {
        $html='<div class="adc-p-account-context"><span>'.esc_html((AccountDashboard::has_work_capability(wp_get_current_user())?PortalAccess::t('إعدادات بيانات حسابك وأمانه ومظهره. أعمالك اليومية متاحة في لوحة العمل.','Manage your account details, security, and appearance here. Daily tasks are available in your work dashboard.'):PortalAccess::t('هذه مساحتك الشخصية؛ تعرض السجلات المرتبطة بحسابك فقط.','Your personal space shows only records linked to your account.'))).'</span>';
        if(AccountDashboard::available()){$html.='<a href="'.esc_url(AccountDashboard::url()).'">'.esc_html(PortalAccess::t('الانتقال إلى لوحة العمل','Open work dashboard')).'</a>';}
        return $html.'</div>';
    }
    public static function values(array $statuses=array()):array {
        $value=static fn($key)=>isset($_GET[$key])&&is_string($_GET[$key])?sanitize_text_field(wp_unslash($_GET[$key])):'';
        $status=$value('status');$from=$value('from');$to=$value('to');
        $valid=static function($date){$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $parsed&&$parsed->format('Y-m-d')===$date;};
        return array('search'=>mb_substr($value('search'),0,100),'status'=>isset($statuses[$status])?$status:'','from'=>$valid($from)?$from:'','to'=>$valid($to)?$to:'');
    }
    public static function args():array {
        $args=array();foreach(array('search','status','from','to','request','sale','kind') as $key){if(isset($_GET[$key])&&is_string($_GET[$key])&&''!==$_GET[$key]){$args[$key]=mb_substr(sanitize_text_field(wp_unslash($_GET[$key])),0,100);}}
        return $args;
    }
    public static function render(string $section,array $statuses=array(),bool $dates=true):void {
        $data=self::values($statuses);$t=static fn($ar,$en)=>esc_html(PortalAccess::t($ar,$en));
        echo '<form method="get" class="adc-p-account-filters"><input type="hidden" name="cd_account" value="dashboard"><input type="hidden" name="portal" value="'.esc_attr($section).'"><input type="hidden" name="lang" value="'.esc_attr(PortalAccess::language()).'"><label>'.$t('بحث','Search').'<input type="search" name="search" maxlength="100" value="'.esc_attr($data['search']).'"></label>';
        if($statuses){echo '<label>'.$t('الحالة / النوع','Status / type').'<select name="status"><option value="">'.$t('الكل','All').'</option>';foreach($statuses as $key=>$label){echo '<option value="'.esc_attr($key).'" '.selected($data['status'],$key,false).'>'.esc_html($label).'</option>';}echo '</select></label>';}
        if($dates){foreach(array('from'=>array('من تاريخ','From date'),'to'=>array('إلى تاريخ','To date')) as $key=>$label){echo '<label>'.esc_html(PortalAccess::t(...$label)).'<input type="date" name="'.esc_attr($key).'" value="'.esc_attr($data[$key]).'"></label>';}}
        echo '<button type="submit" class="adc-p-button is-secondary">'.$t('تطبيق','Apply').'</button><a href="'.esc_url(PortalAccess::url($section)).'">'.$t('مسح التصفية','Clear filters').'</a></form>';
    }
}
