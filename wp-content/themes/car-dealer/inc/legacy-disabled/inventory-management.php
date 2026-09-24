<?php
/**
 * نظام إدارة المخزون لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة حقول مخصصة لإدارة المخزون
 */
function car_dealer_inventory_custom_fields() {
	add_meta_box(
		'inventory_status',
		'حالة المخزون',
		'car_dealer_inventory_status_callback',
		'car',
		'side',
		'high'
	);

	add_meta_box(
		'inventory_details',
		'تفاصيل المخزون',
		'car_dealer_inventory_details_callback',
		'car',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'car_dealer_inventory_custom_fields' );

/**
 * دالة رد الاتصال لعرض حقول حالة المخزون
 */
function car_dealer_inventory_status_callback( WP_Post $post ) {
	// الحصول على القيم الحالية للحقول المخصصة
	$inventory_status = get_post_meta( $post->ID, '_inventory_status', true );
	$featured = get_post_meta( $post->ID, '_featured', true );
	$special_offer = get_post_meta( $post->ID, '_special_offer', true );

	// إضافة حقم nonce للتحقق من الأمان
	wp_nonce_field( 'car_dealer_save_inventory_status', 'car_dealer_nonce' );

	// عرض حقول الإدخال
	echo '<table class="form-table">';
	echo '<tbody>';
	echo '<tr>';
	echo '<th><label for="_inventory_status">الحالة</label></th>';
	echo '<td>';
	echo '<select id="_inventory_status" name="_inventory_status">';
	echo '<option value="available" ' . selected( $inventory_status, 'available', false ) . '>' . __( 'متوفر', 'car-dealer' ) . '</option>';
	echo '<option value="reserved" ' . selected( $inventory_status, 'reserved', false ) . '>' . __( 'محجوز', 'car-dealer' ) . '</option>';
	echo '<option value="sold" ' . selected( $inventory_status, 'sold', false ) . '>' . __( 'مباع', 'car-dealer' ) . '</option>';
	echo '<option value="pending" ' . selected( $inventory_status, 'pending', false ) . '>' . __( 'قيد الانتظار', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_featured">عرض كسيارة مميزة</label></th>';
	echo '<td>';
	echo '<select id="_featured" name="_featured">';
	echo '<option value="0" ' . selected( $featured, '0', false ) . '>' . __( 'لا', 'car-dealer' ) . '</option>';
	echo '<option value="1" ' . selected( $featured, '1', false ) . '>' . __( 'نعم', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_special_offer">عرض خاص</label></th>';
	echo '<td>';
	echo '<select id="_special_offer" name="_special_offer">';
	echo '<option value="0" ' . selected( $special_offer, '0', false ) . '>' . __( 'لا', 'car-dealer' ) . '</option>';
	echo '<option value="1" ' . selected( $special_offer, '1', false ) . '>' . __( 'نعم', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '</tbody>';
	echo '</table>';
}

/**
 * دالة رد الاتصال لعرض حقول تفاصيل المخزون
 */
function car_dealer_inventory_details_callback( WP_Post $post ) {
	// الحصول على القيم الحالية للحقول المخصصة
	$vin = get_post_meta( $post->ID, '_vin', true );
	$stock_number = get_post_meta( $post->ID, '_stock_number', true );
	$color = get_post_meta( $post->ID, '_color', true );
	$interior_color = get_post_meta( $post->ID, '_interior_color', true );
	$doors = get_post_meta( $post->ID, '_doors', true );
	$engine = get_post_meta( $post->ID, '_engine', true );
	$features = get_post_meta( $post->ID, '_features', true );
	$last_updated = get_post_meta( $post->ID, '_last_updated', true );

	// إضافة حقم nonce للتحقق من الأمان
	wp_nonce_field( 'car_dealer_save_inventory_details', 'car_dealer_nonce' );

	// عرض حقول الإدخال
	echo '<table class="form-table">';
	echo '<tbody>';
	echo '<tr>';
	echo '<th><label for="_vin">رقم VIN</label></th>';
	echo '<td><input type="text" id="_vin" name="_vin" value="' . esc_attr( $vin ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_stock_number">رقم المخزون</label></th>';
	echo '<td><input type="text" id="_stock_number" name="_stock_number" value="' . esc_attr( $stock_number ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_color">اللون الخارجي</label></th>';
	echo '<td><input type="text" id="_color" name="_color" value="' . esc_attr( $color ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_interior_color">لون الداخلية</label></th>';
	echo '<td><input type="text" id="_interior_color" name="_interior_color" value="' . esc_attr( $interior_color ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_doors">عدد الأبواب</label></th>';
	echo '<td>';
	echo '<select id="_doors" name="_doors">';
	echo '<option value="2" ' . selected( $doors, '2', false ) . '>' . __( '2', 'car-dealer' ) . '</option>';
	echo '<option value="4" ' . selected( $doors, '4', false ) . '>' . __( '4', 'car-dealer' ) . '</option>';
	echo '<option value="5" ' . selected( $doors, '5', false ) . '>' . __( '5', 'car-dealer' ) . '</option>';
	echo '<option value="6" ' . selected( $doors, '6', false ) . '>' . __( '6', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_engine">المحرك</label></th>';
	echo '<td><input type="text" id="_engine" name="_engine" value="' . esc_attr( $engine ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_features">الميزات</label></th>';
	echo '<td>';
	wp_editor( $features, '_features', array(
		'media_buttons' => false,
		'textarea_rows' => 5,
		'teeny' => true,
	) );
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_last_updated">آخر تحديث</label></th>';
	echo '<td><input type="text" id="_last_updated" name="_last_updated" value="' . esc_attr( $last_updated ) . '" class="regular-text" readonly></td>';
	echo '</tr>';
	echo '</tbody>';
	echo '</table>';
}

/**
 * حفظ حقول إدارة المخزون المخصصة
 */
function car_dealer_save_inventory_fields( int $post_id ) {
	// التحقق من nonce
	if ( ! isset( $_POST['car_dealer_nonce'] ) || ! wp_verify_nonce( $_POST['car_dealer_nonce'], 'car_dealer_save_inventory_status' ) || ! wp_verify_nonce( $_POST['car_dealer_nonce'], 'car_dealer_save_inventory_details' ) ) {
		return;
	}

	// التحقق من المستخدم لديه الصلاحيات
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// حفظ حقول حالة المخزون
	if ( isset( $_POST['_inventory_status'] ) ) {
		update_post_meta( $post_id, '_inventory_status', sanitize_text_field( $_POST['_inventory_status'] ) );
	}

	if ( isset( $_POST['_featured'] ) ) {
		update_post_meta( $post_id, '_featured', intval( $_POST['_featured'] ) );
	}

	if ( isset( $_POST['_special_offer'] ) ) {
		update_post_meta( $post_id, '_special_offer', intval( $_POST['_special_offer'] ) );
	}

	// حفظ حقول تفاصيل المخزون
	if ( isset( $_POST['_vin'] ) ) {
		update_post_meta( $post_id, '_vin', sanitize_text_field( $_POST['_vin'] ) );
	}

	if ( isset( $_POST['_stock_number'] ) ) {
		update_post_meta( $post_id, '_stock_number', sanitize_text_field( $_POST['_stock_number'] ) );
	}

	if ( isset( $_POST['_color'] ) ) {
		update_post_meta( $post_id, '_color', sanitize_text_field( $_POST['_color'] ) );
	}

	if ( isset( $_POST['_interior_color'] ) ) {
		update_post_meta( $post_id, '_interior_color', sanitize_text_field( $_POST['_interior_color'] ) );
	}

	if ( isset( $_POST['_doors'] ) ) {
		update_post_meta( $post_id, '_doors', intval( $_POST['_doors'] ) );
	}

	if ( isset( $_POST['_engine'] ) ) {
		update_post_meta( $post_id, '_engine', sanitize_text_field( $_POST['_engine'] ) );
	}

	if ( isset( $_POST['_features'] ) ) {
		update_post_meta( $post_id, '_features', wp_kses_post( $_POST['_features'] ) );
	}

	if ( isset( $_POST['_last_updated'] ) ) {
		update_post_meta( $post_id, '_last_updated', current_time( 'mysql' ) );
	} else {
		update_post_meta( $post_id, '_last_updated', current_time( 'mysql' ) );
	}
}
add_action( 'save_post', 'car_dealer_save_inventory_fields' );

/**
 * إضافة عمود حالة المخزون في صفحة القائمة
 */
function car_dealer_add_inventory_status_column( array $columns ) {
	$columns['inventory_status'] = __( 'الحالة', 'car-dealer' );
	$columns['featured'] = __( 'مميز', 'car-dealer' );
	$columns['special_offer'] = __( 'عرض خاص', 'car-dealer' );
	return $columns;
}
add_filter( 'manage_car_posts_columns', 'car_dealer_add_inventory_status_column' );

/**
 * إضافة محتوى عمود حالة المخزون في صفحة القائمة
 */
function car_dealer_inventory_status_column_content( array $column, int $post_id ) {
	switch ( $column ) {
		case 'inventory_status':
			$status = get_post_meta( $post_id, '_inventory_status', true );
			switch ( $status ) {
				case 'available':
					echo '<span class="status-available">' . __( 'متوفر', 'car-dealer' ) . '</span>';
					break;
				case 'reserved':
					echo '<span class="status-reserved">' . __( 'محجوز', 'car-dealer' ) . '</span>';
					break;
				case 'sold':
					echo '<span class="status-sold">' . __( 'مباع', 'car-dealer' ) . '</span>';
					break;
				case 'pending':
					echo '<span class="status-pending">' . __( 'قيد الانتظار', 'car-dealer' ) . '</span>';
					break;
				default:
					echo '-';
			}
			break;
		case 'featured':
			$featured = get_post_meta( $post_id, '_featured', true );
			if ( $featured ) {
				echo '<i class="fas fa-star"></i>';
			} else {
				echo '-';
			}
			break;
		case 'special_offer':
			$special_offer = get_post_meta( $post_id, '_special_offer', true );
			if ( $special_offer ) {
				echo '<i class="fas fa-tag"></i>';
			} else {
				echo '-';
			}
			break;
	}
}
add_action( 'manage_car_posts_custom_column', 'car_dealer_inventory_status_column_content', 10, 2 );

/**
 * إضافة فلترة حسب حالة المخزون في صفحة القائمة
 */
function car_dealer_add_inventory_status_filter() {
	global $typenow;
	$post_type = 'car';

	if ( $typenow == $post_type ) {
		?>
		<select name="inventory_status_filter" id="inventory_status_filter">
			<option value=""><?php _e( 'جميع الحالات', 'car-dealer' ); ?></option>
			<option value="available" <?php selected( isset( $_GET['inventory_status_filter'] ) && $_GET['inventory_status_filter'] == 'available', true ); ?>><?php _e( 'متوفر', 'car-dealer' ); ?></option>
			<option value="reserved" <?php selected( isset( $_GET['inventory_status_filter'] ) && $_GET['inventory_status_filter'] == 'reserved', true ); ?>><?php _e( 'محجوز', 'car-dealer' ); ?></option>
			<option value="sold" <?php selected( isset( $_GET['inventory_status_filter'] ) && $_GET['inventory_status_filter'] == 'sold', true ); ?>><?php _e( 'مباع', 'car-dealer' ); ?></option>
			<option value="pending" <?php selected( isset( $_GET['inventory_status_filter'] ) && $_GET['inventory_status_filter'] == 'pending', true ); ?>><?php _e( 'قيد الانتظار', 'car-dealer' ); ?></option>
		</select>
		<?php
	}
}
add_action( 'restrict_manage_posts', 'car_dealer_add_inventory_status_filter' );

/**
- تعديل الاستعلام لفلترة حسب حالة المخزون
 */
function car_dealer_inventory_status_filter_query( WP_Query $query ) {
	global $pagenow;
	$post_type = 'car';

	if ( is_admin() && $pagenow == 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] == $post_type && isset( $_GET['inventory_status_filter'] ) ) {
		$query->set( 'meta_key', '_inventory_status' );
		$query->set( 'meta_value', $_GET['inventory_status_filter'] );
	}
}
add_action( 'pre_get_posts', 'car_dealer_inventory_status_filter_query' );

/**
- إضافة إجراءات سريعة في صفحة القائمة
 */
function car_dealer_add_bulk_actions( array $actions ) {
	$actions['mark_as_available'] = __( 'تحديد كمتوفر', 'car-dealer' );
	$actions['mark_as_reserved'] = __( 'تحديد كمحجوز', 'car-dealer' );
	$actions['mark_as_sold'] = __( 'تحديد كمباع', 'car-dealer' );
	$actions['mark_as_pending'] = __( 'تحديد كقيد انتظار', 'car-dealer' );
	$actions['mark_as_featured'] = __( 'تحديد كمميز', 'car-dealer' );
	$actions['mark_as_special_offer'] = __( 'تحديد كعرض خاص', 'car-dealer' );

	return $actions;
}
add_filter( 'bulk_actions-edit-car', 'car_dealer_add_bulk_actions' );

/**
- معالجة الإجراءات السريعة
 */
function car_dealer_handle_bulk_actions( string $redirect_to, string $doaction, array $post_ids ) {
	if ( $doaction == 'mark_as_available' || $doaction == 'mark_as_reserved' || $doaction == 'mark_as_sold' || $doaction == 'mark_as_pending' ) {
		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, '_inventory_status', $doaction );
			update_post_meta( $post_id, '_last_updated', current_time( 'mysql' ) );
		}

		$redirect_to = add_query_arg( 'bulk_action_status', $doaction, $redirect_to );
	} elseif ( $doaction == 'mark_as_featured' || $doaction == 'mark_as_special_offer' ) {
		$value = ( $doaction == 'mark_as_featured' ) ? 1 : 0;

		foreach ( $post_ids as $post_id ) {
			if ( $doaction == 'mark_as_featured' ) {
				update_post_meta( $post_id, '_featured', $value );
			} else {
				update_post_meta( $post_id, '_special_offer', $value );
			}
			update_post_meta( $post_id, '_last_updated', current_time( 'mysql' ) );
		}

		$redirect_to = add_query_arg( 'bulk_action_status', $doaction, $redirect_to );
	}

	return $redirect_to;
}
add_filter( 'handle_bulk_actions-edit-car', 'car_dealer_handle_bulk_actions', 10, 3 );

/**
- عرض رسائل الإجراءات السريعة
 */
function car_dealer_bulk_action_notices() {
	if ( isset( $_GET['bulk_action_status'] ) ) {
		$action = sanitize_key( $_GET['bulk_action_status'] );

		switch ( $action ) {
			case 'mark_as_available':
				echo '<div class="notice notice-success is-dismissible"><p>' . __( 'تم تحديد السيارات كمتوفرة بنجاح.', 'car-dealer' ) . '</p></div>';
				break;
			case 'mark_as_reserved':
				echo '<div class="notice notice-success is-dismissible"><p>' . __( 'تم تحديد السيارات كمحجوزة بنجاح.', 'car-dealer' ) . '</p></div>';
				break;
			case 'mark_as_sold':
				echo '<div class="notice notice-success is-dismissible"><p>' . __( 'تم تحديد السيارات كمباعة بنجاح.', 'car-dealer' ) . '</p></div>';
				break;
			case 'mark_as_pending':
				echo '<div class="notice notice-success is-dismissible"><p>' . __( 'تم تحديد السيارات كقيد انتظار بنجاح.', 'car-dealer' ) . '</p></div>';
				break;
			case 'mark_as_featured':
				echo '<div class="notice notice-success is-dismissible"><p>' . __( 'تم تحديد السيارات كمميزة بنجاح.', 'car-dealer' ) . '</p></div>';
				break;
			case 'mark_as_special_offer':
				echo '<div class="notice notice-success is-dismissible"><p>' . __( 'تم تحديد السيارات كعرض خاص بنجاح.', 'car-dealer' ) . '</p></div>';
				break;
		}
	}
}
add_action( 'admin_notices', 'car_dealer_bulk_action_notices' );

/**
- إنشاء تقرير المخزون
 */
function car_dealer_generate_inventory_report() {
	$args = array(
		'post_type' => 'car',
		'posts_per_page' => -1,
	);

	$cars = get_posts( $args );

	// حساب إجمالي السيارات
	$total_cars = count( $cars );

	// حساب السيارات حسب الحالة
	$status_counts = array(
		'available' => 0,
		'reserved' => 0,
		'sold' => 0,
		'pending' => 0,
	);

	// حساب إجمالي القيمة
	$total_value = 0;

	foreach ( $cars as $car ) {
		$status = get_post_meta( $car->ID, '_inventory_status', true );
		if ( isset( $status_counts[ $status ] ) ) {
			$status_counts[ $status ]++;
		}

		$price = get_post_meta( $car->ID, '_car_price', true );
		if ( ! empty( $price ) ) {
			$total_value += floatval( $price );
		}
	}

	// إنشاء التقرير
	$report = array(
		'total_cars' => $total_cars,
		'status_counts' => $status_counts,
		'total_value' => $total_value,
		'generated_at' => current_time( 'mysql' ),
	);

	return $report;
}

/**
- عرض تقرير المخزون في لوحة التحكم
 */
function car_dealer_show_inventory_report() {
	$report = car_dealer_generate_inventory_report();
	?>
	<div class="inventory-report">
		<h2><?php _e( 'تقرير المخزون', 'car-dealer' ); ?></h2>
		<div class="report-stats">
			<div class="stat-item">
				<div class="stat-value"><?php echo esc_html( $report['total_cars'] ); ?></div>
				<div class="stat-label"><?php _e( 'إجمالي السيارات', 'car-dealer' ); ?></div>
			</div>
			<div class="stat-item">
				<div class="stat-value"><?php echo esc_html( $report['status_counts']['available'] ); ?></div>
				<div class="stat-label"><?php _e( 'متوفر', 'car-dealer' ); ?></div>
			</div>
			<div class="stat-item">
				<div class="stat-value"><?php echo esc_html( $report['status_counts']['reserved'] ); ?></div>
				<div class="stat-label"><?php _e( 'محجوز', 'car-dealer' ); ?></div>
			</div>
			<div class="stat-item">
				<div class="stat-value"><?php echo esc_html( $report['status_counts']['sold'] ); ?></div>
				<div class="stat-label"><?php _e( 'مباع', 'car-dealer' ); ?></div>
			</div>
			<div class="stat-item">
				<div class="stat-value"><?php echo esc_html( $report['status_counts']['pending'] ); ?></div>
				<div class="stat-label"><?php _e( 'قيد الانتظار', 'car-dealer' ); ?></div>
			</div>
			<div class="stat-item">
				<div class="stat-value"><?php echo esc_html( number_format( $report['total_value'], 2 ) ); ?></div>
				<div class="stat-label"><?php _e( 'إجمالي القيمة (ريال)', 'car-dealer' ); ?></div>
			</div>
		</div>
		<div class="report-actions">
			<a href="#" class="button" id="export-report"><?php _e( 'تصدير التقرير', 'car-dealer' ); ?></a>
			<a href="#" class="button" id="refresh-report"><?php _e( 'تحديث التقرير', 'car-dealer' ); ?></a>
		</div>
	</div>
	<?php
}

/**
 * حساب عدد السيارات حسب الحالة
 */
function car_dealer_count_cars_by_status( string $status ) {
	$args = array(
		'post_type'  => 'car',
		'meta_key'   => '_inventory_status',
		'meta_value' => $status,
	);
	
	$cars = get_posts( $args );
	return count( $cars );
}

/**
 * حساب عدد السيارات المميزة
 */
function car_dealer_count_featured_cars() {
	$args = array(
		'post_type'  => 'car',
		'meta_key'   => '_featured',
		'meta_value' => 1,
	);
	
	$cars = get_posts( $args );
	return count( $cars );
}

/**
 * حساب عدد العروض الخاصة
 */
function car_dealer_count_special_offers() {
	$args = array(
		'post_type'  => 'car',
		'meta_key'   => '_special_offer',
		'meta_value' => 1,
	);
	
	$cars = get_posts( $args );
	return count( $cars );
}

/**
 * إضافة إحصائيات المخزون في لوحة التحكم
 */
function car_dealer_inventory_dashboard_widget() {
	wp_add_dashboard_widget(
		'car_dealer_inventory_stats',
		__( 'إحصائيات المخزون', 'car-dealer' ),
		'car_dealer_inventory_stats_callback'
	);
}
add_action( 'wp_dashboard_setup', 'car_dealer_inventory_dashboard_widget' );

/**
 * عرض تقرير المخزون في صفحة القائمة
 */
function car_dealer_show_inventory_report_in_list() {
	// التحقق من وجود المعلمة في URL
	if ( isset( $_GET['page'] ) && $_GET['page'] == 'inventory-report' ) {
		car_dealer_show_inventory_report();
	}
}
add_action( 'admin_footer', 'car_dealer_show_inventory_report_in_list' );

/**
 * تصدير تقرير المخزون
 */
function car_dealer_export_inventory_report() {
	// التحقق من وجود المعلمة في URL
	if ( isset( $_GET['page'] ) && $_GET['page'] == 'export-inventory-report' ) {
		$report = car_dealer_generate_inventory_report();
		
		// إعداد رؤوس HTTP للتصدير
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=inventory-report-' . date( 'Y-m-d' ) . '.csv' );
		
		// فتح ملف CSV للكتابة
		$output = fopen( 'php://output', 'w' );
		
		// إضافة BOM لدعم الأحرف العربية
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		
		// إضافة رأس الجدول
		fputcsv( $output, array( 'الحالة', 'عدد السيارات' ) );
		
		// إ��加 بيانات الحالات
		$status_labels = array(
			'available' => 'متوفر',
			'reserved'  => 'محجوز',
			'sold'      => 'مباع',
			'pending'   => 'قيد الانتظار',
		);
		
		foreach ( $report['status_counts'] as $status => $count ) {
			fputcsv( $output, array( $status_labels[ $status ], $count ) );
		}
		
		// إ��加 إجمالي السيارات
		fputcsv( $output, array( 'إجمالي السيارات', $report['total_cars'] ) );
		
		// إضافة إجمالي القيمة
		fputcsv( $output, array( 'إجمالي القيمة (ريال)', $report['total_value'] ) );
		
		// إضافة تاريخ التوليد
		fputcsv( $output, array( 'تاريخ التوليد', $report['generated_at'] ) );
		
		// إغلاق الملف
		fclose( $output );
		
		// إنهاء التنفيذ
		exit;
	}
}
add_action( 'admin_init', 'car_dealer_export_inventory_report' );

/**
 * إنشاء صفحة إدارة المخزون في لوحة التحكم
 */
function car_dealer_add_inventory_management_menu() {
	add_menu_page(
		__( 'إدارة المخزون', 'car-dealer' ),
		__( 'إدارة المخزون', 'car-dealer' ),
		'manage_options',
		'inventory-management',
		'car_dealer_inventory_management_page',
		'dashicons-car',
		6
	);
	
	// إضافة صفحة فرعية لتقرير المخزون
	add_submenu_page(
		'inventory-management',
		__( 'تقرير المخزون', 'car-dealer' ),
		__( 'تقرير المخزون', 'car-dealer' ),
		'manage_options',
		'inventory-report',
		'car_dealer_inventory_report_page',
		'inventory-management'
	);
}
add_action( 'admin_menu', 'car_dealer_add_inventory_management_menu' );

/**
 * دالة رد الاتصال لعرض صفحة إدارة المخزون
 */
function car_dealer_inventory_management_page() {
	?>
	<div class="wrap">
		<h1><?php _e( 'إدارة المخزون', 'car-dealer' ); ?></h1>
		
		<div class="inventory-management-overview">
			<h2><?php _e( 'نظرة عامة', 'car-dealer' ); ?></h2>
			<?php car_dealer_show_inventory_report(); ?>
		</div>
		
		<div class="inventory-management-actions">
			<h2><?php _e( 'إجراءات سريعة', 'car-dealer' ); ?></h2>
			<div class="action-buttons">
				<a href="edit.php?post_type=car" class="button button-primary">
					<?php _e( 'إضافة سيارة جديدة', 'car-dealer' ); ?>
				</a>
				<a href="edit.php?post_type=car&inventory_status_filter=available" class="button">
					<?php _e( 'عرض السيارات المتوفرة', 'car-dealer' ); ?>
				</a>
				<a href="edit.php?post_type=car&inventory_status_filter=sold" class="button">
					<?php _e( 'عرض السيارات المباعة', 'car-dealer' ); ?>
				</a>
				<a href="admin.php?page=inventory-report" class="button">
					<?php _e( 'عرض تقرير المخزون', 'car-dealer' ); ?>
				</a>
				<a href="admin.php?page=export-inventory-report" class="button">
					<?php _e( 'تصدير تقرير المخزون', 'car-dealer' ); ?>
				</a>
			</div>
		</div>
	</div>
	<?php
}

/**
 * دالة رد الاتصال لعرض صفحة تقرير المخزون
 */
function car_dealer_inventory_report_page() {
	?>
	<div class="wrap">
		<h1><?php _e( 'تقرير المخزون', 'car-dealer' ); ?></h1>
		<?php car_dealer_show_inventory_report(); ?>
	</div>
	<?php
}

/**
 * تحسين البحث المتقدم في المخزون
 */
function car_dealer_add_inventory_search_filters() {
	// إضافة حقول البحث المتقدم في صفحة القائمة
	?>
	<div class="inventory-search-filters">
		<h3><?php _e( 'بحث متقدم', 'car-dealer' ); ?></h3>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="car">
			
			<div class="filter-group">
				<label for="search-price-min"><?php _e( 'السعر من', 'car-dealer' ); ?></label>
				<input type="number" id="search-price-min" name="price_min" value="<?php echo isset( $_GET['price_min'] ) ? esc_attr( $_GET['price_min'] ) : ''; ?>">
			</div>
			
			<div class="filter-group">
				<label for="search-price-max"><?php _e( 'السعر إلى', 'car-dealer' ); ?></label>
				<input type="number" id="search-price-max" name="price_max" value="<?php echo isset( $_GET['price_max'] ) ? esc_attr( $_GET['price_max'] ) : ''; ?>">
			</div>
			
			<div class="filter-group">
				<label for="search-year-min"><?php _e( 'سنة التصنيع من', 'car-dealer' ); ?></label>
				<input type="number" id="search-year-min" name="year_min" value="<?php echo isset( $_GET['year_min'] ) ? esc_attr( $_GET['year_min'] ) : ''; ?>" min="1900" max="<?php echo date( 'Y' ); ?>">
			</div>
			
			<div class="filter-group">
				<label for="search-year-max"><?php _e( 'سنة التصنيع إلى', 'car-dealer' ); ?></label>
				<input type="number" id="search-year-max" name="year_max" value="<?php echo isset( $_GET['year_max'] ) ? esc_attr( $_GET['year_max'] ) : ''; ?>" min="1900" max="<?php echo date( 'Y' ); ?>">
			</div>
			
			<div class="filter-group">
				<label for="search-kilometers-min"><?php _e( 'المسافة من (كم)', 'car-dealer' ); ?></label>
				<input type="number" id="search-kilometers-min" name="kilometers_min" value="<?php echo isset( $_GET['kilometers_min'] ) ? esc_attr( $_GET['kilometers_min'] ) : ''; ?>">
			</div>
			
			<div class="filter-group">
				<label for="search-kilometers-max"><?php _e( 'المسافة إلى (كم)', 'car-dealer' ); ?></label>
				<input type="number" id="search-kilometers-max" name="kilometers_max" value="<?php echo isset( $_GET['kilometers_max'] ) ? esc_attr( $_GET['kilometers_max'] ) : ''; ?>">
			</div>
			
			<div class="filter-actions">
				<input type="submit" class="button" value="<?php _e( 'بحث', 'car-dealer' ); ?>">
				<a href="<?php echo esc_url( remove_query_arg( array( 'price_min', 'price_max', 'year_min', 'year_max', 'kilometers_min', 'kilometers_max' ) ) ); ?>" class="button"><?php _e( 'إعادة تعيين', 'car-dealer' ); ?></a>
			</div>
		</form>
	</div>
	<?php
}
add_action( 'restrict_manage_posts', 'car_dealer_add_inventory_search_filters' );

/**
 * تطبيق عوامل البحث المتقدم
 */
function car_dealer_apply_inventory_search_filters( WP_Query $query ) {
	global $pagenow;
	$post_type = 'car';
	
	if ( is_admin() && $pagenow == 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] == $post_type ) {
		// تطبيق فلترة السعر
		if ( isset( $_GET['price_min'] ) && isset( $_GET['price_max'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_price',
					'value' => array( intval( $_GET['price_min'] ), intval( $_GET['price_max'] ) ),
					'compare' => 'BETWEEN',
					'type' => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['price_min'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_price',
					'value' => intval( $_GET['price_min'] ),
					'compare' => '>=',
					'type' => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['price_max'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_price',
					'value' => intval( $_GET['price_max'] ),
					'compare' => '<=',
					'type' => 'NUMERIC',
				)
			) );
		}
		
		// تطبيق فلترة سنة التصنيع
		if ( isset( $_GET['year_min'] ) && isset( $_GET['year_max'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_year',
					'value' => array( intval( $_GET['year_min'] ), intval( $_GET['year_max'] ) ),
					'compare' => 'BETWEEN',
					'type' => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['year_min'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_year',
					'value' => intval( $_GET['year_min'] ),
					'compare' => '>=',
					'type' => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['year_max'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_year',
					'value' => intval( $_GET['year_max'] ),
					'compare' => '<=',
					'type' => 'NUMERIC',
				)
			) );
		}
		
		// تطبيق فلترة المسافة المقطوعة
		if ( isset( $_GET['kilometers_min'] ) && isset( $_GET['kilometers_max'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_kilometers',
					'value' => array( intval( $_GET['kilometers_min'] ), intval( $_GET['kilometers_max'] ) ),
					'compare' => 'BETWEEN',
					'type' => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['kilometers_min'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_kilometers',
					'value' => intval( $_GET['kilometers_min'] ),
					'compare' => '>=',
					'type' => 'NUMERIC',
				)
			) );
		} elseif ( isset( $_GET['kilometers_max'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array(
					'key' => '_car_kilometers',
					'value' => intval( $_GET['kilometers_max'] ),
					'compare' => '<=',
					'type' => 'NUMERIC',
				)
			) );
		}
	}
}
add_action( 'pre_get_posts', 'car_dealer_apply_inventory_search_filters' );

/**
 * تحسين عرض السيارات في صفحة القائمة
 */
function car_dealer_add_inventory_columns( array $columns ) {
	$columns['inventory_status'] = __( 'الحالة', 'car-dealer' );
	$columns['featured'] = __( 'مميز', 'car-dealer' );
	$columns['special_offer'] = __( 'عرض خاص', 'car-dealer' );
	$columns['price'] = __( 'السعر', 'car-dealer' );
	$columns['year'] = __( 'السنة', 'car-dealer' );
	$columns['kilometers'] = __( 'المسافة (كم)', 'car-dealer' );
	return $columns;
}
add_filter( 'manage_car_posts_columns', 'car_dealer_add_inventory_columns' );

/**
 * محتوى أعمدة عرض السيارات
 */
function car_dealer_inventory_column_content( string $column, int $post_id ) {
	switch ( $column ) {
		case 'inventory_status':
			$status = get_post_meta( $post_id, '_inventory_status', true );
			$status_labels = array(
				'available' => '<span class="status-available">' . __( 'متوفر', 'car-dealer' ) . '</span>',
				'reserved'  => '<span class="status-reserved">' . __( 'محجوز', 'car-dealer' ) . '</span>',
				'sold'      => '<span class="status-sold">' . __( 'مباع', 'car-dealer' ) . '</span>',
				'pending'   => '<span class="status-pending">' . __( 'قيد الانتظار', 'car-dealer' ) . '</span>',
			);
			echo isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : '-';
			break;
		case 'featured':
			echo get_post_meta( $post_id, '_featured', true ) ? '<span class="featured-star">⭐</span>' : '-';
			break;
		case 'special_offer':
			echo get_post_meta( $post_id, '_special_offer', true ) ? '<span class="special-tag">🏷️</span>' : '-';
			break;
		case 'price':
			$price = get_post_meta( $post_id, '_car_price', true );
			echo ! empty( $price ) ? number_format( $price, 2 ) . ' ريال' : '-';
			break;
		case 'year':
			echo get_post_meta( $post_id, '_car_year', true );
			break;
		case 'kilometers':
			echo number_format( get_post_meta( $post_id, '_car_kilometers', true ) ) . ' كم';
			break;
	}
}
add_action( 'manage_car_posts_custom_column', 'car_dealer_inventory_column_content', 10, 2 );

/**
 * جعل أعمدة المخزون قابلة للفرز
 */
function car_dealer_make_inventory_columns_sortable( array $columns ) {
	$columns['inventory_status'] = 'inventory_status';
	$columns['price'] = '_car_price';
	$columns['year'] = '_car_year';
	$columns['kilometers'] = '_car_kilometers';
	return $columns;
}
add_filter( 'manage_edit-car_sortable_columns', 'car_dealer_make_inventory_columns_sortable' );

/**
 * تطبيق الفرز حسب أعمدة المخزون
 */
function car_dealer_inventory_column_orderby( WP_Query $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	
	$orderby = $query->get( 'orderby' );
	
	if ( 'inventory_status' === $orderby ) {
		$query->set( 'meta_key', '_inventory_status' );
		$query->set( 'orderby', 'meta_value' );
	} elseif ( '_car_price' === $orderby ) {
		$query->set( 'meta_key', '_car_price' );
		$query->set( 'orderby', 'meta_value_num' );
	} elseif ( '_car_year' === $orderby ) {
		$query->set( 'meta_key', '_car_year' );
		$query->set( 'orderby', 'meta_value_num' );
	} elseif ( '_car_kilometers' === $orderby ) {
		$query->set( 'meta_key', '_car_kilometers' );
		$query->set( 'orderby', 'meta_value_num' );
	}
}
add_action( 'pre_get_posts', 'car_dealer_inventory_column_orderby' );

/**
 * إضافة حقول مخصصة لعرض السيارات في الواجهة الأمامية
 */
function car_dealer_car_listing_fields() {
	add_meta_box(
		'car_listing_fields',
		__( 'تفاصيل السيارة', 'car-dealer' ),
		'car_dealer_car_listing_fields_callback',
		'car',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'car_dealer_car_listing_fields' );

/**
 * دالة رد الاتصال لعرض حقول السيارة في الواجهة الأمامية
 */
function car_dealer_car_listing_fields_callback( WP_Post $post ) {
	// الحصول على القيم الحالية
	$price = get_post_meta( $post->ID, '_car_price', true );
	$year = get_post_meta( $post->ID, '_car_year', true );
	$kilometers = get_post_meta( $post->ID, '_car_kilometers', true );
	$transmission = get_post_meta( $post->ID, '_car_transmission', true );
	$fuel_type = get_post_meta( $post->ID, '_car_fuel_type', true );
	$inventory_status = get_post_meta( $post->ID, '_inventory_status', true );
	$featured = get_post_meta( $post->ID, '_featured', true );
	$special_offer = get_post_meta( $post->ID, '_special_offer', true );
	
	// إضافة nonce للتحقق من الأمان
	wp_nonce_field( 'car_dealer_save_car_listing_fields', 'car_dealer_nonce' );
	
	echo '<table class="form-table">';
	echo '<tbody>';
	echo '<tr>';
	echo '<th><label for="_car_price">السعر (ريال)</label></th>';
	echo '<td><input type="number" id="_car_price" name="_car_price" value="' . esc_attr( $price ) . '" class="regular-text"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_car_year">سنة التصنيع</label></th>';
	echo '<td><input type="number" id="_car_year" name="_car_year" value="' . esc_attr( $year ) . '" min="1900" max="' . date( 'Y' ) . '"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_car_kilometers">المسافة المقطوعة (كم)</label></th>';
	echo '<td><input type="number" id="_car_kilometers" name="_car_kilometers" value="' . esc_attr( $kilometers ) . '"></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_car_transmission">ناقل الحركة</label></th>';
	echo '<td>';
	echo '<select id="_car_transmission" name="_car_transmission">';
	echo '<option value="manual" ' . selected( $transmission, 'manual', false ) . '>' . __( 'يدوي', 'car-dealer' ) . '</option>';
	echo '<option value="automatic" ' . selected( $transmission, 'automatic', false ) . '>' . __( 'أوتوماتيكي', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_car_fuel_type">نوع الوقود</label></th>';
	echo '<td>';
	echo '<select id="_car_fuel_type" name="_car_fuel_type">';
	echo '<option value="gasoline" ' . selected( $fuel_type, 'gasoline', false ) . '>' . __( 'بنزين', 'car-dealer' ) . '</option>';
	echo '<option value="diesel" ' . selected( $fuel_type, 'diesel', false ) . '>' . __( 'ديزل', 'car-dealer' ) . '</option>';
	echo '<option value="hybrid" ' . selected( $fuel_type, 'hybrid', false ) . '>' . __( 'هجين', 'car-dealer' ) . '</option>';
	echo '<option value="electric" ' . selected( $fuel_type, 'electric', false ) . '>' . __( 'كهربائي', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_inventory_status">حالة المخزون</label></th>';
	echo '<td>';
	echo '<select id="_inventory_status" name="_inventory_status">';
	echo '<option value="available" ' . selected( $inventory_status, 'available', false ) . '>' . __( 'متوفر', 'car-dealer' ) . '</option>';
	echo '<option value="reserved" ' . selected( $inventory_status, 'reserved', false ) . '>' . __( 'محجوز', 'car-dealer' ) . '</option>';
	echo '<option value="sold" ' . selected( $inventory_status, 'sold', false ) . '>' . __( 'مباع', 'car-dealer' ) . '</option>';
	echo '<option value="pending" ' . selected( $inventory_status, 'pending', false ) . '>' . __( 'قيد الانتظار', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_featured">سيارة مميزة</label></th>';
	echo '<td>';
	echo '<select id="_featured" name="_featured">';
	echo '<option value="0" ' . selected( $featured, '0', false ) . '>' . __( 'لا', 'car-dealer' ) . '</option>';
	echo '<option value="1" ' . selected( $featured, '1', false ) . '>' . __( 'نعم', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_special_offer">عرض خاص</label></th>';
	echo '<td>';
	echo '<select id="_special_offer" name="_special_offer">';
	echo '<option value="0" ' . selected( $special_offer, '0', false ) . '>' . __( 'لا', 'car-dealer' ) . '</option>';
	echo '<option value="1" ' . selected( $special_offer, '1', false ) . '>' . __( 'نعم', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '</tbody>';
	echo '</table>';
}

/**
 * حفظ حقول السيارة في الواجهة الأمامية
 */
function car_dealer_save_car_listing_fields( int $post_id ) {
	// التحقق من nonce
	if ( ! isset( $_POST['car_dealer_nonce'] ) || ! wp_verify_nonce( $_POST['car_dealer_nonce'], 'car_dealer_save_car_listing_fields' ) ) {
		return;
	}
	
	// التحقق من الصلاحيات
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	
	// الحقول وحدهات التنظيف
	$fields = array(
		'_car_price'        => 'floatval',
		'_car_year'         => 'intval',
		'_car_kilometers'  => 'intval',
		'_car_transmission' => 'sanitize_text_field',
		'_car_fuel_type'    => 'sanitize_text_field',
		'_inventory_status' => 'sanitize_text_field',
		'_featured'         => 'intval',
		'_special_offer'    => 'intval',
	);
	
	// حفظ الحقول
	foreach ( $fields as $field => $sanitize ) {
		if ( isset( $_POST[ $field ] ) ) {
			update_post_meta( $post_id, $field, $sanitize( $_POST[ $field ] ) );
		}
	}
}
add_action( 'save_post', 'car_dealer_save_car_listing_fields' );

/**
 * إضافة حقول مخصصة لعرض السيارات في الواجهة الأمامية
 */
function car_dealer_car_front_end_fields() {
	add_meta_box(
		'car_front_end_fields',
		__( 'تفاصيل السيارة للعرض', 'car-dealer' ),
		'car_dealer_car_front_end_fields_callback',
		'car',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'car_dealer_car_front_end_fields' );

/**
 * دالة رد الاتصال لعرض حقول السيارة في الواجهة الأمامية
 */
function car_dealer_car_front_end_fields_callback( WP_Post $post ) {
	// الحصول على القيم الحالية
	$price = get_post_meta( $post->ID, '_car_price', true );
	$year = get_post_meta( $post->ID, '_car_year', true );
	$kilometers = get_post_meta( $post->ID, '_car_kilometers', true );
	$transmission = get_post_meta( $post->ID, '_car_transmission', true );
	$fuel_type = get_post_meta( $post->ID, '_car_fuel_type', true );
	$inventory_status = get_post_meta( $post->ID, '_inventory_status', true );
	$featured = get_post_meta( $post->ID, '_featured', true );
	$special_offer = get_post_meta( $post->ID, '_special_offer', true );
	
	// إضافة nonce للتحقق من الأمان
	wp_nonce_field( 'car_dealer_save_car_front_end_fields', 'car_dealer_nonce' );
	
	echo '<p><strong>' . __( 'السعر:', 'car-dealer' ) . '</strong> ' . ( ! empty( $price ) ? number_format( $price, 2 ) . ' ريال' : '-' ) . '</p>';
	echo '<p><strong>' . __( 'سنة التصنيع:', 'car-dealer' ) . '</strong> ' . ( ! empty( $year ) ? $year : '-' ) . '</p>';
	echo '<p><strong>' . __( 'المسافة المقطوعة:', 'car-dealer' ) . '</strong> ' . ( ! empty( $kilometers ) ? number_format( $kilometers ) . ' كم' : '-' ) . '</p>';
	echo '<p><strong>' . __( 'ناقل الحركة:', 'car-dealer' ) . '</strong> ' . ( ! empty( $transmission ) ? ( $transmission === 'manual' ? __( 'يدوي', 'car-dealer' ) : __( 'أوتوماتيكي', 'car-dealer' ) ) : '-' ) . '</p>';
	echo '<p><strong>' . __( 'نوع الوقود:', 'car-dealer' ) . '</strong> ' . ( ! empty( $fuel_type ) ? $fuel_type : '-' ) . '</p>';
	echo '<p><strong>' . __( 'حالة المخزون:', 'car-dealer' ) . '</strong> ' . ( ! empty( $inventory_status ) ? ( $inventory_status === 'available' ? __( 'متوفر', 'car-dealer' ) : ( $inventory_status === 'reserved' ? __( 'محجوز', 'car-dealer' ) : ( $inventory_status === 'sold' ? __( 'مباع', 'car-dealer' ) : __( 'قيد الانتظار', 'car-dealer' ) ) ) ) : '-' ) . '</p>';
	echo '<p><strong>' . __( 'سيارة مميزة:', 'car-dealer' ) . '</strong> ' . ( ! empty( $featured ) ? __( 'نعم', 'car-dealer' ) : __( 'لا', 'car-dealer' ) ) . '</p>';
	echo '<p><strong>' . __( 'عرض خاص:', 'car-dealer' ) . '</strong> ' . ( ! empty( $special_offer ) ? __( 'نعم', 'car-dealer' ) : __( 'لا', 'car-dealer' ) ) . '</p>';
}

/**
 * حفظ حقول السيارة في الواجهة الأمامية
 */
function car_dealer_save_car_front_end_fields( int $post_id ) {
	// التحقق من nonce
	if ( ! isset( $_POST['car_dealer_nonce'] ) || ! wp_verify_nonce( $_POST['car_dealer_nonce'], 'car_dealer_save_car_front_end_fields' ) ) {
		return;
	}
	
	// التحقق من الصلاحيات
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	
	// الحقول وحدهات التنظيف
	$fields = array(
		'_car_price'        => 'floatval',
		'_car_year'         => 'intval',
		'_car_kilometers'  => 'intval',
		'_car_transmission' => 'sanitize_text_field',
		'_car_fuel_type'    => 'sanitize_text_field',
		'_inventory_status' => 'sanitize_text_field',
		'_featured'         => 'intval',
		'_special_offer'    => 'intval',
	);
	
	// حفظ الحقول
	foreach ( $fields as $field => $sanitize ) {
		if ( isset( $_POST[ $field ] ) ) {
			update_post_meta( $post_id, $field, $sanitize( $_POST[ $field ] ) );
		}
	}
}
add_action( 'save_post', 'car_dealer_save_car_front_end_fields' );

/**
 * إضافة حقول مخصصة لعرض السيارات في الواجهة الأمامية
 */
function car_dealer_add_car_shortcodes() {
	// إضافة shortcode لعرض السيارات
	add_shortcode( 'car_listing', 'car_dealer_car_listing_shortcode' );
	add_shortcode( 'car_single', 'car_dealer_car_single_shortcode' );
}
add_action( 'init', 'car_dealer_add_car_shortcodes' );

/**
 * دالة رد الاتصال لعرض سيارات القائمة
 */
function car_dealer_car_listing_shortcode( array $atts ) {
	// استخراج المعاملات
	$atts = shortcode_atts(
		array(
			'status' => 'available',
			'featured' => '0',
			'special_offer' => '0',
			'limit' => '12',
			'orderby' => 'date',
			'order' => 'DESC',
		),
		$atts,
		'car_listing'
	);
	
	// إعداد الاستعلام
	$args = array(
		'post_type' => 'car',
		'posts_per_page' => intval( $atts['limit'] ),
		'orderby' => $atts['orderby'],
		'order' => $atts['order'],
	);
	
	// إضافة فلترة الحالة
	if ( $atts['status'] !== 'all' ) {
		$args['meta_query'][] = array(
			'key' => '_inventory_status',
			'value' => $atts['status'],
		);
	}
	
	// إضافة فلترة المميزة
	if ( $atts['featured'] === '1' ) {
		$args['meta_query'][] = array(
			'key' => '_featured',
			'value' => '1',
		);
	}
	
	// إضافة فلترة العرض الخاص
	if ( $atts['special_offer'] === '1' ) {
		$args['meta_query'][] = array(
			'key' => '_special_offer',
			'value' => '1',
		);
	}
	
	// تنفيذ الاستعلام
	$cars_query = new WP_Query( $args );
	
	// إعداد المخرجات
	$output = '<div class="car-listing">';
	
	if ( $cars_query->have_posts() ) {
		while ( $cars_query->have_posts() ) {
			$cars_query->the_post();
			
			// الحصول على بيانات السيارة
			$price = get_post_meta( get_the_ID(), '_car_price', true );
			$year = get_post_meta( get_the_ID(), '_car_year', true );
			$kilometers = get_post_meta( get_the_ID(), '_car_kilometers', true );
			$transmission = get_post_meta( get_the_ID(), '_car_transmission', true );
			$fuel_type = get_post_meta( get_the_ID(), '_car_fuel_type', true );
			$inventory_status = get_post_meta( get_the_ID(), '_inventory_status', true );
			$featured = get_post_meta( get_the_ID(), '_featured', true );
			$special_offer = get_post_meta( get_the_ID(), '_special_offer', true );
			
			// إضافة السيارة إلى المخرجات
			$output .= '<div class="car-item">';
			$output .= '<div class="car-image">' . get_the_post_thumbnail( get_the_ID(), 'medium' ) . '</div>';
			$output .= '<div class="car-details">';
			$output .= '<h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
			$output .= '<div class="car-price">' . ( ! empty( $price ) ? number_format( $price, 2 ) . ' ريال' : '-' ) . '</div>';
			$output .= '<div class="car-info">' . __( 'سنة:', 'car-dealer' ) . ' ' . ( ! empty( $year ) ? $year : '-' ) . ' | ' . __( 'المسافة:', 'car-dealer' ) . ' ' . ( ! empty( $kilometers ) ? number_format( $kilometers ) . ' كم' : '-' ) . '</div>';
			$output .= '<div class="car-meta">';
			if ( ! empty( $transmission ) ) {
				$output .= '<span class="car-transmission">' . ( $transmission === 'manual' ? __( 'يدوي', 'car-dealer' ) : __( 'أوتوماتيكي', 'car-dealer' ) ) . '</span>';
			}
			if ( ! empty( $fuel_type ) ) {
				$output .= '<span class="car-fuel">' . $fuel_type . '</span>';
			}
			if ( ! empty( $inventory_status ) ) {
				$status_class = $inventory_status === 'available' ? 'status-available' : ( $inventory_status === 'reserved' ? 'status-reserved' : ( $inventory_status === 'sold' ? 'status-sold' : 'status-pending' ) );
				$output .= '<span class="car-status ' . $status_class . '">' . ( $inventory_status === 'available' ? __( 'متوفر', 'car-dealer' ) : ( $inventory_status === 'reserved' ? __( 'محجوز', 'car-dealer' ) : ( $inventory_status === 'sold' ? __( 'مباع', 'car-dealer' ) : __( 'قيد الانتظار', 'car-dealer' ) ) ) ) . '</span>';
			}
			$output .= '</div>';
			$output .= '<div class="car-actions">';
			if ( $inventory_status === 'available' ) {
				$output .= '<a href="' . get_permalink() . '" class="button button-primary">' . __( 'تفاصيل السيارة', 'car-dealer' ) . '</a>';
				$output .= '<a href="#" class="button request-test-drive" data-car-id="' . get_the_ID() . '">' . __( 'حجز تجربة قيادة', 'car-dealer' ) . '</a>';
			}
			$output .= '</div>';
			$output .= '</div>';
			$output .= '</div>';
		}
	} else {
		$output .= '<p>' . __( 'لم يتم العثور على سيارات مطابقة للبحث', 'car-dealer' ) . '</p>';
	}
	
	wp_reset_postdata();
	$output .= '</div>';
	
	return $output;
}

/**
 * دالة رد الاتصال لعرض سيارة واحدة
 */
function car_dealer_car_single_shortcode( array $atts ) {
	// استخراج المعاملات
	$atts = shortcode_atts(
		array(
			'id' => '',
		),
		$atts,
		'car_single'
	);
	
	// التحقق من وجود معرّف السيارة
	if ( empty( $atts['id'] ) ) {
		return '<p>' . __( 'لم يتم تحديد السيارة', 'car-dealer' ) . '</p>';
	}
	
	// الحصول على بيانات السيارة
	$car = get_post( intval( $atts['id'] ) );
	
	// التحقق من وجود السيارة
	if ( ! $car || $car->post_type !== 'car' ) {
		return '<p>' . __( 'لم يتم العثور على السيارة', 'car-dealer' ) . '</p>';
	}
	
	// الحصول على البيانات المخصصة
	$price = get_post_meta( $car->ID, '_car_price', true );
	$year = get_post_meta( $car->ID, '_car_year', true );
	$kilometers = get_post_meta( $car->ID, '_car_kilometers', true );
	$transmission = get_post_meta( $car->ID, '_car_transmission', true );
	$fuel_type = get_post_meta( $car->ID, '_car_fuel_type', true );
	$inventory_status = get_post_meta( $car->ID, '_inventory_status', true );
	$featured = get_post_meta( $car->ID, '_featured', true );
	$special_offer = get_post_meta( $car->ID, '_special_offer', true );
	
	// إعداد المخرجات
	$output = '<div class="car-single">';
	$output .= '<div class="car-gallery">' . get_the_post_thumbnail( $car->ID, 'large' ) . '</div>';
	$output .= '<div class="car-details">';
	$output .= '<h2>' . $car->post_title . '</h2>';
	$output .= '<div class="car-price">' . ( ! empty( $price ) ? number_format( $price, 2 ) . ' ريال' : '-' ) . '</div>';
	$output .= '<div class="car-info">' . __( 'سنة:', 'car-dealer' ) . ' ' . ( ! empty( $year ) ? $year : '-' ) . ' | ' . __( 'المسافة:', 'car-dealer' ) . ' ' . ( ! empty( $kilometers ) ? number_format( $kilometers ) . ' كم' : '-' ) . '</div>';
	$output .= '<div class="car-meta">';
	if ( ! empty( $transmission ) ) {
		$output .= '<span class="car-transmission">' . ( $transmission === 'manual' ? __( 'يدوي', 'car-dealer' ) : __( 'أوتوماتيكي', 'car-dealer' ) ) . '</span>';
	}
	if ( ! empty( $fuel_type ) ) {
		$output .= '<span class="car-fuel">' . $fuel_type . '</span>';
	}
	if ( ! empty( $inventory_status ) ) {
		$status_class = $inventory_status === 'available' ? 'status-available' : ( $inventory_status === 'reserved' ? 'status-reserved' : ( $inventory_status === 'sold' ? 'status-sold' : 'status-pending' ) );
		$output .= '<span class="car-status ' . $status_class . '">' . ( $inventory_status === 'available' ? __( 'متوفر', 'car-dealer' ) : ( $inventory_status === 'reserved' ? __( 'محجوز', 'car-dealer' ) : ( $inventory_status === 'sold' ? __( 'مباع', 'car-dealer' ) : __( 'قيد الانتظار', 'car-dealer' ) ) ) ) . '</span>';
	}
	$output .= '</div>';
	$output .= '<div class="car-description">' . $car->post_content . '</div>';
	$output .= '<div class="car-actions">';
	if ( $inventory_status === 'available' ) {
		$output .= '<a href="#" class="button button-primary request-test-drive" data-car-id="' . $car->ID . '">' . __( 'حجز تجربة قيادة', 'car-dealer' ) . '</a>';
	}
	$output .= '</div>';
	$output .= '</div>';
	$output .= '</div>';
	
	return $output;
}

/**
 * دالة رد الاتصال لعرض إحصائيات المخزون
 */
function car_dealer_inventory_stats_callback() {
	$statuses = array( 'available', 'reserved', 'sold', 'pending' );
	$status_labels = array(
		'available' => 'متوفر',
		'reserved'  => 'محجوز',
		'sold'      => 'مباع',
		'pending'   => 'قيد الانتظار',
	);
	$status_colors = array(
		'available' => '#4CAF50',
		'reserved'  => '#FF9800',
		'sold'      => '#F44336',
		'pending'   => '#2196F3',
	);
	
	$total = 0;
	
	echo '<div class="inventory-stats">';
	echo '<div class="stats-grid">';
	
	foreach ( $statuses as $status ) {
		$count = car_dealer_count_cars_by_status( $status );
		$total += $count;
		
		echo '<div class="stat-item" style="border-left: 4px solid ' . $status_colors[ $status ] . ';">';
		echo '<div class="stat-count">' . $count . '</div>';
		echo '<div class="stat-label">' . $status_labels[ $status ] . '</div>';
		echo '</div>';
	}
	
	echo '</div>';
	
	// إجمالي السيارات
	echo '<div class="stat-total">';
	echo '<strong>' . __( 'إجمالي السيارات', 'car-dealer' ) . ':</strong> ' . $total;
	echo '</div>';
	
	// السيارات المميزة
	$featured_count = car_dealer_count_featured_cars();
	echo '<div class="stat-featured">';
	echo '<strong>' . __( 'سيارات مميزة', 'car-dealer' ) . ':</strong> ' . $featured_count;
	echo '</div>';
	
	// عروض خاصة
	$special_count = car_dealer_count_special_offers();
	echo '<div class="stat-special">';
	echo '<strong>' . __( 'عروض خاصة', 'car-dealer' ) . ':</strong> ' . $special_count;
	echo '</div>';
	
	echo '</div>';
	
	// إضافة CSS للإحصائيات
	echo '<style>
		.inventory-stats .stats-grid {
			display: grid;
			grid-template-columns: repeat(2, 1fr);
			gap: 10px;
			margin-bottom: 15px;
		}
		.inventory-stats .stat-item {
			padding: 10px;
			background: #f9f9f9;
			border-radius: 4px;
		}
		.inventory-stats .stat-count {
			font-size: 24px;
			font-weight: bold;
		}
		.inventory-stats .stat-label {
			font-size: 12px;
			color: #666;
		}
		.inventory-stats .stat-total,
		.inventory-stats .stat-featured,
		.inventory-stats .stat-special {
			padding: 8px 0;
			border-top: 1px solid #eee;
		}
	</style>';
}
add_action( 'dashboard_glance_items', 'car_dealer_show_inventory_report' );
