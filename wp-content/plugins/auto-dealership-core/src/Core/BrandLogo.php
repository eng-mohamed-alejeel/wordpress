<?php
namespace AutoDealership\Core;
defined('ABSPATH') || exit;

/** Bound logo geometry in the HTML itself, before external styles or image decoding. */
final class BrandLogo {
    private static string $placement='header';
    public static function boot(): void {
        add_filter('wp_get_attachment_image',array(self::class,'image'),30,5);
        add_action('admin_init',static function(): void {
            $stamp='1:'.wp_get_theme()->get('Version');
            if(current_user_can('manage_options') && $stamp!==get_option('adc_logo_markup_version')){BrandPalette::invalidate();update_option('adc_logo_markup_version',$stamp,false);}
        });
    }
    public static function public_logo(string $placement='header'): string {
        $previous=self::$placement;self::$placement='footer'===$placement?'footer':'header';
        try{return get_custom_logo();}finally{self::$placement=$previous;}
    }
    public static function dimensions(int $width,int $height,int $max_width,int $max_height): array {
        if($width<1 || $height<1){$width=240;$height=60;}
        $scale=min(1,$max_width/$width,$max_height/$height);
        return array(max(1,(int)round($width*$scale)),max(1,(int)round($height*$scale)));
    }
    public static function image(string $html,int $attachment,$size,bool $icon,array $attributes): string {
        $classes=preg_split('/\s+/',trim((string)($attributes['class']??'')));
        if(in_array('adc-about-site-logo',$classes,true)){$placement='about';$limits=array(300,180);}
        elseif(in_array('adc-workspace-logo',$classes,true)){$placement='workspace';$limits=array(148,100);}
        elseif(in_array('custom-logo',$classes,true)){$placement=self::$placement;$limits=array(180,'footer'===$placement?40:48);}
        else{return $html;}
        $tag=new \WP_HTML_Tag_Processor($html);if(!$tag->next_tag('IMG')){return $html;}
        list($width,$height)=self::dimensions((int)$tag->get_attribute('width'),(int)$tag->get_attribute('height'),$limits[0],$limits[1]);
        $existing=trim((string)$tag->get_attribute('style'));
        $tag->set_attribute('width',$width);$tag->set_attribute('height',$height);
        $tag->set_attribute('style',($existing?rtrim($existing,';').';':'').'width:'.$width.'px;height:auto;max-width:100%;max-height:'.$limits[1].'px;aspect-ratio:'.$width.'/'.$height.';object-fit:contain;display:block');
        $tag->set_attribute('decoding','async');$tag->set_attribute('data-adc-logo',$placement);
        if($tag->get_attribute('srcset')){$tag->set_attribute('sizes','(max-width:'.$width.'px) 100vw, '.$width.'px');}
        if('header'===$placement){$tag->set_attribute('loading','eager');$tag->set_attribute('fetchpriority','high');}
        elseif('footer'===$placement){$tag->set_attribute('loading','lazy');$tag->remove_attribute('fetchpriority');}
        return $tag->get_updated_html();
    }
}
