<?php
namespace AutoDealership\Content;

use AutoDealership\Core\BranchLocations;
use AutoDealership\Core\Localization;
use AutoDealership\Inventory\PublicCatalog;

defined('ABSPATH') || exit;

/** Lightweight homepage discovery, using published catalog and active branch data. */
final class HomeDiscovery {
	private static function t(string $ar,string $en): string { return 'en'===Localization::language()?$en:$ar; }
	private static function page(string $slug): string {
		$page=get_page_by_path($slug);
		return $page && 'publish'===$page->post_status ? add_query_arg('lang',Localization::language(),get_permalink($page)) : '';
	}
	private static function catalog(array $filters=[]): string {
		$url=get_post_type_archive_link('car');
		return $url ? add_query_arg(array_merge(['lang'=>Localization::language()],$filters),$url) : '';
	}
	private static function icon(string $key): string {
		$paths=['needs'=>'M4 7h16M7 4v6m10 4v6M4 17h16','journey'=>'m5 12 4 4L19 6','branches'=>'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'];
		return '<span class="adc-discovery-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="'.$paths[$key].'"/></svg></span>';
	}

	public static function step_copy(int $step,string $field,string $lang): string {
		if($step<1 || $step>3 || !in_array($field,['title','text'],true) || !in_array($lang,['ar','en'],true)) { return ''; }
		$steps=self::steps(); return $steps[$step-1][('text'===$field?2:0)+('en'===$lang?1:0)];
	}
	private static function steps(): array {
		return[
				['استكشف واختر','Explore and shortlist','استعرض السيارات وقارن المواصفات والأسعار المعلنة لتحدد الخيارات المناسبة.','Browse vehicles and compare specifications and published prices to shortlist your options.'],
				['استفسر وخطّط','Ask and plan','تواصل مع الفريق لتأكيد التوفر والسعر وتنسيق المعاينة أو طلب تجربة قيادة.','Contact our team to confirm availability and price, and arrange a visit or request a test drive.'],
				['راجع وأكمل الإجراءات','Review and proceed','راجع العرض والشروط مع الموظف المختص قبل الحجز أو إتمام الشراء.','Review the offer and terms with your assigned employee before reserving or purchasing.']
			];
	}
	public static function render(string $key): string {
		if(!in_array($key,['needs','journey','branches'],true) || !SiteContent::home_enabled($key)) { return ''; }
		$data=SiteContent::data(); $settings=$data['home'][$key];
		$limit=max(1,min(48,(int)$settings['count'])); $lang=Localization::language();
		$defaults=[
			'needs'=>['تصفح حسب احتياجك','Explore your options','ابدأ بالأحدث أو رتّب الخيارات حسب السعر، واستكشف الماركات المتوفرة.','Start with newer models, explore prices, or browse available brands.'],
			'journey'=>['رحلتك لامتلاك السيارة','Your buying journey','ثلاث خطوات تساعدك على الانتقال من الاستكشاف إلى قرار مدروس.','Three steps to help you move from browsing to an informed decision.'],
			'branches'=>['فروعنا وزيارتك','Visit our branches','اختر الفرع المناسب وافتح الاتجاهات، وتواصل معنا لتنسيق زيارتك.','Choose a branch, open directions, and contact us to plan your visit.']
		];
		$d=$defaults[$key]; $title=SiteContent::home_text($key,'title',self::t($d[0],$d[1])); $description=SiteContent::home_text($key,'description',self::t($d[2],$d[3]));
		$cards=''; $catalog=self::catalog(); $contact=self::page('contact');
		if('needs'===$key && $catalog) {
			$options=PublicCatalog::presentation_filter_options(); $choices=[];
			if(PublicCatalog::catalog_total()>0) {
				$choices[]=['title'=>self::t('ابدأ حسب السعر','Explore by price'),'text'=>self::t('رتّب الخيارات حسب السعر المعلن، واستفسر عن الخيارات التي تحتاج عرض سعر.','Sort by published price and enquire about vehicles that require a quote.'),'url'=>self::catalog(['sort'=>'price_asc'])];
				$choices[]=['title'=>self::t('أحدث الموديلات','Newer model years'),'text'=>self::t('استعرض السيارات بترتيب سنة الموديل من الأحدث.','Explore vehicles sorted by the newest model year.'),'url'=>self::catalog(['sort'=>'year_desc'])];
			}
			$types=$options['options']['body_type']??[];
			foreach($types as $type) {
				$label=function_exists('car_dealer_catalog_value_label')?car_dealer_catalog_value_label((string)$type):(string)$type;
				$choices[]=['title'=>$label,'text'=>self::t('استعرض السيارات المنشورة من هذا النوع.','Browse published vehicles of this type.'),'url'=>self::catalog(['body_type'=>$type])];
			}
			if(!$types) {
				$brands=$options['options']['brand']??wp_list_pluck($options['brands']??[],'slug');
				foreach($brands as $brand) {
					$label=function_exists('car_dealer_catalog_value_label')?car_dealer_catalog_value_label((string)$brand):(string)$brand;
					$choices[]=['title'=>$label,'text'=>self::t('استكشف خيارات هذه الماركة المتوفرة في الموقع.','Explore this brand’s available options.'),'url'=>self::catalog(['brand'=>$brand])];
				}
			}
			foreach(array_slice($choices,0,$limit) as $choice) {
				$cards.='<a class="adc-discovery-card adc-discovery-link" href="'.esc_url($choice['url']).'">'.self::icon($key).'<h3>'.esc_html($choice['title']).'</h3><p>'.esc_html($choice['text']).'</p><span class="adc-discovery-action">'.esc_html(self::t('استعرض الخيارات','Explore options')).'<span aria-hidden="true"> ↗</span></span></a>';
			}
		}
		if('journey'===$key) {
			$steps=self::steps();
			foreach($steps as $index=>$step) {
				$n=$index+1; $heading=SiteContent::home_text($key,'step_'.$n.'_title',self::t($step[0],$step[1])); $text=SiteContent::home_text($key,'step_'.$n.'_text',self::t($step[2],$step[3]));
				$cards.='<li class="adc-discovery-card"><span class="adc-journey-number" aria-hidden="true">'.esc_html((string)$n).'</span><h3>'.esc_html($heading).'</h3><p>'.esc_html($text).'</p></li>';
			}
		}
		if('branches'===$key) {
			$rows=array_slice(array_values(array_filter(BranchLocations::all(),static fn($row)=>!empty($row['active']))),0,$limit);
			foreach($rows as $row) {
				$name=trim((string)($row['name_'.$lang]??''))?:trim((string)($row['name_ar']??'')); $address=(string)($row['address_'.$lang]??'');
				$link=$row['directions_url']?:'https://www.google.com/maps/search/?api=1&query='.rawurlencode($name.' '.$address);
				$cards.='<article class="adc-discovery-card">'.self::icon($key).'<h3>'.esc_html($name).'</h3>'.($address?'<p>'.esc_html($address).'</p>':'').'<div class="adc-discovery-branch-actions"><a class="btn btn-outline" href="'.esc_url($link).'" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr(self::t('الاتجاهات إلى ','Directions to ').$name).'">'.esc_html(self::t('الاتجاهات','Directions')).'<span aria-hidden="true"> ↗</span></a>';
				$phone=preg_replace('/[^+0-9]/','',(string)($row['phone']??'')); if($phone) { $cards.='<a class="adc-discovery-phone" href="'.esc_url('tel:'.$phone).'" dir="ltr">'.esc_html($row['phone']).'</a>'; }
				$cards.='</div></article>';
			}
		}
		$ids=['needs'=>'explore-options','journey'=>'buying-journey','branches'=>'visit-branches'];
		$html='<section id="'.$ids[$key].'" class="ab-section adc-discovery-section adc-discovery-'.$key.'" aria-labelledby="'.$ids[$key].'-title"><div class="container"><div class="section-heading"><div><h2 id="'.$ids[$key].'-title">'.esc_html($title).'</h2><p class="section-subtitle">'.esc_html($description).'</p></div></div>';
		$tag='journey'===$key?'ol':'div';
		$html.=$cards?'<'.$tag.' class="adc-discovery-grid">'.$cards.'</'.$tag.'>':'<p class="adc-discovery-empty">'.esc_html(self::t('نعمل على استكمال الخيارات لهذا القسم. تواصل معنا لمساعدتك.','We are preparing the options for this section. Contact us for assistance.')).'</p>';
		$url='needs'===$key?$catalog:$contact;
		if($url) { $html.='<div class="adc-discovery-footer"><a class="btn btn-primary" href="'.esc_url($url).'">'.esc_html('needs'===$key?self::t('جميع السيارات','All vehicles'):self::t('تواصل معنا','Contact us')).'</a></div>'; }
		return $html.'</div></section>';
	}
}
