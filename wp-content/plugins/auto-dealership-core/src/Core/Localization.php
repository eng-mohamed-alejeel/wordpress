<?php
namespace AutoDealership\Core;

use AutoDealership\Inventory\CatalogPresentation;

defined( 'ABSPATH' ) || exit;

/** Built-in UI translations; stored business keys and customer content stay intact. */
final class Localization {
	private static ?array $catalog = null;

	public static function boot(): void {
		add_filter( 'gettext_auto-dealership-core', array( self::class, 'gettext' ), 20, 3 );
		add_filter( 'gettext_with_context_auto-dealership-core', array( self::class, 'gettext_context' ), 20, 4 );
	}

	public static function language(): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return str_starts_with( get_user_locale(), 'en' ) ? 'en' : 'ar';
		}
		return CatalogPresentation::language();
	}

	public static function gettext( string $translation, string $text, string $domain ): string {
		if ( null === self::$catalog ) {
			self::$catalog = require __DIR__ . '/../../languages/ui.php';
		}
		static $labels = null;
		if ( null === $labels ) {
			$labels = require __DIR__ . '/../../languages/labels.php';
		}
		$display = $labels[$text] ?? $text;
		return self::$catalog[ self::language() ][ $display ] ?? ( $display !== $text ? $display : $translation );
	}

	public static function gettext_context( string $translation, string $text, string $context, string $domain ): string {
		return self::gettext( $translation, $text, $domain );
	}

	/** Human-readable display for an internal status, action or reference type. */
	public static function label( string $value ): string {
		static $labels = null;
		if ( null === $labels ) {
			$labels = require __DIR__ . '/../../languages/labels.php';
		}
		return isset( $labels[$value] ) ? __( $labels[$value], 'auto-dealership-core' ) : __( $value, 'auto-dealership-core' );
	}
}
