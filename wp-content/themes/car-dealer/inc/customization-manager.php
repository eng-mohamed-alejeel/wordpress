<?php
/** Theme settings and controlled dynamic styles migrated from legacy modules. */
defined( 'ABSPATH' ) || exit;

/** Keep logo selection usable on hosts without an image editor. */
function car_dealer_customize_logo_control( $manager ) {
	if ( wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) ) { return; }
	$control = $manager->get_control( 'custom_logo' );
	if ( ! $control instanceof WP_Customize_Cropped_Image_Control ) { return; }
	$manager->remove_control( 'custom_logo' );
	$manager->add_control( new WP_Customize_Media_Control( $manager, 'custom_logo', array(
		'label' => $control->label,
		'section' => $control->section,
		'settings' => 'custom_logo',
		'priority' => $control->priority,
		'mime_type' => 'image',
		'button_labels' => $control->button_labels,
		'description' => __( 'اختر شعارًا جاهزًا من مكتبة الوسائط؛ ستُستخدم الصورة الأصلية دون قص.', 'car-dealer' ),
	) ) );
}
add_action( 'customize_register', 'car_dealer_customize_logo_control', 100 );

/** Both administration screens edit the same option; refresh previews all outputs. */
function car_dealer_customize_theme_settings( $manager ) {
	$manager->add_section( 'car_dealer_appearance', array( 'title' => __( 'مظهر المعرض', 'car-dealer' ), 'priority' => 35 ) );
	$manager->add_section( 'car_dealer_contact', array( 'title' => __( 'بيانات التواصل', 'car-dealer' ), 'priority' => 36 ) );
	$options = car_dealer_theme_options();
	$manager->add_setting( 'car_dealer_theme_settings[description_en]', array( 'type' => 'option', 'default' => $options['description_en'], 'capability' => 'manage_options', 'sanitize_callback' => 'sanitize_textarea_field', 'transport' => 'refresh' ) );
	$manager->add_control( 'car_dealer_theme_settings[description_en]', array( 'label' => 'وصف الموقع بالإنجليزية', 'section' => 'title_tagline', 'type' => 'textarea' ) );
	$fields = array(
		'primary_color' => array( 'اللون الرئيسي', 'color', 'sanitize_hex_color' ),
		'accent_color' => array( 'لون الأزرار', 'color', 'sanitize_hex_color' ),
		'phone' => array( 'الهاتف', 'text', 'sanitize_text_field' ),
		'email' => array( 'البريد', 'email', 'sanitize_email' ),
		'address' => array( 'العنوان', 'textarea', 'sanitize_textarea_field' ),
		'facebook' => array( 'فيسبوك', 'url', 'esc_url_raw' ),
		'instagram' => array( 'إنستغرام', 'url', 'esc_url_raw' ),
		'whatsapp' => array( 'واتساب', 'text', 'sanitize_text_field' ),
	);
	foreach ( $fields as $key => $field ) {
		$id = 'car_dealer_theme_settings[' . $key . ']';
		$manager->add_setting( $id, array( 'type' => 'option', 'default' => $options[$key], 'capability' => 'manage_options', 'sanitize_callback' => $field[2], 'transport' => 'color' === $field[1] ? 'postMessage' : 'refresh' ) );
		$args = array( 'label' => __( $field[0], 'car-dealer' ), 'section' => 'color' === $field[1] ? 'car_dealer_appearance' : 'car_dealer_contact', 'type' => $field[1] );
		if ( 'color' === $field[1] ) { $manager->add_control( new WP_Customize_Color_Control( $manager, $id, $args ) ); }
		else { $manager->add_control( $id, $args ); }
	}
}
add_action( 'customize_register', 'car_dealer_customize_theme_settings' );

function car_dealer_enqueue_customize_preview() {
	$file = get_template_directory() . '/assets/js/customize-preview.js';
	wp_enqueue_script( 'car-dealer-customize-preview', get_template_directory_uri() . '/assets/js/customize-preview.js', array( 'customize-preview' ), (string) filemtime( $file ), true );
}
add_action( 'customize_preview_init', 'car_dealer_enqueue_customize_preview' );

/** No widget editor is needed when neither theme nor plugins register sidebars. */
function car_dealer_customize_skip_unused_widget_editor() {
	global $wp_customize, $wp_registered_sidebars;
	if ( empty( $wp_registered_sidebars ) && $wp_customize && isset( $wp_customize->widgets ) ) {
		remove_action( 'customize_controls_enqueue_scripts', array( $wp_customize->widgets, 'enqueue_scripts' ) );
	}
}
add_action( 'customize_controls_enqueue_scripts', 'car_dealer_customize_skip_unused_widget_editor', 0 );

function car_dealer_register_theme_settings() {
	register_setting( 'car_dealer_theme_settings', 'car_dealer_theme_settings', array(
		'type' => 'array',
		'sanitize_callback' => 'car_dealer_sanitize_theme_settings',
		'default' => array(),
	) );
}
add_action( 'admin_init', 'car_dealer_register_theme_settings' );

function car_dealer_sanitize_theme_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	return array(
		'description_en' => sanitize_textarea_field( $input['description_en'] ?? car_dealer_theme_options()['description_en'] ),
		'primary_color' => sanitize_hex_color( $input['primary_color'] ?? '' ) ?: '#102a43',
		'accent_color' => sanitize_hex_color( $input['accent_color'] ?? '' ) ?: '#1677c8',
		'phone' => sanitize_text_field( $input['phone'] ?? '' ),
		'email' => sanitize_email( $input['email'] ?? '' ),
		'address' => sanitize_textarea_field( $input['address'] ?? '' ),
		'facebook' => esc_url_raw( $input['facebook'] ?? '' ),
		'instagram' => esc_url_raw( $input['instagram'] ?? '' ),
		'whatsapp' => sanitize_text_field( $input['whatsapp'] ?? '' ),
	);
}

function car_dealer_theme_options() {
	$defaults = array( 'primary_color' => '#102a43', 'accent_color' => '#1677c8', 'phone' => '', 'email' => '', 'address' => '', 'facebook' => '', 'instagram' => '', 'whatsapp' => '' );
	$defaults['description_en'] = 'Explore available vehicles and contact our team.';
	return wp_parse_args( get_option( 'car_dealer_theme_settings', array() ), $defaults );
}

function car_dealer_register_settings_menu() {
	add_theme_page( __( 'مظهر المعرض', 'car-dealer' ), __( 'مظهر المعرض', 'car-dealer' ), 'manage_options', 'car-dealer-settings', 'car_dealer_render_settings_page' );
}
add_action( 'admin_menu', 'car_dealer_register_settings_menu', 25 );

function car_dealer_render_settings_page() {
	$options = car_dealer_theme_options();
	?>
	<div class="wrap cd-admin" dir="<?php echo 'en' === car_dealer_ui_language() ? 'ltr' : 'rtl'; ?>">
		<h1><?php esc_html_e( 'مظهر المعرض وبيانات التواصل', 'car-dealer' ); ?></h1>
		<p class="cd-page-description"><?php echo esc_html__( 'خصص هوية الموقع وبيانات التواصل التي تظهر لعملائك.', 'car-dealer' ); ?></p>
		<?php settings_errors(); ?>
		<form method="post" action="options.php" class="cd-panel">
			<?php settings_fields( 'car_dealer_theme_settings' ); ?>
			<fieldset class="cd-settings-section"><legend><?php echo esc_html__( 'الهوية البصرية', 'car-dealer' ); ?></legend><p><?php echo esc_html__( 'اختر ألوان واجهة موقع المعرض.', 'car-dealer' ); ?></p>
			<div class="cd-settings-grid">
				<label><?php esc_html_e( 'اللون الرئيسي', 'car-dealer' ); ?><input type="color" name="car_dealer_theme_settings[primary_color]" value="<?php echo esc_attr( $options['primary_color'] ); ?>"></label>
				<label><?php esc_html_e( 'لون الأزرار', 'car-dealer' ); ?><input type="color" name="car_dealer_theme_settings[accent_color]" value="<?php echo esc_attr( $options['accent_color'] ); ?>"></label>
			</div></fieldset>
			<fieldset class="cd-settings-section"><legend><?php echo esc_html__( 'التواصل مع المعرض', 'car-dealer' ); ?></legend><p><?php echo esc_html__( 'أضف أرقام التواصل والبريد الذي يمكن للعملاء مراسلتك عليه.', 'car-dealer' ); ?></p><div class="cd-settings-grid">
				<label><?php esc_html_e( 'الهاتف', 'car-dealer' ); ?><input type="text" name="car_dealer_theme_settings[phone]" value="<?php echo esc_attr( $options['phone'] ); ?>"></label>
				<label><?php esc_html_e( 'البريد', 'car-dealer' ); ?><input type="email" name="car_dealer_theme_settings[email]" value="<?php echo esc_attr( $options['email'] ); ?>"></label>
			</div></fieldset>
			<fieldset class="cd-settings-section"><legend><?php echo esc_html__( 'حسابات التواصل والموقع', 'car-dealer' ); ?></legend><p><?php echo esc_html__( 'استخدم الروابط الكاملة لحسابات المعرض، وأضف عنواناً واضحاً لزيارته.', 'car-dealer' ); ?></p><div class="cd-settings-grid">
				<label><?php esc_html_e( 'فيسبوك', 'car-dealer' ); ?><input type="url" name="car_dealer_theme_settings[facebook]" value="<?php echo esc_attr( $options['facebook'] ); ?>"></label>
				<label><?php esc_html_e( 'إنستغرام', 'car-dealer' ); ?><input type="url" name="car_dealer_theme_settings[instagram]" value="<?php echo esc_attr( $options['instagram'] ); ?>"></label>
				<label><?php esc_html_e( 'واتساب', 'car-dealer' ); ?><input type="text" name="car_dealer_theme_settings[whatsapp]" value="<?php echo esc_attr( $options['whatsapp'] ); ?>"></label>
				<label class="cd-wide"><?php esc_html_e( 'العنوان', 'car-dealer' ); ?><textarea name="car_dealer_theme_settings[address]" rows="4"><?php echo esc_textarea( $options['address'] ); ?></textarea></label>
				<label class="cd-wide">وصف الموقع بالإنجليزية<textarea name="car_dealer_theme_settings[description_en]" rows="3" dir="ltr"><?php echo esc_textarea( $options['description_en'] ); ?></textarea></label>
			</div></fieldset>
			<?php submit_button( __( 'حفظ الإعدادات', 'car-dealer' ) ); ?>
		</form>
	</div>
	<?php
}

function car_dealer_dynamic_theme_styles() {
	$options = car_dealer_theme_options();
	printf( '<style id="car-dealer-dynamic-theme">:root{--cd-color-primary:%1$s;--cd-color-accent:%2$s;--cd-navy:%1$s;--cd-blue:%2$s;}</style>', esc_html( sanitize_hex_color( $options['primary_color'] ) ?: '#102a43' ), esc_html( sanitize_hex_color( $options['accent_color'] ) ?: '#1677c8' ) );
}
add_action( 'wp_head', 'car_dealer_dynamic_theme_styles', 20 );
