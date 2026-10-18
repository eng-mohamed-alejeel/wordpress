<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Shared visual identity; no business data or user account preference is changed. */
final class BrandIdentity {
    public static function boot(): void {
        foreach ( array( 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'login_enqueue_scripts' ) as $hook ) {
            add_action( $hook, static function (): void {
                self::tokens();
                wp_enqueue_style( 'autobrands-palette', plugins_url( 'assets/css/brand-palette.css', ADC_FILE ), array( 'autobrands-ui' ), ADC_VERSION );
            }, 999 );
        }
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
        add_action( 'personal_options', static function (): void {
            echo '<tr><th scope="row">' . esc_html(Localization::language() === 'en' ? 'Appearance' : 'المظهر') . '</th><td>';
            self::preferences();
            echo '</td></tr>';
        } );
    }

    public static function tokens(): void {
        if ( wp_style_is( 'autobrands-tokens', 'enqueued' ) ) { return; }
        $file = ADC_PATH . 'assets/css/brand-tokens.css';
        wp_enqueue_style( 'autobrands-tokens', plugins_url( 'assets/css/brand-tokens.css', ADC_FILE ), array(), (string) filemtime( $file ) );
        wp_add_inline_style( 'autobrands-tokens', BrandPalette::css() );
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
        wp_add_inline_style( 'autobrands-editor', BrandPalette::css() );
    }

    public static function editor_palette( array $settings ): array {
        if ( 'car-dealer' !== get_stylesheet() ) { return $settings; }
        $colors = BrandPalette::resolve();
        $settings['colors'] = array();
        foreach ( BrandPalette::schema() as $key => $spec ) {
            $settings['colors'][] = array( 'name' => $spec['label'], 'slug' => 'autobrands-' . $key, 'color' => $colors[$key] );
        }
        // Preserve existing block palette slugs saved in page content.
        foreach ( array( 'navy'=>'brand-navy', 'blue'=>'brand-blue', 'background'=>'bg', 'white'=>'surface' ) as $slug=>$key ) {
            $settings['colors'][] = array( 'name'=>$slug, 'slug'=>'autobrands-'.$slug, 'color'=>$colors[$key] );
        }
        return $settings;
    }

    public static function preference_script(): void {
        // Runs before first paint; the same cached HTML supports every preference.
        echo '<script id="autobrands-appearance-bootstrap">(function(){var r=document.documentElement,p="system";try{var s=localStorage.getItem("autobrands.appearance");if(s==="light"||s==="dark")p=s;}catch(e){}var m=p==="system"?(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):p;r.dataset.abTheme=m;r.dataset.abPreference=p;r.style.colorScheme=m;})();</script>';
        echo '<style id="autobrands-appearance-initial">html:not([data-ab-appearance-ready]) *,html:not([data-ab-appearance-ready]) *:before,html:not([data-ab-appearance-ready]) *:after{transition:none!important}.ab-show-light{display:none}html[data-ab-theme="dark"] .ab-show-light{display:block}html[data-ab-theme="dark"] .ab-show-dark{display:none}</style>';
        $file = ADC_PATH . 'assets/js/brand-appearance.js';
        echo '<script id="autobrands-appearance" src="' . esc_url( plugins_url( 'assets/js/brand-appearance.js', ADC_FILE ) . '?ver=' . filemtime( $file ) ) . '"></script>';
    }

    public static function control(): void {
        $english = Localization::language() === 'en';
        $label = $english ? 'Switch appearance' : 'تبديل المظهر';
        echo '<div class="ab-appearance-control" data-ab-appearance-control><button type="button" class="ab-appearance-option ab-appearance-toggle" data-ab-toggle aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '">';
        echo '<svg class="ab-show-light" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>';
        echo '<svg class="ab-show-dark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg></button></div>';
    }

    public static function preferences(): void {
        $english = Localization::language() === 'en';
        echo '<section class="ab-appearance-settings" aria-label="' . esc_attr($english ? 'Appearance preferences' : 'تفضيلات المظهر') . '"><p data-ab-preference-status aria-live="polite"></p>';
        self::control();
        echo ' <button type="button" class="button ab-appearance-reset" data-ab-reset>' . esc_html($english ? 'Use device setting' : 'استخدام إعداد الجهاز') . '</button><p class="description">' . esc_html($english ? 'Saved in this browser. Device settings apply until you choose a mode.' : 'يُحفظ اختيارك في هذا المتصفح. يُتبع إعداد الجهاز حتى تختار وضعًا بنفسك.') . '</p></section>';
    }
    public static function admin_control( $bar ): void {
        if ( ! is_admin() ) { return; }
        $bar->add_node( array( 'id' => 'ab-appearance', 'title' => '<span data-ab-cycle>' . esc_html__( 'المظهر', 'auto-dealership-core' ) . '</span>', 'href' => '#ab-appearance', 'meta' => array( 'title' => 'المظهر / Appearance' ) ) );
    }

    public static function customizer_control(): void {
        echo '<div id="ab-customizer-appearance">';
        self::control();
        echo '<details class="ab-customizer-preferences"><summary>' . esc_html(Localization::language() === 'en' ? 'Appearance preferences' : 'تفضيلات المظهر') . '</summary>';
        self::preferences();
        echo '</details>';
        echo '</div>';
    }
}
