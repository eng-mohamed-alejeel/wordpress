<?php
/** Local CLI setup: verify SMTP authentication, then activate outside-webroot credentials. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$configPath = $root . '/wp-config.php';
$config = file_get_contents( $configPath );
$marker = "// AutoBrands local Gmail settings (outside webroot).";
$pattern = '/(?:\/\/ AutoBrands local Gmail settings \(outside webroot\)\.\r?\nrequire [^\r\n]+;\r?\n)?(?:defined\( \'ADC_MAIL_MODE\' \) \|\| )?define\( \'ADC_MAIL_MODE\', \'mailpit\' \);/';
if ( 1 !== preg_match( $pattern, $config ) ) { fwrite( STDERR, "Mail configuration changed; setup stopped without editing it.\n" ); exit( 1 ); }
if ( in_array( '--mailpit', $argv, true ) ) {
    $updated = preg_replace( $pattern, "define( 'ADC_MAIL_MODE', 'mailpit' );", $config, 1 );
} else {
    $input = json_decode( stream_get_contents( STDIN ), true );
    $password = preg_replace( '/\s+/', '', (string) ( $input['password'] ?? '' ) );
    $directory = realpath( (string) ( $input['directory'] ?? '' ) );
    if ( ! preg_match( '/\A[a-z]{16}\z/', $password ) || ! $directory || str_starts_with( strtolower( str_replace( '\\', '/', $directory ) . '/' ), strtolower( str_replace( '\\', '/', $root ) . '/' ) ) ) {
        fwrite( STDERR, "Invalid app password or credential directory.\n" ); exit( 1 );
    }
    require $root . '/wp-includes/PHPMailer/Exception.php';
    require $root . '/wp-includes/PHPMailer/SMTP.php';
    require $root . '/wp-includes/PHPMailer/PHPMailer.php';
    $mailer = new PHPMailer\PHPMailer\PHPMailer( true );
    $mailer->isSMTP();
    $mailer->Host = 'smtp.gmail.com';
    $mailer->Port = 587;
    $mailer->SMTPSecure = 'tls';
    $mailer->SMTPAuth = true;
    $mailer->Username = 'mohamedipos@gmail.com';
    $mailer->Password = $password;
    $mailer->Timeout = 15;
    $mailer->SMTPDebug = 0;
    try {
        if ( ! $mailer->smtpConnect() ) { throw new RuntimeException(); }
        $mailer->smtpClose();
    } catch ( Throwable $error ) {
        $mailer->smtpClose();
        fwrite( STDERR, "Gmail authentication failed. Check the new app password and network. Configuration was not changed.\n" ); exit( 1 );
    }
    $settings = array( 'ADC_MAIL_MODE'=>'smtp', 'ADC_SMTP_HOST'=>'smtp.gmail.com', 'ADC_SMTP_PORT'=>'587', 'ADC_SMTP_ENCRYPTION'=>'tls', 'ADC_SMTP_USERNAME'=>'mohamedipos@gmail.com', 'ADC_SMTP_PASSWORD'=>$password, 'ADC_MAIL_FROM'=>'mohamedipos@gmail.com', 'ADC_MAIL_FROM_NAME'=>'AutoBrands' );
    $credentialPath = $directory . '/gmail.php';
    $content = "<?php\n";
    foreach ( $settings as $key=>$value ) { $content .= 'define( ' . var_export( $key, true ) . ', ' . var_export( $value, true ) . " );\n"; }
    if ( false === file_put_contents( $credentialPath, $content, LOCK_EX ) ) { fwrite( STDERR, "Could not save credentials.\n" ); exit( 1 ); }
    $replacement = $marker . "\nrequire " . var_export( $credentialPath, true ) . ";\ndefine( 'ADC_MAIL_MODE', 'mailpit' );";
    // The fallback is conditional; the credential file defines smtp first.
    $replacement = str_replace( "define( 'ADC_MAIL_MODE', 'mailpit' );", "defined( 'ADC_MAIL_MODE' ) || define( 'ADC_MAIL_MODE', 'mailpit' );", $replacement );
    $updated = preg_replace_callback( $pattern, static fn() => $replacement, $config, 1 );
}
if ( false === file_put_contents( $configPath, $updated, LOCK_EX ) ) { fwrite( STDERR, "Could not activate transport.\n" ); exit( 1 ); }
echo isset( $settings ) ? "Gmail SMTP authentication succeeded and real mail is now enabled. No message was sent.\n" : "Mailpit restored.\n";
