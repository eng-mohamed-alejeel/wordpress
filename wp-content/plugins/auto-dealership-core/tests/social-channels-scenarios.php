<?php
/** Real saves only in the runner's disposable database. */
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Core\SocialChannels as Social;
global $wpdb;
$social_checks=0;$social_verify=static function($ok,$message)use(&$social_checks){if(!$ok){throw new RuntimeException($message);}++$social_checks;echo "PASS $message\n";};
$social_actor=get_current_user_id();$social_before=get_option(Social::URL_OPTION,null);$social_pref_before=get_option(Social::OPTION,null);$social_admin=get_user_by('login','verify_admin');wp_set_current_user($social_admin->ID);
try{
 update_option(Social::URL_OPTION,array('phone'=>'0500000000','description_en'=>'Keep this description','unknown_setting'=>'preserved'));
 $input=array('social_urls'=>array(),'preferences'=>wp_json_encode(Social::defaults()),'revision'=>Social::revision());foreach(Social::platforms()as$key=>$row){$input['social_urls'][$key]=$key==='whatsapp'?'0538722576':'https://'.$row['hosts'][0].'/test-account';}
 $social_verify(Social::save($input)===true,'Social accounts and visibility preferences save through the real service');
 $saved=get_option(Social::URL_OPTION);$social_verify($saved['x']==='https://x.com/test-account'&&$saved['whatsapp']==='966538722576','All platform URLs and normalized WhatsApp number persist');
 $social_verify($saved['phone']==='0500000000'&&$saved['unknown_setting']==='preserved'&&$saved['description_en']==='Keep this description','Social save preserves unrelated contact, appearance and future fields');
 $social_verify(is_wp_error(Social::save($input)),'Stale social settings cannot overwrite a newer save');
 $input['revision']=Social::revision();$prefs=Social::defaults();$prefs['order']=array_reverse($prefs['order']);$prefs['channels']['x']['footer']=false;$prefs['style']='brand';$input['preferences']=wp_json_encode($prefs);$social_verify(Social::save($input)===true&&Social::preferences()===$prefs,'Custom order, context visibility and brand style persist together');
 $input['revision']=Social::revision();$bad=$input;$bad['social_urls']['facebook']='https://evil.example/profile';$before_revision=Social::revision();$social_verify(is_wp_error(Social::save($bad))&&Social::revision()===$before_revision,'Invalid URL causes no partial account or preference writes');
 $input['social_urls']['facebook']='https://facebook.com/changed';$fault=static fn(string $sql):string=>str_contains($sql,'INSERT INTO')&&str_contains($sql,\AutoDealership\Database\Schema::table('audit_events'))?'INSERT INTO adc_missing_social_audit VALUES(1)':$sql;$wpdb->suppress_errors(true);add_filter('query',$fault);$failed=Social::save($input);remove_filter('query',$fault);$social_verify(is_wp_error($failed)&&Social::revision()===$before_revision,'Audit failure rolls social URLs and preferences back together');
 wp_set_current_user(0);$social_verify(is_wp_error(Social::save($input)),'Anonymous callers cannot save social accounts');wp_set_current_user($social_admin->ID);
 echo "Social channel persistence checks passed: $social_checks\n";
}finally{if(null===$social_before){delete_option(Social::URL_OPTION);}else{update_option(Social::URL_OPTION,$social_before);}if(null===$social_pref_before){delete_option(Social::OPTION);}else{update_option(Social::OPTION,$social_pref_before);}wp_set_current_user($social_actor);}
