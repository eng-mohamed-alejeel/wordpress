<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleSpecifications;

defined( 'ABSPATH' ) || exit;

final class VehicleSpecificationsPage {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			add_submenu_page( 'adc-workspace', __( 'مواصفات المركبات', 'auto-dealership-core' ), __( 'مواصفات المركبات', 'auto-dealership-core' ), 'adc_manage_inventory', 'adc-vehicle-specifications', array( self::class, 'render' ) );
		} );
		add_action( 'admin_post_adc_save_vehicle_specifications', array( self::class, 'save' ) );
		add_action('admin_post_adc_onyx_link',array(self::class,'link'));
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { wp_die( esc_html__( 'غير مسموح.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$id = absint( $_GET['vehicle_id'] ?? 0 );
		$vehicle = $id ? VehicleService::get( $id, true ) : null;
		$labels = array( 'exterior_color'=>'اللون الخارجي', 'interior_color'=>'اللون الداخلي', 'engine_size'=>'سعة المحرك (مع الوحدة)', 'drivetrain'=>'نظام الدفع', 'doors'=>'عدد الأبواب', 'seats'=>'عدد المقاعد', 'horsepower'=>'القوة بالحصان', 'warranty'=>'الضمان', 'interior_features'=>'التجهيزات الداخلية', 'exterior_features'=>'التجهيزات الخارجية', 'safety_features'=>'تجهيزات السلامة' );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'مواصفات المركبات', 'auto-dealership-core' ); ?></h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ المواصفات.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر الحفظ. راجع القيم وحالة المركبة وصلاحية الوصول.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<form method="get"><input type="hidden" name="page" value="adc-vehicle-specifications"><label for="adc-spec-id"><?php esc_html_e( 'معرّف المركبة', 'auto-dealership-core' ); ?></label> <input id="adc-spec-id" name="vehicle_id" type="number" min="1" required value="<?php echo $id ?: ''; ?>"> <button class="button"><?php esc_html_e( 'عرض', 'auto-dealership-core' ); ?></button></form>
		<?php if ( $id && ! $vehicle ) : ?><p><?php esc_html_e( 'المركبة غير موجودة أو خارج نطاق الوصول.', 'auto-dealership-core' ); ?></p><?php endif; ?>
		<?php if ( $vehicle ) : ?>
		<?php $onyx_state=\AutoDealership\Inventory\OnyxOperationalPolicy::current((int)$vehicle['id']);if(is_wp_error($onyx_state)){echo '<div class="notice notice-warning"><p>'.esc_html($onyx_state->get_error_message()).'</p></div>';} ?>
		<h2><?php echo esc_html( $vehicle['stock_number'] . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'brand', (string) $vehicle['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'model', (string) $vehicle['model'] ) ); ?></h2>
		<p><?php esc_html_e( 'تظهر هذه المواصفات للعملاء عند إتاحة المركبة ونشر صفحتها. تُقفل التعديلات أثناء الحجز والبيع والتسليم.', 'auto-dealership-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="adc_save_vehicle_specifications"><input type="hidden" name="id" value="<?php echo $id; ?>">
		<?php wp_nonce_field( 'adc_save_vehicle_specifications_' . $id ); ?>
		<table class="form-table"><tbody>
		<?php foreach ( $labels as $field => $label ) : $value = $vehicle[$field] ?? ''; ?>
		<tr><th><label for="adc-spec-<?php echo esc_attr( $field ); ?>"><?php echo esc_html__( $label, 'auto-dealership-core' ); ?></label></th><td>
		<?php if ( 'drivetrain' === $field ) : ?>
		<select id="adc-spec-drivetrain" name="specifications[drivetrain]"><?php foreach ( array( ''=>'غير محدد', 'fwd'=>'دفع أمامي', 'rwd'=>'دفع خلفي', 'awd'=>'دفع كلي', '4wd'=>'دفع رباعي' ) as $key => $name ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html__( $name, 'auto-dealership-core' ); ?></option><?php endforeach; ?></select>
		<?php elseif ( isset( VehicleSpecifications::NUMBER_LIMITS[$field] ) ) : ?>
		<input id="adc-spec-<?php echo esc_attr( $field ); ?>" name="specifications[<?php echo esc_attr( $field ); ?>]" type="number" min="1" max="<?php echo (int) VehicleSpecifications::NUMBER_LIMITS[$field]; ?>" step="1" value="<?php echo esc_attr( $value ); ?>">
		<?php elseif ( VehicleSpecifications::TEXT_LIMITS[$field] > 100 ) : ?>
		<textarea class="large-text" rows="3" id="adc-spec-<?php echo esc_attr( $field ); ?>" name="specifications[<?php echo esc_attr( $field ); ?>]" maxlength="<?php echo (int) VehicleSpecifications::TEXT_LIMITS[$field]; ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php else : ?>
		<input class="regular-text" id="adc-spec-<?php echo esc_attr( $field ); ?>" name="specifications[<?php echo esc_attr( $field ); ?>]" maxlength="<?php echo (int) VehicleSpecifications::TEXT_LIMITS[$field]; ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endif; ?></td></tr><?php endforeach; ?>
		<tr><th><label for="adc-spec-origin-country"><?php esc_html_e( 'بلد المنشأ', 'auto-dealership-core' ); ?></label></th><td><input class="regular-text" id="adc-spec-origin-country" name="specifications[origin_country]" maxlength="80" value="<?php echo esc_attr( $vehicle['origin_country'] ?? '' ); ?>"></td></tr>
		<tr><th><label for="adc-spec-cylinders"><?php esc_html_e( 'عدد الأسطوانات', 'auto-dealership-core' ); ?></label></th><td><input id="adc-spec-cylinders" name="specifications[cylinders]" type="number" min="1" max="32" value="<?php echo esc_attr( $vehicle['cylinders'] ?? '' ); ?>"></td></tr>
		<tr><th><label for="adc-spec-video"><?php esc_html_e( 'رابط فيديو المركبة', 'auto-dealership-core' ); ?></label></th><td><input class="large-text" id="adc-spec-video" name="specifications[video_url]" type="url" maxlength="500" value="<?php echo esc_attr( $vehicle['video_url'] ?? '' ); ?>"></td></tr>
		<tr><th><label for="adc-spec-gallery"><?php esc_html_e( 'معرّفات صور المعرض', 'auto-dealership-core' ); ?></label></th><td><input class="large-text" id="adc-spec-gallery" name="specifications[gallery_media_ids]" value="<?php echo esc_attr( implode( ',', (array) ( $vehicle['gallery_media_ids'] ?? array() ) ) ); ?>"><p class="description"><?php esc_html_e( 'معرّفات مرفقات صور WordPress، مفصولة بفواصل، بحد أقصى 30 صورة.', 'auto-dealership-core' ); ?></p></td></tr>
		<tr><th><label for="adc-spec-reason"><?php esc_html_e( 'سبب التعديل', 'auto-dealership-core' ); ?></label></th><td><textarea id="adc-spec-reason" name="reason" required maxlength="2000" class="large-text"></textarea></td></tr>
		</tbody></table><?php submit_button( __( 'حفظ المواصفات', 'auto-dealership-core' ) ); ?></form><?php self::render_onyx_link($id); ?><?php endif; ?></div>
		<?php
	}

	private static function text(string $ar,string $en): string { return \AutoDealership\Core\Localization::language()==='en'?$en:$ar; }
	public static function render_onyx_link(int $id): void {
		global $wpdb;
		if(!current_user_can('manage_options')||!current_user_can('adc_manage_inventory')){return;}
		$vehicle=VehicleService::get($id,true);if(!$vehicle){return;}
		$source=$wpdb->get_row($wpdb->prepare('SELECT source_identifier,present FROM '.\AutoDealership\Database\Schema::table('onyx_stock').' WHERE vehicle_id=%d',$id),ARRAY_A);
		if($wpdb->last_error){echo '<p>'.esc_html(self::text('تعذر تحميل ربط أونكس.','Unable to load the Onyx link.')).'</p>';return;}
		echo '<section class="adc-vehicle-onyx-link"><h2>'.esc_html(self::text('ربط السيارة بمخزون أونكس','Link this vehicle to Onyx inventory')).'</h2>';
		if(isset($_GET['onyx_saved'])){echo '<div class="notice notice-success"><p>'.esc_html(self::text('تم ربط السيارة بمخزون أونكس.','Vehicle linked to Onyx inventory.')).'</p></div>';}
		if(isset($_GET['onyx_error'])){echo '<div class="notice notice-error"><p>'.esc_html(self::text('تعذر الربط. تحقق من تطابق رمز أونكس ورقم الهيكل وسنة الموديل ونطاق الصلاحية.','Unable to link. Check the Onyx identifier, full VIN, model year and access scope.')).'</p></div>';}
		echo '<p>'.esc_html(self::text('رقم الهيكل الكامل','Full VIN')).': <code dir="ltr">'.esc_html($vehicle['vin']).'</code></p>';
		if($source){echo '<p>'.esc_html(self::text('رمز أونكس المرتبط','Linked Onyx identifier')).': <code dir="ltr">'.esc_html($source['source_identifier']).'</code> — '.esc_html($source['present']?self::text('متوفرة في أونكس','Available in Onyx'):self::text('مباعة حسب أونكس','Sold according to Onyx')).'</p></section>';return;}
		$matches=\AutoDealership\Inventory\InventoryIdentityLookup::sources($vehicle['vin']);$code=count($matches)===1&& (int)$matches[0]['model_year']===(int)$vehicle['model_year']?$matches[0]['source_identifier']:'';
		echo '<p>'.esc_html(self::text('هذا الربط مخصص للحجز والبيع داخل النظام. رفع أونكس ونشر السيارات يعملان تلقائيًا دون هذا الإجراء.','This link supports internal reservations and sales. Onyx uploads and website publication work automatically without this action.')).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_onyx_link"><input type="hidden" name="vehicle_id" value="'.absint($id).'">';
		wp_nonce_field('adc_onyx_link_'.$id);
		echo '<label>'.esc_html(self::text('رمز أونكس','Onyx identifier')).' <input name="source_identifier" dir="ltr" required maxlength="32" value="'.esc_attr($code).'"></label> <button class="button">'.esc_html(self::text('تحقق واربط هذه السيارة','Verify and link this vehicle')).'</button></form></section>';
	}
	public static function link_result(int $id,string $code){
		global $wpdb;
		if(!current_user_can('manage_options')||!current_user_can('adc_manage_inventory')){return new \WP_Error('adc_onyx_forbidden');}
		$vehicle=VehicleService::get($id,true);if(!$vehicle){return new \WP_Error('adc_onyx_missing');}
		$source=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.\AutoDealership\Database\Schema::table('onyx_stock').' WHERE source_identifier=%s',strtoupper(trim($code))));
		return \AutoDealership\Inventory\OnyxImportService::link($source,$vehicle['vin'],$id);
	}
	public static function link(): void {
		$id=absint($_POST['vehicle_id']??0);check_admin_referer('adc_onyx_link_'.$id);
		$result=self::link_result($id,sanitize_text_field(wp_unslash($_POST['source_identifier']??'')));
		wp_safe_redirect(add_query_arg(array('page'=>'adc-vehicle-specifications','vehicle_id'=>$id,is_wp_error($result)?'onyx_error':'onyx_saved'=>'1'),admin_url('admin.php')));exit;
	}
	public static function save(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_save_vehicle_specifications_' . $id );
		$input = isset( $_POST['specifications'] ) && is_array( $_POST['specifications'] ) ? wp_unslash( $_POST['specifications'] ) : array();
		if ( isset( $input['gallery_media_ids'] ) && is_string( $input['gallery_media_ids'] ) ) {
			$input['gallery_media_ids'] = '' === trim( $input['gallery_media_ids'] ) ? array() : array_map( 'trim', explode( ',', $input['gallery_media_ids'] ) );
		}
		$reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '';
		$result = VehicleSpecifications::update( $id, $input, $reason );
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-vehicle-specifications', 'vehicle_id'=>$id, is_wp_error( $result ) ? 'error' : 'saved'=>'1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
