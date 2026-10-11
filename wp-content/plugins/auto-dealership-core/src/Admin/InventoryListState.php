<?php
namespace AutoDealership\Admin;
use AutoDealership\Inventory\InventoryWorkspace;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Security\BranchScope;
defined('ABSPATH') || exit;

/** Explain scoped results without disclosing inventory outside the employee's branches. */
final class InventoryListState {
	private static function t(string $ar,string $en):string{return \AutoDealership\Core\Localization::language()==='en'?$en:$ar;}
	public static function describe($result,string $phase='',bool $source=false,bool $filtered=false):array {
		if(is_wp_error($result)&&$result->get_error_code()==='adc_forbidden'){return array('message'=>self::t('لا تملك صلاحية عرض قائمة السيارات. اطلب من المسؤول مراجعة صلاحيات حسابك.','You do not have permission to view this vehicle list. Ask your administrator to review your access.'),'error'=>true,'link'=>false);}
		if(is_wp_error($result)){return array('message'=>self::t('تعذر تحميل بيانات السيارات. أعد تحميل الصفحة أو تواصل مع مسؤول النظام إذا استمر الخطأ.','Unable to load vehicle data. Reload the page or contact your administrator if the error persists.'),'error'=>true,'link'=>false);}
		if(!BranchScope::is_global()){$active=array_filter(BranchScope::assigned_branches(),static fn($id)=>BranchScope::is_active((int)$id));if(!$active){return array('message'=>self::t('لم يُعيّن لك فرع نشط. اطلب من المسؤول مراجعة إسناد الفروع لحسابك.','No active branch is assigned to your account. Ask your administrator to review your branch assignment.'),'error'=>false,'link'=>false);}}
		if($source&&!$filtered){$all=InventoryWorkspace::list(array('availability'=>'all'));if(is_wp_error($all)){return self::describe($all);}if($all['total']>0){return array('message'=>self::t('لا توجد سيارات بهذه الحالة في آخر مخزون أونكس. غيّر فلتر التوفر لعرض السجلات السابقة.','No vehicles have this status in the latest Onyx inventory. Change the availability filter to view historical records.'),'error'=>false,'link'=>false);}}
		if($filtered){return array('message'=>self::t('لا توجد سيارات تطابق البحث والفلاتر المختارة. عدّل الفلاتر أو أعد ضبط البحث.','No vehicles match your search and filters. Adjust filters or reset the search.'),'error'=>false,'link'=>false);}
		$operational=VehicleService::page_for_current_user(1);if(is_wp_error($operational)){return self::describe($operational);}
		if(!$source&&$operational['total']>0){$labels=array('received'=>self::t('لا توجد سيارات تنتظر توثيق الاستلام.','No vehicles are awaiting receipt.'),'inspection'=>self::t('لا توجد سيارات تنتظر الفحص.','No vehicles are awaiting inspection.'),'available'=>self::t('لا توجد سيارات مؤهلة للتحويل حاليًا. يلزم أن تكون متاحة تشغيليًا وموجودة في آخر مخزون أونكس.','No vehicles are currently eligible for transfer. Vehicles must be operationally available and present in the latest Onyx inventory.'));return array('message'=>$labels[$phase]??self::t('لا توجد سيارات في هذه القائمة حاليًا.','No vehicles are currently in this list.'),'error'=>false,'link'=>true);}
		$pending=current_user_can('adc_view_inventory')?InventoryWorkspace::list(array('completion'=>'pending','availability'=>'available')):array('total'=>0);if(is_wp_error($pending)){return self::describe($pending);}
		if($pending['total']>0){return array('message'=>sprintf(self::t('يوجد %d سيارة في مخزون أونكس تحتاج استكمال بياناتها التشغيلية. استكمل رقم الهيكل الكامل والفرع من «إدارة السيارات» أولًا.','%d Onyx vehicles need operational details. Complete their full VIN and branch in Vehicle management first.'),$pending['total']),'error'=>false,'link'=>true);}
		return array('message'=>BranchScope::is_global()?self::t('لا توجد سجلات سيارات لهذه القائمة حاليًا. راجع آخر رفع لأونكس وبيانات الاستكمال.','No vehicle records are currently available for this list. Review the latest Onyx upload and vehicle completion.'):self::t('لا توجد سجلات سيارات مرتبطة بفروعك لهذه القائمة. راجع مع المسؤول ربط مخازن أونكس بالفروع واستكمال بيانات السيارات.','No vehicle records are linked to your branches for this list. Ask your administrator to review warehouse mappings and vehicle completion.'),'error'=>false,'link'=>true);
	}
	public static function render($result,string $phase='',bool $source=false,bool $filtered=false):void {
		$state=self::describe($result,$phase,$source,$filtered);echo '<div class="adc-inventory-state"'.($state['error']?' role="alert"':' role="status"').'><p>'.esc_html($state['message']).'</p>';
		if($state['link']&&current_user_can('adc_view_inventory')){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=adc-inventory')).'">'.esc_html(self::t('فتح إدارة السيارات','Open vehicle management')).'</a>';}echo '</div>';
	}
	public static function pagination($result,string $slug,string $section=''):void {
		if(is_wp_error($result)||$result['pages']<2){return;}$args=array('page'=>$slug,'paged'=>99999999);if($slug==='adc-inventory'&&!empty($_GET['legacy'])){$args['legacy']=1;}if($section!==''){$args['section']=$section;}echo '<nav aria-label="'.esc_attr(self::t('صفحات السيارات','Vehicle pages')).'">'.wp_kses_post(paginate_links(array('base'=>str_replace('99999999','%#%',add_query_arg($args,admin_url('admin.php'))),'format'=>'','total'=>$result['pages'],'current'=>$result['page']))).'</nav>';
	}
}
