<?php
defined( 'ABSPATH' ) || exit;

$categories = get_terms( array( 'taxonomy' => 'car_category', 'hide_empty' => true ) );
$brands = get_terms( array( 'taxonomy' => 'car_brand', 'hide_empty' => true ) );
$get = static function ( $key ) { return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; };
?>
<form class="filter-bar ab-filter" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">
	<label><span><?php esc_html_e( 'بحث', 'car-dealer' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'الماركة أو الموديل', 'car-dealer' ); ?>"></label>
	<label><span><?php esc_html_e( 'الماركة', 'car-dealer' ); ?></span><select name="car_brand"><option value=""><?php esc_html_e( 'كل الماركات', 'car-dealer' ); ?></option><?php foreach ( $brands as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( get_query_var( 'car_brand' ), $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label>
	<label><span><?php esc_html_e( 'نوع السيارة', 'car-dealer' ); ?></span><select name="car_category"><option value=""><?php esc_html_e( 'كل الأنواع', 'car-dealer' ); ?></option><?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( get_query_var( 'car_category' ), $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label>
	<label><span><?php esc_html_e( 'الموديل', 'car-dealer' ); ?></span><input name="model" value="<?php echo esc_attr( $get( 'model' ) ); ?>" placeholder="<?php esc_attr_e( 'Camry', 'car-dealer' ); ?>"></label>
	<label><span><?php esc_html_e( 'السنة من', 'car-dealer' ); ?></span><input type="number" min="1990" name="min_year" value="<?php echo esc_attr( $get( 'min_year' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'السعر إلى', 'car-dealer' ); ?></span><input type="number" min="0" name="max_price" value="<?php echo esc_attr( $get( 'max_price' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'الوقود', 'car-dealer' ); ?></span><select name="fuel"><option value=""><?php esc_html_e( 'الكل', 'car-dealer' ); ?></option><option value="gasoline" <?php selected( $get( 'fuel' ), 'gasoline' ); ?>><?php esc_html_e( 'بنزين', 'car-dealer' ); ?></option><option value="diesel" <?php selected( $get( 'fuel' ), 'diesel' ); ?>><?php esc_html_e( 'ديزل', 'car-dealer' ); ?></option><option value="hybrid" <?php selected( $get( 'fuel' ), 'hybrid' ); ?>><?php esc_html_e( 'هجين', 'car-dealer' ); ?></option><option value="electric" <?php selected( $get( 'fuel' ), 'electric' ); ?>><?php esc_html_e( 'كهربائي', 'car-dealer' ); ?></option></select></label>
	<label><span><?php esc_html_e( 'ناقل الحركة', 'car-dealer' ); ?></span><select name="transmission"><option value=""><?php esc_html_e( 'الكل', 'car-dealer' ); ?></option><option value="automatic" <?php selected( $get( 'transmission' ), 'automatic' ); ?>><?php esc_html_e( 'أوتوماتيكي', 'car-dealer' ); ?></option><option value="manual" <?php selected( $get( 'transmission' ), 'manual' ); ?>><?php esc_html_e( 'يدوي', 'car-dealer' ); ?></option></select></label>
	<button class="btn btn-primary" type="submit"><?php esc_html_e( 'تطبيق', 'car-dealer' ); ?></button>
</form>
