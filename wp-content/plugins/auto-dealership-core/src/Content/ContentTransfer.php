<?php
namespace AutoDealership\Content;
use AutoDealership\Admin\SiteContentPage;
defined('ABSPATH') || exit;

/** Bounded ZIP transfer. Assets travel as files; source attachment IDs never enter a target site. */
final class ContentTransfer {
	public static function boot(): void { add_action('adc_content_import_cleanup',[self::class,'cleanup']); }
	public static function cleanup(string $path): void {
		$parent=realpath(dirname($path)); $temp=realpath(sys_get_temp_dir());
		if($parent && $temp && $parent===$temp && str_starts_with(basename($path),'adc-content-import-') && is_file($path)) { unlink($path); }
	}
	private static function key(): string { return 'adc_content_import_'.get_current_user_id(); }
	public static function bundle(): array {
		$data=SiteContent::data(); $assets=[];
		$map=static function(int $id)use(&$assets): string { if(!$id) { return ''; } $path=get_attached_file($id); if(!$path || !is_file($path) || !in_array(wp_get_image_mime($path),['image/jpeg','image/png','image/webp','image/gif'],true)) { return ''; } $key='media/'.hash_file('sha256',$path).'.'.pathinfo($path,PATHINFO_EXTENSION); $assets[$key]=$path; return $key; };
		$data['hero_image']=$map((int)$data['hero_image']); foreach($data['team'] as &$row){$row['image']=$map((int)$row['image']);}unset($row);
		return ['manifest'=>['product'=>'auto-dealership-content','format'=>1,'content'=>$data],'assets'=>$assets];
	}
	public static function download(): void {
		if(!current_user_can('manage_options')) { wp_die('Forbidden'); }
		if(!class_exists('ZipArchive')) { wp_die(SiteContent::t('دعم ZIP مطلوب لتصدير المحتوى والصور.','ZIP support is required to export content and images.')); }
		$bundle=self::bundle(); $size=array_sum(array_map('filesize',$bundle['assets'])); if($size>20*1024*1024) { wp_die(SiteContent::t('يتجاوز مجموع الصور 20 ميغابايت؛ استخدم صورًا محسنة.','Images exceed 20 MB; use optimized images.')); }
		$path=tempnam(sys_get_temp_dir(),'adc-content-');$zip=new \ZipArchive();$zip->open($path,\ZipArchive::OVERWRITE);$zip->addFromString('content.json',wp_json_encode($bundle['manifest'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));foreach($bundle['assets'] as $key=>$file){$zip->addFile($file,$key);}$zip->close();
		nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="website-content.zip"');header('Content-Length: '.filesize($path));readfile($path);unlink($path);exit;
	}
	public static function inspect(string $path) {
		if(!class_exists('ZipArchive')) { return new \WP_Error('adc_content_zip',SiteContent::t('دعم ZIP غير متوفر على الخادم.','ZIP support is not available.')); }
		$zip=new \ZipArchive(); if(true!==$zip->open($path)) { return new \WP_Error('adc_content_zip','Invalid ZIP'); }
		try {
			if($zip->numFiles>102) { throw new \RuntimeException('Too many archive entries'); } $total=0;
			for($i=0;$i<$zip->numFiles;$i++) { $stat=$zip->statIndex($i);$total+=$stat['size'];if($stat['size']>8*1024*1024 || $total>24*1024*1024 || (!preg_match('~\Amedia/[a-f0-9]{64}\.(?:jpe?g|png|webp|gif)\z~i',$stat['name']) && 'content.json'!==$stat['name'])) { throw new \RuntimeException('Unsupported or oversized archive entry'); } }
			$raw=$zip->getFromName('content.json');if(!is_string($raw) || strlen($raw)>1024*1024) { throw new \RuntimeException('Missing or oversized manifest'); }
			$manifest=json_decode($raw,true);if(!is_array($manifest) || array_diff(array_keys($manifest),['product','format','content']) || ($manifest['product']??'')!=='auto-dealership-content' || 1!==($manifest['format']??0) || !is_array($manifest['content']??null)) { throw new \RuntimeException('Unsupported content format'); }
			$data=$manifest['content'];$assets=[];
			$validate=static function($key)use($zip,&$assets): int {
				if(''===$key || 0===$key) { return 0; }if(!is_string($key) || !preg_match('~\Amedia/([a-f0-9]{64})\.(?:jpe?g|png|webp|gif)\z~i',$key,$match)) { throw new \RuntimeException('Invalid asset reference'); }
				$bytes=$zip->getFromName($key);if(!is_string($bytes) || !hash_equals($match[1],hash('sha256',$bytes))) { throw new \RuntimeException('Missing or modified image'); }
				$info=@getimagesizefromstring($bytes);if(!$info || !in_array($info['mime'],['image/jpeg','image/png','image/webp','image/gif'],true) || $info[0]*$info[1]>40000000) { throw new \RuntimeException('Invalid or oversized image'); } $assets[$key]=['mime'=>$info['mime'],'bytes'=>strlen($bytes)];return 0;
			};
			$data['hero_image']=$validate($data['hero_image']??'');foreach($data['team']??[] as $i=>$r){$data['team'][$i]['image']=$validate($r['image']??'');}
			$clean=SiteContent::normalize($data);if(is_wp_error($clean)){return $clean;}return ['manifest'=>$manifest,'normalized'=>$clean,'assets'=>$assets];
		} catch(\Throwable $e) { return new \WP_Error('adc_content_zip',SiteContent::t('ملف المحتوى غير صالح أو يتجاوز الحدود: ','Invalid or oversized content file: ').$e->getMessage()); }
		finally { $zip->close(); }
	}
	public static function stage(array $file) {
		if(!current_user_can('manage_options')) { return new \WP_Error('adc_content_forbidden','Forbidden'); }
		if(!empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || ($file['size']??0)>24*1024*1024) { return new \WP_Error('adc_content_upload',SiteContent::t('اختر حزمة ZIP أصغر من 24 ميغابايت.','Choose a ZIP package smaller than 24 MB.')); }
		$check=self::inspect($file['tmp_name']);if(is_wp_error($check)){return $check;}
		$old=get_transient(self::key());if(is_array($old) && is_file($old['path'])){unlink($old['path']);}
		$path=rtrim(sys_get_temp_dir(),'/\\').DIRECTORY_SEPARATOR.'adc-content-import-'.bin2hex(random_bytes(16)).'.zip';$handle=fopen($path,'x');if(!$handle){return new \WP_Error('adc_content_upload','Could not stage upload');}fclose($handle);if(!copy($file['tmp_name'],$path)){unlink($path);return new \WP_Error('adc_content_upload','Could not stage upload');}
		$preview=['path'=>$path,'hash'=>hash_file('sha256',$path),'revision'=>SiteContent::revision(),'expires'=>time()+600];set_transient(self::key(),$preview,DAY_IN_SECONDS);wp_schedule_single_event(time()+660,'adc_content_import_cleanup',[$path]);return true;
	}
	public static function apply(string $hash,string $revision) {
		if(!current_user_can('manage_options')){return new \WP_Error('adc_content_forbidden','Forbidden');}
		$p=get_transient(self::key());if(!is_array($p) || $p['expires']<time() || !is_file($p['path']) || !hash_equals($p['hash'],$hash) || !hash_equals($hash,hash_file('sha256',$p['path'])) || !hash_equals($p['revision'],$revision) || !hash_equals(SiteContent::revision(),$revision)){return new \WP_Error('adc_content_preview',SiteContent::t('انتهت المعاينة أو تغير المحتوى؛ أعد رفع الملف.','Preview expired or content changed; upload again.'));}
		$check=self::inspect($p['path']);if(is_wp_error($check)){return $check;}
		$zip=new \ZipArchive();$zip->open($p['path']);$created=[];$map=[];$success=false;
		try {
			require_once ABSPATH.'wp-admin/includes/image.php';
			foreach($check['assets'] as $key=>$info) {
				$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$info['mime']];$upload=wp_upload_bits('content-'.substr(basename($key),0,16).'.'.$ext,null,$zip->getFromName($key));if($upload['error']){throw new \RuntimeException($upload['error']);}
				$id=wp_insert_attachment(['post_mime_type'=>$info['mime'],'post_title'=>SiteContent::t('صورة محتوى الموقع','Website content image'),'post_status'=>'inherit'],$upload['file'],0,true);if(is_wp_error($id)){unlink($upload['file']);throw new \RuntimeException($id->get_error_message());}$created[]=$id;wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$upload['file']));$map[$key]=$id;
			}
			$data=$check['manifest']['content'];$data['hero_image']=$map[$data['hero_image']]??0;foreach($data['team'] as &$r){$r['image']=$map[$r['image']]??0;}unset($r);
			$result=SiteContent::save($data,$revision);if(is_wp_error($result)){throw new \RuntimeException($result->get_error_message());}
			$success=true;delete_transient(self::key());return true;
		} catch(\Throwable $e) { foreach($created as $id){wp_delete_attachment($id,true);}return new \WP_Error('adc_content_import',$e->getMessage()); }
		finally{$zip->close();if($success && is_file($p['path'])){unlink($p['path']);}}
	}
	public static function screen(): void {
		echo '<h2>'.esc_html(SiteContent::t('نقل الأقسام والصور','Transfer sections and images')).'</h2><p>'.esc_html(SiteContent::t('تتضمن الحزمة أقسام الرئيسية والفريق والآراء والإحصاءات وصورها فقط. لا تتضمن حسابات أو بيانات عملاء أو إعدادات بريد. استيرادها يستبدل هذه الأقسام فقط؛ الفروع والسيارات والصفحات والهوية لا تتغير.','The package includes homepage settings, team, reviews, metrics and their images only. No accounts, customer records or mail settings. Import replaces these sections only; branches, vehicles, pages and identity remain unchanged.')).'</p>';
		foreach(['export'=>['تصدير المحتوى والصور','Export content and images'],'import_preview'=>['معاينة الاستيراد','Preview import']] as $op=>$label){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data"><input type="hidden" name="action" value="adc_site_content"><input type="hidden" name="tab" value="transfer"><input type="hidden" name="operation" value="'.esc_attr($op).'">';wp_nonce_field('adc_site_content');if('import_preview'===$op){echo '<input type="file" name="content_file" accept=".zip,application/zip" required>';}submit_button(SiteContent::t(...$label),'secondary');echo '</form>';}
		$p=get_transient(self::key());if(is_array($p) && $p['expires']>=time() && is_file($p['path'])){$check=self::inspect($p['path']);if(!is_wp_error($check)){echo '<h2>'.esc_html(SiteContent::t('راجع المحتوى قبل الاستبدال','Review before replacing content')).'</h2><p>'.esc_html(sprintf(SiteContent::t('الصور المرفقة: %d','Attached images: %d'),count($check['assets']))).'</p><pre class="adc-content-json">'.esc_html(wp_json_encode($check['manifest'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_site_content"><input type="hidden" name="tab" value="transfer"><input type="hidden" name="operation" value="import"><input type="hidden" name="revision" value="'.esc_attr($p['revision']).'"><input type="hidden" name="preview_hash" value="'.esc_attr($p['hash']).'">';wp_nonce_field('adc_site_content');submit_button(SiteContent::t('استبدال الأقسام بالمحتوى المعروض','Replace sections with displayed content'));echo '</form>';}}
	}
}
