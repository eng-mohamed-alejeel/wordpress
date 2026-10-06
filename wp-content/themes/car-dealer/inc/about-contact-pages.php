<?php
/**
 * صفحات "من نحن" و"تواصل معنا" الاحترافية
 * مع shortcodes لإنشاء المحتوى الديناميكي
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 2.0
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════════════════════
   تعريفات صفحات العرض لأداة الإعداد الصريحة في الإضافة
   ═══════════════════════════════════════════════════════════ */

function car_dealer_editorial_page_blueprints( $blueprints ) {
	$blueprints = is_array( $blueprints ) ? $blueprints : array();
	return array_merge( $blueprints, array(
		'finance' => array(
			'title' => 'حاسبة التمويل',
			'content' => ( '<section class="cd-finance-intro" lang="ar" dir="rtl"><p>' . esc_html__( 'أدخل بيانات السيارة والدفعة الأولى والمدة في حاسبة التمويل للحصول على تقدير أولي غير ملزم للقسط الشهري. تختلف الأهلية والرسوم والشروط النهائية بحسب الجهة المرخصة والعرض المعتمد عند توفره.', 'car-dealer' ) . '</p></section>' )
				. '<section class="cd-finance-intro" lang="en" dir="ltr"><p>Enter the vehicle price, down payment and term in the Finance Calculator for a preliminary, non-binding monthly payment estimate. Eligibility, charges and final terms depend on an approved offer from a licensed provider when available.</p></section>'
				. '[car_dealer_loan_calculator]'
				. ( '<section class="cd-finance-enquiry" lang="ar" dir="rtl"><h2>' . esc_html__( 'لديك استفسار؟', 'car-dealer' ) . '</h2><p>' . esc_html__( 'أرسل استفسارك إلى فريق المعرض. إرسال النموذج لا يُنشئ طلب تمويل لدى مزود خارجي.', 'car-dealer' ) . '</p></section>' )
				. '<section class="cd-finance-enquiry" lang="en" dir="ltr"><h2>Have a question?</h2><p>Send your enquiry to the dealership team. Submitting this form does not create a financing application with an external provider.</p></section>'
				. '[car_dealer_contact_form]',
		),
		'about' => array(
			'title' => 'من نحن',
			'content' => '
[ab_hero_section]
[ab_mission_vision]
[ab_stats_section]
[ab_values_section]
[ab_team_section]
[ab_cta_section]
[ab_testimonials_section]
',
			'template' => 'page-about.php',
		),
		'contact' => array(
			'title' => 'تواصل معنا',
			'content' => '
[ab_contact_hero]
[ab_contact_grid]
[ab_contact_map]
[ab_contact_form_section]
[ab_faq_section]
[ab_social_section]
',
			'template' => 'page-contact.php',
		),
	) );
}
add_filter( 'adc_editorial_page_blueprints', 'car_dealer_editorial_page_blueprints' );

/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_hero_section] - البطل الرئيسي لمن نحن
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_hero() {
	$catalog_url = car_dealer_archive_url( 'car' );
	$contact_url = car_dealer_page_url( 'contact' );
	ob_start(); ?>
<section class="ab-about-hero">
	<div class="container">
		<div class="ab-about-hero-inner">
			<div class="ab-about-hero-text">
				<p class="eyebrow"><?php echo esc_html__( 'من نحن', 'car-dealer' ); ?></p>
				<h1><?php echo esc_html__( 'AUTO BRANDS للسيارات', 'car-dealer' ); ?></h1>
				<p><?php echo esc_html__( 'إحدى شركات ناصر مطر المطيري للسيارات', 'car-dealer' ); ?></p>
				<p class="ab-about-hero-desc"><?php echo esc_html__( 'نقدّم معلومات السيارات المنشورة ووسائل التواصل مع فريق المعرض. تتحدد أي خدمات إضافية وشروطها بحسب الاتفاق المتاح لكل سيارة.', 'car-dealer' ); ?></p>
				<div class="ab-actions">
					<?php if ( $catalog_url ) : ?><a href="<?php echo esc_url( $catalog_url ); ?>" class="btn btn-primary btn-lg">
						<span><?php echo esc_html__( 'استكشف سياراتنا', 'car-dealer' ); ?></span>
						<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					</a><?php endif; ?>
					<?php if ( $contact_url ) : ?><a href="<?php echo esc_url( $contact_url ); ?>" class="btn btn-outline btn-lg">
						<span><?php echo esc_html__( 'تواصل معنا', 'car-dealer' ); ?></span>
						<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
					</a><?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_hero_section', 'car_dealer_shortcode_ab_hero' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_mission_vision] - الرؤية والرسالة
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_mission_vision() {
	ob_start(); ?>
<section class="ab-mission-vision ab-section">
	<div class="container">
		<div class="section-heading section-heading-center">
			<div>
				<p class="eyebrow"><?php echo esc_html__( 'رسالتنا ورؤيتنا', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html__( 'نحن هنا لنوفر لك أفضل تجربة', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php echo esc_html__( 'نؤمن بأن كل عميل يستحق الأفضل، ونعمل بلا كلل لتحقيق ذلك', 'car-dealer' ); ?></p>
			</div>
		</div>
		<div class="ab-mission-grid">
			<div class="ab-mission-card ab-mission-mission">
				<div class="ab-mission-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
				</div>
				<h3><?php echo esc_html__( 'رسالتنا', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'نهدف إلى عرض معلومات السيارات وأسعارها المنشورة بوضوح، وتيسير التواصل مع فريق المعرض.', 'car-dealer' ); ?></p>
			</div>
			<div class="ab-mission-card ab-mission-vision">
				<div class="ab-mission-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
				</div>
				<h3><?php echo esc_html__( 'رؤيتنا', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'تطوير تجربة واضحة لاختيار السيارة ومتابعة الطلب من الاستفسار حتى إتمام البيع.', 'car-dealer' ); ?></p>
			</div>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_mission_vision', 'car_dealer_shortcode_ab_mission_vision' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_stats_section] - الإحصائيات
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_stats() {
	// Publish metrics only after actual figures and their source are approved.
	return '';
}
car_dealer_register_shortcode_adapter( 'ab_stats_section', 'car_dealer_shortcode_ab_stats' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_values_section] - القيم والمزايا
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_values() {
	ob_start(); ?>
<section class="ab-values-section ab-section ab-why">
	<div class="container">
		<div class="section-heading section-heading-center">
			<div>
				<p class="eyebrow"><?php echo esc_html__( 'لماذا تختارنا', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html__( 'قيمنا تميّزنا', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php echo esc_html__( 'نحن لا نبيع سيارات فقط، بل نبني علاقات ثقة مع عملائنا', 'car-dealer' ); ?></p>
			</div>
		</div>
		<div class="ab-feature-grid-v2">
			<div class="ab-feature-card">
				<span class="ab-feature-num">01</span>
				<div class="ab-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
				</div>
				<h3><?php echo esc_html__( 'معلومات السيارة', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'اطّلع على المواصفات والحالة المنشورة لكل سيارة، واطلب الوثائق والتفاصيل قبل الشراء.', 'car-dealer' ); ?></p>
			</div>
			<div class="ab-feature-card">
				<span class="ab-feature-num">02</span>
				<div class="ab-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
				</div>
				<h3><?php echo esc_html__( 'أسعار منشورة', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'قارن الأسعار المعروضة واستفسر عن الشروط المتاحة لكل سيارة.', 'car-dealer' ); ?></p>
			</div>
			<div class="ab-feature-card">
				<span class="ab-feature-num">03</span>
				<div class="ab-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
				</div>
				<h3><?php echo esc_html__( 'خدمة عملاء ممتازة', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'يمكنك إرسال استفسارك عبر قنوات التواصل المتاحة وسيتابعه فريق المعرض.', 'car-dealer' ); ?></p>
			</div>
			<div class="ab-feature-card">
				<span class="ab-feature-num">04</span>
				<div class="ab-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
				</div>
				<h3><?php echo esc_html__( 'تسليم سريع', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'تُحدد خطوات التسليم ومستنداته في اتفاق البيع المعتمد.', 'car-dealer' ); ?></p>
			</div>
			<div class="ab-feature-card">
				<span class="ab-feature-num">05</span>
				<div class="ab-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
				</div>
				<h3><?php echo esc_html__( 'تفاصيل مكتوبة', 'car-dealer' ); ?></h3>
				<p><?php echo esc_html__( 'راجع مستندات البيع والضمان والشروط المتاحة قبل الموافقة النهائية.', 'car-dealer' ); ?></p>
			</div>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_values_section', 'car_dealer_shortcode_ab_values' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_team_section] - الفريق
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_team() {
	$team_members = array(
		array(
			'name' => 'الاستاذ/ ناصر مطر المطيري',
			'role' => 'الرئيس المؤسس ',
			'experience' => 'خبرة 30 سنة في تجارة السيارات',
			'bg' => 'linear-gradient(135deg, #D69E2E, #ECC94B)',
		),
		array(
			'name' => 'الاستاذ/ محمد مطر المطيري',
			'role' => 'المدير التنفيذي',
			'experience' => 'خبرة 25 سنة في معارض السيارات',
			'bg' => 'linear-gradient(135deg, #0669D9, #2B4C7E)',
		),
		array(
			'name' => 'الاستاذ/ عاطف يوسف الشافعي',
			'role' => 'المدير العام',
			'experience' => 'خبرة 40 سنة في مجال السيارات',
			'bg' => 'linear-gradient(135deg, #E21D2F, #D02B2B)',
		),
		array(
			'name' => 'الاستاذة/ نجلاء ناصر المطيري',
			'role' => 'مدير الموارد البشرية',
			'experience' => 'خبرة 6 سنوات في مجال السيارات',
			'bg' => 'linear-gradient(135deg, #2F855A, #38A169)',
		),
	);

	$output = '<section class="ab-team-section ab-section"><div class="container">';
	$output .= ( '<div class="section-heading section-heading-center"><div><p class="eyebrow">' . esc_html__( 'الإدارة العليا', 'car-dealer' ) . '</p><h2>' . esc_html__( 'خبراء في المجال', 'car-dealer' ) . '</h2><p class="section-subtitle">' . esc_html__( 'إدارة مؤسسة وكفاءات عالية', 'car-dealer' ) . '</p></div></div>' );
	$output .= '<div class="ab-team-grid">';

	foreach ( $team_members as $member ) {
		$output .= '
		<div class="ab-team-card">
			<div class="ab-team-avatar" style="background:' . esc_attr( $member['bg'] ) . ';">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
			</div>
			<h3>' . esc_html__( $member['name'], 'car-dealer' ) . '</h3>
			<p class="ab-team-role">' . esc_html__( $member['role'], 'car-dealer' ) . '</p>
			<p class="ab-team-experience">' . esc_html__( $member['experience'], 'car-dealer' ) . '</p>
		</div>';
	}

	$output .= '</div></div></section>';
	return $output;
}
car_dealer_register_shortcode_adapter( 'ab_team_section', 'car_dealer_shortcode_ab_team' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_cta_section] - دعوة للعمل
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_cta() {
	$contact_url = car_dealer_page_url( 'contact' );
	$whatsapp_url = car_dealer_whatsapp_url( car_dealer_text( 'مرحباً، أود الاستفسار عن سيارة.', 'Hello, I would like to ask about a vehicle.' ) );
	if ( ! $contact_url && '#' === $whatsapp_url ) { return ''; }
	ob_start(); ?>
<section class="ab-cta-v2">
	<div class="container">
		<div class="ab-cta-content">
			<div class="ab-cta-icon">
				<svg viewBox="0 0 64 64" fill="currentColor"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4z"/></svg>
			</div>
			<h2><?php echo esc_html__( 'جاهز لتجربة سيارة جديدة؟', 'car-dealer' ); ?></h2>
			<p><?php echo esc_html__( 'تواصل مع فريق المعرض للاستفسار عن السيارات والشروط المتاحة.', 'car-dealer' ); ?></p>
			<div class="ab-actions">
				<?php if ( $contact_url ) : ?><a href="<?php echo esc_url( $contact_url ); ?>" class="btn btn-primary btn-lg">
					<span><?php echo esc_html__( 'تواصل معنا الآن', 'car-dealer' ); ?></span>
					<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
				</a><?php endif; ?>
				<?php if ( '#' !== $whatsapp_url ) : ?><a href="<?php echo esc_url( $whatsapp_url ); ?>" class="btn btn-chrome btn-lg" target="_blank" rel="noopener">
					<span><?php echo esc_html__( 'تواصل عبر واتساب', 'car-dealer' ); ?></span>
				</a><?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_cta_section', 'car_dealer_shortcode_ab_cta' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_testimonials_section] - آراء العملاء
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_testimonials() {
	// Render only approved, real customer endorsements when a content source exists.
	return '';
}
car_dealer_register_shortcode_adapter( 'ab_testimonials_section', 'car_dealer_shortcode_ab_testimonials' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_contact_hero] - بطل صفحة التواصل
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_contact_hero() {
	ob_start(); ?>
<section class="ab-contact-hero">
	<div class="container">
		<div class="ab-contact-hero-inner">
			<div class="ab-contact-hero-text">
				<p class="eyebrow"><?php echo esc_html__( 'تواصل معنا', 'car-dealer' ); ?></p>
				<h1><?php echo esc_html__( 'نحن هنا لمساعدتك', 'car-dealer' ); ?></h1>
				<p class="ab-contact-hero-desc"><?php echo esc_html__( 'فريقنا جاهز للإجابة على جميع استفساراتك ومساعدتك في اختيار السيارة المناسبة لك. تواصل معنا عبر أي من القنوات أدناه.', 'car-dealer' ); ?></p>
			</div>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_contact_hero', 'car_dealer_shortcode_ab_contact_hero' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_contact_grid] - بطاقات التواصل
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_contact_grid() {
	$options = car_dealer_theme_options();
	$items = array();
	if ( $options['address'] ) { $items[] = array( 'العنوان', esc_html( $options['address'] ) ); }
	$phone = preg_replace( '/[^0-9+]/', '', (string) $options['phone'] );
	if ( $phone ) { $items[] = array( 'الهاتف', '<a class="ab-contact-link" href="tel:' . esc_attr( $phone ) . '">' . esc_html( $options['phone'] ) . '</a>' ); }
	if ( is_email( $options['email'] ) ) { $items[] = array( 'البريد الإلكتروني', '<a class="ab-contact-link" href="mailto:' . esc_attr( $options['email'] ) . '">' . esc_html( $options['email'] ) . '</a>' ); }
	if ( ! $items ) { return ''; }
	$html = '<section class="ab-contact-grid-section ab-section"><div class="container"><div class="ab-contact-grid">';
	foreach ( $items as $item ) { $html .= '<div class="ab-contact-info-card"><h3>' . esc_html__( $item[0], 'car-dealer' ) . '</h3><p>' . $item[1] . '</p></div>'; }
	return $html . '</div></div></section>';
}
car_dealer_register_shortcode_adapter( 'ab_contact_grid', 'car_dealer_shortcode_ab_contact_grid' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_contact_map] - الخريطة
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_contact_map() {
	$options = car_dealer_theme_options();
	$address = trim( (string) $options['address'] );
	if ( '' === $address ) { return ''; }
	$url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
	return '<section class="ab-contact-map-section ab-section"><div class="container"><div class="section-heading section-heading-center"><h2>' . esc_html__( 'الموقع', 'car-dealer' ) . '</h2></div><p>' . esc_html( $address ) . '</p><p><a class="btn btn-outline" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'عرض الموقع على الخريطة', 'car-dealer' ) . '</a></p></div></section>';
}
car_dealer_register_shortcode_adapter( 'ab_contact_map', 'car_dealer_shortcode_ab_contact_map' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_contact_form_section] - نموذج التواصل
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_contact_form() {
	ob_start(); ?>
<section class="ab-contact-form-section ab-section">
	<div class="container">
		<div class="ab-contact-form-wrapper">
			<div class="section-heading">
				<div>
					<p class="eyebrow"><?php echo esc_html__( 'أرسل لنا رسالة', 'car-dealer' ); ?></p>
					<h2><?php echo esc_html__( 'تواصل معنا', 'car-dealer' ); ?></h2>
				</div>
				<p class="section-subtitle"><?php echo esc_html__( 'املأ النموذج أدناه وسوف نتواصل معك في أقرب وقت', 'car-dealer' ); ?></p>
			</div>
			<?php echo do_shortcode( '[car_dealer_contact_form]' ); ?>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_contact_form_section', 'car_dealer_shortcode_ab_contact_form' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_faq_section] - الأسئلة الشائعة
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_faq() {
	$faqs = array(
		array(
			'q' => 'ما هي سياسات الضمان المتوفرة لديكم؟',
			'a' => 'تختلف تغطية الضمان ومدته حسب السيارة والجهة المزوّدة. اطلب التفاصيل المكتوبة قبل إتمام الشراء.',
		),
		array(
			'q' => 'هل تقدمون خدمات التمويل؟',
			'a' => 'تُعرض خيارات التمويل وشروطها بعد توفر مزود معتمد ودراسة الطلب. الحاسبة في الموقع تقديرية فقط.',
		),
		array(
			'q' => 'هل يمكنني تجربة قيادة السيارة؟',
			'a' => 'يمكنك إرسال طلب تجربة قيادة، ويؤكد المعرض الموعد والتوفر قبل الزيارة.',
		),
		array(
			'q' => 'ما هي خدمات ما بعد البيع؟',
			'a' => 'تلميع.',
		),
	);

	$output = '<section class="ab-faq-section ab-section"><div class="container">';
	$output .= ( '<div class="section-heading section-heading-center"><div><p class="eyebrow">' . esc_html__( 'الأسئلة الشائعة', 'car-dealer' ) . '</p><h2>' . esc_html__( 'الأسئلة المتكررة', 'car-dealer' ) . '</h2><p class="section-subtitle">' . esc_html__( 'إجابات على أكثر الأسئلة شيوعاً', 'car-dealer' ) . '</p></div></div>' );
	$output .= '<div class="ab-faq-list">';

	foreach ( $faqs as $faq ) {
		$output .= '
		<div class="ab-faq-item">
			<button class="ab-faq-question" aria-expanded="false">
				<span>' . esc_html__( $faq['q'], 'car-dealer' ) . '</span>
				<svg class="ab-faq-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="ab-faq-answer">
				<p>' . esc_html__( $faq['a'], 'car-dealer' ) . '</p>
			</div>
		</div>';
	}

	$output .= '</div></div></section>';
	$output .= '<script>
	document.addEventListener("DOMContentLoaded", function() {
		document.querySelectorAll(".ab-faq-question").forEach(function(btn) {
			btn.addEventListener("click", function() {
				var answer = this.nextElementSibling;
				var isOpen = answer.style.maxHeight;
				document.querySelectorAll(".ab-faq-answer").forEach(function(a) { a.style.maxHeight = null; });
				document.querySelectorAll(".ab-faq-question").forEach(function(b) { b.setAttribute("aria-expanded", "false"); b.classList.remove("is-open"); });
				if (!isOpen) {
					answer.style.maxHeight = answer.scrollHeight + "px";
					btn.setAttribute("aria-expanded", "true");
					btn.classList.add("is-open");
				}
			});
		});
	});
	</script>';

	return $output;
}
car_dealer_register_shortcode_adapter( 'ab_faq_section', 'car_dealer_shortcode_ab_faq' );


/* ═══════════════════════════════════════════════════════
   Shortcode: [ab_social_section] - وسائل التواصل الاجتماعي
   ═══════════════════════════════════════════════════════ */
function car_dealer_shortcode_ab_social() {
	$options = car_dealer_theme_options();
	$facebook = esc_url( $options['facebook'] ?? '' );
	$instagram = esc_url( $options['instagram'] ?? '' );
	$phone = preg_replace( '/\D+/', '', (string) ( $options['whatsapp'] ?: get_option( 'car_dealer_whatsapp', '' ) ) );
	if ( ! $facebook && ! $instagram && ! $phone ) { return ''; }
	ob_start(); ?>
<section class="ab-social-section">
	<div class="container">
		<div class="ab-social-inner">
			<div class="ab-social-content">
				<h2><?php echo esc_html__( 'تابعنا على وسائل التواصل', 'car-dealer' ); ?></h2>
				<p><?php echo esc_html__( 'ابقَ على اطلاع بآخر العروض والمبادرات الجديدة', 'car-dealer' ); ?></p>
			</div>
			<div class="ab-social-links">
				<?php if ( $facebook ) : ?><a href="<?php echo esc_url( $facebook ); ?>" class="ab-social-btn ab-social-fb" aria-label="<?php echo esc_attr__( 'فيسبوك', 'car-dealer' ); ?>">
					<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
					<?php echo esc_html__( 'فيسبوك', 'car-dealer' ); ?>
				</a><?php endif; ?>
				<?php if ( $instagram ) : ?><a href="<?php echo esc_url( $instagram ); ?>" class="ab-social-btn ab-social-insta" aria-label="<?php echo esc_attr__( 'إنستغرام', 'car-dealer' ); ?>">
					<svg viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5"/></svg>
					<?php echo esc_html__( 'إنستغرام', 'car-dealer' ); ?>
				</a><?php endif; ?>
				<?php if ( $phone ) : ?><a href="<?php echo esc_url( 'https://wa.me/' . $phone ); ?>" class="ab-social-btn ab-social-wa" aria-label="<?php echo esc_attr__( 'واتساب', 'car-dealer' ); ?>">
					<svg viewBox="0 0 64 64" fill="currentColor"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4z"/></svg>
					<?php echo esc_html__( 'واتساب', 'car-dealer' ); ?>
				</a><?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'ab_social_section', 'car_dealer_shortcode_ab_social' );
