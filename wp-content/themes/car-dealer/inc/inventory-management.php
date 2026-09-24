<?php
/** Inventory management migrated from the legacy inventory module. */
defined( 'ABSPATH' ) || exit;

function car_dealer_inventory_meta_box() {
	add_meta_box( 'car-inventory', __( 'حالة المخزون', 'car-dealer' ), 'car_dealer_inventory_meta_box_html', 'car', 'side', 'high' );
}
add_action( 'add_meta_boxes', 'car_dealer_inventory_meta_box' );

function car_dealer_inventory_meta_box_html( $post ) {
	wp_nonce_field( 'car_dealer_save_inventory', 'car_dealer_inventory_nonce' );
	$status = get_post_meta( $post->ID, '_car_inventory_status', true ) ?: 'available';
	$sku = get_post_meta( $post->ID, '_car_stock_number', true );
	$offer = get_post_meta( $post->ID, '_car_special_offer', true );
	?>
	<p><label><?php esc_html_e( 'رقم المخزون', 'car-dealer' ); ?><input class="widefat" name="_car_stock_number" value="<?php echo esc_attr( $sku ); ?>"></label></p>
	<p><label><?php esc_html_e( 'الحالة', 'car-dealer' ); ?><select class="widefat" name="_car_inventory_status">
		<option value="available" <?php selected( $status, 'available' ); ?>><?php esc_html_e( 'متوفر', 'car-dealer' ); ?></option>
		<option value="reserved" <?php selected( $status, 'reserved' ); ?>><?php esc_html_e( 'محجوز', 'car-dealer' ); ?></option>
		<option value="sold" <?php selected( $status, 'sold' ); ?>><?php esc_html_e( 'مباع', 'car-dealer' ); ?></option>
		<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'قيد التجهيز', 'car-dealer' ); ?></option>
	</select></label></p>
	<p><label><input type="checkbox" name="_car_special_offer" value="1" <?php checked( $offer ); ?>> <?php esc_html_e( 'عرض خاص', 'car-dealer' ); ?></label></p>
	<?php
}

function car_dealer_save_inventory_meta( $post_id ) {
	if ( ! isset( $_POST['car_dealer_inventory_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['car_dealer_inventory_nonce'] ) ), 'car_dealer_save_inventory' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
	update_post_meta( $post_id, '_car_stock_number', sanitize_text_field( wp_unslash( $_POST['_car_stock_number'] ?? '' ) ) );
	update_post_meta( $post_id, '_car_inventory_status', sanitize_key( wp_unslash( $_POST['_car_inventory_status'] ?? 'available' ) ) );
	update_post_meta( $post_id, '_car_special_offer', isset( $_POST['_car_special_offer'] ) ? 1 : 0 );
}
add_action( 'save_post_car', 'car_dealer_save_inventory_meta' );

function car_dealer_inventory_columns( $columns ) {
	$columns['inventory_status'] = __( 'حالة المخزون', 'car-dealer' );
	$columns['stock_number'] = __( 'رقم المخزون', 'car-dealer' );
	return $columns;
}
add_filter( 'manage_car_posts_columns', 'car_dealer_inventory_columns' );

function car_dealer_inventory_column_content( $column, $post_id ) {
	if ( 'inventory_status' === $column ) { echo esc_html( car_dealer_inventory_status_label( get_post_meta( $post_id, '_car_inventory_status', true ) ?: 'available' ) ); }
	if ( 'stock_number' === $column ) { echo esc_html( get_post_meta( $post_id, '_car_stock_number', true ) ); }
}
add_action( 'manage_car_posts_custom_column', 'car_dealer_inventory_column_content', 10, 2 );

function car_dealer_inventory_status_label( $status ) {
	$labels = array( 'available' => __( 'متوفر', 'car-dealer' ), 'reserved' => __( 'محجوز', 'car-dealer' ), 'sold' => __( 'مباع', 'car-dealer' ), 'pending' => __( 'قيد التجهيز', 'car-dealer' ) );
	return $labels[ $status ] ?? $labels['available'];
}

function car_dealer_inventory_filter() {
	global $typenow;
	if ( 'car' !== $typenow ) { return; }
	$current = sanitize_key( wp_unslash( $_GET['inventory_status'] ?? '' ) );
	echo '<select name="inventory_status"><option value="">' . esc_html__( 'كل حالات المخزون', 'car-dealer' ) . '</option>';
	foreach ( array( 'available', 'reserved', 'sold', 'pending' ) as $status ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $status ), selected( $current, $status, false ), esc_html( car_dealer_inventory_status_label( $status ) ) );
	}
	echo '</select>';
}
add_action( 'restrict_manage_posts', 'car_dealer_inventory_filter' );

function car_dealer_inventory_filter_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || 'car' !== $query->get( 'post_type' ) ) { return; }
	$status = sanitize_key( wp_unslash( $_GET['inventory_status'] ?? '' ) );
	if ( in_array( $status, array( 'available', 'reserved', 'sold', 'pending' ), true ) ) {
		$meta_query = $query->get( 'meta_query' ) ?: array();
		$query->set( 'meta_query', array( 'relation' => 'AND', $meta_query, car_dealer_inventory_status_query( $status ) ) );
	}
}
add_action( 'pre_get_posts', 'car_dealer_inventory_filter_query' );

/** Cars without a saved status are available, as in the inventory editor. */
function car_dealer_inventory_status_query( $status ) {
	$condition = array( 'key' => '_car_inventory_status', 'value' => $status );
	if ( 'available' !== $status ) { return $condition; }
	return array(
		'relation' => 'OR',
		$condition,
		array( 'key' => '_car_inventory_status', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_car_inventory_status', 'value' => '' ),
	);
}

function car_dealer_register_inventory_menu() {
	add_submenu_page( 'car-dealer-dashboard', __( 'تقرير المخزون', 'car-dealer' ), __( 'تقرير المخزون', 'car-dealer' ), 'manage_car_dealer', 'car-dealer-inventory', 'car_dealer_render_inventory_report' );
}
add_action( 'admin_menu', 'car_dealer_register_inventory_menu', 30 );

function car_dealer_render_inventory_report() {
	if ( ! current_user_can( 'manage_car_dealer' ) ) { wp_die( esc_html__( 'ليست لديك صلاحية لعرض التقرير.', 'car-dealer' ) ); }
	$args = array( 'post_type' => 'car', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => 1, 'fields' => 'ids' );
	$total = new WP_Query( $args );
	echo '<div class="wrap cd-admin" dir="rtl"><h1>' . esc_html__( 'تقرير المخزون', 'car-dealer' ) . '</h1><p>' . esc_html__( 'يشمل السيارات المحفوظة بجميع حالات النشر، باستثناء سلة المهملات والمسودات التلقائية. السيارات دون حالة مخزون محددة تُحسب كمتوفرة.', 'car-dealer' ) . '</p><div class="cd-stats">';
	car_dealer_dashboard_stat( __( 'إجمالي السيارات', 'car-dealer' ), $total->found_posts, 'dashicons-car' );
	foreach ( array( 'available', 'reserved', 'sold', 'pending' ) as $status ) {
		$count = new WP_Query( array_merge( $args, array( 'meta_query' => array( car_dealer_inventory_status_query( $status ) ) ) ) );
		car_dealer_dashboard_stat( car_dealer_inventory_status_label( $status ), $count->found_posts, 'dashicons-chart-bar' );
	}
	echo '</div></div>';
}
