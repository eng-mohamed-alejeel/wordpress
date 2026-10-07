<?php
namespace AutoDealership\Content;

use AutoDealership\Core\Localization;

defined( 'ABSPATH' ) || exit;

/** Display names only: taxonomy IDs, names used for editing, and filter slugs stay stable. */
final class TermTranslations {
	private const TYPES = array( 'car_brand', 'car_category' );
	public static function boot(): void {
		foreach ( self::TYPES as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( self::class, 'fields' ) );
			add_action( 'edited_' . $taxonomy, array( self::class, 'save' ) );
		}
		add_filter( 'get_term', array( self::class, 'display' ), 20 );
		add_filter( 'get_terms', array( self::class, 'terms' ), 20 );
	}
	public static function fields( \WP_Term $term ): void {
		wp_nonce_field( 'adc_term_translation_' . $term->term_id, 'adc_term_translation_nonce' );
		foreach ( array( 'ar' => 'Arabic name', 'en' => 'English name' ) as $language => $label ) {
			echo '<tr class="form-field"><th><label for="adc-term-' . esc_attr( $language ) . '">' . esc_html__( $label, 'auto-dealership-core' ) . '</label></th><td><input id="adc-term-' . esc_attr( $language ) . '" name="adc_term_' . esc_attr( $language ) . '" lang="' . esc_attr( $language ) . '" dir="' . ( 'en' === $language ? 'ltr' : 'rtl' ) . '" value="' . esc_attr( get_term_meta( $term->term_id, '_adc_name_' . $language, true ) ) . '"></td></tr>';
		}
		echo '<tr class="form-field"><th>' . esc_html__( 'Reviewed', 'auto-dealership-core' ) . '</th><td><input type="hidden" name="adc_term_source_hash" value="' . esc_attr( hash( 'sha256', $term->name ) ) . '"><label><input type="checkbox" name="adc_term_reviewed" value="1"> ' . esc_html__( 'Save approved translations', 'auto-dealership-core' ) . '</label></td></tr>';
	}
	public static function save( int $id ): void {
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) || ! in_array( $term->taxonomy, self::TYPES, true ) ) { return; }
		$taxonomy = get_taxonomy( $term->taxonomy );
		$nonce = isset( $_POST['adc_term_translation_nonce'] ) && is_string( $_POST['adc_term_translation_nonce'] ) ? wp_unslash( $_POST['adc_term_translation_nonce'] ) : '';
		if ( ! current_user_can( $taxonomy->cap->manage_terms ) || ! wp_verify_nonce( $nonce, 'adc_term_translation_' . $id ) ) { return; }
		foreach ( array( 'ar', 'en' ) as $language ) {
			if ( ! isset( $_POST['adc_term_' . $language] ) || ! is_string( $_POST['adc_term_' . $language] ) ) { continue; }
			$value = sanitize_text_field( wp_unslash( $_POST['adc_term_' . $language] ) );
			$reviewed = $value !== (string) get_term_meta( $id, '_adc_name_' . $language, true ) || '1' === ( $_POST['adc_term_reviewed'] ?? '' );
			update_term_meta( $id, '_adc_name_' . $language, $value );
			if ( $reviewed ) { update_term_meta( $id, '_adc_name_' . $language . '_source_hash', hash( 'sha256', $term->name ) ); }
			elseif ( '' === (string) get_term_meta( $id, '_adc_name_' . $language . '_source_hash', true ) && is_string( $_POST['adc_term_source_hash'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/', $_POST['adc_term_source_hash'] ) ) { update_term_meta( $id, '_adc_name_' . $language . '_source_hash', $_POST['adc_term_source_hash'] ); }
		}
	}
	/**
	 * @param mixed $term
	 */
	public static function display( $term ) {
		if ( ! $term instanceof \WP_Term || is_admin() || ! in_array( $term->taxonomy, self::TYPES, true ) ) { return $term; }
		$language = Localization::language();
		$value = (string) get_term_meta( $term->term_id, '_adc_name_' . $language, true );
		if ( '' !== trim( $value ) && $value === $term->name && ( 'en' !== $language || ! preg_match( '/\p{Arabic}/u', $value ) ) ) { return $term; }
		$hash = (string) get_term_meta( $term->term_id, '_adc_name_' . $language . '_source_hash', true );
		$name = '' !== trim( $value ) && ( '' === $hash || hash_equals( $hash, hash( 'sha256', $term->name ) ) ) ? $value : Localization::label( $term->name );
		if ( 'en' === $language && preg_match( '/\p{Arabic}/u', $name ) ) { $name = 'Category #' . $term->term_id; }
		$copy = clone $term; $copy->name = $name; return $copy;
	}
	/**
	 * @param mixed $terms
	 */
	public static function terms( $terms ) {
		if ( ! is_array( $terms ) ) { return $terms; }
		return array_map( array( self::class, 'display' ), $terms );
	}
}
