<?php
/** CLI-only integration check: synthetic messages are retained in local Mailpit. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'mailpit' !== autobrands_mail_setting( 'ADC_MAIL_MODE' ) ) {
	fwrite( STDERR, "This test requires local Mailpit.\n" ); exit( 1 );
}
$check = static function ( bool $ok, string $label ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) { exit( 1 ); }
};
$run = 'ADC-MAIL-' . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 3 ) );
$attachment = tempnam( sys_get_temp_dir(), 'adc-mail-' );
file_put_contents( $attachment, 'Synthetic attachment / مرفق تجريبي' );
try {
	$check( wp_mail( 'qa@autobrands.test', $run . ' Arabic HTML', '<html lang="ar" dir="rtl"><body><p>اختبار البريد المحلي — العربية سليمة</p><a href="' . esc_url( wp_lostpassword_url() ) . '">استعادة كلمة المرور</a></body></html>', array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: reply@autobrands.test' ), array( $attachment ) ), 'Arabic HTML, reply-to and attachment accepted' );
	$check( wp_mail( 'qa@autobrands.test', $run . ' Follow-up', 'متابعة عمل مستحقة / Due workflow follow-up ' . admin_url( 'admin.php?page=adc-follow-ups' ) ), 'Plain-text follow-up accepted' );
} finally { unlink( $attachment ); }
$messages = json_decode( file_get_contents( 'http://127.0.0.1:8025/api/v1/messages' ), true );
$found = array_values( array_filter( $messages['messages'] ?? array(), static fn( $message ) => str_starts_with( $message['Subject'], $run ) ) );
$check( 2 === count( $found ), 'Exactly two matching messages captured' );
foreach ( $found as $message ) {
	$detail = json_decode( file_get_contents( 'http://127.0.0.1:8025/api/v1/message/' . rawurlencode( $message['ID'] ) ), true );
	$check( 'wordpress@autobrands.test' === $detail['From']['Address'], 'Local sender verified' );
	if ( str_ends_with( $detail['Subject'], 'Arabic HTML' ) ) {
		$check( str_contains( $detail['HTML'], 'العربية سليمة' ) && str_contains( $detail['HTML'], 'dir="rtl"' ), 'UTF-8 and RTL preserved' );
		$check( 1 === count( $detail['Attachments'] ?? array() ), 'Attachment captured' );
		$check( 'reply@autobrands.test' === ( $detail['ReplyTo'][0]['Address'] ?? '' ), 'Reply-To preserved' );
		$check( str_contains( $detail['HTML'], 'wp-login.php' ), 'Recovery link preserved' );
	}
}
$errors = array();
add_action( 'wp_mail_failed', static function ( $error ) use ( &$errors ) { $errors[] = $error->get_error_code(); } );
$reject = static function ( $mailer ) { $mailer->Port = 1; $mailer->Timeout = 1; };
add_action( 'phpmailer_init', $reject, PHP_INT_MAX );
$check( false === wp_mail( 'qa@autobrands.test', $run . ' Failure', 'Synthetic failure' ), 'Unavailable SMTP returns false' );
remove_action( 'phpmailer_init', $reject, PHP_INT_MAX );
$check( in_array( 'wp_mail_failed', $errors, true ), 'Failure hook fired' );
putenv( 'ADC_MAIL_MODE=disabled' );
$check( false === wp_mail( 'qa@autobrands.test', $run . ' Disabled', 'Synthetic disabled transport' ), 'Disabled transport fails closed' );
putenv( 'ADC_MAIL_MODE' );
putenv( 'ADC_MAIL_MODE=smtp' );
putenv( 'ADC_SMTP_HOST=' );
$check( false === wp_mail( 'qa@autobrands.test', $run . ' Invalid SMTP', 'Synthetic invalid configuration' ), 'Incomplete SMTP configuration fails closed' );
putenv( 'ADC_SMTP_HOST' );
putenv( 'ADC_MAIL_MODE' );
echo 'Run: ' . $run . "\n";
