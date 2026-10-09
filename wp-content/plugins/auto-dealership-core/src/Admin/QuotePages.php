<?php
namespace AutoDealership\Admin;

use AutoDealership\Pricing\Money;
use AutoDealership\Pricing\QuoteDocument;
use AutoDealership\Pricing\QuoteHistory;

defined( 'ABSPATH' ) || exit;

final class QuotePages {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			if ( QuoteHistory::can_read() ) {
				add_menu_page( __( 'عروض الأسعار', 'auto-dealership-core' ), __( 'عروض الأسعار', 'auto-dealership-core' ), 'read', 'adc-quotes', array( self::class, 'render' ), 'dashicons-media-document', 59 );
			}
		} );
		add_action( 'admin_post_adc_print_quote', array( self::class, 'print_quote' ) );
	}

	public static function render(): void {
		if ( ! QuoteHistory::can_read() ) { wp_die( esc_html__( 'لا تملك صلاحية عرض عروض الأسعار.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$quote_id = absint( $_GET['quote_id'] ?? 0 );
		$versions = $quote_id ? QuoteHistory::versions( $quote_id ) : array();
		if ( is_wp_error( $versions ) ) { $versions = array(); }
		$quotes = QuoteHistory::list_for_current_user();
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'عروض الأسعار', 'auto-dealership-core' ); ?></h1>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'رقم العرض', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المركبة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'النسخة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الإجمالي', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الصلاحية', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $quotes as $quote ) : ?><tr><td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'adc-quotes', 'quote_id' => (int) $quote['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $quote['quote_number'] ); ?></a></td><td><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) ( $quote['translation_vehicle_id'] ?? $quote['vehicle_id'] ?? $quote['id'] ), 'brand', (string) $quote['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) ( $quote['translation_vehicle_id'] ?? $quote['vehicle_id'] ?? $quote['id'] ), 'model', (string) $quote['model'] ) ); ?></td><td><?php echo absint( $quote['version'] ); ?></td><td><?php echo esc_html( Money::display( (int) $quote['final_amount'] ) ); ?></td><td><?php echo esc_html( $quote['valid_until'] ); ?></td></tr><?php endforeach; ?>
		<?php if ( ! $quotes ) : ?><tr><td colspan="5"><?php
			if ( \AutoDealership\Security\BranchScope::is_global() ) {
				esc_html_e( 'لا توجد عروض أسعار مسجّلة حتى الآن.', 'auto-dealership-core' );
			} else {
				esc_html_e( 'لا توجد عروض أسعار ضمن نطاق وصولك.', 'auto-dealership-core' );
			}
		?></td></tr><?php endif; ?></tbody></table>
		<?php if ( $versions ) : ?><h2><?php esc_html_e( 'نسخ العرض', 'auto-dealership-core' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'النسخة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الإجمالي', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'وقت الحفظ', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الوثيقة', 'auto-dealership-core' ); ?></th></tr></thead><tbody><?php foreach ( $versions as $version ) :
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'adc_print_quote', 'quote_id' => (int) $version['quotation_id'], 'version' => (int) $version['version'] ), admin_url( 'admin-post.php' ) ), 'adc_print_quote_' . (int) $version['quotation_id'] . '_' . (int) $version['version'] ); ?>
		<tr><td><?php echo absint( $version['version'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $version['status'] ) ); ?></td><td><?php echo esc_html( Money::display( (int) $version['final_amount'] ) ); ?></td><td><?php echo esc_html( $version['created_at'] ); ?></td><td><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'طباعة / PDF', 'auto-dealership-core' ); ?></a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
		</div><?php
	}

	public static function print_quote(): void {
		$quote_id = absint( $_GET['quote_id'] ?? 0 );
		$version = absint( $_GET['version'] ?? 0 );
		check_admin_referer( 'adc_print_quote_' . $quote_id . '_' . $version );
		$quote = QuoteHistory::version( $quote_id, $version );
		if ( is_wp_error( $quote ) ) { wp_die( esc_html( $quote->get_error_message() ), '', array( 'response' => (int) ( $quote->get_error_data()['status'] ?? 403 ) ) ); }
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		echo QuoteDocument::render( $quote ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes every dynamic value.
		exit;
	}
}
