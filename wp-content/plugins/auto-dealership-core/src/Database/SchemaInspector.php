<?php
namespace AutoDealership\Database;

defined( 'ABSPATH' ) || exit;

/** Read-only checks against this plugin's code-owned, one-definition-per-line DDL. */
final class SchemaInspector {
	private static function type( string $type ): string {
		// MySQL 8 omits integer display widths; these do not affect storage or bounds.
		return preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower( trim( $type ) ) );
	}

	public static function verify( array $definitions ): array {
		global $wpdb;
		$issues = array();
		foreach ( $definitions as $ddl ) {
			if ( ! preg_match( '/\ACREATE TABLE ([a-zA-Z0-9_]+)\s*\(/', $ddl, $match ) ) {
				throw new \LogicException( 'Unsupported dealership schema declaration.' );
			}
			$table = $match[1];
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( ! $status || $status['Name'] !== $table ) {
				$issues[] = $table . ':missing_table';
				continue;
			}
			if ( 'innodb' !== strtolower( (string) $status['Engine'] ) ) {
				$issues[] = $table . ':requires_innodb';
			}
			$columns = array_column( $wpdb->get_results( "SHOW FULL COLUMNS FROM `$table`", ARRAY_A ) ?: array(), null, 'Field' );
			$indexes = array();
			foreach ( $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A ) ?: array() as $index ) {
				$name = $index['Key_name'];
				$indexes[ $name ]['unique'] = 0 === (int) $index['Non_unique'];
				$indexes[ $name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				$indexes[ $name ]['partial'] = ( $indexes[ $name ]['partial'] ?? false ) || null !== $index['Sub_part'];
			}
			foreach ( explode( "\n", $ddl ) as $line ) {
				$line = trim( $line, " \t\r," );
				if ( preg_match( '/\A(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:([a-zA-Z0-9_]+)\s+)?\(([^)]+)\)/', $line, $key ) ) {
					$name = 'PRIMARY KEY' === $key[1] ? 'PRIMARY' : $key[2];
					$expected = array_map( 'trim', explode( ',', $key[3] ) );
					$index = $indexes[ $name ] ?? null;
					if ( $index ) { ksort( $index['columns'] ); }
					if ( ! $index || $index['partial'] || array_values( $index['columns'] ) !== $expected || $index['unique'] !== ( 'KEY' !== $key[1] ) ) {
						$issues[] = $table . ':index:' . $name;
					}
					continue;
				}
				if ( ! preg_match( '/\A([a-z_]+)\s+((?:bigint|smallint|tinyint|int|varchar|char|datetime|date|longtext|text)(?:\(\d+\))?(?: unsigned)?)\s+(.*)\z/i', $line, $column ) ) {
					if ( '' !== $line && ! str_starts_with( $line, 'CREATE TABLE ' ) && ! str_starts_with( $line, ')' ) ) {
						$issues[] = $table . ':unsupported_definition';
					}
					continue;
				}
				$name = $column[1];
				$actual = $columns[ $name ] ?? null;
				$nullable = ! str_contains( strtoupper( $column[3] ), 'NOT NULL' );
				$auto_increment = str_contains( strtoupper( $column[3] ), 'AUTO_INCREMENT' );
				if ( ! $actual || self::type( $actual['Type'] ) !== self::type( $column[2] ) || ( 'YES' === $actual['Null'] ) !== $nullable || str_contains( $actual['Extra'], 'auto_increment' ) !== $auto_increment ) {
					$issues[] = $table . ':column:' . $name;
					continue;
				}
				if ( preg_match( "/DEFAULT\\s+(?:'([^']*)'|([0-9]+))/i", $column[3], $default ) ) {
					$expected = isset( $default[2] ) && '' !== $default[2] ? $default[2] : $default[1];
					if ( null === $actual['Default'] || (string) $actual['Default'] !== $expected ) {
						$issues[] = $table . ':default:' . $name;
					}
				}
			}
		}
		return $issues;
	}
}
