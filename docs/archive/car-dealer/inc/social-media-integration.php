<?php
/** Social sharing and metadata migrated from legacy social module. */
defined( 'ABSPATH' ) || exit;

function car_dealer_social_share_buttons() {
	if ( ! is_singular( 'car' ) ) { return; }
	$url = rawurlencode( get_permalink() );
	$title = rawurlencode( get_the_title() );
	echo '<div class="cd-social-share"><span>' . esc_html__( 'مشاركة:', 'car-dealer' ) . '</span>';
	printf( '<a target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=%1$s">Facebook</a>', esc_attr( $url ) );
	printf( '<a target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?url=%1$s&text=%2$s">X</a>', esc_attr( $url ), esc_attr( $title ) );
	printf( '<a target="_blank" rel="noopener" href="https://wa.me/?text=%2$s%%20%1$s">WhatsApp</a>', esc_attr( $url ), esc_attr( $title ) );
	echo '</div>';
}

function car_dealer_social_meta_tags() {
	if ( ! is_singular() ) { return; }
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( wp_get_document_title() ) );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( get_permalink() ) );
	printf( '<meta property="og:type" content="%s">' . "\n", is_singular( 'car' ) ? 'product' : 'article' );
	if ( has_post_thumbnail() ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( get_the_post_thumbnail_url( get_the_ID(), 'large' ) ) );
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}
add_action( 'wp_head', 'car_dealer_social_meta_tags', 12 );

function car_dealer_footer_social_links() {
	$options = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array();
	$links = array_filter( array( 'facebook' => $options['facebook'] ?? '', 'instagram' => $options['instagram'] ?? '' ) );
	if ( ! $links && empty( $options['whatsapp'] ) ) { return; }
	echo '<div class="cd-social-links">';
	foreach ( $links as $label => $url ) { printf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( ucfirst( $label ) ) ); }
	if ( ! empty( $options['whatsapp'] ) ) { printf( '<a href="https://wa.me/%1$s" target="_blank" rel="noopener">WhatsApp</a>', esc_attr( preg_replace( '/\D+/', '', $options['whatsapp'] ) ) ); }
	echo '</div>';
}
