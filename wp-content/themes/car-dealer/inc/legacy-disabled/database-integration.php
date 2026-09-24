<?php
/**
 * تكامل قاعدة البيانات والتخصيص للقالب
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 2.0
 */

// منع الوصول المباشر إلى الملف
if (!defined('ABSPATH')) {
    exit;
}

/**
 * إعداد قاعدة البيانات للتخصيص
 */
function car_dealer_database_setup() {
    // إنشاء الجداول المخصصة عند تفعيل القالب
    car_dealer_create_custom_tables();

    // إعداد الخيارات الافتراضية
    car_dealer_set_default_options();
}
add_action('after_setup_theme', 'car_dealer_database_setup');

/**
 * إنشاء الجداول المخصصة في قاعدة البيانات
 */
function car_dealer_create_custom_tables() {
    global $wpdb;

    // جدول لتخزين إعدادات التخصيص
    $custom_settings_table = $wpdb->prefix . 'car_dealer_settings';

    // إنشاء الجداول إذا لم تكن موجودة
    if ($wpdb->get_var("SHOW TABLES LIKE '$custom_settings_table'") != $custom_settings_table) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $custom_settings_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_key varchar(50) NOT NULL,
            setting_value longtext NOT NULL,
            setting_group varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // جدول لتخزين إعدادات المستخدمين
    $user_preferences_table = $wpdb->prefix . 'car_dealer_user_preferences';

    if ($wpdb->get_var("SHOW TABLES LIKE '$user_preferences_table'") != $user_preferences_table) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $user_preferences_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            preference_key varchar(50) NOT NULL,
            preference_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_preference (user_id, preference_key)
        ) $charset_collate;";

        dbDelta($sql);
    }
}

/**
 * إعداد الخيارات الافتراضية
 */
function car_dealer_set_default_options() {
    // إضافة الإعدادات الافتراضية إذا لم تكن موجودة
    car_dealer_update_setting('theme_color', '#3498db', 'general');
    car_dealer_update_setting('logo_width', '200', 'general');
    car_dealer_update_setting('show_testimonials', '1', 'display');
    car_dealer_update_setting('featured_cars_count', '6', 'display');
    car_dealer_update_setting('contact_form_email', get_option('admin_email'), 'contact');
}

/**
 * الحصول على إعداد مخصص
 */
function car_dealer_get_setting(string $key, $default = '', string $group = 'general') {
    global $wpdb;

    $settings_table = $wpdb->prefix . 'car_dealer_settings';
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $settings_table WHERE setting_key = %s AND setting_group = %s",
        $key,
        $group
    ));

    return $value !== null ? maybe_unserialize($value) : $default;
}

/**
 * تحديث إعداد مخصص
 */
function car_dealer_update_setting(string $key, $value, string $group = 'general'): bool {
    global $wpdb;

    $settings_table = $wpdb->prefix . 'car_dealer_settings';

    // التحقق من وجود الإعداد
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $settings_table WHERE setting_key = %s AND setting_group = %s",
        $key,
        $group
    ));

    if ($existing) {
        // تحديث الإ�� الموجود
        $wpdb->update(
            $settings_table,
            array(
                'setting_value' => maybe_serialize($value),
                'updated_at' => current_time('mysql')
            ),
            array(
                'setting_key' => $key,
                'setting_group' => $group
            ),
            array('%s', '%s'),
            array('%s', '%s')
        );
    } else {
        // إضافة إعداد جديد
        $wpdb->insert(
            $settings_table,
            array(
                'setting_key' => $key,
                'setting_value' => maybe_serialize($value),
                'setting_group' => $group
            ),
            array('%s', '%s', '%s')
        );
    }
    
    return true;
}

/**
 * الحصول على تفضيل المستخدم
 */
function car_dealer_get_user_preference(int $user_id, string $key, $default = '') {
    global $wpdb;

    $preferences_table = $wpdb->prefix . 'car_dealer_user_preferences';
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT preference_value FROM $preferences_table WHERE user_id = %d AND preference_key = %s",
        $user_id,
        $key
    ));

    return $value !== null ? maybe_unserialize($value) : $default;
}

/**
 * تحديث تفضيل المستخدم
 */
function car_dealer_update_user_preference(int $user_id, string $key, $value): bool {
    global $wpdb;

    $preferences_table = $wpdb->prefix . 'car_dealer_user_preferences';

    // التحقق من وجود التفضيل
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $preferences_table WHERE user_id = %d AND preference_key = %s",
        $user_id,
        $key
    ));

    if ($existing) {
        // تحديث التفضيل الموجود
        $wpdb->update(
            $preferences_table,
            array(
                'preference_value' => maybe_serialize($value),
                'updated_at' => current_time('mysql')
            ),
            array(
                'user_id' => $user_id,
                'preference_key' => $key
            ),
            array('%s', '%s'),
            array('%d', '%s')
        );
    } else {
        // إضافة تفضيل جديد
        $wpdb->insert(
            $preferences_table,
            array(
                'user_id' => $user_id,
                'preference_key' => $key,
                'preference_value' => maybe_serialize($value)
            ),
            array('%d', '%s', '%s')
        );
    }
    
    return true;
}

/**
 * الحصول على جميع إعدادات المجموعة
 */
function car_dealer_get_settings_group(string $group): array {
    global $wpdb;

    $settings_table = $wpdb->prefix . 'car_dealer_settings';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT setting_key, setting_value FROM $settings_table WHERE setting_group = %s",
        $group
    ));

    $settings = array();
    foreach ($results as $result) {
        $settings[$result->setting_key] = maybe_unserialize($result->setting_value);
    }

    return $settings;
}

/**
 * إضافة عناصر قائمة مخصصة لوحة التحكم
 */
function car_dealer_add_admin_menus() {
    // إضافة قسم التخصيص
    add_menu_page(
        'إعدادات معرض السيارات',
        'معرض السيارات',
        'manage_options',
        'car-dealer-settings',
        'car_dealer_settings_page',
        'dashicons-car',
        6
    );

    // إضافة القوائم الفرعية
    add_submenu_page(
        'car-dealer-settings',
        'الإعدادات العامة',
        'الإعدادات العامة',
        'manage_options',
        'car-dealer-settings',
        'car_dealer_settings_page'
    );

    add_submenu_page(
        'car-dealer-settings',
        'إعدادات العرض',
        'إعدادات العرض',
        'manage_options',
        'car-dealer-display',
        'car_dealer_display_page'
    );

    add_submenu_page(
        'car-dealer-settings',
        'إعدادات التواصل',
        'إعدادات التواصل',
        'manage_options',
        'car-dealer-contact',
        'car_dealer_contact_page'
    );
}
add_action('admin_menu', 'car_dealer_add_admin_menus');

/**
 * صفحة إعدادات معرض السيارات
 */
function car_dealer_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('ليس لديك الصلاحية لهذه الصفحة');
    }

    // معالجة حفظ الإعدادات
    if (isset($_POST['car_dealer_settings_nonce']) && wp_verify_nonce($_POST['car_dealer_settings_nonce'], 'save_car_dealer_settings')) {
        // حفظ الإعدادات
        car_dealer_update_setting('theme_color', sanitize_text_field($_POST['theme_color']), 'general');
        car_dealer_update_setting('logo_width', intval($_POST['logo_width']), 'general');
        car_dealer_update_setting('site_description', wp_kses_post($_POST['site_description']), 'general');

        // إضافة رسالة نجاح
        add_action('admin_notices', 'car_dealer_settings_success_notice');
    }

    // الحصول على الإعدادات الحالية
    $theme_color = car_dealer_get_setting('theme_color', '#3498db', 'general');
    $logo_width = car_dealer_get_setting('logo_width', '200', 'general');
    $site_description = car_dealer_get_setting('site_description', '', 'general');

    // عرض نموذج الإعدادات
    ?>
    <div class="wrap">
        <h1>إعدادات معرض السيارات</h1>

        <form method="post">
            <?php wp_nonce_field('save_car_dealer_settings', 'car_dealer_settings_nonce'); ?>

            <div class="form-group">
                <label for="theme_color">لون الموقع الرئيسي</label>
                <input type="color" id="theme_color" name="theme_color" value="<?php echo esc_attr($theme_color); ?>">
            </div>

            <div class="form-group">
                <label for="logo_width">عرض الشعار (بالبكسل)</label>
                <input type="number" id="logo_width" name="logo_width" value="<?php echo esc_attr($logo_width); ?>" min="50" max="500">
            </div>

            <div class="form-group">
                <label for="site_description">وصف الموقع</label>
                <textarea id="site_description" name="site_description" rows="5"><?php echo esc_textarea($site_description); ?></textarea>
            </div>

            <div class="form-group">
                <input type="submit" class="button button-primary" value="حفظ الإعدادات">
            </div>
        </form>
    </div>
    <?php
}

/**
 * صفحة إعدادات العرض
 */
function car_dealer_display_page() {
    if (!current_user_can('manage_options')) {
        wp_die('ليس لديك الصلاحية لهذه الصفحة');
    }

    // معالجة حفظ الإعدادات
    if (isset($_POST['car_dealer_display_nonce']) && wp_verify_nonce($_POST['car_dealer_display_nonce'], 'save_car_dealer_display')) {
        // حفظ الإعدادات
        car_dealer_update_setting('show_testimonials', isset($_POST['show_testimonials']) ? '1' : '0', 'display');
        car_dealer_update_setting('featured_cars_count', intval($_POST['featured_cars_count']), 'display');
        car_dealer_update_setting('show_car_specs', isset($_POST['show_car_specs']) ? '1' : '0', 'display');

        // إضافة رسالة نجاح
        add_action('admin_notices', 'car_dealer_settings_success_notice');
    }

    // الحصول على الإعدادات الحالية
    $show_testimonials = car_dealer_get_setting('show_testimonials', '1', 'display');
    $featured_cars_count = car_dealer_get_setting('featured_cars_count', '6', 'display');
    $show_car_specs = car_dealer_get_setting('show_car_specs', '1', 'display');

    // عرض نموذج الإعدادات
    ?>
    <div class="wrap">
        <h1>إعدادات العرض</h1>

        <form method="post">
            <?php wp_nonce_field('save_car_dealer_display', 'car_dealer_display_nonce'); ?>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="show_testimonials" value="1" <?php checked($show_testimonials, '1'); ?>>
                    عرض شهادات العملاء
                </label>
            </div>

            <div class="form-group">
                <label for="featured_cars_count">عدد السيارات المميزة</label>
                <input type="number" id="featured_cars_count" name="featured_cars_count" value="<?php echo esc_attr($featured_cars_count); ?>" min="1" max="20">
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="show_car_specs" value="1" <?php checked($show_car_specs, '1'); ?>>
                    عرض مواصفات السيارات بالتفصيل
                </label>
            </div>

            <div class="form-group">
                <input type="submit" class="button button-primary" value="حفظ الإعدادات">
            </div>
        </form>
    </div>
    <?php
}

/**
 * صفحة إعدادات التواصل
 */
function car_dealer_contact_page() {
    if (!current_user_can('manage_options')) {
        wp_die('ليس لديك الصلاحية لهذه الصفحة');
    }

    // معالجة حفظ الإعدادات
    if (isset($_POST['car_dealer_contact_nonce']) && wp_verify_nonce($_POST['car_dealer_contact_nonce'], 'save_car_dealer_contact')) {
        // حفظ الإعدادات
        car_dealer_update_setting('contact_form_email', sanitize_email($_POST['contact_form_email']), 'contact');
        car_dealer_update_setting('phone_number', sanitize_text_field($_POST['phone_number']), 'contact');
        car_dealer_update_setting('address', wp_kses_post($_POST['address']), 'contact');

        // إضافة رسالة نجاح
        add_action('admin_notices', 'car_dealer_settings_success_notice');
    }

    // الحصول على الإعدادات الحالية
    $contact_form_email = car_dealer_get_setting('contact_form_email', get_option('admin_email'), 'contact');
    $phone_number = car_dealer_get_setting('phone_number', '+966 50 123 4567', 'contact');
    $address = car_dealer_get_setting('address', 'الرياض، المملكة العربية السعودية', 'contact');

    // عرض نموذج الإعدادات
    ?>
    <div class="wrap">
        <h1>إعدادات التواصل</h1>

        <form method="post">
            <?php wp_nonce_field('save_car_dealer_contact', 'car_dealer_contact_nonce'); ?>

            <div class="form-group">
                <label for="contact_form_email">بريد التواصل</label>
                <input type="email" id="contact_form_email" name="contact_form_email" value="<?php echo esc_attr($contact_form_email); ?>">
            </div>

            <div class="form-group">
                <label for="phone_number">رقم الهاتف</label>
                <input type="text" id="phone_number" name="phone_number" value="<?php echo esc_attr($phone_number); ?>">
            </div>

            <div class="form-group">
                <label for="address">العنوان</label>
                <textarea id="address" name="address" rows="3"><?php echo esc_textarea($address); ?></textarea>
            </div>

            <div class="form-group">
                <input type="submit" class="button button-primary" value="حفظ الإعدادات">
            </div>
        </form>
    </div>
    <?php
}

/**
 * عرض إشعار نجاح الإعدادات
 */
function car_dealer_settings_success_notice() {
    ?>
    <div class="notice notice-success is-dismissible">
        <p>تم حفظ الإعدادات بنجاح!</p>
    </div>
    <?php
}

/**
 * معالجة نموذج الاتصال
 */
function car_dealer_handle_contact_form() {
    if (isset($_POST['action']) && $_POST['action'] === 'send_contact_form') {
        // التحقق من nonce
        if (!isset($_POST['contact_form_nonce']) || !wp_verify_nonce($_POST['contact_form_nonce'], 'contact_form_submission')) {
            wp_die('خطأ في التحقق من النموذج');
        }

        // التحقق من البيانات
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $message = sanitize_textarea_field($_POST['message']);

        if (empty($name) || empty($email) || empty($phone) || empty($message)) {
            wp_die('يرجى ملء جميع الحقول');
        }

        // الحصول على إعدادات التواصل
        $contact_email = car_dealer_get_setting('contact_form_email', get_option('admin_email'), 'contact');

        // إعداد رسالة البريد الإلكتروني
        $subject = 'رسالة جديدة من نموذج الاتصال';
        $body = "تم استلام رسالة جديدة من نموذج الاتصال:

";
        $body .= "الاسم: $name
";
        $body .= "البريد الإلكتروني: $email
";
        $body .= "رقم الهاتف: $phone
";
        $body .= "الرسالة:
$message";

        // إرسال البريد الإلكتروني
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($contact_email, $subject, nl2br($body), $headers);

        // إضافة رسالة نجاح
        wp_set_current_user(get_current_user_id());
        update_user_meta(get_current_user_id(), 'contact_form_message', 'تم إرسال رسالتك بنجاح. سنتواصل معك قريباً.');

        // إعادة التوجيه
        wp_redirect(add_query_arg(array('contact_sent' => '1'), home_url('#contact')));
        exit;
    }
}
add_action('wp_ajax_send_contact_form', 'car_dealer_handle_contact_form');
add_action('wp_ajax_nopriv_send_contact_form', 'car_dealer_handle_contact_form');

/**
 * إضافة أنماط CSS مخصصة بناءً على الإعدادات
 */
function car_dealer_custom_styles() {
    // الحصول على إعدادات الألوان
    $theme_color = car_dealer_get_setting('theme_color', '#3498db', 'general');

    // إنشاء أنماط CSS مخصصة
    $custom_css = "
        :root {
            --primary-color: $theme_color;
        }

        .btn {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn:hover {
            background-color: " . car_dealer_adjust_color($theme_color, -20) . ";
            border-color: " . car_dealer_adjust_color($theme_color, -20) . ";
        }

        .car-badge {
            background-color: var(--primary-color);
        }

        .filter-btn.active {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
    ";

    wp_add_inline_style('car-dealer-style', $custom_css);
}
add_action('wp_enqueue_scripts', 'car_dealer_custom_styles');

/**
 * دالة لتعديل درجة لون
 */
function car_dealer_adjust_color(string $hex, int $steps): string {
    // تحويل اللون من HEX إلى RGB
    $hex = str_replace("#", "", $hex);

    if (strlen($hex) == 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $r = hexdec($hex[0] . $hex[1]);
    $g = hexdec($hex[2] . $hex[3]);
    $b = hexdec($hex[4] . $hex[5]);

    // تعديل درجات اللون
    $r = max(0, min(255, $r + $steps));
    $g = max(0, min(255, $g + $steps));
    $b = max(0, min(255, $b + $steps));

    // تحويل RGB إلى HEX
    return "#" . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . 
           str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . 
           str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

/**
 * إضافة دعم للمستخدمين والتفضيلات
 */
function car_dealer_user_preferences() {
    // التحقق من وجود المستخدم
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();

    // الحصول على تفضيلات المستخدم
    $preferred_theme = car_dealer_get_user_preference($user_id, 'theme', 'default');
    $preferred_currency = car_dealer_get_user_preference($user_id, 'currency', 'SAR');

    // تطبيق التفضيلات
    if ($preferred_theme !== 'default') {
        add_filter('template', function() use ($preferred_theme) {
            return $preferred_theme;
        });
    }

    // إضافة متغيرات JavaScript للتفضيلات
    wp_localize_script('car-dealer-script', 'userPreferences', array(
        'theme' => $preferred_theme,
        'currency' => $preferred_currency
    ));
}
add_action('wp', 'car_dealer_user_preferences');

/**
 * تسجيل نقاط توقف للتصحيح
 */
if (!function_exists('car_dealer_debug')) {
    function car_dealer_debug($data, bool $die = false): void {
        if (current_user_can('manage_options')) {
            echo '<pre>';
            print_r($data);
            echo '</pre>';

            if ($die) {
                die;
            }
        }
    }
}
