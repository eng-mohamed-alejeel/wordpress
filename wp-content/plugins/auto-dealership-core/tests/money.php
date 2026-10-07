<?php
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/src/Pricing/Money.php';

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

$thrown = false;
try { Money::calculate( 100, 100, 1500 ); } catch ( InvalidArgumentException $error ) { $thrown = true; }
$check( $thrown, 'A discount cannot consume the full base amount.' );

$thrown = false;
try { Money::calculate( PHP_INT_MAX, 0, 10000 ); } catch ( OverflowException $error ) { $thrown = true; }
$check( $thrown, 'Final amount overflow is rejected.' );

$check( 123456 === Money::from_sar( '1234.56' ), 'SAR input converts exactly to minor units.' );
$check( 100000 === Money::from_sar( '1000' ), 'Integer user input is SAR.' );
$check( 1 === Money::from_sar( '0.01' ), 'One halala is accepted as 0.01 SAR.' );
$check( 12345 === Money::from_sar( '١٢٣٫٤٥' ), 'Arabic digits and decimal separator normalize exactly.' );
$check( 12345 === Money::from_sar( '۱۲۳.۴۵' ), 'Persian digits normalize exactly.' );
$check( '1234.56' === Money::decimal( 123456 ), 'User field values round trip.' );
$check( '-12.34' === Money::decimal( -1234 ), 'Negative margins retain their sign.' );
$check( '0.00' === Money::decimal( '0' ), 'Zero has two decimal places.' );
$check( '999999999999999999.99' === Money::decimal( '99999999999999999999' ), 'SQL aggregate formatting does not overflow or lose precision.' );
$check( PHP_INT_MAX === Money::from_sar( Money::format( PHP_INT_MAX ) ), 'Maximum integer round trips without floats.' );
foreach ( array( '1.001','1e3','-1','1,234.56','1٬234٫56','1.','',array(),null,1.25 ) as $invalid ) {
	$check( null === Money::from_sar( $invalid ), 'Ambiguous, excess precision and invalid types are rejected.' );
}
$check( null === Money::from_sar( Money::format( PHP_INT_MAX ) . '0' ), 'Overflow is rejected.' );
echo "PASS: $checks money checks.\n";
