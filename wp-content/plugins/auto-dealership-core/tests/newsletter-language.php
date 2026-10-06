<?php
/** Offline language regression: invalid subscriptions stop before database access. */
define( 'ABSPATH', __DIR__ . '/' );
function check_ajax_referer() { return true; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function is_user_logged_in() { return false; }
function __( $text, $domain = '' ) { return $text; }
final class NewsletterResponse extends Exception {
	public function __construct( public array $payload, public int $status ) { parent::__construct( $payload['message'] ?? '' ); }
}
function wp_send_json_error( $payload, $status ) { throw new NewsletterResponse( $payload, $status ); }
require_once dirname( __DIR__ ) . '/src/Leads/MarketingSubscription.php';

foreach ( array(
	array( 'en', 'Enter a valid email address and explicitly agree to subscribe.' ),
	array( 'ar', 'أدخل بريدًا صحيحًا ووافق صراحة على الاشتراك.' ),
) as $case ) {
	$_POST = array( 'lang' => $case[0], 'email' => 'invalid', 'consent_marketing' => '0' );
	try {
		\AutoDealership\Leads\MarketingSubscription::handle_theme();
		fwrite( STDERR, "Missing validation error for {$case[0]}\n" );
		exit( 1 );
	} catch ( NewsletterResponse $response ) {
		if ( 400 !== $response->status || $case[1] !== $response->payload['message'] ) {
			fwrite( STDERR, "Wrong validation language for {$case[0]}\n" );
			exit( 1 );
		}
	}
}
echo "Newsletter validation uses the requested language (2 checks).\n";
