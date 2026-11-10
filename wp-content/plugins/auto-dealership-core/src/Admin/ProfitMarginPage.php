<?php
namespace AutoDealership\Admin;

use AutoDealership\Pricing\Money;
use AutoDealership\Pricing\ProfitMargin;

defined( 'ABSPATH' ) || exit;

final class ProfitMarginPage {
	private static function t( string $ar, string $en ): string {
		return \AutoDealership\Core\CurrencySettings::localize_unit_text( \AutoDealership\Core\Localization::language() === 'en' ? $en : $ar );
	}

	public static function boot(): void {
		add_action( 'admin_post_adc_profit_margin', array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', static function (): void {
			if ( ( $_GET['page'] ?? '' ) === 'adc-profit-margin' ) {
				wp_enqueue_script( 'adc-profit-margin', plugins_url( 'assets/js/profit-margin.js', ADC_FILE ), array(), filemtime( ADC_PATH . 'assets/js/profit-margin.js' ), true );
			}
		} );
	}

	public static function save(): void {
		check_admin_referer( 'adc_profit_margin' );
		$result = ProfitMargin::save( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			set_transient( 'adc_margin_error_' . get_current_user_id(), $result->get_error_message(), 120 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-profit-margin', 'saved' => is_wp_error( $result ) ? '0' : '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
		$policy = ProfitMargin::policy();
		$english = \AutoDealership\Core\Localization::language() === 'en';
		$money_step = \AutoDealership\Core\CurrencySettings::input_step();
		$unit = \AutoDealership\Core\CurrencySettings::ui_unit( $english ? 'en' : 'ar' );
		$value_step = 'percentage' === $policy['type'] ? '0.01' : $money_step;
		$value_display = 'percentage' === $policy['type'] ? Money::percent( $policy['value'] ) : Money::decimal( $policy['value'] );
		echo '<div class="wrap"><h1>' . esc_html( self::t( 'هامش ربح السيارات', 'Vehicle profit margin' ) ) . '</h1>';
		$error = get_transient( 'adc_margin_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'adc_margin_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( self::t( 'لم يُحفظ الهامش. تحقق من القيم وأعد تحميل الشاشة إذا تغيرت السياسة.', 'Margin was not saved. Check values and reload if the policy changed.' ) ) . '</p></div>';
		}
		if ( ( $_GET['saved'] ?? '' ) === '1' ) {
			echo '<div class="notice notice-success"><p>' . esc_html( self::t( 'تم حفظ سياسة الهامش وتحديث أسعار الموقع.', 'Margin policy saved and website prices updated.' ) ) . '</p></div>';
		}
		echo '<p>' . esc_html( self::t( 'تطبق السياسة على أسعار السيارات في الكتالوج وصفحات التفاصيل والمقارنة والبحث. تطبق أيضًا على عروض الأسعار التجارية الجديدة مع الرسوم واللوحات. تحفظ أسعار أونكس الأصلية والعروض السابقة والمستندات المالية وأسعار العروض التسويقية المدخلة يدويًا.', 'Applies to vehicle prices across the catalog, details, comparison and search. New commercial quotations use the same margin, fees and plates. Original Onyx prices, existing quotations, financial documents and manually entered promotional offer prices are preserved.' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-margin-form data-currency="' . esc_attr( \AutoDealership\Core\CurrencySettings::base_code() ) . '" data-unit="' . esc_attr( $unit ) . '" data-money-step="' . esc_attr( $money_step ) . '" data-decimals="' . (int) \AutoDealership\Core\CurrencySettings::decimals() . '" data-fee="' . (int) get_option( 'adc_pricing_fee_amount', 0 ) . '" data-vat="' . \AutoDealership\Pricing\PublicVehiclePrice::rate() . '" data-language="' . ( $english ? 'en' : 'ar' ) . '"><input type="hidden" name="action" value="adc_profit_margin"><input type="hidden" name="revision" value="' . (int) $policy['revision'] . '">';
		wp_nonce_field( 'adc_profit_margin' );
		echo '<table class="form-table"><tbody><tr><th>' . esc_html( self::t( 'طريقة الهامش', 'Margin type' ) ) . '</th><td><select name="type" aria-label="' . esc_attr( self::t( 'طريقة الهامش', 'Margin type' ) ) . '">';
		foreach ( array( 'none' => array( 'بدون هامش إضافي', 'No additional margin' ), 'fixed' => array( 'مبلغ ثابت (%s)', 'Fixed %s amount' ), 'percentage' => array( 'نسبة مئوية', 'Percentage' ) ) as $key => $labels ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $policy['type'], $key, false ) . '>' . esc_html( self::t( ...$labels ) ) . '</option>';
		}
		echo '</select></td></tr><tr><th><label for="margin-value">' . esc_html( self::t( 'القيمة', 'Value' ) ) . '</label></th><td><input id="margin-value" name="value" type="number" min="0" step="' . esc_attr( $value_step ) . '" required value="' . esc_attr( $value_display ) . '"><span data-margin-unit></span><p class="description">' . esc_html( self::t( 'اكتب النسبة مباشرة مثل 5، أو المبلغ (%s) مثل 1500. استخدم دقة العملة المفعلة.', 'Enter a percentage such as 5, or a %s amount such as 1500. Use the configured currency precision.' ) ) . '</p></td></tr><tr><th>' . esc_html( self::t( 'توقيت إضافة الهامش', 'Margin stage' ) ) . '</th><td><select name="stage" aria-label="' . esc_attr( self::t( 'توقيت إضافة الهامش', 'Margin stage' ) ) . '">';
		foreach ( array( 'before_tax' => array( 'قبل الضريبة', 'Before VAT' ), 'after_tax' => array( 'بعد الضريبة', 'After VAT' ) ) as $key => $labels ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $policy['stage'], $key, false ) . '>' . esc_html( self::t( ...$labels ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html( self::t( 'قبل الضريبة: الهامش على السعر الأساسي وتُحسب الضريبة على المجموع. بعد الضريبة: الهامش محسوب على السعر شامل الضريبة، وقيمته شاملة الضريبة؛ يُستخرج صافي الهامش وتُحدّث الضريبة. اللوحات تُضاف في النهاية ولا تدخل في النسبة.', 'Before VAT: margin uses the base price; VAT applies to the subtotal. After VAT: margin uses the VAT-inclusive price and includes VAT; the net margin is extracted and VAT is updated. Plate fees are added last and excluded from the percentage.' ) ) . '</p></td></tr></tbody></table>';
		echo '<h2>' . esc_html( self::t( 'معاينة الحساب قبل الحفظ', 'Calculation preview before saving' ) ) . '</h2><label>' . esc_html( self::t( 'سعر أساسي للتجربة (%s)', 'Example base price (%s)' ) ) . ' <input type="number" min="' . esc_attr( $money_step ) . '" step="' . esc_attr( $money_step ) . '" value="80000" data-margin-example></label><p data-margin-preview role="status" aria-live="polite"></p>';
		submit_button( self::t( 'حفظ سياسة الهامش', 'Save margin policy' ) );
		echo '</form></div>';
	}
}
