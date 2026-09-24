<?php
/**
 * الوظائف الأمامية لقالب معرض السيارات
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
 * إضافة مسار الملفات الإضافية
 */
require_once get_template_directory() . '/inc/database-integration.php';

/**
 * تعديل الترويسة لعرض الإعدادات المخصصة
 */
function car_dealer_custom_header() {
    // الحصول على الإعدادات المخصصة
    $theme_color = car_dealer_get_setting('theme_color', '#3498db', 'general');
    $logo_width = car_dealer_get_setting('logo_width', '200', 'general');

    // إضافة الأنماط المخصصة
    ?>
    <style>
        :root {
            --primary-color: <?php echo esc_attr($theme_color); ?>;
        }

        .logo img, .logo h1 {
            width: <?php echo esc_attr($logo_width); ?>px;
        }

        .btn, .btn-outline {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline:hover, .btn:hover {
            background-color: <?php echo esc_attr(darken_color($theme_color, 10)); ?>;
            border-color: <?php echo esc_attr(darken_color($theme_color, 10)); ?>;
        }

        .car-badge {
            background-color: var(--primary-color);
        }

        .filter-btn.active {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .feature-icon {
            background-color: var(--primary-color);
        }
    </style>
    <?php
}
add_action('wp_head', 'car_dealer_custom_header');

/**
 * تعديل التذييل لعرض الإعدادات المخصصة
 */
function car_dealer_custom_footer() {
    // الحصول على الإعدادات المخصصة
    $site_description = car_dealer_get_setting('site_description', '', 'general');

    // تعديل الوصف إذا كان مخصصاً
    if (!empty($site_description)) {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var footerDescription = document.querySelector('.footer-about p');
                if (footerDescription) {
                    footerDescription.textContent = '<?php echo esc_js($site_description); ?>';
                }
            });
        </script>
        <?php
    }
}
add_action('wp_footer', 'car_dealer_custom_footer');

/**
 * تعديل صفحة السيارات لعرض الإعدادات المخصصة
 */
function car_dealer_custom_cars_display() {
    // الحصول على الإعدادات المخصصة
    $featured_cars_count = car_dealer_get_setting('featured_cars_count', '6', 'display');
    $show_car_specs = car_dealer_get_setting('show_car_specs', '1', 'display');

    // تعديل عدد السيارات المميزة
    if (is_front_page()) {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // يمكن استخدام هذا لتعديل عدد السيارات المعروضة
                console.log('عدد السيارات المميزة: <?php echo esc_attr($featured_cars_count); ?>');
            });
        </script>
        <?php
    }

    // إظهار أو إخفاء مواصفات السيارة
    if (!$show_car_specs) {
        ?>
        <style>
            .car-specs {
                display: none;
            }
        </style>
        <?php
    }
}
add_action('wp_head', 'car_dealer_custom_cars_display');

/**
 * إضافة قسم شهادات العملاء إذا كان مفعلاً
 */
function car_dealer_add_testimonials_section() {
    $show_testimonials = car_dealer_get_setting('show_testimonials', '1', 'display');

    if ($show_testimonials) {
        ?>
        <section class="testimonials">
            <div class="container">
                <h2>ماذا يقول عملاؤنا</h2>
                <div class="testimonials-slider">
                    <?php
                    // الحصول على شهادات العملاء من قاعدة البيانات
                    $testimonials = car_dealer_get_testimonials();

                    if (!empty($testimonials)) {
                        foreach ($testimonials as $testimonial) {
                            ?>
                            <div class="testimonial-card">
                                <div class="testimonial-content">
                                    <p><?php echo esc_html($testimonial->content); ?></p>
                                </div>
                                <div class="testimonial-author">
                                    <div class="author-avatar">
                                        <img src="<?php echo esc_url($testimonial->avatar); ?>" alt="<?php echo esc_attr($testimonial->name); ?>">
                                    </div>
                                    <div class="author-info">
                                        <h4><?php echo esc_html($testimonial->name); ?></h4>
                                        <p><?php echo esc_html($testimonial->position); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
        </section>
        <?php
    }
}
add_action('car_dealer_after_cars_section', 'car_dealer_add_testimonials_section');

/**
 * الحصول على شهادات العملاء من قاعدة البيانات
 */
function car_dealer_get_testimonials($limit = 3) {
    global $wpdb;

    // يمكن إضافة جدول شهادات العملاء لاحقاً
    // مؤقتاً نستخدم بيانات ثابتة
    $testimonials = array(
        (object) array(
            'name' => 'أحمد محمد',
            'position' => 'عميل راضٍ',
            'content' => 'خدمة ممتازة وسيارة في حالة ممتازة، سأعود لشراء المزيد من السيارات مستقبلاً.',
            'avatar' => 'https://via.placeholder.com/60x60?text=أحمد'
        ),
        (object) array(
            'name' => 'فاطمة علي',
            'position' => 'عميلة راضية',
            'content' => 'تجربة رائعة مع معرض السيارات، الموظفون متعاونون والأسعار تنافسية.',
            'avatar' => 'https://via.placeholder.com/60x60?text=فاطمة'
        ),
        (object) array(
            'name' => 'خالد سعود',
            'position' => 'عميل مستمر',
            'content' => 'اشتريت سيارتي من هنا منذ عام والخدمة ما زال ممتازة، أنصح بالتعامل معهم.',
            'avatar' => 'https://via.placeholder.com/60x60?text=خالد'
        )
    );

    return array_slice($testimonials, 0, $limit);
}

/**
 * إضافة قسم الميزات الإضافية
 */
function car_dealer_add_features_section() {
    ?>
    <section class="additional-features">
        <div class="container">
            <h2>خدماتنا المميزة</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">🔧</div>
                    <h3>صيانة متخصصة</h3>
                    <p>نقدم خدمات صيانة متخصصة لجميع أنواع السيارات</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🛡️</div>
                    <h3>ضمان شامل</h3>
                    <p>نقدم ضمان شامل على جميع السيارات مع تغطية واسعة</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">💸</div>
                    <h3>تمويل سهل</h3>
                    <p>نقدم خيارات تمويل مرنة تناسب جميع الميزانيات</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🚚</div>
                    <h3>توصيل سريع</h3>
                    <p>نوفر خدمة توصيل سريع لجميع أنحاء المملكة</p>
                </div>
            </div>
        </div>
    </section>
    <?php
}
add_action('car_dealer_after_testimonials_section', 'car_dealer_add_features_section');

/**
 * تعديل نموذج التواصل للتعامل مع الإعدادات المخصصة
 */
function car_dealer_custom_contact_form() {
    // الحصول على الإعدادات المخصصة
    $contact_email = car_dealer_get_setting('contact_form_email', get_option('admin_email'), 'contact');

    // تعديل البريد الإلكتروني في النموذج
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var contactForm = document.getElementById('contact-form');
            if (contactForm) {
                contactForm.setAttribute('data-email', '<?php echo esc_attr($contact_email); ?>');
            }
        });
    </script>
    <?php
}
add_action('wp_footer', 'car_dealer_custom_contact_form');

/**
 * إضافة دعم لقوائم مخصصة
 */
function car_dealer_custom_menus() {
    // إضافة قائمة مخصصة للسيارات المميزة
    register_nav_menu('featured-cars', 'السيارات المميزة');

    // إضافة قائمة مخصصة للخدمات
    register_nav_menu('services', 'الخدمات');
}
add_action('after_setup_theme', 'car_dealer_custom_menus');

/**
 * تعديل صفحة السيارات الفردية لعرض الإعدادات المخصصة
 */
function car_dealer_custom_single_car() {
    // الحصول على الإعدادات المخصصة
    $show_car_specs = car_dealer_get_setting('show_car_specs', '1', 'display');

    // إضافة معلومات إضافية للسيارة
    if (is_singular('car')) {
        global $post;

        // الحصول على معلومات إضافية من قاعدة البيانات
        $additional_info = car_dealer_get_car_additional_info($post->ID);

        if (!empty($additional_info)) {
            ?>
            <div class="additional-car-info">
                <h3>معلومات إضافية</h3>
                <ul>
                    <?php foreach ($additional_info as $key => $value) : ?>
                        <li><strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html($value); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
    }
}
add_action('car_dealer_after_car_details', 'car_dealer_custom_single_car');

/**
 * الحصول على معلومات إضافية للسيارة
 */
function car_dealer_get_car_additional_info(int $car_id): array {
    // يمكن إضافة جدول لمعلومات إضافية للسيارات لاحقاً
    // مؤقتاً نستخدم بيانات ثابتة
    $info = array();

    // معلومات إضافية من الحقول المخصصة
    $additional_fields = array(
        '_car_engine' => 'المحرك',
        '_car_horsepower' => 'قوة الحصان',
        '_car_interior' => 'داخلية السيارة',
        '_car_safety_features' => 'ميزات الأمان'
    );

    foreach ($additional_fields as $field => $label) {
        $value = get_post_meta($car_id, $field, true);
        if (!empty($value)) {
            $info[$label] = $value;
        }
    }

    return $info;
}

/**
 * دالة مساعدة لتغميق الألوان
 */
function darken_color(string $color, int $percent): string {
    $color = str_replace('#', '', $color);
    $rgb = sscanf($color, '%2x%2x%2x');

    $darkened = array(
        max(0, $rgb[0] - $percent),
        max(0, $rgb[1] - $percent),
        max(0, $rgb[2] - $percent)
    );

    return sprintf('#%02x%02x%02x', $darkened[0], $darkened[1], $darkened[2]);
}
