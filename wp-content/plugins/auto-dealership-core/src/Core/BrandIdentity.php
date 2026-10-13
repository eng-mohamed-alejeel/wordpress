<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Shared visual identity; no business data or user account preference is changed. */
final class BrandIdentity {
    public static function boot(): void {
        foreach ( array( 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'login_enqueue_scripts', 'customize_controls_enqueue_scripts' ) as $hook ) {
            add_action( $hook, array( self::class, 'enqueue' ), 100 );
        }
        add_action( 'enqueue_block_assets', array( self::class, 'editor_assets' ) );
        add_filter( 'block_editor_settings_all', array( self::class, 'editor_palette' ) );
        foreach ( array( 'wp_head', 'admin_head', 'login_head', 'customize_controls_print_scripts' ) as $hook ) {
            add_action( $hook, array( self::class, 'preference_script' ), 1 );
        }
        add_action( 'admin_bar_menu', array( self::class, 'admin_control' ), 90 );
        add_action( 'login_footer', array( self::class, 'control' ) );
        add_action( 'login_header', static function (): void { echo '<main id="ab-login-main">'; } );
        add_action( 'login_footer', static function (): void { echo '</main>'; }, 99 );
        add_action( 'customize_controls_print_footer_scripts', array( self::class, 'customizer_control' ), 5 );
    }

    public static function tokens(): void {
        if ( wp_style_is( 'autobrands-tokens', 'enqueued' ) ) { return; }
        $file = ADC_PATH . 'assets/css/brand-tokens.css';
        wp_enqueue_style( 'autobrands-tokens', plugins_url( 'assets/css/brand-tokens.css', ADC_FILE ), array(), (string) filemtime( $file ) );
        $options = get_option( 'car_dealer_theme_settings', array() );
        $navy = sanitize_hex_color( $options['primary_color'] ?? '' ) ?: '#0E2A4A';
        $blue = sanitize_hex_color( $options['accent_color'] ?? '' ) ?: '#2563EB';
        wp_add_inline_style( 'autobrands-tokens', ':root{--ab-brand-navy:' . $navy . ';--ab-brand-blue:' . $blue . ';}' );
    }

    public static function enqueue(): void {
        self::tokens();
        $fields = ADC_PATH . 'assets/css/brand-fields.css';
        wp_enqueue_style( 'autobrands-fields', plugins_url( 'assets/css/brand-fields.css', ADC_FILE ), array( 'autobrands-tokens' ), (string) filemtime( $fields ) );
        $file = ADC_PATH . 'assets/css/brand-ui.css';
        wp_enqueue_style( 'autobrands-ui', plugins_url( 'assets/css/brand-ui.css', ADC_FILE ), array( 'autobrands-fields' ), (string) filemtime( $file ) );
        $field_script = ADC_PATH . 'assets/js/brand-fields.js';
        wp_enqueue_script( 'autobrands-fields', plugins_url( 'assets/js/brand-fields.js', ADC_FILE ), array(), (string) filemtime( $field_script ), true );
        if ( is_admin() ) {
            $file = ADC_PATH . 'assets/css/brand-wordpress.css';
            wp_enqueue_style( 'autobrands-wordpress', plugins_url( 'assets/css/brand-wordpress.css', ADC_FILE ), array( 'autobrands-ui' ), (string) filemtime( $file ) );
            $script = ADC_PATH . 'assets/js/brand-wordpress.js';
            wp_enqueue_script( 'autobrands-wordpress', plugins_url( 'assets/js/brand-wordpress.js', ADC_FILE ), array(), (string) filemtime( $script ), true );
        }
    }

    public static function editor_assets(): void {
        self::tokens();
        $file = ADC_PATH . 'assets/css/brand-editor.css';
        wp_enqueue_style( 'autobrands-editor', plugins_url( 'assets/css/brand-editor.css', ADC_FILE ), array( 'autobrands-tokens' ), (string) filemtime( $file ) );
    }

    public static function editor_palette( array $settings ): array {
        if ( 'car-dealer' !== get_stylesheet() ) { return $settings; }
        $options = get_option( 'car_dealer_theme_settings', array() );
        $settings['colors'] = array(
            array( 'name' => 'AUTO BRANDS Navy', 'slug' => 'autobrands-navy', 'color' => sanitize_hex_color( $options['primary_color'] ?? '' ) ?: '#0E2A4A' ),
            array( 'name' => 'AUTO BRANDS Blue', 'slug' => 'autobrands-blue', 'color' => sanitize_hex_color( $options['accent_color'] ?? '' ) ?: '#2563EB' ),
            array( 'name' => 'Charcoal', 'slug' => 'autobrands-text', 'color' => '#1A1D20' ),
            array( 'name' => 'Off white', 'slug' => 'autobrands-background', 'color' => '#F8FAFC' ),
            array( 'name' => 'White', 'slug' => 'autobrands-white', 'color' => '#FFFFFF' ),
        );
        return $settings;
    }

    public static function preference_script(): void {
        // Runs before first paint; the same cached HTML supports every preference.
        $file = ADC_PATH . 'assets/js/brand-appearance.js';
        echo '<script id="autobrands-appearance" src="' . esc_url( plugins_url( 'assets/js/brand-appearance.js', ADC_FILE ) . '?ver=' . filemtime( $file ) ) . '"></script>';
    }

    public static function control(): void {
        $english = ( isset( $_GET['lang'] ) && 'en' === $_GET['lang'] ) || str_starts_with( determine_locale(), 'en' );
        if ( function_exists( 'car_dealer_catalog_language' ) && ! is_admin() ) { $english = 'en' === car_dealer_catalog_language(); }
        echo '<label class="ab-appearance-control"><span>' . esc_html( $english ? 'Appearance' : 'المظهر' ) . '</span><select data-ab-appearance aria-label="' . esc_attr( $english ? 'Appearance' : 'المظهر' ) . '">';
        foreach ( array( 'system' => $english ? 'System' : 'الجهاز', 'light' => $english ? 'Light' : 'فاتح', 'dark' => $english ? 'Dark' : 'داكن' ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public static function admin_control( $bar ): void {
        if ( ! is_admin() ) { return; }
        $bar->add_node( array( 'id' => 'ab-appearance', 'title' => '<span data-ab-cycle>' . esc_html__( 'المظهر', 'auto-dealership-core' ) . '</span>', 'href' => '#ab-appearance', 'meta' => array( 'title' => 'المظهر / Appearance' ) ) );
    }

    public static function customizer_control(): void {
        echo '<div id="ab-customizer-appearance">';
        self::control();
        echo '</div>';
    }
}
