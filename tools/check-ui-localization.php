<?php
/** Check literal gettext copy and the reviewed dynamic workflow label catalog. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$roots = array( 'wp-content/plugins/auto-dealership-core'=>'auto-dealership-core', 'wp-content/themes/car-dealer'=>'car-dealer' );
$errors = array(); $count = 0;
foreach ( $roots as $folder=>$domain ) {
	$catalog = require ABSPATH . $folder . '/languages/ui.php';
	$scan = 'auto-dealership-core' === $domain ? ABSPATH . $folder . '/src' : ABSPATH . $folder;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scan, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( 'php' !== $file->getExtension() || str_contains( $file->getPathname(), DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR ) ) { continue; }
		$source = file_get_contents( $file->getPathname() );
		preg_match_all( '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*\x27((?:\\\\.|[^\x27\\\\])*)\x27\s*,\s*\x27' . preg_quote( $domain, '/' ) . '\x27/', $source, $matches );
		foreach ( $matches[1] as $literal ) {
			$text = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $literal );
			$language = preg_match( '/[\x{0600}-\x{06ff}]/u', $text ) ? 'en' : 'ar';
			if ( ! isset( $catalog[$language][$text] ) ) { $errors[] = $file->getFilename() . ': ' . $text; }
			++$count;
		}
	}
}
$catalog = require ABSPATH . 'wp-content/plugins/auto-dealership-core/languages/ui.php';
$labels = require ABSPATH . 'wp-content/plugins/auto-dealership-core/languages/labels.php';
foreach ( $labels as $key=>$label ) {
	if ( ! isset( $catalog['ar'][$label] ) ) { $errors[] = 'Workflow label ' . $key . ': ' . $label; }
}
// Navigation labels are data passed to gettext at runtime, outside literal-call scanning.
function __( $text, $domain = '' ) { global $catalog; return $catalog['en'][$text] ?? $text; }
function is_admin() { return true; }
function wp_doing_ajax() { return false; }
function get_user_locale() { return 'en_US'; }
require ABSPATH . 'wp-content/plugins/auto-dealership-core/src/Core/Localization.php';
require ABSPATH . 'wp-content/plugins/auto-dealership-core/src/Core/ProductSetup.php';
require ABSPATH . 'wp-content/plugins/auto-dealership-core/src/Admin/Navigation.php';
$navigation_count = 0;
foreach ( \AutoDealership\Admin\Navigation::groups() as $group ) {
	foreach ( array_merge( array( array( 'title'=>$group['title'] ) ), $group['items'] ) as $item ) {
		foreach ( array( $item['title'], $item['description'] ?? '' ) as $copy ) { if ( preg_match( '/\p{Arabic}/u', $copy ) ) { $errors[] = 'Navigation: ' . $copy; } }
		++$navigation_count;
	}
}
if ( $errors ) { fwrite( STDERR, implode( "\n", array_unique( $errors ) ) . "\n" ); exit( 1 ); }
echo "PASS {$count} literal gettext occurrences, " . count( $labels ) . " workflow labels and {$navigation_count} navigation titles have bilingual copy.\n";
