<?php
/**
 * Plugin Name: AutoBrands Password Reset Email
 * Description: Branded, bilingual password reset email with a plain-text alternative.
 */
defined( 'ABSPATH' ) || exit;
if ( defined( 'ADC_PASSWORD_MAIL_LOADED' ) ) { return; }
define( 'ADC_PASSWORD_MAIL_LOADED', true );
require_once __DIR__ . '/LegacyHooks.php';
adc_product_remove_legacy_mail_hooks( 'autobrands-password-email.php', array( 'retrieve_password_notification_email', 'phpmailer_init' ) );

add_filter( 'retrieve_password_notification_email', static function ( array $email, string $key, string $login, $user ): array {
	$preference = get_user_meta( (int) $user->ID, 'adc_preferred_language', true );
	$locale = in_array( $preference, array( 'ar', 'en' ), true ) ? ( 'en' === $preference ? 'en_US' : 'ar' ) : get_locale();
	$ar = str_starts_with( $locale, 'ar' );
	$brand = class_exists( '\AutoDealership\Core\ProductSetup' ) ? \AutoDealership\Core\ProductSetup::brand( $ar ? 'ar' : 'en' ) : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	$url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ), 'login' );
	$url = add_query_arg( 'wp_lang', $locale, $url );
	if(class_exists('\\AutoDealership\\Accounts\\PasswordRecovery')&&\AutoDealership\Accounts\PasswordRecovery::enabled()){
		$url=add_query_arg(array('key'=>$key,'login'=>$login),\AutoDealership\Accounts\PasswordRecovery::url('resetpass','',$ar?'ar':'en'));
	}
	$palette = \AutoDealership\Core\BrandPalette::email_colors();
	$navy = $palette['mail-header'];
	$blue = $palette['mail-action'];
	$copy = $ar ? array(
		'preheader' => 'خطوة واحدة لاستعادة الوصول إلى حسابك في ' . $brand . '.',
		'eyebrow' => 'حسابك لدى ' . $brand,
		'title' => 'إعادة تعيين كلمة المرور',
		'hello' => 'مرحبًا بك،',
		'intro' => 'تلقّينا طلبًا لإعادة تعيين كلمة المرور لحسابك. اضغط الزر أدناه لاختيار كلمة مرور جديدة والعودة إلى حسابك.',
		'button' => 'تعيين كلمة مرور جديدة',
		'safety' => 'لم تطلب تغيير كلمة المرور؟',
		'ignore' => 'يمكنك تجاهل هذه الرسالة بأمان؛ ستبقى كلمة مرورك الحالية كما هي.',
		'privacy' => 'هذا الرابط مخصّص لك. لا تشاركه مع أي شخص، وتنتهي صلاحيته بعد مدة محدودة.',
		'fallback' => 'إذا لم يعمل الزر، انسخ الرابط التالي والصقه في متصفحك:',
		'footer' => 'رسالة تلقائية لحماية حسابك لدى ' . $brand . '.',
	) : array(
		'preheader' => 'Restore access to your ' . $brand . ' account.',
		'eyebrow' => 'Your ' . $brand . ' account',
		'title' => 'Reset your password',
		'hello' => 'Hello,',
		'intro' => 'We received a request to reset your password. Use the button below to choose a new password and return to your account.',
		'button' => 'Set a new password',
		'safety' => 'Did not request a password reset?',
		'ignore' => 'You can safely ignore this email. Your current password will remain unchanged.',
		'privacy' => 'This link is just for you. Do not share it with anyone. It expires after a limited time.',
		'fallback' => 'If the button does not work, copy and paste this link into your browser:',
		'footer' => 'An automated message to protect your ' . $brand . ' account.',
	);
	$copy = array_map( 'esc_html', $copy );
	$link = esc_url( $url );
	$direction = $ar ? 'rtl' : 'ltr';
	$align = $ar ? 'right' : 'left';
	$lang = $ar ? 'ar' : 'en';
	$brand_html = esc_html( $brand );
	$brand_english = esc_html( class_exists( '\AutoDealership\Core\ProductSetup' ) ? \AutoDealership\Core\ProductSetup::brand( 'en' ) : $brand );
	$email['subject'] = $brand . ' | ' . ( $ar ? 'إعادة تعيين كلمة المرور' : 'Reset your password' );
	// Inline styles and presentation tables keep the layout compatible with email clients.
	$email['message'] = <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$direction}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{$copy['title']}</title></head>
<body style="margin:0;padding:0;background-color:#F3F6FA;font-family:Tahoma,Arial,sans-serif;color:#24364B;direction:{$direction};">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{$copy['preheader']}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#F3F6FA;"><tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background-color:#FFFFFF;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;">
<tr><td bgcolor="{$navy}" style="padding:28px 28px;text-align:{$align};border-bottom:4px solid {$blue};">
<p style="margin:0;color:#FFFFFF;font-size:26px;font-weight:bold;line-height:1.6;">{$brand_html}</p>
<p dir="ltr" style="margin:2px 0 0;color:#D5E3F4;font-size:12px;letter-spacing:3px;text-align:{$align};">{$brand_english}</p>
</td></tr>
<tr><td dir="{$direction}" style="padding:32px 28px;text-align:{$align};">
<p style="margin:0 0 8px;color:#64748B;font-size:13px;line-height:1.8;">{$copy['eyebrow']}</p>
<h1 style="margin:0 0 24px;color:{$navy};font-size:26px;line-height:1.5;">{$copy['title']}</h1>
<p style="margin:0 0 10px;font-size:16px;line-height:1.9;font-weight:bold;">{$copy['hello']}</p>
<p style="margin:0;font-size:15px;line-height:1.9;">{$copy['intro']}</p>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;"><tr><td align="center" bgcolor="{$blue}" style="border-radius:8px;mso-padding-alt:16px 24px;">
<a href="{$link}" style="display:inline-block;padding:16px 24px;border:1px solid {$blue};border-radius:8px;color:#FFFFFF;text-decoration:none;font-size:16px;font-weight:bold;line-height:1.5;">{$copy['button']}</a>
</td></tr></table>
<p style="margin:0 0 24px;color:#64748B;font-size:13px;line-height:1.9;">{$copy['privacy']}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="padding:18px;background-color:#F3F6FA;border:1px solid #E2E8F0;border-radius:8px;">
<p style="margin:0 0 6px;color:{$navy};font-size:14px;font-weight:bold;line-height:1.8;">{$copy['safety']}</p>
<p style="margin:0;font-size:13px;line-height:1.9;">{$copy['ignore']}</p>
</td></tr></table>
<p style="margin:24px 0 8px;color:#64748B;font-size:12px;line-height:1.9;">{$copy['fallback']}</p>
<p dir="ltr" style="margin:0;text-align:left;word-break:break-all;overflow-wrap:anywhere;font-size:12px;line-height:1.8;"><a href="{$link}" style="color:{$blue};text-decoration:underline;">{$link}</a></p>
</td></tr>
<tr><td style="padding:20px 28px;border-top:1px solid #E2E8F0;text-align:center;"><p style="margin:0;color:#64748B;font-size:12px;line-height:1.9;">{$copy['footer']}</p></td></tr>
</table></td></tr></table>
</body></html>
HTML;
	$email['message'] = \AutoDealership\Core\BrandPalette::email_html( $email['message'], $palette );
	$headers = $email['headers'] ?? array();
	if ( ! is_array( $headers ) ) { $headers = preg_split( '/\r?\n/', $headers ); }
	$headers = array_values( array_filter( $headers, static fn( $header ) => '' !== trim( $header ) && ! preg_match( '/^Content-Type\s*:/i', $header ) ) );
	$headers[] = 'Content-Type: text/html; charset=UTF-8';
	$headers[] = 'X-AutoBrands-Template: password-reset';
	$email['headers'] = $headers;
	return $email;
}, 20, 4 );

add_action( 'phpmailer_init', static function ( $mailer ): void {
	foreach ( $mailer->getCustomHeaders() as $header ) {
		if ( 'x-autobrands-template' === strtolower( $header[0] ) && 'password-reset' === $header[1] ) {
			$text = preg_replace( '/<\/(?:p|h1|div|table)>/i', "\n\n", $mailer->Body );
			$text = preg_replace( '/<head\b[^>]*>.*?<\/head>/is', '', $text );
			$mailer->AltBody = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
			break;
		}
	}
}, 20 );
