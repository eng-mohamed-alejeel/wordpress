<?php get_header(); ?>
<section class="archive-hero ab-offers-hero">
	<div class="container">
		<p class="eyebrow">AUTO BRANDS</p>
		<h1><?php esc_html_e( 'عروض AUTO BRANDS', 'car-dealer' ); ?></h1>
		<p><?php esc_html_e( 'عروض مختارة، أقساط مرنة، وفرص محدودة على سيارات تناسب رحلتك القادمة.', 'car-dealer' ); ?></p>
	</div>
</section>
<main class="container archive-content">
	<?php if ( have_posts() ) : ?>
		<div class="offer-grid">
			<?php while ( have_posts() ) : the_post(); car_dealer_offer_card( get_the_ID() ); endwhile; ?>
		</div>
		<div class="pagination"><?php the_posts_pagination(); ?></div>
	<?php else : ?>
		<div class="empty-state"><?php esc_html_e( 'لا توجد عروض منشورة حالياً.', 'car-dealer' ); ?></div>
	<?php endif; ?>
</main>
<?php get_footer(); ?>
