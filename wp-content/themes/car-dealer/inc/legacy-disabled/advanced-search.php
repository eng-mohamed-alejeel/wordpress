<?php
/**
 * نظام البحث المتقدم للسيارات لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة نموذج البحث المتقدم في الشريط الجانبي
 */
function car_dealer_advanced_search_widget() {
	register_widget( 'Car_Dealer_Advanced_Search_Widget' );
}
add_action( 'widgets_init', 'car_dealer_advanced_search_widget' );

/**
 * widget البحث المتقدم
 */
class Car_Dealer_Advanced_Search_Widget extends WP_Widget {

	/**
	 * إعداد الـ widget
	 */
	public function __construct() {
		parent::__construct(
			'car_dealer_advanced_search',
			__( 'البحث المتقدم للسيارات', 'car-dealer' ),
			array( 'description' => __( 'نموذج بحث متقدم للسيارات', 'car-dealer' ) )
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

		// الحصول على قيم الفلاتر الحالية من URL
		$current_make = isset( $_GET['make'] ) ? sanitize_text_field( $_GET['make'] ) : '';
		$current_model = isset( $_GET['model'] ) ? sanitize_text_field( $_GET['model'] ) : '';
		$current_year_min = isset( $_GET['year_min'] ) ? intval( $_GET['year_min'] ) : '';
		$current_year_max = isset( $_GET['year_max'] ) ? intval( $_GET['year_max'] ) : '';
		$current_price_min = isset( $_GET['price_min'] ) ? intval( $_GET['price_min'] ) : '';
		$current_price_max = isset( $_GET['price_max'] ) ? intval( $_GET['price_max'] ) : '';
		$current_fuel_type = isset( $_GET['fuel_type'] ) ? sanitize_text_field( $_GET['fuel_type'] ) : '';
		$current_transmission = isset( $_GET['transmission'] ) ? sanitize_text_field( $_GET['transmission'] ) : '';

		?>
		<form action="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>" method="get" class="advanced-search-form">
			<div class="search-field">
				<label for="make"><?php _e( 'الماركة', 'car-dealer' ); ?></label>
				<?php
				$makes = get_terms( array(
					'taxonomy'   => 'car_brand',
					'hide_empty' => false,
				) );

				if ( ! empty( $makes ) ) {
					echo '<select name="make" id="make">';
					echo '<option value="">' . __( 'كل الماركات', 'car-dealer' ) . '</option>';
					foreach ( $makes as $make ) {
						echo '<option value="' . esc_attr( $make->slug ) . '" ' . selected( $current_make, $make->slug, false ) . '>' . esc_html( $make->name ) . '</option>';
					}
					echo '</select>';
				}
				?>
			</div>

			<div class="search-field">
				<label for="model"><?php _e( 'المodel', 'car-dealer' ); ?></label>
				<input type="text" id="model" name="model" value="<?php echo esc_attr( $current_model ); ?>" placeholder="<?php esc_attr_e( 'اكتب موديل السيارة', 'car-dealer' ); ?>">
			</div>

			<div class="search-field">
				<label for="year"><?php _e( 'سنة التصنيع', 'car-dealer' ); ?></label>
				<div class="year-range">
					<input type="number" id="year_min" name="year_min" value="<?php echo esc_attr( $current_year_min ); ?>" placeholder="<?php esc_attr_e( 'من', 'car-dealer' ); ?>" min="1900" max="<?php echo date( 'Y' ); ?>">
					<span>-</span>
					<input type="number" id="year_max" name="year_max" value="<?php echo esc_attr( $current_year_max ); ?>" placeholder="<?php esc_attr_e( 'إلى', 'car-dealer' ); ?>" min="1900" max="<?php echo date( 'Y' ); ?>">
				</div>
			</div>

			<div class="search-field">
				<label for="price"><?php _e( 'السعر (ريال)', 'car-dealer' ); ?></label>
				<div class="price-range">
					<input type="number" id="price_min" name="price_min" value="<?php echo esc_attr( $current_price_min ); ?>" placeholder="<?php esc_attr_e( 'من', 'car-dealer' ); ?>" min="0">
					<span>-</span>
					<input type="number" id="price_max" name="price_max" value="<?php echo esc_attr( $current_price_max ); ?>" placeholder="<?php esc_attr_e( 'إلى', 'car-dealer' ); ?>" min="0">
				</div>
			</div>

			<div class="search-field">
				<label for="fuel_type"><?php _e( 'نوع الوقود', 'car-dealer' ); ?></label>
				<?php
				$fuel_types = array(
					'gasoline' => __( 'بنزين', 'car-dealer' ),
					'diesel'   => __( 'ديزل', 'car-dealer' ),
					'hybrid'   => __( 'هجين', 'car-dealer' ),
					'electric' => __( 'كهربائي', 'car-dealer' ),
				);

				echo '<select name="fuel_type" id="fuel_type">';
				echo '<option value="">' . __( 'كل الأنواع', 'car-dealer' ) . '</option>';
				foreach ( $fuel_types as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_fuel_type, $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				?>
			</div>

			<div class="search-field">
				<label for="transmission"><?php _e( 'ناقل الحركة', 'car-dealer' ); ?></label>
				<?php
				$transmissions = array(
					'manual'    => __( 'يدوي', 'car-dealer' ),
					'automatic' => __( 'أوتوماتيكي', 'car-dealer' ),
				);

				echo '<select name="transmission" id="transmission">';
				echo '<option value="">' . __( 'كل الأنواع', 'car-dealer' ) . '</option>';
				foreach ( $transmissions as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_transmission, $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				?>
			</div>

			<div class="search-actions">
				<button type="submit" class="btn"><?php _e( 'بحث', 'car-dealer' ); ?></button>
				<a href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>" class="btn btn-reset"><?php _e( 'إعادة تعيين', 'car-dealer' ); ?></a>
			</div>
		</form>
		<?php

		echo $args['after_widget'];
	}

	/**
	 * إعداد النموذج في لوحة التحكم
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'البحث المتقدم للسيارات', 'car-dealer' );
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
 * فلترة استعلام السيارات بناءً على معلمات البحث
 */
function car_dealer_filter_vehicles( WP_Query $query ) {
	if ( ! is_admin() && $query->is_post_type_archive( 'car' ) && $query->is_main_query() ) {
		// فلترة حسب الماركة
		if ( isset( $_GET['make'] ) && ! empty( $_GET['make'] ) ) {
			$query->set( 'tax_query', array(
				array(
					'taxonomy' => 'car_brand',
					'field'    => 'slug',
					'terms'    => array( sanitize_text_field( $_GET['make'] ) ),
				)
			) );
		}

		// فلترة حسب الفئة
		if ( isset( $_GET['category'] ) && ! empty( $_GET['category'] ) ) {
			$tax_query = $query->get( 'tax_query' );
			if ( empty( $tax_query ) ) {
				$tax_query = array();
			}

			$tax_query[] = array(
				'taxonomy' => 'car_category',
				'field'    => 'slug',
				'terms'    => array( sanitize_text_field( $_GET['category'] ) ),
			);

			$query->set( 'tax_query', $tax_query );
		}

		// فلترة حسب سنة التصنيع
		if ( isset( $_GET['year_min'] ) && isset( $_GET['year_max'] ) && ! empty( $_GET['year_min'] ) && ! empty( $_GET['year_max'] ) ) {
			$year_min = intval( $_GET['year_min'] );
			$year_max = intval( $_GET['year_max'] );

			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_year',
					'value'   => array( $year_min, $year_max ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['year_min'] ) && ! empty( $_GET['year_min'] ) ) {
			$year_min = intval( $_GET['year_min'] );

			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_year',
					'value'   => $year_min,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['year_max'] ) && ! empty( $_GET['year_max'] ) ) {
			$year_max = intval( $_GET['year_max'] );

			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_year',
					'value'   => $year_max,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				)
			) );
		}

		// فلترة حسب السعر
		if ( isset( $_GET['price_min'] ) && isset( $_GET['price_max'] ) && ! empty( $_GET['price_min'] ) && ! empty( $_GET['price_max'] ) ) {
			$price_min = intval( $_GET['price_min'] );
			$price_max = intval( $_GET['price_max'] );

			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_price',
					'value'   => array( $price_min, $price_max ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['price_min'] ) && ! empty( $_GET['price_min'] ) ) {
			$price_min = intval( $_GET['price_min'] );

			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_price',
					'value'   => $price_min,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['price_max'] ) && ! empty( $_GET['price_max'] ) ) {
			$price_max = intval( $_GET['price_max'] );

			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_price',
					'value'   => $price_max,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				)
			) );
		}

		// فلترة حسب نوع الوقود
		if ( isset( $_GET['fuel_type'] ) && ! empty( $_GET['fuel_type'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_fuel_type',
					'value'   => sanitize_text_field( $_GET['fuel_type'] ),
					'compare' => '=',
				)
			) );
		}

		// فلترة حسب ناقل الحركة
		if ( isset( $_GET['transmission'] ) && ! empty( $_GET['transmission'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key'     => '_car_transmission',
					'value'   => sanitize_text_field( $_GET['transmission'] ),
					'compare' => '=',
				)
			) );
		}
	}
}
add_action( 'pre_get_posts', 'car_dealer_filter_vehicles' );
