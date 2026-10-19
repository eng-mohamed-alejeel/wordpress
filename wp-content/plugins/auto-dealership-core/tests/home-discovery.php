<?php
/** Read-only discovery regression tests; fixtures use option filters, never tenant writes. */
if(PHP_SAPI!=='cli') { exit(1); }
define('DISABLE_WP_CRON',true);
require dirname(__DIR__,4).'/wp-load.php';
use AutoDealership\Content\SiteContent as Content;
use AutoDealership\Content\HomeDiscovery as Discovery;
$checks=0;
$check=static function(bool $ok,string $label) use(&$checks): void { if(!$ok) { throw new RuntimeException($label); } ++$checks; echo "PASS $label\n"; };
$home=Content::data();
$locations=[['id'=>'test-active','name_ar'=>'فرع تجريبي','name_en'=>'Sample branch','address_ar'=>'عنوان تجريبي','address_en'=>'Sample address','directions_url'=>'https://www.google.com/maps/search/?api=1&query=21,39','phone'=>'0550000000','active'=>true],['id'=>'test-hidden','name_ar'=>'فرع مخفي','name_en'=>'Hidden branch','address_ar'=>'','address_en'=>'','directions_url'=>'','phone'=>'','active'=>false]];
add_filter('pre_option_adc_site_content',static function() use(&$home) { return $home; });
add_filter('pre_option_adc_branch_locations',static function() use(&$locations) { return $locations; });
foreach(['ar','en'] as $lang) {
	$_GET['lang']=$lang;
	foreach(['needs','journey','branches'] as $key) { $check(str_contains(Discovery::render($key),'<section'),'Section renders '.$key.'/'.$lang); }
	$journey=Discovery::render('journey');
	$check(substr_count($journey,'<li class="adc-discovery-card"')===3,'Three ordered steps '.$lang);
	$home['home']['journey']['step_1_title_'.$lang]='Custom <script>unsafe</script>';
	$journey=Discovery::render('journey');
	$check(!str_contains($journey,'<script>')&&str_contains($journey,'&lt;script&gt;'),'Editable step escaped '.$lang);
	$branches=Discovery::render('branches');
	$check(substr_count($branches,'<article')===1&&!str_contains($branches,'Hidden branch')&&!str_contains($branches,'فرع مخفي'),'Inactive branches excluded '.$lang);
	$check(!str_contains($branches,'iframe'),'No map iframe on homepage '.$lang);
	$check(str_contains($branches,'tel:0550000000')&&str_contains($branches,'noopener noreferrer'),'Branch actions safe '.$lang);
	$check(str_contains($branches,'lang='.$lang),'Contact link keeps language '.$lang);
}
$home['home']['needs']['status']='hidden';
$check(Discovery::render('needs')==='','Hidden section produces no content');
$check(Discovery::render('team')===''&&Discovery::render('metrics')==='','Removed homepage sections stay unavailable');
$locations=[];
$check(!str_contains(Discovery::render('branches'),'<article'),'Empty branch data produces no fabricated branch');
$check(Discovery::step_copy(1,'title','en')==='Explore and shortlist','Editor and public default share same source');
$check(Discovery::step_copy(9,'title','en')==='','Invalid step handled safely');
$invalid=$home;$invalid['home']['journey']['unexpected']='bad';
$check(is_wp_error(Content::normalize($invalid)),'Unknown journey fields rejected');
$old=$home;unset($old['home']['needs'],$old['home']['journey'],$old['home']['branches']);$home=$old;
$data=Content::data();$sorted=$data['home'];uasort($sorted,static fn($a,$b)=>(int)$a['order']<=>(int)$b['order']);$keys=array_keys($sorted);
$check(array_search('needs',$keys,true)===array_search('hero',$keys,true)+1,'New discovery inserted after hero');
$check(array_search('journey',$keys,true)===array_search('why',$keys,true)+1,'Journey inserted after value section');
$check(array_search('branches',$keys,true)+1===array_search('cta',$keys,true),'Branches inserted before final CTA');
$check(!isset($data['home']['team'],$data['home']['metrics']),'Old content groups stay excluded from homepage');
echo "PASS discovery checks: $checks; no persistent writes.\n";
