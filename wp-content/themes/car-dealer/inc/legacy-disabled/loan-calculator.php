<?php
/**
 * حاسبة القروض لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إنشاء widget لحاسبة القروض
 */
function car_dealer_loan_calculator_widget() {
	register_widget( 'Car_Dealer_Loan_Calculator_Widget' );
}
add_action( 'widgets_init', 'car_dealer_loan_calculator_widget' );

/**
 * widget حاسبة القروض
 */
class Car_Dealer_Loan_Calculator_Widget extends WP_Widget {

	/**
	 * إعداد الـ widget
	 */
	public function __construct() {
		parent::__construct(
			'car_dealer_loan_calculator',
			__( 'حاسبة القروض', 'car-dealer' ),
			array( 'description' => __( 'أداة لحساب القروض للسيارات', 'car-dealer' ) )
		);
	}

	/**
	 * عرض الـ widget في الواجهة الأمامية
	 */
	public function widget( $args, $instance ) {
		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		?>
		<div class="loan-calculator-widget">
			<form id="loan-calculator-form">
				<div class="form-group">
					<label for="car-price"><?php _e( 'سعر السيارة (ريال)', 'car-dealer' ); ?></label>
					<input type="number" id="car-price" min="0" step="1000" value="100000">
				</div>

				<div class="form-group">
					<label for="down-payment"><?php _e( 'دفعة أولى (ريال)', 'car-dealer' ); ?></label>
					<input type="number" id="down-payment" min="0" step="1000" value="20000">
				</div>

				<div class="form-group">
					<label for="loan-term"><?php _e( 'مدة القرض (سنوات)', 'car-dealer' ); ?></label>
					<input type="number" id="loan-term" min="1" max="10" value="5">
				</div>

				<div class="form-group">
					<label for="interest-rate"><?php _e( 'معدل الفائدة (%)', 'car-dealer' ); ?></label>
					<input type="number" id="interest-rate" min="0" max="30" step="0.1" value="5">
				</div>

				<button type="submit" class="btn"><?php _e( 'حساب', 'car-dealer' ); ?></button>
			</form>

			<div class="loan-results" style="display: none;">
				<div class="result-item">
					<span class="result-label"><?php _e( 'مبلغ القرض', 'car-dealer' ); ?>:</span>
					<span class="result-value" id="loan-amount">0</span>
				</div>

				<div class="result-item">
					<span class="result-label"><?php _e( 'القسط الشهري', 'car-dealer' ); ?>:</span>
					<span class="result-value" id="monthly-payment">0</span>
				</div>

				<div class="result-item">
					<span class="result-label"><?php _e( 'إجمالي الفوائد', 'car-dealer' ); ?>:</span>
					<span class="result-value" id="total-interest">0</span>
				</div>

				<div class="result-item">
					<span class="result-label"><?php _e( 'إجمالي المبلغ', 'car-dealer' ); ?>:</span>
					<span class="result-value" id="total-amount">0</span>
				</div>
			</div>
		</div>
		<?php

		echo $args['after_widget'];
	}

	/**
	 * إعداد النموذج في لوحة التحكم
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'حاسبة القروض', 'car-dealer' );
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'العنوان:', 'car-dealer' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}

	/**
	 * حفظ الإعدادات
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';

		return $instance;
	}
}

/**
 * إضافة كود JavaScript لحساب القروض
 */
function car_dealer_loan_calculator_script() {
	?>
	<script>
	jQuery(document).ready(function($) {
		$('#loan-calculator-form').on('submit', function(e) {
			e.preventDefault();

			// الحصول على القيم من النموذج
			var carPrice = parseFloat($('#car-price').val()) || 0;
			var downPayment = parseFloat($('#down-payment').val()) || 0;
			var loanTerm = parseInt($('#loan-term').val()) || 0;
			var interestRate = parseFloat($('#interest-rate').val()) || 0;

			// حساب القرض
			var loanAmount = carPrice - downPayment;
			var monthlyInterestRate = interestRate / 100 / 12;
			var numberOfPayments = loanTerm * 12;

			// حساب القسط الشهري باستخدام صيغة القرض
			var monthlyPayment = loanAmount * monthlyInterestRate * Math.pow(1 + monthlyInterestRate, numberOfPayments) / (Math.pow(1 + monthlyInterestRate, numberOfPayments) - 1);

			// حساب إجمالي الفوائد وإجمالي المبلغ
			var totalInterest = (monthlyPayment * numberOfPayments) - loanAmount;
			var totalAmount = loanAmount + totalInterest;

			// عرض النتائج
			$('#loan-amount').text(loanAmount.toFixed(2) + ' ريال');
			$('#monthly-payment').text(monthlyPayment.toFixed(2) + ' ريال');
			$('#total-interest').text(totalInterest.toFixed(2) + ' ريال');
			$('#total-amount').text(totalAmount.toFixed(2) + ' ريال');

			// إظهار قسم النتائج
			$('.loan-results').slideDown();
		});
	});
	</script>
	<?php
}
add_action( 'wp_footer', 'car_dealer_loan_calculator_script' );
