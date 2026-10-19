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
	$wa_message   = sprintf( car_dealer_text( 'مرحباً %1$s، أريد الاستفسار عن %2$s', 'Hello %1$s, I would like to ask about %2$s' ), car_dealer_brand_name(), get_the_title() );
	?>
	<section class="ab-car-hero" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
		<div class="container ab-car-hero-grid">
			<div class="car-single-media">
				<?php if ( ! class_exists('\AutoDealership\Inventory\VehicleColors') || ! \AutoDealership\Inventory\VehicleColors::render((int)$id) ) : ?>
				<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large', array( 'loading' => 'eager', 'alt' => get_post_meta( get_post_thumbnail_id(), '_wp_attachment_image_alt', true ) ?: get_the_title() ) ); } else { ?><div class="car-image-placeholder"><?php echo car_dealer_vehicle_placeholder(); ?><span class="car-image-placeholder-label"><?php echo esc_html( car_dealer_text( 'صورة افتراضية للسيارة', 'Default vehicle image' ) ); ?></span></div><?php } ?>
				<?php $gallery_ids = get_post_meta( $id, '_car_gallery_ids', true ); if ( is_array( $gallery_ids ) && $gallery_ids ) : ?>
				<div class="adc-public-gallery" aria-label="<?php echo esc_attr( car_dealer_text( 'معرض صور السيارة', 'Vehicle photo gallery' ) ); ?>">
					<?php foreach ( array_slice( $gallery_ids, 0, 20 ) as $gallery_id ) : if ( ! wp_attachment_is_image( (int)$gallery_id ) ) { continue; } ?>
					<a href="<?php echo esc_url( wp_get_attachment_image_url( (int)$gallery_id, 'full' ) ); ?>"><?php echo wp_get_attachment_image( (int)$gallery_id, 'medium', false, array( 'loading'=>'lazy', 'alt'=>get_post_meta( (int)$gallery_id, '_wp_attachment_image_alt', true ) ?: get_the_title() ) ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="car-single-content">
				<div class="car-single-brand-row">
				<p class="eyebrow"><?php echo $brand_name ? esc_html( car_dealer_catalog_value_label( (string) $brand_name ) ) : esc_html( car_dealer_brand_name() ); ?></p>
					<div class="car-single-utilities">
				<?php if ( current_user_can( 'edit_post', $id ) ) : ?>
					<?php $car_edit_url = get_edit_post_link( $id, 'raw' ); ?>
					<?php if ( $car_edit_url ) : ?>
						<a class="car-single-edit" aria-label="<?php echo esc_attr( car_dealer_text( 'تعديل تفاصيل السيارة', 'Edit vehicle details' ) ); ?>" title="<?php echo esc_attr( car_dealer_text( 'تعديل تفاصيل السيارة', 'Edit vehicle details' ) ); ?>" href="<?php echo esc_url( $car_edit_url ); ?>">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m16 3 5 5L8 21H3v-5L16 3Z"/><path d="m14 5 5 5"/></svg>
						</a>
					<?php endif; ?>
				<?php endif; ?>
					<?php if ( class_exists( '\AutoDealership\Portal\CustomerJourney' ) ) { \AutoDealership\Portal\CustomerJourney::favorite_button( (int) $id, true ); } ?>
					</div>
				</div>
				<h1><?php the_title(); ?></h1>
				<?php if ( $price ) : ?><p class="car-single-price"><?php if ( ! empty( $view['price_from'] ) ) { echo esc_html( car_dealer_text( 'يبدأ من ', 'From ' ) ); } echo esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $price ) : car_dealer_format_price( $price ) ); ?></p><?php endif; ?>
				<?php if ( $price && ! empty( $view['price_label'] ) ) : ?><p class="public-price-inclusions"><?php echo esc_html( $view['price_label'] ); ?></p><?php endif; ?>
		<?php if ( $monthly ) : ?><p class="car-monthly car-single-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $monthly ) : car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
				<?php if ( '' !== trim( (string) get_post_field( 'post_excerpt', $id, 'raw' ) ) ) : ?>
					<?php $car_summary = trim( wp_strip_all_tags( get_the_excerpt( $id ) ) ); ?>
					<?php if ( '' !== $car_summary ) : ?><p class="car-single-summary"><?php echo esc_html( $car_summary ); ?></p><?php endif; ?>
				<?php endif; ?>
				<div class="car-single-actions">
					<a class="btn btn-primary" href="#request-price"><?php esc_html_e( 'اطلب السعر', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#book-drive"><?php esc_html_e( 'احجز تجربة قيادة', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#finance-request"><?php esc_html_e( 'اطلب تمويل', 'car-dealer' ); ?></a>
					<a class="btn btn-outline" href="#finance-calculator"><?php echo esc_html(car_dealer_text('حاسبة التمويل','Finance calculator')); ?></a>
					<?php $wa_link = car_dealer_whatsapp_url( $wa_message ); if ( '#' !== $wa_link ) : ?><a class="btn btn-whatsapp" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener"><?php echo car_dealer_whatsapp_icon(); ?><?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?></a><?php endif; ?>
					<?php if ( function_exists( 'car_dealer_comparison_button' ) ) { car_dealer_comparison_button( $id ); } ?>
				</div>
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
			<?php if ( isset($view['catalog_model']) && class_exists('\AutoDealership\Inventory\VehicleColors') ) { \AutoDealership\Inventory\VehicleColors::render_variant_picker(); } ?>
			<dl class="car-specification">
				<?php foreach ( isset( $view['catalog_model'] ) ? array(
					car_dealer_text( 'الماركة', 'Brand' ) => car_dealer_catalog_value_label( (string) $brand_name ),
					car_dealer_text( 'الطراز', 'Model' ) => car_dealer_catalog_value_label( (string) $model ),
					car_dealer_text( 'الفئة', 'Trim' ) => implode( ' / ', array_map( 'car_dealer_catalog_value_label', array_values( array_unique( array_filter( wp_list_pluck( $view['catalog_model']['options'], 'trim_name' ) ) ) ) ) ),
					car_dealer_text( 'الموديل', 'Model year' ) => $year,
					car_dealer_text( 'سعر البيع', 'Sale price' ) => $price ? ( ! empty( $view['price_from'] ) ? car_dealer_text( 'يبدأ من ', 'From ' ) : '' ) . car_dealer_catalog_format_price( $price ) : '',
				) : array(
					__( 'الموديل', 'car-dealer' ) => car_dealer_catalog_value_label( (string) $model ),
					__( 'الفئة', 'car-dealer' ) => $vehicle['trim_name'] ?? '',
					__( 'سنة الصنع', 'car-dealer' ) => $year,
					__( 'الحالة', 'car-dealer' ) => car_dealer_catalog_value_label( (string) $condition ),
					__( 'حالة التوفر', 'car-dealer' ) => function_exists( 'car_dealer_inventory_status_label' ) ? car_dealer_inventory_status_label( $status ) : $status,
					__( 'اللون', 'car-dealer' ) => car_dealer_catalog_value_label( (string) $color ),
					__( 'اللون الداخلي', 'car-dealer' ) => car_dealer_catalog_value_label( (string) ( $vehicle['interior_color'] ?? '' ) ),
					__( 'نوع الهيكل', 'car-dealer' ) => $vehicle && function_exists( 'car_dealer_catalog_value_label' ) ? car_dealer_catalog_value_label( (string) ( $vehicle['body_type'] ?? '' ) ) : ( $vehicle['body_type'] ?? '' ),
					__( 'الوقود', 'car-dealer' ) => function_exists( 'car_dealer_catalog_value_label' ) ? car_dealer_catalog_value_label( (string) $fuel ) : $fuel,
					__( 'ناقل الحركة', 'car-dealer' ) => car_dealer_catalog_value_label( (string) $transmission ),
					__( 'المحرك', 'car-dealer' ) => $vehicle['engine_size'] ?? '',
					__( 'نظام الدفع', 'car-dealer' ) => car_dealer_catalog_value_label( (string) ( $vehicle['drivetrain'] ?? '' ) ),
					__( 'القوة', 'car-dealer' ) => ! empty( $vehicle['horsepower'] ) ? number_format_i18n( $vehicle['horsepower'] ) . ' ' . __( 'HP', 'car-dealer' ) : '',
					__( 'الأبواب', 'car-dealer' ) => $vehicle['doors'] ?? '',
					__( 'المقاعد', 'car-dealer' ) => $vehicle['seats'] ?? '',
					__( 'الممشى', 'car-dealer' ) => null !== $kilometers && '' !== $kilometers && false !== $kilometers ? ( function_exists( 'car_dealer_catalog_distance' ) ? car_dealer_catalog_distance( $kilometers ) : number_format_i18n( $kilometers ) . ' كم' ) : '',
					__( 'الفرع', 'car-dealer' ) => $vehicle['branch_name'] ?? '',
					__( 'مرجع السيارة', 'car-dealer' ) => $vehicle['stock_number'] ?? '',
				) as $label => $value ) : if ( '' === (string) $value ) { continue; } ?><div><dt><?php echo esc_html( $label ); ?></dt><dd<?php $spec_key=array(car_dealer_text('الفئة','Trim')=>'trim',car_dealer_text('الموديل','Model year')=>'year',car_dealer_text('سعر البيع','Sale price')=>'price'); if(isset($view['catalog_model'],$spec_key[$label])) { echo ' data-vehicle-spec="'.esc_attr($spec_key[$label]).'"'; } ?>><?php echo esc_html($value); ?></dd></div><?php endforeach; ?>
			</dl>
		</aside>
	</article>

	<section class="container cd-single-tools ab-lead-tools" data-car-dialogs data-car-name="<?php echo esc_attr(get_the_title()); ?>" data-close-label="<?php echo esc_attr(car_dealer_text('إغلاق النافذة','Close dialog')); ?>" dir="<?php echo esc_attr($catalog_direction); ?>" lang="<?php echo esc_attr($catalog_language); ?>">
		<noscript><p class="cd-car-noscript-note"><?php echo esc_html(car_dealer_text('لإرسال الطلبات أو استخدام الحاسبة، فعّل JavaScript في المتصفح، أو تواصل معنا مباشرة.','To send requests or use the calculator, enable JavaScript in your browser, or contact us directly.')); ?> <?php if(isset($wa_link) && '#'!==$wa_link): ?><a href="<?php echo esc_url($wa_link); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(car_dealer_text('تواصل واتساب','Contact on WhatsApp')); ?></a><?php endif; ?></p></noscript>
		<?php foreach(array('request-price'=>car_dealer_text('اطلب السعر','Request price'),'book-drive'=>car_dealer_text('احجز تجربة قيادة','Request a test drive'),'finance-request'=>car_dealer_text('اطلب تمويل','Request financing'),'finance-calculator'=>car_dealer_text('حاسبة التمويل','Finance calculator')) as $panel_id=>$panel_title): ?>
		<details id="<?php echo esc_attr($panel_id); ?>" data-car-panel data-panel-title="<?php echo esc_attr($panel_title); ?>">
			<summary><?php echo esc_html($panel_title); ?></summary>
			<div data-car-panel-content>
				<?php if('request-price'===$panel_id && function_exists('car_dealer_lead_form')) { car_dealer_lead_form($id,'price_request',__('اطلب السعر','car-dealer'),__('إرسال طلب السعر','car-dealer')); }
				elseif('book-drive'===$panel_id && function_exists('car_dealer_booking_form')) { car_dealer_booking_form($id); }
				elseif('finance-request'===$panel_id && function_exists('car_dealer_lead_form')) { car_dealer_lead_form($id,'finance_request',__('اطلب تمويل','car-dealer'),__('إرسال طلب التمويل','car-dealer')); }
				elseif('finance-calculator'===$panel_id) { echo do_shortcode('[car_dealer_loan_calculator price="'.esc_attr(max(0,(float)$price)).'"]'); } ?>
			</div>
		</details>
		<?php endforeach; ?>
	</section>
<?php endwhile; ?>
<?php get_footer(); ?>
