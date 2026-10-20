<?php
defined('ABSPATH') || exit;
?>
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
