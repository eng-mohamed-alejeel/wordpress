<?php
namespace AutoDealership\Integrations;

defined( 'ABSPATH' ) || exit;

/** Validated provider acknowledgement returned by delivery, polling or a verified webhook. */
final class ProviderResult {
	public const STATUSES = array( 'pending', 'accepted', 'rejected' );

	private string $status;
	private string $remote_reference;
	private string $code;

	private function __construct( string $status, string $remote_reference, string $code ) {
		$status = sanitize_key( $status );
		$remote_reference = trim( $remote_reference );
		$code = sanitize_key( $code );
		if ( ! in_array( $status, self::STATUSES, true ) || ! self::valid_reference( $remote_reference ) || mb_strlen( $code ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid provider result.' );
		}
		$this->status = $status;
		$this->remote_reference = $remote_reference;
		$this->code = $code;
	}

	public static function pending( string $remote_reference, string $code = '' ): self { return new self( 'pending', $remote_reference, $code ); }
	public static function accepted( string $remote_reference, string $code = '' ): self { return new self( 'accepted', $remote_reference, $code ); }
	public static function rejected( string $remote_reference, string $code ): self { return new self( 'rejected', $remote_reference, $code ); }

	public function status(): string { return $this->status; }
	public function remote_reference(): string { return $this->remote_reference; }
	public function code(): string { return $this->code; }

	private static function valid_reference( string $value ): bool {
		return mb_strlen( $value ) >= 1 && mb_strlen( $value ) <= 190 && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,189}\z/', $value );
	}
}
