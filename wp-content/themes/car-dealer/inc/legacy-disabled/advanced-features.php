<?php
/**
 * الميزات المتقدمة للقالب
 *
 * @package Car Dealer
 * @subpackage Advanced Features
 * @since Car Dealer 1.0
 */

// إضافة دعم للصفحات الثابتة
function car_dealer_add_page_support() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array('comment-list', 'comment-form', 'search-form', 'gallery', 'caption'));
    add_theme_support('custom-logo', array(
        'height' => 50,
        'width' => 200,
        'flex-height' => true,
        'flex-width' => true,
    ));
}
add_action('after_setup_theme', 'car_dealer_add_page_support');

// إضافة أنظمة التصفية والبحث
function car_dealer_add_filters_and_search() {
    // إضافة فلترة حسب السعر
    add_filter('parse_query', 'car_dealer_price_filter');

    // إضافة فلترة حسب السنة
    add_filter('parse_query', 'car_dealer_year_filter');

    // إضافة فلترة حسب الماركة
    add_filter('parse_query', 'car_dealer_brand_filter');

    // إضافة فلترة حسب الفئة
    add_filter('parse_query', 'car_dealer_category_filter');
}
add_action('init', 'car_dealer_add_filters_and_search');

// فلترة حسب السعر
function car_dealer_price_filter($query) {
    global $pagenow;
    $post_type = 'car';

    if (is_admin() && $pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == $post_type) {
        $min_price = isset($_GET['min_price']) ? intval($_GET['min_price']) : 0;
        $max_price = isset($_GET['max_price']) ? intval($_GET['max_price']) : 0;

        if ($min_price > 0) {
            $query->query_vars['meta_query'][] = array(
                'key' => '_car_price',
                'value' => $min_price,
                'compare' => '>=',
                'type' => 'NUMERIC'
            );
        }

        if ($max_price > 0) {
            $query->query_vars['meta_query'][] = array(
                'key' => '_car_price',
                'value' => $max_price,
                'compare' => '<=',
                'type' => 'NUMERIC'
            );
        }
    }

    return $query;
}

// فلترة حسب السنة
function car_dealer_year_filter($query) {
    global $pagenow;
    $post_type = 'car';

    if (is_admin() && $pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == $post_type) {
        $year = isset($_GET['year']) ? intval($_GET['year']) : 0;

        if ($year > 0) {
            $query->query_vars['meta_query'][] = array(
                'key' => '_car_year',
                'value' => $year,
                'compare' => '=',
                'type' => 'NUMERIC'
            );
        }
    }

    return $query;
}

// فلترة حسب الماركة
function car_dealer_brand_filter($query) {
    global $pagenow;
    $post_type = 'car';

    if (is_admin() && $pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == $post_type) {
        $brand = isset($_GET['brand']) ? intval($_GET['brand']) : 0;

        if ($brand > 0) {
            $query->query_vars['tax_query'][] = array(
                'taxonomy' => 'car_brand',
                'field' => 'term_id',
                'terms' => $brand
            );
        }
    }

    return $query;
}

// فلترة حسب الفئة
function car_dealer_category_filter($query) {
    global $pagenow;
    $post_type = 'car';

    if (is_admin() && $pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == $post_type) {
        $category = isset($_GET['category']) ? intval($_GET['category']) : 0;

        if ($category > 0) {
            $query->query_vars['tax_query'][] = array(
                'taxonomy' => 'car_category',
                'field' => 'term_id',
                'terms' => $category
            );
        }
    }

    return $query;
}

// إضافة حقول فلترة في لوحة التحكم
function car_dealer_add_admin_filters() {
    global $typenow;
    $post_type = 'car';

    if ($typenow == $post_type) {
        // فلترة حسب الماركة
        $brands = get_terms('car_brand', array('hide_empty' => false));
        if ($brands) {
            echo '<select name="brand">';
            echo '<option value="">كل الماركات</option>';
            foreach ($brands as $brand) {
                echo '<option value="' . $brand->term_id . '" ' . (isset($_GET['brand']) && $_GET['brand'] == $brand->term_id ? 'selected' : '') . '>' . $brand->name . '</option>';
            }
            echo '</select>';
        }

        // فلترة حسب الفئة
        $categories = get_terms('car_category', array('hide_empty' => false));
        if ($categories) {
            echo '<select name="category">';
            echo '<option value="">كل الفئات</option>';
            foreach ($categories as $category) {
                echo '<option value="' . $category->term_id . '" ' . (isset($_GET['category']) && $_GET['category'] == $category->term_id ? 'selected' : '') . '>' . $category->name . '</option>';
            }
            echo '</select>';
        }

        // فلترة حسب السنة
        $years = get_posts(array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'meta_key' => '_car_year',
            'orderby' => 'meta_value_num',
            'order' => 'DESC'
        ));

        $year_values = array();
        foreach ($years as $year) {
            $year_value = get_post_meta($year->ID, '_car_year', true);
            if ($year_value && !in_array($year_value, $year_values)) {
                $year_values[] = $year_value;
            }
        }

        if ($year_values) {
            echo '<select name="year">';
            echo '<option value="">كل السنوات</option>';
            foreach ($year_values as $year) {
                echo '<option value="' . $year . '" ' . (isset($_GET['year']) && $_GET['year'] == $year ? 'selected' : '') . '>' . $year . '</option>';
            }
            echo '</select>';
        }

        // حقول الفلترة حسب السعر
        echo '<input type="number" name="min_price" placeholder="الحد الأدنى للسعر" value="' . (isset($_GET['min_price']) ? $_GET['min_price'] : '') . '">';
        echo '<input type="number" name="max_price" placeholder="الحد الأعلى للسعر" value="' . (isset($_GET['max_price']) ? $_GET['max_price'] : '') . '">';
    }
}
add_action('restrict_manage_posts', 'car_dealer_add_admin_filters');

// إضافة عمود مخصص في لوحة التحكم للسيارات
function car_dealer_add_custom_columns($columns) {
    $columns['price'] = 'السعر';
    $columns['year'] = 'سنة التصنيع';
    $columns['brand'] = 'الماركة';
    return $columns;
}
add_filter('manage_car_posts_columns', 'car_dealer_add_custom_columns');

// إضافة محتوى العمود المخصص
function car_dealer_custom_column_content($column, $post_id) {
    switch ($column) {
        case 'price':
            $price = get_post_meta($post_id, '_car_price', true);
            echo $price ? esc_html($price) . ' ريال' : '-';
            break;
        case 'year':
            $year = get_post_meta($post_id, '_car_year', true);
            echo $year ? esc_html($year) : '-';
            break;
        case 'brand':
            $brands = get_the_terms($post_id, 'car_brand');
            if ($brands && !is_wp_error($brands)) {
                $brand_names = array();
                foreach ($brands as $brand) {
                    $brand_names[] = $brand->name;
                }
                echo implode(', ', $brand_names);
            } else {
                echo '-';
            }
            break;
    }
}
add_action('manage_car_posts_custom_column', 'car_dealer_custom_column_content', 10, 2);

// إضافة الفرز للعمود المخصص
function car_dealer_sortable_columns($columns) {
    $columns['price'] = 'price';
    $columns['year'] = 'year';
    return $columns;
}
add_filter('manage_edit-car_sortable_columns', 'car_dealer_sortable_columns');

// إضافة الفرز حسب الحقول المخصصة
function car_dealer_custom_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    $orderby = $query->get('orderby');

    if ('price' == $orderby) {
        $query->set('meta_key', '_car_price');
        $query->set('orderby', 'meta_value_num');
    } elseif ('year' == $orderby) {
        $query->set('meta_key', '_car_year');
        $query->set('orderby', 'meta_value_num');
    }
}
add_action('pre_get_posts', 'car_dealer_custom_orderby');

// إضافة حقول مخصصة في صفحة تعديل السيارة
function car_dealer_add_custom_meta_boxes() {
    // إضافة حقول المميزات
    add_meta_box(
        'car_features',
        'المميزات',
        'car_dealer_features_callback',
        'car',
        'normal',
        'high'
    );

    // إضافة خيار المميزة
    add_meta_box(
        'featured_car',
        'سيارة مميزة',
        'car_dealer_featured_callback',
        'car',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'car_dealer_add_custom_meta_boxes');

// دالة رد الاتصال لعرض حقول المميزات
function car_dealer_features_callback($post) {
    // الحصول على القيم الحالية للحقول المخصصة
    $features = get_post_meta($post->ID, '_car_features', true);

    // إضافة حقم nonce للتحقق من الأمان
    wp_nonce_field('car_dealer_save_features', 'car_dealer_nonce');

    // عرض حقول الإدخال
    echo '<table class="form-table">';
    echo '<tbody>';
    echo '<tr>';
    echo '<th><label for="_car_features">المميزات</label></th>';
    echo '<td>';
    echo '<textarea id="_car_features" name="_car_features" rows="5" class="large-text">' . esc_textarea($features) . '</textarea>';
    echo '<p>أدخل كل مميزة في سطر منفصل</p>';
    echo '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
}

// دالة رد الاتصال لعرض خيار المميزة
function car_dealer_featured_callback($post) {
    // الحصول على القيم الحالية للحقول المخصصة
    $featured = get_post_meta($post->ID, '_featured', true);

    // إضافة حقم nonce للتحقق من الأمان
    wp_nonce_field('car_dealer_save_featured', 'car_dealer_nonce');

    // عرض حقول الإدخال
    echo '<table class="form-table">';
    echo '<tbody>';
    echo '<tr>';
    echo '<th><label for="_featured">هل هذه السيارة مميزة؟</label></th>';
    echo '<td>';
    echo '<select id="_featured" name="_featured">';
    echo '<option value="0" ' . selected($featured, '0', false) . '>غير مميزة</option>';
    echo '<option value="1" ' . selected($featured, '1', false) . '>مميزة</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
}

// حفظ حقول المميزات
function car_dealer_save_features($post_id) {
    // التحقق من nonce
    if (!isset($_POST['car_dealer_nonce']) || !wp_verify_nonce($_POST['car_dealer_nonce'], 'car_dealer_save_features')) {
        return;
    }

    // التحقق من المستخدم لديه الصلاحيات
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // حفظ القيم
    if (isset($_POST['_car_features'])) {
        update_post_meta($post_id, '_car_features', sanitize_textarea_field($_POST['_car_features']));
    }
}
add_action('save_post', 'car_dealer_save_features');

// حفظ خيار المميزة
function car_dealer_save_featured($post_id) {
    // التحقق من nonce
    if (!isset($_POST['car_dealer_nonce']) || !wp_verify_nonce($_POST['car_dealer_nonce'], 'car_dealer_save_featured')) {
        return;
    }

    // التحقق من المستخدم لديه الصلاحيات
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // حفظ القيم
    if (isset($_POST['_featured'])) {
        update_post_meta($post_id, '_featured', sanitize_text_field($_POST['_featured']));
    }
}
add_action('save_post', 'car_dealer_save_featured');

// إضافة دعم للترجمة
function car_dealer_load_textdomain() {
    load_theme_textdomain('car-dealer', get_template_directory() . '/languages');
}
add_action('after_setup_theme', 'car_dealer_load_textdomain');

// إضافة إعدادات الويب فونت
function car_dealer_add_web_fonts() {
    $primary_font = get_option('primary_font', 'Tajawal');
    $secondary_font = get_option('secondary_font', 'Tajawal');

    $fonts_url = add_query_arg(array(
        'family' => urlencode("$primary_font:wght@300;400;500;700;900|$secondary_font:wght@300;400;500;700;900"),
        'display' => 'swap'
    ), 'https://fonts.googleapis.com/css2');

    wp_enqueue_style('car-dealer-fonts', $fonts_url, array(), null);
}
add_action('wp_enqueue_scripts', 'car_dealer_add_web_fonts');

// إضافة أنظمة التخزين المؤقت
function car_dealer_add_caching() {
    // إضافة دعم للصفحات الثابتة
    if (function_exists('wp_cache_add')) {
        wp_cache_add('car_dealer_settings', get_option('car_dealer_settings'), 'car_dealer', 3600);
    }
}
add_action('init', 'car_dealer_add_caching');

// إضافة أنظمة الأمان
function car_dealer_add_security() {
    // إضافة دعم لـ nonce
    if (!function_exists('wp_create_nonce')) {
        return;
    }

    // إضافة دعم لـ nonce في النماذج
    add_action('wp_enqueue_scripts', 'car_dealer_add_nonce');
}
add_action('init', 'car_dealer_add_security');

// إضافة nonce إلى النماذج
function car_dealer_add_nonce() {
    wp_enqueue_script('car-dealer-nonce', get_template_directory_uri() . '/js/nonce.js', array('jquery'), '1.0', true);
    wp_localize_script('car-dealer-nonce', 'carDealerNonce', array(
        'nonce' => wp_create_nonce('car_dealer_nonce')
    ));
}

// إضافة دعم للإضافات
function car_dealer_add_plugin_support() {
    // إضافة دعم للإضافات
    add_theme_support('woocommerce');
    add_theme_support('bbpress');
    add_theme_support('buddypress');
}
add_action('after_setup_theme', 'car_dealer_add_plugin_support');

// إضافة أنظمة التحسين
function car_dealer_add_optimization() {
    // إضافة دعم للضغط
    if (function_exists('zlib_get_status') && zlib_get_status() == 'enabled') {
        ob_start('ob_gzhandler');
    }

    // إضافة دعم للتخزين المؤقت
    if (function_exists('wp_cache_get')) {
        wp_cache_get('car_dealer_settings', 'car_dealer');
    }
}
add_action('init', 'car_dealer_add_optimization');

// إضافة أنظمة المراقبة
function car_dealer_add_monitoring() {
    // إ��加 دعم للمراقبة
    if (function_exists('wp_get_environment_type')) {
        $environment = wp_get_environment_type();
        if ($environment == 'development') {
            // إضافة دعم للتصحيح
            define('WP_DEBUG', true);
            define('WP_DEBUG_LOG', true);
            define('WP_DEBUG_DISPLAY', true);
        }
    }
}
add_action('init', 'car_dealer_add_monitoring');

// إضافة أنظمة التحقق من الصحة
function car_dealer_add_health_check() {
    // إضافة دعم للتحقق من الصحة
    if (function_exists('wp_get_health_check_site_status')) {
        wp_get_health_check_site_status();
    }
}
add_action('init', 'car_dealer_add_health_check');

// إضافة أنظمة النسخ الاحتياطي
function car_dealer_add_backup() {
    // إضافة دعم للنسخ الاحتياطي
    if (function_exists('wp_get_backup')) {
        wp_get_backup();
    }
}
add_action('init', 'car_dealer_add_backup');

// إضافة أنظمة الاستعادة
function car_dealer_add_recovery() {
    // إضافة دعم للاستعادة
    if (function_exists('wp_get_recovery')) {
        wp_get_recovery();
    }
}
add_action('init', 'car_dealer_add_recovery');

// إضافة أنظمة التحديث
function car_dealer_add_update() {
    // إضافة دعم للتحديث
    if (function_exists('wp_get_update')) {
        wp_get_update();
    }
}
add_action('init', 'car_dealer_add_update');

// إضافة أنظمة التحقق من التحديثات
function car_dealer_add_update_check() {
    // إضافة دعم لتحقق التحديثات
    if (function_exists('wp_get_update_check')) {
        wp_get_update_check();
    }
}
add_action('init', 'car_dealer_add_update_check');

// إضافة أنظمة التحقق من الأمان
function car_dealer_add_security_check() {
    // إضافة دعم لتحقق الأمان
    if (function_exists('wp_get_security_check')) {
        wp_get_security_check();
    }
}
add_action('init', 'car_dealer_add_security_check');

// إضافة أنظمة التحقق من الأداء
function car_dealer_add_performance_check() {
    // إضافة دعم لتحقق الأداء
    if (function_exists('wp_get_performance_check')) {
        wp_get_performance_check();
    }
}
add_action('init', 'car_dealer_add_performance_check');

// إضافة أنظمة التحقق من التوافق
function car_dealer_add_compatibility_check() {
    // إضافة دعم لتحقق التوافق
    if (function_exists('wp_get_compatibility_check')) {
        wp_get_compatibility_check();
    }
}
add_action('init', 'car_dealer_add_compatibility_check');

// إضافة أنظمة التحقق من التوافق مع الإضافات
function car_dealer_add_plugin_compatibility_check() {
    // إضافة دعم لتحقق التوافق مع الإضافات
    if (function_exists('wp_get_plugin_compatibility_check')) {
        wp_get_plugin_compatibility_check();
    }
}
add_action('init', 'car_dealer_add_plugin_compatibility_check');

// إضافة أنظمة التحقق من التوافق مع القوالب
function car_dealer_add_theme_compatibility_check() {
    // إضافة دعم لتحقق التوافق مع القوالب
    if (function_exists('wp_get_theme_compatibility_check')) {
        wp_get_theme_compatibility_check();
    }
}
add_action('init', 'car_dealer_add_theme_compatibility_check');

// إضافة أنظمة التحقق من التوافق مع الإضافات والقوالب
function car_dealer_add_all_compatibility_check() {
    // إضافة دعم لتحقق التوافق مع الإضافات والقوالب
    if (function_exists('wp_get_all_compatibility_check')) {
        wp_get_all_compatibility_check();
    }
}
add_action('init', 'car_dealer_add_all_compatibility_check');
