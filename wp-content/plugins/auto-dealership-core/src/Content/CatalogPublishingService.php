<?php
namespace AutoDealership\Content;

use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Inventory\GroupedModelCatalog;
use AutoDealership\Audit\AuditLog;

defined( 'ABSPATH' ) || exit;

/** Dedicated editorial writes, preserving public IDs and net inventory prices. */
final class CatalogPublishingService {
	public static function fingerprint( int $id ): string {
		$post = get_post( $id );
		return hash( 'sha256', wp_json_encode( array( $post ? $post->to_array() : null, get_post_meta( $id ), wp_get_object_terms( $id, array( 'car_brand', 'car_category' ), array( 'fields'=>'ids' ) ) ) ) );
	}
	public static function term_fingerprint( int $id, string $taxonomy ): string {
		$term = get_term( $id, $taxonomy, ARRAY_A, 'raw' );
		return hash( 'sha256', wp_json_encode( array( $term, get_term_meta( $id ) ) ) );
	}
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( $code, $message ); }
	private static function begin(): bool {
		global $wpdb;
		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) { return false; }
		}
		return Transaction::begin();
	}
	private static function image( $id ): bool { return is_numeric( $id ) && (int)$id >= 0 && ( 0 === (int)$id || ( 'attachment' === get_post_type( (int)$id ) && wp_attachment_is_image( (int)$id ) && current_user_can( 'read_post', (int)$id ) ) ); }

	public static function save_car( array $input ) {
		global $wpdb;
		foreach(array('post_id','model_id','thumbnail_id')as$field){if(isset($input[$field])&&(!is_scalar($input[$field])||!ctype_digit((string)$input[$field]))){return self::error('adc_invalid',__('Invalid vehicle publishing fields.','auto-dealership-core'));}}
		$id = absint( $input['post_id'] ?? 0 );
		$type = get_post_type_object( 'car' );
		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) || ( $id && ( 'car' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) ) || ( ! $id && ! current_user_can( $type->cap->create_posts ) ) ) { return self::error( 'adc_forbidden', __( 'You cannot edit this vehicle page.', 'auto-dealership-core' ) ); }
		foreach ( array( 'title','description','summary','status','revision','plate_type','features','gallery_ids' ) as $field ) { if ( isset( $input[$field] ) && ! is_string( $input[$field] ) ) { return self::error( 'adc_invalid', __( 'Invalid vehicle publishing fields.', 'auto-dealership-core' ) ); } }
		$title = sanitize_text_field( $input['title'] ?? '' );
		$source_title=$id?\AutoDealership\Inventory\OnyxCatalogNames::for_post($id):\AutoDealership\Inventory\OnyxCatalogNames::for_model(absint($input['model_id']??0));
		if($source_title){$title=$source_title;}
		$description = wp_kses_post( $input['description'] ?? '' );
		$status = $input['status'] ?? 'draft';
		$plate = $input['plate_type'] ?? '';
		if ( '' === $title || mb_strlen($title)>($source_title?4000:200) || strlen($description)>200000 || ! in_array( $status, array('draft','pending','publish','private','future'), true ) || ! in_array( $plate, array('','private_transport','commercial_transport'), true ) || ( 'future' === $status && ( ! $id || get_post_status($id)!=='future' ) ) ) { return self::error( 'adc_invalid', __( 'Invalid vehicle publishing fields.', 'auto-dealership-core' ) ); }
		if ( in_array($status,array('publish','private','future'),true) && ! current_user_can($type->cap->publish_posts) ) { return self::error( 'adc_forbidden', __( 'You cannot publish vehicle pages.', 'auto-dealership-core' ) ); }
		$save_general_media=array_key_exists('thumbnail_id',$input)||array_key_exists('gallery_ids',$input);
		$thumbnail = $input['thumbnail_id'] ?? 0;
		$gallery = array_filter(explode(',', $input['gallery_ids'] ?? ''));
		if ( ! self::image($thumbnail) || count($gallery)>20 ) { return self::error('adc_images', __( 'Select valid vehicle images from the media library.', 'auto-dealership-core')); }
		foreach ($gallery as $image) { if (!self::image($image) || (int)$image<1) { return self::error('adc_images', __( 'Select valid vehicle images from the media library.', 'auto-dealership-core')); } }
		$gallery=array_values(array_unique(array_map('intval',$gallery)));
		$color_galleries=null;$color_mains=null;$default_color=null;
		if(isset($input['color_galleries_present'])){
			$color_galleries=\AutoDealership\Inventory\VehicleColors::validate_galleries($input['color_galleries']??array());
			if(is_wp_error($color_galleries)){return $color_galleries;}
		}
		if(isset($input['color_primary_present'])){
			$color_mains=\AutoDealership\Inventory\VehicleColors::validate_primary_images($input['color_main']??array(),$color_galleries??array());
			if(is_wp_error($color_mains)){return $color_mains;}
			foreach($color_mains as$color_key=>$main){$color_galleries[$color_key]=array_values(array_unique(array_merge($main?array($main):array(),$color_galleries[$color_key])));}
			$default_color=$input['default_color']??'';
			$known=array_map(array(\AutoDealership\Inventory\VehicleColors::class,'gallery_key'),\AutoDealership\Inventory\VehicleColors::source_names($id));
			if(!is_string($default_color)||($default_color!==''&&(!preg_match('/^[a-f0-9]{64}$/D',$default_color)||!in_array($default_color,$known,true)))){return self::error('adc_colors',(\AutoDealership\Core\Localization::language()==='en'?'Choose a default color from this vehicle’s Onyx colors.':'اختر لون العرض الافتراضي من ألوان هذه السيارة في أونكس.'));}
		}
		$terms=array();
		foreach(array('car_brand','car_category')as$taxonomy){
			if(!array_key_exists($taxonomy,$input)){continue;}
			if(!current_user_can(get_taxonomy($taxonomy)->cap->assign_terms)){return self::error('adc_forbidden',__('You cannot assign vehicle classifications.','auto-dealership-core'));}
			if(!is_array($input[$taxonomy])||count($input[$taxonomy])>20){return self::error('adc_invalid',__('Invalid vehicle publishing fields.','auto-dealership-core'));}
			$terms[$taxonomy]=array();foreach($input[$taxonomy]as$term){if(!is_scalar($term)||!ctype_digit((string)$term)||!term_exists((int)$term,$taxonomy)){return self::error('adc_invalid',__('Invalid vehicle publishing fields.','auto-dealership-core'));}$terms[$taxonomy][]=(int)$term;}
		}
		if ( ! self::begin() ) { return self::error('adc_storage',__('Publishing storage is unavailable.','auto-dealership-core')); }
		$new = !$id; $term_ids=array();
		try {
			if($id){
				if(!$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d AND post_type='car' FOR UPDATE",$id))){throw new \RuntimeException('missing');}
				clean_post_cache($id);
				if(!hash_equals(self::fingerprint($id),$input['revision']??'')){ $wpdb->query('ROLLBACK');return self::error('adc_stale',__('This page changed. Reload it before saving.','auto-dealership-core')); }
				$term_ids=wp_get_object_terms($id,array('car_brand','car_category'),array('fields'=>'ids'));
				if(is_wp_error($term_ids)){throw new \RuntimeException('terms');}
				if(get_post_status($id)==='trash'){throw new \RuntimeException('trash');}
			}else{
				$model=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('catalog_models').' WHERE id=%d FOR UPDATE',absint($input['model_id']??0)),ARRAY_A);
				if(!$model || ($model['public_post_id'] && get_post_type((int)$model['public_post_id'])==='car')){$wpdb->query('ROLLBACK');return self::error('adc_model',__('Select an Onyx model that does not already have a vehicle page.','auto-dealership-core'));}
				$id=wp_insert_post(wp_slash(array('post_type'=>'car','post_status'=>'draft','post_title'=>$title)),true);
				if(is_wp_error($id)||!$id||$wpdb->update(Schema::table('catalog_models'),array('public_post_id'=>$id),array('id'=>$model['id']))!==1){throw new \RuntimeException('insert');}
			}
			if('publish'===$status && GroupedModelCatalog::is_mapped($id) && !GroupedModelCatalog::view($id)){ $wpdb->query('ROLLBACK');clean_post_cache($id);return self::error('adc_stock',__('No available Onyx stock exists for this model.','auto-dealership-core')); }
			$result=wp_update_post(wp_slash(array('ID'=>$id,'post_title'=>$title,'post_content'=>$description,'post_excerpt'=>sanitize_textarea_field($input['summary']??''),'post_status'=>$status)),true);
			if(is_wp_error($result)||!$result){throw new \RuntimeException('update');}
			$meta=array('_car_plate_type'=>$plate,'_car_features'=>sanitize_textarea_field($input['features']??''),'_car_featured'=>!empty($input['featured'])?'1':'','_car_demand'=>!empty($input['demand'])?'yes':'');
			if($save_general_media){$meta['_thumbnail_id']=(int)$thumbnail;$meta['_car_gallery_ids']=$gallery;}
			if($color_galleries!==null){$existing_colors=get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true);$meta[\AutoDealership\Inventory\VehicleColors::META]=array_replace(is_array($existing_colors)?$existing_colors:array(),$color_galleries);}
			if($color_mains!==null){$existing_mains=get_post_meta($id,\AutoDealership\Inventory\VehicleColors::MAIN_META,true);$meta[\AutoDealership\Inventory\VehicleColors::MAIN_META]=array_replace(is_array($existing_mains)?$existing_mains:array(),$color_mains);$meta[\AutoDealership\Inventory\VehicleColors::DEFAULT_META]=$default_color;}
			foreach($meta as$key=>$value){update_post_meta($id,$key,$value);if((string)maybe_serialize(get_post_meta($id,$key,true))!==(string)maybe_serialize($value)){throw new \RuntimeException('meta');}}
			foreach($terms as$taxonomy=>$ids){$assigned=wp_set_object_terms($id,$ids,$taxonomy);if(is_wp_error($assigned)){throw new \RuntimeException('terms');}$term_ids=array_merge($term_ids,$ids);}
			if(!Transaction::commit(static fn()=>AuditLog::record('content.vehicle_published','car_post',$id,'Dedicated vehicle publishing',null,array('status'=>$status,'plate_type'=>$plate,'image_count'=>count($gallery),'created'=>$new)))){throw new \RuntimeException('audit');}
			clean_post_cache($id);
			if(($color_galleries!==null||$color_mains!==null)&&function_exists('car_dealer_clear_public_page_cache')){\car_dealer_clear_public_page_cache();}
			return array('id'=>$id,'created'=>$new);
		}catch(\Throwable){$wpdb->query('ROLLBACK');if(is_int($id)){clean_post_cache($id);}foreach(array_keys($terms)as$taxonomy){clean_term_cache($term_ids,$taxonomy);}return self::error('adc_save',__('The change could not be saved; reload and try again.','auto-dealership-core'));}
	}

	public static function change_status(int $id,string $action,string $revision){
		global $wpdb;
		if('car'!==get_post_type($id)||!current_user_can('delete_post',$id)||!in_array($action,array('trash','restore'),true)){return self::error('adc_forbidden',__('You cannot change this vehicle page.','auto-dealership-core'));}
		if(!self::begin()){return self::error('adc_storage',__('Publishing storage is unavailable.','auto-dealership-core'));}
		$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$id));clean_post_cache($id);
		if(!hash_equals(self::fingerprint($id),$revision)){ $wpdb->query('ROLLBACK');return self::error('adc_stale',__('This page changed. Reload it before saving.','auto-dealership-core')); }
		// WordPress permanently deletes instead of trashing when retention is disabled.
		if($action==='trash'&&defined('EMPTY_TRASH_DAYS')&&!EMPTY_TRASH_DAYS){$wpdb->query('ROLLBACK');return self::error('adc_trash_disabled',(\AutoDealership\Core\Localization::language()==='en'?'Trash is disabled. Enable trash retention before moving this vehicle page.':'السلة معطلة. فعّل الاحتفاظ بالمحذوفات قبل نقل صفحة السيارة للسلة.'));}
		$state=get_post_status($id);if(($action==='trash'&&$state==='trash')||($action==='restore'&&$state!=='trash')){$wpdb->query('ROLLBACK');return self::error('adc_stale',__('This page changed. Reload it before saving.','auto-dealership-core'));}
		$ok=$action==='trash'?wp_trash_post($id):wp_untrash_post($id);
		if($ok&&$action==='restore'){$ok=wp_update_post(array('ID'=>$id,'post_status'=>'draft'),true);if(is_wp_error($ok)){$ok=false;}}
		if(!$ok||!Transaction::commit(static fn()=>AuditLog::record('content.vehicle_'.$action,'car_post',$id,'Vehicle publication state changed'))){$wpdb->query('ROLLBACK');clean_post_cache($id);return self::error('adc_save',__('The change could not be saved; reload and try again.','auto-dealership-core'));}
		clean_post_cache($id);return array('id'=>$id);
	}

	public static function save_term(array $input){
		global $wpdb;
		foreach(array('term_id','parent','image_id')as$field){if(isset($input[$field])&&(!is_scalar($input[$field])||!ctype_digit((string)$input[$field]))){return self::error('adc_invalid',__('Invalid classification fields.','auto-dealership-core'));}}
		$taxonomy=$input['taxonomy']??'';$id=absint($input['term_id']??0);
		if(!is_string($taxonomy)||!in_array($taxonomy,array('car_brand','car_category'),true)||!current_user_can(get_taxonomy($taxonomy)->cap->manage_terms)){return self::error('adc_forbidden',__('You cannot manage vehicle classifications.','auto-dealership-core'));}
		foreach(array('name','slug','description','name_ar','name_en','revision')as$field){if(isset($input[$field])&&!is_string($input[$field])){return self::error('adc_invalid',__('Invalid classification fields.','auto-dealership-core'));}}
		$name=sanitize_text_field($input['name']??'');$parent=absint($input['parent']??0);$image=$input['image_id']??0;
		if(''===$name||mb_strlen($name)>200||!self::image($image)||($id&&(!get_term($id,$taxonomy)||is_wp_error(get_term($id,$taxonomy))))||($parent&&($taxonomy!=='car_category'||!term_exists($parent,$taxonomy)||$parent===$id||in_array($id,get_ancestors($parent,$taxonomy),true)))){return self::error('adc_invalid',__('Invalid classification fields.','auto-dealership-core'));}
		if(!self::begin()){return self::error('adc_storage',__('Publishing storage is unavailable.','auto-dealership-core'));}
		try{
			if($id){$wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->terms} WHERE term_id=%d FOR UPDATE",$id));clean_term_cache($id,$taxonomy);if(!hash_equals(self::term_fingerprint($id,$taxonomy),$input['revision']??'')){$wpdb->query('ROLLBACK');return self::error('adc_stale',__('This classification changed. Reload it before saving.','auto-dealership-core'));}}
			$args=array('name'=>$name,'slug'=>sanitize_title($input['slug']??''),'description'=>sanitize_textarea_field($input['description']??''),'parent'=>$parent);
			$result=$id?wp_update_term($id,$taxonomy,$args):wp_insert_term($name,$taxonomy,$args);
			if(is_wp_error($result)){$wpdb->query('ROLLBACK');clean_term_cache($id,$taxonomy);return $result;}
			$id=(int)$result['term_id'];
			$meta=array('_adc_catalog_image_id'=>(int)$image);foreach(array('ar','en')as$lang){$meta['_adc_name_'.$lang]=sanitize_text_field($input['name_'.$lang]??'');$meta['_adc_name_'.$lang.'_source_hash']=hash('sha256',$name);}
			foreach($meta as$key=>$value){update_term_meta($id,$key,$value);if((string)get_term_meta($id,$key,true)!==(string)$value){throw new \RuntimeException('term_meta');}}
			if(!Transaction::commit(static fn()=>AuditLog::record('content.classification_saved',$taxonomy,$id,'Vehicle classification saved',null,array('name'=>$name,'parent'=>$parent)))){throw new \RuntimeException('audit');}
			clean_term_cache($id,$taxonomy);return array('id'=>$id);
		}catch(\Throwable){$wpdb->query('ROLLBACK');clean_term_cache($id,$taxonomy);wp_cache_delete($id,'term_meta');return self::error('adc_save',__('The change could not be saved; reload and try again.','auto-dealership-core'));}
	}
}
