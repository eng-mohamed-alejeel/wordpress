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
<section class="cd-auth-card" data-auth-switch>
<div class="cd-auth-intro"><span><?php echo esc_html__( 'أهلاً بك في', 'car-dealer' ); ?> <?php echo esc_html( car_dealer_brand_name() ); ?></span><h1 data-login-title="<?php echo esc_attr__( 'سعداء بعودتك', 'car-dealer' ); ?>" data-register-title="<?php echo esc_attr__( 'ابدأ رحلتك معنا', 'car-dealer' ); ?>" aria-live="polite"><?php echo $register ? esc_html__( 'ابدأ رحلتك معنا', 'car-dealer' ) : esc_html__( 'سعداء بعودتك', 'car-dealer' ); ?></h1><p><?php echo esc_html__( 'حساب واحد لمتابعة طلباتك وحجوزات تجربة القيادة والتواصل مع المعرض.', 'car-dealer' ); ?></p><?php if ( $catalog_url ) : ?><a href="<?php echo esc_url( $catalog_url ); ?>"><?php echo esc_html__( 'استكشف السيارات ←', 'car-dealer' ); ?></a><?php endif; ?></div>
<div class="cd-auth-form"><nav class="cd-auth-tabs" aria-label="<?php echo esc_attr( car_dealer_text( 'الحساب', 'Account' ) ); ?>"><a <?php echo ! $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'login' ) ); ?>"><?php echo esc_html__( 'تسجيل الدخول', 'car-dealer' ); ?></a><a <?php echo $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'register' ) ); ?>"><?php echo esc_html__( 'إنشاء حساب', 'car-dealer' ); ?></a></nav>
<?php foreach ( array( 'login', 'register' ) as $form_view ) : $register = 'register' === $form_view; ?>
<form data-auth-view="<?php echo esc_attr( $form_view ); ?>" <?php echo $form_view !== $view ? 'hidden' : ''; ?> method="post" action="<?php echo esc_url( car_dealer_account_url( $form_view ) ); ?>">
<?php echo str_replace( array( 'id="_wpnonce"', 'id="_wp_http_referer"' ), array( 'id="auth-nonce-' . $form_view . '"', 'id="auth-referer-' . $form_view . '"' ), wp_nonce_field( 'cd_account_' . $form_view, '_wpnonce', true, false ) ); ?>
<?php if ( $register ) : ?>
<label><?php echo esc_html__( 'الاسم الكامل', 'car-dealer' ); ?><input name="display_name" autocomplete="name" required value="<?php echo esc_attr( car_dealer_account_field( 'display_name' ) ); ?>"></label>
<label><?php echo esc_html__( 'البريد الإلكتروني', 'car-dealer' ); ?><input name="email" type="email" autocomplete="email" dir="ltr" required value="<?php echo esc_attr( car_dealer_account_field( 'email' ) ); ?>"></label>
<label><?php echo esc_html__( 'رقم الهاتف', 'car-dealer' ); ?><input name="phone" type="tel" autocomplete="tel" dir="ltr" required value="<?php echo esc_attr( car_dealer_account_field( 'phone' ) ); ?>"></label>
<div class="cd-auth-trap" aria-hidden="true"><label><?php echo esc_html__( 'الموقع الإلكتروني', 'car-dealer' ); ?><input name="company_website" tabindex="-1" autocomplete="off"></label></div>
<?php else : ?>
<label><?php echo esc_html__( 'البريد الإلكتروني أو اسم المستخدم', 'car-dealer' ); ?><input name="login" autocomplete="username" required value="<?php echo esc_attr( car_dealer_account_field( 'login' ) ); ?>"></label>
<?php endif; ?>
<label><?php echo esc_html__( 'كلمة المرور', 'car-dealer' ); ?><input <?php echo $register ? 'id="cd-register-password" data-password-policy minlength="12" maxlength="128" aria-describedby="cd-password-requirements cd-password-strength"' : ''; ?> name="password" type="password" autocomplete="<?php echo $register ? 'new-password' : 'current-password'; ?>" required></label>
<?php if ( $register ) : ?>
<section class="cd-password-policy" data-password-policy-panel aria-labelledby="cd-password-policy-title">
  <div class="cd-password-policy-head">
    <strong id="cd-password-policy-title"><?php echo esc_html( car_dealer_text( 'شروط كلمة المرور', 'Password requirements' ) ); ?></strong>
    <button class="cd-password-generate" type="button" data-password-generate>
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.64 5.64l2.12 2.12M16.24 16.24l2.12 2.12M18.36 5.64l-2.12 2.12M7.76 16.24l-2.12 2.12"/><path d="m9.5 14.5 5-5 2 2-5 5-2-2Z"/></svg>
      <span><?php echo esc_html( car_dealer_text( 'إنشاء كلمة قوية', 'Generate strong password' ) ); ?></span>
    </button>
  </div>
  <ul id="cd-password-requirements" class="cd-password-requirements">
    <li data-password-rule="length"><span aria-hidden="true"></span><?php echo esc_html( car_dealer_text( 'من 12 إلى 128 حرفًا', '12 to 128 characters' ) ); ?></li>
    <li data-password-rule="uppercase"><span aria-hidden="true"></span><?php echo esc_html( car_dealer_text( 'حرف إنجليزي كبير واحد على الأقل (A–Z)', 'At least one uppercase English letter (A–Z)' ) ); ?></li>
    <li data-password-rule="lowercase"><span aria-hidden="true"></span><?php echo esc_html( car_dealer_text( 'حرف إنجليزي صغير واحد على الأقل (a–z)', 'At least one lowercase English letter (a–z)' ) ); ?></li>
    <li data-password-rule="number"><span aria-hidden="true"></span><?php echo esc_html( car_dealer_text( 'رقم واحد على الأقل', 'At least one number' ) ); ?></li>
    <li data-password-rule="symbol"><span aria-hidden="true"></span><?php echo esc_html( car_dealer_text( 'رمز خاص واحد على الأقل، مثل ! أو @', 'At least one symbol, such as ! or @' ) ); ?></li>
    <li data-password-rule="ascii"><span aria-hidden="true"></span><?php echo esc_html( car_dealer_text( 'أحرف إنجليزية وأرقام ورموز فقط، دون مسافات', 'English letters, numbers, and symbols only; no spaces' ) ); ?></li>
  </ul>
  <div class="cd-password-meter" data-password-meter data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
  <p id="cd-password-strength" class="cd-password-strength" aria-live="polite"><span><?php echo esc_html( car_dealer_text( 'قوة كلمة المرور:', 'Password strength:' ) ); ?></span> <strong data-password-strength-label><?php echo esc_html( car_dealer_text( 'لم تُكتب بعد', 'Not entered yet' ) ); ?></strong></p>
  <div class="cd-password-generated-actions">
    <button type="button" class="cd-password-copy" data-password-copy hidden><?php echo esc_html( car_dealer_text( 'نسخ كلمة المرور', 'Copy password' ) ); ?></button>
    <span class="cd-password-status" data-password-status role="status" aria-live="polite"></span>
  </div>
</section>
<label><?php echo esc_html__( 'تأكيد كلمة المرور', 'car-dealer' ); ?><input id="cd-register-password-confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="cd-password-confirm-status" required></label>
<small id="cd-password-confirm-status" class="cd-password-confirm-status" data-password-confirm-status aria-live="polite"></small>
<label class="cd-auth-consent">
  <input type="checkbox" name="privacy_consent" required data-validation-required-ar="<?php echo esc_attr( 'يجب الموافقة على سياسة الخصوصية وشروط الاستخدام لإنشاء الحساب.' ); ?>" data-validation-required-en="<?php echo esc_attr( 'You must agree to the Privacy Policy and Terms of Use to create an account.' ); ?>">
  <span><?php echo esc_html( car_dealer_text( 'أوافق على ', 'I agree to the ' ) ); ?><?php $privacy_url = get_privacy_policy_url(); $terms_url = car_dealer_page_url( 'terms' ); ?><?php if ( $privacy_url ) : ?><a href="<?php echo esc_url( car_dealer_catalog_localized_url( $privacy_url ) ); ?>"><?php echo esc_html__( 'سياسة الخصوصية', 'car-dealer' ); ?></a><?php else : ?><?php echo esc_html__( 'سياسة الخصوصية', 'car-dealer' ); ?><?php endif; ?><?php echo esc_html( car_dealer_text( ' و ', ' and ' ) ); ?><?php if ( $terms_url ) : ?><a href="<?php echo esc_url( $terms_url ); ?>"><?php echo esc_html__( 'شروط الاستخدام', 'car-dealer' ); ?></a><?php else : ?><?php echo esc_html__( 'شروط الاستخدام', 'car-dealer' ); ?><?php endif; ?></span>
</label>
<?php else : ?><label class="cd-auth-remember"><input name="remember" type="checkbox" value="1"> <?php echo esc_html__( 'تذكرني', 'car-dealer' ); ?></label><?php endif; ?>
<button class="btn btn-primary" type="submit"><?php echo $register ? esc_html__( 'إنشاء حساب', 'car-dealer' ) : esc_html__( 'تسجيل الدخول', 'car-dealer' ); ?></button>
<?php if ( ! $register ) : ?>
<div class="cd-auth-footer">
  <a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>" class="cd-auth-forgot"><?php echo esc_html__( 'نسيت كلمة المرور؟', 'car-dealer' ); ?></a>
</div>
<?php endif; ?>
</form><?php endforeach; ?></div></section>
<?php else : $user = wp_get_current_user(); $kind = car_dealer_account_kind( $user ); $labels = array( 'administrator' => 'مدير الموقع', 'manager' => 'مدير المعرض', 'sales' => 'مستشار المبيعات', 'staff' => 'موظف المعرض', 'customer' => 'حساب العميل' ); ?>
<header class="cd-account-welcome"><div><span><?php echo esc_html__( $labels[$kind], 'car-dealer' ); ?></span><h1><?php echo esc_html__( 'مرحباً،', 'car-dealer' ); ?> <?php echo esc_html( $user->display_name ); ?></h1><p><?php echo esc_html__( 'كل ما تحتاجه لإدارة حسابك في مكان واحد.', 'car-dealer' ); ?></p></div><a class="btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php echo esc_html__( 'تسجيل الخروج', 'car-dealer' ); ?></a></header>
<?php if ( isset( $_GET['saved'] ) ) : ?><p class="cd-account-notice" role="status" data-success-notice data-notice-param="saved"><?php echo esc_html__( 'تم تحديث بياناتك.', 'car-dealer' ); ?></p><?php endif; ?>
<?php if ( isset( $_GET['preferences_saved'] ) ) : ?><p class="cd-account-notice" role="status" data-success-notice data-notice-param="preferences_saved"><?php echo esc_html__( 'تم حفظ تفضيلات التواصل التسويقي.', 'car-dealer' ); ?></p><?php endif; ?>
<?php if ( isset( $_GET['request_updated'] ) ) : ?><p class="cd-account-notice" role="status" data-success-notice data-notice-param="request_updated"><?php echo esc_html__( 'تم تحديث الحجز وإبلاغ المعرض داخل لوحة الإدارة.', 'car-dealer' ); ?></p><?php endif; ?>
<?php if ( 'customer' !== $kind ) : ?>
<section class="cd-account-panel"><h2><?php echo esc_html__( 'مساحة العمل', 'car-dealer' ); ?></h2><div class="cd-account-tools">
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
	if ( in_array( $tool[0], $allowed_targets, true ) ) { echo '<a href="' . esc_url( admin_url( $tool[1] ) ) . '"><strong>' . esc_html__( $tool[2], 'car-dealer' ) . '</strong><span>' . esc_html__( $tool[3], 'car-dealer' ) . '</span></a>'; }
}
?>
</div></section>
<?php else : ?>
<?php if ( $catalog_url ) : ?><div class="cd-account-actions"><a class="btn btn-primary" href="<?php echo esc_url( $catalog_url ); ?>"><?php echo esc_html__( 'تصفح السيارات واحجز تجربة قيادة', 'car-dealer' ); ?></a></div><?php endif; ?>
<?php car_dealer_account_request_table( 'messages' ); car_dealer_account_request_table( 'bookings' ); ?>
<section class="cd-account-panel"><h2><?php echo esc_html__( 'إرسال رسالة إلى المعرض', 'car-dealer' ); ?></h2><p><?php echo esc_html__( 'ستظهر الرسالة ورد المعرض ضمن طلباتك في هذه الصفحة.', 'car-dealer' ); ?></p><?php echo do_shortcode( '[car_dealer_contact_form]' ); ?></section>
<?php endif; ?>
<section class="cd-account-panel"><h2><?php echo esc_html__( 'بياناتي الشخصية', 'car-dealer' ); ?></h2><form class="cd-account-profile" method="post" action="<?php echo esc_url( car_dealer_account_url() ); ?>">
<?php wp_nonce_field( 'cd_account_dashboard' ); ?>
<label><?php echo esc_html__( 'الاسم', 'car-dealer' ); ?><input name="display_name" autocomplete="name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
<label><?php echo esc_html__( 'الهاتف', 'car-dealer' ); ?><input name="phone" type="tel" autocomplete="tel" dir="ltr" value="<?php echo esc_attr( get_user_meta( $user->ID, 'car_dealer_phone', true ) ); ?>"></label>
<p><?php echo esc_html__( 'البريد الإلكتروني:', 'car-dealer' ); ?> <bdi><?php echo esc_html( $user->user_email ); ?></bdi></p><div class="cd-account-actions"><button class="btn btn-primary"><?php echo esc_html__( 'حفظ البيانات', 'car-dealer' ); ?></button><a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>"><?php echo esc_html__( 'إعادة تعيين كلمة المرور', 'car-dealer' ); ?></a></div>
</form></section>
<?php if ( 'customer' === $kind && function_exists( 'adc_customer_current_preferences' ) ) :
$preferences = adc_customer_current_preferences();
if ( ! is_wp_error( $preferences ) ) : ?>
<section class="cd-account-panel cd-account-preferences" id="communication-preferences"><h2><?php echo esc_html__( 'تفضيلات التواصل', 'car-dealer' ); ?></h2>
<p><?php echo esc_html__( 'اختر ما إذا كنت ترغب في استقبال عروض وأخبار تسويقية. رسائل الطلبات والحجوزات والخدمة اللازمة لتنفيذ طلبك لا تتأثر بهذا الاختيار.', 'car-dealer' ); ?></p>
<form method="post" action="<?php echo esc_url( car_dealer_account_url() ); ?>">
<?php wp_nonce_field( 'cd_account_dashboard' ); ?><input type="hidden" name="account_action" value="save_preferences">
<p><label for="cd-newsletter-language"><?php echo esc_html( car_dealer_text( 'لغة رسائل البريد الإلكتروني', 'Email language preference' ) ); ?></label><select id="cd-newsletter-language" name="newsletter_language"><option value="ar" <?php selected( $preferences['newsletter_language'] ?? 'ar', 'ar' ); ?>>العربية</option><option value="en" <?php selected( $preferences['newsletter_language'] ?? 'ar', 'en' ); ?>>English</option></select></p>
<fieldset><legend><?php echo esc_html__( 'التواصل التسويقي', 'car-dealer' ); ?></legend>
<label><input type="radio" name="consent_marketing" value="1" required <?php checked( ! empty( $preferences['consent_marketing'] ) ); ?>> <?php echo esc_html__( 'أوافق على استقبال العروض والأخبار التسويقية.', 'car-dealer' ); ?></label>
<label><input type="radio" name="consent_marketing" value="0" required <?php checked( empty( $preferences['consent_marketing'] ) ); ?>> <?php echo esc_html__( 'لا أرغب في استقبال تواصل تسويقي.', 'car-dealer' ); ?></label>
</fieldset>
<?php if ( ! empty( $preferences['recorded_at'] ) ) : ?><p><small><?php echo esc_html__( 'آخر موافقة مسجلة:', 'car-dealer' ); ?> <?php echo esc_html( get_date_from_gmt( $preferences['recorded_at'], 'Y-m-d H:i' ) ); ?></small></p><?php endif; ?>
<button class="btn btn-primary" type="submit"><?php echo esc_html__( 'حفظ التفضيلات', 'car-dealer' ); ?></button>
</form></section>
<?php else : ?><section class="cd-account-panel"><h2><?php echo esc_html__( 'تفضيلات التواصل', 'car-dealer' ); ?></h2><p role="alert"><?php echo esc_html__( 'تعذر تحميل التفضيلات حالياً. حاول مرة أخرى لاحقاً.', 'car-dealer' ); ?></p></section>
<?php endif; endif; ?>
<?php endif; ?>
</div>
<?php
$account_html = ob_get_clean();
echo $account_html;
get_footer();
?>
