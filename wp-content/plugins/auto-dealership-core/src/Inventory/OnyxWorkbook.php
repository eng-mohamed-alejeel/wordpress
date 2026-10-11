<?php
namespace AutoDealership\Inventory;
defined( 'ABSPATH' ) || exit;

/** Bounded XLSX reader: no extraction, external entities or formula execution. */
final class OnyxWorkbook {
	private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private static function xml( string $value ): \SimpleXMLElement {
		if ( stripos( $value, '<!DOCTYPE' ) !== false || stripos( $value, '<!ENTITY' ) !== false ) { throw new \RuntimeException( 'Unsupported XML declarations.' ); }
		$previous = libxml_use_internal_errors( true );
		try { $xml = simplexml_load_string( $value, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT ); if ( ! $xml ) { throw new \RuntimeException( 'Invalid workbook XML.' ); } return $xml; }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
	}
	private static function text( \SimpleXMLElement $node ): string { $node->registerXPathNamespace( 'm', self::NS ); return implode( '', array_map( 'strval', $node->xpath( './m:t|./m:r/m:t' ) ?: array() ) ); }
	public static function read( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) || ! is_file( $path ) || filesize( $path ) > 8 * 1024 * 1024 ) { throw new \RuntimeException( 'XLSX unavailable or file exceeds 8 MB.' ); }
		$zip = new \ZipArchive(); if ( true !== $zip->open( $path ) ) { throw new \RuntimeException( 'Invalid XLSX archive.' ); }
		try {
			$total = 0; $sheets = array();
			if ( $zip->numFiles > 1000 ) { throw new \RuntimeException( 'Workbook has too many entries.' ); }
			for ( $i=0; $i<$zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i ); $total += (int) $stat['size'];
				if ( $stat['size'] > 16 * 1024 * 1024 || $total > 48 * 1024 * 1024 ) { throw new \RuntimeException( 'Workbook expansion limit exceeded.' ); }
				if ( preg_match( '/\Axl\/worksheets\/sheet[0-9]+\.xml\z/', $stat['name'] ) ) { $sheets[] = $stat['name']; }
			}
			if ( ! $sheets ) { throw new \RuntimeException( 'Workbook has no supported worksheet.' ); }
			natsort( $sheets ); $strings = array(); $shared = $zip->getFromName( 'xl/sharedStrings.xml' );
			if ( false !== $shared ) { $xml=self::xml( $shared ); foreach ( $xml->children( self::NS )->si as $item ) { $strings[]=self::text( $item ); if ( count( $strings ) > 100000 ) { throw new \RuntimeException( 'Shared string limit exceeded.' ); } } }
			$rows = array(); $xml = self::xml( $zip->getFromName( reset( $sheets ) ) );
			foreach ( $xml->children( self::NS )->sheetData->row as $row ) {
				$values=array(); foreach ( $row->c as $cell ) {
					$attributes=$cell->attributes(); $ref=(string)$attributes['r']; if ( ! preg_match( '/\A([A-Z]{1,3})[0-9]+\z/', $ref, $match ) ) { continue; }
					$type=(string)$attributes['t']; $value=(string)$cell->v;
					if ( 's' === $type ) { if ( ! ctype_digit( $value ) || ! array_key_exists( (int)$value, $strings ) ) { throw new \RuntimeException( 'Invalid shared string reference.' ); } $value=$strings[(int)$value]; }
					if ( 'inlineStr' === $type ) { $value=self::text( $cell->is ); }
					if ( isset( $cell->f ) ) { $values['_formulas'][]=$match[1]; }
					$values[$match[1]]=trim( $value );
				}
				if ( $values ) { $rows[(int)$row->attributes()['r']]=$values; } if ( count( $rows ) > 15000 ) { throw new \RuntimeException( 'Worksheet row limit exceeded.' ); }
			}
			return $rows;
		} finally { $zip->close(); }
	}
}
