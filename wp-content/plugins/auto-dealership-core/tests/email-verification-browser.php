<?php
/** Optional real browser checks; bootstraps only the disposable database. */
if ( '1' !== getenv( 'ADC_VERIFICATION_BROWSER' ) ) { return; }
if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) || '127.0.0.1:33381' !== DB_HOST ) { throw new RuntimeException( 'Browser test requires the dedicated disposable database server.' ); }
wp_set_current_user( 0 );
$_GET['lang']='en';
$wpdb->query( 'DELETE FROM ' . \AutoDealership\Security\PublicRequestGuard::table() . " WHERE policy_key IN ('intake','email_verification')" );
$_POST = array( 'display_name'=>'Browser verification', 'email'=>'browser-verification@example.invalid', 'password'=>'StrongPass123!test', 'password_confirm'=>'StrongPass123!test', 'privacy_consent'=>'1', 'phone'=>'+966505555554' );
$browser_account = $register->invoke( null );
\AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) );
$browser_account_token = $verification_token( end( $captured ) );
$subscribe( 'browser-newsletter@example.invalid', 'en', false ); $browser_newsletter_token = $verification_token( end( $captured ) );
$subscribe( 'browser-concurrent@example.invalid', 'ar', false ); $browser_concurrent_token = $verification_token( end( $captured ) );
$temporary = tempnam( sys_get_temp_dir(), 'adc-verification-' );
$config = array( 'database'=>DB_NAME, 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'http_config'=>ADC_TEST_HTTP_CONFIG );
file_put_contents( $temporary, wp_json_encode( $config ) );
$server = null;
try {
	$environment = getenv(); $environment['ADC_VERIFICATION_HTTP_CONFIG'] = $temporary; $environment['ADC_THEME_TEST']='1';
	$server = proc_open( array( PHP_BINARY, '-d', 'log_errors=1', '-S', '127.0.0.1:33382', __DIR__ . '/email-verification-router.php' ), array( 0=>array( 'pipe','r' ), 1=>array( 'file',$temporary . '.server','a' ), 2=>array( 'file',$temporary . '.server','a' ) ), $pipes, ABSPATH, $environment, array( 'bypass_shell'=>true ) );
	if ( ! is_resource( $server ) ) { throw new RuntimeException( 'Browser test server did not start.' ); }
	fclose( $pipes[0] );
	$ready = false;
	for ( $attempt = 0; $attempt < 40; ++$attempt ) { $connection = @fsockopen( '127.0.0.1', 33382, $errno, $error, 0.1 ); if ( $connection ) { fclose( $connection ); $ready = true; break; } usleep( 100000 ); }
	if ( ! $ready ) { throw new RuntimeException( 'Browser test server did not become ready.' ); }
	$browser = proc_open( array( 'node', __DIR__ . '/email-verification-browser.cjs' ), array( 0=>array( 'pipe','r' ), 1=>array( 'pipe','w' ), 2=>array( 'file',$temporary . '.browser','a' ) ), $browser_pipes, ABSPATH, null, array( 'bypass_shell'=>true ) );
	if ( ! is_resource( $browser ) ) { throw new RuntimeException( 'Browser test did not start.' ); }
	fwrite( $browser_pipes[0], wp_json_encode( array( 'base'=>'http://127.0.0.1:33382/', 'account'=>$browser_account_token, 'newsletter'=>$browser_newsletter_token, 'concurrent'=>$browser_concurrent_token, 'password'=>'StrongPass123!test', 'root'=>ABSPATH ) ) ); fclose( $browser_pipes[0] );
	$output = stream_get_contents( $browser_pipes[1] ); fclose( $browser_pipes[1] );
	$result = proc_close( $browser );
	echo $output;
	if ( 0 !== $result && is_file( $temporary . '.browser' ) ) { echo preg_replace('/token=[^\s&#]+/','token=[redacted]',file_get_contents($temporary.'.browser'))."\n"; }
	if(0!==$result&&is_file($temporary.'.server')){echo implode("\n",array_slice(explode("\n",file_get_contents($temporary.'.server')),-35))."\n";}
	$check( 0 === $result, 'Real browser verification, scanner safety, replay and concurrent consumption checks pass' );
} finally {
	if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); }
	foreach ( array( $temporary, $temporary . '.server', $temporary . '.browser' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
}
