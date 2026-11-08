<?php
namespace AutoDealership\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Renders trusted plugin HTML to a real PDF on the server.
 *
 * Remote resources are deliberately disabled. Documents must be self-contained
 * so a report cannot make an unexpected network request during export.
 */
final class ServerPdfRenderer {
	private const DEFAULT_LANGUAGE = 'ar';
	private const PAGE_MARGIN_MM = 12;
	private const PAGE_MARGIN_BOTTOM_MM = 14;

	public static function available(): bool {
		return (bool) self::environment()['ready'];
	}

	/**
	 * Return a safe, non-sensitive preflight report for server-side PDF exports.
	 *
	 * The renderer libraries declare these PHP extensions as platform requirements.
	 * Checking them before rendering prevents a misleading fallback attempt and gives
	 * administrators an actionable result on shared hosting environments.
	 */
	public static function environment(): array {
		$plugin_path = defined( 'ADC_PATH' ) ? rtrim( ADC_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		$has_mpdf = class_exists( '\\Mpdf\\Mpdf' ) && class_exists( '\\Mpdf\\Output\\Destination' );
		$has_dompdf = class_exists( '\\Dompdf\\Dompdf' ) && class_exists( '\\Dompdf\\Options' );
		$font_paths = array(
			'mPDF DejaVu Sans'   => $plugin_path . 'vendor' . DIRECTORY_SEPARATOR . 'mpdf' . DIRECTORY_SEPARATOR . 'mpdf' . DIRECTORY_SEPARATOR . 'ttfonts' . DIRECTORY_SEPARATOR . 'DejaVuSans.ttf',
			'Dompdf DejaVu Sans' => $plugin_path . 'vendor' . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'DejaVuSans.ttf',
		);
		$available_fonts = array();
		foreach ( $font_paths as $font_label => $font_path ) {
			if ( is_readable( $font_path ) ) {
				$available_fonts[] = $font_label;
			}
		}
		$temp_root = function_exists( 'sys_get_temp_dir' ) ? (string) sys_get_temp_dir() : '';
		$mbstring_loaded = extension_loaded( 'mbstring' );
		$dom_loaded = extension_loaded( 'dom' );
		$gd_loaded = extension_loaded( 'gd' );
		$temp_ready = '' !== $temp_root && is_dir( $temp_root ) && is_writable( $temp_root );
		$checks = array(
			'php' => array(
				'label' => __( 'PHP version', 'auto-dealership-core' ), 'required' => true,
				'ok' => version_compare( PHP_VERSION, '8.0.0', '>=' ), 'value' => PHP_VERSION,
				'detail' => __( 'Requires PHP 8.0 or newer.', 'auto-dealership-core' ),
			),
			'mbstring' => array(
				'label' => __( 'mbstring extension', 'auto-dealership-core' ), 'required' => true,
				'ok' => $mbstring_loaded, 'value' => $mbstring_loaded ? __( 'Loaded', 'auto-dealership-core' ) : __( 'Missing', 'auto-dealership-core' ),
				'detail' => __( 'Required for UTF-8 and Arabic text processing.', 'auto-dealership-core' ),
			),
			'dom' => array(
				'label' => __( 'DOM extension', 'auto-dealership-core' ), 'required' => true,
				'ok' => $dom_loaded, 'value' => $dom_loaded ? __( 'Loaded', 'auto-dealership-core' ) : __( 'Missing', 'auto-dealership-core' ),
				'detail' => __( 'Required by the HTML/PDF compatibility layer.', 'auto-dealership-core' ),
			),
			'gd' => array(
				'label' => __( 'GD extension', 'auto-dealership-core' ), 'required' => false,
				'ok' => $gd_loaded, 'value' => $gd_loaded ? __( 'Loaded', 'auto-dealership-core' ) : __( 'Not loaded', 'auto-dealership-core' ),
				'detail' => __( 'Recommended for image processing; not required for text-only reports.', 'auto-dealership-core' ),
			),
			'mpdf' => array(
				'label' => __( 'mPDF library', 'auto-dealership-core' ), 'required' => false,
				'ok' => $has_mpdf, 'value' => $has_mpdf ? __( 'Available', 'auto-dealership-core' ) : __( 'Unavailable', 'auto-dealership-core' ),
				'detail' => __( 'Primary renderer with Arabic shaping and bidirectional layout.', 'auto-dealership-core' ),
			),
			'dompdf' => array(
				'label' => __( 'Dompdf library', 'auto-dealership-core' ), 'required' => false,
				'ok' => $has_dompdf, 'value' => $has_dompdf ? __( 'Available', 'auto-dealership-core' ) : __( 'Unavailable', 'auto-dealership-core' ),
				'detail' => __( 'Compatibility fallback renderer.', 'auto-dealership-core' ),
			),
			'renderer' => array(
				'label' => __( 'PDF renderer', 'auto-dealership-core' ), 'required' => true,
				'ok' => $has_mpdf || $has_dompdf, 'value' => $has_mpdf ? 'mPDF' : ( $has_dompdf ? 'Dompdf' : __( 'None', 'auto-dealership-core' ) ),
				'detail' => __( 'At least one server-side renderer must be installed.', 'auto-dealership-core' ),
			),
			'arabic_font' => array(
				'label' => __( 'Arabic-capable font', 'auto-dealership-core' ), 'required' => true,
				'ok' => ! empty( $available_fonts ), 'value' => $available_fonts ? implode( ', ', $available_fonts ) : __( 'Not found', 'auto-dealership-core' ),
				'detail' => __( 'A bundled DejaVu Sans font is required for Arabic and Latin glyphs.', 'auto-dealership-core' ),
			),
			'temp_dir' => array(
				'label' => __( 'PDF temporary directory', 'auto-dealership-core' ), 'required' => true,
				'ok' => $temp_ready, 'value' => $temp_ready ? __( 'Writable', 'auto-dealership-core' ) : __( 'Not writable', 'auto-dealership-core' ),
				'detail' => __( 'The PDF engine needs a writable temporary directory.', 'auto-dealership-core' ),
			),
		);
		$missing = array();
		foreach ( $checks as $key => $check ) {
			if ( ! empty( $check['required'] ) && empty( $check['ok'] ) ) {
				$missing[] = $key;
			}
		}
		return array( 'ready' => empty( $missing ), 'missing' => $missing, 'checks' => $checks );
	}

	public static function normalize_orientation( string $orientation, string $default = 'portrait' ): string {
		$orientation = strtolower( trim( $orientation ) );
		$default = in_array( strtolower( $default ), array( 'portrait', 'landscape' ), true ) ? strtolower( $default ) : 'portrait';
		return in_array( $orientation, array( 'portrait', 'landscape' ), true ) ? $orientation : $default;
	}

	public static function normalize_language( ?string $language, string $default = 'ar' ): string {
		$language = strtolower( trim( (string) $language ) );
		$default = 'en' === strtolower( trim( $default ) ) ? 'en' : 'ar';
		return in_array( $language, array( 'ar', 'en' ), true ) ? $language : $default;
	}

	/**
	 * Render UTF-8 HTML while retaining the document language and writing direction.
	 * DejaVu Sans is bundled by Dompdf and contains both Arabic and Latin glyphs.
	 */
	public static function render( string $html, string $paper = 'A4', string $orientation = 'landscape', ?string $language = null ) {
		$environment = self::environment();
		if ( empty( $environment['ready'] ) ) {
			return new \WP_Error( 'adc_pdf_environment_not_ready', __( 'توقف تصدير PDF لأن بيئة الخادم تفتقد مكوّناً مطلوباً أو أكثر. راجع صفحة صحة الإدارة لمعرفة التفاصيل.', 'auto-dealership-core' ), array( 'status' => 503, 'missing' => $environment['missing'], 'checks' => $environment['checks'] ) );
		}

		$orientation = self::normalize_orientation( $orientation, 'landscape' );
		$document = self::prepare_document( $html, $language );

		$direction = 'en' === self::language_from_html( $document ) ? 'ltr' : 'rtl';
		$last_error = null;

		// mPDF performs Arabic shaping and bidirectional layout; it is the primary renderer.
		if ( class_exists( '\\Mpdf\\Mpdf' ) && class_exists( '\\Mpdf\\Output\\Destination' ) ) {
			try {
				$temp_dir = self::temporary_directory();
				$pdf = new \Mpdf\Mpdf( array(
					'mode' => 'utf-8',
					'format' => $paper,
					'orientation' => 'landscape' === strtolower( $orientation ) ? 'L' : 'P',
					'margin_left' => self::PAGE_MARGIN_MM,
					'margin_right' => self::PAGE_MARGIN_MM,
					'margin_top' => self::PAGE_MARGIN_MM,
					'margin_bottom' => self::PAGE_MARGIN_BOTTOM_MM,
					'margin_header' => 0,
					'margin_footer' => 0,
					'tempDir' => $temp_dir,
					'default_font' => 'dejavusans',
					'autoScriptToLang' => true,
					'autoLangToFont' => true,
					'useSubstitutions' => true,
					'shrink_tables_to_fit' => 1,
					'use_kwt' => true,
				) );
				$pdf->SetDirectionality( $direction );
				$pdf->WriteHTML( $document );
				$bytes = $pdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
				if ( self::valid_pdf( $bytes ) ) {
					return $bytes;
				}
				$last_error = 'mPDF returned an invalid PDF payload.';
			} catch ( \Throwable $exception ) {
				$last_error = $exception->getMessage();
			}
		}

		// Keep Dompdf as a compatibility fallback for installations that only ship its dependency.
		if ( class_exists( '\\Dompdf\\Dompdf' ) && class_exists( '\\Dompdf\\Options' ) ) {
			try {
				$options = new \Dompdf\Options();
				$options->set( 'isHtml5ParserEnabled', true );
				$options->set( 'isRemoteEnabled', false );
				$options->set( 'isFontSubsettingEnabled', true );
				$options->set( 'defaultFont', 'DejaVu Sans' );
				if ( defined( 'ADC_PATH' ) && is_dir( ADC_PATH ) ) {
					$options->setChroot( ADC_PATH );
				}
				$pdf = new \Dompdf\Dompdf( $options );
				$pdf->loadHtml( $document, 'UTF-8' );
				$pdf->setPaper( $paper, $orientation );
				$pdf->render();
				$bytes = $pdf->output();
				if ( self::valid_pdf( $bytes ) ) {
					return $bytes;
				}
				$last_error = 'Dompdf returned an invalid PDF payload.';
			} catch ( \Throwable $exception ) {
				$last_error = $exception->getMessage();
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $last_error ) {
			error_log( 'Auto Dealership Core PDF render failed: ' . $last_error );
		}
		return new \WP_Error( 'adc_pdf_render_failed', __( 'تعذر إنشاء ملف PDF. راجع سجل النظام لمزيد من التفاصيل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
	}

	private static function valid_pdf( $bytes ): bool {
		return is_string( $bytes ) && str_starts_with( $bytes, '%PDF-' );
	}

	private static function temporary_directory(): string {
		$directory = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'auto-dealership-core-pdf';
		if ( ! is_dir( $directory ) ) {
			@mkdir( $directory, 0700, true );
		}
		return is_dir( $directory ) && is_writable( $directory ) ? $directory : sys_get_temp_dir();
	}

	private static function prepare_document( string $html, ?string $language ): string {
		$language = self::normalize_language( $language ?: self::language_from_html( $html ) );
		$direction = 'en' === $language ? 'ltr' : 'rtl';
		$document = trim( $html );

		if ( ! preg_match( "/<html\\b/i", $document ) ) {
			$document = '<!doctype html><html lang="' . $language . '" dir="' . $direction . '"><head><meta charset="UTF-8"></head><body>' . $document . '</body></html>';
		} else {
			$document = preg_replace_callback(
				"/<html\\b([^>]*)>/i",
				static function ( array $match ) use ( $language, $direction ): string {
					$attributes = preg_replace( "/\\s(?:lang|dir)\\s*=\\s*(?:\"[^\"]*\"|'[^']*'|[^\\s>]+)/i", '', $match[1] );
					return '<html' . $attributes . ' lang="' . $language . '" dir="' . $direction . '">';
				},
				$document,
				1
			);
			$document = preg_replace( "/<meta\\s+charset=[^>]*>/i", '', $document, 1 );
			$document = preg_replace( "/<head\\s*>/i", '<head><meta charset="UTF-8">', $document, 1 );
		}

		$css = '<style id="adc-pdf-direction">@page{margin:' . self::PAGE_MARGIN_MM . 'mm ' . self::PAGE_MARGIN_MM . 'mm ' . self::PAGE_MARGIN_BOTTOM_MM . 'mm;}html,body{direction:' . $direction . ';unicode-bidi:plaintext;}body{font-family:"DejaVu Sans",sans-serif;margin:0!important;padding:0!important;max-width:none!important;width:auto!important;overflow-wrap:break-word;word-wrap:break-word;}*,*::before,*::after{box-sizing:border-box;max-width:100%;overflow-wrap:break-word;word-wrap:break-word;}table{width:100%;max-width:100%;table-layout:fixed;page-break-inside:auto;}th,td{overflow-wrap:break-word;word-wrap:break-word;vertical-align:top;}img,svg{max-width:100%;height:auto;}.head{display:block!important;}.head>div{display:block!important;margin-bottom:8px;}.grid{display:block!important;}.grid>.card{display:block!important;width:100%!important;margin:0 0 12px!important;}[dir="ltr"]{direction:ltr;text-align:left;}[dir="rtl"]{direction:rtl;text-align:right;}.adc-pdf-number,.adc-pdf-code{direction:ltr;unicode-bidi:embed;white-space:nowrap;}</style>';
		if ( stripos( $document, '</head>' ) !== false ) {
			$document = preg_replace( '/<\\/head>/i', $css . '</head>', $document, 1 );
		} else {
			$document = '<!doctype html><meta charset="UTF-8">' . $css . $document;
		}

		return $document;
	}

	private static function language_from_html( string $html ): string {
		if ( preg_match( "/<html\\b[^>]*\\blang\\s*=\\s*[\"']([^\"']+)[\"']/i", $html, $match ) ) {
			return strtolower( substr( $match[1], 0, 2 ) );
		}
		if ( preg_match( "/<html\\b[^>]*\\bdir\\s*=\\s*[\"']ltr[\"']/i", $html ) ) {
			return 'en';
		}
		return self::DEFAULT_LANGUAGE;
	}

	public static function send( string $bytes, string $filename, bool $inline = false ): void {
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'document.pdf';
		}
		if ( ! str_ends_with( strtolower( $filename ), '.pdf' ) ) {
			$filename .= '.pdf';
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: sandbox' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
