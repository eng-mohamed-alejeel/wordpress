<?php
/** Read-only layout compatibility and validation checks against WordPress. */
if(PHP_SAPI!=='cli'){exit(1);}define('DISABLE_WP_CRON',true);require dirname(__DIR__,4).'/wp-load.php';
use AutoDealership\Content\SiteContent as Content;
$count=0;$check=static function($ok,$label)use(&$count){if(!$ok){throw new RuntimeException($label);}++$count;echo "PASS $label\n";};
$old=Content::defaults();foreach($old['home'] as &$row){unset($row['height'],$row['spacing']);}unset($row);
$clean=Content::normalize($old);$check(!is_wp_error($clean),'Legacy section settings accepted');
foreach($clean['home'] as $key=>$row){$check($row['height']==='auto'&&$row['spacing']==='normal','Safe layout defaults '.$key);}
foreach(['auto','balanced','screen'] as $height){$data=$old;$data['home']['why']['height']=$height;$check(!is_wp_error(Content::normalize($data)),'Supported narrative height '.$height);}
foreach(['compact','normal','spacious'] as $spacing){$data=$old;$data['home']['reviews']['spacing']=$spacing;$check(!is_wp_error(Content::normalize($data)),'Supported density '.$spacing);}
foreach(['featured','latest','offers','reviews','demand'] as $key){$data=$old;$data['home'][$key]['height']='screen';$check(is_wp_error(Content::normalize($data)),'Unsupported screen height rejected '.$key);}
foreach(['height','spacing'] as $field){$data=$old;$data['home']['hero'][$field]='injected';$check(is_wp_error(Content::normalize($data)),'Invalid enum rejected '.$field);}
$html=Content::home_layout('<section id="kept" style="color:red"><h2>Kept content</h2></section>','why',['height'=>'screen','spacing'=>'compact']);
$check(str_contains($html,'id="kept"')&&str_contains($html,'color:red')&&str_contains($html,'Kept content'),'Markup and content preserved');
$check(str_contains($html,'data-section-height="screen"')&&str_contains($html,'data-section-spacing="compact"'),'Layout attributes rendered');
$html=Content::home_layout('<section></section>','reviews',['height'=>'screen','spacing'=>'normal']);$check(str_contains($html,'data-section-height="auto"'),'Unsafe saved height coerced safely at render');
$check(Content::home_layout('','team',[])==='','Hidden empty section stays empty');
$legacy=$old;$legacy['home']['team']=['status'=>'published'];$legacy['home']['metrics']=['status'=>'published'];$normalized=Content::normalize($legacy);$check(!is_wp_error($normalized)&&!isset($normalized['home']['team'],$normalized['home']['metrics']),'Legacy homepage blocks discarded');$check(!isset(Content::home_definitions()['team'],Content::home_definitions()['metrics']),'Removed from homepage definitions');$check(!Content::home_enabled('team')&&!Content::home_enabled('metrics'),'Stored flags cannot restore removed blocks');
$check(Content::normalize($old)['team']===$old['team'],'Team records preserved');
$check(Content::normalize($old)['metrics']===$old['metrics'],'Metric records preserved');
echo "PASS home layout checks: $count; no persistent writes.\n";
