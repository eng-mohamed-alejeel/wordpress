<?php
defined( 'ABSPATH' ) || exit;
$view = car_dealer_account_view(); $error = $GLOBALS['cd_account_error'] ?? '';
get_header();
?>
<div class="container cd-account" dir="rtl">
<?php if ( $error ) : ?><div class="cd-account-notice is-error" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
<?php if ( 'dashboard' !== $view ) : $register = 'register' === $view; ?>
<section class="cd-auth-card">
<div class="cd-auth-intro"><span>أهلاً بك في <?php echo esc_html( get_bloginfo( 'name' ) ); ?></span><h1><?php echo $register ? 'ابدأ رحلتك معنا' : 'سعداء بعودتك'; ?></h1><p>حساب واحد لمتابعة طلباتك وحجوزات تجربة القيادة والتواصل مع المعرض.</p><a href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">استكشف السيارات ←</a></div>
<div class="cd-auth-form"><nav class="cd-auth-tabs" aria-label="الحساب"><a <?php echo ! $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'login' ) ); ?>">تسجيل الدخول</a><a <?php echo $register ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( car_dealer_account_url( 'register' ) ); ?>">إنشاء حساب</a></nav>
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
<?php if ( $register ) : ?><small>استخدم 10 أحرف على الأقل.</small><label>تأكيد كلمة المرور<input name="password_confirm" type="password" autocomplete="new-password" minlength="10" required></label><?php else : ?><label class="cd-auth-remember"><input name="remember" type="checkbox" value="1"> تذكرني</label><?php endif; ?>
<button class="btn btn-primary" type="submit"><?php echo $register ? 'إنشاء حساب العميل' : 'تسجيل الدخول'; ?></button>
<p><a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>">نسيت كلمة المرور؟</a></p>
<?php if ( $register && get_privacy_policy_url() ) : ?><p><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">سياسة الخصوصية</a></p><?php endif; ?>
</form></div></section>
<?php else : $user = wp_get_current_user(); $kind = car_dealer_account_kind( $user ); $labels = array( 'administrator' => 'مدير الموقع', 'manager' => 'مدير المعرض', 'sales' => 'مستشار المبيعات', 'customer' => 'حساب العميل' ); ?>
<header class="cd-account-welcome"><div><span><?php echo esc_html( $labels[$kind] ); ?></span><h1>مرحباً، <?php echo esc_html( $user->display_name ); ?></h1><p>كل ما تحتاجه لإدارة حسابك في مكان واحد.</p></div><a class="btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">تسجيل الخروج</a></header>
<?php if ( isset( $_GET['saved'] ) ) : ?><p class="cd-account-notice" role="status">تم تحديث بياناتك.</p><?php endif; ?>
<?php if ( isset( $_GET['request_updated'] ) ) : ?><p class="cd-account-notice" role="status">تم تحديث الحجز وإبلاغ المعرض داخل لوحة الإدارة.</p><?php endif; ?>
<?php if ( 'customer' !== $kind ) : ?>
<section class="cd-account-panel"><h2>مساحة العمل</h2><div class="cd-account-tools">
<?php
$tools = array(
 array( 'manage_car_dealer', 'admin.php?page=car-dealer-dashboard', 'داشبورد المعرض', 'نظرة عامة على نشاط المعرض' ),
 array( 'manage_car_dealer', 'admin.php?page=car-dealer-crm' . ( 'sales' === $kind ? '&mine=1' : '' ), 'العملاء والمتابعات', 'sales' === $kind ? 'العملاء المسندون إليك' : 'إدارة علاقات العملاء وفرص البيع' ),
 array( 'edit_cars', 'admin.php?page=car-dealer-add-car', 'إضافة سيارة', 'إضافة سيارة جديدة إلى المعرض' ),
 array( 'edit_others_cars', 'admin.php?page=car-dealer-inventory', 'تقرير المخزون', 'متابعة توفر السيارات وحالاتها' ),
 array( 'manage_options', 'admin.php?page=car-dealer-users', 'المستخدمون والصلاحيات', 'إدارة أدوار فريق العمل' ),
 array( 'manage_options', 'admin.php?page=car-dealer-settings', 'إعدادات المعرض', 'تخصيص بيانات الموقع' ),
);
foreach ( $tools as $tool ) { if ( current_user_can( $tool[0] ) ) { echo '<a href="' . esc_url( admin_url( $tool[1] ) ) . '"><strong>' . esc_html( $tool[2] ) . '</strong><span>' . esc_html( $tool[3] ) . '</span></a>'; } }
?>
</div></section>
<?php else : ?>
<div class="cd-account-actions"><a class="btn btn-primary" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">تصفح السيارات واحجز تجربة قيادة</a></div>
<?php car_dealer_account_request_table( 'messages' ); car_dealer_account_request_table( 'bookings' ); ?>
<section class="cd-account-panel"><h2>إرسال رسالة إلى المعرض</h2><p>ستظهر الرسالة ورد المعرض ضمن طلباتك في هذه الصفحة.</p><?php echo car_dealer_contact_form_shortcode(); ?></section>
<?php endif; ?>
<section class="cd-account-panel"><h2>بياناتي الشخصية</h2><form class="cd-account-profile" method="post" action="<?php echo esc_url( car_dealer_account_url() ); ?>">
<?php wp_nonce_field( 'cd_account_dashboard' ); ?>
<label>الاسم<input name="display_name" autocomplete="name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
<label>الهاتف<input name="phone" type="tel" autocomplete="tel" value="<?php echo esc_attr( get_user_meta( $user->ID, 'car_dealer_phone', true ) ); ?>"></label>
<p>البريد الإلكتروني: <bdi><?php echo esc_html( $user->user_email ); ?></bdi></p><div class="cd-account-actions"><button class="btn btn-primary">حفظ البيانات</button><a href="<?php echo esc_url( wp_lostpassword_url( car_dealer_account_url( 'login' ) ) ); ?>">إعادة تعيين كلمة المرور</a></div>
</form></section>
<?php endif; ?>
</div>
<?php get_footer(); ?>
