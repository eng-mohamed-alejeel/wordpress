<?php
/**
 * إعداد قاعدة البيانات للقالب
 *
 * @package Car Dealer
 * @subpackage Database
 * @since Car Dealer 1.0
 */

// إنشاء الجداول اللازمة عند تفعيل القالب
function car_dealer_create_database_tables() {
    global $wpdb;

    // جدول لتخزين إعدادات التخصيص
    $customization_table = $wpdb->prefix . 'car_dealer_customizations';

    if ($wpdb->get_var("SHOW TABLES LIKE '$customization_table'") != $customization_table) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $customization_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            setting_key varchar(50) NOT NULL,
            setting_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY setting_key (setting_key)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // جدول لتخزين بيانات المقارنة
    $comparison_table = $wpdb->prefix . 'car_dealer_comparison';

    if ($wpdb->get_var("SHOW TABLES LIKE '$comparison_table'") != $comparison_table) {
        $sql = "CREATE TABLE $comparison_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            car_id bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY car_id (car_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    // جدول لتخزين بيانات حجز تجربة القيادة
    $booking_table = $wpdb->prefix . 'car_dealer_bookings';

    if ($wpdb->get_var("SHOW TABLES LIKE '$booking_table'") != $booking_table) {
        $sql = "CREATE TABLE $booking_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            car_id bigint(20) NOT NULL,
            name varchar(100) NOT NULL,
            phone varchar(20) NOT NULL,
            email varchar(100) NOT NULL,
            date date NOT NULL,
            time time NOT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY car_id (car_id),
            KEY date_time (date, time)
        ) $charset_collate;";

        dbDelta($sql);
    }
}
add_action('after_setup_theme', 'car_dealer_create_database_tables');

/**
 * الحصول على إعداد مخصص من قاعدة البيانات
 */
function car_dealer_get_customization($key, $default = '') {
    if (is_user_logged_in()) {
        global $wpdb;
        $table = $wpdb->prefix . 'car_dealer_customizations';
        $user_id = get_current_user_id();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM $table WHERE user_id = %d AND setting_key = %s",
            $user_id, $key
        ));

        return $value ? $value : $default;
    }

    return $default;
}

/**
 * حفظ إعداد مخصص في قاعدة البيانات
 */
function car_dealer_save_customization($key, $value) {
    if (is_user_logged_in()) {
        global $wpdb;
        $table = $wpdb->prefix . 'car_dealer_customizations';
        $user_id = get_current_user_id();

        // التحقق إذا كان الإعداد موجوداً مسبقاً
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND setting_key = %s",
            $user_id, $key
        ));

        if ($existing) {
            // تحديث الإعداد الموجود
            $wpdb->update(
                $table,
                array('setting_value' => $value),
                array('id' => $existing)
            );
        } else {
            // إضافة إعداد جديد
            $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'setting_key' => $key,
                    'setting_value' => $value
                )
            );
        }

        return true;
    }

    return false;
}

/**
 * إضافة سيارة إلى قائمة المقارنة
 */
function car_dealer_add_to_comparison($car_id) {
    if (!is_user_logged_in() || !is_numeric($car_id)) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_comparison';
    $user_id = get_current_user_id();

    // التحقق إذا كانت السيارة موجودة مسبقاً في قائمة المقارنة
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND car_id = %d",
        $user_id, $car_id
    ));

    if (!$existing) {
        // إضافة السيارة إلى قائمة المقارنة
        $result = $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'car_id' => $car_id
            )
        );

        return $result !== false;
    }

    return true; // تمت الإضافة بالفعل
}

/**
 * إزالة سيارة من قائمة المقارنة
 */
function car_dealer_remove_from_comparison($car_id) {
    if (!is_user_logged_in() || !is_numeric($car_id)) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_comparison';
    $user_id = get_current_user_id();

    $result = $wpdb->delete(
        $table,
        array(
            'user_id' => $user_id,
            'car_id' => $car_id
        )
    );

    return $result !== false;
}

/**
 * الحصول على قائمة المقارنة للمستخدم الحالي
 */
function car_dealer_get_comparison_list() {
    if (!is_user_logged_in()) {
        return array();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_comparison';
    $user_id = get_current_user_id();

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT car_id FROM $table WHERE user_id = %d",
        $user_id
    ));

    return $results ? array_column($results, 'car_id') : array();
}

/**
 * حجز تجربة قيادة
 */
function car_dealer_book_test_drive($data) {
    if (!is_numeric($data['car_id']) || !is_numeric($data['user_id'])) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_bookings';

    $result = $wpdb->insert(
        $table,
        array(
            'user_id' => $data['user_id'],
            'car_id' => $data['car_id'],
            'name' => sanitize_text_field($data['name']),
            'phone' => sanitize_text_field($data['phone']),
            'email' => sanitize_email($data['email']),
            'date' => sanitize_text_field($data['date']),
            'time' => sanitize_text_field($data['time']),
            'status' => 'pending'
        )
    );

    return $result !== false;
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
