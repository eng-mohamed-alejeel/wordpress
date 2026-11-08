<?php
namespace AutoDealership\Reports;

use AutoDealership\Marketing\AffiliateCommissionService;

defined( 'ABSPATH' ) || exit;

/** Generates Excel workbooks and a non-JavaScript legacy HTML fallback. */
final class AffiliateCommissionExport {
	public static function xlsx( array $rows, array $filters ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return new \WP_Error( 'adc_affiliate_excel_unavailable', 'The PHP ZIP extension is required for Excel export.', array( 'status'=>503 ) ); }
		$path = wp_tempnam( 'affiliate-commission-' . wp_generate_uuid4() . '.xlsx' );
		if ( ! $path ) { return new \WP_Error( 'adc_affiliate_excel_temp', 'Unable to create a temporary Excel file.', array( 'status'=>500 ) ); }
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) { @unlink( $path ); return new \WP_Error( 'adc_affiliate_excel_zip', 'Unable to build the Excel workbook.', array( 'status'=>500 ) ); }
		$zip->addFromString( '[Content_Types].xml', self::content_types() );
		$zip->addFromString( '_rels/.rels', self::rels() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels() );
		$zip->addFromString( 'xl/styles.xml', self::styles() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet( $rows, $filters ) );
		$zip->addFromString( 'docProps/core.xml', self::core_properties( $filters ) );
		$zip->addFromString( 'docProps/app.xml', self::app_properties() );
		$zip->close();
		$data = file_get_contents( $path ); @unlink( $path );
		return false === $data ? new \WP_Error( 'adc_affiliate_excel_read', 'Unable to read the Excel workbook.', array( 'status'=>500 ) ) : $data;
	}

	/** Kept for bookmarked integrations; it never invokes browser printing. */
	public static function print_html( array $rows, array $filters ): string {
		return self::pdf_html( $rows, $filters );
	}

	public static function pdf( array $rows, array $filters, string $orientation = 'landscape', ?string $language = null ) {
		$language = ServerPdfRenderer::normalize_language( $language ?: ( class_exists( '\\AutoDealership\\Core\\Localization' ) ? \AutoDealership\Core\Localization::language() : 'ar' ) );
		return ServerPdfRenderer::render( self::pdf_html( $rows, $filters, $language ), 'A4', ServerPdfRenderer::normalize_orientation( $orientation, 'landscape' ), $language );
	}

	private static function pdf_html( array $rows, array $filters, string $language = 'ar' ): string {
		$language = ServerPdfRenderer::normalize_language( $language );
		$direction = 'en' === $language ? 'ltr' : 'rtl';
		$align = 'en' === $language ? 'left' : 'right';
		$labels = 'en' === $language ? array(
			'title' => 'Affiliate Commission Report', 'period' => 'Period', 'to' => 'to', 'timezone' => 'Timezone', 'currency' => 'Currency',
			'group' => 'Category', 'period_column' => 'Period', 'count' => 'Commission count', 'gross' => 'Gross', 'reversals' => 'Reversals', 'net' => 'Net ledger', 'paid' => 'Paid',
			'empty' => 'No results were found for the selected period.', 'footer' => 'Generated from the financial commission ledger. Negative values are retained as reversal movements.',
		) : array(
			'title' => 'تقرير عمولات المسوقين', 'period' => 'الفترة', 'to' => 'إلى', 'timezone' => 'المنطقة الزمنية', 'currency' => 'العملة',
			'group' => 'التصنيف', 'period_column' => 'الفترة', 'count' => 'عدد العمولات', 'gross' => 'الإجمالي', 'reversals' => 'العكوسات', 'net' => 'صافي السجل', 'paid' => 'المدفوع',
			'empty' => 'لا توجد نتائج ضمن الفترة المحددة.', 'footer' => 'تم إنشاء التقرير من سجل العمولات المالي. الأرقام السالبة محفوظة كحركات عكسية.',
		);
		$money = static fn( $value ): string => '<span class="adc-pdf-number" dir="ltr">' . esc_html( number_format( (int) $value / 100, 2, '.', ',' ) . ' SAR' ) . '</span>';
		$from = esc_html( (string) ( $filters['from'] ?? '' ) );
		$to = esc_html( (string) ( $filters['to'] ?? '' ) );
		$timezone = esc_html( (string) ( $filters['timezone'] ?? '' ) );
		$html = '<!doctype html><html lang="' . $language . '" dir="' . $direction . '"><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans",sans-serif;color:#24364b;font-size:10px;margin:24px;direction:' . $direction . '}h1{font-size:20px;margin:0 0 5px;color:#17324d}.meta{color:#64748b;margin:0 0 18px}table{border-collapse:collapse;width:100%;margin-top:12px;direction:' . $direction . '}th,td{border:1px solid #cbd5e1;padding:6px;text-align:' . $align . ';vertical-align:middle}th{background:#e7eef5;color:#17324d}tr:nth-child(even){background:#f8fafc}.adc-pdf-number{direction:ltr;unicode-bidi:embed;white-space:nowrap}.footer{margin-top:18px;color:#64748b;font-size:9px}</style></head><body><h1>' . esc_html( $labels['title'] ) . '</h1><p class="meta"><span>' . esc_html( $labels['period'] ) . ': ' . $from . ' ' . esc_html( $labels['to'] ) . ' ' . $to . '</span> · <span>' . esc_html( $labels['timezone'] ) . ': <span class="adc-pdf-code" dir="ltr">' . $timezone . '</span></span> · <span>' . esc_html( $labels['currency'] ) . ': <span class="adc-pdf-code" dir="ltr">SAR</span></span></p><table><thead><tr><th>' . esc_html( $labels['group'] ) . '</th><th>' . esc_html( $labels['period_column'] ) . '</th><th>' . esc_html( $labels['count'] ) . '</th><th>' . esc_html( $labels['gross'] ) . '</th><th>' . esc_html( $labels['reversals'] ) . '</th><th>' . esc_html( $labels['net'] ) . '</th><th>' . esc_html( $labels['paid'] ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr><td dir="auto">' . esc_html( (string) ( $row['group_label'] ?? '' ) ) . '</td><td dir="ltr">' . esc_html( (string) ( $row['period'] ?? '' ) ) . '</td><td class="adc-pdf-number" dir="ltr">' . absint( $row['commission_count'] ?? 0 ) . '</td><td>' . $money( $row['gross'] ?? 0 ) . '</td><td>' . $money( $row['reversals'] ?? 0 ) . '</td><td>' . $money( $row['net_ledger'] ?? 0 ) . '</td><td>' . $money( $row['paid'] ?? 0 ) . '</td></tr>';
		}
		if ( ! $rows ) { $html .= '<tr><td colspan="7">' . esc_html( $labels['empty'] ) . '</td></tr>'; }
		return $html . '</tbody></table><p class="footer">' . esc_html( $labels['footer'] ) . '</p></body></html>';
	}

	private static function sheet( array $rows, array $filters ): string {
		$headers = array( 'التصنيف','المعرّف','الوصف','الفترة','عدد العمولات','الإجمالي SAR','العكوسات SAR','صافي السجل SAR','قيد المراجعة SAR','معتمد SAR','مدفوع SAR','ملغى SAR','مرتجع SAR' );
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$line = 1; $xml .= '<row r="' . $line . '">' . self::cells( array( 'من','إلى','المنطقة الزمنية','العملة' ), array( false,false,false,false ) ) . '</row>';
		++$line; $xml .= '<row r="' . $line . '">' . self::cells( array( $filters['from'], $filters['to'], $filters['timezone'], AffiliateCommissionService::CURRENCY ), array( false,false,false,false ) ) . '</row>';
		++$line; $xml .= '<row r="' . $line . '">' . self::cells( $headers, array_fill( 0, count( $headers ), false ) ) . '</row>'; ++$line;
		foreach ( $rows as $row ) {
			$values = array( $row['group_type'], $row['group_id'], $row['group_label'], $row['period'], $row['commission_count'], number_format( (int) $row['gross'] / 100, 2, '.', '' ), number_format( (int) $row['reversals'] / 100, 2, '.', '' ), number_format( (int) $row['net_ledger'] / 100, 2, '.', '' ), number_format( (int) $row['pending_review'] / 100, 2, '.', '' ), number_format( (int) $row['approved'] / 100, 2, '.', '' ), number_format( (int) $row['paid'] / 100, 2, '.', '' ), number_format( (int) $row['voided'] / 100, 2, '.', '' ), number_format( (int) $row['returned'] / 100, 2, '.', '' ) );
			$numeric = array( false,true,false,false,true,true,true,true,true,true,true,true,true ); $xml .= '<row r="' . $line . '">' . self::cells( $values, $numeric ) . '</row>'; ++$line;
		}
		return $xml . '</sheetData><autoFilter ref="A3:M' . max( 3, $line - 1 ) . '"/><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews></worksheet>';
	}

	private static function cells( array $values, array $numeric ): string { $xml = ''; foreach ( $values as $index => $value ) { $ref = self::column( $index + 1 ); $text = (string) $value; if ( ! empty( $numeric[ $index ] ) && is_numeric( $text ) ) { $xml .= '<c r="' . $ref . '" t="n"><v>' . esc_attr( $text ) . '</v></c>'; } else { $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . esc_html( $text ) . '</t></is></c>'; } } return $xml; }
	private static function column( int $number ): string { $column = ''; while ( $number > 0 ) { $remainder = ( $number - 1 ) % 26; $column = chr( 65 + $remainder ) . $column; $number = (int) floor( ( $number - 1 ) / 26 ); } return $column; }
	private static function content_types(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>'; }
	private static function rels(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/package/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
	private static function workbook(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Affiliate commissions" sheetId="1" r:id="rId1"/></sheets></workbook>'; }
	private static function workbook_rels(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'; }
	private static function styles(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs></styleSheet>'; }
	private static function core_properties( array $filters ): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Affiliate commissions ' . esc_html( $filters['from'] . ' - ' . $filters['to'] ) . '</dc:title><dc:creator>Auto Dealership Core</dc:creator></cp:coreProperties>'; }
	private static function app_properties(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Auto Dealership Core</Application></Properties>'; }
}
