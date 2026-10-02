<?php get_header(); ?>
<?php
$home = function_exists( 'adc_home_page_view' ) ? adc_home_page_view() : array();
$featured_ids = $home['featured_ids'] ?? array();
$latest_ids = $home['latest_ids'] ?? array();
$demand_ids = $home['demand_ids'] ?? array();
$offer_ids = $home['offer_ids'] ?? array();
$total_cars = (int) ( $home['published_car_count'] ?? 0 );
$hero_image = $featured_ids ? get_the_post_thumbnail_url( $featured_ids[0], 'full' ) : '';
$whatsapp_url = car_dealer_whatsapp_url( 'مرحباً AUTO BRANDS، أريد الاستفسار عن السيارات المتاحة.' );
$catalog_url = car_dealer_archive_url( 'car' );
$offers_url = car_dealer_archive_url( 'car_offer' );
?>

<?php if ( ! function_exists( 'adc_home_page_view' ) ) : ?>
<div class="container"><p class="empty-state" role="status"><?php esc_html_e( 'الكتالوج غير متاح حاليًا. يرجى التواصل مع إدارة الموقع.', 'car-dealer' ); ?></p></div>
<?php endif; ?>

<!-- ═══════════════ HERO CINEMATIC ═══════════════ -->
<section class="ab-hero ab-hero-v2" <?php echo $hero_image ? 'style="--hero-image:url(' . esc_url( $hero_image ) . ')"' : ''; ?>>
	<div class="ab-hero-particles" aria-hidden="true">
		<span></span><span></span><span></span><span></span><span></span>
	</div>
	<div class="container ab-hero-grid">
		<div class="ab-hero-copy">
			<p class="eyebrow ab-hero-eyebrow"><?php esc_html_e( 'AUTO BRANDS', 'car-dealer' ); ?></p>
			<h1><?php esc_html_e( 'اختر سيارتك.. وابدأ رحلتك بثقة', 'car-dealer' ); ?></h1>
			<p class="ab-hero-desc"><?php esc_html_e( 'استعرض السيارات والعروض المنشورة، وتعرّف على تفاصيلها قبل إرسال طلبك إلى فريق المعرض.', 'car-dealer' ); ?></p>
			<div class="ab-actions">
				<?php if ( $catalog_url ) : ?><a class="btn btn-primary btn-lg" href="<?php echo esc_url( $catalog_url ); ?>">
					<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					<?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?>
				</a><?php endif; ?>
				<?php if ( '#' !== $whatsapp_url ) : ?><a class="btn btn-chrome btn-lg" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener">
					<svg class="btn-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.05-.67.149-.198.198-.597.694-.744.893-.148.198-.297.248-.546.05-.297-.198-1.164-.794-1.743-1.194-.621-.446-1.094-.694-1.39-.893-.198-.149-.446-.05-.645.099l-.744.893c-.148.198-.297.248-.546.05-.297-.198-.893-.597-1.39-.893-.496-.297-.993-.198-1.341.149l-.744.893c-.148.198-.297.248-.546.05-.297-.198-1.758-.867-2.03-.967-.273-.099-.471-.05-.67.149-.198.198-.297.248-.546.05l-.744.893c-.148.198-.297.248-.546.05-.297-.198-.893-.597-1.39-.893-.496-.297-.993-.198-1.341.149l-.744.893c-.148.198-.297.248-.546.05-.297-.198-1.758-.867-2.03-.967-.273-.099-.471-.05-.67.149z"/></svg>
					<?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?>
				</a><?php endif; ?>
			</div>
			<?php if ( $total_cars > 0 ) : ?><div class="ab-hero-stats"><div class="ab-stat"><strong><?php echo esc_html( number_format_i18n( $total_cars ) ); ?></strong><span><?php esc_html_e( 'سيارة منشورة', 'car-dealer' ); ?></span></div></div><?php endif; ?>
		</div>
		<?php if ( $total_cars > 0 ) : ?><div class="ab-hero-panel">
			<div class="ab-hero-panel-inner">
				<span class="ab-panel-label"><?php esc_html_e( 'Premium Automotive', 'car-dealer' ); ?></span>
				<strong class="ab-panel-number"><?php echo esc_html( number_format_i18n( $total_cars ) ); ?></strong>
				<small class="ab-panel-sub"><?php esc_html_e( 'سيارة منشورة', 'car-dealer' ); ?></small>
				<?php if ( $catalog_url ) : ?><a class="btn btn-primary btn-sm" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'تصفح الآن', 'car-dealer' ); ?></a><?php endif; ?>
			</div>
		</div><?php endif; ?>
	</div>
	<div class="ab-hero-scroll-hint" aria-hidden="true">
		<span><?php esc_html_e( 'اكتشف المزيد', 'car-dealer' ); ?></span>
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
	</div>
</section>

<!-- ═══════════════ FEATURED CARS ═══════════════ -->
<?php if ( $featured_ids ) : ?>
<section class="ab-section ab-featured-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'مختارة بعناية', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'السيارات المميزة', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'أفضل السيارات التي نرشحها لك بعناية فائقة', 'car-dealer' ); ?></p>
			</div>
			<?php if ( $catalog_url ) : ?><a class="section-link" href="<?php echo esc_url( $catalog_url ); ?>">
				<?php esc_html_e( 'عرض الكل', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a><?php endif; ?>
		</div>
		<?php echo car_dealer_render_shortcode_car_ids( $featured_ids, 'car-grid car-grid-featured' ); ?>
	</div>
</section>
<?php endif; ?>

<!-- ═══════════════ WHY CHOOSE US ═══════════════ -->
<section class="ab-section ab-why ab-why-v2">
	<div class="container">
		<div class="section-heading section-heading-v2 section-heading-center">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'ثقة وخبرة', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'لماذا AUTO BRANDS؟', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'نقدم لك تجربة شراء لا مثيل لها', 'car-dealer' ); ?></p>
			</div>
		</div>
		<div class="ab-feature-grid ab-feature-grid-v2">
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><path d="M16 24l5 5 11-11" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<span class="ab-feature-num">01</span>
				<h3><?php esc_html_e( 'اختيار متنوع', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'استعرض السيارات المنشورة وقارن بين مواصفاتها لاختيار ما يناسب احتياجك.', 'car-dealer' ); ?></p>
			</article>
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><rect x="14" y="20" width="20" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path d="M18 20v-4a6 6 0 0112 0v4" stroke="currentColor" stroke-width="2"/></svg>
				</div>
				<span class="ab-feature-num">02</span>
				<h3><?php esc_html_e( 'حلول تمويل', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'استخدم الحاسبة للاطلاع على تقدير أولي، وتعرّف على الخيارات المتاحة عند توفيرها.', 'car-dealer' ); ?></p>
			</article>
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><path d="M24 14v8l6 4" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><circle cx="24" cy="24" r="2" fill="currentColor"/></svg>
				</div>
				<span class="ab-feature-num">03</span>
				<h3><?php esc_html_e( 'فريق مبيعات سريع', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'أرسل استفسارك عبر الموقع ليتابعه فريق المعرض.', 'car-dealer' ); ?></p>
			</article>
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><path d="M16 28l4-8 4 5 4-7 4 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<span class="ab-feature-num">04</span>
				<h3><?php esc_html_e( 'تفاصيل واضحة', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'اطلع على معلومات كل سيارة وشروطها المنشورة قبل إرسال طلبك.', 'car-dealer' ); ?></p>
			</article>
		</div>
	</div>
</section>

<!-- ═══════════════ LATEST CARS (DARK) ═══════════════ -->
<?php if ( $latest_ids ) : ?>
<section class="ab-section ab-section-dark ab-latest-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'وصل حديثاً', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'أحدث السيارات', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'آخر الإضافات لمخزوننا المتجدد باستمرار', 'car-dealer' ); ?></p>
			</div>
			<?php if ( $catalog_url ) : ?><a class="section-link section-link-light" href="<?php echo esc_url( $catalog_url ); ?>">
				<?php esc_html_e( 'تصفح المخزون', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a><?php endif; ?>
		</div>
		<?php echo car_dealer_render_shortcode_car_ids( $latest_ids, 'car-grid car-grid-latest' ); ?>
	</div>
</section>
<?php endif; ?>

<!-- ═══════════════ OFFERS ═══════════════ -->
<?php if ( $offer_ids ) : ?>
<section class="ab-section ab-offers-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'عروض محدودة', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'العروض الحالية', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'لا تفوّت هذه الفرص الاستثنائية', 'car-dealer' ); ?></p>
			</div>
			<?php if ( $offers_url ) : ?><a class="section-link" href="<?php echo esc_url( $offers_url ); ?>">
				<?php esc_html_e( 'كل العروض', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a><?php endif; ?>
		</div>
		<div class="offer-grid offer-grid-v2"><?php foreach ( $offer_ids as $offer_id ) { car_dealer_offer_card( $offer_id ); } ?></div>
	</div>
</section>
<?php endif; ?>

<!-- ═══════════════ DEMAND CARS ═══════════════ -->
<?php if ( $demand_ids ) : ?>
<section class="ab-section ab-demand-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'من الكتالوج', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'سيارات مختارة', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'استعرض سيارات منشورة اختارها فريق المعرض لهذا القسم.', 'car-dealer' ); ?></p>
			</div>
		</div>
		<?php echo car_dealer_render_shortcode_car_ids( $demand_ids, 'car-grid car-grid-demand' ); ?>
	</div>
</section>
<?php endif; ?>

<!-- ═══════════════ FINANCE BAND ═══════════════ -->
<section class="ab-finance-band ab-finance-v2">
	<div class="container ab-finance-grid">
		<div class="ab-finance-copy">
			<p class="eyebrow"><?php esc_html_e( 'تمويل', 'car-dealer' ); ?></p>
			<h2><?php esc_html_e( 'احسب فرصتك التمويلية', 'car-dealer' ); ?></h2>
			<p><?php esc_html_e( 'احسب قسطًا تقديريًا للمقارنة الأولية. شروط التمويل الفعلية تعتمد على مزود الخدمة عند توفره.', 'car-dealer' ); ?></p>
			<?php echo do_shortcode( '[car_dealer_loan_calculator]' ); ?>
		</div>
	</div>
</section>

<!-- ═══════════════ TESTIMONIALS ═══════════════ -->
<?php echo do_shortcode( '[car_dealer_testimonials count="3"]' ); ?>

<!-- ═══════════════ WHATSAPP CTA ═══════════════ -->
<section class="ab-whatsapp-cta ab-cta-v2">
	<div class="container">
		<div class="ab-cta-content">
			<div class="ab-cta-icon" aria-hidden="true">
				<svg viewBox="0 0 64 64" fill="currentColor"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4zm14.08 38.56c-.64 1.28-2.88 2.4-4 2.56-1.12.16-2.56.16-4.16-.48-1.6-.64-3.52-1.44-5.92-2.88-5.12-3.04-8.32-8.16-8.64-8.64-.32-.48-2.56-3.36-2.56-6.4 0-3.04 1.6-4.48 2.24-5.12.64-.64 1.28-.64 1.76-.64h1.28c.48 0 1.12 0 1.6 1.12.48 1.28 1.76 4.32 1.92 4.64.16.32.16.64 0 .96-.16.32-.32.64-.64.96-.32.32-.64.8-.96 1.12-.32.32-.64.64-.32 1.28.32.64 1.6 2.88 3.52 4.8 2.24 2.24 4 3.04 4.64 3.36.64.32 1.12.32 1.44-.16.32-.48 1.6-1.92 2.08-2.56.48-.64.96-.48 1.6-.16.64.32 4 1.92 4.64 2.24.64.32 1.12.48 1.28.8.16.32.16 1.6-.48 2.88z"/></svg>
			</div>
			<h2><?php esc_html_e( 'جاهز لاختيار سيارتك القادمة؟', 'car-dealer' ); ?></h2>
			<p><?php esc_html_e( 'اختر سيارة وأرسل طلبك عبر الموقع.', 'car-dealer' ); ?></p>
			<?php if ( $catalog_url ) : ?><a class="btn btn-primary btn-xl" href="<?php echo esc_url( $catalog_url ); ?>">
				<?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?>
				<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
			</a><?php endif; ?>
		</div>
	</div>
</section>
<?php get_footer(); ?>
