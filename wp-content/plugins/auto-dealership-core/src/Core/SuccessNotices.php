<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Dismissible success feedback; consumed URL flags must not survive a refresh. */
final class SuccessNotices {
	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}
	public static function enqueue(): void {
		$account = ! is_admin() && isset( $_GET['cd_account'] );
		$newsletter = is_admin() && 'car-dealer-subscribers' === ( $_GET['page'] ?? '' );
		if ( ! $account && ! $newsletter ) { return; }
		wp_enqueue_style( 'adc-success-notices', plugins_url( 'assets/css/success-notices.css', ADC_FILE ), array(), (string) filemtime( ADC_PATH . 'assets/css/success-notices.css' ) );
		wp_enqueue_script( 'adc-success-notices', plugins_url( 'assets/js/success-notices.js', ADC_FILE ), array(), (string) filemtime( ADC_PATH . 'assets/js/success-notices.js' ), true );
		wp_localize_script( 'adc-success-notices', 'adcSuccessNotices', array( 'close'=>'en' === Localization::language() ? 'Dismiss notification' : 'إغلاق الإشعار' ) );
	}
}
