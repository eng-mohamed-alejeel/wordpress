<?php
namespace AutoDealership\Security;

defined( 'ABSPATH' ) || exit;

/** Account/address limits with a generous shared-network safety budget. */
final class AuthenticationGuard {
	private static bool $booted = false;
	private static ?\WP_Error $denied = null;
	private static ?\WP_Error $application_denied = null;

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		add_filter( 'authenticate', array( self::class, 'begin' ), 0, 3 );
		// Core validators do not consistently preserve a preceding WP_Error.
		// Wrap them, rather than merely returning an error before expensive hashing.
		foreach ( array( 'wp_authenticate_username_password'=>'username', 'wp_authenticate_email_password'=>'email', 'wp_authenticate_application_password'=>'application' ) as $callback=>$method ) {
			if ( remove_filter( 'authenticate', $callback, 20 ) ) {
				add_filter( 'authenticate', array( self::class, $method ), 20, 3 );
			}
		}
		add_filter( 'authenticate', array( self::class, 'finish' ), PHP_INT_MAX, 3 );
		// REST Basic authentication bypasses the authenticate filter entirely.
		if ( remove_filter( 'determine_current_user', 'wp_validate_application_password', 20 ) ) {
			add_filter( 'determine_current_user', array( self::class, 'validate_application' ), 20 );
		}
		add_filter( 'rest_authentication_errors', array( self::class, 'rest_error' ), PHP_INT_MAX );
	}

	private static function identity( string $login ): string {
		// Match core's username-first lookup. Email/login aliases share one budget.
		$user = get_user_by( 'login', $login );
		if ( ! $user && is_email( $login ) ) { $user = get_user_by( 'email', $login ); }
		return $user ? 'user:' . $user->ID : 'unknown:' . strtolower( trim( $login ) );
	}

	private static function consume( string $policy, string $identity, string $network_policy ): ?\WP_Error {
		$address = ClientAddress::resolve();
		// Check the narrow bucket first: repeatedly hitting an already blocked
		// account must not consume the remaining allowance of its shared network.
		$subject = 'credentials-v2:' . hash( 'sha256', $address . '|' . $identity );
		$result = PublicRequestGuard::consume( $policy, $subject );
		if ( is_wp_error( $result ) ) { return $result; }
		$result = PublicRequestGuard::consume( $network_policy, 'credentials-network-v2:' . $address );
		return is_wp_error( $result ) ? $result : null;
	}

	/** Registration is independent of sign-in and other emails on the same NAT. */
	public static function registration( string $email ): ?\WP_Error {
		return self::consume( 'account_register', 'registration:' . strtolower( sanitize_email( $email ) ), 'registration_network' );
	}

	public static function begin( $user, $username, $password ) {
		// A fresh budget decision for EACH authenticate invocation, including XML-RPC
		// multicalls. Never memoize an allowed request for subsequent credentials.
		self::$denied = null;
		if ( is_string( $username ) && is_string( $password ) && '' !== $username && '' !== $password ) {
			self::$denied = self::consume( 'account_auth', self::identity( $username ), 'auth_network' );
		}
		return self::$denied ?? $user;
	}

	public static function username( $user, $username, $password ) {
		return self::$denied ?? wp_authenticate_username_password( $user, $username, $password );
	}
	public static function email( $user, $username, $password ) {
		return self::$denied ?? wp_authenticate_email_password( $user, $username, $password );
	}
	public static function application( $user, $username, $password ) {
		return self::$denied ?? wp_authenticate_application_password( $user, $username, $password );
	}

	public static function finish( $user, $username = '', $password = '' ) {
		if ( ! self::$denied ) { return $user; }
		self::response_headers( self::$denied );
		return self::$denied;
	}

	public static function validate_application( $user ) {
		self::$application_denied = null;
		if ( ! empty( $user ) || ! wp_is_application_passwords_available()
			|| ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) { return $user; }
		self::$application_denied = self::consume( 'application_auth', self::identity( (string) $_SERVER['PHP_AUTH_USER'] ), 'application_network' );
		if ( self::$application_denied ) {
			do_action( 'application_password_failed_authentication', self::$application_denied );
			return $user;
		}
		return wp_validate_application_password( $user );
	}

	public static function rest_error( $error ) {
		if ( ! self::$application_denied ) { return $error; }
		self::response_headers( self::$application_denied );
		return self::$application_denied;
	}

	private static function response_headers( \WP_Error $error ): void {
		if ( headers_sent() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) { return; }
		$data = (array) $error->get_error_data();
		status_header( (int) ( $data['status'] ?? 503 ) );
		nocache_headers();
		if ( isset( $data['retry_after'] ) ) { header( 'Retry-After: ' . max( 1, (int) $data['retry_after'] ) ); }
	}
}
