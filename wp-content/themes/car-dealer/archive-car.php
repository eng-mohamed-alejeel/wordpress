<?php get_header(); ?>
<?php $catalog_language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar'; $catalog_direction = function_exists( 'car_dealer_catalog_direction' ) ? car_dealer_catalog_direction() : 'rtl'; ?>
<section class="archive-hero ab-inventory-hero" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<div class="container">
		<p class="eyebrow"><?php echo esc_html(car_dealer_text('سيارات أوتو براندز','AUTO BRANDS Vehicles')); ?></p>
		<h1><?php echo esc_html( car_dealer_text( 'استعرض سيارات أوتو براندز', 'Browse AUTO BRANDS Vehicles' ) ); ?></h1>
		<p><?php echo esc_html(car_dealer_text('اختر سيارتك حسب الماركة والطراز والفئة واللون والسنة والسعر.','Find your vehicle by brand, model, trim, color, year and price.')); ?></p>
	</div>
</section>
<div class="container archive-content" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<?php if ( ! function_exists( 'adc_public_vehicle_view' ) ) : ?>
		<p class="empty-state" role="status"><?php esc_html_e( 'كتالوج السيارات غير متاح حاليًا. يرجى المحاولة لاحقًا.', 'car-dealer' ); ?></p>
	<?php else : get_template_part( 'templates/components/filter-bar' ); ?>
	<?php if ( have_posts() ) : ?>
		<?php
		$result_count = (int) $GLOBALS['wp_query']->found_posts;
		if ( 'en' === $catalog_language ) {
			$result_label = $result_count === 1 ? '1 available option' : number_format_i18n( $result_count ) . ' available options';
		} elseif ( $result_count === 1 ) {
			$result_label = 'خيار واحد متاح';
		} elseif ( $result_count === 2 ) {
			$result_label = 'خياران متاحان';
		} else {
			$last_two = $result_count % 100;
			$result_label = number_format_i18n( $result_count ) . ( $last_two >= 3 && $last_two <= 10 ? ' خيارات متاحة' : ' خيارًا متاحًا' );
		}
		?>
		<p class="catalog-result-count" role="status" aria-live="polite"><?php echo esc_html( $result_label ); ?></p>
		<div class="car-grid"><?php while ( have_posts() ) { the_post(); get_template_part( 'templates/components/car-card' ); } ?></div>
		<div class="pagination"><?php the_posts_pagination( array( 'prev_text' => __( 'السابق', 'car-dealer' ), 'next_text' => __( 'التالي', 'car-dealer' ), 'screen_reader_text' => car_dealer_text( 'تصفح صفحات السيارات', 'Browse vehicle pages' ), 'aria_label' => car_dealer_text( 'صفحات السيارات', 'Vehicle pages' ) ) ); ?></div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'لا توجد سيارات مطابقة للبحث.', 'car-dealer' ); ?></p>
	<?php endif; endif; ?>
</div>
<?php get_footer(); ?>
