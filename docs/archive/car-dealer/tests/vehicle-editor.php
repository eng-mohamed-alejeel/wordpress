<?php
if(PHP_SAPI!=='cli') { exit; }
require dirname(__DIR__,4).'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
function vehicle_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$pass=wp_generate_password(24);$user=wp_insert_user(array('user_login'=>'vehicle_test_'.uniqid(),'user_pass'=>$pass,'role'=>'car_dealer_manager'));$car=0;$term=0;
$ch=curl_init();curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>''));
$request=function($url,$data=null)use($ch){curl_setopt($ch,CURLOPT_URL,$url);curl_setopt($ch,CURLOPT_POST,$data!==null);if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($data));$html=curl_exec($ch);if($html===false)throw new RuntimeException(curl_error($ch));return $html;};
$nonce=function($html){preg_match('/name="_wpnonce" value="([^"]+)"/',$html,$m);return $m[1]??'';};
try{
 $html=$request(car_dealer_account_url('login'));$request(car_dealer_account_url('login'),array('_wpnonce'=>$nonce($html),'login'=>get_userdata($user)->user_login,'password'=>$pass));
 $url=admin_url('admin.php?page=car-dealer-add-car');$html=$request($url);
 $term_result=wp_insert_term('Vehicle test '.uniqid(),'car_brand');$term=$term_result['term_id'];
 $data=array('_wpnonce'=>$nonce($html),'car_dealer_add_car'=>1,'car_title'=>'Vehicle edit test','car_description'=>'Existing description','post_status'=>'draft','_car_price'=>'123456','_car_model'=>'Model test','_car_transmission'=>'automatic','_car_warranty'=>'Warranty test','_car_is_featured'=>1,'_car_features'=>array('Custom feature'),'car_brand'=>array($term));
 $html=$request($url,$data);parse_str(parse_url(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL),PHP_URL_QUERY),$query);$car=(int)($query['car_id']??0);
 vehicle_check($car>0,'Create redirects to editing same vehicle');
 vehicle_check(strpos($html,'value="123456"')!==false && strpos($html,'Existing description')!==false && strpos($html,'Warranty test')!==false,'Saved fields prefilled');
 vehicle_check(preg_match('/value="automatic"\s+selected=/',$html) && preg_match('/value="Custom feature"\s+checked=/',$html),'Select and custom feature prefilled');
 wp_set_current_user($user);vehicle_check(strpos(get_edit_post_link($car,'raw'),'car-dealer-add-car')!==false,'Edit links use dealership form');
 $html=$request(admin_url('post.php?post='.$car.'&action=edit'));vehicle_check(strpos(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL),'car-dealer-add-car')!==false,'Native editor URL redirects');
 $data['_wpnonce']=$nonce($html);$data['car_id']=$car;$data['car_title']='Updated vehicle';$data['_car_price']='98765';$data['car_brand']=array('');unset($data['_car_is_featured'],$data['_car_features']);
 $html=$request(car_dealer_vehicle_editor_url($car),$data);clean_post_cache($car);
 vehicle_check(get_post_field('post_title',$car)==='Updated vehicle' && get_post_meta($car,'_car_price',true)==='98765','Update persists on original ID');
 vehicle_check(get_post_meta($car,'_car_is_featured',true)==='0' && get_post_meta($car,'_car_features',true)===array(),'Unchecked flags and features clear');
 vehicle_check(wp_get_object_terms($car,'car_brand',array('fields'=>'ids'))===array(),'Cleared classification persists');
 $data['_wpnonce']='invalid';$request(car_dealer_vehicle_editor_url($car),$data);vehicle_check(curl_getinfo($ch,CURLINFO_HTTP_CODE)===403,'Invalid save nonce blocked');
}finally{if($car)wp_delete_post($car,true);if($term)wp_delete_term($term,'car_brand');wp_delete_user($user);curl_close($ch);}
