<?php
/**
 * نظام مقارنة السيارات لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة زر إضافة إلى المقارنة لكل سيارة
 */
function car_dealer_add_comparison_button() {
	global $post;

	if ( $post->post_type !== 'car' ) {
		return;
	}

	$comparison_list = car_dealer_get_comparison_list();
	$is_in_comparison = in_array( $post->ID, $comparison_list );

	?>
	<div class="comparison-button">
		<?php if ( $is_in_comparison ) : ?>
			<button class="btn added" data-car-id="<?php echo esc_attr( $post->ID ); ?>">
				<i class="fas fa-check"></i> <?php _e( 'مضاف إلى المقارنة', 'car-dealer' ); ?>
			</button>
		<?php else : ?>
			<button class="btn" data-car-id="<?php echo esc_attr( $post->ID ); ?>">
				<i class="fas fa-plus"></i> <?php _e( 'إضافة إلى المقارنة', 'car-dealer' ); ?>
			</button>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'single_car_after_title', 'car_dealer_add_comparison_button' );

/**
 * الحصول على قائمة السيارات المضافة للمقارنة
 */
function car_dealer_get_comparison_list() {
	$comparison_list = array();

	if ( isset( $_COOKIE['car_comparison'] ) ) {
		$comparison_list = json_decode( stripslashes( $_COOKIE['car_comparison'] ), true );
	}

	return $comparison_list;
}

/**
 * إضافة سيارة إلى قائمة المقارنة
 */
function car_dealer_add_to_comparison() {
	$car_id = isset( $_POST['car_id'] ) ? intval( $_POST['car_id'] ) : 0;

	if ( $car_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'معرّف السيارة غير صالح', 'car-dealer' ) ) );
	}

	$comparison_list = car_dealer_get_comparison_list();

	// التأكد من ألا تزيد عدد السيارات في المقارنة عن 4
	if ( count( $comparison_list ) >= 4 ) {
		wp_send_json_error( array( 'message' => __( 'لا يمكن مقارنة أكثر من 4 سيارات', 'car-dealer' ) ) );
	}

	// إضافة السيارة إلى القائمة إذا لم تكن موجودة بالفعل
	if ( ! in_array( $car_id, $comparison_list ) ) {
		$comparison_list[] = $car_id;
		setcookie( 'car_comparison', json_encode( $comparison_list ), time() + ( 86400 * 30 ), '/' ); // 30 يوم
	}

	wp_send_json_success( array(
		'message' => __( 'تمت إضافة السيارة إلى المقارنة بنجاح', 'car-dealer' ),
		'count'  => count( $comparison_list )
	) );
}
add_action( 'wp_ajax_car_dealer_add_to_comparison', 'car_dealer_add_to_comparison' );
add_action( 'wp_ajax_nopriv_car_dealer_add_to_comparison', 'car_dealer_add_to_comparison' );

/**
- إزالة سيارة من قائمة المقارنة
 */
function car_dealer_remove_from_comparison() {
	$car_id = isset( $_POST['car_id'] ) ? intval( $_POST['car_id'] ) : 0;

	if ( $car_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'معرّف السيارة غير صالح', 'car-dealer' ) ) );
	}

	$comparison_list = car_dealer_get_comparison_list();

	// إزالة السيارة من القائمة إذا كانت موجودة
	if ( ( $key = array_search( $car_id, $comparison_list ) ) !== false ) {
		unset( $comparison_list[ $key ] );
		setcookie( 'car_comparison', json_encode( $comparison_list ), time() + ( 86400 * 30 ), '/' ); // 30 يوم
	}

	wp_send_json_success( array(
		'message' => __( 'تمت إزالة السيارة من المقارنة بنجاح', 'car-dealer' ),
		'count'  => count( $comparison_list )
	) );
}
add_action( 'wp_ajax_car_dealer_remove_from_comparison', 'car_dealer_remove_from_comparison' );
add_action( 'wp_ajax_nopriv_car_dealer_remove_from_comparison', 'car_dealer_remove_from_comparison' );

/**
 * عرض مقارنة السيارات
 */
function car_dealer_display_comparison() {
	$comparison_list = car_dealer_get_comparison_list();

	if ( empty( $comparison_list ) ) {
		echo '<div class="comparison-empty">' . __( 'لم تقم بإضافة أي سيارات إلى قائمة المقارنة بعد', 'car-dealer' ) . '</div>';
		return;
	}

	$args = array(
		'post_type'      => 'car',
		'post__in'      => $comparison_list,
		'posts_per_page' => -1,
	);

	$cars_query = new WP_Query( $args );

	if ( $cars_query->have_posts() ) {
		echo '<div class="comparison-table">';
		echo '<table>';
		echo '<thead>';
		echo '<tr>';
		echo '<th>&nbsp;</th>';

		while ( $cars_query->have_posts() ) {
			$cars_query->the_post();
			echo '<th>' . get_the_title() . '</th>';
		}

		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		// إعادة تعيين الاستعلام
		wp_reset_postdata();
		$cars_query = new WP_Query( $args );

		while ( $cars_query->have_posts() ) {
			$cars_query->the_post();
			$car_id = get_the_ID();

			echo '<tr>';

			// اسم السيارة
			echo '<td class="feature-name">' . __( 'الاسم', 'car-dealer' ) . '</td>';
			echo '<td>' . get_the_title() . '</td>';

			// الصورة
			echo '<td class="feature-name">' . __( 'الصورة', 'car-dealer' ) . '</td>';
			echo '<td>';
			if ( has_post_thumbnail() ) {
				the_post_thumbnail( 'medium' );
			} else {
				echo '<img src="https://via.placeholder.com/200x150?text=صورة+السيارة" alt="' . esc_attr( get_the_title() ) . '">';
			}
			echo '</td>';

			// السعر
			echo '<td class="feature-name">' . __( 'السعر', 'car-dealer' ) . '</td>';
			echo '<td>' . get_post_meta( $car_id, '_car_price', true ) . ' ريال</td>';

			// سنة التصنيع
			echo '<td class="feature-name">' . __( 'سنة التصنيع', 'car-dealer' ) . '</td>';
			echo '<td>' . get_post_meta( $car_id, '_car_year', true ) . '</td>';

			// المسافة المقطوعة
			echo '<td class="feature-name">' . __( 'المسافة المقطوعة', 'car-dealer' ) . '</td>';
			echo '<td>' . get_post_meta( $car_id, '_car_kilometers', true ) . ' كم</td>';

			// ناقل الحركة
			echo '<td class="feature-name">' . __( 'ناقل الحركة', 'car-dealer' ) . '</td>';
			echo '<td>' . ( get_post_meta( $car_id, '_car_transmission', true ) === 'manual' ? __( 'يدوي', 'car-dealer' ) : __( 'أوتوماتيكي', 'car-dealer' ) ) . '</td>';

			// نوع الوقود
			echo '<td class="feature-name">' . __( 'نوع الوقود', 'car-dealer' ) . '</td>';
			echo '<td>' . get_post_meta( $car_id, '_car_fuel_type', true ) . '</td>';

			// زر الإزالة
			echo '<td class="remove-comparison">';
			echo '<button class="btn btn-remove" data-car-id="' . esc_attr( $car_id ) . '">';
			echo '<i class="fas fa-trash"></i>';
			echo '</button>';
			echo '</td>';

			echo '</tr>';
		}

		wp_reset_postdata();

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	} else {
		echo '<div class="comparison-error">' . __( 'لم يتم العثور على السيارات المحددة', 'car-dealer' ) . '</div>';
	}
}

/**
 * إنشاء widget لعرض مقارنة السيارات
 */
function car_dealer_comparison_widget() {
	register_widget( 'Car_Dealer_Comparison_Widget' );
}
add_action( 'widgets_init', 'car_dealer_comparison_widget' );

/**
 * widget مقارنة السيارات
 */
class Car_Dealer_Comparison_Widget extends WP_Widget {

	/**
	 * إعداد الـ widget
	 */
	public function __construct() {
		parent::__construct(
			'car_dealer_comparison',
			__( 'مقارنة السيارات', 'car-dealer' ),
			array( 'description' => __( 'عرض قائمة مقارنة السيارات', 'car-dealer' ) )
		);
	}

	/**
	 * عرض الـ widget في الواجهة الأمامية
	 */
	public function widget( $args, $instance ) {
		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		echo '<div class="comparison-widget">';
		car_dealer_display_comparison();
		echo '</div>';

		echo $args['after_widget'];
	}

	/**
	 * إعداد النموذج في لوحة التحكم
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'مقارنة السيارات', 'car-dealer' );
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'العنوان:', 'car-dealer' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}

	/**
	 * حفظ الإعدادات
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';

		return $instance;
	}
}

/**
 * إضافة رابط إلى صفحة المقارنة في القائمة الرئيسية
 */
function car_dealer_add_comparison_link_to_menu( string $items, object $args ) {
	if ( $args->theme_location === 'primary' ) {
		$comparison_list = car_dealer_get_comparison_list();

		if ( ! empty( $comparison_list ) ) {
			$items .= '<li class="menu-item comparison-item"><a href="#"><i class="fas fa-balance-scale"></i> <span class="comparison-count">' . count( $comparison_list ) . '</span> ' . __( 'المقارنة', 'car-dealer' ) . '</a></li>';
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_items', 'car_dealer_add_comparison_link_to_menu', 10, 2 );
