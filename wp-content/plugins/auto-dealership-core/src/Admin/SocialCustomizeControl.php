<?php
namespace AutoDealership\Admin;
defined('ABSPATH') || exit;
final class SocialCustomizeControl extends \WP_Customize_Control {
	public $type='adc-social-preferences';
	public function enqueue(){\AutoDealership\Core\SocialChannels::assets(true);}
	public function render_content(){echo '<span class="customize-control-title">'.esc_html($this->label).'</span>';\AutoDealership\Core\SocialChannels::preferences_control($this->get_link(),$this->value());}
}
