<?php
namespace AutoDealership\Content;

use AutoDealership\Inventory\CatalogPresentation;

defined( 'ABSPATH' ) || exit;

/** Optional approved English editorial copy for public pages, vehicles and offers. */
final class PublicEditorialTranslations {
	private const TYPES = array( 'page', 'post', 'car', 'car_offer' );
	private const TITLE_META = '_adc_title_en';
	private const CONTENT_META = '_adc_content_en';

	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register_box' ) );
		add_action( 'save_post', array( self::class, 'save' ), 20, 2 );
		add_action( 'post_updated', array( self::class, 'capture_legacy_source' ), 10, 3 );
		add_filter( 'the_title', array( self::class, 'title' ), 8, 2 );
		add_filter( 'the_content', array( self::class, 'content' ), 8 );
		add_filter( 'get_the_excerpt', array( self::class, 'excerpt' ), 8, 2 );
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
		if ( ( '' !== $title && '' === self::approved( $post->ID, self::TITLE_META ) ) || ( '' !== $content && '' === self::approved( $post->ID, self::CONTENT_META ) ) ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'Needs translation review', 'auto-dealership-core' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Enter approved English copy. Missing or outdated translations show an English notice instead of Arabic content.', 'auto-dealership-core' ) . '</p>';
		echo '<p><label for="adc-title-en"><strong>' . esc_html__( 'English title', 'auto-dealership-core' ) . '</strong></label><br>';
		echo '<input id="adc-title-en" name="adc_title_en" type="text" lang="en" dir="ltr" class="widefat" value="' . esc_attr( $title ) . '"></p>';
		echo '<p><label for="adc-content-en"><strong>' . esc_html__( 'English content', 'auto-dealership-core' ) . '</strong></label><br>';
		echo '<textarea id="adc-content-en" name="adc_content_en" lang="en" dir="ltr" class="widefat" rows="8">' . esc_textarea( $content ) . '</textarea></p>';
		echo '<p><label><input type="checkbox" name="adc_english_reviewed" value="1"> ' . esc_html__( 'I reviewed the English copy against the current original content.', 'auto-dealership-core' ) . '</label></p>';
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
			$reviewed = $title !== (string) get_post_meta( $post_id, self::TITLE_META, true ) || '1' === ( $_POST['adc_english_reviewed'] ?? '' );
			'' === $title ? delete_post_meta( $post_id, self::TITLE_META ) : update_post_meta( $post_id, self::TITLE_META, $title );
			if ( $reviewed ) { update_post_meta( $post_id, '_adc_title_en_source_hash', hash( 'sha256', $post->post_title ) ); }
		}
		if ( isset( $_POST['adc_content_en'] ) && is_scalar( $_POST['adc_content_en'] ) ) {
			$content = wp_kses_post( wp_unslash( (string) $_POST['adc_content_en'] ) );
			$reviewed = $content !== (string) get_post_meta( $post_id, self::CONTENT_META, true ) || '1' === ( $_POST['adc_english_reviewed'] ?? '' );
			'' === trim( $content ) ? delete_post_meta( $post_id, self::CONTENT_META ) : update_post_meta( $post_id, self::CONTENT_META, $content );
			if ( $reviewed ) { update_post_meta( $post_id, '_adc_content_en_source_hash', hash( 'sha256', $post->post_content ) ); }
		}
	}

	public static function title( string $title, int $post_id ): string {
		if ( ( is_admin() && ! wp_doing_ajax() ) || 'en' !== CatalogPresentation::language() || ! in_array( get_post_type( $post_id ), self::TYPES, true ) ) {
			return $title;
		}
		$english = self::approved( $post_id, self::TITLE_META );
		return '' !== trim( $english ) ? $english : ( preg_match( '/\p{Arabic}/u', $title ) ? 'Content #' . $post_id : $title );
	}

	/** Keep the browser tab title consistent with the approved public translation. */
	public static function document_title( array $parts ): array {
		if ( is_admin() || 'en' !== CatalogPresentation::language() || ! is_singular( self::TYPES ) ) {
			return $parts;
		}
		$post_id = get_queried_object_id();
		$parts['title'] = self::title( (string) ( $parts['title'] ?? '' ), $post_id );
		return $parts;
	}

	public static function content( string $content ): string {
		if ( is_admin() || 'en' !== CatalogPresentation::language() || ! is_singular( self::TYPES ) || get_queried_object_id() !== get_the_ID() ) {
			return $content;
		}
		$english = self::approved( get_the_ID(), self::CONTENT_META );
		if ( '' !== trim( $english ) ) {
			return $english;
		}
		if ( preg_match( '/\p{Arabic}/u', $content ) ) {
			return '<p class="adc-translation-notice" lang="en">The English translation is awaiting review.</p>';
		}
		return $content;
	}

	public static function approved( int $post_id, string $key ): string {
		if ( ! in_array( $key, array( self::TITLE_META, self::CONTENT_META ), true ) ) { return ''; }
		$post = get_post( $post_id );
		if ( ! $post ) { return ''; }
		$hash = (string) get_post_meta( $post_id, $key . '_source_hash', true );
		$source = self::TITLE_META === $key ? $post->post_title : $post->post_content;
		if ( '' !== $hash && ! hash_equals( $hash, hash( 'sha256', $source ) ) ) { return ''; }
		$copy = (string) get_post_meta( $post_id, $key, true );
		return preg_match( '/\p{Arabic}/u', strip_shortcodes( wp_strip_all_tags( $copy ) ) ) ? '' : $copy;
	}

	/** Legacy approved copies acquire the old source hash before the first subsequent edit. */
	public static function capture_legacy_source( int $id, \WP_Post $after, \WP_Post $before ): void {
		if ( ! in_array( $after->post_type, self::TYPES, true ) ) { return; }
		foreach ( array( self::TITLE_META => $before->post_title, self::CONTENT_META => $before->post_content ) as $key => $source ) {
			if ( '' !== (string) get_post_meta( $id, $key, true ) && '' === (string) get_post_meta( $id, $key . '_source_hash', true ) ) { update_post_meta( $id, $key . '_source_hash', hash( 'sha256', $source ) ); }
		}
	}

	public static function excerpt( string $excerpt, \WP_Post $post ): string {
		if ( is_admin() || 'en' !== CatalogPresentation::language() || ! in_array( $post->post_type, self::TYPES, true ) ) { return $excerpt; }
		$english = self::approved( $post->ID, self::CONTENT_META );
		if ( '' !== trim( $english ) ) { return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $english ) ), 35 ); }
		return preg_match( '/\p{Arabic}/u', $excerpt ) ? 'The English translation is awaiting review.' : $excerpt;
	}
}
