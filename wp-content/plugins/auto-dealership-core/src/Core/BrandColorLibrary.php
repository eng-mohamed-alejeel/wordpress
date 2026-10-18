<?php
namespace AutoDealership\Core;
defined('ABSPATH') || exit;

/** Private Customizer metadata and bounded, site-wide identity snapshots. */
final class BrandColorLibrary {
    public static function boot(): void {
        add_action('customize_register',array(self::class,'register'),31);
        add_action('update_option_'.BrandPalette::OPTION,array(self::class,'remember'),4,2);
    }
    public static function meta($value) {
        if(!is_array($value)||array_diff(array_keys($value),array('format','generated','extracted','roles','style'))||1!==($value['format']??null)){return new \WP_Error('adc_color_metadata','Invalid color metadata.');}
        if(isset($value['style'])&&!in_array($value['style'],array('','corporate','luxury','modern','bold','neutral','complementary','split','triadic'),true)){return new \WP_Error('adc_color_metadata','Invalid color scheme.');}
        if(!is_array($value['generated']??null)||array_diff(array_keys($value['generated']),array('light','dark'))){return new \WP_Error('adc_color_metadata','Invalid generated colors.');}
        $maps=BrandPalette::normalize(array('format'=>1,'light'=>$value['generated']['light']??array(),'dark'=>$value['generated']['dark']??array()));
        if(is_wp_error($maps)||!is_array($value['extracted']??null)||count($value['extracted'])>6||!is_array($value['roles']??null)||array_diff(array_keys($value['roles']),array('primary','accent','navy'))){return new \WP_Error('adc_color_metadata','Invalid color metadata.');}
        $out=array('format'=>1,'generated'=>array('light'=>$maps['light'],'dark'=>$maps['dark']),'extracted'=>array(),'roles'=>array());
        foreach($value['extracted'] as $color){if(!is_string($color)||!preg_match('/^#[0-9a-f]{6}$/i',$color)){return new \WP_Error('adc_color_metadata','Invalid extracted color.');}$out['extracted'][]=strtoupper($color);}
        foreach($value['roles'] as $key=>$color){if(!is_string($color)||!preg_match('/^#[0-9a-f]{6}$/i',$color)){return new \WP_Error('adc_color_metadata','Invalid role color.');}$out['roles'][$key]=strtoupper($color);}
        if(isset($value['style'])){$out['style']=$value['style'];}
        return $out;
    }
    public static function empty_meta(): array {return array('format'=>1,'generated'=>array('light'=>array(),'dark'=>array()),'extracted'=>array(),'roles'=>array());}
    public static function library($value) {
        if(!is_array($value)||count($value)>12){return new \WP_Error('adc_color_library','Maximum 12 saved identities.');}$out=array();
        foreach($value as $id=>$entry){if(!preg_match('/^[a-z0-9_-]{1,64}$/',strval($id))||!is_array($entry)||!is_string($entry['name']??null)||preg_match_all('/./us',$entry['name'])>80||''===trim($entry['name'])){return new \WP_Error('adc_color_library','Invalid identity name.');}$palette=BrandPalette::normalize($entry['palette']??null);$meta=self::meta($entry['meta']??self::empty_meta());if(is_wp_error($palette)||is_wp_error($meta)){return new \WP_Error('adc_color_library','Invalid saved identity.');}$out[$id]=array('name'=>sanitize_text_field($entry['name']),'palette'=>$palette,'meta'=>$meta);}
        return $out;
    }
    public static function register($manager): void {
        if('car-dealer'!==get_stylesheet()){return;}
        foreach(array('adc_brand_palette_meta'=>array(self::class,'meta'),'adc_brand_palette_library'=>array(self::class,'library')) as $key=>$callback){
            $default=get_option($key,'adc_brand_palette_meta'===$key?self::empty_meta():array());
            $manager->add_setting($key,array('type'=>'option','capability'=>'manage_options','default'=>$default,'transport'=>'postMessage','sanitize_callback'=>static function($value)use($callback){$clean=call_user_func($callback,$value);return is_wp_error($clean)?null:$clean;},'validate_callback'=>static function($validity,$value)use($callback){$clean=call_user_func($callback,$value);if(is_wp_error($clean)){$validity->add('adc_color_library_invalid',$clean->get_error_message());}return $validity;}));
        }
    }
    public static function remember($old,$new): void {
        if(!is_array($old)||$old===$new){return;}$history=get_option('adc_brand_palette_history',array());if(!is_array($history)){$history=array();}
        $history[]=array('date'=>gmdate('c'),'palette'=>$old,'meta'=>get_option('adc_brand_palette_meta',self::empty_meta()));
        update_option('adc_brand_palette_history',array_slice($history,-20),false);
    }
}
