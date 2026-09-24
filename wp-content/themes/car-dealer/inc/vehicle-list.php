<?php
/** Dealership presentation for the native, fully functional vehicle list. */
defined( 'ABSPATH' ) || exit;
function car_dealer_is_vehicle_list() {
 $screen = get_current_screen();
 return $screen && 'edit' === $screen->base && 'car' === $screen->post_type;
}
add_filter( 'admin_body_class', function ( $classes ) { return car_dealer_is_vehicle_list() ? $classes . ' cd-vehicle-list' : $classes; } );
add_action( 'admin_enqueue_scripts', function () {
 if ( car_dealer_is_vehicle_list() ) { wp_enqueue_style( 'car-dealer-vehicle-list', get_template_directory_uri() . '/assets/css/vehicle-list.css', array( 'car-dealer-workspace' ), filemtime( __DIR__ . '/../assets/css/vehicle-list.css' ) ); }
} );
add_action( 'admin_notices', function () {
 if ( ! car_dealer_is_vehicle_list() || ! current_user_can( 'edit_cars' ) ) { return; }
 $args = array( 'post_type' => 'car', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => 1, 'fields' => 'ids' );
 if ( ! current_user_can( 'edit_others_cars' ) ) { $args['author'] = get_current_user_id(); }
 echo '<section class="cd-vehicles-heading" dir="rtl"><div><span class="cd-vehicles-eyebrow">إدارة مخزون المعرض</span><h1>كل السيارات</h1><p>صور ومواصفات وأسعار سياراتك في مكان واحد. ابحث، صفِّ النتائج، وعدّل بيانات السيارة مباشرة.</p></div><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=car-dealer-add-car' ) ) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> إضافة سيارة</a></section>';
 echo '<nav class="cd-vehicles-metrics" aria-label="ملخص السيارات" dir="rtl">';
 foreach ( array( 'all' => array( 'كل السيارات', 'car' ), 'available' => array( 'متوفرة للبيع', 'yes-alt' ), 'reserved' => array( 'محجوزة', 'clock' ), 'sold' => array( 'تم بيعها', 'saved' ) ) as $status => $item ) {
  $query_args = $args;
  if ( 'all' !== $status ) { $query_args['meta_query'] = array( car_dealer_inventory_status_query( $status ) ); }
  $query = new WP_Query( $query_args );
  $url = admin_url( 'edit.php?post_type=car' );
  if ( 'all' !== $status ) { $url = add_query_arg( 'inventory_status', $status, $url ); }
  if ( isset( $args['author'] ) ) { $url = add_query_arg( 'author', $args['author'], $url ); }
  echo '<a class="cd-vehicle-metric cd-metric-' . esc_attr( $status ) . '" href="' . esc_url( $url ) . '"><span class="dashicons dashicons-' . esc_attr( $item[1] ) . '" aria-hidden="true"></span><div><strong>' . esc_html( number_format_i18n( $query->found_posts ) ) . '</strong><span>' . esc_html( $item[0] ) . '</span></div></a>';
 }
 echo '</nav>';
} );
add_filter( 'manage_car_posts_columns', function ( $columns ) {
 $result = array();
 foreach ( $columns as $key => $label ) {
  if ( 'title' === $key ) { $result['vehicle_photo'] = 'الصورة'; }
  $result[$key] = $label;
  if ( 'title' === $key ) { $result['vehicle_specs'] = 'المواصفات'; $result['vehicle_price'] = 'السعر'; }
 }
 return $result;
}, 30 );
add_action( 'manage_car_posts_custom_column', function ( $column, $id ) {
 if ( 'vehicle_photo' === $column ) {
  $image = get_the_post_thumbnail( $id, 'thumbnail', array( 'class' => 'cd-list-car-image', 'alt' => '' ) );
  $image = $image ?: '<span class="cd-list-no-image"><span class="dashicons dashicons-car" aria-hidden="true"></span><small>بدون صورة</small></span>';
  if ( current_user_can( 'edit_post', $id ) ) { echo '<a aria-label="' . esc_attr( 'تعديل ' . get_the_title( $id ) ) . '" href="' . esc_url( get_edit_post_link( $id ) ) . '">' . $image . '</a>'; } else { echo $image; }
 } elseif ( 'vehicle_price' === $column ) {
  $price = get_post_meta( $id, '_car_price', true );
  echo '<strong class="cd-list-price">' . esc_html( '' === $price ? 'غير محدد' : car_dealer_format_price( $price ) ) . '</strong>';
  $monthly = get_post_meta( $id, '_car_monthly_payment', true );
  if ( $monthly ) { echo '<small class="cd-list-secondary">قسط ' . esc_html( car_dealer_format_price( $monthly ) ) . ' / شهر</small>'; }
 } elseif ( 'vehicle_specs' === $column ) {
  $specs = array_filter( array( get_post_meta( $id, '_car_make', true ), get_post_meta( $id, '_car_model', true ), get_post_meta( $id, '_car_year', true ) ) );
  echo '<span class="cd-list-specs">' . esc_html( implode( ' · ', $specs ) ?: 'لم تُحدد المواصفات' ) . '</span>';
  $mileage = get_post_meta( $id, '_car_mileage', true );
  if ( '' === $mileage ) { $mileage = get_post_meta( $id, '_car_kilometers', true ); }
  if ( '' !== $mileage ) { echo '<small class="cd-list-secondary">' . esc_html( number_format_i18n( (float) $mileage ) ) . ' كم</small>'; }
 }
}, 10, 2 );
add_action( 'restrict_manage_posts', function ( $post_type ) {
 if ( 'car' !== $post_type ) { return; }
 wp_dropdown_categories( array( 'taxonomy' => 'car_brand', 'name' => 'car_brand', 'value_field' => 'slug', 'selected' => isset( $_GET['car_brand'] ) && is_string( $_GET['car_brand'] ) ? sanitize_text_field( wp_unslash( $_GET['car_brand'] ) ) : '', 'show_option_all' => 'كل الماركات', 'hide_empty' => false, 'show_count' => false ) );
 echo '<a class="button cd-clear-vehicle-filters" href="' . esc_url( admin_url( 'edit.php?post_type=car' ) ) . '">إعادة ضبط</a>';
} );
