<?php
/** Presentation only: reset keys and mutations belong to the core plugin. */
defined('ABSPATH') || exit;
$view=car_dealer_account_view();
$error=(string)($GLOBALS['cd_account_error']??'');
$reset='resetpass'===$view;
$recovery=\AutoDealership\Accounts\PasswordRecovery::class;
$valid=$reset&&!is_wp_error($recovery::user());
$requested=!$reset&&isset($_GET['requested']);
$title=$reset?car_dealer_text('تعيين كلمة مرور جديدة','Set a new password'):car_dealer_text('استعادة كلمة المرور','Reset your password');
?>
<div class="container cd-account" dir="<?php echo esc_attr(car_dealer_catalog_direction()); ?>" lang="<?php echo esc_attr(car_dealer_catalog_language()); ?>">
<section class="cd-auth-card cd-auth-recovery">
<div class="cd-auth-intro"><span><?php echo esc_html(car_dealer_brand_name()); ?></span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html(car_dealer_text('استعد الوصول إلى حسابك لمتابعة طلباتك وحجوزاتك والتواصل مع المعرض.','Recover access to your account to follow your requests, bookings and conversations.')); ?></p><a href="<?php echo esc_url(car_dealer_site_url()); ?>"><?php echo esc_html(car_dealer_text('العودة إلى الرئيسية','Back to home')); ?></a></div>
<div class="cd-auth-form">
<nav class="cd-auth-tabs" aria-label="<?php echo esc_attr(car_dealer_text('الحساب','Account')); ?>"><a href="<?php echo esc_url(car_dealer_account_url('login')); ?>"><?php echo esc_html(car_dealer_text('تسجيل الدخول','Sign in')); ?></a><a href="<?php echo esc_url(car_dealer_account_url('register')); ?>"><?php echo esc_html(car_dealer_text('إنشاء حساب','Create account')); ?></a></nav>
<?php if($error&&(!$reset||$valid)): ?><p class="cd-account-notice is-error" role="alert"><?php echo esc_html($error); ?></p><?php endif; ?>
<?php if($reset&&!$valid): ?>
<p class="cd-account-notice is-error" role="alert"><?php echo esc_html($recovery::invalid_message()); ?></p>
<a class="btn btn-primary" href="<?php echo esc_url(car_dealer_account_url('lostpassword')); ?>"><?php echo esc_html(car_dealer_text('طلب رابط استعادة جديد','Request a new recovery link')); ?></a>
<?php elseif($requested): ?>
<div class="cd-account-notice is-success" role="status"><strong><?php echo esc_html(car_dealer_text('تحقق من بريدك الإلكتروني','Check your email')); ?></strong><p><?php echo esc_html(car_dealer_text('إذا كان البريد مرتبطًا بحساب، فستصلك رسالة تتضمن رابط تعيين كلمة مرور جديدة. تحقق من صندوق الوارد والبريد غير المرغوب فيه.','If this email belongs to an account, you will receive a link to set a new password. Check your inbox and spam folder.')); ?></p></div>
<a href="<?php echo esc_url(car_dealer_account_url('lostpassword')); ?>"><?php echo esc_html(car_dealer_text('المحاولة مرة أخرى','Try again')); ?></a>
<?php else: ?>
<p><?php echo esc_html($reset?car_dealer_text('اختر كلمة مرور قوية ومختلفة عن كلمات مرور حساباتك الأخرى.','Choose a strong password that differs from your other accounts.'):car_dealer_text('أدخل البريد الإلكتروني أو اسم المستخدم المرتبط بحسابك لإرسال رابط الاستعادة.','Enter your account email address or username to request a recovery link.')); ?></p>
<form method="post" action="<?php echo esc_url(car_dealer_account_url($view)); ?>" data-account-recovery>
<?php wp_nonce_field($reset?$recovery::nonce_action():'cd_account_lostpassword'); ?>
<?php if($reset): ?>
<label><?php echo esc_html(car_dealer_text('كلمة المرور الجديدة','New password')); ?><input id="cd-reset-password" data-password-policy name="pass1" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-describedby="cd-password-requirements cd-password-strength"></label>
<?php include __DIR__.'/account-password-policy.php'; ?>
<label><?php echo esc_html(car_dealer_text('تأكيد كلمة المرور الجديدة','Confirm new password')); ?><input name="pass2" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-describedby="cd-password-confirm-status"></label>
<small id="cd-password-confirm-status" class="cd-password-confirm-status" data-password-confirm-status aria-live="polite"></small>
<?php else: ?>
<label><?php echo esc_html(car_dealer_text('البريد الإلكتروني أو اسم المستخدم','Email address or username')); ?><input name="user_login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" dir="ltr" maxlength="100" required value="<?php echo esc_attr(car_dealer_account_field('user_login')); ?>"></label>
<?php endif; ?>
<button class="btn btn-primary" type="submit"><?php echo esc_html($reset?car_dealer_text('حفظ كلمة المرور الجديدة','Save new password'):car_dealer_text('إرسال رابط الاستعادة','Send recovery link')); ?></button>
</form>
<?php endif; ?>
<div class="cd-auth-footer"><a class="cd-auth-forgot" href="<?php echo esc_url(car_dealer_account_url('login')); ?>"><?php echo esc_html(car_dealer_text('العودة إلى تسجيل الدخول','Back to sign in')); ?></a></div>
</div></section></div>
