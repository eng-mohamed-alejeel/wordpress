<?php get_header(); ?>
<?php $catalog_language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar'; $catalog_direction = function_exists( 'car_dealer_catalog_direction' ) ? car_dealer_catalog_direction() : 'rtl'; ?>
<section class="archive-hero ab-inventory-hero" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<div class="container">
		<?php if ( function_exists( 'car_dealer_catalog_language_switch' ) ) { car_dealer_catalog_language_switch(); } ?>
		<p class="eyebrow">AUTO BRANDS INVENTORY</p>
		<h1><?php esc_html_e( 'استعرض سيارات AUTO BRANDS', 'car-dealer' ); ?></h1>
		<p><?php esc_html_e( 'فلترة دقيقة حسب الماركة، الموديل، السنة، السعر، نوع السيارة، الوقود وناقل الحركة.', 'car-dealer' ); ?></p>
	</div>
</section>
<div class="container archive-content" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<?php if ( ! function_exists( 'adc_public_vehicle_view' ) ) : ?>
		<p class="empty-state" role="status"><?php esc_html_e( 'كتالوج السيارات غير متاح حاليًا. يرجى المحاولة لاحقًا.', 'car-dealer' ); ?></p>
	<?php else : get_template_part( 'templates/components/filter-bar' ); ?>
	<?php if ( have_posts() ) : ?>
		<p class="catalog-result-count" role="status" aria-live="polite"><?php printf( esc_html( _n( 'سيارة واحدة مطابقة', '%s سيارة مطابقة', (int) $GLOBALS['wp_query']->found_posts, 'car-dealer' ) ), esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) ) ); ?></p>
		<div class="car-grid"><?php while ( have_posts() ) { the_post(); get_template_part( 'templates/components/car-card' ); } ?></div>
		<div class="pagination"><?php the_posts_pagination( array( 'prev_text' => __( 'السابق', 'car-dealer' ), 'next_text' => __( 'التالي', 'car-dealer' ) ) ); ?></div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'لا توجد سيارات مطابقة للبحث.', 'car-dealer' ); ?></p>
	<?php endif; endif; ?>
</div>
<?php get_footer(); ?>
