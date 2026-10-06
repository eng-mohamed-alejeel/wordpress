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
<div class="cd-auth-intro"><span><?php echo esc_html__( 'أهلاً بك في', 'car-dealer' ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></span><h1><?php echo $register ? esc_html__( 'ابدأ رحلتك معنا', 'car-dealer' ) : esc_html__( 'سعداء بعودتك', 'car-dealer' ); ?></h1><p><?php echo esc_html__( 'حساب واحد لمتابعة طلباتك وحجوزات تجربة القيادة والتواصل مع المعرض.', 'car-dealer' ); ?></p><?php if ( $catalog_url ) : ?><a href="<?php echo esc_url( $catalog_url ); ?>"><?php echo esc_html__( 'استكشف السيارات ←', 'car-dealer' ); ?></a><?php endif; ?></div>
<div class="cd-auth-form"><nav class="cd-auth-tabs" aria-label="<?php echo esc_attr( car_dealer_text( 'الحساب', 'Account' ) ); ?>"><a <?php echo ! $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'login' ) ); ?>"><?php echo esc_html__( 'تسجيل الدخول', 'car-dealer' ); ?></a><a <?php echo $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'register' ) ); ?>"><?php echo esc_html__( 'إنشاء حساب', 'car-dealer' ); ?></a></nav>
<form method="post" action="<?php echo esc_url( car_dealer_account_url( $view ) ); ?>">
<?php wp_nonce_field( 'cd_account_' . $view ); ?>
<?php if ( $register ) : ?>
<label><?php echo esc_html__( 'الاسم الكامل', 'car-dealer' ); ?><input name="display_name" autocomplete="name" required value="<?php echo esc_attr( car_dealer_account_field( 'display_name' ) ); ?>"></label>
<label><?php echo esc_html__( 'البريد الإلكتروني', 'car-dealer' ); ?><input name="email" type="email" autocomplete="email" dir="ltr" required value="<?php echo esc_attr( car_dealer_account_field( 'email' ) ); ?>"></label>
<label><?php echo esc_html__( 'رقم الهاتف', 'car-dealer' ); ?> <small><?php echo esc_html__( '(اختياري)', 'car-dealer' ); ?></small><input name="phone" type="tel" autocomplete="tel" dir="ltr" value="<?php echo esc_attr( car_dealer_account_field( 'phone' ) ); ?>"></label>
<div class="cd-auth-trap" aria-hidden="true"><label><?php echo esc_html__( 'الموقع الإلكتروني', 'car-dealer' ); ?><input name="company_website" tabindex="-1" autocomplete="off"></label></div>
<?php else : ?>
<label><?php echo esc_html__( 'البريد الإلكتروني أو اسم المستخدم', 'car-dealer' ); ?><input name="login" autocomplete="username" required value="<?php echo esc_attr( car_dealer_account_field( 'login' ) ); ?>"></label>
<?php endif; ?>
<label><?php echo esc_html__( 'كلمة المرور', 'car-dealer' ); ?><input name="password" type="password" autocomplete="<?php echo $register ? 'new-password' : 'current-password'; ?>" <?php echo $register ? 'minlength="10"' : ''; ?> required></label>
<?php if ( $register ) : ?><small><?php echo esc_html__( 'استخدم 10 أحرف على الأقل.', 'car-dealer' ); ?></small><label><?php echo esc_html__( 'تأكيد كلمة المرور', 'car-dealer' ); ?><input name="password_confirm" type="password" autocomplete="new-password" minlength="10" required></label>
<label class="cd-auth-consent">
  <input type="checkbox" name="privacy_consent" required>
  <span><?php echo esc_html( car_dealer_text( 'أوافق على ', 'I agree to the ' ) ); ?><?php $privacy_url = get_privacy_policy_url(); $terms_url = car_dealer_page_url( 'terms' ); ?><?php if ( $privacy_url ) : ?><a href="<?php echo esc_url( car_dealer_catalog_localized_url( $privacy_url ) ); ?>"><?php echo esc_html__( 'سياسة الخصوصية', 'car-dealer' ); ?></a><?php else : ?><?php echo esc_html__( 'سياسة الخصوصية', 'car-dealer' ); ?><?php endif; ?><?php echo esc_html( car_dealer_text( ' و ', ' and ' ) ); ?><?php if ( $terms_url ) : ?><a href="<?php echo esc_url( $terms_url ); ?>"><?php echo esc_html__( 'شروط الاستخدام', 'car-dealer' ); ?></a><?php else : ?><?php echo esc_html__( 'شروط الاستخدام', 'car-dealer' ); ?><?php endif; ?></span>
</label>
<?php else : ?><label class="cd-auth-remember"><input name="remember" type="checkbox" value="1"> <?php echo esc_html__( 'تذكرني', 'car-dealer' ); ?></label><?php endif; ?>
<button class="btn btn-primary" type="submit"><?php echo $register ? esc_html__( 'إنشاء حساب', 'car-dealer' ) : esc_html__( 'تسجيل الدخول', 'car-dealer' ); ?></button>
<?php if ( ! $register ) : ?>
<div class="cd-auth-footer">
  <a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>" class="cd-auth-forgot"><?php echo esc_html__( 'نسيت كلمة المرور؟', 'car-dealer' ); ?></a>
</div>
<?php endif; ?>
</form></div></section>
<?php else : $user = wp_get_current_user(); $kind = car_dealer_account_kind( $user ); $labels = array( 'administrator' => 'مدير الموقع', 'manager' => 'مدير المعرض', 'sales' => 'مستشار المبيعات', 'staff' => 'موظف المعرض', 'customer' => 'حساب العميل' ); ?>
<header class="cd-account-welcome"><div><span><?php echo esc_html__( $labels[$kind], 'car-dealer' ); ?></span><h1><?php echo esc_html__( 'مرحباً،', 'car-dealer' ); ?> <?php echo esc_html( $user->display_name ); ?></h1><p><?php echo esc_html__( 'كل ما تحتاجه لإدارة حسابك في مكان واحد.', 'car-dealer' ); ?></p></div><a class="btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php echo esc_html__( 'تسجيل الخروج', 'car-dealer' ); ?></a></header>
<?php if ( isset( $_GET['saved'] ) ) : ?><p class="cd-account-notice" role="status"><?php echo esc_html__( 'تم تحديث بياناتك.', 'car-dealer' ); ?></p><?php endif; ?>
<?php if ( isset( $_GET['preferences_saved'] ) ) : ?><p class="cd-account-notice" role="status"><?php echo esc_html__( 'تم حفظ تفضيلات التواصل التسويقي.', 'car-dealer' ); ?></p><?php endif; ?>
<?php if ( isset( $_GET['request_updated'] ) ) : ?><p class="cd-account-notice" role="status"><?php echo esc_html__( 'تم تحديث الحجز وإبلاغ المعرض داخل لوحة الإدارة.', 'car-dealer' ); ?></p><?php endif; ?>
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
