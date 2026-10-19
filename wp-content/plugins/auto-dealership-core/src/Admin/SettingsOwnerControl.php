<?php
namespace AutoDealership\Admin;
defined( 'ABSPATH' ) || exit;

/** The preview keeps a link to the owner, rather than another editable copy. */
final class SettingsOwnerControl extends \WP_Customize_Control {
	public function render_content() {
		echo '<span class="customize-control-title">' . esc_html( $this->label ) . '</span><p>' . esc_html( \AutoDealership\Core\ProductSetup::t( 'تُدار هذه البيانات من شاشتها المخصصة. احفظ تعديلات المظهر قبل الانتقال.', 'Manage these details in their dedicated screen. Save appearance changes before leaving.' ) ) . '</p><a class="button" href="' . esc_url( $this->description ) . '" target="_top">' . esc_html( \AutoDealership\Core\ProductSetup::t( 'فتح الإعدادات', 'Open settings' ) ) . '</a>';
	}
}
