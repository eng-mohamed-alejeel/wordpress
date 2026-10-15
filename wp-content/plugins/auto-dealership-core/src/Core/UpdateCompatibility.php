<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Validates extracted product packages before WordPress replaces installed files. */
final class UpdateCompatibility {
	public const CONTRACT = 1;
	private const PLUGIN = 'auto-dealership-core/auto-dealership-core.php';

	public static function boot(): void {
		add_filter( 'upgrader_source_selection', array( self::class, 'source_selection' ), 20, 4 );
	}

	public static function source_selection( $source, $remote_source = '', $upgrader = null, $extra = array() ) {
		if ( is_wp_error( $source ) || ! is_string( $source ) ) { return $source; }
		$extra = is_array( $extra ) ? $extra : array();
		$kind = ( $extra['plugin'] ?? '' ) === self::PLUGIN ? 'plugin' : ( ( $extra['theme'] ?? '' ) === 'car-dealer' ? 'theme' : '' );
		if ( ! $kind ) {
			if ( is_file( $source . '/auto-dealership-core.php' ) ) { $kind = 'plugin'; }
			elseif ( is_file( $source . '/style.css' ) ) {
				$header = get_file_data( $source . '/style.css', array( 'domain'=>'Text Domain' ) );
				if ( 'car-dealer' === $header['domain'] ) { $kind = 'theme'; }
			}
		}
		if ( ! $kind ) { return $source; }
		$result = self::validate_directory( $source, $kind );
		return is_wp_error( $result ) ? $result : $source;
	}

	public static function metadata( string $directory, string $kind ) {
		$file = $directory . ( 'plugin' === $kind ? '/resources/release.json' : '/inc/release.json' );
		if ( ! is_file( $file ) || filesize( $file ) > 16384 ) { return self::error( 'manifest', 'حزمة المنتج لا تحتوي بيان توافق صالحًا. استخدم حزمة إصدار معتمدة.', 'The product package needs a valid compatibility manifest. Use a reviewed release package.' ); }
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) || 1 !== ( $data['format'] ?? 0 ) || 'auto-dealership' !== ( $data['product'] ?? '' ) || $kind !== ( $data['component'] ?? '' ) || self::CONTRACT !== ( $data['contract'] ?? 0 ) ) { return self::error( 'contract', 'إصدار واجهة التكامل في الحزمة غير مدعوم. يلزم مسار ترقية متوافق.', 'The package integration contract is unsupported. A compatible upgrade path is required.' ); }
		foreach ( array( 'version', 'requires_php', 'requires_wp', 'partner_min', 'partner_before' ) as $key ) {
			if ( ! isset( $data[$key] ) || ! is_string( $data[$key] ) || ! preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/', $data[$key] ) ) { return self::error( 'manifest', 'بيان توافق الحزمة غير صالح.', 'Invalid package compatibility manifest.' ); }
		}
		if ( version_compare( $data['partner_min'], $data['partner_before'], '>=' ) ) { return self::error( 'manifest', 'نطاق توافق الحزمة غير صالح.', 'Invalid package compatibility range.' ); }
		if ( 'plugin' === $kind ) {
			foreach ( array( 'schema_min', 'schema_max', 'schema_target' ) as $key ) { if ( ! is_string( $data[$key] ?? null ) || ! preg_match( '/\A\d+\.\d+\.\d+\z/', $data[$key] ) ) { return self::error( 'manifest', 'بيان توافق قاعدة البيانات غير صالح.', 'Invalid database compatibility manifest.' ); } }
			if ( version_compare( $data['schema_min'], $data['schema_max'], '>' ) || version_compare( $data['schema_target'], $data['schema_max'], '>' ) || version_compare( $data['schema_target'], $data['schema_min'], '<' ) ) { return self::error( 'manifest', 'نطاق قاعدة البيانات غير صالح.', 'Invalid database compatibility range.' ); }
		}
		return $data;
	}

	public static function validate_directory( string $directory, string $kind ) {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) { return self::error( 'component', 'نوع الحزمة غير صالح.', 'Invalid package component.' ); }
		$data = self::metadata( $directory, $kind ); if ( is_wp_error( $data ) ) { return $data; }
		$header_file = $directory . ( 'plugin' === $kind ? '/auto-dealership-core.php' : '/style.css' );
		if ( ! is_file( $header_file ) ) { return self::error( 'header', 'ملفات الحزمة غير مكتملة.', 'Package files are incomplete.' ); }
		$header = get_file_data( $header_file, array( 'version'=>'Version', 'php'=>'Requires PHP', 'wp'=>'Requires at least', 'domain'=>'Text Domain' ) );
		if ( $header['version'] !== $data['version'] || ( 'plugin' === $kind ? 'auto-dealership-core' : 'car-dealer' ) !== $header['domain'] || version_compare( $data['requires_php'], $header['php'] ?: '0', '<' ) || version_compare( $data['requires_wp'], $header['wp'] ?: '0', '<' ) ) { return self::error( 'header', 'بيانات الإصدار لا تطابق ملفات الحزمة.', 'Release metadata does not match package headers.' ); }
		global $wp_version;
		if ( version_compare( PHP_VERSION, $data['requires_php'], '<' ) || version_compare( $wp_version, $data['requires_wp'], '<' ) ) { return self::error( 'environment', 'إصدار PHP أو ووردبريس لا يحقق متطلبات الحزمة.', 'PHP or WordPress does not meet package requirements.' ); }
		$installed = self::installed( $kind );
		if ( $installed && version_compare( $data['version'], $installed['version'], '<' ) ) { return self::error( 'downgrade', 'الرجوع لإصدار أقدم يحتاج استعادة نسخة متزامنة من الملفات وقاعدة البيانات، وليس استبدال الملفات وحدها.', 'Downgrading requires restoring a matching files-and-database backup, not replacing code alone.' ); }
		$partner_kind = 'plugin' === $kind ? 'theme' : 'plugin'; $partner = self::installed( $partner_kind );
		if ( ! $partner && 'theme' === $kind ) { return self::error( 'partner', 'ثبّت الإضافة المتوافقة قبل تثبيت الثيم.', 'Install the compatible core plugin before the theme.' ); }
		if ( $partner ) {
			if ( ! self::within( $partner['version'], $data ) ) { return self::error( 'partner', 'الحزمة غير متوافقة مع المكوّن المثبت. استخدم إصدارًا وسيطًا متوافقًا للترقية.', 'The package is incompatible with the installed partner. Use a compatible bridge release.' ); }
			$other = self::metadata( $partner['directory'], $partner_kind );
			// Only known pre-guard releases have a supported legacy contract.
			if ( is_wp_error( $other ) ) {
				if ( is_file( $partner['directory'] . ( 'plugin' === $partner_kind ? '/resources/release.json' : '/inc/release.json' ) ) || ! in_array( $partner['version'], array( '1.39.1', '1.40.0' ), true ) ) { return self::error( 'partner', 'تعذر إثبات توافق المكوّن المثبت.', 'The installed partner compatibility cannot be established.' ); }
			} elseif ( $other['version'] !== $partner['version'] || ! self::within( $data['version'], $other ) ) { return self::error( 'partner', 'المكوّن المثبت لا يدعم الإصدار الجديد أو بيان إصداره غير مطابق.', 'The installed partner does not support the new version or its release metadata does not match.' ); }
		}
		if ( 'plugin' === $kind ) {
			$schema = (string) get_option( 'adc_db_version', '' );
			if ( $schema && ( version_compare( $schema, $data['schema_min'], '<' ) || version_compare( $schema, $data['schema_max'], '>' ) || version_compare( $data['schema_target'], $schema, '<' ) ) ) { return self::error( 'schema', 'مخطط قاعدة البيانات خارج نطاق الترقية المدعوم. لا تُعدّل الملفات قبل استعادة أو ترقية مدروسة.', 'The database schema is outside the supported upgrade range. Do not replace code before a reviewed restore or migration.' ); }
		}
		return true;
	}
	private static function within( string $version, array $manifest ): bool { return version_compare( $version, $manifest['partner_min'], '>=' ) && version_compare( $version, $manifest['partner_before'], '<' ); }
	private static function installed( string $kind ): ?array {
		$dir = 'plugin' === $kind ? WP_PLUGIN_DIR . '/auto-dealership-core' : get_theme_root( 'car-dealer' ) . '/car-dealer';
		$file = $dir . ( 'plugin' === $kind ? '/auto-dealership-core.php' : '/style.css' );
		if ( ! is_file( $file ) ) { return null; }
		$header = get_file_data( $file, array( 'version'=>'Version' ) );
		return array( 'directory'=>$dir, 'version'=>$header['version'] );
	}
	private static function error( string $code, string $ar, string $en ): \WP_Error { return new \WP_Error( 'adc_update_' . $code, ProductSetup::t( $ar, $en ) ); }
}
