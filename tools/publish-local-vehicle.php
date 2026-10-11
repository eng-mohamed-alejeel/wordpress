<?php
/** Explicit, audited publication of one local vehicle through the normal publishing service. */
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--publish'||!ctype_digit($argv[2]??'')){exit(1);}
require dirname(__DIR__).'/wp-load.php';
if(!in_array(parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true)){exit(2);}
$admins=get_users(array('role'=>'administrator','number'=>1,'orderby'=>'ID'));if(!$admins){exit(3);}wp_set_current_user($admins[0]->ID);
$id=(int)$argv[2];$post=get_post($id);if(!$post||$post->post_type!=='car'||!in_array($post->post_status,array('draft','pending','publish'),true)){exit(4);}
$input=array('post_id'=>(string)$id,'title'=>$post->post_title,'description'=>$post->post_content,'summary'=>$post->post_excerpt,'status'=>'publish','revision'=>\AutoDealership\Content\CatalogPublishingService::fingerprint($id),'thumbnail_id'=>(string)get_post_thumbnail_id($id),'gallery_ids'=>implode(',',(array)get_post_meta($id,'_car_gallery_ids',true)),'plate_type'=>(string)get_post_meta($id,'_car_plate_type',true),'features'=>get_post_meta($id,'_car_features',true),'featured'=>get_post_meta($id,'_car_featured',true),'demand'=>get_post_meta($id,'_car_demand',true)==='yes');
if(is_array($input['features'])){$input['features']=implode("\n",$input['features']);}else{$input['features']=(string)$input['features'];}
foreach(array('car_brand','car_category')as$taxonomy){$terms=wp_get_object_terms($id,$taxonomy,array('fields'=>'ids'));if(is_wp_error($terms)){exit(5);}$input[$taxonomy]=array_map('strval',$terms);}
$result=\AutoDealership\Content\CatalogPublishingService::save_car($input);
if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().': '.$result->get_error_message());exit(6);}
echo wp_json_encode(array('id'=>$id,'title'=>get_post($id)->post_title,'status'=>get_post_status($id),'url'=>get_permalink($id),'default_image'=>!get_post_thumbnail_id($id),'description_empty'=>get_post($id)->post_content===''),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
