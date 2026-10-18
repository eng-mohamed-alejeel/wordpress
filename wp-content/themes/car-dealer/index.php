<?php get_header(); ?>
<?php
$home = function_exists( 'adc_home_page_view' ) ? adc_home_page_view() : array();
$featured_ids = $home['featured_ids'] ?? array();
$latest_ids = $home['latest_ids'] ?? array();
$demand_ids = $home['demand_ids'] ?? array();
$offer_ids = $home['offer_ids'] ?? array();
$total_cars = (int) ( $home['available_car_count'] ?? 0 );
$catalog_car_count = (int) ( $home['published_car_count'] ?? 0 );
$car_count_step = $total_cars > 50 ? (int) ( floor( ( $total_cars - 1 ) / 50 ) * 50 ) : $total_cars;
$content_class = '\AutoDealership\Content\SiteContent';
$manage_content = class_exists($content_class);
$site_content = $manage_content ? $content_class::data() : array();
$hero_image = !empty($site_content['hero_image']) ? wp_get_attachment_image_url($site_content['hero_image'], 'full') : ($featured_ids ? get_the_post_thumbnail_url( $featured_ids[0], 'full' ) : '');
$home_text = static fn($key,$field,$fallback) => $manage_content ? $content_class::home_text($key,$field,$fallback) : $fallback;
$home_parts = array();
$whatsapp_url = car_dealer_whatsapp_url( car_dealer_text( 'مرحباً {site_name}، أريد الاستفسار عن السيارات المتاحة.', 'Hello {site_name}, I would like to ask about the available vehicles.' ) );
$catalog_url = car_dealer_archive_url( 'car' );
$offers_url = car_dealer_archive_url( 'car_offer' );
$home_scroll_target = '';
foreach(array('featured'=>'featured-vehicles','why'=>'why-auto-brands','latest'=>'latest-vehicles','offers'=>'current-offers','demand'=>'selected-vehicles','cta'=>'browse-inventory') as $section=>$anchor) {
 if ($manage_content && !$content_class::home_enabled($section)) { continue; }
 $home_scroll_target=$anchor; break;
}
?>

<?php if ( ! function_exists( 'adc_home_page_view' ) ) : ?>
<div class="container"><p class="empty-state" role="status"><?php esc_html_e( 'الكتالوج غير متاح حاليًا. يرجى التواصل مع إدارة الموقع.', 'car-dealer' ); ?></p></div>
<?php endif; ?>

<!-- ═══════════════ HERO CINEMATIC ═══════════════ -->
<?php ob_start(); ?>
<section class="ab-hero ab-hero-v2" <?php echo $hero_image ? 'style="--hero-image:url(' . esc_url( $hero_image ) . ')"' : ''; ?>>
	<div class="ab-hero-particles" aria-hidden="true">
		<span></span><span></span><span></span><span></span><span></span>
	</div>
	<div class="container ab-hero-grid">
		<div class="ab-hero-copy">
			<p class="eyebrow ab-hero-eyebrow"><?php echo esc_html( car_dealer_brand_name() ); ?></p>
			<h1><?php echo esc_html( $home_text('hero','title',car_dealer_text( 'سيارتك الجديدة تبدأ من {site_name}', 'Your new vehicle starts with {site_name}' )) ); ?></h1>
			<p class="ab-hero-desc"><?php echo esc_html( $home_text('hero','description',class_exists( '\AutoDealership\Core\ProductSetup' ) ? \AutoDealership\Core\ProductSetup::hero_description( car_dealer_catalog_language() ) : car_dealer_text( 'استعرض السيارات المتاحة وتواصل مع فريقنا.', 'Explore available vehicles and contact our team.' ) )); ?></p>
			<div class="ab-actions">
				<?php if ( $catalog_url ) : ?><a class="btn btn-primary btn-lg" href="<?php echo esc_url( $catalog_url ); ?>">
					<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
					<?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?>
				</a><?php endif; ?>
				<?php if ( '#' !== $whatsapp_url ) : ?><a class="btn btn-whatsapp btn-lg" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener">
					<?php echo car_dealer_whatsapp_icon(); ?>
					<?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?>
				</a><?php endif; ?>
			</div>
			<?php if ( $catalog_car_count > 0 ) : ?><div class="ab-hero-stats"><div class="ab-stat"><strong><?php echo esc_html( number_format_i18n( $catalog_car_count ) ); ?></strong><span><?php echo esc_html( car_dealer_text( 'خيارًا من السيارات', $catalog_car_count === 1 ? 'Vehicle option' : 'Vehicle options' ) ); ?></span></div></div><?php endif; ?>
		</div>
		<?php if ( $total_cars > 0 ) : ?><div class="ab-hero-panel">
			<div class="ab-hero-panel-inner">
				<span class="ab-panel-label"><?php echo esc_html( $total_cars > 50 ? car_dealer_text( 'أكثر من', 'More than' ) : car_dealer_text( 'اختر سيارتك', 'Choose your vehicle' ) ); ?></span>
				<strong class="ab-panel-number"><?php echo esc_html( number_format_i18n( $car_count_step ) ); ?></strong>
				<small class="ab-panel-sub"><?php echo esc_html( car_dealer_text( 'سيارة متاحة', $total_cars === 1 ? 'Available vehicle' : 'Available vehicles' ) ); ?></small>
				<?php if ( $catalog_url ) : ?><a class="btn btn-primary btn-sm" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'تصفح الآن', 'car-dealer' ); ?></a><?php endif; ?>
			</div>
		</div><?php endif; ?>
	</div>
	<?php if ($home_scroll_target) : ?><a class="ab-hero-scroll-hint" href="#<?php echo esc_attr( $home_scroll_target ); ?>">
		<span><?php esc_html_e( 'اكتشف المزيد', 'car-dealer' ); ?></span>
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
	</a>
<?php endif; ?>
</section>

<?php $home_parts['hero'] = ob_get_clean(); ?>
<!-- ═══════════════ FEATURED CARS ═══════════════ -->
<?php ob_start(); ?>
<?php if ( $featured_ids ) : ?>
<section id="featured-vehicles" class="ab-section ab-featured-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'مختارة بعناية', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html($home_text('featured','title',car_dealer_text('السيارات المميزة','Featured vehicles'))); ?></h2>
				<p class="section-subtitle"><?php echo esc_html($home_text('featured','description',car_dealer_text('أفضل السيارات التي نرشحها لك بعناية فائقة','Explore vehicles selected by our team.'))); ?></p>
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

<?php $home_parts['featured'] = ob_get_clean(); ?>
<!-- ═══════════════ WHY CHOOSE US ═══════════════ -->
<?php ob_start(); ?>
<section id="why-auto-brands" class="ab-section ab-why ab-why-v2">
	<div class="container">
		<div class="section-heading section-heading-v2 section-heading-center">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'ثقة وخبرة', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html( $home_text('why','title',car_dealer_text( 'لماذا {site_name}؟', 'Why {site_name}?' )) ); ?></h2>
				<p class="section-subtitle"><?php echo esc_html($home_text('why','description',car_dealer_text('نقدم لك تجربة شراء لا مثيل لها','Explore your options with support from our team.'))); ?></p>
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
				<h3><?php $cd_feature_finance = car_dealer_page_url( 'finance' ); if ( $cd_feature_finance ) : ?><a href="<?php echo esc_url( $cd_feature_finance ); ?>"><?php esc_html_e( 'حاسبة التمويل', 'car-dealer' ); ?></a><?php else : esc_html_e( 'حاسبة التمويل', 'car-dealer' ); endif; ?></h3>
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

<?php $home_parts['why'] = ob_get_clean(); ?>
<!-- ═══════════════ LATEST CARS (DARK) ═══════════════ -->
<?php ob_start(); ?>
<?php if ( $latest_ids ) : ?>
<section id="latest-vehicles" class="ab-section ab-inventory-section ab-latest-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'وصل حديثاً', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html($home_text('latest','title',car_dealer_text('أحدث السيارات','Latest vehicles'))); ?></h2>
				<p class="section-subtitle"><?php echo esc_html($home_text('latest','description',car_dealer_text('اكتشف أحدث السيارات المتاحة بخيارات تتجدد باستمرار','Discover recently published vehicles.'))); ?></p>
			</div>
			<?php if ( $catalog_url ) : ?><a class="section-link" href="<?php echo esc_url( $catalog_url ); ?>">
				<?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a><?php endif; ?>
		</div>
		<div class="latest-rows">
		<?php foreach ( array_chunk( $latest_ids, max( 1, (int) ceil( count( $latest_ids ) / 3 ) ) ) as $row_index => $row_ids ) :
			$row_id = 'latest-vehicles-row-' . ( $row_index + 1 );
			$row_label = car_dealer_text( 'أحدث السيارات — الصف ', 'Latest vehicles — row ' ) . ( $row_index + 1 );
		?>
			<div class="latest-row" data-vehicle-row>
				<div class="latest-row-controls">
					<button type="button" data-scroll-direction="left" aria-controls="<?php echo esc_attr( $row_id ); ?>" aria-label="<?php echo esc_attr( car_dealer_text( 'تحريك لليسار: ', 'Scroll left: ' ) . $row_label ); ?>"><span aria-hidden="true">←</span></button>
					<button type="button" data-scroll-direction="right" aria-controls="<?php echo esc_attr( $row_id ); ?>" aria-label="<?php echo esc_attr( car_dealer_text( 'تحريك لليمين: ', 'Scroll right: ' ) . $row_label ); ?>"><span aria-hidden="true">→</span></button>
				</div>
				<div id="<?php echo esc_attr( $row_id ); ?>" class="latest-row-track" tabindex="0" role="region" aria-label="<?php echo esc_attr( $row_label ); ?>">
					<?php echo car_dealer_render_shortcode_car_ids( $row_ids, 'car-grid car-grid-latest' ); ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php $home_parts['latest'] = ob_get_clean(); ?>
<!-- ═══════════════ OFFERS ═══════════════ -->
<?php ob_start(); ?>
<?php if ( $offer_ids ) : ?>
<section id="current-offers" class="ab-section ab-offers-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'عروض محدودة', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html($home_text('offers','title',car_dealer_text('العروض الحالية','Current offers'))); ?></h2>
				<p class="section-subtitle"><?php echo esc_html($home_text('offers','description',car_dealer_text('لا تفوّت هذه الفرص الاستثنائية','Explore our current offers.'))); ?></p>
			</div>
			<?php if ( $offers_url ) : ?><a class="section-link" href="<?php echo esc_url( $offers_url ); ?>">
				<?php esc_html_e( 'كل العروض', 'car-dealer' ); ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l7-7-7-7"/></svg>
			</a><?php endif; ?>
		</div>
		<?php ob_start(); foreach ( $offer_ids as $offer_id ) { car_dealer_offer_card( $offer_id ); } car_dealer_offer_rail( ob_get_clean() ); ?>
	</div>
</section>
<?php endif; ?>

<?php $home_parts['offers'] = ob_get_clean(); ?>
<!-- ═══════════════ DEMAND CARS ═══════════════ -->
<?php ob_start(); ?>
<?php if ( $demand_ids ) : ?>
<section id="selected-vehicles" class="ab-section ab-demand-section">
	<div class="container">
		<div class="section-heading section-heading-v2">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'من الكتالوج', 'car-dealer' ); ?></p>
				<h2><?php echo esc_html($home_text('demand','title',car_dealer_text('سيارات مختارة','Selected vehicles'))); ?></h2>
				<p class="section-subtitle"><?php echo esc_html($home_text('demand','description',car_dealer_text('استعرض سيارات منشورة اختارها فريق المعرض لهذا القسم.','Explore published vehicles selected by our team.'))); ?></p>
			</div>
		</div>
		<?php echo car_dealer_render_shortcode_car_ids( $demand_ids, 'car-grid car-grid-demand' ); ?>
	</div>
</section>
<?php endif; ?>

<?php $home_parts['demand'] = ob_get_clean(); ?>
<!-- ═══════════════ TESTIMONIALS ═══════════════ -->
<?php ob_start(); ?>
<?php echo $manage_content ? $content_class::render('reviews',array('count'=>$site_content['home']['reviews']['count'],'title'=>$home_text('reviews','title',''),'description'=>$home_text('reviews','description',''))) : do_shortcode('[car_dealer_testimonials count="3"]'); ?>

<?php $home_parts['reviews'] = ob_get_clean(); ?>
<!-- ═══════════════ BROWSE INVENTORY CTA ═══════════════ -->
<?php ob_start(); ?>
<section id="browse-inventory" class="ab-whatsapp-cta ab-cta-v2 ab-inventory-cta">
	<div class="container">
		<div class="ab-cta-content">
			<div class="ab-inventory-cta-copy">
				<h2><?php echo esc_html( $home_text('cta','title',car_dealer_text( 'اعثر على سيارتك القادمة', 'Find your next car' )) ); ?></h2>
				<p><?php echo esc_html( $home_text('cta','description',car_dealer_text( 'تصفّح السيارات المتاحة وقارن المواصفات والأسعار.', 'Browse available vehicles and compare specifications and prices.' )) ); ?></p>
			</div>
			<div class="ab-inventory-cta-actions">
			<?php if ( $catalog_url ) : ?><a class="btn btn-primary btn-xl" href="<?php echo esc_url( $catalog_url ); ?>">
				<?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?>
				<svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
			</a><?php endif; ?>
			<?php $cta_contact_url = car_dealer_page_url( 'contact' ); if ( $cta_contact_url ) : ?><a class="btn btn-outline" href="<?php echo esc_url( $cta_contact_url ); ?>"><?php echo esc_html( car_dealer_text( 'تواصل معنا', 'Contact us' ) ); ?></a><?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php $home_parts['cta'] = ob_get_clean();
if ($manage_content) {
 $home_parts['team'] = $content_class::render('team',array('count'=>$site_content['home']['team']['count'],'title'=>$home_text('team','title',''),'description'=>$home_text('team','description','')));
 $home_parts['metrics'] = $content_class::render('metrics',array('count'=>$site_content['home']['metrics']['count'],'title'=>$home_text('metrics','title',''),'description'=>$home_text('metrics','description','')));
 echo $content_class::home_render($home_parts);
} else { echo implode('', $home_parts); }
get_footer(); ?>
