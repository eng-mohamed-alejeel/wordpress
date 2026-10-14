<?php
/** Optional loopback SMTP test. Never loads source configuration or subscriber data. */
if ( '1' !== getenv( 'ADC_VERIFICATION_MAILPIT' ) ) { return; }
if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Mailpit verification test requires an isolated local database.' ); }
require_once ABSPATH . 'wp-includes/PHPMailer/Exception.php';
require_once ABSPATH . 'wp-includes/PHPMailer/SMTP.php';
require_once ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php';
putenv( 'ADC_MAIL_MODE=mailpit' );
require_once ABSPATH . 'wp-content/mu-plugins/autobrands-mail.php';
$arabic_mail = null;
foreach ( $captured as $candidate ) { if ( str_contains( $candidate['subject'], 'تأكيد الاشتراك' ) ) { $arabic_mail = $candidate; break; } }
$marker = 'ADC verification local ' . wp_generate_uuid4();
foreach ( array( 'ar'=>$arabic_mail, 'en'=>$pending_mail ) as $language=>$verification_mail ) {
	if ( ! $verification_mail ) { throw new RuntimeException( 'A localized verification fixture is missing.' ); }
	$mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
	$mailer->CharSet='UTF-8'; $mailer->isHTML( true );
	$mailer->Subject=$marker . ' ' . $language;
	$mailer->Body=$verification_mail['message'];
	$mailer->addAddress( 'qa@autobrands.test' ); $mailer->addCustomHeader( 'X-AutoBrands-Template', 'branded' );
	do_action( 'phpmailer_init', $mailer );
	$check( '127.0.0.1' === $mailer->Host && 1025 === $mailer->Port && ! $mailer->SMTPAuth, 'Mailpit transport is strictly loopback for ' . $language . ' verification fixture' );
	$check( str_contains( $mailer->AltBody, '#token=' ) && str_contains( $mailer->Body, 'lang="' . $language . '"' ), 'HTML and plain text preserve the localized verification link for ' . $language );
	$check( true === $mailer->send(), 'Local Mailpit accepts ' . $language . ' verification message' );
}
putenv( 'ADC_MAIL_MODE' );
echo "Mailpit verification captured two synthetic QA messages. No external SMTP used.\n";
