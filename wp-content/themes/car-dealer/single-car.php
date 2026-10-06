<?php get_header(); ?>
<?php
while ( have_posts() ) :
	the_post();
	$id           = get_the_ID();
	$view         = car_dealer_vehicle_view( $id, is_preview() );
	if ( ! $view ) { echo '<div class="container empty-state">' . esc_html__( 'بيانات السيارة غير متاحة حاليًا.', 'car-dealer' ) . '</div>'; continue; }
	$vehicle      = $view['vehicle'];
	$price        = $view['price'];
	$monthly      = $view['monthly'];
	$year         = $view['year'];
	$model        = $view['model'];
	$color        = $view['color'];
	$kilometers   = $view['kilometers'];
	$transmission = $view['transmission'];
	$fuel         = $view['fuel'];
	$condition    = $view['condition'];
	$status       = $view['status'];
	$features     = $view['features'];
	$brand_name   = $view['brand_name'];
	$catalog_language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar';
	$catalog_direction = function_exists( 'car_dealer_catalog_direction' ) ? car_dealer_catalog_direction() : 'rtl';
	$wa_message   = 'en' === $catalog_language ? sprintf( 'Hello AUTO BRANDS, I would like to ask about %s', get_the_title() ) : sprintf( 'مرحباً AUTO BRANDS، أريد الاستفسار عن %s', get_the_title() );
	?>
	<section class="ab-car-hero" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
		<div class="container ab-car-hero-grid">
			<div class="car-single-media">
				<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large', array( 'loading' => 'eager', 'alt' => get_post_meta( get_post_thumbnail_id(), '_wp_attachment_image_alt', true ) ?: get_the_title() ) ); } else { ?><div class="car-image-placeholder"><?php echo car_dealer_vehicle_placeholder(); ?><span class="car-image-placeholder-label"><?php echo esc_html( car_dealer_text( 'صورة السيارة قيد الإضافة', 'Vehicle photo pending' ) ); ?></span></div><?php } ?>
			</div>
			<div class="car-single-content">
				<p class="eyebrow"><?php echo $brand_name ? esc_html( $brand_name ) : esc_html__( 'AUTO BRANDS', 'car-dealer' ); ?></p>
				<h1><?php the_title(); ?></h1>
				<?php if ( $price ) : ?><p class="car-single-price"><?php echo esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $price ) : car_dealer_format_price( $price ) ); ?></p><?php endif; ?>
				<?php if ( $monthly ) : ?><p class="car-monthly car-single-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $monthly ) : car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
				<div class="car-single-actions">
					<a class="btn btn-primary" href="#request-price"><?php esc_html_e( 'اطلب السعر', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#book-drive"><?php esc_html_e( 'احجز تجربة قيادة', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#finance-request"><?php esc_html_e( 'اطلب تمويل', 'car-dealer' ); ?></a>
					<?php $wa_link = car_dealer_whatsapp_url( $wa_message ); if ( '#' !== $wa_link ) : ?><a class="btn btn-chrome" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?></a><?php endif; ?>
				</div>
				<?php if ( function_exists( 'car_dealer_comparison_button' ) ) { car_dealer_comparison_button( $id ); } ?>
			</div>
		</div>
	</section>

	<article class="car-single container" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
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
					__( 'الفئة', 'car-dealer' ) => $vehicle['trim_name'] ?? '',
					__( 'سنة الصنع', 'car-dealer' ) => $year,
					__( 'الحالة', 'car-dealer' ) => $condition ? ( 'new' === $condition ? __( 'جديدة', 'car-dealer' ) : __( 'مستعملة', 'car-dealer' ) ) : '',
					__( 'حالة المخزون', 'car-dealer' ) => function_exists( 'car_dealer_inventory_status_label' ) ? car_dealer_inventory_status_label( $status ) : $status,
					__( 'اللون', 'car-dealer' ) => $color,
					__( 'اللون الداخلي', 'car-dealer' ) => $vehicle['interior_color'] ?? '',
					__( 'نوع الهيكل', 'car-dealer' ) => $vehicle && function_exists( 'car_dealer_catalog_value_label' ) ? car_dealer_catalog_value_label( (string) ( $vehicle['body_type'] ?? '' ) ) : ( $vehicle['body_type'] ?? '' ),
					__( 'الوقود', 'car-dealer' ) => function_exists( 'car_dealer_catalog_value_label' ) ? car_dealer_catalog_value_label( (string) $fuel ) : $fuel,
					__( 'ناقل الحركة', 'car-dealer' ) => $transmission ? ( 'automatic' === $transmission ? __( 'أوتوماتيكي', 'car-dealer' ) : __( 'يدوي', 'car-dealer' ) ) : '',
					__( 'المحرك', 'car-dealer' ) => $vehicle['engine_size'] ?? '',
					__( 'نظام الدفع', 'car-dealer' ) => strtoupper( (string) ( $vehicle['drivetrain'] ?? '' ) ),
					__( 'القوة', 'car-dealer' ) => ! empty( $vehicle['horsepower'] ) ? number_format_i18n( $vehicle['horsepower'] ) . ' HP' : '',
					__( 'الأبواب', 'car-dealer' ) => $vehicle['doors'] ?? '',
					__( 'المقاعد', 'car-dealer' ) => $vehicle['seats'] ?? '',
					__( 'الممشى', 'car-dealer' ) => null !== $kilometers && '' !== $kilometers && false !== $kilometers ? ( function_exists( 'car_dealer_catalog_distance' ) ? car_dealer_catalog_distance( $kilometers ) : number_format_i18n( $kilometers ) . ' كم' ) : '',
					__( 'الفرع', 'car-dealer' ) => $vehicle['branch_name'] ?? '',
					__( 'رقم المخزون', 'car-dealer' ) => $vehicle['stock_number'] ?? '',
				) as $label => $value ) : if ( '' === (string) $value ) { continue; } ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div><?php endforeach; ?>
			</dl>
		</aside>
	</article>

	<section class="container cd-single-tools ab-lead-tools" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
		<div id="request-price"><?php if ( function_exists( 'car_dealer_lead_form' ) ) { car_dealer_lead_form( $id, 'price_request', __( 'اطلب السعر', 'car-dealer' ), __( 'إرسال طلب السعر', 'car-dealer' ) ); } ?></div>
		<div id="book-drive"><?php if ( function_exists( 'car_dealer_booking_form' ) ) { car_dealer_booking_form( $id ); } ?></div>
		<div id="finance-request"><?php if ( function_exists( 'car_dealer_lead_form' ) ) { car_dealer_lead_form( $id, 'finance_request', __( 'اطلب تمويل', 'car-dealer' ), __( 'إرسال طلب التمويل', 'car-dealer' ) ); } ?></div>
		<?php echo do_shortcode( '[car_dealer_loan_calculator price="' . absint( $price ) . '"]' ); ?>
	</section>
<?php endwhile; ?>
<?php get_footer(); ?>
