<?php get_header(); ?>
<?php
while ( have_posts() ) :
	the_post();
	$id           = get_the_ID();
	$price        = get_post_meta( $id, '_car_price', true );
	$monthly      = get_post_meta( $id, '_car_monthly_payment', true );
	$year         = get_post_meta( $id, '_car_year', true );
	$model        = get_post_meta( $id, '_car_model', true );
	$color        = get_post_meta( $id, '_car_color', true );
	$kilometers   = get_post_meta( $id, '_car_kilometers', true );
	$transmission = get_post_meta( $id, '_car_transmission', true );
	$fuel         = get_post_meta( $id, '_car_fuel_type', true );
	$condition    = get_post_meta( $id, '_car_condition', true );
	$status       = get_post_meta( $id, '_car_inventory_status', true ) ?: 'available';
	$features_meta = get_post_meta( $id, '_car_features', true );
	$features     = is_array( $features_meta ) ? array_filter( array_map( 'trim', $features_meta ) ) : array_filter( array_map( 'trim', explode( "\n", (string) $features_meta ) ) );
	$brands       = get_the_terms( $id, 'car_brand' );
	$wa_message   = sprintf( 'مرحباً AUTO BRANDS، أريد الاستفسار عن %s', get_the_title() );
	?>
	<section class="ab-car-hero">
		<div class="container ab-car-hero-grid">
			<div class="car-single-media">
				<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large', array( 'loading' => 'eager' ) ); } else { ?><div class="car-image-placeholder">🚘</div><?php } ?>
			</div>
			<div class="car-single-content">
				<p class="eyebrow"><?php echo ! is_wp_error( $brands ) && ! empty( $brands ) ? esc_html( $brands[0]->name ) : esc_html__( 'AUTO BRANDS', 'car-dealer' ); ?></p>
				<h1><?php the_title(); ?></h1>
				<?php if ( $price ) : ?><p class="car-single-price"><?php echo esc_html( car_dealer_format_price( $price ) ); ?></p><?php endif; ?>
				<?php if ( $monthly ) : ?><p class="car-monthly car-single-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
				<div class="car-single-actions">
					<a class="btn btn-primary" href="#request-price"><?php esc_html_e( 'اطلب السعر', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#book-drive"><?php esc_html_e( 'احجز تجربة قيادة', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#finance-request"><?php esc_html_e( 'اطلب تمويل', 'car-dealer' ); ?></a>
					<a class="btn btn-chrome" href="<?php echo esc_url( car_dealer_whatsapp_url( $wa_message ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?></a>
				</div>
				<?php if ( function_exists( 'car_dealer_comparison_button' ) ) { car_dealer_comparison_button( $id ); } ?>
			</div>
		</div>
	</section>

	<article class="car-single container">
		<div>
			<div class="car-description"><h2><?php esc_html_e( 'الوصف', 'car-dealer' ); ?></h2><?php the_content(); ?></div>
			<?php if ( $features ) : ?>
				<div class="car-features"><h2><?php esc_html_e( 'المزايا', 'car-dealer' ); ?></h2><ul><?php foreach ( $features as $feature ) : ?><li><?php echo esc_html( $feature ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
			<?php if ( function_exists( 'car_dealer_social_share_buttons' ) ) { car_dealer_social_share_buttons(); } ?>
		</div>
		<aside class="ab-spec-panel">
			<h2><?php esc_html_e( 'المواصفات', 'car-dealer' ); ?></h2>
			<dl class="car-specification">
				<?php foreach ( array(
					__( 'الموديل', 'car-dealer' ) => $model,
					__( 'سنة الصنع', 'car-dealer' ) => $year,
					__( 'الحالة', 'car-dealer' ) => $condition ? ( 'new' === $condition ? __( 'جديدة', 'car-dealer' ) : __( 'مستعملة', 'car-dealer' ) ) : '',
					__( 'حالة المخزون', 'car-dealer' ) => function_exists( 'car_dealer_inventory_status_label' ) ? car_dealer_inventory_status_label( $status ) : $status,
					__( 'اللون', 'car-dealer' ) => $color,
					__( 'الوقود', 'car-dealer' ) => $fuel,
					__( 'ناقل الحركة', 'car-dealer' ) => $transmission ? ( 'automatic' === $transmission ? __( 'أوتوماتيكي', 'car-dealer' ) : __( 'يدوي', 'car-dealer' ) ) : '',
					__( 'الممشى', 'car-dealer' ) => $kilometers ? number_format_i18n( $kilometers ) . ' كم' : '',
				) as $label => $value ) : if ( '' === (string) $value ) { continue; } ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div><?php endforeach; ?>
			</dl>
		</aside>
	</article>

	<section class="container cd-single-tools ab-lead-tools">
		<div id="request-price"><?php if ( function_exists( 'car_dealer_lead_form' ) ) { car_dealer_lead_form( $id, 'price_request', __( 'اطلب السعر', 'car-dealer' ), __( 'إرسال طلب السعر', 'car-dealer' ) ); } ?></div>
		<div id="book-drive"><?php if ( function_exists( 'car_dealer_booking_form' ) ) { car_dealer_booking_form( $id ); } ?></div>
		<div id="finance-request"><?php if ( function_exists( 'car_dealer_lead_form' ) ) { car_dealer_lead_form( $id, 'finance_request', __( 'اطلب تمويل', 'car-dealer' ), __( 'إرسال طلب التمويل', 'car-dealer' ) ); } ?></div>
		<?php echo do_shortcode( '[car_dealer_loan_calculator price="' . absint( $price ) . '"]' ); ?>
	</section>
<?php endwhile; ?>