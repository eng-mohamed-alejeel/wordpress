<?php
/** Run: php wp-content/plugins/auto-dealership-core/tests/brand-palette.php */
namespace AutoDealership\Core { final class ProductSetup { public static function t($ar,$en){return $en;} } }
namespace {
define('ABSPATH',__DIR__);$options=array();
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function sanitize_hex_color($value){return is_string($value)&&preg_match('/^#[0-9a-f]{6}$/i',$value)?$value:null;}
function update_option($key,$value,$autoload=null){global $options;$options[$key]=$value;}
class WP_Error {private $code;function __construct($code,$message){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($value){return $value instanceof WP_Error;}
require dirname(__DIR__).'/src/Core/BrandPalette.php';
use AutoDealership\Core\BrandPalette as Palette;
$count=0;function check($ok,$label){global $count;if(!$ok){throw new RuntimeException($label);}++$count;echo "PASS $label\n";}
$empty=Palette::empty_config();$light=Palette::resolve('light',$empty);$dark=Palette::resolve('dark',$empty);
check(count($light)>90,'complete semantic palette and color scales');
check($light['action']==='#1764CE'&&$light['action-hover']==='#104FA6','approved primary button shades');
check($light['offer']==='#C90000'&&$light['brand-red']==='#E60000','limited red offer palette');
check($light['header']==='#FFFFFF'&&$light['footer']==='#101C32','light navigation and navy footer');
foreach(array('light'=>$light,'dark'=>$dark) as $mode=>$colors){check(count(array_filter($colors,static fn($v)=>preg_match('/^#[A-F0-9]{6}$/',$v)))===count($colors),"$mode resolves only safe hex colors");foreach(array(array('on-action','action'),array('on-action','action-hover'),array('on-primary','primary'),array('on-primary','primary-hover'),array('on-offer','offer'),array('on-offer','offer-hover'),array('text','surface'),array('muted','soft'),array('footer-muted','footer'),array('hero-muted','hero-end'),array('success','success-bg'),array('warning','warning-bg'),array('error','error-bg'),array('mail-on-action','mail-action')) as $pair){check(Palette::contrast($colors[$pair[0]],$colors[$pair[1]])>=4.5,"$mode contrast {$pair[0]} / {$pair[1]}");}}
foreach(array(null,array(),array('format'=>2,'light'=>array(),'dark'=>array()),array('format'=>1,'light'=>array('unknown'=>'#123456'),'dark'=>array()),array('format'=>1,'light'=>array('action'=>'red'),'dark'=>array()),array('format'=>1,'light'=>array('action'=>'#fff'),'dark'=>array()),array('format'=>1,'light'=>array('action'=>array()),'dark'=>array()),array('format'=>1,'light'=>array(),'dark'=>array(),'smtp_password'=>'secret'),array('format'=>1,'light'=>array('action'=>'#123456;}body{display:none'),'dark'=>array())) as $bad){check(is_wp_error(Palette::normalize($bad)),'invalid import rejected atomically');}
$custom=$empty;$custom['light']['brand-blue']='#7C3AED';$custom['light']['action']='#123456';check(Palette::resolve('light',$custom)['action']==='#123456','manual override survives brand change');unset($custom['light']['action']);check(Palette::resolve('light',$custom)['action']!==$light['action'],'automatic action follows brand change');check(Palette::resolve('dark',$custom)['brand-blue']==='#7C3AED','dark brand inherits light brand');$custom['dark']['brand-blue']='#123456';check(Palette::resolve('dark',$custom)['brand-blue']==='#123456','explicit dark override is independent');
check(Palette::normalize(json_decode(json_encode($custom),true))===$custom,'portable import export round trip');
$options['car_dealer_theme_settings']=array('primary_color'=>'#123456','accent_color'=>'#ABCDEF','phone'=>'unchanged');$legacy=Palette::config();check($legacy['light']['brand-navy']==='#123456'&&$legacy['light']['action']==='#ABCDEF','legacy colors preserved before explicit preset publication');
$options[Palette::OPTION]=$custom;Palette::legacy_update(array('accent_color'=>'#ABCDEF'),array('accent_color'=>'#112233'));check($options[Palette::OPTION]['light']['brand-blue']==='#112233'&&!isset($options[Palette::OPTION]['light']['action']),'legacy color edits update the central palette');
$colors=array();foreach($light as $key=>$value){if(str_starts_with($key,'mail-')){$colors[$key]=$value;}}$colors['mail-header-text']='#112233';$colors['mail-on-action']='#223344';$colors['mail-bg']='#334455';$colors['mail-surface']='#445566';$colors['mail-heading']='#556677';
$html='<body style="background:#F3F6FA;color:#24364B"><table bgcolor="#F3F6FA" style="background:#fff;border:1px solid #E2E8F0"><p style="color:#fff;font-size:26px">Brand</p><h1 style="color:#101C32">Title</h1><a href="https://example.test/?key=abc#F3F6FA" style="border:1px solid #1764CE;color:#fff;">CTA</a></table></body>';
$html=Palette::email_html($html,$colors);check(str_contains($html,'color:#112233;font-size:26px')&&str_contains($html,'color:#223344;'),'email header and button foregrounds are independent');check(str_contains($html,'background:#334455')&&str_contains($html,'background:#445566')&&str_contains($html,'color:#556677'),'email surfaces and heading use palette');check(str_contains($html,'https://example.test/?key=abc#F3F6FA'),'email color styling preserves signed URLs');
echo "$count brand palette checks passed.\n";
}
