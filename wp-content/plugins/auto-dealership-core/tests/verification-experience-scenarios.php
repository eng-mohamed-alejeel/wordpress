<?php
/** Runs only in the disposable newsletter database. */
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Accounts\VerificationExperience as Experience;
use AutoDealership\Accounts\EmailVerification as Verification;
use AutoDealership\Database\Transaction;
wp_set_current_user(0);
$stamp=Experience::browser_stamp('account');
$check(!Experience::valid_browser_stamp($stamp,'account'),'Browser stamp cannot be submitted immediately');
sleep(2);
$check(Experience::valid_browser_stamp($stamp,'account')&&!Experience::valid_browser_stamp($stamp,'newsletter')&&!Experience::valid_browser_stamp($stamp.'a','account'),'Browser stamp is time checked, purpose bound and authenticated');
$wait_key=new ReflectionMethod(Experience::class,'cooldown_key');$wait_key->setAccessible(true);delete_transient($wait_key->invoke(null,'account'));
$check(true===Experience::reserve_resend('account')&&Experience::remaining('account')>0&&is_wp_error(Experience::reserve_resend('account')),'Rolling minute resend reservation rejects repeated requests independently of the email');
delete_transient($wait_key->invoke(null,'account'));
$correction_id=wp_insert_user(array('user_login'=>'correction_fixture','user_email'=>'correction-old@example.invalid','user_pass'=>'CorrectionSecure123!','role'=>'car_dealer_customer'));
update_user_meta($correction_id,'adc_email_verification_status','pending');update_user_meta($correction_id,'adc_preferred_language','en');
Transaction::begin();$original_issue=Verification::issue('account',$correction_id,'correction-old@example.invalid','en');$wpdb->query('COMMIT');
$correction_row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Verification::table()." WHERE purpose='account' AND subject_id=%d",$correction_id),ARRAY_A);
$proof_method=new ReflectionMethod(Verification::class,'proof');$proof_method->setAccessible(true);
$original_token=$correction_row['selector'].'.'.$proof_method->invoke(null,$correction_row);
$check(is_wp_error(Experience::change_email('correction-new@example.invalid')),'Email correction without an enrollment capability is rejected');
$ticket=bin2hex(random_bytes(32));$_COOKIE['adc_enrollment']=$ticket;set_transient('adc_enrollment_'.hash('sha256',$ticket),$correction_id,1800);
$check(Experience::enrollment_id()===$correction_id,'Original browser capability identifies only its pending enrollment');
$check(is_wp_error(Experience::change_email('correction-new@example.invalid'))&&get_userdata($correction_id)->user_email==='correction-old@example.invalid','Correction respects the per-account cooldown and rolls back the email on failure');
$wpdb->update(Verification::table(),array('created_at'=>'2000-01-01 00:00:00'),array('id'=>$correction_row['id']));
$check(true===Experience::change_email('correction-new@example.invalid')&&get_userdata($correction_id)->user_email==='correction-new@example.invalid'&&Verification::blocked($correction_id),'Authorized correction changes the pending address without activating it');
$check(is_wp_error(Verification::confirm($original_token)),'Correcting the email revokes the preceding proof');
$check(is_wp_error(Experience::change_email('account-security-new@example.invalid'))&&get_userdata($correction_id)->user_email==='correction-new@example.invalid','Correction cannot claim an email already assigned to another account');
$new_row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Verification::table().' WHERE id=%d',$correction_row['id']),ARRAY_A);
$new_token=$new_row['selector'].'.'.$proof_method->invoke(null,$new_row);
$check(!is_wp_error(Verification::confirm($new_token))&&Experience::enrollment_id()===0,'New email proof confirms the account and revokes correction access');
$check(is_wp_error(Experience::change_email('should-not-change@example.invalid'))&&get_userdata($correction_id)->user_email==='correction-new@example.invalid','Enrollment capability cannot change an activated account');
$check(Verification::confirmed_account_token($new_token)&&!Verification::confirmed_account_token($new_token.'x'),'Already-active recognition requires the genuine consumed account proof');
$check(true===Verification::request('account','correction-new@example.invalid')&&true===Verification::request('account','unknown-correction@example.invalid'),'Public resend response does not distinguish active and unknown accounts');
// Authenticate an expired proof before returning the specific expiration diagnosis.
$expired_id=wp_insert_user(array('user_login'=>'expired_fixture','user_email'=>'expired-fixture@example.invalid','user_pass'=>'ExpiredSecure123!','role'=>'car_dealer_customer'));
update_user_meta($expired_id,'adc_email_verification_status','pending');Transaction::begin();Verification::issue('account',$expired_id,'expired-fixture@example.invalid','en');$wpdb->query('COMMIT');
$expired_row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Verification::table()." WHERE purpose='account' AND subject_id=%d",$expired_id),ARRAY_A);
$expired_row['expires_at']='2000-01-01 00:00:00';$expired_proof=$proof_method->invoke(null,$expired_row);
$wpdb->update(Verification::table(),array('expires_at'=>$expired_row['expires_at'],'token_hash'=>hash('sha256',$expired_proof)),array('id'=>$expired_row['id']));
$expired_result=Verification::confirm($expired_row['selector'].'.'.$expired_proof);
$check(is_wp_error($expired_result)&&$expired_result->get_error_code()==='adc_verification_expired'&&Verification::blocked($expired_id),'Authentic expired proof gets a clear recovery message without activation');
$check(is_wp_error(Verification::confirm($expired_row['selector'].'.'.str_repeat('A',43)))&&Verification::confirm($expired_row['selector'].'.'.str_repeat('A',43))->get_error_code()==='adc_verification_invalid','Forged proof does not reveal expiration details');
wp_set_current_user($admin);ob_start();Experience::profile(get_userdata($expired_id));$diagnostic=ob_get_clean();
$check(str_contains($diagnostic,Experience::account_status((int)$expired_id))&&str_contains($diagnostic,'adc_verification_resend')&&str_contains($diagnostic,Verification::t('قبول SMTP لا يثبت','SMTP acceptance does not prove')),'Administrator sees pending status, safe resend and truthful SMTP diagnostics');
$check(!str_contains($diagnostic,$expired_proof)&&!str_contains($diagnostic,$expired_row['selector']),'Administrative diagnostics contain no activation selector or proof');
wp_set_current_user($correction_id);ob_start();Experience::profile(get_userdata($expired_id));$denied_profile=ob_get_clean();
$check($denied_profile==='','Customers cannot inspect administrative verification diagnostics');
wp_set_current_user(0);unset($_COOKIE['adc_enrollment']);
