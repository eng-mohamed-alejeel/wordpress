<?php
namespace AutoDealership\Admin;

use AutoDealership\Payments\RefundService;

defined( 'ABSPATH' ) || exit;

final class RefundPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_request_refund', array( self::class, 'request' ) );
		add_action( 'admin_post_adc_decide_refund', array( self::class, 'decide' ) );
	}
	public static function menu(): void { add_submenu_page( 'adc-finance', __( 'Refunds', 'auto-dealership-core' ), __( 'Refunds', 'auto-dealership-core' ), 'adc_view_finance', 'adc-refunds', array( self::class, 'render' ) ); }
	public static function render(): void {
		if(!current_user_can('adc_view_finance')){wp_die('', '',array('response'=>403));}
		$returns=RefundService::refundable_returns();$cancellations=RefundService::refundable_cancellations();$refunds=RefundService::list_for_current_user(); ?>
		<div class="wrap" dir="rtl"><h1>الاستردادات المالية</h1><p>توثق هذه الشاشة استردادًا منفذًا خارج النظام، ولا تنقل أموالًا.</p>
		<?php if(current_user_can('adc_record_refunds')):?><form class="card" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="adc_request_refund"><?php wp_nonce_field('adc_request_refund');?><select name="return_id" required><option value="">الإرجاع</option><?php foreach($returns as $row):?><option value="<?php echo absint($row['id']);?>"><?php echo esc_html($row['stock_number'].' — #'.$row['sale_id']);?></option><?php endforeach;?></select><input type="number" min="1" step="1" name="amount" required placeholder="المبلغ بالهللات"><select name="method"><option value="cash_refund">نقدي</option><option value="bank_transfer">تحويل بنكي</option><option value="finance_reversal">عكس تمويل</option></select><input name="reference" maxlength="100" required placeholder="مرجع الاسترداد"><button class="button button-primary">إرسال للمراجعة</button></form><?php endif;?>
		<?php if(current_user_can('adc_record_refunds')&&$cancellations):?><form class="card" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="adc_request_refund"><?php wp_nonce_field('adc_request_refund');?><select name="cancellation_id" required><option value="">إلغاء البيع</option><?php foreach($cancellations as $row):?><option value="<?php echo absint($row['id']);?>"><?php echo esc_html($row['stock_number'].' — #'.$row['sale_id']);?></option><?php endforeach;?></select><input type="number" min="1" step="1" name="amount" required placeholder="المبلغ بالهللات"><select name="method"><option value="cash_refund">نقدي</option><option value="bank_transfer">تحويل بنكي</option><option value="finance_reversal">عكس تمويل</option></select><input name="reference" maxlength="100" required placeholder="مرجع الاسترداد"><button class="button button-primary">استرداد إلغاء البيع</button></form><?php endif;?>
		<table class="widefat striped"><thead><tr><th>المركبة</th><th>المبلغ</th><th>المرجع</th><th>الحالة</th><th>القرار</th></tr></thead><tbody><?php foreach($refunds as $row):?><tr><td><?php echo esc_html($row['stock_number']);?></td><td><?php echo esc_html(number_format_i18n((int)$row['amount']));?></td><td><?php echo esc_html($row['reference']);?></td><td><?php echo esc_html($row['status']);?></td><td><?php if(current_user_can('adc_verify_refunds')&&'pending'===$row['status']&&(int)$row['requested_by']!==get_current_user_id()):?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="adc_decide_refund"><input type="hidden" name="id" value="<?php echo absint($row['id']);?>"><?php wp_nonce_field('adc_decide_refund_'.(int)$row['id']);?><select name="decision"><option value="approve">اعتماد</option><option value="reject">رفض</option></select><input name="reason" required placeholder="سبب القرار"><button class="button">حفظ</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php
	}
	private static function input(string $key):string{return isset($_POST[$key])&&is_string($_POST[$key])?wp_unslash($_POST[$key]):'';}
	private static function redirect($result):void{wp_safe_redirect(add_query_arg(is_wp_error($result)?'error':'saved','1',admin_url('admin.php?page=adc-refunds')));exit;}
	public static function request():void{check_admin_referer('adc_request_refund');$cancellation=absint(self::input('cancellation_id'));$result=$cancellation?RefundService::request_cancellation($cancellation,absint(self::input('amount')),self::input('method'),self::input('reference')):RefundService::request(absint(self::input('return_id')),absint(self::input('amount')),self::input('method'),self::input('reference'));self::redirect($result);}
	public static function decide():void{$id=absint(self::input('id'));check_admin_referer('adc_decide_refund_'.$id);$decision=self::input('decision');self::redirect(in_array($decision,array('approve','reject'),true)?RefundService::decide($id,'approve'===$decision,self::input('reason')):new \WP_Error('adc_invalid_refund_decision'));}
}
