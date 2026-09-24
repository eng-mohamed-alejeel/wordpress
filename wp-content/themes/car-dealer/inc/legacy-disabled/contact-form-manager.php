<?php
/**
 * Ù…Ø¯ÙŠØ± Ø§Ù„Ù†Ù…Ø§Ø°Ø¬ ÙˆØ§Ù„Ø±Ø³Ø§Ø¦Ù„ Ù„Ù„Ù‚Ø§Ù„Ø¨
 *
 * @package Car Dealer
 * @subpackage Contact Form
 * @since Car Dealer 1.0
 */

// Ø¥Ø¶Ø§ÙØ© Ø¯Ø¹Ù… Ù„ÙˆÙˆØ±Ø¯Ø¨Ø±ÙŠØ³ Ù„Ù„Ø±Ø³Ø§Ø¦Ù„
if (!function_exists('wp_mail')) {
    require_once(ABSPATH . 'wp-includes/pluggable.php');
}

// Ø¥Ø¶Ø§ÙØ© Ø¯Ø¹Ù… Ù„Ù€ AJAX
function car_dealer_load_scripts() {
    // Ø¥Ø¶Ø§ÙØ© Ù…Ù„Ù JavaScript Ù„Ù„Ù†Ù…Ø§Ø°Ø¬
    wp_enqueue_script(
        'car-dealer-contact-script',
        get_template_directory_uri() . '/js/contact-form.js',
        array('jquery'),
        wp_get_theme()->get('Version'),
        true
    );

    // Ø¥Ø¶Ø§ÙØ© Ù…ØªØºÙŠØ±Ø§Øª JavaScript Ù„Ù„Ù†Ù…Ø§Ø°Ø¬
    wp_localize_script('car-dealer-contact-script', 'carDealerContact', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('car_dealer_contact_nonce'),
        'success' => __('ØªÙ… Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø±Ø³Ø§Ù„Ø© Ø¨Ù†Ø¬Ø§Ø­', 'car-dealer'),
        'error' => __('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©', 'car-dealer'),
    ));
}
add_action('wp_enqueue_scripts', 'car_dealer_load_scripts');

// Ù…Ø¹Ø§Ù„Ø¬Ø© Ù†Ù…ÙˆØ°Ø¬ Ø§Ù„Ø§ØªØµØ§Ù„
function car_dealer_handle_contact_form() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_contact_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù…Ø±Ø³Ù„Ø©
    if (!isset($_POST['name']) || !isset($_POST['email']) || !isset($_POST['phone']) || !isset($_POST['message'])) {
        wp_send_json_error(__('Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø­Ù‚ÙˆÙ„ Ù…Ø·Ù„ÙˆØ¨Ø©', 'car-dealer'));
    }

    // ØªÙ†Ø¸ÙŠÙ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
    $name = sanitize_text_field($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone']);
    $message = sanitize_textarea_field($_POST['message']);

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØµØ­Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
    if (empty($name) || empty($email) || empty($phone) || empty($message)) {
        wp_send_json_error(__('Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø­Ù‚ÙˆÙ„ Ù…Ø·Ù„ÙˆØ¨Ø©', 'car-dealer'));
    }

    if (!is_email($email)) {
        wp_send_json_error(__('Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    // Ø¥Ø¹Ø¯Ø§Ø¯ Ù…Ø­ØªÙˆÙ‰ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    $subject = __('Ø±Ø³Ø§Ù„Ø© Ù…Ù† Ù…ÙˆÙ‚Ø¹ Ù…Ø¹Ø±Ø¶ Ø§Ù„Ø³ÙŠØ§Ø±Ø§Øª', 'car-dealer');
    $body = sprintf(
        "Ø§Ø³Ù…: %s

Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ: %s

Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ: %s

Ø§Ù„Ø±Ø³Ø§Ù„Ø©:
%s",
        $name,
        $email,
        $phone,
        $message
    );

    // Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>');

    $sent = wp_mail(get_option('contact_email', 'info@cardealer.com'), $subject, $body, $headers);

    if ($sent) {
        // Ø­ÙØ¸ Ø§Ù„Ø±Ø³Ø§Ù„Ø© ÙÙŠ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
        global $wpdb;
        $table = $wpdb->prefix . 'car_dealer_messages';

        $wpdb->insert(
            $table,
            array(
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
                'status' => 'new',
                'created_at' => current_time('mysql'),
            )
        );

        wp_send_json_success(__('ØªÙ… Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø±Ø³Ø§Ù„Ø© Ø¨Ù†Ø¬Ø§Ø­', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©', 'car-dealer'));
    }
}
add_action('wp_ajax_car_dealer_contact_form', 'car_dealer_handle_contact_form');
add_action('wp_ajax_nopriv_car_dealer_contact_form', 'car_dealer_handle_contact_form');

// Ù…Ø¹Ø§Ù„Ø¬Ø© Ù†Ù…ÙˆØ°Ø¬ Ø­Ø¬Ø² ØªØ¬Ø±Ø¨Ø© Ø§Ù„Ù‚ÙŠØ§Ø¯Ø©
function car_dealer_handle_test_drive_booking() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_test_drive_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù…Ø±Ø³Ù„Ø©
    if (!isset($_POST['name']) || !isset($_POST['email']) || !isset($_POST['phone']) || 
        !isset($_POST['car_id']) || !isset($_POST['date']) || !isset($_POST['time'])) {
        wp_send_json_error(__('Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø­Ù‚ÙˆÙ„ Ù…Ø·Ù„ÙˆØ¨Ø©', 'car-dealer'));
    }

    // ØªÙ†Ø¸ÙŠÙ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
    $name = sanitize_text_field($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone']);
    $car_id = intval($_POST['car_id']);
    $date = sanitize_text_field($_POST['date']);
    $time = sanitize_text_field($_POST['time']);

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØµØ­Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
    if (empty($name) || empty($email) || empty($phone) || empty($car_id) || empty($date) || empty($time)) {
        wp_send_json_error(__('Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø­Ù‚ÙˆÙ„ Ù…Ø·Ù„ÙˆØ¨Ø©', 'car-dealer'));
    }

    if (!is_email($email)) {
        wp_send_json_error(__('Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(__('ÙŠØ¬Ø¨ ØªØ³Ø¬ÙŠÙ„ Ø§Ù„Ø¯Ø®ÙˆÙ„ Ù„Ø­Ø¬Ø² ØªØ¬Ø±Ø¨Ø© Ù‚ÙŠØ§Ø¯Ø©', 'car-dealer'));
    }

    // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø³ÙŠØ§Ø±Ø©
    $car = get_post($car_id);
    if (!$car || $car->post_type !== 'car') {
        wp_send_json_error(__('Ø§Ù„Ø³ÙŠØ§Ø±Ø© ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯Ø©', 'car-dealer'));
    }

    // Ø¥Ø¹Ø¯Ø§Ø¯ Ù…Ø­ØªÙˆÙ‰ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    $subject = __('Ø·Ù„Ø¨ Ø­Ø¬Ø² ØªØ¬Ø±Ø¨Ø© Ù‚ÙŠØ§Ø¯Ø© - ' . $car->post_title, 'car-dealer');
    $body = sprintf(
        "Ø§Ø³Ù…: %s

Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ: %s

Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ: %s

Ø§Ù„Ø³ÙŠØ§Ø±Ø©: %s

Ø§Ù„ØªØ§Ø±ÙŠØ®: %s

Ø§Ù„ÙˆÙ‚Øª: %s

",
        $name,
        $email,
        $phone,
        $car->post_title,
        $date,
        $time
    );

    // Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>');

    $sent = wp_mail(get_option('contact_email', 'info@cardealer.com'), $subject, $body, $headers);

    if ($sent) {
        // Ø­ÙØ¸ Ø§Ù„Ø­Ø¬Ø² ÙÙŠ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
        global $wpdb;
        $table = $wpdb->prefix . 'car_dealer_bookings';

        $wpdb->insert(
            $table,
            array(
                'user_id' => get_current_user_id(),
                'car_id' => $car_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'date' => $date,
                'time' => $time,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            )
        );

        wp_send_json_success(__('ØªÙ… Ø¥Ø±Ø³Ø§Ù„ Ø·Ù„Ø¨ Ø§Ù„Ø­Ø¬Ø² Ø¨Ù†Ø¬Ø§Ø­', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø¥Ø±Ø³Ø§Ù„ Ø·Ù„Ø¨ Ø§Ù„Ø­Ø¬Ø²', 'car-dealer'));
    }
}
add_action('wp_ajax_car_dealer_test_drive_booking', 'car_dealer_handle_test_drive_booking');
add_action('wp_ajax_nopriv_car_dealer_test_drive_booking', 'car_dealer_handle_test_drive_booking');

// Ù…Ø¹Ø§Ù„Ø¬Ø© Ù†Ù…ÙˆØ°Ø¬ Ø§Ù„Ø§Ø´ØªØ±Ø§Ùƒ ÙÙŠ Ø§Ù„Ù†Ø´Ø±Ø© Ø§Ù„Ø¥Ø®Ø¨Ø§Ø±ÙŠØ©
function car_dealer_handle_newsletter_subscription() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_newsletter_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù…Ø±Ø³Ù„Ø©
    if (!isset($_POST['email'])) {
        wp_send_json_error(__('Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ Ù…Ø·Ù„ÙˆØ¨', 'car-dealer'));
    }

    // ØªÙ†Ø¸ÙŠÙ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
    $email = sanitize_email($_POST['email']);

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØµØ­Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
    if (empty($email)) {
        wp_send_json_error(__('Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ Ù…Ø·Ù„ÙˆØ¨', 'car-dealer'));
    }

    if (!is_email($email)) {
        wp_send_json_error(__('Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ø¥Ø°Ø§ ÙƒØ§Ù† Ø§Ù„Ù…Ø´ØªØ±Ùƒ Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹ Ù…Ø³Ø¨Ù‚Ø§Ù‹
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_subscribers';

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE email = %s",
        $email
    ));

    if ($existing) {
        wp_send_json_error(__('Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ Ù…Ø´ØªØ±Ùƒ Ù…Ø³Ø¨Ù‚Ø§Ù‹', 'car-dealer'));
    }

    // Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ù…Ø´ØªØ±Ùƒ
    $result = $wpdb->insert(
        $table,
        array(
            'email' => $email,
            'created_at' => current_time('mysql'),
        )
    );

    if ($result) {
        // Ø¥Ø±Ø³Ø§Ù„ Ø±Ø³Ø§Ù„Ø© ØªØ£ÙƒÙŠØ¯
        $subject = __('Ø§Ø´ØªØ±Ø§ÙƒÙƒ ÙÙŠ Ø§Ù„Ù†Ø´Ø±Ø© Ø§Ù„Ø¥Ø®Ø¨Ø§Ø±ÙŠØ© Ù„Ù…Ø¹Ø±Ø¶ Ø§Ù„Ø³ÙŠØ§Ø±Ø§Øª', 'car-dealer');
        $body = sprintf(
            "Ù…Ø±Ø­Ø¨Ø§Ù‹ Ø¨Ùƒ ÙÙŠ Ù†Ø´Ø±ØªÙ†Ø§ Ø§Ù„Ø¥Ø®Ø¨Ø§Ø±ÙŠØ©!


" .
            "Ø³ØªØµÙ„ÙƒÙ… Ø¢Ø®Ø± Ø§Ù„Ø¹Ø±ÙˆØ¶ ÙˆØ§Ù„Ù…Ø³ØªØ¬Ø¯Ø§Øª Ù…Ù† Ù…Ø¹Ø±Ø¶ Ø§Ù„Ø³ÙŠØ§Ø±Ø§Øª Ø¹Ù„Ù‰ Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ Ø§Ù„ØªØ§Ù„ÙŠ:
%s


" .
            "Ù…Ø¹ Ø£Ø·ÙŠØ¨ Ø§Ù„ØªØ­ÙŠØ§ØªØŒ
ÙØ±ÙŠÙ‚ Ù…Ø¹Ø±Ø¶ Ø§Ù„Ø³ÙŠØ§Ø±Ø§Øª",
            $email
        );

        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>');

        wp_mail($email, $subject, $body, $headers);

        wp_send_json_success(__('ØªÙ… Ø§Ù„Ø§Ø´ØªØ±Ø§Ùƒ Ø¨Ù†Ø¬Ø§Ø­', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø§Ù„Ø§Ø´ØªØ±Ø§Ùƒ', 'car-dealer'));
    }
}
add_action('wp_ajax_car_dealer_newsletter_subscription', 'car_dealer_handle_newsletter_subscription');
add_action('wp_ajax_nopriv_car_dealer_newsletter_subscription', 'car_dealer_handle_newsletter_subscription');

// Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯ÙˆÙ„ Ù„Ù„Ù…Ø´ØªØ±ÙƒÙŠÙ† ÙÙŠ Ø§Ù„Ù†Ø´Ø±Ø© Ø§Ù„Ø¥Ø®Ø¨Ø§Ø±ÙŠØ©
function car_dealer_create_subscribers_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_subscribers';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            email varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
add_action('after_setup_theme', 'car_dealer_create_subscribers_table');

// Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯ÙˆÙ„ Ù„Ù„Ø±Ø³Ø§Ø¦Ù„
function car_dealer_create_messages_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_messages';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) NOT NULL,
            message text NOT NULL,
            status varchar(20) DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
add_action('after_setup_theme', 'car_dealer_create_messages_table');

// Ø¥Ù†Ø´Ø§Ø¡ Ù‚Ø§Ø¦Ù…Ø© Ø±Ø³Ø§Ø¦Ù„ ÙÙŠ Ù„ÙˆØ­Ø© Ø§Ù„ØªØ­ÙƒÙ…
function car_dealer_add_messages_menu() {
    add_menu_page(
        'Ø§Ù„Ø±Ø³Ø§Ø¦Ù„', // Ø¹Ù†ÙˆØ§Ù† Ø§Ù„ØµÙØ­Ø©
        'Ø§Ù„Ø±Ø³Ø§Ø¦Ù„', // Ø¹Ù†ÙˆØ§Ù† Ø§Ù„Ù‚Ø§Ø¦Ù…Ø©
        'manage_options', // Ø§Ù„ØµÙ„Ø§Ø­ÙŠØ§Øª Ø§Ù„Ù…Ø·Ù„ÙˆØ¨Ø©
        'car-dealer-messages', // Ø§Ù„Ù…Ø¹Ø±Ù‘Ù Ø§Ù„ÙØ±ÙŠØ¯
        'car_dealer_messages_page_html', // Ø¯Ø§Ù„Ø© Ø¹Ø±Ø¶ Ø§Ù„Ù…Ø­ØªÙˆÙ‰
        'dashicons-email', // Ø£ÙŠÙ‚ÙˆÙ†Ø© Ø§Ù„Ù‚Ø³Ù…
        30 // Ø§Ù„Ù…ÙˆÙ‚Ø¹ ÙÙŠ Ø§Ù„Ù‚Ø§Ø¦Ù…Ø©
    );

    add_submenu_page(
        'car-dealer-messages',
        'Ø§Ù„Ø±Ø³Ø§Ø¦Ù„ Ø§Ù„Ø¬Ø¯ÙŠØ¯Ø©',
        'Ø§Ù„Ø±Ø³Ø§Ø¦Ù„ Ø§Ù„Ø¬Ø¯ÙŠØ¯Ø©',
        'manage_options',
        'car-dealer-messages',
        'car_dealer_messages_page_html'
    );

    add_submenu_page(
        'car-dealer-messages',
        'Ø§Ù„Ù…Ø´ØªØ±ÙƒÙŠÙ†',
        'Ø§Ù„Ù…Ø´ØªØ±ÙƒÙˆÙ†',
        'manage_options',
        'car-dealer-subscribers',
        'car_dealer_subscribers_page_html'
    );

    add_submenu_page(
        'car-dealer-messages',
        'Ø§Ù„Ø­Ø¬ÙˆØ²Ø§Øª',
        'Ø­Ø¬ÙˆØ²Ø§Øª ØªØ¬Ø±Ø¨Ø© Ø§Ù„Ù‚ÙŠØ§Ø¯Ø©',
        'manage_options',
        'car-dealer-bookings',
        'car_dealer_bookings_page_html'
    );
}
add_action('admin_menu', 'car_dealer_add_messages_menu');

// Ø¯Ø§Ù„Ø© Ø¹Ø±Ø¶ ØµÙØ­Ø© Ø§Ù„Ø±Ø³Ø§Ø¦Ù„
function car_dealer_messages_page_html() {
    ?>
    <div class="wrap">
        <h1>Ø§Ù„Ø±Ø³Ø§Ø¦Ù„</h1>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <form method="post">
                    <select name="message_status">
                        <option value="">Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø­Ø§Ù„Ø§Øª</option>
                        <option value="new" <?php if (isset($_GET['status']) && $_GET['status'] == 'new') echo 'selected'; ?>>Ø¬Ø¯ÙŠØ¯Ø©</option>
                        <option value="read" <?php if (isset($_GET['status']) && $_GET['status'] == 'read') echo 'selected'; ?>>Ù…Ù‚Ø±ÙˆØ¡Ø©</option>
                        <option value="replied" <?php if (isset($_GET['status']) && $_GET['status'] == 'replied') echo 'selected'; ?>>Ù…Ø±Ø¯ÙˆØ¯ Ø¹Ù„ÙŠÙ‡Ø§</option>
                    </select>
                    <input type="submit" name="filter_messages" class="button action" value="ÙÙ„ØªØ±Ø©">
                </form>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </th>
                    <th scope="col">Ø§Ù„Ø§Ø³Ù…</th>
                    <th scope="col">Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ</th>
                    <th scope="col">Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ</th>
                    <th scope="col">Ø§Ù„Ø­Ø§Ù„Ø©</th>
                    <th scope="col">Ø§Ù„ØªØ§Ø±ÙŠØ®</th>
                    <th scope="col">Ø§Ù„Ø¥Ø¬Ø±Ø§Ø¡Ø§Øª</th>
                </tr>
            </thead>
            <tbody>
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'car_dealer_messages';
                $status = isset($_GET['status']) ? $_GET['status'] : '';

                $where = array();
                if (!empty($status)) {
                    $where[] = "status = '$status'";
                }

                $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

                $messages = $wpdb->get_results("SELECT * FROM $table $where_clause ORDER BY created_at DESC");

                if ($messages) {
                    foreach ($messages as $message) {
                        ?>
                        <tr>
                            <td><input type="checkbox" name="message_ids[]" value="<?php echo esc_attr($message->id); ?>"></td>
                            <td><?php echo esc_html($message->name); ?></td>
                            <td><?php echo esc_html($message->email); ?></td>
                            <td><?php echo esc_html($message->phone); ?></td>
                            <td><?php echo esc_html($message->status); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($message->created_at))); ?></td>
                            <td>
                                <a href="#" class="view-message button" data-id="<?php echo esc_attr($message->id); ?>">Ø¹Ø±Ø¶</a>
                                <?php if ($message->status == 'new') : ?>
                                    <a href="#" class="mark-read button" data-id="<?php echo esc_attr($message->id); ?>">ØªØ¹ÙŠÙŠÙ† ÙƒÙ…Ù‚Ø±ÙˆØ¡Ø©</a>
                                <?php endif; ?>
                                <a href="mailto:<?php echo esc_attr($message->email); ?>" class="button">Ø±Ø¯</a>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="7">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø±Ø³Ø§Ø¦Ù„</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <div id="message-modal" style="display: none;">
        <div class="modal-content">
            <h3>Ø¹Ø±Ø¶ Ø§Ù„Ø±Ø³Ø§Ù„Ø©</h3>
            <div class="message-details"></div>
            <button class="button button-primary close-modal">Ø¥ØºÙ„Ø§Ù‚</button>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Ø¹Ø±Ø¶ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
        $('.view-message').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');

            $.ajax({
                type: 'POST',
                url: carDealerContact.ajaxurl,
                data: {
                    action: 'get_message_details',
                    nonce: carDealerContact.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        $('.message-details').html(response.data);
                        $('#message-modal').show();
                    } else {
                        alert('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø¬Ù„Ø¨ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©');
                    }
                }
            });
        });

        // ØªØ¹ÙŠÙŠÙ† Ø§Ù„Ø±Ø³Ø§Ù„Ø© ÙƒÙ…Ù‚Ø±ÙˆØ¡Ø©
        $('.mark-read').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');

            $.ajax({
                type: 'POST',
                url: carDealerContact.ajaxurl,
                data: {
                    action: 'mark_message_as_read',
                    nonce: carDealerContact.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ ØªØ­Ø¯ÙŠØ« Ø­Ø§Ù„Ø© Ø§Ù„Ø±Ø³Ø§Ù„Ø©');
                    }
                }
            });
        });

        // Ø¥ØºÙ„Ø§Ù‚ Ø§Ù„Ù†Ø§ÙØ°Ø©
        $('.close-modal').on('click', function() {
            $('#message-modal').hide();
        });
    });
    </script>
    <?php
}

// Ø¯Ø§Ù„Ø© Ø¹Ø±Ø¶ ØµÙØ­Ø© Ø§Ù„Ù…Ø´ØªØ±ÙƒÙŠÙ†
function car_dealer_subscribers_page_html() {
    ?>
    <div class="wrap">
        <h1>Ø§Ù„Ù…Ø´ØªØ±ÙƒÙˆÙ† ÙÙŠ Ø§Ù„Ù†Ø´Ø±Ø© Ø§Ù„Ø¥Ø®Ø¨Ø§Ø±ÙŠØ©</h1>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <form method="post">
                    <input type="submit" name="export_subscribers" class="button action" value="ØªØµØ¯ÙŠØ± Ø§Ù„Ù…Ø´ØªØ±ÙƒÙŠÙ†">
                </form>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </th>
                    <th scope="col">Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ</th>
                    <th scope="col">ØªØ§Ø±ÙŠØ® Ø§Ù„Ø§Ø´ØªØ±Ø§Ùƒ</th>
                    <th scope="col">Ø§Ù„Ø¥Ø¬Ø±Ø§Ø¡Ø§Øª</th>
                </tr>
            </thead>
            <tbody>
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'car_dealer_subscribers';

                $subscribers = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

                if ($subscribers) {
                    foreach ($subscribers as $subscriber) {
                        ?>
                        <tr>
                            <td><input type="checkbox" name="subscriber_ids[]" value="<?php echo esc_attr($subscriber->id); ?>"></td>
                            <td><?php echo esc_html($subscriber->email); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($subscriber->created_at))); ?></td>
                            <td>
                                <a href="mailto:<?php echo esc_attr($subscriber->email); ?>" class="button">Ù…Ø±Ø§Ø³Ù„Ø©</a>
                                <a href="#" class="button button-primary delete-subscriber" data-id="<?php echo esc_attr($subscriber->id); ?>">Ø­Ø°Ù</a>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="4">Ù„Ø§ ÙŠÙˆØ¬Ø¯ Ù…Ø´ØªØ±ÙƒÙˆÙ†</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Ø­Ø°Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ
        $('.delete-subscriber').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');

            if (confirm('Ù‡Ù„ Ø£Ù†Øª Ù…ØªØ£ÙƒØ¯ Ù…Ù† Ø­Ø°Ù Ù‡Ø°Ø§ Ø§Ù„Ù…Ø´ØªØ±ÙƒØŸ')) {
                $.ajax({
                    type: 'POST',
                    url: carDealerContact.ajaxurl,
                    data: {
                        action: 'delete_subscriber',
                        nonce: carDealerContact.nonce,
                        id: id
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø­Ø°Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ');
                        }
                    }
                });
            }
        });
    });
    </script>
    <?php
}

// Ø¯Ø§Ù„Ø© Ø¹Ø±Ø¶ ØµÙØ­Ø© Ø§Ù„Ø­Ø¬ÙˆØ²Ø§Øª
function car_dealer_bookings_page_html() {
    ?>
    <div class="wrap">
        <h1>Ø­Ø¬ÙˆØ²Ø§Øª ØªØ¬Ø±Ø¨Ø© Ø§Ù„Ù‚ÙŠØ§Ø¯Ø©</h1>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <form method="post">
                    <select name="booking_status">
                        <option value="">Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø­Ø§Ù„Ø§Øª</option>
                        <option value="pending" <?php if (isset($_GET['status']) && $_GET['status'] == 'pending') echo 'selected'; ?>>Ù‚ÙŠØ¯ Ø§Ù„Ø§Ù†ØªØ¸Ø§Ø±</option>
                        <option value="confirmed" <?php if (isset($_GET['status']) && $_GET['status'] == 'confirmed') echo 'selected'; ?>>Ù…Ø¤ÙƒØ¯</option>
                        <option value="cancelled" <?php if (isset($_GET['status']) && $_GET['status'] == 'cancelled') echo 'selected'; ?>>Ù…Ù„ØºÙ‰</option>
                    </select>
                    <input type="submit" name="filter_bookings" class="button action" value="ÙÙ„ØªØ±Ø©">
                </form>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </th>
                    <th scope="col">Ø§Ù„Ø§Ø³Ù…</th>
                    <th scope="col">Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ</th>
                    <th scope="col">Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ</th>
                    <th scope="col">Ø§Ù„Ø³ÙŠØ§Ø±Ø©</th>
                    <th scope="col">Ø§Ù„ØªØ§Ø±ÙŠØ®</th>
                    <th scope="col">Ø§Ù„ÙˆÙ‚Øª</th>
                    <th scope="col">Ø§Ù„Ø­Ø§Ù„Ø©</th>
                    <th scope="col">Ø§Ù„Ø¥Ø¬Ø±Ø§Ø¡Ø§Øª</th>
                </tr>
            </thead>
            <tbody>
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'car_dealer_bookings';
                $status = isset($_GET['status']) ? $_GET['status'] : '';

                $where = array();
                if (!empty($status)) {
                    $where[] = "status = '$status'";
                }

                $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

                $bookings = $wpdb->get_results("
                    SELECT b.*, c.post_title as car_title 
                    FROM $table b 
                    LEFT JOIN {$wpdb->prefix}posts c ON b.car_id = c.ID 
                    $where_clause 
                    ORDER BY b.created_at DESC
                ");

                if ($bookings) {
                    foreach ($bookings as $booking) {
                        ?>
                        <tr>
                            <td><input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr($booking->id); ?>"></td>
                            <td><?php echo esc_html($booking->name); ?></td>
                            <td><?php echo esc_html($booking->email); ?></td>
                            <td><?php echo esc_html($booking->phone); ?></td>
                            <td><?php echo esc_html($booking->car_title); ?></td>
                            <td><?php echo esc_html($booking->date); ?></td>
                            <td><?php echo esc_html($booking->time); ?></td>
                            <td><?php echo esc_html($booking->status); ?></td>
                            <td>
                                <?php if ($booking->status == 'pending') : ?>
                                    <a href="#" class="button button-primary confirm-booking" data-id="<?php echo esc_attr($booking->id); ?>">ØªØ£ÙƒÙŠØ¯</a>
                                    <a href="#" class="button button-secondary cancel-booking" data-id="<?php echo esc_attr($booking->id); ?>">Ø¥Ù„ØºØ§Ø¡</a>
                                <?php endif; ?>
                                <a href="mailto:<?php echo esc_attr($booking->email); ?>" class="button">Ù…Ø±Ø§Ø³Ù„Ø©</a>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="9">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø­Ø¬ÙˆØ²Ø§Øª</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // ØªØ£ÙƒÙŠØ¯ Ø§Ù„Ø­Ø¬Ø²
        $('.confirm-booking').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');

            if (confirm('Ù‡Ù„ Ø£Ù†Øª Ù…ØªØ£ÙƒØ¯ Ù…Ù† ØªØ£ÙƒÙŠØ¯ Ù‡Ø°Ø§ Ø§Ù„Ø­Ø¬Ø²ØŸ')) {
                $.ajax({
                    type: 'POST',
                    url: carDealerContact.ajaxurl,
                    data: {
                        action: 'confirm_booking',
                        nonce: carDealerContact.nonce,
                        id: id
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ ØªØ£ÙƒÙŠØ¯ Ø§Ù„Ø­Ø¬Ø²');
                        }
                    }
                });
            }
        });

        // Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¬Ø²
        $('.cancel-booking').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');

            if (confirm('Ù‡Ù„ Ø£Ù†Øª Ù…ØªØ£ÙƒØ¯ Ù…Ù† Ø¥Ù„ØºØ§Ø¡ Ù‡Ø°Ø§ Ø§Ù„Ø­Ø¬Ø²ØŸ')) {
                $.ajax({
                    type: 'POST',
                    url: carDealerContact.ajaxurl,
                    data: {
                        action: 'cancel_booking',
                        nonce: carDealerContact.nonce,
                        id: id
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¬Ø²');
                        }
                    }
                });
            }
        });
    });
    </script>
    <?php
}

// Ø¯Ø§Ù„Ø© Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
function car_dealer_get_message_details() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_contact_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        wp_send_json_error(__('Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø±Ø³Ø§Ù„Ø© ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    $id = intval($_POST['id']);

    // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_messages';

    $message = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

    if ($message) {
        // ØªØ­Ø¯ÙŠØ« Ø§Ù„Ø­Ø§Ù„Ø© Ø¥Ù„Ù‰ Ù…Ù‚Ø±ÙˆØ¡Ø©
        $wpdb->update(
            $table,
            array('status' => 'read'),
            array('id' => $id)
        );

        // Ø¹Ø±Ø¶ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø±Ø³Ø§Ù„Ø©
        $output = '<p><strong>Ø§Ù„Ø§Ø³Ù…:</strong> ' . esc_html($message->name) . '</p>';
        $output .= '<p><strong>Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ:</strong> ' . esc_html($message->email) . '</p>';
        $output .= '<p><strong>Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ:</strong> ' . esc_html($message->phone) . '</p>';
        $output .= '<p><strong>Ø§Ù„Ø±Ø³Ø§Ù„Ø©:</strong></p>';
        $output .= '<div class="message-content">' . nl2br(esc_html($message->message)) . '</div>';

        wp_send_json_success($output);
    } else {
        wp_send_json_error(__('Ø§Ù„Ø±Ø³Ø§Ù„Ø© ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯Ø©', 'car-dealer'));
    }
}
add_action('wp_ajax_get_message_details', 'car_dealer_get_message_details');

// Ø¯Ø§Ù„Ø© ØªØ¹ÙŠÙŠÙ† Ø§Ù„Ø±Ø³Ø§Ù„Ø© ÙƒÙ…Ù‚Ø±ÙˆØ¡Ø©
function car_dealer_mark_message_as_read() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_contact_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø±Ø³Ø§Ù„Ø©
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        wp_send_json_error(__('Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø±Ø³Ø§Ù„Ø© ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    $id = intval($_POST['id']);

    // ØªØ­Ø¯ÙŠØ« Ø§Ù„Ø­Ø§Ù„Ø© Ø¥Ù„Ù‰ Ù…Ù‚Ø±ÙˆØ¡Ø©
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_messages';

    $result = $wpdb->update(
        $table,
        array('status' => 'read'),
        array('id' => $id)
    );

    if ($result !== false) {
        wp_send_json_success(__('ØªÙ… ØªØ­Ø¯ÙŠØ« Ø­Ø§Ù„Ø© Ø§Ù„Ø±Ø³Ø§Ù„Ø©', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ ØªØ­Ø¯ÙŠØ« Ø­Ø§Ù„Ø© Ø§Ù„Ø±Ø³Ø§Ù„Ø©', 'car-dealer'));
    }
}
add_action('wp_ajax_mark_message_as_read', 'car_dealer_mark_message_as_read');

// Ø¯Ø§Ù„Ø© Ø­Ø°Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ
function car_dealer_delete_subscriber() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_contact_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        wp_send_json_error(__('Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    $id = intval($_POST['id']);

    // Ø­Ø°Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_subscribers';

    $result = $wpdb->delete(
        $table,
        array('id' => $id)
    );

    if ($result !== false) {
        wp_send_json_success(__('ØªÙ… Ø­Ø°Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø­Ø°Ù Ø§Ù„Ù…Ø´ØªØ±Ùƒ', 'car-dealer'));
    }
}
add_action('wp_ajax_delete_subscriber', 'car_dealer_delete_subscriber');

// Ø¯Ø§Ù„Ø© ØªØ£ÙƒÙŠØ¯ Ø§Ù„Ø­Ø¬Ø²
function car_dealer_confirm_booking() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_contact_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø­Ø¬Ø²
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        wp_send_json_error(__('Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø­Ø¬Ø² ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    $id = intval($_POST['id']);

    // ØªØ­Ø¯ÙŠØ« Ø§Ù„Ø­Ø§Ù„Ø© Ø¥Ù„Ù‰ Ù…Ø¤ÙƒØ¯
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_bookings';

    $result = $wpdb->update(
        $table,
        array('status' => 'confirmed'),
        array('id' => $id)
    );

    if ($result !== false) {
        // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø­Ø¬Ø²
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if ($booking) {
            // Ø¥Ø±Ø³Ø§Ù„ Ø±Ø³Ø§Ù„Ø© ØªØ£ÙƒÙŠØ¯
            $subject = __('ØªÙ… ØªØ£ÙƒÙŠØ¯ Ø­Ø¬Ø² ØªØ¬Ø±Ø¨Ø© Ø§Ù„Ù‚ÙŠØ§Ø¯Ø©', 'car-dealer');
            $body = sprintf(
                "Ù…Ø±Ø­Ø¨Ø§Ù‹ %sØŒ

" .
                "ØªÙ… ØªØ£ÙƒÙŠØ¯ Ø­Ø¬Ø²Ùƒ Ù„ØªØ¬Ø±Ø¨Ø© Ù‚ÙŠØ§Ø¯Ø© Ø§Ù„Ø³ÙŠØ§Ø±Ø© Ø§Ù„ØªØ§Ù„ÙŠØ©:

" .
                "Ø§Ù„Ø³ÙŠØ§Ø±Ø©: %s
" .
                "Ø§Ù„ØªØ§Ø±ÙŠØ®: %s
" .
                "Ø§Ù„ÙˆÙ‚Øª: %s

" .
                "Ù†ØªØ·Ù„Ø¹ Ù„Ø§Ø³ØªÙ‚Ø¨Ø§Ù„Ùƒ ÙÙŠ ÙØ±Ø¹Ù†Ø§ Ø§Ù„Ù…Ø­Ø¯Ø¯.

" .
                "Ù…Ø¹ Ø£Ø·ÙŠØ¨ Ø§Ù„ØªØ­ÙŠØ§ØªØŒ
ÙØ±ÙŠÙ‚ Ù…Ø¹Ø±Ø¶ Ø§Ù„Ø³ÙŠØ§Ø±Ø§Øª",
                $booking->name,
                get_the_title($booking->car_id),
                $booking->date,
                $booking->time
            );

            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>');

            wp_mail($booking->email, $subject, $body, $headers);
        }

        wp_send_json_success(__('ØªÙ… ØªØ£ÙƒÙŠØ¯ Ø§Ù„Ø­Ø¬Ø²', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ ØªØ£ÙƒÙŠØ¯ Ø§Ù„Ø­Ø¬Ø²', 'car-dealer'));
    }
}
add_action('wp_ajax_confirm_booking', 'car_dealer_confirm_booking');

// Ø¯Ø§Ù„Ø© Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¬Ø²
function car_dealer_cancel_booking() {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_dealer_contact_nonce')) {
        wp_send_json_error(__('Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø£Ù…Ø§Ù† ÙØ´Ù„', 'car-dealer'));
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø­Ø¬Ø²
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        wp_send_json_error(__('Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ø­Ø¬Ø² ØºÙŠØ± ØµØ§Ù„Ø­', 'car-dealer'));
    }

    $id = intval($_POST['id']);

    // ØªØ­Ø¯ÙŠØ« Ø§Ù„Ø­Ø§Ù„Ø© Ø¥Ù„Ù‰ Ù…Ù„ØºÙ‰
    global $wpdb;
    $table = $wpdb->prefix . 'car_dealer_bookings';

    $result = $wpdb->update(
        $table,
        array('status' => 'cancelled'),
        array('id' => $id)
    );

    if ($result !== false) {
        // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø­Ø¬Ø²
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if ($booking) {
            // Ø¥Ø±Ø³Ø§Ù„ Ø±Ø³Ø§Ù„Ø© Ø¥Ù„ØºØ§Ø¡
            $subject = __('ØªÙ… Ø¥Ù„ØºØ§Ø¡ Ø­Ø¬Ø² ØªØ¬Ø±Ø¨Ø© Ø§Ù„Ù‚ÙŠØ§Ø¯Ø©', 'car-dealer');
            $body = sprintf(
                "Ù…Ø±Ø­Ø¨Ø§Ù‹ %sØŒ

" .
                "ØªÙ… Ø¥Ù„ØºØ§Ø¡ Ø­Ø¬Ø²Ùƒ Ù„ØªØ¬Ø±Ø¨Ø© Ù‚ÙŠØ§Ø¯Ø© Ø§Ù„Ø³ÙŠØ§Ø±Ø© Ø§Ù„ØªØ§Ù„ÙŠØ©:

" .
                "Ø§Ù„Ø³ÙŠØ§Ø±Ø©: %s
" .
                "Ø§Ù„ØªØ§Ø±ÙŠØ®: %s
" .
                "Ø§Ù„ÙˆÙ‚Øª: %s

" .
                "ÙŠÙ…ÙƒÙ†Ùƒ Ø­Ø¬Ø² ØªØ¬Ø±Ø¨Ø© Ø£Ø®Ø±Ù‰ ÙÙŠ Ø£ÙŠ ÙˆÙ‚Øª ØªÙ†Ø§Ø³Ø¨Ù‡.

" .
                "Ù…Ø¹ Ø£Ø·ÙŠØ¨ Ø§Ù„ØªØ­ÙŠØ§ØªØŒ
ÙØ±ÙŠÙ‚ Ù…Ø¹Ø±Ø¶ Ø§Ù„Ø³ÙŠØ§Ø±Ø§Øª",
                $booking->name,
                get_the_title($booking->car_id),
                $booking->date,
                $booking->time
            );

            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>');

            wp_mail($booking->email, $subject, $body, $headers);
        }

        wp_send_json_success(__('ØªÙ… Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¬Ø²', 'car-dealer'));
    } else {
        wp_send_json_error(__('Ø­Ø¯Ø« Ø®Ø·Ø£ Ø£Ø«Ù†Ø§Ø¡ Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¬Ø²', 'car-dealer'));
    }
}
add_action('wp_ajax_cancel_booking', 'car_dealer_cancel_booking');


