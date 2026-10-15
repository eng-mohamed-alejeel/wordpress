<?php
namespace AutoDealership\Accounts;

defined( 'ABSPATH' ) || exit;

/** Shared email layout; styles stay inline for email-client compatibility. */
final class BrandedMail {
	public static function render( string $title, string $body, string $button = '', string $url = '', string $footer = '', string $locale = 'ar' ): string {
		$ar = str_starts_with( $locale, 'ar' );
		$dir = $ar ? 'rtl' : 'ltr'; $align = $ar ? 'right' : 'left'; $lang = $ar ? 'ar' : 'en';
		$brand = esc_html( \AutoDealership\Core\ProductSetup::brand( $lang ) );
		$brand_english = esc_html( \AutoDealership\Core\ProductSetup::brand( 'en' ) );
		$options = (array) get_option( 'car_dealer_theme_settings', array() );
		$navy = sanitize_hex_color( $options['primary_color'] ?? '' ) ?: '#0E2A4A';
		$blue = sanitize_hex_color( $options['accent_color'] ?? '' ) ?: '#2563EB';
		$heading = esc_html( $title );
		$content = wp_kses_post( $body );
		$cta = '';
		if ( $button && $url ) {
			$cta = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0"><tr><td bgcolor="' . esc_attr( $blue ) . '" style="border-radius:8px;mso-padding-alt:16px 24px"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:16px 24px;border:1px solid ' . esc_attr( $blue ) . ';border-radius:8px;color:#fff;text-decoration:none;font-size:16px;font-weight:bold">' . esc_html( $button ) . '</a></td></tr></table>';
		}
		$footer = wp_kses_post( $footer );
		return '<!doctype html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $heading . '</title></head><body style="margin:0;background:#F3F6FA;font-family:Tahoma,Arial,sans-serif;color:#24364B"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#F3F6FA"><tr><td align="center" style="padding:32px 12px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#fff;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden"><tr><td bgcolor="' . esc_attr( $navy ) . '" dir="' . $dir . '" style="padding:28px;text-align:' . $align . ';border-bottom:4px solid ' . esc_attr( $blue ) . '"><p style="margin:0;color:#fff;font-size:26px;font-weight:bold;line-height:1.6">' . $brand . '</p><p dir="ltr" style="margin:4px 0 0;color:#D5E3F4;letter-spacing:3px;font-size:12px;text-align:' . $align . '">' . $brand_english . '</p></td></tr><tr><td dir="' . $dir . '" style="padding:32px 28px;text-align:' . $align . ';font-size:15px;line-height:1.9"><h1 style="margin:0 0 24px;font-size:26px;line-height:1.5;color:' . esc_attr( $navy ) . '">' . $heading . '</h1>' . $content . $cta . '</td></tr><tr><td dir="' . $dir . '" style="padding:20px 28px;text-align:center;border-top:1px solid #E2E8F0;font-size:12px;line-height:1.9;color:#64748B">' . $footer . '</td></tr></table></td></tr></table></body></html>';
	}

	public static function send( string $to, string $subject, string $html, array $headers = array() ): bool {
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$headers[] = 'X-AutoBrands-Template: branded';
		return wp_mail( $to, $subject, $html, $headers );
	}

	public static function boot(): void {
		add_action( 'phpmailer_init', static function ( $mailer ): void {
			foreach ( $mailer->getCustomHeaders() as $header ) {
				if ( 'x-autobrands-template' !== strtolower( $header[0] ) || 'branded' !== $header[1] ) { continue; }
				$text = preg_replace_callback( '/<a\b[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', static fn( $match ) => wp_strip_all_tags( $match[2] ) . ' (' . html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) . ')', $mailer->Body );
				$text = preg_replace( '/<head\b[^>]*>.*?<\/head>/is', '', $text );
				$text = preg_replace( '/<\/(?:p|h1|h2|h3|div|table|li)>/i', "\n\n", $text );
				$mailer->AltBody = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
				break;
			}
		}, 25 );
	}
}
