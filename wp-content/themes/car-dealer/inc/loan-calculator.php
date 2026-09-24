<?php
/** Loan calculator migrated from the legacy widget into a shortcode/component. */
defined( 'ABSPATH' ) || exit;

function car_dealer_loan_calculator_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'price' => 0 ), $atts, 'car_dealer_loan_calculator' );
	$price = absint( $atts['price'] );
	ob_start();
	?>
	<section class="cd-tool cd-loan-calculator" data-loan-calculator>
		<h2><?php esc_html_e( 'حاسبة التمويل', 'car-dealer' ); ?></h2>
		<div class="cd-form-grid">
			<label><?php esc_html_e( 'سعر السيارة', 'car-dealer' ); ?><input type="number" data-loan-price value="<?php echo esc_attr( $price ); ?>" min="0"></label>
			<label><?php esc_html_e( 'الدفعة الأولى', 'car-dealer' ); ?><input type="number" data-loan-down value="0" min="0"></label>
			<label><?php esc_html_e( 'نسبة الفائدة %', 'car-dealer' ); ?><input type="number" data-loan-rate value="4.5" min="0" step="0.1"></label>
			<label><?php esc_html_e( 'المدة بالأشهر', 'car-dealer' ); ?><input type="number" data-loan-months value="60" min="1"></label>
		</div>
		<p class="cd-loan-result"><?php esc_html_e( 'القسط المتوقع:', 'car-dealer' ); ?> <strong data-loan-result>0</strong></p>
	</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'car_dealer_loan_calculator', 'car_dealer_loan_calculator_shortcode' );
