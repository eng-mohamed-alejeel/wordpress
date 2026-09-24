<?php
/**
 * Professional admin workspace for the car dealer theme.
 *
 * The theme stays compatible with WordPress core by using normal roles,
 * capabilities, admin menus and custom post types instead of changing wp-admin.
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/admin-workspace.php';

function car_dealer_register_roles() {
	$manager_caps = array(
		'read'                  => true,
		'edit_cars'             => true,
		'edit_others_cars'      => true,
		'edit_published_cars'   => true,
		'publish_cars'          => true,
		'delete_cars'           => true,
		'delete_others_cars'    => true,
		'delete_published_cars' => true,
		'read_private_cars'     => true,
		'upload_files'          => true,
		'manage_car_dealer'     => true,
		'manage_car_brands'     => true,
		'assign_car_brands'     => true,
		'manage_car_categories' => true,
		'assign_car_categories' => true,
	);

	$sales_caps = array(
		'read'                  => true,
		'edit_cars'             => true,
		'delete_cars'           => true,
		'upload_files'          => true,
		'manage_car_dealer'     => true,
		'assign_car_brands'     => true,
		'assign_car_categories' => true,
	);

	add_role( 'car_dealer_manager', __( 'مدير المعرض', 'car-dealer' ), $manager_caps );
	add_role( 'car_dealer_sales', __( 'مستشار مبيعات', 'car-dealer' ), $sales_caps );

	foreach ( array( 'car_dealer_manager' => $manager_caps, 'car_dealer_sales' => $sales_caps, 'administrator' => $manager_caps ) as $role_name => $caps ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}

		foreach ( $caps as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}
}
add_action( 'init', 'car_dealer_register_roles', 5 );

function car_dealer_admin_menu() {
	add_menu_page( __( 'إدارة المعرض', 'car-dealer' ), __( 'إدارة المعرض', 'car-dealer' ), 'manage_car_dealer', 'car-dealer-dashboard', 'car_dealer_render_dashboard', 'dashicons-car', 3 );
	add_submenu_page( 'car-dealer-dashboard', __( 'نظرة عامة', 'car-dealer' ), __( 'نظرة عامة', 'car-dealer' ), 'manage_car_dealer', 'car-dealer-dashboard', 'car_dealer_render_dashboard' );
	add_submenu_page( 'car-dealer-dashboard', __( 'إضافة سيارة', 'car-dealer' ), __( 'إضافة سيارة', 'car-dealer' ), 'edit_cars', 'car-dealer-add-car', 'car_dealer_render_add_car_page' );
	add_submenu_page( 'car-dealer-dashboard', __( 'كل السيارات', 'car-dealer' ), __( 'كل السيارات', 'car-dealer' ), 'edit_cars', 'edit.php?post_type=car' );
	add_submenu_page( 'car-dealer-dashboard', __( 'الماركات', 'car-dealer' ), __( 'الماركات', 'car-dealer' ), 'manage_car_brands', 'edit-tags.php?taxonomy=car_brand&post_type=car' );
	add_submenu_page( 'car-dealer-dashboard', __( 'الفئات', 'car-dealer' ), __( 'الفئات', 'car-dealer' ), 'manage_car_categories', 'edit-tags.php?taxonomy=car_category&post_type=car' );

	if ( current_user_can( 'manage_options' ) ) {
		add_submenu_page( 'car-dealer-dashboard', __( 'المستخدمون والصلاحيات', 'car-dealer' ), __( 'المستخدمون والصلاحيات', 'car-dealer' ), 'manage_options', 'car-dealer-users', 'car_dealer_render_dashboard' );
	}
}
add_action( 'admin_menu', 'car_dealer_admin_menu' );

function car_dealer_prioritize_dashboard_submenu() {
	global $submenu;

	if ( empty( $submenu['car-dealer-dashboard'] ) ) {
		return;
	}

	$seen = array();
	foreach ( $submenu['car-dealer-dashboard'] as $index => $item ) {
		$slug = $item[2] ?? '';
		if ( isset( $seen[ $slug ] ) ) {
			unset( $submenu['car-dealer-dashboard'][ $index ] );
			continue;
		}
		$seen[ $slug ] = true;
	}

	usort(
		$submenu['car-dealer-dashboard'],
		function ( $a, $b ) {
			$order = array(
				'car-dealer-dashboard'       => 0,
				'car-dealer-add-car'        => 1,
				'edit.php?post_type=car'    => 2,
				'edit-tags.php?taxonomy=car_brand&post_type=car'    => 3,
				'edit-tags.php?taxonomy=car_category&post_type=car' => 4,
				'car-dealer-users'          => 90,
			);

			return ( $order[ $a[2] ] ?? 50 ) <=> ( $order[ $b[2] ] ?? 50 );
		}
	);

	$submenu['car-dealer-dashboard'] = array_values( $submenu['car-dealer-dashboard'] );
	if ( isset( $submenu['car-dealer-dashboard'][0][0] ) && 'car-dealer-dashboard' === ( $submenu['car-dealer-dashboard'][0][2] ?? '' ) ) {
		$submenu['car-dealer-dashboard'][0][0] = __( 'نظرة عامة', 'car-dealer' );
	}
}
add_action( 'admin_menu', 'car_dealer_prioritize_dashboard_submenu', 999 );

function car_dealer_admin_assets( $hook ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( false === strpos( $hook, 'car-dealer' ) && ( ! $screen || ! in_array( $screen->post_type, array( 'car', 'car_offer' ), true ) ) ) {
		return;
	}

	wp_enqueue_style( 'car-dealer-admin', get_template_directory_uri() . '/assets/css/admin-dashboard.css', array(), filemtime( get_template_directory() . '/assets/css/admin-dashboard.css' ) );
	wp_enqueue_style( 'car-dealer-workspace', get_template_directory_uri() . '/assets/css/admin-workspace.css', array( 'car-dealer-admin', 'car-dealer-white-label-admin' ), filemtime( get_template_directory() . '/assets/css/admin-workspace.css' ) );
	wp_enqueue_script( 'car-dealer-workspace', get_template_directory_uri() . '/assets/js/admin-workspace.js', array(), filemtime( get_template_directory() . '/assets/js/admin-workspace.js' ), true );

	if ( isset( $_GET['page'] ) && 'car-dealer-add-car' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', 'jQuery(function($){var frame;$(".cd-media-button").on("click",function(e){e.preventDefault();var button=$(this);if(frame){frame.open();return;}frame=wp.media({title:"اختيار صورة السيارة",button:{text:"استخدام الصورة"},multiple:false});frame.on("select",function(){var attachment=frame.state().get("selection").first().toJSON();$("#_thumbnail_id").val(attachment.id);$(".cd-media-preview").html("<img src=\"" + attachment.url + "\" alt=\"\">");button.text("تغيير الصورة");});frame.open();});$(".cd-media-remove").on("click",function(e){e.preventDefault();$("#_thumbnail_id").val("");$(".cd-media-preview").empty();$(".cd-media-button").text("اختيار صورة رئيسية");});});' );
	}
}
add_action( 'admin_enqueue_scripts', 'car_dealer_admin_assets' );

function car_dealer_car_title_placeholder( $title, $post ) {
	if ( $post && 'car' === $post->post_type ) {
		return __( 'اكتب اسم السيارة مثل: Toyota Camry 2026', 'car-dealer' );
	}

	if ( $post && 'car_offer' === $post->post_type ) {
		return __( 'اكتب عنوان العرض', 'car-dealer' );
	}

	return $title;
}
add_filter( 'enter_title_here', 'car_dealer_car_title_placeholder', 10, 2 );

function car_dealer_car_updated_messages( $messages ) {
	$messages['car'] = array(
		0  => '',
		1  => __( 'تم تحديث بيانات السيارة.', 'car-dealer' ),
		6  => __( 'تم نشر السيارة.', 'car-dealer' ),
		7  => __( 'تم حفظ السيارة.', 'car-dealer' ),
		8  => __( 'تم إرسال السيارة للمراجعة.', 'car-dealer' ),
		10 => __( 'تم حفظ مسودة السيارة.', 'car-dealer' ),
	);

	$messages['car_offer'] = array(
		0  => '',
		1  => __( 'تم تحديث العرض.', 'car-dealer' ),
		6  => __( 'تم نشر العرض.', 'car-dealer' ),
		7  => __( 'تم حفظ العرض.', 'car-dealer' ),
		10 => __( 'تم حفظ مسودة العرض.', 'car-dealer' ),
	);

	return $messages;
}
add_filter( 'post_updated_messages', 'car_dealer_car_updated_messages' );

function car_dealer_car_admin_body_class( $classes ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ( in_array( $screen->post_type, array( 'car', 'car_offer' ), true ) || false !== strpos( $screen->id, 'car-dealer' ) ) ) {
		$classes .= ' car-dealer-admin-screen';
	}
	if ( $screen && in_array( $screen->post_type, array( 'car', 'car_offer' ), true ) ) {
		$classes .= ' cd-car-editor';
	}
	return $classes;
}
add_filter( 'admin_body_class', 'car_dealer_car_admin_body_class' );

function car_dealer_dashboard_redirect() {
	if ( is_admin() && isset( $_GET['page'] ) && 'car-dealer-users' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		car_dealer_handle_user_actions();
	}
}
add_action( 'admin_init', 'car_dealer_dashboard_redirect' );

function car_dealer_handle_user_actions() {
	if ( ! current_user_can( 'manage_options' ) || empty( $_POST['car_dealer_user_action'] ) ) {
		return;
	}

	check_admin_referer( 'car_dealer_user_action' );

	$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	$role    = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
	$user    = $user_id ? get_userdata( $user_id ) : false;
	if ( $user_id === get_current_user_id() ) {
		add_settings_error( 'car_dealer_dashboard', 'own_role_unchanged', __( 'لا يمكن تغيير صلاحيتك من هذه الشاشة. اطلب من مدير آخر إجراء التغيير.', 'car-dealer' ), 'error' );
		return;
	}

	if ( $user && current_user_can( 'promote_user', $user_id ) && $user_id !== get_current_user_id() && in_array( $role, array( 'administrator', 'car_dealer_manager', 'car_dealer_sales', 'car_dealer_customer', 'subscriber' ), true ) ) {
		$user->set_role( $role );
		add_settings_error( 'car_dealer_dashboard', 'user_updated', __( 'تم تحديث صلاحية المستخدم بنجاح.', 'car-dealer' ), 'updated' );
	}
}

function car_dealer_handle_add_car_form() {
	if ( ! current_user_can( 'edit_cars' ) || empty( $_POST['car_dealer_add_car'] ) ) {
		return;
	}

	check_admin_referer( 'car_dealer_add_car' );
	$editing_id = absint( $_POST['car_id'] ?? 0 );
	if ( $editing_id && ( 'car' !== get_post_type( $editing_id ) || ! current_user_can( 'edit_post', $editing_id ) || 'trash' === get_post_status( $editing_id ) ) ) { wp_die( 'ليست لديك صلاحية لتعديل هذه السيارة.', '', array( 'response' => 403 ) ); }
	$status = sanitize_key( $_POST['post_status'] ?? 'draft' );
	if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) { $status = 'draft'; }
	if ( ! current_user_can( 'publish_cars' ) && ! in_array( $status, array( 'draft', 'pending' ), true ) ) { $status = 'pending'; }

	$title = isset( $_POST['car_title'] ) ? sanitize_text_field( wp_unslash( $_POST['car_title'] ) ) : '';
	if ( '' === $title ) {
		add_settings_error( 'car_dealer_add_car', 'missing_title', __( 'يرجى إدخال اسم السيارة قبل الحفظ.', 'car-dealer' ), 'error' );
		return;
	}

	$post_id = wp_insert_post(
		array(
			'ID'           => $editing_id,
			'post_type'    => 'car',
			'post_title'   => $title,
			'post_content' => isset( $_POST['car_description'] ) ? wp_kses_post( wp_unslash( $_POST['car_description'] ) ) : '',
			'post_status'  => $status,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		add_settings_error( 'car_dealer_add_car', 'insert_failed', $post_id->get_error_message(), 'error' );
		return;
	}

	foreach ( array_merge( car_dealer_car_text_fields(), car_dealer_car_number_fields(), car_dealer_car_select_fields() ) as $key => $field ) {
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $value );
	}

	$textarea_fields = array( '_car_financing_options', '_car_safety_features', '_car_warranty', '_car_interior_features', '_car_exterior_features' );
	foreach ( $textarea_fields as $key ) {
		$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $value );
	}

	$features = array();
	if ( ! empty( $_POST['_car_features'] ) && is_array( $_POST['_car_features'] ) ) {
		$features = array_map( 'sanitize_text_field', wp_unslash( $_POST['_car_features'] ) );
	}
	update_post_meta( $post_id, '_car_features', $features );
	update_post_meta( $post_id, '_car_is_featured', isset( $_POST['_car_is_featured'] ) ? '1' : '0' );
	update_post_meta( $post_id, '_car_is_offer', isset( $_POST['_car_is_offer'] ) ? '1' : '0' );

	if ( ! empty( $_POST['_thumbnail_id'] ) ) {
		set_post_thumbnail( $post_id, absint( $_POST['_thumbnail_id'] ) );
	} else {
		delete_post_thumbnail( $post_id );
	}

	if ( isset( $_POST['car_brand'] ) && current_user_can( 'assign_car_brands' ) ) {
		wp_set_object_terms( $post_id, array_filter( array_map( 'absint', (array) $_POST['car_brand'] ) ), 'car_brand' );
	}

	if ( isset( $_POST['car_category'] ) && current_user_can( 'assign_car_categories' ) ) {
		wp_set_object_terms( $post_id, array_filter( array_map( 'absint', (array) $_POST['car_category'] ) ), 'car_category' );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'car-dealer-add-car', 'car_id' => $post_id, 'car_saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'car_dealer_handle_add_car_form' );

function car_dealer_car_text_fields() {
	return array(
		'_car_make'       => __( 'الماركة', 'car-dealer' ),
		'_car_model'      => __( 'الموديل', 'car-dealer' ),
		'_car_color'      => __( 'اللون الخارجي', 'car-dealer' ),
		'_car_vin'        => __( 'رقم الهيكل VIN', 'car-dealer' ),
		'_car_stock'      => __( 'رقم المخزون', 'car-dealer' ),
		'_car_location'   => __( 'موقع السيارة', 'car-dealer' ),
		'_car_whatsapp'   => __( 'رقم واتساب المبيعات', 'car-dealer' ),
		'_car_drive_type' => __( 'نظام الدفع', 'car-dealer' ),
	);
}

function car_dealer_car_number_fields() {
	return array(
		'_car_year'            => __( 'سنة الصنع', 'car-dealer' ),
		'_car_price'           => __( 'السعر', 'car-dealer' ),
		'_car_monthly_payment' => __( 'القسط الشهري المتوقع', 'car-dealer' ),
		'_car_mileage'         => __( 'الممشى', 'car-dealer' ),
		'_car_engine_size'     => __( 'سعة المحرك', 'car-dealer' ),
		'_car_horsepower'      => __( 'القوة بالحصان', 'car-dealer' ),
		'_car_doors'           => __( 'عدد الأبواب', 'car-dealer' ),
		'_car_seats'           => __( 'عدد المقاعد', 'car-dealer' ),
	);
}

function car_dealer_car_select_fields() {
	return array(
		'_car_condition'    => array( 'label' => __( 'الحالة', 'car-dealer' ), 'options' => array( 'new' => __( 'جديدة', 'car-dealer' ), 'used' => __( 'مستعملة', 'car-dealer' ), 'certified' => __( 'مضمونة', 'car-dealer' ) ) ),
		'_car_fuel_type'    => array( 'label' => __( 'نوع الوقود', 'car-dealer' ), 'options' => array( 'gasoline' => __( 'بنزين', 'car-dealer' ), 'diesel' => __( 'ديزل', 'car-dealer' ), 'hybrid' => __( 'هايبرد', 'car-dealer' ), 'electric' => __( 'كهرباء', 'car-dealer' ) ) ),
		'_car_transmission' => array( 'label' => __( 'ناقل الحركة', 'car-dealer' ), 'options' => array( 'automatic' => __( 'أوتوماتيك', 'car-dealer' ), 'manual' => __( 'عادي', 'car-dealer' ), 'cvt' => __( 'CVT', 'car-dealer' ) ) ),
		'_car_body_type'    => array( 'label' => __( 'نوع السيارة', 'car-dealer' ), 'options' => array( 'sedan' => __( 'سيدان', 'car-dealer' ), 'suv' => __( 'SUV', 'car-dealer' ), 'coupe' => __( 'كوبيه', 'car-dealer' ), 'pickup' => __( 'بيك أب', 'car-dealer' ), 'van' => __( 'فان', 'car-dealer' ) ) ),
	);
}

function car_dealer_dashboard_stat( $label, $value, $icon = 'dashicons-chart-bar' ) {
	echo '<div class="cd-stat-card"><span class="dashicons ' . esc_attr( $icon ) . '"></span><strong>' . esc_html( $value ) . '</strong><p>' . esc_html( $label ) . '</p></div>';
}

function car_dealer_admin_link( $url, $label, $description, $icon = 'dashicons-admin-generic' ) {
	echo '<a class="cd-admin-card" href="' . esc_url( $url ) . '"><span aria-hidden="true" class="dashicons ' . esc_attr( $icon ) . '"></span><div><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $description ) . '</small></div></a>';
}

function car_dealer_render_add_car_page() {
	if ( ! current_user_can( 'edit_cars' ) ) {
		wp_die( esc_html__( 'ليست لديك صلاحية لإضافة السيارات.', 'car-dealer' ) );
	}

 $car_id = absint( $_POST['car_id'] ?? $_GET['car_id'] ?? 0 );
 if ( $car_id && ( 'car' !== get_post_type( $car_id ) || ! current_user_can( 'edit_post', $car_id ) || 'trash' === get_post_status( $car_id ) ) ) { wp_die( 'السيارة غير موجودة أو ليست لديك صلاحية تعديلها.', '', array( 'response' => 403 ) ); }
 $value = function ( $key ) use ( $car_id ) { return car_dealer_vehicle_form_value( $key, $car_id ); };
 $brands     = get_terms( array( 'taxonomy' => 'car_brand', 'hide_empty' => false ) );
	$categories = get_terms( array( 'taxonomy' => 'car_category', 'hide_empty' => false ) );
	$features   = array( 'فتحة سقف', 'جلد', 'كاميرا خلفية', 'حساسات', 'مثبت سرعة', 'شاشة لمس', 'بلوتوث', 'ملاحة', 'تشغيل بصمة', 'دخول ذكي', 'مقاعد كهربائية', 'تبريد مقاعد' );
	$features = array_unique( array_merge( $features, (array) $value( '_car_features' ) ) );
	?>
	<div class="wrap car-dealer-dashboard cd-add-car-page">
		<div class="cd-admin-hero cd-admin-hero-compact">
			<div>
				<span><?php esc_html_e( 'إدارة المخزون', 'car-dealer' ); ?></span>
				<h1><?php echo esc_html( $car_id ? 'تعديل السيارة' : 'إضافة سيارة جديدة' ); ?></h1>
				<p><?php esc_html_e( 'أضف المعلومات والصور والمواصفات، ثم انشر السيارة أو احفظها كمسودة لإكمالها لاحقاً.', 'car-dealer' ); ?></p>
			</div>
			<a class="button button-hero" href="<?php echo esc_url( admin_url( 'edit.php?post_type=car' ) ); ?>"><?php esc_html_e( 'عرض كل السيارات', 'car-dealer' ); ?></a>
		</div>
		<?php settings_errors( 'car_dealer_add_car' ); ?>
<?php if ( ! empty( $_GET['car_saved'] ) ) : ?><div class="notice notice-success"><p>تم حفظ بيانات السيارة بنجاح.</p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['car_added'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تمت إضافة السيارة بنجاح.', 'car-dealer' ); ?> <a href="<?php echo esc_url( get_edit_post_link( absint( $_GET['car_added'] ) ) ); ?>"><?php esc_html_e( 'تعديل السيارة', 'car-dealer' ); ?></a></p></div>
		<?php endif; ?>

		<form method="post" class="cd-add-car-form">
			<?php wp_nonce_field( 'car_dealer_add_car' ); ?>
			<input type="hidden" name="car_dealer_add_car" value="1"><input type="hidden" name="car_id" value="<?php echo absint( $car_id ); ?>">

			<div class="cd-form-grid">
				<section class="cd-form-panel cd-form-panel-main">
					<div class="cd-panel-heading">
						<span class="dashicons dashicons-car"></span>
						<div>
							<h2><?php esc_html_e( 'البيانات الأساسية', 'car-dealer' ); ?></h2>
							<p><?php esc_html_e( 'المعلومات التي تظهر أولاً للعميل في صفحة السيارة.', 'car-dealer' ); ?></p>
						</div>
					</div>
					<label><?php esc_html_e( 'اسم السيارة', 'car-dealer' ); ?><input type="text" name="car_title" value="<?php echo esc_attr( $value( 'car_title' ) ); ?>" required placeholder="<?php esc_attr_e( 'مثال: Toyota Camry 2026', 'car-dealer' ); ?>"></label>
					<label><?php esc_html_e( 'وصف السيارة', 'car-dealer' ); ?><textarea name="car_description" rows="6" placeholder="<?php esc_attr_e( 'اكتب وصفاً تسويقياً واضحاً للسيارة...', 'car-dealer' ); ?>"><?php echo esc_textarea( $value( 'car_description' ) ); ?></textarea></label>

					<div class="cd-fields-two">
						<?php foreach ( car_dealer_car_text_fields() as $key => $label ) : ?>
							<label><?php echo esc_html( $label ); ?><input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value( $key ) ); ?>"></label>
						<?php endforeach; ?>
					</div>

					<div class="cd-fields-two">
						<?php foreach ( car_dealer_car_number_fields() as $key => $label ) : ?>
							<label><?php echo esc_html( $label ); ?><input type="number" name="<?php echo esc_attr( $key ); ?>" step="any" value="<?php echo esc_attr( $value( $key ) ); ?>"></label>
						<?php endforeach; ?>
					</div>
				</section>

				<aside class="cd-form-panel">
					<div class="cd-panel-heading">
						<span class="dashicons dashicons-admin-settings"></span>
						<div>
							<h2><?php esc_html_e( 'النشر والتصنيف', 'car-dealer' ); ?></h2>
							<p><?php esc_html_e( 'التحكم في ظهور السيارة ومكانها داخل المعرض.', 'car-dealer' ); ?></p>
						</div>
					</div>
					<label><?php esc_html_e( 'حالة النشر', 'car-dealer' ); ?><select name="post_status"><option value="publish" <?php selected( $value( 'post_status' ), 'publish' ); ?>><?php esc_html_e( 'نشر مباشر', 'car-dealer' ); ?></option><option value="draft" <?php selected( $value( 'post_status' ), 'draft' ); ?>><?php esc_html_e( 'حفظ كمسودة', 'car-dealer' ); ?></option><?php foreach ( array( 'pending' => 'بانتظار المراجعة', 'private' => 'خاص', 'future' => 'مجدول' ) as $state => $label ) : if ( $state !== 'pending' && $value( 'post_status' ) !== $state ) { continue; } ?><option value="<?php echo esc_attr( $state ); ?>" <?php selected( $value( 'post_status' ), $state ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'الماركة', 'car-dealer' ); ?><input type="hidden" name="car_brand[]" value=""><select name="car_brand[]" multiple><option value=""><?php esc_html_e( 'اختر الماركة', 'car-dealer' ); ?></option><?php foreach ( $brands as $brand ) : ?><option value="<?php echo esc_attr( $brand->term_id ); ?>" <?php selected( in_array( $brand->term_id, array_map( 'absint', (array) $value( 'car_brand' ) ), true ) ); ?>><?php echo esc_html( $brand->name ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'الفئة', 'car-dealer' ); ?><input type="hidden" name="car_category[]" value=""><select name="car_category[]" multiple><option value=""><?php esc_html_e( 'اختر الفئة', 'car-dealer' ); ?></option><?php foreach ( $categories as $category ) : ?><option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( $category->term_id, array_map( 'absint', (array) $value( 'car_category' ) ), true ) ); ?>><?php echo esc_html( $category->name ); ?></option><?php endforeach; ?></select></label>

					<div class="cd-media-field">
						<strong><?php esc_html_e( 'الصورة الرئيسية', 'car-dealer' ); ?></strong>
						<div class="cd-media-preview"><?php echo wp_get_attachment_image( absint( $value( '_thumbnail_id' ) ), 'medium' ); ?></div>
						<input type="hidden" id="_thumbnail_id" name="_thumbnail_id" value="<?php echo esc_attr( $value( '_thumbnail_id' ) ); ?>">
						<button type="button" class="button cd-media-button"><?php esc_html_e( 'اختيار صورة رئيسية', 'car-dealer' ); ?></button>
						<button type="button" class="button-link-delete cd-media-remove"><?php esc_html_e( 'إزالة الصورة', 'car-dealer' ); ?></button>
					</div>

					<label class="cd-check"><input type="checkbox" name="_car_is_featured" value="1" <?php checked( $value( '_car_is_featured' ), '1' ); ?>> <?php esc_html_e( 'سيارة مميزة', 'car-dealer' ); ?></label>
					<label class="cd-check"><input type="checkbox" name="_car_is_offer" value="1" <?php checked( $value( '_car_is_offer' ), '1' ); ?>> <?php esc_html_e( 'ضمن العروض الحالية', 'car-dealer' ); ?></label>
				</aside>
			</div>

			<section class="cd-form-panel">
				<div class="cd-panel-heading">
					<span class="dashicons dashicons-clipboard"></span>
					<div>
						<h2><?php esc_html_e( 'المواصفات التفصيلية', 'car-dealer' ); ?></h2>
						<p><?php esc_html_e( 'تفاصيل تساعد فريق المبيعات والعميل على اتخاذ القرار بسرعة.', 'car-dealer' ); ?></p>
					</div>
				</div>
				<div class="cd-fields-two">
					<?php foreach ( car_dealer_car_select_fields() as $key => $field ) : ?>
						<label><?php echo esc_html( $field['label'] ); ?><select name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value( $key ) ); ?>"><option value=""><?php esc_html_e( 'اختر', 'car-dealer' ); ?></option><?php foreach ( $field['options'] as $option_value => $label ) : ?><option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value( $key ), $option_value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<?php endforeach; ?>
				</div>
				<div class="cd-feature-list">
					<?php foreach ( $features as $feature ) : ?>
						<label><input type="checkbox" name="_car_features[]" value="<?php echo esc_attr( $feature ); ?>" <?php checked( in_array( $feature, (array) $value( '_car_features' ), true ) ); ?>> <?php echo esc_html( $feature ); ?></label>
					<?php endforeach; ?>
				</div>
				<div class="cd-fields-two">
					<label><?php esc_html_e( 'خيارات التمويل', 'car-dealer' ); ?><textarea name="_car_financing_options" rows="4"><?php echo esc_textarea( $value( '_car_financing_options' ) ); ?></textarea></label>
					<label><?php esc_html_e( 'أنظمة السلامة', 'car-dealer' ); ?><textarea name="_car_safety_features" rows="4"><?php echo esc_textarea( $value( '_car_safety_features' ) ); ?></textarea></label>
					<label><?php esc_html_e( 'الضمان', 'car-dealer' ); ?><textarea name="_car_warranty" rows="4"><?php echo esc_textarea( $value( '_car_warranty' ) ); ?></textarea></label>
					<label><?php esc_html_e( 'المواصفات الداخلية', 'car-dealer' ); ?><textarea name="_car_interior_features" rows="4"><?php echo esc_textarea( $value( '_car_interior_features' ) ); ?></textarea></label>
					<label><?php esc_html_e( 'المواصفات الخارجية', 'car-dealer' ); ?><textarea name="_car_exterior_features" rows="4"><?php echo esc_textarea( $value( '_car_exterior_features' ) ); ?></textarea></label>
				</div>
			</section>

			<p class="submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'حفظ السيارة', 'car-dealer' ); ?></button></p>
		</form>
	</div>
	<?php
}

function car_dealer_render_admin_hub() {
	$total_cars    = wp_count_posts( 'car' );
	$published     = isset( $total_cars->publish ) ? (int) $total_cars->publish : 0;
	$drafts        = isset( $total_cars->draft ) ? (int) $total_cars->draft : 0;
	$total_offers  = wp_count_posts( 'car_offer' );
	$offers        = isset( $total_offers->publish ) ? (int) $total_offers->publish : 0;
	$users_count   = count_users();
	$users_total   = isset( $users_count['total_users'] ) ? (int) $users_count['total_users'] : 0;
	?>
	<div class="wrap car-dealer-dashboard">
		<div class="cd-admin-hero">
			<div>
				<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
				<h1><?php esc_html_e( 'لوحة إدارة المعرض', 'car-dealer' ); ?></h1>
				<p><?php esc_html_e( 'نظرة تشغيلية سريعة لإدارة السيارات، العروض، العملاء، الرسائل، والمستخدمين من مكان واحد.', 'car-dealer' ); ?></p>
			</div>
			<div class="cd-hero-actions">
				<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=car-dealer-add-car' ) ); ?>"><?php esc_html_e( 'إضافة سيارة', 'car-dealer' ); ?></a>
				<a class="button button-ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'زيارة الموقع', 'car-dealer' ); ?></a>
			</div>
		</div>

		<div class="cd-stats-grid">
			<?php
			car_dealer_dashboard_stat( __( 'سيارات منشورة', 'car-dealer' ), $published, 'dashicons-car' );
			car_dealer_dashboard_stat( __( 'مسودات السيارات', 'car-dealer' ), $drafts, 'dashicons-edit' );
			car_dealer_dashboard_stat( __( 'عروض نشطة', 'car-dealer' ), $offers, 'dashicons-megaphone' );
			car_dealer_dashboard_stat( __( 'مستخدمون', 'car-dealer' ), $users_total, 'dashicons-groups' );
			?>
		</div>

		<div class="cd-section-title">
			<span><?php esc_html_e( 'مركز التحكم', 'car-dealer' ); ?></span>
			<h2><?php esc_html_e( 'إدارة سريعة', 'car-dealer' ); ?></h2>
		</div>
		<div class="cd-admin-grid">
			<?php
			car_dealer_admin_link( admin_url( 'admin.php?page=car-dealer-add-car' ), __( 'إضافة سيارة', 'car-dealer' ), __( 'نموذج احترافي شامل لبيانات السيارة.', 'car-dealer' ), 'dashicons-plus-alt2' );
			car_dealer_admin_link( admin_url( 'edit.php?post_type=car' ), __( 'كل السيارات', 'car-dealer' ), __( 'إدارة مخزون السيارات المنشورة والمسودات.', 'car-dealer' ), 'dashicons-car' );
			if ( current_user_can( 'edit_posts' ) ) {
				car_dealer_admin_link( admin_url( 'edit.php?post_type=car_offer' ), __( 'العروض', 'car-dealer' ), __( 'إدارة عروض التمويل والخصومات.', 'car-dealer' ), 'dashicons-megaphone' );
			}
			car_dealer_admin_link( admin_url( 'admin.php?page=car-dealer-messages' ), __( 'الرسائل والعملاء', 'car-dealer' ), __( 'متابعة العملاء المحتملين وطلبات التواصل.', 'car-dealer' ), 'dashicons-email-alt2' );
			car_dealer_admin_link( admin_url( 'admin.php?page=car-dealer-bookings' ), __( 'تجارب القيادة', 'car-dealer' ), __( 'متابعة حجوزات العملاء ومواعيد التجربة.', 'car-dealer' ), 'dashicons-calendar-alt' );
			if ( current_user_can( 'manage_options' ) ) {
				car_dealer_admin_link( admin_url( 'admin.php?page=car-dealer-users' ), __( 'المستخدمون والصلاحيات', 'car-dealer' ), __( 'إدارة أدوار فريق الإدارة والمبيعات.', 'car-dealer' ), 'dashicons-admin-users' );
			}
			?>
		</div>
		<?php car_dealer_crm_summary(); ?>
		<?php car_dealer_workspace_recent_cars(); ?>
	</div>
	<?php
}

function car_dealer_render_dashboard() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'car-dealer-dashboard';

	if ( 'car-dealer-users' === $page ) {
		car_dealer_render_users_panel();
		return;
	}

	car_dealer_render_admin_hub();
}

function car_dealer_render_users_panel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'ليست لديك صلاحية لإدارة المستخدمين.', 'car-dealer' ) );
	}

	$users = get_users( array( 'fields' => 'all' ) );
	$roles = array(
		'administrator'       => __( 'مدير الموقع', 'car-dealer' ),
		'car_dealer_manager'  => __( 'مدير المعرض', 'car-dealer' ),
		'car_dealer_sales'    => __( 'مستشار مبيعات', 'car-dealer' ),
		'car_dealer_customer' => __( 'عميل المعرض', 'car-dealer' ),
		'subscriber'          => __( 'مشترك', 'car-dealer' ),
	);
	?>
	<div class="wrap car-dealer-dashboard">
		<div class="cd-admin-hero cd-admin-hero-compact">
			<div>
				<span><?php esc_html_e( 'إدارة الفريق', 'car-dealer' ); ?></span>
				<h1><?php esc_html_e( 'المستخدمون والصلاحيات', 'car-dealer' ); ?></h1>
				<p><?php esc_html_e( 'تحكم في صلاحيات فريق الإدارة والمبيعات بدون تعديل ملفات ووردبريس الأساسية.', 'car-dealer' ); ?></p>
			</div>
			<a class="button button-hero" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php esc_html_e( 'إضافة مستخدم', 'car-dealer' ); ?></a>
		</div>
		<?php settings_errors( 'car_dealer_dashboard' ); ?>

		<div class="cd-table-card">
		<table class="widefat striped cd-users-table">
			<thead><tr><th><?php esc_html_e( 'المستخدم', 'car-dealer' ); ?></th><th><?php esc_html_e( 'البريد الإلكتروني', 'car-dealer' ); ?></th><th><?php esc_html_e( 'الدور الحالي', 'car-dealer' ); ?></th><th><?php esc_html_e( 'تغيير الصلاحية', 'car-dealer' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $users as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user->display_name ); ?></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( function ( $role ) use ( $roles ) { return $roles[ $role ] ?? $role; }, $user->roles ) ) ); ?></td>
						<td>
							<form method="post" class="cd-inline-form">
								<?php wp_nonce_field( 'car_dealer_user_action' ); ?>
								<input type="hidden" name="car_dealer_user_action" value="1">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
								<select name="role" aria-label="<?php echo esc_attr( 'صلاحية ' . $user->display_name ); ?>">
									<?php foreach ( $roles as $role_key => $role_label ) : ?>
										<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( in_array( $role_key, $user->roles, true ) ); ?>><?php echo esc_html( $role_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button"><?php esc_html_e( 'حفظ', 'car-dealer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	</div>
	<?php
}
