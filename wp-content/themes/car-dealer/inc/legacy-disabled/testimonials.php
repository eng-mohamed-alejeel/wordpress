<?php
/**
 * قسم العملاء الراضين لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة نوع المقال المخصص لشهادات العملاء
 */
function car_dealer_register_testimonial_cpt() {
	register_post_type( 'testimonial',
		array(
			'labels'      => array(
				'name'          => __( 'شهادات العملاء', 'car-dealer' ),
				'singular_name' => __( 'شهادة عميل', 'car-dealer' ),
				'add_new_item'  => __( 'إضافة شهادة جديدة', 'car-dealer' ),
				'edit_item'     => __( 'تعديل الشهادة', 'car-dealer' ),
				'new_item'      => __( 'شهادة جديدة', 'car-dealer' ),
				'view_item'     => __( 'عرض الشهادة', 'car-dealer' ),
				'search_items'  => __( 'بحث عن شهادات', 'car-dealer' ),
				'not_found'     => __( 'لم يتم العثور على شهادات', 'car-dealer' ),
				'not_found_in_trash' => __( 'لم يتم العثور على شهادات في المهملات', 'car-dealer' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-testimonial',
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
		)
	);

	// إضافة تصنيفات لشهادات العملاء
	register_taxonomy(
		'testimonial_category',
		'testimonial',
		array(
			'labels'            => array(
				'name'          => __( 'فئات الشهادات', 'car-dealer' ),
				'singular_name' => __( 'فئة شهادات', 'car-dealer' ),
				'search_items'  => __( 'بحث عن فئات', 'car-dealer' ),
				'all_items'     => __( 'كل الفئات', 'car-dealer' ),
				'parent_item'   => __( 'فئة رئيسية', 'car-dealer' ),
				'parent_item_colon' => __( 'فئة رئيسية:', 'car-dealer' ),
				'edit_item'     => __( 'تعديل الفئة', 'car-dealer' ),
				'update_item'   => __( 'تحديث الفئة', 'car-dealer' ),
				'add_new_item'  => __( 'إضافة فئة جديدة', 'car-dealer' ),
				'new_item_name' => __( 'اسم الفئة الجديدة', 'car-dealer' ),
				'menu_name'     => __( 'فئات الشهادات', 'car-dealer' ),
			),
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'testimonial-category' ),
		)
	);
}
add_action( 'init', 'car_dealer_register_testimonial_cpt' );

/**
 * إضافة الحقول المخصصة لشهادات العملاء
 */
function car_dealer_testimonial_custom_fields() {
	add_meta_box(
		'testimonial_details',
		'تفاصيل الشهادة',
		'car_dealer_testimonial_details_callback',
		'testimonial',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'car_dealer_testimonial_custom_fields' );

/**
 * دالة رد الاتصال لعرض حقول الشهادات المخصصة
 */
function car_dealer_testimonial_details_callback( WP_Post $post ) {
	// الحصول على القيم الحالية للحقول المخصصة
	$customer_name = get_post_meta( $post->ID, '_customer_name', true );
	$customer_title = get_post_meta( $post->ID, '_customer_title', true );
	$company = get_post_meta( $post->ID, '_company', true );
	$rating = get_post_meta( $post->ID, '_rating', true );

	// إضافة حقم nonce للتحقق من الأمان
	wp_nonce_field( 'car_dealer_save_testimonial_details', 'car_dealer_nonce' );

	// عرض حقول الإدخال
	echo '<table class="form-table">';
	echo '<tbody>';
	echo '<tr>';
	echo '<th><label for="_customer_name">اسم العميل</label></th>';
	echo '<td><input type="text" id="_customer_name" name="_customer_name" value="' . esc_attr( $customer_name ) . '" class="regular-text" required></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_customer_title">المنصب الوظيفي</label></th>';
	echo '<td><input type="text" id="_customer_title" name="_customer_title" value="' . esc_attr( $customer_title ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_company">الشركة</label></th>';
	echo '<td><input type="text" id="_company" name="_company" value="' . esc_attr( $company ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_rating">التقييم</label></th>';
	echo '<td>';
	echo '<select id="_rating" name="_rating">';
	echo '<option value="5" ' . selected( $rating, '5', false ) . '>' . __( '5 نجوم', 'car-dealer' ) . '</option>';
	echo '<option value="4" ' . selected( $rating, '4', false ) . '>' . __( '4 نجوم', 'car-dealer' ) . '</option>';
	echo '<option value="3" ' . selected( $rating, '3', false ) . '>' . __( '3 نجوم', 'car-dealer' ) . '</option>';
	echo '<option value="2" ' . selected( $rating, '2', false ) . '>' . __( '2 نجوم', 'car-dealer' ) . '</option>';
	echo '<option value="1" ' . selected( $rating, '1', false ) . '>' . __( '1 نجمة', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '</tbody>';
	echo '</table>';
}

/**
 * حفظ حقول الشهادات المخصصة
 */
function car_dealer_save_testimonial_details( int $post_id ) {
	// التحقق من nonce
	if ( ! isset( $_POST['car_dealer_nonce'] ) || ! wp_verify_nonce( $_POST['car_dealer_nonce'], 'car_dealer_save_testimonial_details' ) ) {
		return;
	}

	// التحقق من المستخدم لديه الصلاحيات
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// حفظ القيم
	if ( isset( $_POST['_customer_name'] ) ) {
		update_post_meta( $post_id, '_customer_name', sanitize_text_field( $_POST['_customer_name'] ) );
	}

	if ( isset( $_POST['_customer_title'] ) ) {
		update_post_meta( $post_id, '_customer_title', sanitize_text_field( $_POST['_customer_title'] ) );
	}

	if ( isset( $_POST['_company'] ) ) {
		update_post_meta( $post_id, '_company', sanitize_text_field( $_POST['_company'] ) );
	}

	if ( isset( $_POST['_rating'] ) ) {
		update_post_meta( $post_id, '_rating', intval( $_POST['_rating'] ) );
	}
}
add_action( 'save_post', 'car_dealer_save_testimonial_details' );

/**
 * إنشاء widget لعرض شهادات العملاء
 */
function car_dealer_testimonials_widget() {
	register_widget( 'Car_Dealer_Testimonials_Widget' );
}
add_action( 'widgets_init', 'car_dealer_testimonials_widget' );

/**
 * widget شهادات العملاء
 */
class Car_Dealer_Testimonials_Widget extends WP_Widget {

	/**
	 * إعداد الـ widget
	 */
	public function __construct() {
		parent::__construct(
			'car_dealer_testimonials',
			__( 'شهادات العملاء', 'car-dealer' ),
			array( 'description' => __( 'عرض شهادات العملاء', 'car-dealer' ) )
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

		$number = ! empty( $instance['number'] ) ? absint( $instance['number'] ) : 3;
		$category = ! empty( $instance['category'] ) ? absint( $instance['category'] ) : 0;

		$query_args = array(
			'post_type'      => 'testimonial',
			'posts_per_page' => $number,
			'orderby'        => 'rand',
		);

		if ( $category > 0 ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'testimonial_category',
					'field'    => 'term_id',
					'terms'    => $category,
				)
			);
		}

		$testimonials_query = new WP_Query( $query_args );

		if ( $testimonials_query->have_posts() ) {
			echo '<div class="testimonials-widget">';

			while ( $testimonials_query->have_posts() ) {
				$testimonials_query->the_post();

				$customer_name = get_post_meta( get_the_ID(), '_customer_name', true );
				$customer_title = get_post_meta( get_the_ID(), '_customer_title', true );
				$company = get_post_meta( get_the_ID(), '_company', true );
				$rating = get_post_meta( get_the_ID(), '_rating', true );

				echo '<div class="testimonial-item">';

				if ( has_post_thumbnail() ) {
					echo '<div class="testimonial-avatar">';
					the_post_thumbnail( 'thumbnail' );
					echo '</div>';
				}

				echo '<div class="testimonial-content">';

				// عرض التقييم
				if ( ! empty( $rating ) ) {
					echo '<div class="testimonial-rating">';
					for ( $i = 1; $i <= 5; $i++ ) {
						if ( $i <= $rating ) {
							echo '<i class="fas fa-star"></i>';
						} else {
							echo '<i class="far fa-star"></i>';
						}
					}
					echo '</div>';
				}

				echo '<div class="testimonial-text">';
				the_content();
				echo '</div>';

				echo '<div class="testimonial-author">';
				echo '<div class="author-name">' . esc_html( $customer_name ) . '</div>';
				if ( ! empty( $customer_title ) ) {
					echo '<div class="author-title">' . esc_html( $customer_title ) . '</div>';
				}
				if ( ! empty( $company ) ) {
					echo '<div class="author-company">' . esc_html( $company ) . '</div>';
				}
				echo '</div>';

				echo '</div>';
				echo '</div>';
			}

			wp_reset_postdata();

			echo '</div>';
		} else {
			echo '<p>' . __( 'لا توجد شهادات للعرض', 'car-dealer' ) . '</p>';
		}

		echo $args['after_widget'];
	}

	/**
	 * إعداد النموذج في لوحة التحكم
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'شهادات العملاء', 'car-dealer' );
		$number = ! empty( $instance['number'] ) ? absint( $instance['number'] ) : 3;
		$category = ! empty( $instance['category'] ) ? absint( $instance['category'] ) : 0;

		$categories = get_terms( array(
			'taxonomy'   => 'testimonial_category',
			'hide_empty' => false,
		) );
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php __( 'العنوان:', 'car-dealer' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php __( 'عدد الشهادات:', 'car-dealer' ); ?></label>
			<input class="tiny-text" id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="number" step="1" min="1" value="<?php echo esc_attr( $number ); ?>" size="3">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'category' ); ?>"><?php __( 'الفئة:', 'car-dealer' ); ?></label>
			<select id="<?php echo $this->get_field_id( 'category' ); ?>" name="<?php echo $this->get_field_name( 'category' ); ?>">
				<option value="0"><?php __( 'كل الفئات', 'car-dealer' ); ?></option>
				<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $category, $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * حفظ الإعدادات
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		$instance['number'] = ( ! empty( $new_instance['number'] ) ) ? absint( $new_instance['number'] ) : 3;
		$instance['category'] = ( ! empty( $new_instance['category'] ) ) ? absint( $new_instance['category'] ) : 0;

		return $instance;
	}
}
