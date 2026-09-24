<?php get_header(); ?>
<section class="archive-hero ab-inventory-hero">
	<div class="container">
		<p class="eyebrow">AUTO BRANDS INVENTORY</p>
		<h1><?php esc_html_e( 'استعرض سيارات AUTO BRANDS', 'car-dealer' ); ?></h1>
		<p><?php esc_html_e( 'فلترة دقيقة حسب الماركة، الموديل، السنة، السعر، نوع السيارة، الوقود وناقل الحركة.', 'car-dealer' ); ?></p>
	</div>
</section>
<div class="container archive-content">
	<?php get_template_part( 'templates/components/filter-bar' ); ?>
	<?php if ( have_posts() ) : ?>
		<div class="car-grid"><?php while ( have_posts() ) { the_post(); get_template_part( 'templates/components/car-card' ); } ?></div>
		<div class="pagination"><?php the_posts_pagination( array( 'prev_text' => __( 'السابق', 'car-dealer' ), 'next_text' => __( 'التالي', 'car-dealer' ) ) ); ?></div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'لا توجد سيارات مطابقة للبحث.', 'car-dealer' ); ?></p>
	<?php endif; ?>
</div>
<?php get_footer(); ?>
