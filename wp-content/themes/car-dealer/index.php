<?php get_header(); ?>
<?php
$featured_cars = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => 3, 'meta_key' => '_car_featured', 'meta_value' => '1' ) );
$latest_cars   = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => 6 ) );
$demand_cars   = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => 3, 'meta_key' => '_car_demand', 'meta_value' => 'yes' ) );
$offers        = new WP_Query( array( 'post_type' => 'car_offer', 'posts_per_page' => 3 ) );
$hero_image    = '';
if ( $featured_cars->have_posts() ) {
	$featured_cars->the_post();
	$hero_image = get_the_post_thumbnail_url( get_the_ID(), 'full' );
	wp_reset_postdata();
}
?>
<section class="ab-hero" <?php echo $hero_image ? 'style="--hero-image:url(' . esc_url( $hero_image ) . ')"' : ''; ?>>
	<div class="container ab-hero-grid">
		<div class="ab-hero-copy">
			<p class="eyebrow">AUTO BRANDS</p>
			<h1><?php esc_html_e( 'سيارات مختارة.. لرحلة أفضل', 'car-dealer' ); ?></h1>
			<p><?php esc_html_e( 'اكتشف أحدث السيارات والعروض المتاحة لدى AUTO BRANDS بتجربة شراء عصرية وخدمة مبيعات أسرع.', 'car-dealer' ); ?></p>
			<div class="ab-actions">
				<a class="btn btn-primary" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>"><?php esc_html_e( 'استعرض السيارات', 'car-dealer' ); ?></a>
				<a class="btn btn-chrome" href="<?php echo esc_url( car_dealer_whatsapp_url( 'مرحباً AUTO BRANDS، أريد الاستفسار عن السيارات المتاحة.' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تواصل معنا عبر واتساب', 'car-dealer' ); ?></a>
			</div>
		</div>
		<div class="ab-hero-panel">
			<span><?php esc_html_e( 'Premium Automotive', 'car-dealer' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( wp_count_posts( 'car' )->publish ) ); ?></strong>
			<small><?php esc_html_e( 'سيارة في المخزون', 'car-dealer' ); ?></small>
		</div>
	</div>
</section>

<section class="ab-section">
	<div class="container">
		<div class="section-heading"><div><p class="eyebrow"><?php esc_html_e( 'مختارة بعناية', 'car-dealer' ); ?></p><h2><?php esc_html_e( 'السيارات المميزة', 'car-dealer' ); ?></h2></div><a href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>"><?php esc_html_e( 'عرض الكل', 'car-dealer' ); ?></a></div>
		<?php if ( $featured_cars->have_posts() ) : ?><div class="car-grid"><?php while ( $featured_cars->have_posts() ) { $featured_cars->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div><?php else : ?><div class="empty-state"><?php esc_html_e( 'حدد سيارات مميزة من لوحة الإدارة لتظهر هنا.', 'car-dealer' ); ?></div><?php endif; ?>
	</div>
</section>

<section class="ab-section ab-section-dark">
	<div class="container">
		<div class="section-heading"><div><p class="eyebrow"><?php esc_html_e( 'وصل حديثاً', 'car-dealer' ); ?></p><h2><?php esc_html_e( 'أحدث السيارات', 'car-dealer' ); ?></h2></div><a href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>"><?php esc_html_e( 'تصفح المخزون', 'car-dealer' ); ?></a></div>
		<?php if ( $latest_cars->have_posts() ) : ?><div class="car-grid"><?php while ( $latest_cars->have_posts() ) { $latest_cars->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div><?php endif; ?>
	</div>
</section>

<section class="ab-section">
	<div class="container">
		<div class="section-heading"><div><p class="eyebrow"><?php esc_html_e( 'عروض محدودة', 'car-dealer' ); ?></p><h2><?php esc_html_e( 'العروض الحالية', 'car-dealer' ); ?></h2></div><a href="<?php echo esc_url( get_post_type_archive_link( 'car_offer' ) ); ?>"><?php esc_html_e( 'كل العروض', 'car-dealer' ); ?></a></div>
		<?php if ( $offers->have_posts() ) : ?><div class="offer-grid"><?php while ( $offers->have_posts() ) { $offers->the_post(); car_dealer_offer_card( get_the_ID() ); } wp_reset_postdata(); ?></div><?php else : ?><div class="ab-offer-strip"><div><p class="eyebrow"><?php esc_html_e( 'عرض الأسبوع', 'car-dealer' ); ?></p><h3><?php esc_html_e( 'قسط يبدأ من XXXX ريال', 'car-dealer' ); ?></h3></div><a class="btn btn-primary" href="<?php echo esc_url( car_dealer_whatsapp_url( 'أريد معرفة عروض AUTO BRANDS الحالية.' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'احصل على العرض', 'car-dealer' ); ?></a></div><?php endif; ?>
	</div>
</section>

<section class="ab-section ab-why">
	<div class="container">
		<div class="section-heading"><div><p class="eyebrow"><?php esc_html_e( 'ثقة وخبرة', 'car-dealer' ); ?></p><h2><?php esc_html_e( 'لماذا AUTO BRANDS؟', 'car-dealer' ); ?></h2></div></div>
		<div class="ab-feature-grid">
			<article><span>01</span><h3><?php esc_html_e( 'اختيار متنوع', 'car-dealer' ); ?></h3><p><?php esc_html_e( 'سيارات مختارة تناسب الاستخدام اليومي والفخامة والعمل.', 'car-dealer' ); ?></p></article>
			<article><span>02</span><h3><?php esc_html_e( 'حلول تمويل', 'car-dealer' ); ?></h3><p><?php esc_html_e( 'نساعدك في اختيار مسار التمويل الأنسب مع خطوات واضحة.', 'car-dealer' ); ?></p></article>
			<article><span>03</span><h3><?php esc_html_e( 'فريق مبيعات سريع', 'car-dealer' ); ?></h3><p><?php esc_html_e( 'كل طلب يصل مباشرة إلى لوحة الإدارة ليتابعه موظف المبيعات.', 'car-dealer' ); ?></p></article>
		</div>
	</div>
</section>

<section class="ab-section">
	<div class="container">
		<div class="section-heading"><div><p class="eyebrow"><?php esc_html_e( 'اختيارات العملاء', 'car-dealer' ); ?></p><h2><?php esc_html_e( 'السيارات الأكثر طلباً', 'car-dealer' ); ?></h2></div></div>
		<?php if ( $demand_cars->have_posts() ) : ?><div class="car-grid"><?php while ( $demand_cars->have_posts() ) { $demand_cars->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div><?php else : ?><?php echo do_shortcode( '[car_dealer_cars count="3"]' ); ?><?php endif; ?>
	</div>
</section>

<section class="ab-finance-band">
	<div class="container ab-finance-grid">
		<div><p class="eyebrow"><?php esc_html_e( 'تمويل', 'car-dealer' ); ?></p><h2><?php esc_html_e( 'احسب فرصتك التمويلية', 'car-dealer' ); ?></h2><p><?php esc_html_e( 'تمويل بنكي وتمويل شركات مع مسار تقديم واضح ومتابعة من فريق AUTO BRANDS.', 'car-dealer' ); ?></p></div>
		<?php echo do_shortcode( '[car_dealer_loan_calculator]' ); ?>
	</div>
</section>

<?php echo do_shortcode( '[car_dealer_testimonials count="3"]' ); ?>

<section class="ab-whatsapp-cta">
	<div class="container">
		<h2><?php esc_html_e( 'جاهز لاختيار سيارتك القادمة؟', 'car-dealer' ); ?></h2>
		<p><?php esc_html_e( 'ابدأ المحادثة الآن، أو اختر سيارة وأرسل طلب سعر أو تمويل مباشرة.', 'car-dealer' ); ?></p>
		<a class="btn btn-primary" href="<?php echo esc_url( car_dealer_whatsapp_url( 'أريد المساعدة في اختيار سيارة من AUTO BRANDS.' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?></a>
	</div>
</section>
<?php get_footer(); ?>
