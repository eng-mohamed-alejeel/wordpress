<?php
namespace AutoDealership\Tools;

defined( 'ABSPATH' ) || exit;

/** Provider-neutral public estimate. It never represents a finance approval or offer. */
final class LoanCalculator {
	public const DEFAULT_RATE_BPS = 450;
	public const DEFAULT_MONTHS = 60;
	public const MAX_AMOUNT = 100000000;
	public const MAX_MONTHS = 120;
	public const MAX_RATE_BPS = 10000;

	/** @return array<string,int|string|bool>|\WP_Error */
	public static function calculate( array $input ) {
		$price = self::integer( $input['price'] ?? null );
		$down = self::integer( $input['down_payment'] ?? 0 );
		$months = self::integer( $input['months'] ?? self::DEFAULT_MONTHS );
		$rate_bps = self::rate_bps( $input['annual_rate'] ?? ( self::DEFAULT_RATE_BPS / 100 ) );
		if ( null === $price || null === $down || null === $months || null === $rate_bps || $price < 1 || $price > self::MAX_AMOUNT || $down > $price || $months < 1 || $months > self::MAX_MONTHS || $rate_bps > self::MAX_RATE_BPS ) {
			return new \WP_Error( 'adc_loan_invalid', __( 'راجع مبلغ السيارة والدفعة والنسبة والمدة ثم حاول مجددًا.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}

		$principal = $price - $down;
		$monthly_rate = $rate_bps / 10000 / 12;
		if ( 0 === $principal ) {
			$payment = 0;
		} elseif ( 0 === $rate_bps ) {
			$payment = (int) round( $principal / $months, 0, PHP_ROUND_HALF_UP );
		} else {
			$divisor = 1 - pow( 1 + $monthly_rate, -$months );
			if ( $divisor <= 0 || ! is_finite( $divisor ) ) {
				return new \WP_Error( 'adc_loan_unavailable', __( 'تعذر حساب التقدير حاليًا.', 'auto-dealership-core' ), array( 'status'=>503 ) );
			}
			$payment = (int) round( $principal * $monthly_rate / $divisor, 0, PHP_ROUND_HALF_UP );
		}
		$total = $payment * $months;
		return array(
			'price' => $price,
			'down_payment' => $down,
			'principal' => $principal,
			'annual_rate_bps' => $rate_bps,
			'months' => $months,
			'monthly_payment' => $payment,
			'estimated_total' => $total,
			'estimated_finance_cost' => max( 0, $total - $principal ),
			'currency' => 'SAR',
			'estimate_only' => true,
		);
	}

	/** Neutral constraints/defaults for a theme form adapter. */
	public static function view_model( int $price = 0 ): array {
		return array(
			'price' => min( self::MAX_AMOUNT, max( 0, $price ) ),
			'down_payment' => 0,
			'annual_rate' => number_format( self::DEFAULT_RATE_BPS / 100, 2, '.', '' ),
			'months' => self::DEFAULT_MONTHS,
			'max_amount' => self::MAX_AMOUNT,
			'max_months' => self::MAX_MONTHS,
			'max_rate' => number_format( self::MAX_RATE_BPS / 100, 2, '.', '' ),
		);
	}

	private static function integer( $value ): ?int {
		if ( ! is_scalar( $value ) || ! preg_match( '/\A[0-9]+\z/', trim( (string) $value ) ) ) {
			return null;
		}
		$value = (string) $value;
		if ( strlen( $value ) > 12 ) {
			return null;
		}
		return (int) $value;
	}

	private static function rate_bps( $value ): ?int {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( ! preg_match( '/\A(?:[0-9]{1,3})(?:\.[0-9]{1,2})?\z/', $value ) ) {
			return null;
		}
		return (int) round( (float) $value * 100, 0, PHP_ROUND_HALF_UP );
	}
}
