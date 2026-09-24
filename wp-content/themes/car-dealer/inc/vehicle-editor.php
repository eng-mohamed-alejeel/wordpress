<?php
/** Use the same dealership form for creating and editing vehicles. */
defined( 'ABSPATH' ) || exit;
function car_dealer_vehicle_editor_url( $id ) { return add_query_arg( array( 'page' => 'car-dealer-add-car', 'car_id' => absint( $id ) ), admin_url( 'admin.php' ) ); }
add_filter( 'get_edit_post_link', function ( $link, $id, $context ) {
 if ( 'car' !== get_post_type( $id ) ) { return $link; }
 $url = car_dealer_vehicle_editor_url( $id );
 return 'display' === $context ? esc_url( $url ) : $url;
}, 10, 3 );
add_action( 'load-post.php', function () {
 $id = absint( $_GET['post'] ?? 0 );
 if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'edit' === ( $_GET['action'] ?? '' ) && 'car' === get_post_type( $id ) ) {
  if ( ! current_user_can( 'edit_post', $id ) ) { wp_die( 'ليست لديك صلاحية.', '', array( 'response' => 403 ) ); }
  wp_safe_redirect( car_dealer_vehicle_editor_url( $id ) ); exit;
 }
} );
function car_dealer_vehicle_form_value( $key, $id = 0 ) {
 if ( ! empty( $_POST['car_dealer_add_car'] ) && isset( $_POST[$key] ) ) { return wp_unslash( $_POST[$key] ); }
 if ( ! empty( $_POST['car_dealer_add_car'] ) && in_array( $key, array( '_car_features', '_car_is_featured', '_car_is_offer' ), true ) ) { return '_car_features' === $key ? array() : '0'; }
 if ( 'car_title' === $key ) { return $id ? get_post_field( 'post_title', $id ) : ''; }
 if ( 'car_description' === $key ) { return $id ? get_post_field( 'post_content', $id ) : ''; }
 if ( 'post_status' === $key ) { return $id ? get_post_status( $id ) : ( current_user_can( 'publish_cars' ) ? 'publish' : 'draft' ); }
 if ( in_array( $key, array( 'car_brand', 'car_category' ), true ) ) { return $id ? wp_get_object_terms( $id, $key, array( 'fields' => 'ids' ) ) : array(); }
 $value = $id ? get_post_meta( $id, $key, true ) : '';
 if ( '_car_features' === $key && ! is_array( $value ) ) { return array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $value ) ) ); }
 return $value;
}
