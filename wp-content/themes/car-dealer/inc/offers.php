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
if ( ! function_exists( 'adc_core_owns_content_registry' ) || ! adc_core_owns_content_registry( 'car_offer' ) ) {
	add_action( 'init', 'car_dealer_register_offers' );
}

function car_dealer_offer_available_cars( $selected_id = 0 ) {
	$args = array(
		'post_type'      => 'car',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'meta_query'     => array( car_dealer_inventory_status_query( 'available' ) ),
	);

	$cars = get_posts( $args );
	if ( $selected_id && ! in_array( $selected_id, wp_list_pluck( $cars, 'ID' ), true ) && 'car' === get_post_type( $selected_id ) ) {
		$selected = get_post( $selected_id );
		if ( $selected ) {
			array_unshift( $cars, $selected );
		}
	}

	return $cars;
}

function car_dealer_offer_car_is_available( $car_id ) {
	if ( ! $car_id || 'car' !== get_post_type( $car_id ) || 'publish' !== get_post_status( $car_id ) ) {
		return false;
	}

	$status = get_post_meta( $car_id, '_car_inventory_status', true );
	return '' === $status || 'available' === $status;
}

function car_dealer_offer_admin_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'car_offer' !== $screen->post_type ) {
		return;
	}

	$key = 'car_dealer_offer_notice_' . get_current_user_id();
	$message = get_transient( $key );
	if ( ! $message ) {
		return;
	}

	delete_transient( $key );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
if ( ! function_exists( 'adc_core_owns_offer_post_editor' ) || ! adc_core_owns_offer_post_editor() ) {
	add_action( 'admin_notices', 'car_dealer_offer_admin_notice' );
}

function car_dealer_offer_meta_box() {
	add_meta_box( 'car-offer-details', __( 'تفاصيل العرض', 'car-dealer' ), 'car_dealer_offer_meta_box_html', 'car_offer', 'normal', 'high' );
}
if ( ! function_exists( 'adc_core_owns_offer_post_editor' ) || ! adc_core_owns_offer_post_editor() ) {
	add_action( 'add_meta_boxes', 'car_dealer_offer_meta_box' );
}

function car_dealer_offer_meta_box_html( $post ) {
	wp_nonce_field( 'car_dealer_save_offer', 'car_dealer_offer_nonce' );
	$selected_car = absint( get_post_meta( $post->ID, '_offer_car_id', true ) );
	$cars         = car_dealer_offer_available_cars( $selected_car );
	$selected_car_post = $selected_car ? get_post( $selected_car ) : null;
	$fields            = array(
		'_offer_old_price'       => array(
			'label'       => __( 'السعر قبل العرض', 'car-dealer' ),
			'type'        => 'number',
			'placeholder' => __( 'مثال: 125000', 'car-dealer' ),
			'help'        => __( 'السعر الأصلي للسيارة قبل الخصم.', 'car-dealer' ),
			'icon'        => 'tag',
		),
		'_offer_new_price'       => array(
			'label'       => __( 'السعر بعد العرض', 'car-dealer' ),
			'type'        => 'number',
			'placeholder' => __( 'مثال: 115000', 'car-dealer' ),
			'help'        => __( 'السعر الذي سيظهر كقيمة العرض الأساسية.', 'car-dealer' ),
			'icon'        => 'money-alt',
		),
		'_offer_monthly_payment' => array(
			'label'       => __( 'القسط الشهري يبدأ من', 'car-dealer' ),
			'type'        => 'number',
			'placeholder' => __( 'مثال: 1850', 'car-dealer' ),
			'help'        => __( 'اتركه فارغًا إذا لم يكن العرض تمويليًا.', 'car-dealer' ),
			'icon'        => 'calendar-alt',
		),
		'_offer_expires'         => array(
			'label'       => __( 'تاريخ انتهاء العرض', 'car-dealer' ),
			'type'        => 'date',
			'placeholder' => '',
			'help'        => __( 'يساعد فريق المبيعات على معرفة صلاحية العرض.', 'car-dealer' ),
			'icon'        => 'clock',
		),
	);
	?>
	<div class="cd-offer-builder cd-add-car-page" dir="rtl">
		<div class="cd-form-grid cd-offer-form-grid">
			<section class="cd-form-panel cd-offer-card">
				<div class="cd-panel-heading">
					<span class="dashicons dashicons-car"></span>
					<div>
						<h2><?php esc_html_e( 'السيارة المرتبطة', 'car-dealer' ); ?></h2>
						<p><?php esc_html_e( 'العرض يجب أن يكون مرتبطًا بسيارة منشورة وحالتها متوفر في المخزون.', 'car-dealer' ); ?></p>
					</div>
				</div>

				<label class="cd-offer-field cd-offer-field--wide" for="_offer_car_id">
					<span><?php esc_html_e( 'اختر السيارة', 'car-dealer' ); ?></span>
					<select id="_offer_car_id" name="_offer_car_id" required>
						<option value=""><?php esc_html_e( 'اختر سيارة متوفرة من المخزون', 'car-dealer' ); ?></option>
						<?php foreach ( $cars as $car ) : ?>
							<?php $is_available = car_dealer_offer_car_is_available( $car->ID ); ?>
							<option value="<?php echo absint( $car->ID ); ?>" <?php selected( $selected_car, $car->ID ); ?> <?php disabled( ! $is_available ); ?>>
								<?php
								echo esc_html( get_the_title( $car ) );
								if ( ! $is_available ) {
									echo esc_html__( ' - غير متاحة حاليًا', 'car-dealer' );
								}
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php if ( ! $cars ) : ?>
					<div class="cd-offer-empty">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e( 'لا توجد سيارات متاحة للعروض', 'car-dealer' ); ?></strong>
							<p><?php esc_html_e( 'أضف سيارة منشورة أو غيّر حالة سيارة في المخزون إلى متوفر قبل إنشاء عرض جديد.', 'car-dealer' ); ?></p>
						</div>
					</div>
				<?php elseif ( $selected_car_post ) : ?>
					<?php
					$stock_number = get_post_meta( $selected_car, '_car_stock_number', true );
					$car_price    = get_post_meta( $selected_car, '_car_price', true );
					$status       = get_post_meta( $selected_car, '_car_inventory_status', true ) ?: 'available';
					?>
					<div class="cd-offer-selected-car">
						<div class="cd-offer-selected-car__image">
							<?php
							if ( has_post_thumbnail( $selected_car ) ) {
								echo get_the_post_thumbnail( $selected_car, 'thumbnail' );
							} else {
								echo '<span class="dashicons dashicons-car" aria-hidden="true"></span>';
							}
							?>
						</div>
						<div>
							<strong><?php echo esc_html( get_the_title( $selected_car_post ) ); ?></strong>
							<ul>
								<?php if ( $stock_number ) : ?>
									<li><?php printf( esc_html__( 'رقم المخزون: %s', 'car-dealer' ), esc_html( $stock_number ) ); ?></li>
								<?php endif; ?>
								<?php if ( $car_price ) : ?>
									<li><?php printf( esc_html__( 'سعر السيارة: %s', 'car-dealer' ), esc_html( car_dealer_format_price( $car_price ) ) ); ?></li>
								<?php endif; ?>
								<li><span class="cd-status cd-status-<?php echo esc_attr( sanitize_html_class( $status ) ); ?>"><?php echo esc_html( car_dealer_inventory_status_label( $status ) ); ?></span></li>
							</ul>
						</div>
					</div>
				<?php else : ?>
					<p class="cd-offer-help"><?php esc_html_e( 'بعد اختيار السيارة وحفظ العرض ستظهر هنا بطاقة مختصرة بمعلومات السيارة.', 'car-dealer' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="cd-form-panel cd-offer-card">
				<div class="cd-panel-heading">
					<span class="dashicons dashicons-money-alt"></span>
					<div>
						<h2><?php esc_html_e( 'تفاصيل السعر والمدة', 'car-dealer' ); ?></h2>
						<p><?php esc_html_e( 'استخدم أرقامًا واضحة ومتناسقة، واترك الحقول غير المطلوبة فارغة.', 'car-dealer' ); ?></p>
					</div>
				</div>

				<div class="cd-offer-fields">
					<?php foreach ( $fields as $key => $field ) : ?>
						<label class="cd-offer-field" for="<?php echo esc_attr( $key ); ?>">
							<span><span class="dashicons dashicons-<?php echo esc_attr( $field['icon'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $field['label'] ); ?></span>
							<input type="<?php echo esc_attr( $field['type'] ); ?>" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( get_post_meta( $post->ID, $key, true ) ); ?>" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" <?php echo 'number' === $field['type'] ? 'min="0"' : ''; ?>>
							<small><?php echo esc_html( $field['help'] ); ?></small>
						</label>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
	</div>
	<?php
}

function car_dealer_save_offer_meta( $post_id ) {
	if ( ! isset( $_POST['car_dealer_offer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['car_dealer_offer_nonce'] ) ), 'car_dealer_save_offer' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
	$car_id = absint( $_POST['_offer_car_id'] ?? 0 );
	if ( ! car_dealer_offer_car_is_available( $car_id ) ) {
		delete_post_meta( $post_id, '_offer_car_id' );
		set_transient( 'car_dealer_offer_notice_' . get_current_user_id(), __( 'لم يتم حفظ العرض كعرض صالح: يجب اختيار سيارة منشورة ومتوفرة في المخزون.', 'car-dealer' ), 60 );
		remove_action( 'save_post_car_offer', 'car_dealer_save_offer_meta' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		add_action( 'save_post_car_offer', 'car_dealer_save_offer_meta' );
		return;
	}

	update_post_meta( $post_id, '_offer_car_id', $car_id );
	update_post_meta( $car_id, '_car_special_offer', 1 );
	update_post_meta( $car_id, '_car_is_offer', '1' );

	foreach ( array( '_offer_old_price', '_offer_new_price', '_offer_monthly_payment' ) as $key ) { update_post_meta( $post_id, $key, absint( $_POST[ $key ] ?? 0 ) ); }
	update_post_meta( $post_id, '_offer_expires', sanitize_text_field( wp_unslash( $_POST['_offer_expires'] ?? '' ) ) );
}
if ( ! function_exists( 'adc_core_owns_offer_post_editor' ) || ! adc_core_owns_offer_post_editor() ) {
	add_action( 'save_post_car_offer', 'car_dealer_save_offer_meta' );
}

function car_dealer_offer_admin_columns( $columns ) {
	$updated = array();
	foreach ( $columns as $key => $label ) {
		$updated[ $key ] = $label;
		if ( 'title' === $key ) {
			$updated['offer_car'] = __( 'السيارة المرتبطة', 'car-dealer' );
		}
	}
	return $updated;
}
if ( ! function_exists( 'adc_core_owns_offer_post_editor' ) || ! adc_core_owns_offer_post_editor() ) {
	add_filter( 'manage_car_offer_posts_columns', 'car_dealer_offer_admin_columns' );
}

function car_dealer_offer_admin_column_content( $column, $post_id ) {
	if ( 'offer_car' !== $column ) {
		return;
	}

	$car_id = absint( get_post_meta( $post_id, '_offer_car_id', true ) );
	if ( ! $car_id || 'car' !== get_post_type( $car_id ) ) {
		echo '<span class="cd-status cd-status-cancelled">' . esc_html__( 'غير محددة', 'car-dealer' ) . '</span>';
		return;
	}

	$status = get_post_meta( $car_id, '_car_inventory_status', true ) ?: 'available';
	$title  = get_the_title( $car_id );
	if ( current_user_can( 'edit_post', $car_id ) ) {
		echo '<a href="' . esc_url( get_edit_post_link( $car_id ) ) . '">' . esc_html( $title ) . '</a>';
	} else {
		echo esc_html( $title );
	}
	echo '<br><span class="cd-status cd-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( car_dealer_inventory_status_label( $status ) ) . '</span>';
}
if ( ! function_exists( 'adc_core_owns_offer_post_editor' ) || ! adc_core_owns_offer_post_editor() ) {
	add_action( 'manage_car_offer_posts_custom_column', 'car_dealer_offer_admin_column_content', 10, 2 );
}

function car_dealer_offer_card( $offer_id ) {
	$monthly = get_post_meta( $offer_id, '_offer_monthly_payment', true );
	$new_price = get_post_meta( $offer_id, '_offer_new_price', true );
	$old_price = get_post_meta( $offer_id, '_offer_old_price', true );
	$car_id = absint( get_post_meta( $offer_id, '_offer_car_id', true ) );
	?>
	<article class="offer-card">
		<a class="offer-image" href="<?php echo esc_url( get_permalink( $offer_id ) ); ?>">
			<?php echo get_the_post_thumbnail( $offer_id, 'car-card' ) ?: ( $car_id ? get_the_post_thumbnail( $car_id, 'car-card' ) : '' ); ?>
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
