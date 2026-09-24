<?php
/** AUTO BRANDS offers module. */
defined( 'ABSPATH' ) || exit;

function car_dealer_register_offers() {
	register_post_type( 'car_offer', array(
		'labels' => array(
			'name' => __( 'العروض', 'car-dealer' ),
			'singular_name' => __( 'عرض', 'car-dealer' ),
			'menu_name' => __( 'العروض', 'car-dealer' ),
			'name_admin_bar' => __( 'عرض', 'car-dealer' ),
			'add_new' => __( 'إضافة عرض', 'car-dealer' ),
			'add_new_item' => __( 'إضافة عرض جديد', 'car-dealer' ),
			'new_item' => __( 'عرض جديد', 'car-dealer' ),
			'edit_item' => __( 'تعديل العرض', 'car-dealer' ),
			'view_item' => __( 'عرض التفاصيل', 'car-dealer' ),
			'all_items' => __( 'كل العروض', 'car-dealer' ),
			'search_items' => __( 'البحث في العروض', 'car-dealer' ),
			'not_found' => __( 'لا توجد عروض', 'car-dealer' ),
			'not_found_in_trash' => __( 'لا توجد عروض في سلة المهملات', 'car-dealer' ),
			'featured_image' => __( 'صورة العرض', 'car-dealer' ),
			'set_featured_image' => __( 'تعيين صورة العرض', 'car-dealer' ),
			'remove_featured_image' => __( 'إزالة صورة العرض', 'car-dealer' ),
			'use_featured_image' => __( 'استخدام كصورة العرض', 'car-dealer' ),
			'item_published' => __( 'تم نشر العرض', 'car-dealer' ),
			'item_updated' => __( 'تم تحديث العرض', 'car-dealer' ),
		),
		'public' => true,
		'has_archive' => true,
		'rewrite' => array( 'slug' => 'offers' ),
		'show_in_rest' => true,
		'show_in_menu' => 'car-dealer-dashboard',
		'menu_icon' => 'dashicons-megaphone',
		'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
	) );
}
add_action( 'init', 'car_dealer_register_offers' );

function car_dealer_offer_meta_box() {
	add_meta_box( 'car-offer-details', __( 'تفاصيل العرض', 'car-dealer' ), 'car_dealer_offer_meta_box_html', 'car_offer', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'car_dealer_offer_meta_box' );

function car_dealer_offer_meta_box_html( $post ) {
	wp_nonce_field( 'car_dealer_save_offer', 'car_dealer_offer_nonce' );
	$fields = array(
		'_offer_car_id' => array( 'رقم السيارة المرتبطة', 'number' ),
		'_offer_old_price' => array( 'السعر قبل العرض', 'number' ),
		'_offer_new_price' => array( 'السعر بعد العرض', 'number' ),
		'_offer_monthly_payment' => array( 'قسط يبدأ من', 'number' ),
		'_offer_expires' => array( 'ينتهي في', 'date' ),
	);
	echo '<table class="form-table"><tbody>';
	foreach ( $fields as $key => $field ) {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="%1$s" name="%1$s" value="%4$s"></td></tr>', esc_attr( $key ), esc_html( $field[0] ), esc_attr( $field[1] ), esc_attr( get_post_meta( $post->ID, $key, true ) ) );
	}
	echo '</tbody></table>';
}

function car_dealer_save_offer_meta( $post_id ) {
	if ( ! isset( $_POST['car_dealer_offer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['car_dealer_offer_nonce'] ) ), 'car_dealer_save_offer' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
	foreach ( array( '_offer_car_id', '_offer_old_price', '_offer_new_price', '_offer_monthly_payment' ) as $key ) { update_post_meta( $post_id, $key, absint( $_POST[ $key ] ?? 0 ) ); }
	update_post_meta( $post_id, '_offer_expires', sanitize_text_field( wp_unslash( $_POST['_offer_expires'] ?? '' ) ) );
}
add_action( 'save_post_car_offer', 'car_dealer_save_offer_meta' );

function car_dealer_offer_card( $offer_id ) {
	$monthly = get_post_meta( $offer_id, '_offer_monthly_payment', true );
	$new_price = get_post_meta( $offer_id, '_offer_new_price', true );
	$old_price = get_post_meta( $offer_id, '_offer_old_price', true );
	?>
	<article class="offer-card">
		<a class="offer-image" href="<?php echo esc_url( get_permalink( $offer_id ) ); ?>">
			<?php echo get_the_post_thumbnail( $offer_id, 'car-card' ); ?>
		</a>
		<div class="offer-content">
			<p class="eyebrow"><?php esc_html_e( 'عرض AUTO BRANDS', 'car-dealer' ); ?></p>
			<h3><a href="<?php echo esc_url( get_permalink( $offer_id ) ); ?>"><?php echo esc_html( get_the_title( $offer_id ) ); ?></a></h3>
			<?php if ( $old_price ) : ?><p class="offer-old"><?php echo esc_html( car_dealer_format_price( $old_price ) ); ?></p><?php endif; ?>
			<?php if ( $new_price ) : ?><p class="offer-price"><?php echo esc_html( car_dealer_format_price( $new_price ) ); ?></p><?php endif; ?>
			<?php if ( $monthly ) : ?><p><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
			<a class="btn btn-primary" href="<?php echo esc_url( get_permalink( $offer_id ) ); ?>"><?php esc_html_e( 'احصل على العرض', 'car-dealer' ); ?></a>
		</div>
	</article>
	<?php
}
