<?php
namespace AutoDealership\Reports;

use AutoDealership\Marketing\AffiliateCommissionService;

defined( 'ABSPATH' ) || exit;

/** Generates a lightweight Excel workbook for downloadable report data. */
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

	private static function sheet( array $rows, array $filters ): string {
		$currency = \AutoDealership\Core\CurrencySettings::base_code();
		$headers = array( 'التصنيف','المعرّف','الوصف','الفترة','عدد العمولات','الإجمالي ' . $currency,'العكوسات ' . $currency,'صافي السجل ' . $currency,'قيد المراجعة ' . $currency,'معتمد ' . $currency,'مدفوع ' . $currency,'ملغى ' . $currency,'مرتجع ' . $currency );
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$line = 1; $xml .= '<row r="' . $line . '">' . self::cells( array( 'من','إلى','المنطقة الزمنية','العملة' ), array( false,false,false,false ) ) . '</row>';
		++$line; $xml .= '<row r="' . $line . '">' . self::cells( array( $filters['from'], $filters['to'], $filters['timezone'], \AutoDealership\Core\CurrencySettings::base_code() ), array( false,false,false,false ) ) . '</row>';
		++$line; $xml .= '<row r="' . $line . '">' . self::cells( $headers, array_fill( 0, count( $headers ), false ) ) . '</row>'; ++$line;
		foreach ( $rows as $row ) {
			$values = array( $row['group_type'], $row['group_id'], $row['group_label'], $row['period'], $row['commission_count'], \AutoDealership\Pricing\Money::decimal( (int) $row['gross'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['reversals'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['net_ledger'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['pending_review'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['approved'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['paid'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['voided'] ), \AutoDealership\Pricing\Money::decimal( (int) $row['returned'] ) );
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
