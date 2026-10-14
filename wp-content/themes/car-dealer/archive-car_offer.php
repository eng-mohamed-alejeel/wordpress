<?php
get_header();
$catalog_language  = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar';
$catalog_direction = function_exists( 'car_dealer_catalog_direction' ) ? car_dealer_catalog_direction() : 'rtl';
?>
<section class="archive-hero ab-offers-hero" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<div class="container">
		<p class="eyebrow"><?php echo esc_html( car_dealer_text( 'عروض أوتو براندز', 'AUTO BRANDS Offers' ) ); ?></p>
		<h1><?php echo esc_html( car_dealer_text( 'اكتشف عروض أوتو براندز', 'Explore AUTO BRANDS Offers' ) ); ?></h1>
		<p><?php echo esc_html( car_dealer_text( 'اكتشف العروض المتاحة وقارن الأسعار والأقساط لاختيار السيارة المناسبة لك.', 'Explore available offers and compare prices and payments to find the right vehicle.' ) ); ?></p>
	</div>
</section>
<div class="container archive-content" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<h2 class="screen-reader-text"><?php echo esc_html( car_dealer_text( 'العروض المتاحة', 'Available offers' ) ); ?></h2>
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
		<?php
		$offer_count = (int) $GLOBALS['wp_query']->found_posts;
		if ( 'en' === $catalog_language ) {
			$offer_count_label = 1 === $offer_count ? '1 available offer' : number_format_i18n( $offer_count ) . ' available offers';
		} elseif ( 1 === $offer_count ) {
			$offer_count_label = 'عرض واحد متاح';
		} elseif ( 2 === $offer_count ) {
			$offer_count_label = 'عرضان متاحان';
		} else {
			$last_two = $offer_count % 100;
			$offer_count_label = number_format_i18n( $offer_count ) . ( $last_two >= 3 && $last_two <= 10 ? ' عروض متاحة' : ' عرضًا متاحًا' );
		}
		?>
		<p class="catalog-result-count offer-result-count" role="status" aria-live="polite"><?php echo esc_html( $offer_count_label ); ?></p>
		<div class="offer-grid"><?php echo $offer_cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cards escape their own dynamic values. ?></div>
	<?php else : ?>
		<div class="empty-state"><?php esc_html_e( 'لا توجد عروض منشورة حالياً.', 'car-dealer' ); ?></div>
	<?php endif; ?>
	<?php if ( $had_posts ) : ?><div class="pagination"><?php the_posts_pagination( array( 'prev_text' => car_dealer_text( 'السابق', 'Previous' ), 'next_text' => car_dealer_text( 'التالي', 'Next' ) ) ); ?></div><?php endif; ?>
	<?php endif; ?>
</div>
<?php get_footer(); ?>
