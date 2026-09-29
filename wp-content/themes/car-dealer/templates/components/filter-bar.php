<?php
defined( 'ABSPATH' ) || exit;

$get = static function ( $key ) {
	return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
};
$archive_url = get_post_type_archive_link( 'car' );
$authoritative = function_exists( 'car_dealer_catalog_is_authoritative' ) && car_dealer_catalog_is_authoritative();

if ( ! $authoritative ) {
	$categories = get_terms( array( 'taxonomy' => 'car_category', 'hide_empty' => true ) );
	$brands = get_terms( array( 'taxonomy' => 'car_brand', 'hide_empty' => true ) );
	$categories = is_wp_error( $categories ) ? array() : $categories;
	$brands = is_wp_error( $brands ) ? array() : $brands;
	?>
	<form class="filter-bar ab-filter" method="get" action="<?php echo esc_url( $archive_url ); ?>">
		<?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="post_type" value="car"><?php endif; ?>
		<label><span><?php esc_html_e( 'بحث', 'car-dealer' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'الماركة أو الموديل', 'car-dealer' ); ?>"></label>
		<label><span><?php esc_html_e( 'الماركة', 'car-dealer' ); ?></span><select name="car_brand"><option value=""><?php esc_html_e( 'كل الماركات', 'car-dealer' ); ?></option><?php foreach ( $brands as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( get_query_var( 'car_brand' ), $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label>
		<label><span><?php esc_html_e( 'نوع السيارة', 'car-dealer' ); ?></span><select name="car_category"><option value=""><?php esc_html_e( 'كل الأنواع', 'car-dealer' ); ?></option><?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( get_query_var( 'car_category' ), $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label>
		<label><span><?php esc_html_e( 'الموديل', 'car-dealer' ); ?></span><input name="model" value="<?php echo esc_attr( $get( 'model' ) ); ?>" placeholder="Camry"></label>
		<label><span><?php esc_html_e( 'السنة من', 'car-dealer' ); ?></span><input type="number" min="1900" max="2200" name="min_year" value="<?php echo esc_attr( $get( 'min_year' ) ); ?>"></label>
		<label><span><?php esc_html_e( 'السعر إلى', 'car-dealer' ); ?></span><input type="number" min="0" name="max_price" value="<?php echo esc_attr( $get( 'max_price' ) ); ?>"></label>
		<label><span><?php esc_html_e( 'الوقود', 'car-dealer' ); ?></span><select name="fuel"><option value=""><?php esc_html_e( 'الكل', 'car-dealer' ); ?></option><option value="gasoline" <?php selected( $get( 'fuel' ), 'gasoline' ); ?>><?php esc_html_e( 'بنزين', 'car-dealer' ); ?></option><option value="diesel" <?php selected( $get( 'fuel' ), 'diesel' ); ?>><?php esc_html_e( 'ديزل', 'car-dealer' ); ?></option><option value="hybrid" <?php selected( $get( 'fuel' ), 'hybrid' ); ?>><?php esc_html_e( 'هجين', 'car-dealer' ); ?></option><option value="electric" <?php selected( $get( 'fuel' ), 'electric' ); ?>><?php esc_html_e( 'كهربائي', 'car-dealer' ); ?></option></select></label>
		<label><span><?php esc_html_e( 'ناقل الحركة', 'car-dealer' ); ?></span><select name="transmission"><option value=""><?php esc_html_e( 'الكل', 'car-dealer' ); ?></option><option value="automatic" <?php selected( $get( 'transmission' ), 'automatic' ); ?>><?php esc_html_e( 'أوتوماتيكي', 'car-dealer' ); ?></option><option value="manual" <?php selected( $get( 'transmission' ), 'manual' ); ?>><?php esc_html_e( 'يدوي', 'car-dealer' ); ?></option></select></label>
		<button class="btn btn-primary" type="submit"><?php esc_html_e( 'تطبيق', 'car-dealer' ); ?></button>
	</form>
	<?php
	return;
}

$options = car_dealer_catalog_filter_options();
$select = static function ( $name, $label, array $values, $current, $all_label ) {
	?>
	<label><span><?php echo esc_html( $label ); ?></span><select name="<?php echo esc_attr( $name ); ?>"><option value=""><?php echo esc_html( $all_label ); ?></option><?php foreach ( $values as $value ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( $value ); ?></option><?php endforeach; ?></select></label>
	<?php
};
?>
<form class="filter-bar ab-filter ab-filter-authoritative" method="get" action="<?php echo esc_url( $archive_url ); ?>" aria-label="<?php esc_attr_e( 'تصفية مخزون السيارات', 'car-dealer' ); ?>">
	<?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="post_type" value="car"><?php endif; ?>
	<label><span><?php esc_html_e( 'بحث', 'car-dealer' ); ?></span><input type="search" name="search" value="<?php echo esc_attr( $get( 'search' ) ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'الماركة أو الموديل أو رقم المخزون', 'car-dealer' ); ?>"></label>
	<?php $select( 'brand', __( 'الماركة', 'car-dealer' ), $options['brand'] ?? array(), $get( 'brand' ), __( 'كل الماركات', 'car-dealer' ) ); ?>
	<label><span><?php esc_html_e( 'الموديل', 'car-dealer' ); ?></span><input name="model" value="<?php echo esc_attr( $get( 'model' ) ); ?>" maxlength="120"></label>
	<label><span><?php esc_html_e( 'الفئة', 'car-dealer' ); ?></span><input name="trim" value="<?php echo esc_attr( $get( 'trim' ) ); ?>" maxlength="120"></label>
	<label><span><?php esc_html_e( 'السنة من', 'car-dealer' ); ?></span><input type="number" min="1900" max="2200" name="min_year" value="<?php echo esc_attr( $get( 'min_year' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'السنة إلى', 'car-dealer' ); ?></span><input type="number" min="1900" max="2200" name="max_year" value="<?php echo esc_attr( $get( 'max_year' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'السعر من (ريال)', 'car-dealer' ); ?></span><input type="number" min="0" name="min_price" value="<?php echo esc_attr( $get( 'min_price' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'السعر إلى (ريال)', 'car-dealer' ); ?></span><input type="number" min="0" name="max_price" value="<?php echo esc_attr( $get( 'max_price' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'الممشى من (كم)', 'car-dealer' ); ?></span><input type="number" min="0" name="min_mileage" value="<?php echo esc_attr( $get( 'min_mileage' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'الممشى إلى (كم)', 'car-dealer' ); ?></span><input type="number" min="0" name="max_mileage" value="<?php echo esc_attr( $get( 'max_mileage' ) ); ?>"></label>
	<?php $select( 'body_type', __( 'نوع الهيكل', 'car-dealer' ), $options['body_type'] ?? array(), $get( 'body_type' ), __( 'كل الأنواع', 'car-dealer' ) ); ?>
	<?php $select( 'fuel_type', __( 'الوقود', 'car-dealer' ), $options['fuel_type'] ?? array(), $get( 'fuel_type' ), __( 'كل الأنواع', 'car-dealer' ) ); ?>
	<?php $select( 'transmission', __( 'ناقل الحركة', 'car-dealer' ), $options['transmission'] ?? array(), $get( 'transmission' ), __( 'الكل', 'car-dealer' ) ); ?>
	<label><span><?php esc_html_e( 'المحرك', 'car-dealer' ); ?></span><input name="engine_size" value="<?php echo esc_attr( $get( 'engine_size' ) ); ?>" maxlength="40"></label>
	<label><span><?php esc_html_e( 'نظام الدفع', 'car-dealer' ); ?></span><select name="drivetrain"><option value=""><?php esc_html_e( 'الكل', 'car-dealer' ); ?></option><?php foreach ( array( 'fwd' => 'FWD', 'rwd' => 'RWD', 'awd' => 'AWD', '4wd' => '4WD' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $get( 'drivetrain' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
	<label><span><?php esc_html_e( 'اللون الخارجي', 'car-dealer' ); ?></span><input name="exterior_color" value="<?php echo esc_attr( $get( 'exterior_color' ) ); ?>" maxlength="80"></label>
	<label><span><?php esc_html_e( 'اللون الداخلي', 'car-dealer' ); ?></span><input name="interior_color" value="<?php echo esc_attr( $get( 'interior_color' ) ); ?>" maxlength="80"></label>
	<label><span><?php esc_html_e( 'الحالة', 'car-dealer' ); ?></span><select name="condition"><option value=""><?php esc_html_e( 'الكل', 'car-dealer' ); ?></option><option value="new" <?php selected( $get( 'condition' ), 'new' ); ?>><?php esc_html_e( 'جديدة', 'car-dealer' ); ?></option><option value="used" <?php selected( $get( 'condition' ), 'used' ); ?>><?php esc_html_e( 'مستعملة', 'car-dealer' ); ?></option></select></label>
	<label><span><?php esc_html_e( 'الفرع', 'car-dealer' ); ?></span><select name="branch_id"><option value=""><?php esc_html_e( 'كل الفروع', 'car-dealer' ); ?></option><?php foreach ( $options['branches'] ?? array() as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $get( 'branch_id' ), (string) $branch['id'] ); ?>><?php echo esc_html( $branch['name'] . ( $branch['city'] ? ' — ' . $branch['city'] : '' ) ); ?></option><?php endforeach; ?></select></label>
	<label><span><?php esc_html_e( 'الترتيب', 'car-dealer' ); ?></span><select name="sort"><option value="newest" <?php selected( $get( 'sort' ) ?: 'newest', 'newest' ); ?>><?php esc_html_e( 'الأحدث', 'car-dealer' ); ?></option><option value="price_asc" <?php selected( $get( 'sort' ), 'price_asc' ); ?>><?php esc_html_e( 'السعر: الأقل أولًا', 'car-dealer' ); ?></option><option value="price_desc" <?php selected( $get( 'sort' ), 'price_desc' ); ?>><?php esc_html_e( 'السعر: الأعلى أولًا', 'car-dealer' ); ?></option><option value="year_desc" <?php selected( $get( 'sort' ), 'year_desc' ); ?>><?php esc_html_e( 'سنة الصنع', 'car-dealer' ); ?></option><option value="mileage_asc" <?php selected( $get( 'sort' ), 'mileage_asc' ); ?>><?php esc_html_e( 'الأقل ممشى', 'car-dealer' ); ?></option></select></label>
	<div class="filter-actions"><button class="btn btn-primary" type="submit"><?php esc_html_e( 'تطبيق الفلاتر', 'car-dealer' ); ?></button><a class="btn btn-outline" href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'مسح الفلاتر', 'car-dealer' ); ?></a></div>
</form>
