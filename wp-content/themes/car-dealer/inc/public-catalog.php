<?php
/** Thin presentation adapter for the plugin-owned public catalog. */
defined( 'ABSPATH' ) || exit;

/** Catalog language is explicit and URL-bound; Arabic remains the safe default. */
function car_dealer_catalog_language(): string {
	return function_exists( 'adc_catalog_language' ) ? adc_catalog_language() : 'ar';
}

function car_dealer_catalog_direction(): string {
	return 'en' === car_dealer_catalog_language() ? 'ltr' : 'rtl';
}

function car_dealer_catalog_is_request(): bool {
	return function_exists( 'adc_catalog_is_request' ) && adc_catalog_is_request();
}

/** English catalog copy while the Arabic source remains the default theme language. */
function car_dealer_catalog_english_copy(): array {
	static $copy = null;
	if ( null !== $copy ) {
		return $copy;
	}
	$copy = array(
		'استعرض سيارات AUTO BRANDS' => 'Browse AUTO BRANDS Vehicles',
		'فلترة دقيقة حسب الماركة، الموديل، السنة، السعر، نوع السيارة، الوقود وناقل الحركة.' => 'Filter by brand, model, year, price, body type, fuel and transmission.',
		'السابق' => 'Previous', 'التالي' => 'Next', 'لا توجد سيارات مطابقة للبحث.' => 'No vehicles match your search.',
		'بحث' => 'Search', 'الماركة أو الموديل' => 'Brand or model', 'الماركة أو الموديل أو رقم المخزون' => 'Brand, model or stock number',
		'الماركة' => 'Brand', 'كل الماركات' => 'All brands', 'نوع السيارة' => 'Vehicle type', 'كل الأنواع' => 'All types',
		'الموديل' => 'Model', 'الفئة' => 'Trim', 'السنة من' => 'Year from', 'السنة إلى' => 'Year to',
		'السعر من (ريال)' => 'Price from (SAR)', 'السعر إلى (ريال)' => 'Price to (SAR)', 'السعر إلى' => 'Price to',
		'الممشى من (كم)' => 'Mileage from (km)', 'الممشى إلى (كم)' => 'Mileage to (km)', 'نوع الهيكل' => 'Body type',
		'الوقود' => 'Fuel', 'ناقل الحركة' => 'Transmission', 'المحرك' => 'Engine', 'نظام الدفع' => 'Drivetrain',
		'اللون الخارجي' => 'Exterior color', 'اللون الداخلي' => 'Interior color', 'الحالة' => 'Condition',
		'الفرع' => 'Branch', 'كل الفروع' => 'All branches', 'الترتيب' => 'Sort', 'الأحدث' => 'Newest',
		'السعر: الأقل أولًا' => 'Price: low to high', 'السعر: الأعلى أولًا' => 'Price: high to low',
		'سنة الصنع' => 'Model year', 'الأقل ممشى' => 'Lowest mileage', 'الكل' => 'All',
		'جديدة' => 'New', 'مستعملة' => 'Used', 'بنزين' => 'Gasoline', 'ديزل' => 'Diesel', 'هجين' => 'Hybrid',
		'كهربائي' => 'Electric', 'أوتوماتيكي' => 'Automatic', 'يدوي' => 'Manual', 'تطبيق' => 'Apply',
		'تطبيق الفلاتر' => 'Apply filters', 'مسح الفلاتر' => 'Clear filters', 'تصفية مخزون السيارات' => 'Filter vehicle inventory',
		'مميزة' => 'Featured', 'سيارة متاحة' => 'Available vehicle', 'عرض التفاصيل' => 'View details', 'قسط يبدأ من %s' => 'Monthly payment from %s',
		'عرض تفاصيل %s' => 'View %s details',
		'متاحة' => 'Available', 'محجوزة' => 'Reserved', 'مباعة' => 'Sold', 'إزالة من المقارنة' => 'Remove from comparison', 'أضف للمقارنة' => 'Add to comparison',
		'اطلب السعر' => 'Request price', 'احجز تجربة قيادة' => 'Book a test drive', 'اطلب تمويل' => 'Request financing',
		'تواصل واتساب' => 'Contact on WhatsApp', 'الوصف' => 'Description', 'المزايا' => 'Features', 'المواصفات' => 'Specifications',
		'حالة المخزون' => 'Inventory status', 'اللون' => 'Color', 'القوة' => 'Power', 'الأبواب' => 'Doors', 'المقاعد' => 'Seats',
		'الممشى' => 'Mileage', 'رقم المخزون' => 'Stock number', 'إرسال طلب السعر' => 'Send price request',
		'إرسال طلب التمويل' => 'Send financing request', 'إرسال الطلب' => 'Send request', 'الاسم' => 'Name',
		'البريد الإلكتروني' => 'Email address', 'الهاتف' => 'Phone', 'اليوم' => 'Date', 'الوقت' => 'Time',
		'ملاحظات إضافية' => 'Additional notes', 'حاسبة التمويل' => 'Finance calculator', 'سعر السيارة: %s' => 'Vehicle price: %s',
		'الدفعة الأولى' => 'Down payment', 'مبلغ التمويل' => 'Finance amount', 'المدة (سنوات)' => 'Term (years)', 'احسب' => 'Calculate',
		'مشاركة:' => 'Share:', 'واتساب' => 'WhatsApp', 'تويتر' => 'X / Twitter', 'فيسبوك' => 'Facebook',
		'الانتقال إلى المحتوى' => 'Skip to content', 'فتح القائمة' => 'Open menu', 'القائمة الرئيسية' => 'Primary navigation',
		'ابحث' => 'Search', 'ابحث عن سيارة' => 'Search for a vehicle', 'تواصل عبر واتساب' => 'Contact on WhatsApp',
		'تواصل معنا' => 'Contact us', 'العودة إلى الأعلى' => 'Back to top', 'اشترك' => 'Subscribe',
	);
	return $copy;
}

function car_dealer_catalog_gettext( string $translation, string $text, string $domain ): string {
	if ( 'car-dealer' !== $domain || 'en' !== car_dealer_catalog_language() || ! car_dealer_catalog_is_request() ) {
		return $translation;
	}
	$copy = car_dealer_catalog_english_copy();
	return $copy[ $text ] ?? $translation;
}
add_filter( 'gettext', 'car_dealer_catalog_gettext', 10, 3 );

function car_dealer_catalog_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
	if ( 'car-dealer' === $domain && 'en' === car_dealer_catalog_language() && car_dealer_catalog_is_request() ) {
		return 1 === $number ? '1 matching vehicle' : '%s matching vehicles';
	}
	return $translation;
}
add_filter( 'ngettext', 'car_dealer_catalog_ngettext', 10, 5 );

function car_dealer_catalog_localized_url( string $url, string $language = '' ): string {
	return function_exists( 'adc_catalog_localized_url' ) ? adc_catalog_localized_url( $url, $language ) : $url;
}

function car_dealer_catalog_language_url( string $language ): string {
	return function_exists( 'adc_catalog_language_url' ) ? adc_catalog_language_url( $language ) : '';
}

function car_dealer_catalog_language_switch(): void {
	if ( ! function_exists( 'adc_catalog_language_url' ) ) { return; }
	$current = car_dealer_catalog_language();
	?>
	<nav class="catalog-language-switch" aria-label="<?php echo esc_attr( 'en' === $current ? 'Catalog language' : 'لغة الكتالوج' ); ?>">
		<a lang="ar" dir="rtl" hreflang="ar" href="<?php echo esc_url( car_dealer_catalog_language_url( 'ar' ) ); ?>" <?php echo 'ar' === $current ? 'aria-current="page"' : ''; ?>>العربية</a>
		<a lang="en" dir="ltr" hreflang="en" href="<?php echo esc_url( car_dealer_catalog_language_url( 'en' ) ); ?>" <?php echo 'en' === $current ? 'aria-current="page"' : ''; ?>>English</a>
	</nav>
	<?php
}

function car_dealer_catalog_format_price( $price ): string {
	if ( ! $price ) {
		return '';
	}
	$amount = (float) $price;
	return 'en' === car_dealer_catalog_language() ? 'SAR ' . number_format( $amount, floor( $amount ) === $amount ? 0 : 2 ) : car_dealer_format_price( $price );
}

function car_dealer_catalog_distance( $distance ): string {
	return number_format_i18n( (int) $distance ) . ( 'en' === car_dealer_catalog_language() ? ' km' : ' كم' );
}

function car_dealer_catalog_value_label( string $value ): string {
	if ( 'en' !== car_dealer_catalog_language() ) {
		return $value;
	}
	$labels = array(
		'new'=>'New', 'used'=>'Used', 'gasoline'=>'Gasoline', 'diesel'=>'Diesel', 'hybrid'=>'Hybrid', 'electric'=>'Electric',
		'automatic'=>'Automatic', 'manual'=>'Manual', 'suv'=>'SUV', 'sedan'=>'Sedan', 'coupe'=>'Coupe', 'hatchback'=>'Hatchback',
		'pickup'=>'Pickup', 'van'=>'Van', 'fwd'=>'FWD', 'rwd'=>'RWD', 'awd'=>'AWD', '4wd'=>'4WD',
	);
	return $labels[ strtolower( $value ) ] ?? $value;
}

function car_dealer_vehicle_view( int $post_id, bool $preview = false ): ?array {
	return function_exists( 'adc_public_vehicle_view' ) ? adc_public_vehicle_view( $post_id, $preview ) : null;
}
