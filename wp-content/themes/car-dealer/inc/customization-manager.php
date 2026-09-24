<?php
/** Theme settings and controlled dynamic styles migrated from legacy modules. */
defined( 'ABSPATH' ) || exit;

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
	return wp_parse_args( get_option( 'car_dealer_theme_settings', array() ), $defaults );
}

function car_dealer_register_settings_menu() {
	add_submenu_page( 'car-dealer-dashboard', __( 'إعدادات المعرض', 'car-dealer' ), __( 'إعدادات المعرض', 'car-dealer' ), 'manage_options', 'car-dealer-settings', 'car_dealer_render_settings_page' );
}
add_action( 'admin_menu', 'car_dealer_register_settings_menu', 25 );

function car_dealer_render_settings_page() {
	$options = car_dealer_theme_options();
	?>
	<div class="wrap cd-admin" dir="rtl">
		<h1><?php esc_html_e( 'إعدادات المعرض', 'car-dealer' ); ?></h1>
		<p class="cd-page-description">خصص هوية الموقع وبيانات التواصل التي تظهر لعملائك.</p>
		<?php settings_errors(); ?>
		<form method="post" action="options.php" class="cd-panel">
			<?php settings_fields( 'car_dealer_theme_settings' ); ?>
			<fieldset class="cd-settings-section"><legend>الهوية البصرية</legend><p>اختر ألوان واجهة موقع المعرض.</p>
			<div class="cd-settings-grid">
				<label><?php esc_html_e( 'اللون الرئيسي', 'car-dealer' ); ?><input type="color" name="car_dealer_theme_settings[primary_color]" value="<?php echo esc_attr( $options['primary_color'] ); ?>"></label>
				<label><?php esc_html_e( 'لون الأزرار', 'car-dealer' ); ?><input type="color" name="car_dealer_theme_settings[accent_color]" value="<?php echo esc_attr( $options['accent_color'] ); ?>"></label>
			</div></fieldset>
			<fieldset class="cd-settings-section"><legend>التواصل مع المعرض</legend><p>أضف أرقام التواصل والبريد الذي يمكن للعملاء مراسلتك عليه.</p><div class="cd-settings-grid">
				<label><?php esc_html_e( 'الهاتف', 'car-dealer' ); ?><input type="text" name="car_dealer_theme_settings[phone]" value="<?php echo esc_attr( $options['phone'] ); ?>"></label>
				<label><?php esc_html_e( 'البريد', 'car-dealer' ); ?><input type="email" name="car_dealer_theme_settings[email]" value="<?php echo esc_attr( $options['email'] ); ?>"></label>
			</div></fieldset>
			<fieldset class="cd-settings-section"><legend>حسابات التواصل والموقع</legend><p>استخدم الروابط الكاملة لحسابات المعرض، وأضف عنواناً واضحاً لزيارته.</p><div class="cd-settings-grid">
				<label><?php esc_html_e( 'فيسبوك', 'car-dealer' ); ?><input type="url" name="car_dealer_theme_settings[facebook]" value="<?php echo esc_attr( $options['facebook'] ); ?>"></label>
				<label><?php esc_html_e( 'إنستغرام', 'car-dealer' ); ?><input type="url" name="car_dealer_theme_settings[instagram]" value="<?php echo esc_attr( $options['instagram'] ); ?>"></label>
				<label><?php esc_html_e( 'واتساب', 'car-dealer' ); ?><input type="text" name="car_dealer_theme_settings[whatsapp]" value="<?php echo esc_attr( $options['whatsapp'] ); ?>"></label>
				<label class="cd-wide"><?php esc_html_e( 'العنوان', 'car-dealer' ); ?><textarea name="car_dealer_theme_settings[address]" rows="4"><?php echo esc_textarea( $options['address'] ); ?></textarea></label>
			</div></fieldset>
			<?php submit_button( __( 'حفظ الإعدادات', 'car-dealer' ) ); ?>
		</form>
	</div>
	<?php
}

function car_dealer_dynamic_theme_styles() {
	$options = car_dealer_theme_options();
	printf( '<style id="car-dealer-dynamic-theme">:root{--cd-navy:%1$s;--cd-blue:%2$s;}</style>', esc_html( $options['primary_color'] ), esc_html( $options['accent_color'] ) );
}
add_action( 'wp_head', 'car_dealer_dynamic_theme_styles', 20 );
