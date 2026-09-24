<?php
/** Professional inventory enhancements migrated from legacy modules. */
defined( 'ABSPATH' ) || exit;

function car_dealer_advanced_meta_box() {
	add_meta_box( 'car-advanced-details', __( 'ميزات العرض', 'car-dealer' ), 'car_dealer_advanced_meta_box_html', 'car', 'side', 'default' );
}
add_action( 'add_meta_boxes', 'car_dealer_advanced_meta_box' );

function car_dealer_advanced_meta_box_html( $post ) {
	wp_nonce_field( 'car_dealer_save_advanced', 'car_dealer_advanced_nonce' );
	$featured = (bool) get_post_meta( $post->ID, '_car_featured', true );
	$features = get_post_meta( $post->ID, '_car_features', true );
	?>
	<p><label><input type="checkbox" name="_car_featured" value="1" <?php checked( $featured ); ?>> <?php esc_html_e( 'سيارة مميزة', 'car-dealer' ); ?></label></p>
	<p><label for="_car_features"><?php esc_html_e( 'المزايا', 'car-dealer' ); ?></label></p>
	<textarea id="_car_features" name="_car_features" rows="6" style="width:100%;" placeholder="<?php esc_attr_e( 'ميزة في كل سطر', 'car-dealer' ); ?>"><?php echo esc_textarea( $features ); ?></textarea>
	<?php
}

function car_dealer_save_advanced_meta( $post_id ) {
	if ( ! isset( $_POST['car_dealer_advanced_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['car_dealer_advanced_nonce'] ) ), 'car_dealer_save_advanced' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
	update_post_meta( $post_id, '_car_featured', isset( $_POST['_car_featured'] ) ? 1 : 0 );
	if ( isset( $_POST['_car_features'] ) ) {
		update_post_meta( $post_id, '_car_features', sanitize_textarea_field( wp_unslash( $_POST['_car_features'] ) ) );
	}
}
add_action( 'save_post_car', 'car_dealer_save_advanced_meta' );

function car_dealer_manage_car_columns( $columns ) {
	$columns['car_price'] = __( 'السعر', 'car-dealer' );
	$columns['car_year'] = __( 'السنة', 'car-dealer' );
	$columns['car_featured'] = __( 'مميزة', 'car-dealer' );
	return $columns;
}
add_filter( 'manage_car_posts_columns', 'car_dealer_manage_car_columns' );

function car_dealer_manage_car_column_content( $column, $post_id ) {
	if ( 'car_price' === $column ) { echo esc_html( car_dealer_format_price( get_post_meta( $post_id, '_car_price', true ) ) ); }
	if ( 'car_year' === $column ) { echo esc_html( get_post_meta( $post_id, '_car_year', true ) ); }
	if ( 'car_featured' === $column ) { echo get_post_meta( $post_id, '_car_featured', true ) ? esc_html__( 'نعم', 'car-dealer' ) : esc_html__( 'لا', 'car-dealer' ); }
}
add_action( 'manage_car_posts_custom_column', 'car_dealer_manage_car_column_content', 10, 2 );

function car_dealer_sortable_car_columns( $columns ) {
	$columns['car_price'] = 'car_price';
	$columns['car_year'] = 'car_year';
	return $columns;
}
add_filter( 'manage_edit-car_sortable_columns', 'car_dealer_sortable_car_columns' );

function car_dealer_admin_car_orderby( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) { return; }
	if ( 'car_price' === $query->get( 'orderby' ) ) { $query->set( 'meta_key', '_car_price' ); $query->set( 'orderby', 'meta_value_num' ); }
	if ( 'car_year' === $query->get( 'orderby' ) ) { $query->set( 'meta_key', '_car_year' ); $query->set( 'orderby', 'meta_value_num' ); }
}
add_action( 'pre_get_posts', 'car_dealer_admin_car_orderby' );

function car_dealer_admin_car_filters() {
	global $typenow;
	if ( 'car' !== $typenow ) { return; }
	$featured = isset( $_GET['car_featured'] ) ? sanitize_key( wp_unslash( $_GET['car_featured'] ) ) : '';
	?>
	<select name="car_featured">
		<option value=""><?php esc_html_e( 'كل السيارات', 'car-dealer' ); ?></option>
		<option value="1" <?php selected( $featured, '1' ); ?>><?php esc_html_e( 'السيارات المميزة', 'car-dealer' ); ?></option>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', 'car_dealer_admin_car_filters' );

function car_dealer_apply_admin_car_filters( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || 'car' !== $query->get( 'post_type' ) ) { return; }
	if ( isset( $_GET['car_featured'] ) && '1' === sanitize_key( wp_unslash( $_GET['car_featured'] ) ) ) {
		$query->set( 'meta_key', '_car_featured' );
		$query->set( 'meta_value', '1' );
	}
}
add_action( 'pre_get_posts', 'car_dealer_apply_admin_car_filters' );
