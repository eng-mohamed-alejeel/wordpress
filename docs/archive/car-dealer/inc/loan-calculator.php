<?php
/** Loan calculator migrated from the legacy widget into a shortcode/component. */
defined( 'ABSPATH' ) || exit;

function car_dealer_loan_calculator_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'price' => 0 ), $atts, 'car_dealer_loan_calculator' );
	$price = absint( $atts['price'] );
	$model = class_exists( '\\AutoDealership\\Tools\\LoanCalculator' )
		? \AutoDealership\Tools\LoanCalculator::view_model( $price )
		: array( 'price'=>$price, 'down_payment'=>0, 'annual_rate'=>'4.50', 'months'=>60, 'max_amount'=>100000000, 'max_months'=>120, 'max_rate'=>'100.00' );
	ob_start();
	?>
	<section class="cd-tool cd-loan-calculator">
		<h2><?php esc_html_e( 'حاسبة التمويل', 'car-dealer' ); ?></h2>
		<form data-loan-calculator>
			<div class="cd-form-grid">
				<label><?php esc_html_e( 'سعر السيارة', 'car-dealer' ); ?><input type="number" name="price" data-loan-price value="<?php echo esc_attr( $model['price'] ); ?>" min="1" max="<?php echo esc_attr( $model['max_amount'] ); ?>" required></label>
				<label><?php esc_html_e( 'الدفعة الأولى', 'car-dealer' ); ?><input type="number" name="down_payment" data-loan-down value="<?php echo esc_attr( $model['down_payment'] ); ?>" min="0" max="<?php echo esc_attr( $model['max_amount'] ); ?>" required></label>
				<label><?php esc_html_e( 'النسبة السنوية التقديرية %', 'car-dealer' ); ?><input type="number" name="annual_rate" data-loan-rate value="<?php echo esc_attr( $model['annual_rate'] ); ?>" min="0" max="<?php echo esc_attr( $model['max_rate'] ); ?>" step="0.01" required></label>
				<label><?php esc_html_e( 'المدة بالأشهر', 'car-dealer' ); ?><input type="number" name="months" data-loan-months value="<?php echo esc_attr( $model['months'] ); ?>" min="1" max="<?php echo esc_attr( $model['max_months'] ); ?>" required></label>
			</div>
			<button class="btn btn-primary" type="submit"><?php esc_html_e( 'احسب', 'car-dealer' ); ?></button>
			<p class="cd-loan-result"><?php esc_html_e( 'القسط الشهري التقديري:', 'car-dealer' ); ?> <strong data-loan-result>0 SAR</strong></p>
			<p data-loan-status role="status"></p>
			<small><?php esc_html_e( 'هذا تقدير إرشادي وليس عرض تمويل أو موافقة. تعتمد الشروط النهائية على مزود التمويل.', 'car-dealer' ); ?></small>
		</form>
	</section>
	<?php
	return ob_get_clean();
}
if ( ! shortcode_exists( 'car_dealer_loan_calculator' ) ) {
	add_shortcode( 'car_dealer_loan_calculator', 'car_dealer_loan_calculator_shortcode' );
}
