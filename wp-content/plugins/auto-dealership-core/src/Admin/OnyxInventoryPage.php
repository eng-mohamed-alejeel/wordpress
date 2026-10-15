<?php
namespace AutoDealership\Admin;
use AutoDealership\Inventory\OnyxImportService;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Private upload staging: workbooks never enter publicly accessible media uploads. */
final class OnyxInventoryPage {
	/** The source workbook contract, in its original column order. */
	private static function columns(): array {
		return array(
			'source_identifier' => self::t('الهيكل', 'Chassis'),
			'brand' => self::t('الماركة', 'Brand'),
			'model' => self::t('الطراز', 'Model'),
			'trim_name' => self::t('الفئة', 'Trim'),
			'exterior_color' => self::t('اللون', 'Color'),
			'model_year' => self::t('الموديل', 'Model year'),
			'listed_price' => self::t('سعر البيع', 'Sale price'),
			'supplier_name' => self::t('المورد', 'Supplier'),
			'warehouse_name' => self::t('المخزن', 'Warehouse'),
			'notes' => self::t('ملاحظات', 'Notes'),
			'interior_color' => self::t('اللون الداخلي', 'Interior color'),
			'stock_age' => self::t('عمر المخزون', 'Stock age'),
		);
	}
	private static function stock_table(array $rows, bool $failed = false): void {
		$columns = self::columns();
		echo '<div role="region" tabindex="0" aria-label="'.esc_attr(self::t('جدول مخزون أونكس، قابل للتمرير أفقيًا', 'Onyx inventory table, horizontally scrollable')).'" style="overflow-x:auto;margin-block:12px"><table class="widefat striped adc-onyx-stock" style="min-width:1600px"><thead><tr>';
		foreach ($columns as $label) { echo '<th scope="col" style="white-space:nowrap">'.esc_html($label).'</th>'; }
		echo '</tr></thead><tbody>';
		if ($failed || !$rows) {
			echo '<tr><td colspan="12">'.esc_html($failed ? self::t('تعذر تحميل المخزون.', 'Inventory could not be loaded.') : self::t('لا توجد سجلات.', 'No records.')).'</td></tr>';
		} else {
			foreach ($rows as $row) {
				echo '<tr>';
				foreach ($columns as $field => $label) {
					$value = $row[$field] ?? '';
					if ('listed_price' === $field && '' !== $value) { $value = \AutoDealership\Pricing\Money::decimal((string)$value); }
					echo '<td style="white-space:pre-wrap;overflow-wrap:anywhere">';
					echo 'source_identifier' === $field ? '<code>'.esc_html((string)$value).'</code>'.(array_key_exists('present',$row)?'<br><small>'.esc_html($row['present']?self::t('متوفرة','Available'):self::t('مباعة حسب أونكس','Sold according to Onyx')).'</small>':'') : esc_html((string)$value);
					echo '</td>';
				}
				echo '</tr>';
			}
		}
		echo '</tbody></table></div>';
	}
	private static function t(string $ar,string $en):string{return \AutoDealership\Core\Localization::language()==='en'?$en:$ar;}
	public static function boot():void{add_action('admin_post_adc_onyx_drafts',array(self::class,'drafts'));add_action('admin_post_adc_onyx_upload',array(self::class,'upload'));add_action('admin_post_adc_onyx_apply',array(self::class,'apply'));add_action('admin_post_adc_onyx_model',array(self::class,'model'));add_action('admin_post_adc_onyx_mode',array(self::class,'mode'));}
	public static function drafts():void{self::guard('adc_onyx_drafts');$result=OnyxImportService::complete_model_drafts();if(is_wp_error($result)){self::redirect($result);}wp_safe_redirect(add_query_arg(array('page'=>'adc-onyx','drafts_created'=>$result['created']),admin_url('admin.php')));exit;}
	public static function model():void{
		global $wpdb;self::guard('adc_onyx_model');if(!current_user_can('edit_cars')){wp_die('Forbidden',403);}$id=absint($_POST['model_id']??0);
		if(!\AutoDealership\Database\Transaction::begin()){self::redirect(new \WP_Error('adc_onyx_database'));}
		$model=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('catalog_models').' WHERE id=%d FOR UPDATE',$id),ARRAY_A);
		if(!$model){$wpdb->query('ROLLBACK');self::redirect(new \WP_Error('adc_onyx_missing'));}
		$post=(int)$model['public_post_id'];if($post && get_post_type($post)!=='car'){$post=0;}if(!$post){$post=wp_insert_post(array('post_type'=>'car','post_status'=>'draft','post_title'=>\AutoDealership\Inventory\OnyxCatalogNames::for_model($id)),true);if(is_wp_error($post)||!$post||1!==$wpdb->update(Schema::table('catalog_models'),array('public_post_id'=>$post),array('id'=>$id))){$wpdb->query('ROLLBACK');self::redirect(new \WP_Error('adc_onyx_database'));}
			$term=wp_insert_term($model['brand'],'car_brand',array('slug'=>'onyx-brand-'.substr(hash('sha256',$model['brand']),0,16)));if(is_wp_error($term)&&$term->get_error_code()==='term_exists'){$term=array('term_id'=>(int)$term->get_error_data());}if(is_wp_error($term)||is_wp_error(wp_set_object_terms($post,array((int)$term['term_id']),'car_brand'))){$wpdb->query('ROLLBACK');clean_post_cache($post);self::redirect(new \WP_Error('adc_onyx_taxonomy'));}
		}
		if(!\AutoDealership\Database\Transaction::commit(static fn()=>\AutoDealership\Audit\AuditLog::record('inventory.model_page_created','catalog_model',$id,'Grouped model editorial draft',null,array('public_post_id'=>$post)))){clean_post_cache($post);self::redirect(new \WP_Error('adc_onyx_database'));}wp_safe_redirect(admin_url('post.php?post='.$post.'&action=edit'));exit;
	}
	public static function mode():void{
		global $wpdb;self::guard('adc_onyx_mode');$published=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('catalog_models')." m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_status='publish' AND p.post_type='car'");
		$posts=$wpdb->get_col('SELECT public_post_id FROM '.Schema::table('catalog_models').' WHERE public_post_id IS NOT NULL')?:array();$eligible=array_filter($posts,static fn($post)=>get_post_status((int)$post)==='publish'&&null!==\AutoDealership\Inventory\GroupedModelCatalog::view((int)$post));
		if(!$published||!$eligible){self::redirect(new \WP_Error('adc_onyx_publish_models_first'));}
		if(!\AutoDealership\Database\Transaction::begin()){self::redirect(new \WP_Error('adc_onyx_database'));}$before=get_option('adc_public_catalog_mode');update_option('adc_public_catalog_mode','models');
		if(!\AutoDealership\Database\Transaction::commit(static fn()=>\AutoDealership\Audit\AuditLog::record('inventory.model_catalog_enabled','configuration',0,'Published grouped model catalog selected',array('mode'=>$before),array('mode'=>'models')))){wp_cache_delete('adc_public_catalog_mode','options');wp_cache_delete('alloptions','options');self::redirect(new \WP_Error('adc_onyx_database'));}wp_safe_redirect(admin_url('admin.php?page=adc-onyx'));exit;
	}
	private static function guard(string $action):void{if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer($action);}
	private static function redirect($result):void{
		$args=array('page'=>'adc-onyx');if(is_wp_error($result)){$args['error']=$result->get_error_code();}else{$args['batch']=(int)$result['id'];}
		wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
	}
	public static function upload():void{
		self::guard('adc_onyx_upload');$file=$_FILES['workbook']??array();
		if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='xlsx'){self::redirect(new \WP_Error('adc_onyx_file'));}
		self::redirect(OnyxImportService::upload($file['tmp_name'],$file['name']));
	}
	public static function apply():void{self::guard('adc_onyx_apply');self::redirect(OnyxImportService::apply(absint($_POST['batch']??0)));}
	public static function render():void{
		global $wpdb;if(!current_user_can('manage_options')){wp_die('Forbidden',403);}
		echo '<div class="wrap"><h1>'.esc_html(self::t('مخزون أونكس','Onyx inventory')).'</h1><p>'.esc_html(self::t('المعرّف المختصر محفوظ كنص مع الأصفار والحروف ولا يحل محل رقم الهيكل الكامل. الاستيراد يحدّث أسعار السيارات المرتبطة الحاضرة للعروض الجديدة، ويحفظ أسعار العروض السابقة. الغياب يمنع التعامل الجديد ولا يسجّل بيعًا ماليًا أو يلغي الحجوزات.','Short identifiers preserve leading zeros and letters and do not replace the full VIN. Imports update prices of present linked vehicles for new quotations; existing quotation prices are preserved. Absence blocks new transactions and does not record a financial sale or cancel reservations.')).'</p>';
		if(isset($_GET['batch'])){$upload=OnyxImportService::get(absint($_GET['batch']));if(!is_wp_error($upload)&&$upload['status']==='applied'){echo '<div class="notice notice-success"><p>'.esc_html(self::t('اكتملت مطابقة المخزون والنشر التلقائي.','Inventory synchronization and automatic publication completed.')).'</p></div>';}}
		if(isset($_GET['error'])){echo '<div class="notice notice-error"><p>'.esc_html(self::t('تعذر التنفيذ. تحقق من الملف أو أعد معاينته بعد آخر تحديث. رمز الخطأ: ','Unable to proceed. Check the file or preview again after the latest update. Error: ')).esc_html(sanitize_key($_GET['error'])).'</p></div>';}
		$storage_issues = \AutoDealership\Inventory\OnyxModelDrafts::storage_issues();
		if ($storage_issues) {
			echo '<div class="notice notice-error"><p>' . esc_html(self::t('توقف استيراد المخزون لأن بعض جداول ووردبريس لا تدعم المعاملات أو تعذر التحقق منها. الملف ليس سبب هذا الخطأ. يلزم دعم InnoDB لجميع الجداول التالية قبل الاستيراد:', 'Inventory import is blocked because some WordPress tables are nontransactional or could not be verified. This is not a workbook error. These tables must support InnoDB:')) . '</p><ul>';
			foreach ($storage_issues as $table => $engine) { echo '<li><code>' . esc_html($table) . '</code>: ' . esc_html('unknown' === $engine ? self::t('تعذر التحقق', 'Could not verify') : $engine) . '</li>'; }
			echo '</ul></div>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_onyx_upload">';wp_nonce_field('adc_onyx_upload');
		echo '<p><label>'.esc_html(self::t('ملف أونكس XLSX','Onyx XLSX workbook')).' <input type="file" name="workbook" accept=".xlsx" required></label></p>';
		echo '<p>'.esc_html(self::t('الملف هو المخزون النهائي. عند رفعه تُطابق السيارات برقم الهيكل، وتُحدّث الأسعار والألوان، وتُنشر السيارات المتوفرة تلقائيًا. السيارات الغائبة تُعتبر مباعة حسب أونكس، وتُسحب صفحتها من النشر إذا لم يبقَ مخزون متاح. الوصف اختياري والصورة الافتراضية تُستخدم عند الحاجة.','This file is the complete current inventory. Uploading matches chassis identifiers, updates prices and colors, and automatically publishes available vehicles. Absent vehicles are sold according to Onyx; their page is unpublished when no available stock remains. Description is optional and the default image is used when needed.')).'</p>';submit_button(self::t('رفع ومطابقة المخزون والنشر تلقائيًا','Upload, synchronize inventory and publish automatically'));echo '</form>';
		$batch_id=absint($_GET['batch']??0);if($batch_id){$batch=OnyxImportService::get($batch_id);if(!is_wp_error($batch)){$summary=json_decode($batch['summary_json'],true);echo '<h2>'.esc_html(self::t('نتيجة رفع المخزون','Inventory upload result')).' #'.$batch_id.'</h2><p>'.esc_html($batch['file_name'].' | '.$batch['import_mode'].' | '.$batch['status']).'</p><ul>';foreach(array('rows'=>self::t('السجلات','Rows'),'new'=>self::t('جديدة','New'),'updated'=>self::t('محدثة','Updated'),'unchanged'=>self::t('دون تغيير','Unchanged'),'missing'=>self::t('غائبة','Absent'),'model_pages_created'=>self::t('صفحات سيارات جديدة','New vehicle pages'),'published'=>self::t('صفحات نُشرت تلقائيًا','Automatically published pages'),'unpublished'=>self::t('صفحات سُحبت من النشر','Unpublished pages'),'available_pages'=>self::t('الصفحات المتوفرة','Available pages'),'model_pages_retained'=>self::t('صفحات الطرازات الموجودة','Existing model pages retained'))as$key=>$label){echo '<li>'.esc_html($label).': '.(int)($summary[$key]??0).'</li>';}echo '</ul>';
			foreach($summary['errors']??array()as$error){echo '<p class="notice notice-error">'.esc_html(self::t('السطر','Row').' '.$error['line'].' | '.$error['identifier'].' | '.implode(', ',$error['fields'])).'</p>';}
			$preview_rows = json_decode($batch['rows_json'], true) ?: array();
			$preview_page = max(1, absint($_GET['preview_paged'] ?? 1));
			echo '<p>'.esc_html(self::t('معاينة الحقول كما وردت في ملف أونكس. سعر البيع بالريال السعودي.', 'Preview of the original Onyx fields. Sale prices are in SAR.')).'</p>';
			self::stock_table(array_slice($preview_rows, ($preview_page - 1) * 50, 50));
			echo wp_kses_post(paginate_links(array('base'=>str_replace('99999999','%#%',add_query_arg(array('page'=>'adc-onyx','batch'=>$batch_id,'preview_paged'=>99999999),admin_url('admin.php'))),'format'=>'','current'=>$preview_page,'total'=>max(1,(int)ceil(count($preview_rows)/50)))));

		}}
		$search=sanitize_text_field(wp_unslash($_GET['s']??''));$page=max(1,absint($_GET['paged']??1));$stock_status=sanitize_key($_GET['stock_status']??'available');if(!in_array($stock_status,array('available','sold','all'),true)){$stock_status='available';}$where=$stock_status==='all'?'1=1':($stock_status==='sold'?'present=0':'present=1');if($search!==''){$pattern='%'.$wpdb->esc_like($search).'%';$where.=$wpdb->prepare(' AND (source_identifier LIKE %s OR brand LIKE %s OR model LIKE %s OR warehouse_name LIKE %s)',$pattern,$pattern,$pattern,$pattern);}
		$table=Schema::table('onyx_stock');$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT 50 OFFSET %d",($page-1)*50),ARRAY_A);
		echo '<h2>'.esc_html(self::t('السجلات المستوردة','Imported stock')).' ('.$total.')</h2><form method="get"><input type="hidden" name="page" value="adc-onyx"><select name="stock_status" aria-label="'.esc_attr(self::t('حالة المخزون','Inventory status')).'"><option value="available" '.selected($stock_status,'available',false).'>'.esc_html(self::t('المخزون الحالي','Current inventory')).'</option><option value="sold" '.selected($stock_status,'sold',false).'>'.esc_html(self::t('المباعة حسب أونكس','Sold according to Onyx')).'</option><option value="all" '.selected($stock_status,'all',false).'>'.esc_html(self::t('كل السجلات','All records')).'</option></select> <input name="s" value="'.esc_attr($search).'" aria-label="'.esc_attr(self::t('بحث','Search')).'"> <button class="button">'.esc_html(self::t('بحث','Search')).'</button></form>';
		$failed = (bool)$wpdb->last_error;
		self::stock_table($rows ?? array(), $failed);
		echo wp_kses_post(paginate_links(array('base'=>str_replace('99999999','%#%',add_query_arg(array('page'=>'adc-onyx','s'=>$search,'stock_status'=>$stock_status,'paged'=>99999999),admin_url('admin.php'))),'format'=>'','current'=>$page,'total'=>max(1,(int)ceil($total/50)))));
		$history=$wpdb->get_results('SELECT id,file_name,import_mode,status,created_at FROM '.Schema::table('onyx_imports').' ORDER BY id DESC LIMIT 20',ARRAY_A);echo '<h2>'.esc_html(self::t('آخر عمليات الرفع','Recent imports')).'</h2><ul>';foreach($history??array()as$entry){echo '<li><a href="'.esc_url(add_query_arg(array('page'=>'adc-onyx','batch'=>$entry['id']),admin_url('admin.php'))).'">'.esc_html('#'.$entry['id'].' '.$entry['file_name'].' '.$entry['status'].' '.$entry['created_at']).'</a></li>';}echo '</ul>';
		$stock=Schema::table('onyx_stock');$models=Schema::table('catalog_models');
		$summary=$wpdb->get_row("SELECT (SELECT COUNT(*) FROM $stock WHERE present=1) available, (SELECT COUNT(*) FROM $stock WHERE present=0) sold, (SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN $models m ON m.public_post_id=p.ID WHERE p.post_type='car' AND p.post_status='publish') published",ARRAY_A);
		echo '<section class="adc-onyx-summary"><h2>'.esc_html(self::t('ملخص المخزون وسيارات الموقع','Inventory and website vehicles')).'</h2>';
		if($wpdb->last_error||!$summary){echo '<p role="alert">'.esc_html(self::t('تعذر تحميل ملخص المخزون.','Unable to load inventory summary.')).'</p>';}
		else{echo '<p>'.esc_html(sprintf(self::t('المخزون الحالي: %1$d سيارة • المباعة حسب أونكس: %2$d • صفحات السيارات المنشورة: %3$d','Current inventory: %1$d vehicles • Sold according to Onyx: %2$d • Published vehicle pages: %3$d'),$summary['available'],$summary['sold'],$summary['published'])).'</p>';}
		echo '<p>'.esc_html(self::t('يُحدّث توفر السيارات وأسعارها ونشر صفحاتها تلقائيًا عند اكتمال رفع ملف أونكس.','Availability, prices and page publication update automatically after a successful Onyx upload.')).'</p>';
		if(current_user_can('edit_cars')){echo '<a class="button button-primary" href="'.esc_url(CatalogPublishingPage::url()).'">'.esc_html(self::t('عرض سيارات الموقع','View website vehicles')).'</a>';}
		echo '</section></div>';
	}
}
