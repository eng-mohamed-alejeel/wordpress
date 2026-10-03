<?php
defined( 'ABSPATH' ) || exit;
$view = car_dealer_account_view(); $error = $GLOBALS['cd_account_error'] ?? '';
$core_available = function_exists( 'adc_customer_account_process' ) && function_exists( 'adc_core_owns_customer_account_actions' ) && adc_core_owns_customer_account_actions();
$catalog_url = car_dealer_archive_url( 'car' );
get_header();
ob_start();
?>
<div class="container cd-account" dir="<?php echo esc_attr( car_dealer_catalog_direction() ); ?>" lang="<?php echo esc_attr( car_dealer_catalog_language() ); ?>">
<?php if ( $error && $core_available ) : ?><div class="cd-account-notice is-error" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
<?php if ( ! $core_available ) : ?>
<section class="cd-account-panel"><h1><?php esc_html_e( 'الحساب غير متاح حاليًا', 'car-dealer' ); ?></h1><p><?php esc_html_e( 'خدمة المعرض غير متاحة. يرجى المحاولة لاحقًا.', 'car-dealer' ); ?></p></section>
<?php elseif ( 'dashboard' !== $view ) : $register = 'register' === $view; ?>
<section class="cd-auth-card">
<div class="cd-auth-intro"><span>أهلاً بك في <?php echo esc_html( get_bloginfo( 'name' ) ); ?></span><h1><?php echo $register ? 'ابدأ رحلتك معنا' : 'سعداء بعودتك'; ?></h1><p>حساب واحد لمتابعة طلباتك وحجوزات تجربة القيادة والتواصل مع المعرض.</p><?php if ( $catalog_url ) : ?><a href="<?php echo esc_url( $catalog_url ); ?>">استكشف السيارات ←</a><?php endif; ?></div>
<div class="cd-auth-form"><nav class="cd-auth-tabs" aria-label="<?php echo esc_attr( car_dealer_text( 'الحساب', 'Account' ) ); ?>"><a <?php echo ! $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'login' ) ); ?>">تسجيل الدخول</a><a <?php echo $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'register' ) ); ?>">إنشاء حساب</a></nav>
<form method="post" action="<?php echo esc_url( car_dealer_account_url( $view ) ); ?>">
<?php wp_nonce_field( 'cd_account_' . $view ); ?>
<?php if ( $register ) : ?>
<label>الاسم الكامل<input name="display_name" autocomplete="name" required value="<?php echo esc_attr( car_dealer_account_field( 'display_name' ) ); ?>"></label>
<label>البريد الإلكتروني<input name="email" type="email" autocomplete="email" dir="ltr" required value="<?php echo esc_attr( car_dealer_account_field( 'email' ) ); ?>"></label>
<label>رقم الهاتف <small>(اختياري)</small><input name="phone" type="tel" autocomplete="tel" dir="ltr" value="<?php echo esc_attr( car_dealer_account_field( 'phone' ) ); ?>"></label>
<div class="cd-auth-trap" aria-hidden="true"><label>Website<input name="company_website" tabindex="-1" autocomplete="off"></label></div>
<?php else : ?>
<label>البريد الإلكتروني أو اسم المستخدم<input name="login" autocomplete="username" required value="<?php echo esc_attr( car_dealer_account_field( 'login' ) ); ?>"></label>
<?php endif; ?>
<label>كلمة المرور<input name="password" type="password" autocomplete="<?php echo $register ? 'new-password' : 'current-password'; ?>" <?php echo $register ? 'minlength="10"' : ''; ?> required></label>
<?php if ( $register ) : ?><small>استخدم 10 أحرف على الأقل.</small><label>تأكيد كلمة المرور<input name="password_confirm" type="password" autocomplete="new-password" minlength="10" required></label>
<label class="cd-auth-consent">
  <input type="checkbox" name="privacy_consent" required>
  <span><?php echo esc_html( car_dealer_text( 'أوافق على ', 'I agree to the ' ) ); ?><?php $privacy_url = get_privacy_policy_url(); $terms_url = car_dealer_page_url( 'terms' ); ?><?php if ( $privacy_url ) : ?><a href="<?php echo esc_url( car_dealer_catalog_localized_url( $privacy_url ) ); ?>">سياسة الخصوصية</a><?php else : ?>سياسة الخصوصية<?php endif; ?><?php echo esc_html( car_dealer_text( ' و ', ' and ' ) ); ?><?php if ( $terms_url ) : ?><a href="<?php echo esc_url( $terms_url ); ?>">شروط الاستخدام</a><?php else : ?>شروط الاستخدام<?php endif; ?></span>
</label>
<?php else : ?><label class="cd-auth-remember"><input name="remember" type="checkbox" value="1"> تذكرني</label><?php endif; ?>
<button class="btn btn-primary" type="submit"><?php echo $register ? 'إنشاء حساب' : 'تسجيل الدخول'; ?></button>
<?php if ( ! $register ) : ?>
<div class="cd-auth-footer">
  <a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>" class="cd-auth-forgot">نسيت كلمة المرور؟</a>
</div>
<?php endif; ?>
</form></div></section>
<?php else : $user = wp_get_current_user(); $kind = car_dealer_account_kind( $user ); $labels = array( 'administrator' => 'مدير الموقع', 'manager' => 'مدير المعرض', 'sales' => 'مستشار المبيعات', 'staff' => 'موظف المعرض', 'customer' => 'حساب العميل' ); ?>
<header class="cd-account-welcome"><div><span><?php echo esc_html( $labels[$kind] ); ?></span><h1>مرحباً، <?php echo esc_html( $user->display_name ); ?></h1><p>كل ما تحتاجه لإدارة حسابك في مكان واحد.</p></div><a class="btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">تسجيل الخروج</a></header>
<?php if ( isset( $_GET['saved'] ) ) : ?><p class="cd-account-notice" role="status">تم تحديث بياناتك.</p><?php endif; ?>
<?php if ( isset( $_GET['preferences_saved'] ) ) : ?><p class="cd-account-notice" role="status">تم حفظ تفضيلات التواصل التسويقي.</p><?php endif; ?>
<?php if ( isset( $_GET['request_updated'] ) ) : ?><p class="cd-account-notice" role="status">تم تحديث الحجز وإبلاغ المعرض داخل لوحة الإدارة.</p><?php endif; ?>
<?php if ( 'customer' !== $kind ) : ?>
<section class="cd-account-panel"><h2>مساحة العمل</h2><div class="cd-account-tools">
<?php
$tools = array(
 array( 'workspace', 'admin.php?page=adc-workspace', 'مساحة عمليات المعرض', 'الوصول إلى العمليات المسموح بها حسب دورك وفرعك' ),
 array( 'crm', 'admin.php?page=adc-crm', 'العملاء والمتابعات', 'sales' === $kind ? 'العملاء المسندون إليك' : 'إدارة علاقات العملاء وفرص البيع' ),
 array( 'messages', 'admin.php?page=car-dealer-messages', 'رسائل العملاء', 'متابعة طلبات التواصل والبيع الواقعة ضمن نطاقك' ),
 array( 'bookings', 'admin.php?page=car-dealer-bookings', 'حجوزات التجربة', 'متابعة مواعيد تجربة القيادة الواقعة ضمن نطاقك' ),
 array( 'subscribers', 'admin.php?page=car-dealer-subscribers', 'النشرة البريدية', 'عرض موافقات الاشتراك التسويقي الحالية' ),
 array( 'car_editor', 'post-new.php?post_type=car', 'إضافة سيارة منشورة', 'إنشاء صفحة السيارة التحريرية في الموقع' ),
 array( 'inventory', 'admin.php?page=adc-inventory', 'المخزون التشغيلي', 'متابعة المركبات وحالاتها التشغيلية' ),
 array( 'users', 'users.php', 'المستخدمون والصلاحيات', 'إدارة حسابات فريق العمل' ),
 array( 'settings', 'admin.php?page=adc-settings', 'إعدادات المنصة', 'إدارة إعدادات ووحدات منصة المعرض' ),
);
$allowed_targets = function_exists( 'adc_customer_workspace_targets' ) ? adc_customer_workspace_targets( $user ) : array();
foreach ( $tools as $tool ) {
	if ( in_array( $tool[0], $allowed_targets, true ) ) { echo '<a href="' . esc_url( admin_url( $tool[1] ) ) . '"><strong>' . esc_html( $tool[2] ) . '</strong><span>' . esc_html( $tool[3] ) . '</span></a>'; }
}
?>
</div></section>
<?php else : ?>
<?php if ( $catalog_url ) : ?><div class="cd-account-actions"><a class="btn btn-primary" href="<?php echo esc_url( $catalog_url ); ?>">تصفح السيارات واحجز تجربة قيادة</a></div><?php endif; ?>
<?php car_dealer_account_request_table( 'messages' ); car_dealer_account_request_table( 'bookings' ); ?>
<section class="cd-account-panel"><h2>إرسال رسالة إلى المعرض</h2><p>ستظهر الرسالة ورد المعرض ضمن طلباتك في هذه الصفحة.</p><?php echo do_shortcode( '[car_dealer_contact_form]' ); ?></section>
<?php endif; ?>
<section class="cd-account-panel"><h2>بياناتي الشخصية</h2><form class="cd-account-profile" method="post" action="<?php echo esc_url( car_dealer_account_url() ); ?>">
<?php wp_nonce_field( 'cd_account_dashboard' ); ?>
<label>الاسم<input name="display_name" autocomplete="name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
<label>الهاتف<input name="phone" type="tel" autocomplete="tel" dir="ltr" value="<?php echo esc_attr( get_user_meta( $user->ID, 'car_dealer_phone', true ) ); ?>"></label>
<p>البريد الإلكتروني: <bdi><?php echo esc_html( $user->user_email ); ?></bdi></p><div class="cd-account-actions"><button class="btn btn-primary">حفظ البيانات</button><a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>">إعادة تعيين كلمة المرور</a></div>
</form></section>
<?php if ( 'customer' === $kind && function_exists( 'adc_customer_current_preferences' ) ) :
$preferences = adc_customer_current_preferences();
if ( ! is_wp_error( $preferences ) ) : ?>
<section class="cd-account-panel cd-account-preferences" id="communication-preferences"><h2>تفضيلات التواصل</h2>
<p>اختر ما إذا كنت ترغب في استقبال عروض وأخبار تسويقية. رسائل الطلبات والحجوزات والخدمة اللازمة لتنفيذ طلبك لا تتأثر بهذا الاختيار.</p>
<form method="post" action="<?php echo esc_url( car_dealer_account_url() ); ?>">
<?php wp_nonce_field( 'cd_account_dashboard' ); ?><input type="hidden" name="account_action" value="save_preferences">
<fieldset><legend>التواصل التسويقي</legend>
<label><input type="radio" name="consent_marketing" value="1" required <?php checked( ! empty( $preferences['consent_marketing'] ) ); ?>> أوافق على استقبال العروض والأخبار التسويقية.</label>
<label><input type="radio" name="consent_marketing" value="0" required <?php checked( empty( $preferences['consent_marketing'] ) ); ?>> لا أرغب في استقبال تواصل تسويقي.</label>
</fieldset>
<?php if ( ! empty( $preferences['recorded_at'] ) ) : ?><p><small>آخر موافقة مسجلة: <?php echo esc_html( get_date_from_gmt( $preferences['recorded_at'], 'Y-m-d H:i' ) ); ?></small></p><?php endif; ?>
<button class="btn btn-primary" type="submit">حفظ التفضيلات</button>
</form></section>
<?php else : ?><section class="cd-account-panel"><h2>تفضيلات التواصل</h2><p role="alert">تعذر تحميل التفضيلات حالياً. حاول مرة أخرى لاحقاً.</p></section>
<?php endif; endif; ?>
<?php endif; ?>
</div>
<?php
$account_html = ob_get_clean();
echo 'en' === car_dealer_catalog_language() ? strtr( $account_html, car_dealer_account_english_copy() ) : $account_html;
get_footer();
?>
