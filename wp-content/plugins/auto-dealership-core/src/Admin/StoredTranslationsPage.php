<?php
namespace AutoDealership\Admin;

use AutoDealership\Content\StoredTranslations;
use AutoDealership\Core\Localization;

defined( 'ABSPATH' ) || exit;

final class StoredTranslationsPage {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			add_submenu_page( 'adc-settings', __( 'Stored data translations', 'auto-dealership-core' ), __( 'Stored data translations', 'auto-dealership-core' ), 'manage_options', 'adc-stored-translations', array( self::class, 'render' ) );
		} );
		add_action( 'admin_post_adc_save_stored_translation', array( self::class, 'save' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ) ); }
		$type = isset( $_GET['entity'] ) && is_string( $_GET['entity'] ) ? sanitize_key( $_GET['entity'] ) : 'branches';
		if ( ! isset( StoredTranslations::FIELDS[$type] ) ) { $type = 'branches'; }
		$language = isset( $_GET['target'] ) && 'ar' === $_GET['target'] ? 'ar' : 'en';
		$page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$records = StoredTranslations::records( $type, $page );
		?>
		<div class="wrap" dir="<?php echo 'en' === Localization::language() ? 'ltr' : 'rtl'; ?>">
		<h1><?php esc_html_e( 'Stored data translations', 'auto-dealership-core' ); ?></h1>
		<p><?php esc_html_e( 'Review translations without changing original records. A translation needs review again when its original text changes. Customer names and messages are preserved.', 'auto-dealership-core' ); ?></p>
		<p><?php esc_html_e( 'Page, vehicle and offer titles and descriptions can be translated in their content editor.', 'auto-dealership-core' ); ?></p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Translation saved.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
		<form method="get"><input type="hidden" name="page" value="adc-stored-translations">
		<label><?php esc_html_e( 'Record type', 'auto-dealership-core' ); ?> <select name="entity"><?php foreach ( array_keys( StoredTranslations::FIELDS ) as $key ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( Localization::label( $key ) ); ?></option><?php endforeach; ?></select></label>
		<label><?php esc_html_e( 'Translation language', 'auto-dealership-core' ); ?> <select name="target"><option value="en" <?php selected( $language, 'en' ); ?>>English</option><option value="ar" <?php selected( $language, 'ar' ); ?>>العربية</option></select></label>
		<button class="button"><?php esc_html_e( 'عرض', 'auto-dealership-core' ); ?></button></form>
		<?php foreach ( $records as $record ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<h2>#<?php echo absint( $record['id'] ); ?></h2>
		<input type="hidden" name="action" value="adc_save_stored_translation"><input type="hidden" name="entity" value="<?php echo esc_attr( $type ); ?>"><input type="hidden" name="id" value="<?php echo absint( $record['id'] ); ?>"><input type="hidden" name="target" value="<?php echo esc_attr( $language ); ?>"><input type="hidden" name="paged" value="<?php echo absint( $page ); ?>">
		<?php wp_nonce_field( 'adc_translation_' . $type . '_' . $record['id'] ); ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Field', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Original text', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Approved translation', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( StoredTranslations::FIELDS[$type] as $field ) :
			$source = (string) ( $record[$field] ?? '' );
			if ( '' === trim( $source ) ) { continue; }
			$copy = StoredTranslations::copies( $type, (int) $record['id'] )[$language][$field] ?? array();
			$valid = '' !== StoredTranslations::approved( $type, (int) $record['id'], $field, $source, $language );
		?>
		<tr><th><label for="copy-<?php echo absint( $record['id'] ); ?>-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( Localization::label( $field ) ); ?></label></th><td dir="auto"><?php echo nl2br( esc_html( $source ) ); ?></td><td>
		<input type="hidden" name="hashes[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( StoredTranslations::fingerprint( $source ) ); ?>">
		<textarea id="copy-<?php echo absint( $record['id'] ); ?>-<?php echo esc_attr( $field ); ?>" class="widefat" rows="3" lang="<?php echo esc_attr( $language ); ?>" dir="<?php echo 'en' === $language ? 'ltr' : 'rtl'; ?>" name="copies[<?php echo esc_attr( $field ); ?>]"><?php echo esc_textarea( $copy['text'] ?? '' ); ?></textarea>
		<span><?php echo esc_html__( $valid ? 'Reviewed' : 'Needs translation review', 'auto-dealership-core' ); ?></span></td></tr>
		<?php endforeach; ?></tbody></table><p><button class="button button-primary"><?php esc_html_e( 'Save approved translations', 'auto-dealership-core' ); ?></button></p></form>
		<?php endforeach; ?>
		<p><?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'auto-dealership-core' ); ?></a><?php endif; ?> <?php if ( 25 === count( $records ) ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'auto-dealership-core' ); ?></a><?php endif; ?></p>
		</div><?php
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ) ); }
		$type = isset( $_POST['entity'] ) && is_string( $_POST['entity'] ) ? sanitize_key( $_POST['entity'] ) : '';
		$id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'adc_translation_' . $type . '_' . $id );
		$language = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? $_POST['target'] : '';
		$result = StoredTranslations::save( $type, $id, $language, is_array( $_POST['copies'] ?? null ) ? wp_unslash( $_POST['copies'] ) : array(), is_array( $_POST['hashes'] ?? null ) ? wp_unslash( $_POST['hashes'] ) : array() );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message( ) ), '', array( 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-stored-translations', 'entity' => $type, 'target' => $language, 'saved' => 1, 'paged' => isset( $_POST['paged'] ) && is_scalar( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1 ), admin_url( 'admin.php' ) ) ); exit;
	}
}
