<?php get_header(); ?>
<?php
$featured_cars = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => 6, 'meta_key' => '_car_featured', 'meta_value' => '1' ) );
$latest_cars   = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => 8 ) );
$demand_cars   = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => 3, 'meta_key' => '_car_demand', 'meta_value' => 'yes' ) );
$offers        = new WP_Query( array( 'post_type' => 'car_offer', 'posts_per_page' => 3 ) );
$hero_image    = '';
if ( $featured_cars->have_posts() ) {
	$featured_cars->the_post();
	$hero_image = get_the_post_thumbnail_url( get_the_ID(), 'full' );
	wp_reset_postdata();
}
$total_cars = wp_count_posts( 'car' )->publish;
?>

<!-- ═══════════════ HERO CINEMATIC ═══════════════ -->
<section class="ab-hero ab-hero-v2" <?php echo $hero_image ? 'style="--hero-image:url(' . esc_url( $hero_image ) . ')"' : ''; ?>>
	<div class="ab-hero-particles" aria-hidden="true">
		<span></span><span></span><span></span><span></span><span></span>
	</div>
	<div class="container ab-hero-grid">
		<div class="ab-hero-copy">
			<p class="eyebrow ab-hero-eyebrow"><?php esc_html_e( 'AUTO BRANDS', 'car-dealer' ); ?></p>
			<h1><?php esc_html_e( 'اختر سيارتك.. وابدأ رحلتك بثقة', 'car-dealer' ); ?></h1>
			<p class="ab-hero-desc"><?php esc_html_e( 'اكتشف مجموعة مختارة من أحدث السيارات والعروض المميزة، واستمتع بتجربة شراء متكاملة تجمع بين الجودة، والشفافية، والخدمة الاستثنائية.
سيارات نختارها بعناية.. وخدمة نضع ثقتك في مقدمة أولوياتها.', 'car-dealer' ); ?></p>
			<div class="ab-actions">
				<a class="btn btn-primary btn-lg" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">
					<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					<?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?>
				</a>
				<a class="btn btn-chrome btn-lg" href="<?php echo esc_url( car_dealer_whatsapp_url( 'مرحباً AUTO BRANDS، أريد الاستفسار عن السيارات المتاحة.' ) ); ?>" target="_blank" rel="noopener">
					<svg class="btn-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.05-.67.149-.198.198-.597.694-.744.893-.148.198-.297.248-.546.05-.297-.198-1.164-.794-1.743-1.194-.621-.446-1.094-.694-1.39-.893-.198-.149-.446-.05-.645.099l-.744.893c-.148.198-.297.248-.546.05-.297-.198-.893-.597-1.39-.893-.496-.297-.993-.198-1.341.149l-.744.893c-.148.198-.297.248-.546.05-.297-.198-1.758-.867-2.03-.967-.273-.099-.471-.05-.67.149-.198.198-.297.248-.546.05l-.744.893c-.148.198-.297.248-.546.05-.297-.198-.893-.597-1.39-.893-.496-.297-.993-.198-1.341.149l-.744.893c-.148.198-.297.248-.546.05-.297-.198-1.758-.867-2.03-.967-.273-.099-.471-.05-.67.149z"/></svg>
					<?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?>
				</a>
			</div>
			<div class="ab-hero-stats">
				<div class="ab-stat"><strong><?php echo esc_html( number_format_i18n( $total_cars ) ); ?>+</strong><span><?php esc_html_e( 'سيارة', 'car-dealer' ); ?></span></div>
				<div class="ab-stat-divider"></div>
				<div class="ab-stat"><strong>500+</strong><span><?php esc_html_e( 'عميل سعيد', 'car-dealer' ); ?></span></div>
				<div class="ab-stat-divider"></div>
				<div class="ab-stat"><strong>24/7</strong><span><?php esc_html_e( 'دعم فني', 'car-dealer' ); ?></span></div>
			</div>
		</div>
		<div class="ab-hero-panel">
			<div class="ab-hero-panel-inner">
				<span class="ab-panel-label"><?php esc_html_e( 'Premium Automotive', 'car-dealer' ); ?></span>
				<strong class="ab-panel-number"><?php echo esc_html( number_format_i18n( $total_cars ) ); ?></strong>
				<small class="ab-panel-sub"><?php esc_html_e( 'سيارة جاهزة للتسليم', 'car-dealer' ); ?></small>
				<a class="btn btn-primary btn-sm" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>"><?php esc_html_e( 'تصفح الآن', 'car-dealer' ); ?></a>
			</div>
		</div>
	</div>
	<div class="ab-hero-scroll-hint" aria-hidden="true">
		<span><?php esc_html_e( 'اكتشف المزيد', 'car-dealer' ); ?></span>
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
	</div>
</section>

<!-- ═══════════════ FEATURED CARS ═══════════════ -->
<section class="ab-section ab-featured-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'مختارة بعناية', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'السيارات المميزة', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'أفضل السيارات التي نرشحها لك بعناية فائقة', 'car-dealer' ); ?></p>
			</div>
			<a class="section-link" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">
				<?php esc_html_e( 'عرض الكل', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a>
		</div>
		<?php if ( $featured_cars->have_posts() ) : ?>
			<div class="car-grid car-grid-featured"><?php while ( $featured_cars->have_posts() ) { $featured_cars->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div>
		<?php else : ?>
			<div class="empty-state empty-state-v2">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="empty-icon"><path d="M8 17h8M8 17a4 4 0 01-4-4V7a4 4 0 014-4h4a4 4 0 014 4v6a4 4 0 01-4 4M12 3v6"/></svg>
				<p><?php esc_html_e( 'حدد سيارات مميزة من لوحة الإدارة لتظهر هنا.', 'car-dealer' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</section>

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
				<p><?php esc_html_e( 'سيارات مختارة تناسب الاستخدام اليومي والفخامة والعمل. أكثر من 50 علامة تجارية عالمية.', 'car-dealer' ); ?></p>
			</article>
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><rect x="14" y="20" width="20" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path d="M18 20v-4a6 6 0 0112 0v4" stroke="currentColor" stroke-width="2"/></svg>
				</div>
				<span class="ab-feature-num">02</span>
				<h3><?php esc_html_e( 'حلول تمويل', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'نساعدك في اختيار مسار التمويل الأنسب مع خطوات واضحة ومتابعة مستمرة حتى الموافقة.', 'car-dealer' ); ?></p>
			</article>
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><path d="M24 14v8l6 4" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><circle cx="24" cy="24" r="2" fill="currentColor"/></svg>
				</div>
				<span class="ab-feature-num">03</span>
				<h3><?php esc_html_e( 'فريق مبيعات سريع', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'كل طلب يصل مباشرة إلى لوحة الإدارة ليتابعه موظف المبيعات. رد خلال 30 دقيقة.', 'car-dealer' ); ?></p>
			</article>
			<article class="ab-feature-card">
				<div class="ab-feature-icon">
					<svg viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><path d="M16 28l4-8 4 5 4-7 4 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<span class="ab-feature-num">04</span>
				<h3><?php esc_html_e( 'ضمان شامل', 'car-dealer' ); ?></h3>
				<p><?php esc_html_e( 'ضمان شامل على جميع السيارات.', 'car-dealer' ); ?></p>
			</article>
		</div>
	</div>
</section>

<!-- ═══════════════ LATEST CARS (DARK) ═══════════════ -->
<section class="ab-section ab-section-dark ab-latest-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'وصل حديثاً', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'أحدث السيارات', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'آخر الإضافات لمخزوننا المتجدد باستمرار', 'car-dealer' ); ?></p>
			</div>
			<a class="section-link section-link-light" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">
				<?php esc_html_e( 'تصفح المخزون', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a>
		</div>
		<?php if ( $latest_cars->have_posts() ) : ?>
			<div class="car-grid car-grid-latest"><?php while ( $latest_cars->have_posts() ) { $latest_cars->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div>
		<?php endif; ?>
	</div>
</section>

<!-- ═══════════════ OFFERS ═══════════════ -->
<section class="ab-section ab-offers-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'عروض محدودة', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'العروض الحالية', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'لا تفوّت هذه الفرص الاستثنائية', 'car-dealer' ); ?></p>
			</div>
			<a class="section-link" href="<?php echo esc_url( get_post_type_archive_link( 'car_offer' ) ); ?>">
				<?php esc_html_e( 'كل العروض', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a>
		</div>
		<?php if ( $offers->have_posts() ) : ?>
			<div class="offer-grid offer-grid-v2"><?php while ( $offers->have_posts() ) { $offers->the_post(); car_dealer_offer_card( get_the_ID() ); } wp_reset_postdata(); ?></div>
		<?php else : ?>
			<div class="ab-offer-strip ab-offer-strip-v2">
				<div class="ab-offer-strip-content">
					<div class="ab-offer-badge"><?php esc_html_e( 'عرض الأسبوع', 'car-dealer' ); ?></div>
					<h3><?php esc_html_e( 'قسط يبدأ من XXXX ريال', 'car-dealer' ); ?></h3>
					<p><?php esc_html_e( 'عروض تمويلية حصرية مع شروط ميسرة', 'car-dealer' ); ?></p>
				</div>
				<a class="btn btn-primary btn-lg" href="<?php echo esc_url( car_dealer_whatsapp_url( 'أريد معرفة عروض AUTO BRANDS الحالية.' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'احصل على العرض', 'car-dealer' ); ?></a>
			</div>
		<?php endif; ?>
	</div>
</section>

<!-- ═══════════════ DEMAND CARS ═══════════════ -->
<section class="ab-section ab-demand-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'اختيارات العملاء', 'car-dealer' ); ?></p>
				<h2><?php esc_html_e( 'السيارات الأكثر طلباً', 'car-dealer' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'الأكثر مبيعاً والأعلى تقييماً من عملائنا', 'car-dealer' ); ?></p>
			</div>
		</div>
		<?php if ( $demand_cars->have_posts() ) : ?>
			<div class="car-grid car-grid-demand"><?php while ( $demand_cars->have_posts() ) { $demand_cars->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div>
		<?php else : ?>
			<?php echo do_shortcode( '[car_dealer_cars count="3"]' ); ?>
		<?php endif; ?>
	</div>
</section>

<!-- ═══════════════ FINANCE BAND ═══════════════ -->
<section class="ab-finance-band ab-finance-v2">
	<div class="container ab-finance-grid">
		<div class="ab-finance-copy">
			<p class="eyebrow"><?php esc_html_e( 'تمويل', 'car-dealer' ); ?></p>
			<h2><?php esc_html_e( 'احسب فرصتك التمويلية', 'car-dealer' ); ?></h2>
			<p><?php esc_html_e( 'تمويل بنكي وتمويل شركات مع مسار تقديم واضح ومتابعة من فريق AUTO BRANDS.', 'car-dealer' ); ?></p>
			<div class="ab-finance-badges">
				<span class="ab-badge">🏦 <?php esc_html_e( 'تمويل بنكي', 'car-dealer' ); ?></span>
				<span class="ab-badge">🏢 <?php esc_html_e( 'تمويل شركات', 'car-dealer' ); ?></span>
				<span class="ab-badge">⚡ <?php esc_html_e( 'موافقة سريعة', 'car-dealer' ); ?></span>
			</div>
		</div>
		<?php echo do_shortcode( '[car_dealer_loan_calculator]' ); ?>
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
			<p><?php esc_html_e( 'ابدأ المحادثة الآن، أو اختر سيارة وأرسل طلب سعر أو تمويل مباشرة.', 'car-dealer' ); ?></p>
			<a class="btn btn-primary btn-xl" href="<?php echo esc_url( car_dealer_whatsapp_url( 'أريد المساعدة في اختيار سيارة من AUTO BRANDS.' ) ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'تواصل عبر واتساب', 'car-dealer' ); ?>
				<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
			</a>
		</div>
	</div>
</section>
<?php get_footer(); ?>
