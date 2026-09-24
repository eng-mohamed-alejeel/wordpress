<?php
/**
 * مدير المحتوى الديناميكي للقالب
 *
 * @package Car Dealer
 * @subpackage Dynamic Content
 * @since Car Dealer 1.0
 */

// إضافة الدعم للصور المصغرة
add_theme_support('post-thumbnails');

// إضافة حجم مخصص للصور
add_image_size('car-thumbnail', 400, 250, true);
add_image_size('car-medium', 800, 500, true);
add_image_size('car-large', 1200, 800, true);

// تسجيل القوائم
function car_dealer_register_menus() {
    register_nav_menus(array(
        'primary' => __('القائمة الرئيسية', 'car-dealer'),
        'footer' => __('القائمة التذييلية', 'car-dealer'),
    ));
}
add_action('init', 'car_dealer_register_menus');

// إنشاء أنواع المقالات المخصصة
function car_dealer_custom_post_types() {
    // نوع المقالة: السيارات
    register_post_type('car',
        array(
            'labels' => array(
                'name' => __('السيارات', 'car-dealer'),
                'singular_name' => __('سيارة', 'car-dealer'),
                'add_new_item' => __('إضافة سيارة جديدة', 'car-dealer'),
                'edit_item' => __('تعديل السيارة', 'car-dealer'),
                'new_item' => __('سيارة جديدة', 'car-dealer'),
                'view_item' => __('عرض السيارة', 'car-dealer'),
                'search_items' => __('بحث عن سيارات', 'car-dealer'),
                'not_found' => __('لم يتم العثور على سيارات', 'car-dealer'),
                'not_found_in_trash' => __('لم يتم العثور على سيارات في المهملات', 'car-dealer'),
            ),
            'public' => true,
            'has_archive' => true,
            'menu_icon' => 'dashicons-car',
            'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
            'taxonomies' => array('car_category', 'car_brand'),
            'rewrite' => array('slug' => 'cars'),
        )
    );

    // نوع المقالة: الشهادات
    register_post_type('testimonial',
        array(
            'labels' => array(
                'name' => __('شهادات العملاء', 'car-dealer'),
                'singular_name' => __('شهادة', 'car-dealer'),
                'add_new_item' => __('إضافة شهادة جديدة', 'car-dealer'),
                'edit_item' => __('تعديل الشهادة', 'car-dealer'),
                'new_item' => __('شهادة جديدة', 'car-dealer'),
                'view_item' => __('عرض الشهادة', 'car-dealer'),
                'search_items' => __('البحث عن شهادات', 'car-dealer'),
                'not_found' => __('لم يتم العثور على شهادات', 'car-dealer'),
                'not_found_in_trash' => __('لم يتم العثور على شهادات في المهملات', 'car-dealer'),
            ),
            'public' => true,
            'has_archive' => true,
            'menu_icon' => 'dashicons-testimonial',
            'supports' => array('title', 'editor', 'thumbnail'),
            'rewrite' => array('slug' => 'testimonials'),
        )
    );
}
add_action('init', 'car_dealer_custom_post_types');

// إنشاء التصنيفات المخصصة
function car_dealer_custom_taxonomies() {
    // التصنيف: فئات السيارات
    register_taxonomy(
        'car_category',
        'car',
        array(
            'labels' => array(
                'name' => __('فئات السيارات', 'car-dealer'),
                'singular_name' => __('فئة سيارات', 'car-dealer'),
                'search_items' => __('بحث عن فئات', 'car-dealer'),
                'all_items' => __('كل الفئات', 'car-dealer'),
                'parent_item' => __('فئة سيارة رئيسية', 'car-dealer'),
                'parent_item_colon' => __('فئة سيارة رئيسية:', 'car-dealer'),
                'edit_item' => __('تعديل الفئة', 'car-dealer'),
                'update_item' => __('تحديث الفئة', 'car-dealer'),
                'add_new_item' => __('إضافة فئة جديدة', 'car-dealer'),
                'new_item_name' => __('اسم الفئة الجديدة', 'car-dealer'),
                'menu_name' => __('فئات السيارات', 'car-dealer'),
            ),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'car-category'),
        )
    );

    // التصنيف: ماركات السيارات
    register_taxonomy(
        'car_brand',
        'car',
        array(
            'labels' => array(
                'name' => __('ماركات السيارات', 'car-dealer'),
                'singular_name' => __('ماركة سيارات', 'car-dealer'),
                'search_items' => __('بحث عن ماركات', 'car-dealer'),
                'all_items' => __('كل الماركات', 'car-dealer'),
                'parent_item' => __('ماركة سيارات رئيسية', 'car-dealer'),
                'parent_item_colon' => __('ماركة سيارات رئيسية:', 'car-dealer'),
                'edit_item' => __('تعديل الماركة', 'car-dealer'),
                'update_item' => __('تحديث الماركة', 'car-dealer'),
                'add_new_item' => __('إضافة ماركة جديدة', 'car-dealer'),
                'new_item_name' => __('اسم الماركة الجديدة', 'car-dealer'),
                'menu_name' => __('ماركات السيارات', 'car-dealer'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'car-brand'),
        )
    );
}
add_action('init', 'car_dealer_custom_taxonomies');

// إضافة الحقول المخصصة للسيارات
function car_dealer_custom_fields() {
    // إضافة الحقول المخصصة للسيارات
    add_meta_box(
        'car_details',
        'تفاصيل السيارة',
        'car_dealer_car_details_callback',
        'car',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'car_dealer_custom_fields');

// دالة رد الاتصال لعرض حقول السيارات المخصصة
function car_dealer_car_details_callback($post) {
    // الحصول على القيم الحالية للحقول المخصصة
    $price = get_post_meta($post->ID, '_car_price', true);
    $year = get_post_meta($post->ID, '_car_year', true);
    $kilometers = get_post_meta($post->ID, '_car_kilometers', true);
    $transmission = get_post_meta($post->ID, '_car_transmission', true);
    $fuel_type = get_post_meta($post->ID, '_car_fuel_type', true);
    $features = get_post_meta($post->ID, '_car_features', true);

    // إضافة حقم nonce للتحقق من الأمان
    wp_nonce_field('car_dealer_save_car_details', 'car_dealer_nonce');

    // عرض حقول الإدخال
    echo '<table class="form-table">';
    echo '<tbody>';
    echo '<tr>';
    echo '<th><label for="_car_price">السعر (ريال)</label></th>';
    echo '<td><input type="number" id="_car_price" name="_car_price" value="' . esc_attr($price) . '" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="_car_year">سنة التصنيع</label></th>';
    echo '<td><input type="number" id="_car_year" name="_car_year" value="' . esc_attr($year) . '" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="_car_kilometers">المسافة المقطوعة (كم)</label></th>';
    echo '<td><input type="number" id="_car_kilometers" name="_car_kilometers" value="' . esc_attr($kilometers) . '" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="_car_transmission">ناقل الحركة</label></th>';
    echo '<td>';
    echo '<select id="_car_transmission" name="_car_transmission">';
    echo '<option value="manual" ' . selected($transmission, 'manual', false) . '>يدوي</option>';
    echo '<option value="automatic" ' . selected($transmission, 'automatic', false) . '>أوتوماتيكي</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="_car_fuel_type">نوع الوقود</label></th>';
    echo '<td>';
    echo '<select id="_car_fuel_type" name="_car_fuel_type">';
    echo '<option value="gasoline" ' . selected($fuel_type, 'gasoline', false) . '>بنزين</option>';
    echo '<option value="diesel" ' . selected($fuel_type, 'diesel', false) . '>ديزل</option>';
    echo '<option value="hybrid" ' . selected($fuel_type, 'hybrid', false) . '>هجين</option>';
    echo '<option value="electric" ' . selected($fuel_type, 'electric', false) . '>كهربائي</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="_car_features">المميزات (فاصل سطر لكل مميزة)</label></th>';
    echo '<td>';
    echo '<textarea id="_car_features" name="_car_features" rows="5" class="large-text">' . esc_textarea($features) . '</textarea>';
    echo '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
}

// حفظ حقول السيارات المخصصة
function car_dealer_save_car_details($post_id) {
    // التحقق من nonce
    if (!isset($_POST['car_dealer_nonce']) || !wp_verify_nonce($_POST['car_dealer_nonce'], 'car_dealer_save_car_details')) {
        return;
    }

    // التحقق من المستخدم لديه الصلاحيات
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // حفظ القيم
    if (isset($_POST['_car_price'])) {
        update_post_meta($post_id, '_car_price', sanitize_text_field($_POST['_car_price']));
    }

    if (isset($_POST['_car_year'])) {
        update_post_meta($post_id, '_car_year', sanitize_text_field($_POST['_car_year']));
    }

    if (isset($_POST['_car_kilometers'])) {
        update_post_meta($post_id, '_car_kilometers', sanitize_text_field($_POST['_car_kilometers']));
    }

    if (isset($_POST['_car_transmission'])) {
        update_post_meta($post_id, '_car_transmission', sanitize_text_field($_POST['_car_transmission']));
    }

    if (isset($_POST['_car_fuel_type'])) {
        update_post_meta($post_id, '_car_fuel_type', sanitize_text_field($_POST['_car_fuel_type']));
    }

    if (isset($_POST['_car_features'])) {
        update_post_meta($post_id, '_car_features', sanitize_textarea_field($_POST['_car_features']));
    }
}
add_action('save_post', 'car_dealer_save_car_details');

// الحصول على السيارات المميزة
function car_dealer_get_featured_cars($count = 3) {
    $args = array(
        'post_type' => 'car',
        'posts_per_page' => $count,
        'meta_key' => '_featured',
        'meta_value' => '1',
    );

    $query = new WP_Query($args);
    return $query;
}

// الحصول على أحدث السيارات
function car_dealer_get_latest_cars($count = 6) {
    $args = array(
        'post_type' => 'car',
        'posts_per_page' => $count,
        'orderby' => 'date',
        'order' => 'DESC',
    );

    $query = new WP_Query($args);
    return $query;
}

// الحصول على السيارات حسب الفئة
function car_dealer_get_cars_by_category($category_slug, $count = 6) {
    $args = array(
        'post_type' => 'car',
        'posts_per_page' => $count,
        'tax_query' => array(
            array(
                'taxonomy' => 'car_category',
                'field' => 'slug',
                'terms' => $category_slug,
            ),
        ),
    );

    $query = new WP_Query($args);
    return $query;
}

// الحصول على شهادات العملاء
function car_dealer_get_testimonials($count = 3) {
    $args = array(
        'post_type' => 'testimonial',
        'posts_per_page' => $count,
        'orderby' => 'date',
        'order' => 'DESC',
    );

    $query = new WP_Query($args);
    return $query;
}

// إضافة الأيقونات اللازمة
function car_dealer_enqueue_icons() {
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css', array(), '5.15.3');
}
add_action('wp_enqueue_scripts', 'car_dealer_enqueue_icons');

// إضافة الأنماط والسكريبتات
function car_dealer_enqueue_scripts() {
    // إضافة النمط الرئيسي
    wp_enqueue_style(
        'car-dealer-style',
        get_stylesheet_uri(),
        array(),
        wp_get_theme()->get('Version')
    );

    // إضافة الخطوط العربية من Google Fonts
    wp_enqueue_style(
        'tajawal-font',
        'https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap',
        array(),
        null
    );

    // إضافة ملف JavaScript
    wp_enqueue_script(
        'car-dealer-script',
        get_template_directory_uri() . '/js/main.js',
        array('jquery'),
        wp_get_theme()->get('Version'),
        true
    );

    // إضافة متغيرات JavaScript للقالب
    wp_localize_script('car-dealer-script', 'carDealer', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('car_dealer_nonce'),
    ));
}
add_action('wp_enqueue_scripts', 'car_dealer_enqueue_scripts');

// إضافة دعم للـ widgets
function car_dealer_widgets_init() {
    register_sidebar(array(
        'name' => 'الشريط الجانبي الرئيسي',
        'id' => 'main-sidebar',
        'description' => 'الشريط الجانبي الرئيسي في الصفحات',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget' => '</div>',
        'before_title' => '<h3 class="widget-title">',
        'after_title' => '</h3>',
    ));

    register_sidebar(array(
        'name' => 'الشريط الجانبي في صفحة السيارات',
        'id' => 'cars-sidebar',
        'description' => 'الشريط الجانبي في صفحة أرشيف السيارات',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget' => '</div>',
        'before_title' => '<h3 class="widget-title">',
        'after_title' => '</h3>',
    ));
}
add_action('widgets_init', 'car_dealer_widgets_init');

// معالج AJAX لحجز تجربة القيادة
function car_dealer_handle_test_drive_booking() {
    check_ajax_referer('car_dealer_nonce', 'nonce');

    $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';

    if ($car_id && $name && $phone && $email && $date && $time) {
        global $wpdb;
        $table = $wpdb->prefix . 'car_dealer_bookings';

        $result = $wpdb->insert(
            $table,
            array(
                'user_id' => get_current_user_id(),
                'car_id' => $car_id,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'date' => $date,
                'time' => $time,
                'status' => 'pending'
            )
        );

        if ($result) {
            wp_send_json_success(array('message' => 'تم حجز تجربة القيادة بنجاح! سيتواصل معكم قريباً.'));
        }
    }

    wp_send_json_error(array('message' => 'حدث خطأ أثناء الحجز. يرجى المحاولة مرة أخرى.'));
}
add_action('wp_ajax_car_dealer_book_test_drive', 'car_dealer_handle_test_drive_booking');
add_action('wp_ajax_nopriv_car_dealer_book_test_drive', 'car_dealer_handle_test_drive_booking');

// معالج AJAX لإضافة السيارة إلى المقارنة
function car_dealer_handle_add_to_comparison() {
    check_ajax_referer('car_dealer_nonce', 'nonce');

    $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;

    if ($car_id) {
        $result = car_dealer_add_to_comparison($car_id);

        if ($result) {
            wp_send_json_success(array('message' => 'تمت إضافة السيارة إلى قائمة المقارنة!'));
        }
    }

    wp_send_json_error(array('message' => 'حدث خطأ أثناء إضافة السيارة إلى المقارنة.'));
}
add_action('wp_ajax_car_dealer_add_to_comparison', 'car_dealer_handle_add_to_comparison');
add_action('wp_ajax_nopriv_car_dealer_add_to_comparison', 'car_dealer_handle_add_to_comparison');

// معالج AJAX لإزالة السيارة من المقارنة
function car_dealer_handle_remove_from_comparison() {
    check_ajax_referer('car_dealer_nonce', 'nonce');

    $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;

    if ($car_id) {
        $result = car_dealer_remove_from_comparison($car_id);

        if ($result) {
            wp_send_json_success(array('message' => 'تمت إزالة السيارة من قائمة المقارنة!'));
        }
    }

    wp_send_json_error(array('message' => 'حدث خطأ أثناء إزالة السيارة من المقارنة.'));
}
add_action('wp_ajax_car_dealer_remove_from_comparison', 'car_dealer_handle_remove_from_comparison');
add_action('wp_ajax_nopriv_car_dealer_remove_from_comparison', 'car_dealer_handle_remove_from_comparison');

// معالج AJAX لإرسال نموذج الاتصال
function car_dealer_handle_contact_form() {
    check_ajax_referer('car_dealer_nonce', 'nonce');

    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

    if ($name && $email && $phone && $message) {
        // إرسال الإيميل
        $to = get_option('contact_email', 'info@cardealer.com');
        $subject = 'رسالة جديدة من موقع معرض السيارات';
        $body = "الاسم: $name
";
        $body .= "البريد الإلكتروني: $email
";
        $body .= "رقم الهاتف: $phone

";
        $body .= "الرسالة:
$message";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        if (wp_mail($to, $subject, $body, $headers)) {
            wp_send_json_success(array('message' => 'تم إرسال رسالتك بنجاح! سيتواصل معكم قريباً.'));
        }
    }

    wp_send_json_error(array('message' => 'حدث خطأ أثناء إرسال الرسالة. يرجى المحاولة مرة أخرى.'));
}
add_action('wp_ajax_car_dealer_send_contact_form', 'car_dealer_handle_contact_form');
add_action('wp_ajax_nopriv_car_dealer_send_contact_form', 'car_dealer_handle_contact_form');

// إضافة دعم للـ shortcodes
function car_dealer_featured_cars_shortcode($atts) {
    $atts = shortcode_atts(array(
        'count' => 3,
    ), $atts);

    $query = car_dealer_get_featured_cars($atts['count']);
    ob_start();

    if ($query->have_posts()) {
        echo '<div class="featured-cars">';
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('template-parts/content', 'car');
        }
        echo '</div>';
    }

    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('featured_cars', 'car_dealer_featured_cars_shortcode');

function car_dealer_latest_cars_shortcode($atts) {
    $atts = shortcode_atts(array(
        'count' => 6,
    ), $atts);

    $query = car_dealer_get_latest_cars($atts['count']);
    ob_start();

    if ($query->have_posts()) {
        echo '<div class="latest-cars">';
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('template-parts/content', 'car');
        }
        echo '</div>';
    }

    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('latest_cars', 'car_dealer_latest_cars_shortcode');

function car_dealer_testimonials_shortcode($atts) {
    $atts = shortcode_atts(array(
        'count' => 3,
    ), $atts);

    $query = car_dealer_get_testimonials($atts['count']);
    ob_start();

    if ($query->have_posts()) {
        echo '<div class="testimonials">';
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('template-parts/content', 'testimonial');
        }
        echo '</div>';
    }

    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('testimonials', 'car_dealer_testimonials_shortcode');

// إنشاء قوالب الأجزاء (Template Parts)
function car_dealer_template_parts_init() {
    // إنشاء مجلد القوالب إذا لم يكن موجوداً
    $template_parts_dir = get_template_directory() . '/template-parts';
    if (!file_exists($template_parts_dir)) {
        mkdir($template_parts_dir, 0755, true);
    }

    // إنشاء قالب محتوى السيارة
    $car_template = $template_parts_dir . '/content-car.php';
    if (!file_exists($car_template)) {
        $car_content = <<<'CAR_TEMPLATE'
<?php
/**
 * قالب محتوى السيارة
 *
 * @package Car Dealer
 * @subpackage Template Parts
 * @since Car Dealer 1.0
 */

global $post;

// الحصول على البيانات المخصصة للسيارة
$price = get_post_meta(get_the_ID(), '_car_price', true);
$year = get_post_meta(get_the_ID(), '_car_year', true);
$kilometers = get_post_meta(get_the_ID(), '_car_kilometers', true);
$transmission = get_post_meta(get_the_ID(), '_car_transmission', true);
$fuel_type = get_post_meta(get_the_ID(), '_car_fuel_type', true);
$features = get_post_meta(get_the_ID(), '_car_features', true);

// الحصول على الفئات والماركات
$categories = get_the_terms(get_the_ID(), 'car_category');
$brands = get_the_terms(get_the_ID(), 'car_brand');
$category_slug = !empty($categories) ? $categories[0]->slug : '';
?>
<div class="car-card" data-category="<?php echo esc_attr($category_slug); ?>">
    <div class="car-image">
        <?php if (has_post_thumbnail()) : ?>
            <?php the_post_thumbnail('full'); ?>
        <?php else : ?>
            <img src="https://via.placeholder.com/400x250?text=صورة+السيارة" alt="<?php the_title_attribute(); ?>">
        <?php endif; ?>
        <?php if (!empty($price)) : ?>
            <span class="car-badge">سعر: <?php echo esc_html($price); ?> ريال</span>
        <?php endif; ?>
    </div>
    <div class="car-details">
        <h3 class="car-title"><?php the_title(); ?></h3>
        <?php if (!empty($brands)) : ?>
            <p class="car-brand"><?php echo esc_html($brands[0]->name); ?></p>
        <?php endif; ?>
        <?php if (!empty($price)) : ?>
            <p class="car-price"><?php echo esc_html($price); ?> ريال</p>
        <?php endif; ?>
        <div class="car-specs">
            <?php if (!empty($year)) : ?>
                <span>سنة: <?php echo esc_html($year); ?></span>
            <?php endif; ?>
            <?php if (!empty($kilometers)) : ?>
                <span>كم: <?php echo esc_html($kilometers); ?></span>
            <?php endif; ?>
        </div>
        <div class="car-specs">
            <?php if (!empty($transmission)) : ?>
                <span>ناقل: <?php echo esc_html($transmission === 'manual' ? 'يدوي' : 'أوتوماتيكي'); ?></span>
            <?php endif; ?>
            <?php if (!empty($fuel_type)) : ?>
                <span>وقود: <?php echo esc_html($fuel_type); ?></span>
            <?php endif; ?>
        </div>
        <div class="car-actions">
            <a href="<?php the_permalink(); ?>" class="btn">عرض التفاصيل</a>
            <?php if (is_user_logged_in()) : ?>
                <button class="btn btn-outline add-to-comparison" data-car-id="<?php the_ID(); ?>">
                    <i class="fas fa-balance-scale"></i> مقارنة
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
CAR_TEMPLATE;
        file_put_contents($car_template, $car_content);
    }

    // إنشاء قالب محتوى الشهادة
    $testimonial_template = $template_parts_dir . '/content-testimonial.php';
    if (!file_exists($testimonial_template)) {
        $testimonial_content = <<<'TESTIMONIAL_TEMPLATE'
<?php
/**
 * قالب محتوى الشهادة
 *
 * @package Car Dealer
 * @subpackage Template Parts
 * @since Car Dealer 1.0
 */

global $post;
?>
<div class="testimonial-card">
    <div class="testimonial-content">
        <p class="testimonial-text">"<?php the_content(); ?>"</p>
        <div class="testimonial-author">
            <div class="testimonial-avatar">
                <?php if (has_post_thumbnail()) : ?>
                    <?php the_post_thumbnail('thumbnail'); ?>
                <?php else : ?>
                    <div class="avatar-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="testimonial-info">
                <h4 class="testimonial-name"><?php the_title(); ?></h4>
                <p class="testimonial-position"><?php echo esc_html(get_post_meta(get_the_ID(), '_testimonial_position', true)); ?></p>
            </div>
        </div>
    </div>
</div>
TESTIMONIAL_TEMPLATE;
        file_put_contents($testimonial_template, $testimonial_content);
    }
}
add_action('after_setup_theme', 'car_dealer_template_parts_init');
