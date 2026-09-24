<?php get_header(); ?>
<?php while ( have_posts() ) : the_post(); $monthly = get_post_meta( get_the_ID(), '_offer_monthly_payment', true ); $new = get_post_meta( get_the_ID(), '_offer_new_price', true ); $old = get_post_meta( get_the_ID(), '_offer_old_price', true ); $car_id = absint( get_post_meta( get_the_ID(), '_offer_car_id', true ) ); ?>
<section class="ab-car-hero ab-offer-single">
	<div class="container ab-car-hero-grid">
		<div class="car-single-media"><?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large' ); } ?></div>
		<div class="car-single-content">
			<p class="eyebrow"><?php esc_html_e( 'عرض AUTO BRANDS', 'car-dealer' ); ?></p>
			<h1><?php the_title(); ?></h1>
			<?php if ( $old ) : ?><p class="offer-old"><?php echo esc_html( car_dealer_format_price( $old ) ); ?></p><?php endif; ?>
			<?php if ( $new ) : ?><p class="car-single-price"><?php echo esc_html( car_dealer_format_price( $new ) ); ?></p><?php endif; ?>
			<?php if ( $monthly ) : ?><p class="car-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
			<div class="car-single-actions">
				<a class="btn btn-primary" href="#offer-lead"><?php esc_html_e( 'احصل على العرض', 'car-dealer' ); ?></a>
				<a class="btn btn-chrome" href="<?php echo esc_url( car_dealer_whatsapp_url( 'أريد الاستفادة من عرض: ' . get_the_title() ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?></a>
			</div>
		</div>
	</div>
</section>
<section class="container car-single">
	<div class="car-description"><?php the_content(); ?></div>
	<div id="offer-lead"><?php if ( function_exists( 'car_dealer_lead_form' ) ) { car_dealer_lead_form( $car_id, 'offer_request', __( 'احصل على العرض', 'car-dealer' ), __( 'إرسال الطلب', 'car-dealer' ) ); } ?></div>
</section>
<?php endwhile; ?>
<?php get_footer(); ?>
