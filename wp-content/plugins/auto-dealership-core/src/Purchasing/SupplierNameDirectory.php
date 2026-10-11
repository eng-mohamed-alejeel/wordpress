<?php
namespace AutoDealership\Purchasing;
use AutoDealership\Database\Schema;
use AutoDealership\Audit\AuditLog;
defined('ABSPATH')||exit;

/** A canonical name claim is locked inside the caller's supplier/import transaction. */
final class SupplierNameDirectory {
	public static function normalized(string $name):string{
		if(class_exists('Normalizer')){$name=\Normalizer::normalize($name,\Normalizer::FORM_C);}
		$name=preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u','',$name);
		return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)));
	}
	public static function claim(string $name){
		global $wpdb;
		if(!current_user_can('manage_options')&&!current_user_can('adc_manage_suppliers')){return new \WP_Error('adc_forbidden');}
		$key=hash('sha256',self::normalized($name));$keys=Schema::table('supplier_name_keys');
		if(''===self::normalized($name)||false===$wpdb->query($wpdb->prepare("INSERT INTO $keys(name_key,supplier_id) VALUES(%s,NULL) ON DUPLICATE KEY UPDATE name_key=VALUES(name_key)",$key))){return new \WP_Error('adc_supplier_identity');}
		$id=(int)$wpdb->get_var($wpdb->prepare("SELECT supplier_id FROM $keys WHERE name_key=%s FOR UPDATE",$key));
		if($id){return array('key'=>$key,'id'=>$id);}
		$matches=array();$rows=$wpdb->get_results('SELECT id,display_name FROM '.Schema::table('suppliers').' ORDER BY id',ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_supplier_identity');}
		foreach($rows?:array()as$row){if(self::normalized($row['display_name'])===self::normalized($name)){$matches[]=(int)$row['id'];}}
		if(count($matches)>1){return new \WP_Error('adc_supplier_ambiguous',__('More than one existing supplier matches this Onyx name. Resolve the duplicate supplier records before importing.','auto-dealership-core'));}
		$id=$matches[0]??0;
		if($id&&false===$wpdb->update($keys,array('supplier_id'=>$id),array('name_key'=>$key))){return new \WP_Error('adc_supplier_identity');}
		return array('key'=>$key,'id'=>$id);
	}
	public static function attach(string $key,int $id):bool{
		global $wpdb;
		return 1===$wpdb->query($wpdb->prepare('UPDATE '.Schema::table('supplier_name_keys').' SET supplier_id=%d WHERE name_key=%s AND supplier_id IS NULL',$id,$key));
	}
	public static function import(string $name){
		global $wpdb;$name=sanitize_text_field($name);if(''===trim($name)){return array('id'=>null,'created'=>false);}
		$claim=self::claim($name);if(is_wp_error($claim)){return $claim;}if($claim['id']){return array('id'=>$claim['id'],'created'=>false);}
		$now=current_time('mysql',true);$data=array('supplier_code'=>'ONYX-'.strtoupper(substr($claim['key'],0,40)),'display_name'=>$name,'notes'=>'','active'=>1,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now);
		if(1!==$wpdb->insert(Schema::table('suppliers'),$data)){return new \WP_Error('adc_supplier_identity');}$id=(int)$wpdb->insert_id;
		if(!self::attach($claim['key'],$id)||!AuditLog::record('supplier.onyx_created','supplier',$id,'Supplier name imported from Onyx',null,array('supplier_code'=>$data['supplier_code'],'display_name'=>$name))){return new \WP_Error('adc_supplier_identity');}
		return array('id'=>$id,'created'=>true);
	}
}
