<?php
/** Capture command output while running the real migration command on synthetic data. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
final class WP_CLI {
	public static array $messages = array();
	public static function log( string $message ): void { self::$messages[] = $message; }
	public static function warning( string $message ): void {}
	public static function error( string $message ): void { throw new RuntimeException( $message ); }
}
