<?php
namespace AutoDealership\Content;

use AutoDealership\Inventory\CatalogPresentation;

defined( 'ABSPATH' ) || exit;

/** Optional approved English editorial copy for public pages, vehicles and offers. */
final class PublicEditorialTranslations {
	private const TYPES = array( 'page', 'car', 'car_offer' );
	private const TITLE_META = '_adc_title_en';
	private const CONTENT_META = '_adc_content_en';

	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register_box' ) );
		add_action( 'save_post', array( self::class, 'save' ), 20, 2 );
		add_filter( 'the_title', array( self::class, 'title' ), 8, 2 );
		add_filter( 'the_content', array( self::class, 'content' ), 8 );
		add_filter( 'document_title_parts', array( self::class, 'document_title' ) );
	}

	public static function register_box(): void {
		foreach ( self::TYPES as $type ) {
			add_meta_box( 'adc-public-english', __( 'English public content', 'auto-dealership-core' ), array( self::class, 'render_box' ), $type, 'normal', 'default' );
		}
	}

	public static function render_box( \WP_Post $post ): void {
		wp_nonce_field( 'adc_public_english_' . $post->ID, 'adc_public_english_nonce' );
		$title = (string) get_post_meta( $post->ID, self::TITLE_META, true );
		$content = (string) get_post_meta( $post->ID, self::CONTENT_META, true );
		echo '<p>' . esc_html__( 'Enter approved English copy. Leave a field blank to use the original content.', 'auto-dealership-core' ) . '</p>';
		echo '<p><label for="adc-title-en"><strong>' . esc_html__( 'English title', 'auto-dealership-core' ) . '</strong></label><br>';
		echo '<input id="adc-title-en" name="adc_title_en" type="text" class="widefat" value="' . esc_attr( $title ) . '"></p>';
		echo '<p><label for="adc-content-en"><strong>' . esc_html__( 'English content', 'auto-dealership-core' ) . '</strong></label><br>';
		echo '<textarea id="adc-content-en" name="adc_content_en" class="widefat" rows="8">' . esc_textarea( $content ) . '</textarea></p>';
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::TYPES, true ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['adc_public_english_nonce'] ) && is_scalar( $_POST['adc_public_english_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['adc_public_english_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'adc_public_english_' . $post_id ) ) {
			return;
		}
		if ( isset( $_POST['adc_title_en'] ) && is_scalar( $_POST['adc_title_en'] ) ) {
			$title = sanitize_text_field( wp_unslash( (string) $_POST['adc_title_en'] ) );
			'' === $title ? delete_post_meta( $post_id, self::TITLE_META ) : update_post_meta( $post_id, self::TITLE_META, $title );
		}
		if ( isset( $_POST['adc_content_en'] ) && is_scalar( $_POST['adc_content_en'] ) ) {
			$content = wp_kses_post( wp_unslash( (string) $_POST['adc_content_en'] ) );
			'' === trim( $content ) ? delete_post_meta( $post_id, self::CONTENT_META ) : update_post_meta( $post_id, self::CONTENT_META, $content );
		}
	}

	public static function title( string $title, int $post_id ): string {
		if ( ( is_admin() && ! wp_doing_ajax() ) || 'en' !== CatalogPresentation::language() || ! in_array( get_post_type( $post_id ), self::TYPES, true ) ) {
			return $title;
		}
		$english = (string) get_post_meta( $post_id, self::TITLE_META, true );
		return '' !== trim( $english ) ? $english : $title;
	}

	/** Keep the browser tab title consistent with the approved public translation. */
	public static function document_title( array $parts ): array {
		if ( is_admin() || 'en' !== CatalogPresentation::language() || ! is_singular( self::TYPES ) ) {
			return $parts;
		}
		$post_id = get_queried_object_id();
		$english = (string) get_post_meta( $post_id, self::TITLE_META, true );
		if ( '' !== trim( $english ) ) {
			$parts['title'] = $english;
		}
		return $parts;
	}

	public static function content( string $content ): string {
		if ( is_admin() || 'en' !== CatalogPresentation::language() || ! is_singular( self::TYPES ) || get_queried_object_id() !== get_the_ID() ) {
			return $content;
		}
		$english = (string) get_post_meta( get_the_ID(), self::CONTENT_META, true );
		if ( '' !== trim( $english ) ) {
			return $english;
		}
		if ( in_array( get_post_type( get_the_ID() ), array( 'car', 'car_offer' ), true ) && preg_match( '/[\x{0600}-\x{06FF}]/u', $content ) ) {
			return '<p class="adc-translation-notice" lang="en">This description is currently available in Arabic.</p><div lang="ar" dir="rtl">' . $content . '</div>';
		}
		return $content;
	}
}
