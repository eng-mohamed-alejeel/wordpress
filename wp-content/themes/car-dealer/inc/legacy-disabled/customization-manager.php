<?php
/**
 * مدير التخصيص للقالب
 *
 * @package Car Dealer
 * @subpackage Customization
 * @since Car Dealer 1.0
 */

// إنشاء قسم إعدادات التخصيص في لوحة التحكم
function car_dealer_customization_settings() {
    add_menu_page(
        'إعدادات التخصيص', // عنوان الصفحة
        'التخصيص', // عنوان القائمة
        'manage_options', // الصلاحيات المطلوبة
        'car-dealer-customization', // المعرّف الفريد
        'car_dealer_customization_page_html', // دالة عرض المحتوى
        'dashicons-admin-generic', // أيقونة القسم
        80 // الموقع في القائمة
    );

    add_submenu_page(
        'car-dealer-customization',
        'إعدادات عامة',
        'إعدادات عامة',
        'manage_options',
        'car-dealer-general-settings',
        'car_dealer_general_settings_html'
    );

    add_submenu_page(
        'car-dealer-customization',
        'إعدادات الألوان',
        'إعدادات الألوان',
        'manage_options',
        'car-dealer-color-settings',
        'car_dealer_color_settings_html'
    );

    add_submenu_page(
        'car-dealer-customization',
        'إعدادات المحتوى',
        'إعدادات المحتوى',
        'manage_options',
        'car-dealer-content-settings',
        'car_dealer_content_settings_html'
    );
}
add_action('admin_menu', 'car_dealer_customization_settings');

// تسجيل الإعدادات
function car_dealer_register_settings() {
    // إعدادات عامة
    register_setting('car_dealer_general_settings', 'site_title');
    register_setting('car_dealer_general_settings', 'site_description');
    register_setting('car_dealer_general_settings', 'contact_email');
    register_setting('car_dealer_general_settings', 'contact_phone');
    register_setting('car_dealer_general_settings', 'contact_address');

    // إعدادات الألوان
    register_setting('car_dealer_color_settings', 'primary_color');
    register_setting('car_dealer_color_settings', 'secondary_color');
    register_setting('car_dealer_color_settings', 'accent_color');
    register_setting('car_dealer_color_settings', 'text_color');
    register_setting('car_dealer_color_settings', 'background_color');

    // إعدادات المحتوى
    register_setting('car_dealer_content_settings', 'featured_cars_count');
    register_setting('car_dealer_content_settings', 'cars_per_page');
    register_setting('car_dealer_content_settings', 'show_testimonials');
    register_setting('car_dealer_content_settings', 'testimonials_count');
}
add_action('admin_init', 'car_dealer_register_settings');

// دالة عرض صفحة التخصيص الرئيسية
function car_dealer_customization_page_html() {
    ?>
    <div class="wrap">
        <h1>إعدادات التخصيص</h1>
        <p>هنا يمكنك تخصيص جميع إعدادات الموقع وفقًا لاحتياجاتك.</p>

        <div class="nav-tab-wrapper">
            <a href="?page=car-dealer-general-settings" class="nav-tab <?php if (!isset($_GET['page']) || $_GET['page'] == 'car-dealer-general-settings') echo 'nav-tab-active'; ?>">إعدادات عامة</a>
            <a href="?page=car-dealer-color-settings" class="nav-tab <?php if (isset($_GET['page']) && $_GET['page'] == 'car-dealer-color-settings') echo 'nav-tab-active'; ?>">إعدادات الألوان</a>
            <a href="?page=car-dealer-content-settings" class="nav-tab <?php if (isset($_GET['page']) && $_GET['page'] == 'car-dealer-content-settings') echo 'nav-tab-active'; ?>">إعدادات المحتوى</a>
        </div>

        <div class="tab-content">
            <?php if (isset($_GET['page']) && $_GET['page'] == 'car-dealer-color-settings'): ?>
                <?php car_dealer_color_settings_html(); ?>
            <?php elseif (isset($_GET['page']) && $_GET['page'] == 'car-dealer-content-settings'): ?>
                <?php car_dealer_content_settings_html(); ?>
            <?php else: ?>
                <?php car_dealer_general_settings_html(); ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// دالة عرض إعدادات عامة
function car_dealer_general_settings_html() {
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('car_dealer_general_settings');
        do_settings_sections('car_dealer_general_settings');
        ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="site_title">عنوان الموقع</label></th>
                    <td><input type="text" id="site_title" name="site_title" value="<?php echo esc_attr(get_option('site_title', 'معرض السيارات الفاخر')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="site_description">وصف الموقع</label></th>
                    <td><textarea id="site_description" name="site_description" rows="5" class="large-text"><?php echo esc_textarea(get_option('site_description', 'نقدم أحدث الموديلات والعروض المميزة')); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="contact_email">البريد الإلكتروني</label></th>
                    <td><input type="email" id="contact_email" name="contact_email" value="<?php echo esc_attr(get_option('contact_email', 'info@cardealer.com')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="contact_phone">رقم الهاتف</label></th>
                    <td><input type="tel" id="contact_phone" name="contact_phone" value="<?php echo esc_attr(get_option('contact_phone', '+966 50 123 4567')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="contact_address">العنوان</label></th>
                    <td><input type="text" id="contact_address" name="contact_address" value="<?php echo esc_attr(get_option('contact_address', 'الرياض، المملكة العربية السعودية')); ?>" class="regular-text"></td>
                </tr>
            </tbody>
        </table>

        <?php submit_button('حفظ الإعدادات'); ?>
    </form>
    <?php
}

// دالة عرض إعدادات الألوان
function car_dealer_color_settings_html() {
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('car_dealer_color_settings');
        do_settings_sections('car_dealer_color_settings');
        ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="primary_color">اللون الرئيسي</label></th>
                    <td>
                        <input type="color" id="primary_color" name="primary_color" value="<?php echo esc_attr(get_option('primary_color', '#1a3a52')); ?>">
                        <input type="text" id="primary_color_text" value="<?php echo esc_attr(get_option('primary_color', '#1a3a52')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="secondary_color">اللون الثانوي</label></th>
                    <td>
                        <input type="color" id="secondary_color" name="secondary_color" value="<?php echo esc_attr(get_option('secondary_color', '#ff6b35')); ?>">
                        <input type="text" id="secondary_color_text" value="<?php echo esc_attr(get_option('secondary_color', '#ff6b35')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="accent_color">لون التمييز</label></th>
                    <td>
                        <input type="color" id="accent_color" name="accent_color" value="<?php echo esc_attr(get_option('accent_color', '#00a8cc')); ?>">
                        <input type="text" id="accent_color_text" value="<?php echo esc_attr(get_option('accent_color', '#00a8cc')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="text_color">لون النص</label></th>
                    <td>
                        <input type="color" id="text_color" name="text_color" value="<?php echo esc_attr(get_option('text_color', '#333333')); ?>">
                        <input type="text" id="text_color_text" value="<?php echo esc_attr(get_option('text_color', '#333333')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="background_color">لون الخلفية</label></th>
                    <td>
                        <input type="color" id="background_color" name="background_color" value="<?php echo esc_attr(get_option('background_color', '#f8f9fa')); ?>">
                        <input type="text" id="background_color_text" value="<?php echo esc_attr(get_option('background_color', '#f8f9fa')); ?>" class="regular-text">
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button('حفظ الإعدادات'); ?>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // مزامنة ألوان النص مع حقول النص
        const primaryColor = document.getElementById('primary_color');
        const primaryColorText = document.getElementById('primary_color_text');
        const secondaryColor = document.getElementById('secondary_color');
        const secondaryColorText = document.getElementById('secondary_color_text');
        const accentColor = document.getElementById('accent_color');
        const accentColorText = document.getElementById('accent_color_text');
        const textColor = document.getElementById('text_color');
        const textColorText = document.getElementById('text_color_text');
        const backgroundColor = document.getElementById('background_color');
        const backgroundColorText = document.getElementById('background_color_text');

        primaryColor.addEventListener('input', function() {
            primaryColorText.value = this.value;
        });

        primaryColorText.addEventListener('input', function() {
            primaryColor.value = this.value;
        });

        secondaryColor.addEventListener('input', function() {
            secondaryColorText.value = this.value;
        });

        secondaryColorText.addEventListener('input', function() {
            secondaryColor.value = this.value;
        });

        accentColor.addEventListener('input', function() {
            accentColorText.value = this.value;
        });

        accentColorText.addEventListener('input', function() {
            accentColor.value = this.value;
        });

        textColor.addEventListener('input', function() {
            textColorText.value = this.value;
        });

        textColorText.addEventListener('input', function() {
            textColor.value = this.value;
        });

        backgroundColor.addEventListener('input', function() {
            backgroundColorText.value = this.value;
        });

        backgroundColorText.addEventListener('input', function() {
            backgroundColor.value = this.value;
        });
    });
    </script>
    <?php
}

// دالة عرض إعدادات المحتوى
function car_dealer_content_settings_html() {
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('car_dealer_content_settings');
        do_settings_sections('car_dealer_content_settings');
        ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="featured_cars_count">عدد السيارات المميزة</label></th>
                    <td><input type="number" id="featured_cars_count" name="featured_cars_count" value="<?php echo esc_attr(get_option('featured_cars_count', '6')); ?>" min="1" max="20"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="cars_per_page">عدد السيارات في الصفحة</label></th>
                    <td><input type="number" id="cars_per_page" name="cars_per_page" value="<?php echo esc_attr(get_option('cars_per_page', '12')); ?>" min="1" max="50"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="show_testimonials">عرض الشهادات</label></th>
                    <td>
                        <select id="show_testimonials" name="show_testimonials">
                            <option value="1" <?php selected(get_option('show_testimonials', '1'), '1'); ?>>نعم</option>
                            <option value="0" <?php selected(get_option('show_testimonials', '1'), '0'); ?>>لا</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="testimonials_count">عدد الشهادات المعروضة</label></th>
                    <td><input type="number" id="testimonials_count" name="testimonials_count" value="<?php echo esc_attr(get_option('testimonials_count', '3')); ?>" min="1" max="10"></td>
                </tr>
            </tbody>
        </table>

        <?php submit_button('حفظ الإعدادات'); ?>
    </form>
    <?php
}

// تحديث الألوان الديناميكية في CSS
function car_dealer_dynamic_styles() {
    $primary_color = get_option('primary_color', '#1a3a52');
    $secondary_color = get_option('secondary_color', '#ff6b35');
    $accent_color = get_option('accent_color', '#00a8cc');
    $text_color = get_option('text_color', '#333333');
    $background_color = get_option('background_color', '#f8f9fa');

    ?>
    <style>
    :root {
        --primary-color: <?php echo esc_attr($primary_color); ?>;
        --secondary-color: <?php echo esc_attr($secondary_color); ?>;
        --accent-color: <?php echo esc_attr($accent_color); ?>;
        --text-color: <?php echo esc_attr($text_color); ?>;
        --light-bg: <?php echo esc_attr($background_color); ?>;
    }
    </style>
    <?php
}
add_action('wp_head', 'car_dealer_dynamic_styles');

// إضافة دعم لبرنامج التشغيل AJAX
function car_dealer_ajax_setup() {
    // تسجيل دالة لمعالجة نموذج التواصل
    add_action('wp_ajax_send_contact_form', 'car_dealer_handle_contact_form');
    add_action('wp_ajax_nopriv_send_contact_form', 'car_dealer_handle_contact_form');

    // تسجيل دالة لإضافة السيارة إلى المقارنة
    add_action('wp_ajax_add_to_comparison', 'car_dealer_ajax_add_to_comparison');
    add_action('wp_ajax_nopriv_add_to_comparison', 'car_dealer_ajax_add_to_comparison');

    // تسجيل دالة لإزالة السيارة من المقارنة
    add_action('wp_ajax_remove_from_comparison', 'car_dealer_ajax_remove_from_comparison');
    add_action('wp_ajax_nopriv_remove_from_comparison', 'car_dealer_ajax_remove_from_comparison');

    // تسجيل دالة لحجز تجربة القيادة
    add_action('wp_ajax_book_test_drive', 'car_dealer_ajax_book_test_drive');
    add_action('wp_ajax_nopriv_book_test_drive', 'car_dealer_ajax_book_test_drive');
}
add_action('init', 'car_dealer_ajax_setup');

// دالة معالجة نموذج التواصل
function car_dealer_handle_contact_form() {
    // التحقق من nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'contact_form_nonce')) {
        wp_send_json_error('Nonce غير صالح');
    }

    // التحقق من البيانات
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

    if (empty($name) || empty($email) || empty($message)) {
        wp_send_json_error('جميع الحقول مطلوبة');
    }

    // إعداد البريد
    $to = get_option('contact_email', 'info@cardealer.com');
    $subject = 'رسالة جديدة من موقع معرض السيارات';
    $body = "اسم: $name
";
    $body .= "البريد الإلكتروني: $email
";
    $body .= "الهاتف: $phone

";
    $body .= "الرسالة:
$message";

    // إرسال البريد
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $sent = wp_mail($to, $subject, $body, $headers);

    if ($sent) {
        wp_send_json_success('تم إرسال رسالتك بنجاح');
    } else {
        wp_send_json_error('حدث خطأ أثناء إرسال الرسالة');
    }
}

// دالة إضافة السيارة إلى المقارنة عبر AJAX
function car_dealer_ajax_add_to_comparison() {
    if (!isset($_POST['car_id']) || !is_numeric($_POST['car_id'])) {
        wp_send_json_error('معرّف السيارة غير صالح');
    }

    $car_id = intval($_POST['car_id']);
    $result = car_dealer_add_to_comparison($car_id);

    if ($result) {
        $comparison_list = car_dealer_get_comparison_list();
        wp_send_json_success(array(
            'message' => 'تمت إضافة السيارة إلى قائمة المقارنة',
            'count' => count($comparison_list)
        ));
    } else {
        wp_send_json_error('فشلت عملية الإضافة');
    }
}

// دالة إزالة السيارة من المقارنة عبر AJAX
function car_dealer_ajax_remove_from_comparison() {
    if (!isset($_POST['car_id']) || !is_numeric($_POST['car_id'])) {
        wp_send_json_error('معرّف السيارة غير صالح');
    }

    $car_id = intval($_POST['car_id']);
    $result = car_dealer_remove_from_comparison($car_id);

    if ($result) {
        $comparison_list = car_dealer_get_comparison_list();
        wp_send_json_success(array(
            'message' => 'تمت إزالة السيارة من قائمة المقارنة',
            'count' => count($comparison_list)
        ));
    } else {
        wp_send_json_error('فشلت عملية الإزالة');
    }
}

// دالة حجز تجربة القيادة عبر AJAX
function car_dealer_ajax_book_test_drive() {
    // التحقق من nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_drive_nonce')) {
        wp_send_json_error('Nonce غير صالح');
    }

    // التحقق من البيانات
    $car_id = isset($_POST['car_id']) ? intval($_POST['car_id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';

    if (empty($car_id) || empty($name) || empty($phone) || empty($email) || empty($date) || empty($time)) {
        wp_send_json_error('جميع الحقول مطلوبة');
    }

    // الحصول على معرّف المستخدم إذا كان مسجلاً الدخول
    $user_id = is_user_logged_in() ? get_current_user_id() : 0;

    // إضافة حجز تجربة القيادة
    $data = array(
        'car_id' => $car_id,
        'user_id' => $user_id,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'date' => $date,
        'time' => $time
    );

    $result = car_dealer_book_test_drive($data);

    if ($result) {
        wp_send_json_success('تم حجز تجربة القيادة بنجاح');
    } else {
        wp_send_json_error('فشل حجز تجربة القيادة');
    }
}
