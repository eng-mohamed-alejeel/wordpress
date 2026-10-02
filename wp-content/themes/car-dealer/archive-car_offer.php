<?php get_header(); ?>
<section class="archive-hero ab-offers-hero">
	<div class="container">
		<p class="eyebrow">AUTO BRANDS</p>
		<h1><?php esc_html_e( 'عروض AUTO BRANDS', 'car-dealer' ); ?></h1>
		<p><?php esc_html_e( 'عروض مختارة، أقساط مرنة، وفرص محدودة على سيارات تناسب رحلتك القادمة.', 'car-dealer' ); ?></p>
	</div>
</section>
<main class="container archive-content">
	<?php if ( ! function_exists( 'adc_public_offer_view' ) ) : ?>
		<p class="empty-state" role="status"><?php esc_html_e( 'العروض غير متاحة حاليًا. يرجى المحاولة لاحقًا.', 'car-dealer' ); ?></p>
	<?php else : ?>
	<?php
	$had_posts = have_posts();
	ob_start();
	while ( have_posts() ) {
		the_post();
		car_dealer_offer_card( get_the_ID() );
	}
	$offer_cards = trim( ob_get_clean() );
	if ( '' !== $offer_cards ) : ?>
		<div class="offer-grid"><?php echo $offer_cards; ?></div>
	<?php else : ?>
		<div class="empty-state"><?php esc_html_e( 'لا توجد عروض منشورة حالياً.', 'car-dealer' ); ?></div>
	<?php endif; ?>
	<?php if ( $had_posts ) : ?><div class="pagination"><?php the_posts_pagination(); ?></div><?php endif; ?>
	<?php endif; ?>
</main>
<?php get_footer(); ?>
