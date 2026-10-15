<?php
/** Built-in theme UI copy for public URL language and the staff dashboard locale. */
defined( 'ABSPATH' ) || exit;

function car_dealer_ui_language(): string {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return str_starts_with( get_user_locale(), 'en' ) ? 'en' : 'ar';
	}
	return car_dealer_catalog_language();
}

function car_dealer_ui_gettext( string $translation, string $text, string $domain ): string {
	static $catalog = null;
	if ( null === $catalog ) {
		$catalog = require __DIR__ . '/../languages/ui.php';
	}
	$value = $catalog[ car_dealer_ui_language() ][$text] ?? $translation;
	return class_exists( '\AutoDealership\Core\ProductSetup' ) ? \AutoDealership\Core\ProductSetup::copy( $value, car_dealer_ui_language() ) : $value;
}
add_filter( 'gettext_car-dealer', 'car_dealer_ui_gettext', 20, 3 );

function car_dealer_ui_gettext_context( string $translation, string $text, string $context, string $domain ): string {
	return car_dealer_ui_gettext( $translation, $text, $domain );
}
add_filter( 'gettext_with_context_car-dealer', 'car_dealer_ui_gettext_context', 20, 4 );
