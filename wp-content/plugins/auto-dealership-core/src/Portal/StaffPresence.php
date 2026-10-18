<?php
namespace AutoDealership\Portal;

use AutoDealership\Security\BranchScope;
defined('ABSPATH') || exit;

/** Receiving preference belongs to an authenticated session, not to every device. */
final class StaffPresence {
    private const STATE = 'adc_chat_accepting';
    private const LEASE = 'adc_chat_lease';
    public static function boot(): void {
        add_action('set_logged_in_cookie',array(self::class,'session_started'),10,6);
        add_action('clear_auth_cookie',array(self::class,'session_ended'));
        add_action('admin_bar_menu',array(self::class,'toolbar'),89);
    }
    public static function role(int $user): bool {
        return $user>0 && (user_can($user,'manage_options') || (user_can($user,'adc_view_conversations') && user_can($user,'adc_reply_conversations')));
    }
    public static function eligible(int $user): bool {
        if(!PortalAccess::ready() || !self::role($user)){return false;}
        if(user_can($user,'manage_options')){return true;}
        foreach(BranchScope::assigned_branches($user) as $branch){if(BranchScope::is_active($branch)){return true;}}
        return false;
    }
    public static function automatic(int $user): bool { return self::eligible($user) && !user_can($user,'manage_options'); }
    public static function session_started($cookie,$expire,$expiration,$user,$scheme,$token): void {
        if(!self::role((int)$user)){return;}
        $manager=\WP_Session_Tokens::get_instance((int)$user);$session=$manager->get($token);
        if(!$session || isset($session[self::STATE])){return;}
        $session[self::STATE]=self::automatic((int)$user);$session[self::LEASE]=hash('sha256',$token);$manager->update($token,$session);
        if(PortalAccess::ready()){self::lease((int)$user,$token,$session[self::STATE]);}
    }
    public static function accepting(): bool {
        $user=get_current_user_id();$token=wp_get_session_token();if(!self::eligible($user) || !$token){return false;}
        $manager=\WP_Session_Tokens::get_instance($user);$session=$manager->get($token);if(!$session){return false;}
        if(!array_key_exists(self::STATE,$session)){
            // Existing sessions keep a manual pause from the previous release; admins opt in.
            $session[self::STATE]=self::automatic($user) && '0'!==get_user_meta($user,'adc_chat_available',true);
            $session[self::LEASE]=hash('sha256',$token);$manager->update($token,$session);
        }
        return !empty($session[self::STATE]);
    }
    public static function set(bool $available) {
        $user=get_current_user_id();$token=wp_get_session_token();
        if(!self::eligible($user) || !$token){return PortalAccess::error('adc_portal_forbidden',403);}
        $manager=\WP_Session_Tokens::get_instance($user);$session=$manager->get($token);if(!$session){return PortalAccess::error('adc_portal_forbidden',403);}
        $session[self::STATE]=$available;$session[self::LEASE]=hash('sha256',$token);$manager->update($token,$session);
        return self::lease($user,$token,$available);
    }
    public static function heartbeat() {
        if(!self::role(get_current_user_id())){return true;}
        return self::lease(get_current_user_id(),wp_get_session_token(),self::accepting());
    }
    private static function lease(int $user,string $token,bool $available) {
        if(!$token || !PortalAccess::ready()){return PortalAccess::error('adc_portal_forbidden',403);}
        global $wpdb;
        $ok=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('presence').'(user_id,session_key,thread_id,available,typing_until,seen_at) VALUES(%d,%s,0,%d,0,%d) ON DUPLICATE KEY UPDATE available=VALUES(available),seen_at=VALUES(seen_at)',$user,hash('sha256',$token),(int)$available,time()));
        return false===$ok?PortalAccess::error():true;
    }
    public static function available(int $user,bool $receiving=true): bool {
        if(!self::eligible($user)){return false;}
        $keys=array();foreach(\WP_Session_Tokens::get_instance($user)->get_all() as $session){if((!$receiving || !empty($session[self::STATE])) && !empty($session[self::LEASE]) && ($session['expiration']??0)>time()){$keys[]=$session[self::LEASE];}}
        if(!$keys){return false;}
        global $wpdb;$placeholders=implode(',',array_fill(0,count($keys),'%s'));
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT 1 FROM '.PortalAccess::table('presence').' WHERE user_id=%d'.($receiving?' AND available=1':'').' AND seen_at>%d AND session_key IN ('.$placeholders.') LIMIT 1',array_merge(array($user,time()-40),$keys)));
    }
    public static function session_ended(): void {
        $token=wp_get_session_token();$user=get_current_user_id();if(!$token || !$user || !PortalAccess::ready()){return;}
        global $wpdb;$wpdb->delete(PortalAccess::table('presence'),array('user_id'=>$user,'session_key'=>hash('sha256',$token)));
    }
    public static function branches(): string {
        if(current_user_can('manage_options')){return PortalAccess::t('جميع الفروع النشطة','All active branches');}
        $names=array();foreach(PortalAccess::branches() as $branch){if(BranchScope::allows((int)$branch['id'])){$names[]=$branch['name'];}}
        return implode('، ',$names) ?: PortalAccess::t('لا يوجد فرع نشط مرتبط بحسابك','No active branch is assigned to your account');
    }
    public static function control(string $place='page'): void {
        if(!PortalAccess::ready() || !self::role(get_current_user_id())){return;}
        $available=self::accepting();$eligible=self::eligible(get_current_user_id());
        echo '<section class="adc-staff-presence" data-staff-presence data-placement="'.esc_attr($place).'" aria-label="'.esc_attr(PortalAccess::t('حالة استقبال المحادثات','Chat receiving status')).'"><div><strong>'.esc_html(wp_get_current_user()->display_name).'</strong><small>'.esc_html(self::branches()).'</small></div><button type="button" class="adc-chat-status-toggle" data-chat-availability aria-pressed="'.($available?'true':'false').'" '.($eligible?'':'disabled').'><span class="adc-staff-presence-dot" aria-hidden="true"></span><span data-availability-label>'.esc_html($available?PortalAccess::t('متاح للمحادثات','Available for chat'):PortalAccess::t('غير متاح للمحادثات','Unavailable for chat')).'</span></button><p class="adc-staff-presence-help">'.esc_html(PortalAccess::t('إيقاف الاستقبال يوقف إسناد محادثات جديدة، وتبقى محادثاتك الحالية متاحة للرد.','Pausing stops new chat assignments. You can still reply to existing conversations.')).'</p><p class="adc-staff-presence-feedback" role="status" aria-live="polite"></p></section>';
    }
    public static function toolbar($bar): void {
        if(!PortalAccess::ready() || !self::role(get_current_user_id())){return;}
        $available=self::accepting();
        $bar->add_node(array('id'=>'adc-chat-status','parent'=>'top-secondary','title'=>'<span class="adc-staff-presence-dot" aria-hidden="true"></span><span data-availability-label>'.esc_html($available?PortalAccess::t('متاح للمحادثات','Available for chat'):PortalAccess::t('غير متاح للمحادثات','Unavailable for chat')).'</span>','href'=>'#adc-chat-availability','meta'=>array('title'=>self::branches(),'class'=>'adc-staff-toolbar '.($available?'is-available':'is-paused'))));
    }
}
