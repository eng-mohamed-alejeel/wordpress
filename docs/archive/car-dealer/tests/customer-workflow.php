<?php
/** Run with PHP CLI against the local WordPress installation. Fixtures are removed. */
if ( PHP_SAPI !== 'cli' ) { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
function workflow_assert($value, $label) { if (!$value) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
$users = array(); $posts = array(); $rows = array(); $suffix = wp_generate_password(12, false); $password = wp_generate_password(24, true);
global $wpdb;
try {
 foreach (array('car_dealer_customer','car_dealer_customer','car_dealer_manager') as $role) {
  $id = wp_insert_user(array('user_login'=>'workflow_' . count($users) . '_' . $suffix,'user_email'=>'workflow_' . count($users) . '_' . $suffix . '@example.invalid','user_pass'=>$password,'display_name'=>'Workflow Test','role'=>$role));
  workflow_assert(!is_wp_error($id),'Create temporary ' . $role); $users[]=$id;
 }
 update_user_meta($users[0],'car_dealer_phone','0500000001');
 $crm = car_dealer_customer_crm($users[0]); $posts[]=$crm;
 workflow_assert($crm && (int)car_dealer_crm_meta($crm,'user_id')===$users[0],'Account linked to CRM by ID');
 wp_update_user(array('ID'=>$users[0],'display_name'=>'Updated Customer'));
 update_user_meta($users[0],'car_dealer_phone','0500000002');
 workflow_assert(get_post_field('post_title',$crm)==='Updated Customer' && car_dealer_crm_meta($crm,'phone')==='0500000002','Profile changes synchronize to CRM');
 $car=wp_insert_post(array('post_type'=>'car','post_status'=>'publish','post_title'=>'Workflow Test Car')); $posts[]=$car;
 $date=wp_date('Y-m-d',time()+7*DAY_IN_SECONDS);
 foreach (array('message','booking') as $type) {
  $table=car_dealer_request_table($type);
  $data=array('user_id'=>$users[0],'car_id'=>$car,'name'=>'Updated Customer','email'=>get_userdata($users[0])->user_email,'phone'=>'0500000002','status'=>$type==='booking'?'pending':'new','customer_reply'=>'','created_at'=>current_time('mysql'));
  if ($type==='message') { $data['message']='Integration customer inquiry'; $data['lead_type']='purchase'; }
  else { $data['requested_date']=$date; $data['requested_time']='10:00'; }
  $wpdb->insert($table,$data); $id=$wpdb->insert_id; workflow_assert($id>0,'Create '.$type); $rows[$type]=$id;
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
  car_dealer_crm_capture($type,$row);
  workflow_assert(car_dealer_request_crm($type,$row)===$crm,'Request and booking share account CRM');
 }
 wp_set_current_user($users[1]);
 workflow_assert(is_wp_error(car_dealer_update_request('booking',$rows['booking'],array(),true)),'Other customer cannot cancel booking');
 workflow_assert(is_wp_error(car_dealer_update_request('message',$rows['message'],array('status'=>'completed'))),'Customer cannot use staff updates');
 workflow_assert(car_dealer_account_requests('messages')===array(),'Customer record isolation');
 wp_set_current_user($users[2]);
 workflow_assert(is_wp_error(car_dealer_update_request('booking',$rows['booking'],array('status'=>'confirmed','requested_date'=>'2020-01-01','requested_time'=>'10:00'))),'Past booking dates rejected');
 update_post_meta($car,'_car_inventory_status','sold');
 workflow_assert(is_wp_error(car_dealer_update_request('booking',$rows['booking'],array('status'=>'confirmed','requested_date'=>$date,'requested_time'=>'10:00'))),'Sold car cannot be confirmed');
 update_post_meta($car,'_car_inventory_status','available');
 workflow_assert(car_dealer_update_request('booking',$rows['booking'],array('status'=>'confirmed','requested_date'=>$date,'requested_time'=>'11:00','customer_reply'=>'Your appointment is confirmed'))===true,'Staff confirms and reschedules booking');
 workflow_assert(car_dealer_update_request('message',$rows['message'],array('status'=>'completed','customer_reply'=>'Your quote is ready'))===true,'Staff replies to customer inquiry');
 ob_start(); car_dealer_render_bookings_page(); $admin=ob_get_clean(); workflow_assert(strpos($admin,'car_dealer_request_update')!==false,'Staff action form renders');
 ob_start(); car_dealer_crm_related_requests($crm); $related=ob_get_clean(); workflow_assert(strpos($related,'request_id=')!==false,'CRM links back to source records');
 wp_set_current_user($users[0]);
 ob_start(); car_dealer_account_request_table('messages'); car_dealer_account_request_table('bookings'); $html=ob_get_clean();
 workflow_assert(strpos($html,'Your quote is ready')!==false && strpos($html,'Your appointment is confirmed')!==false && strpos($html,'11:00')!==false,'Replies and new appointment visible in customer account');
 workflow_assert(car_dealer_update_request('booking',$rows['booking'],array(),true)===true,'Owner cancels booking');
 $booking=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}car_dealer_bookings WHERE id=%d",$rows['booking']));
 workflow_assert($booking->status==='cancelled','Cancellation persisted for dealership');
 workflow_assert(is_wp_error(car_dealer_update_request('booking',$rows['booking'],array(),true)),'Repeated cancellation rejected');
 workflow_assert((int)get_comments(array('post_id'=>$crm,'type'=>'crm_activity','count'=>true))>=6,'Updates recorded in CRM history');
 // Exercise the real authenticated POST handlers and their CSRF protection.
 if (function_exists('curl_init')) {
  $staff_session=curl_init(); $customer_session=curl_init();
  $request=function($ch,$url,$data=null) {
   curl_setopt_array($ch,array(CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_POST=>$data!==null));
   if ($data!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($data));
   $body=curl_exec($ch); if($body===false) throw new RuntimeException(curl_error($ch));
   return array($body,curl_getinfo($ch,CURLINFO_HTTP_CODE));
  };
  $nonce=function($html) { preg_match('/name="_wpnonce" value="([^"]+)"/',$html,$matches); return $matches[1]??''; };
  foreach(array(array($staff_session,$users[2]),array($customer_session,$users[0])) as $pair) {
   [$html]=$request($pair[0],car_dealer_account_url('login'));
   [$html]=$request($pair[0],car_dealer_account_url('login'),array('_wpnonce'=>$nonce($html),'login'=>get_userdata($pair[1])->user_login,'password'=>$password));
   workflow_assert(strpos($html,'name="display_name"')!==false,'HTTP authenticated workspace');
  }
  [$html]=$request($staff_session,admin_url('admin.php?page=car-dealer-bookings&request_id='.$rows['booking']));
  $form=array('action'=>'car_dealer_request_update','_wpnonce'=>$nonce($html),'request_type'=>'booking','request_id'=>$rows['booking'],'status'=>'confirmed','requested_date'=>$date,'requested_time'=>'12:00','customer_reply'=>'HTTP staff confirmation');
  [$html,$code]=$request($staff_session,admin_url('admin-post.php'),$form);
  workflow_assert($code===200,'Staff HTTP update handler');
  [$html]=$request($customer_session,car_dealer_account_url());
  workflow_assert(strpos($html,'HTTP staff confirmation')!==false && strpos($html,'12:00')!==false,'Staff HTTP update visible in customer dashboard');
  [$html]=$request($customer_session,car_dealer_account_url(),array('_wpnonce'=>$nonce($html),'account_action'=>'cancel_booking','request_id'=>$rows['booking']));
  workflow_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}car_dealer_bookings WHERE id=%d",$rows['booking']))==='cancelled','HTTP customer cancellation reaches dealership');
  $form['_wpnonce']='invalid';
  [$html,$code]=$request($staff_session,admin_url('admin-post.php'),$form);
  workflow_assert($code===403,'Staff handler rejects invalid nonce');
  [$html,$code]=$request($customer_session,admin_url('admin-post.php'),$form);
  workflow_assert($code===403,'Customer cannot call staff handler');
  curl_close($staff_session); curl_close($customer_session);
 }
 // A guest using an account email must not be merged into the registered account CRM.
 $guest=clone $booking; $guest->id=999999999; $guest->user_id=0;
 car_dealer_crm_capture('booking',$guest); $guest_crm=car_dealer_request_crm('booking',$guest); $posts[]=$guest_crm;
 workflow_assert($guest_crm && $guest_crm!==$crm,'Guest email cannot claim account linkage');
} finally {
 foreach ($rows as $type=>$id) { $wpdb->delete(car_dealer_request_table($type),array('id'=>$id)); }
 foreach (array_unique($posts) as $id) { if ($id) wp_delete_post($id,true); }
 foreach ($users as $id) { wp_delete_user($id); }
}
