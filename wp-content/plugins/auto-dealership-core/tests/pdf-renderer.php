<?php
declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
define( 'ADC_PATH', dirname( __DIR__ ) . '/' );
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
class WP_Error {}

require ADC_PATH . 'vendor/autoload.php';
require ADC_PATH . 'src/Reports/ServerPdfRenderer.php';
require ADC_PATH . 'src/Reports/AffiliateCommissionExport.php';

if ( 'portrait' !== \AutoDealership\Reports\ServerPdfRenderer::normalize_orientation( 'invalid', 'portrait' ) || 'landscape' !== \AutoDealership\Reports\ServerPdfRenderer::normalize_orientation( 'LANDSCAPE' ) ) {
	fwrite( STDERR, "PDF orientation normalization failed\n" );
	exit( 1 );
}
$environment = \AutoDealership\Reports\ServerPdfRenderer::environment();
foreach ( array( 'mbstring', 'dom', 'arabic_font', 'renderer', 'temp_dir' ) as $required_check ) {
	if ( empty( $environment['checks'][ $required_check ]['ok'] ) ) {
		fwrite( STDERR, 'PDF environment preflight failed: ' . $required_check . "\n" );
		exit( 1 );
	}
}

$documents = array(
	'ar' => '<html lang="ar" dir="rtl"><body><h1>تقرير اختبار عربي</h1><p>عمولة المسوق SAR</p></body></html>',
	'en' => '<html lang="en" dir="ltr"><body><h1>English PDF test report</h1><p>Affiliate commission SAR</p></body></html>',
);
$orientations = array( 'ar' => 'portrait', 'en' => 'landscape' );
$pdfs = array();
foreach ( $documents as $language => $document ) {
	$pdf = \AutoDealership\Reports\ServerPdfRenderer::render( $document, 'A4', $orientations[ $language ] );
	if ( ! is_string( $pdf ) || ! str_starts_with( $pdf, '%PDF-' ) ) {
		fwrite( STDERR, strtoupper( $language ) . " PDF smoke test failed\n" );
		exit( 1 );
	}
	$pdfs[ $language ] = $pdf;
}
$report_filters = array( 'from' => '2026-01-01', 'to' => '2026-01-31', 'timezone' => 'Asia/Riyadh' );
$report_pdfs = array(
	'ar' => \AutoDealership\Reports\AffiliateCommissionExport::pdf( array(), $report_filters, 'portrait', 'ar' ),
	'en' => \AutoDealership\Reports\AffiliateCommissionExport::pdf( array(), $report_filters, 'landscape', 'en' ),
);
foreach ( $report_pdfs as $language => $pdf ) {
	if ( ! is_string( $pdf ) || ! str_starts_with( $pdf, '%PDF-' ) ) {
		fwrite( STDERR, strtoupper( $language ) . " affiliate report PDF smoke test failed\n" );
		exit( 1 );
	}
}
if ( is_dir( dirname( __DIR__, 4 ) . '/.tmp' ) ) {
	foreach ( $pdfs as $language => $pdf ) {
		file_put_contents( dirname( __DIR__, 4 ) . '/.tmp/adc-pdf-margin-check-' . $language . '.pdf', $pdf );
	}
	file_put_contents( dirname( __DIR__, 4 ) . '/.tmp/adc-pdf-margin-check.pdf', $pdfs['ar'] );
	foreach ( $report_pdfs as $language => $pdf ) {
		file_put_contents( dirname( __DIR__, 4 ) . '/.tmp/adc-pdf-report-check-' . $language . '.pdf', $pdf );
	}
}
fwrite( STDOUT, 'Arabic and English document/report PDF smoke tests passed (' . strlen( $pdfs['ar'] ) . ' / ' . strlen( $pdfs['en'] ) . ' / ' . strlen( $report_pdfs['ar'] ) . ' / ' . strlen( $report_pdfs['en'] ) . " bytes)\n" );
