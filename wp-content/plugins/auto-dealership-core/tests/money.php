<?php
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return 'adc_base_currency_code' === $key ? 'SAR' : $default; } }
require_once dirname( __DIR__ ) . '/src/Pricing/Money.php';
require_once dirname( __DIR__ ) . '/src/Core/CurrencySettings.php';

use AutoDealership\Pricing\Money;

$checks = 0;
$check = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
};

$check( 0 === Money::parse( '000' ), 'Zero parses without octal behavior.' );
$check( 123456 === Money::parse( '123456' ), 'Digit strings parse as minor units.' );
$check( null === Money::parse( '10.00' ), 'Decimal strings are rejected.' );
$check( null === Money::parse( '1e3' ), 'Scientific notation is rejected.' );
$check( null === Money::parse( -1 ), 'Negative integers are rejected.' );
$check( null === Money::parse( PHP_INT_MAX . '0' ), 'Integer overflow is rejected.' );
$check( PHP_INT_MAX === Money::parse( (string) PHP_INT_MAX ), 'Maximum integer parses exactly.' );

$amounts = Money::calculate( 10000, 1000, 1500 );
$check( 1350 === $amounts['tax_amount'] && 10350 === $amounts['final_amount'], 'VAT uses frozen basis points and integer minor units.' );
$check( 1 === Money::calculate( 3, 0, 1667 )['tax_amount'], 'Tax rounds half up to one minor unit.' );
$check( '123.45' === Money::format( 12345 ), 'Money formatting is deterministic.' );
$check( 500 === Money::from_percent( '5' ) && 1550 === Money::from_percent( '15.5' ), 'Percent input converts independently of currency precision.' );
$check( '15.5' === Money::percent( 1550 ) && '0.05' === Money::percent( 5 ), 'Basis points format as user-facing percentages.' );

$thrown = false;
try { Money::calculate( 100, 100, 1500 ); } catch ( InvalidArgumentException $error ) { $thrown = true; }
$check( $thrown, 'A discount cannot consume the full base amount.' );

$thrown = false;
try { Money::calculate( PHP_INT_MAX, 0, 10000 ); } catch ( OverflowException $error ) { $thrown = true; }
$check( $thrown, 'Final amount overflow is rejected.' );

$check( 123456 === Money::from_major( '1234.56' ), 'Configured currency input converts exactly to minor units.' );
$check( 100000 === Money::from_major( '1000' ), 'Integer user input uses the configured currency.' );
$check( 1 === Money::from_major( '0.01' ), 'One halala is accepted as 0.01 SAR.' );
$check( 12345 === Money::from_major( '١٢٣٫٤٥' ), 'Arabic digits and decimal separator normalize exactly.' );
$check( 12345 === Money::from_major( '۱۲۳.۴۵' ), 'Persian digits normalize exactly.' );
$check( '1234.56' === Money::decimal( 123456 ), 'User field values round trip.' );
$check( '-12.34' === Money::decimal( -1234 ), 'Negative margins retain their sign.' );
$check( '0.00' === Money::decimal( '0' ), 'Zero has two decimal places.' );
$check( '999999999999999999.99' === Money::decimal( '99999999999999999999' ), 'SQL aggregate formatting does not overflow or lose precision.' );
$check( PHP_INT_MAX === Money::from_major( Money::format( PHP_INT_MAX ) ), 'Maximum integer round trips without floats.' );
foreach ( array( '1.001','1e3','-1','1,234.56','1٬234٫56','1.','',array(),null,1.25 ) as $invalid ) {
	$check( null === Money::from_major( $invalid ), 'Ambiguous, excess precision and invalid types are rejected.' );
}
$check( null === Money::from_major( Money::format( PHP_INT_MAX ) . '0' ), 'Overflow is rejected.' );
echo "PASS: $checks money checks.\n";
