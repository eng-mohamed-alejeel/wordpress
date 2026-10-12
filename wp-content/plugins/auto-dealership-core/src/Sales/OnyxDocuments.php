<?php
namespace AutoDealership\Sales;

use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Security\BranchScope;
use AutoDealership\Pricing\Money;

defined('ABSPATH') || exit;

/** Onyx issues official documents; this private archive preserves verified source files. */
final class OnyxDocuments {
	public const MAX_BYTES=5242880;
	private static function text(string $ar,string $en): string {return \AutoDealership\Core\Localization::language()==='en'?$en:$ar;}
	public static function boot(): void {
		add_action('admin_menu',static function():void{if(self::can_read()){add_submenu_page('adc-quotes',self::text('مستندات أونكس المالية','Onyx financial documents'),self::text('مستندات أونكس المالية','Onyx financial documents'),'read','adc-onyx-documents',array(self::class,'render'));}});
		add_action('admin_post_adc_onyx_document',array(self::class,'submit'));
		add_action('admin_post_adc_download_onyx_document',array(self::class,'download'));
	}
	private static function can_read(): bool {return current_user_can('manage_options')||current_user_can('adc_view_finance')||current_user_can('adc_approve_sales');}
	private static function sale(int $id,bool $lock=false) {
		global $wpdb;
		if(!self::can_read()){return null;}
		$row=$wpdb->get_row($wpdb->prepare('SELECT s.*,v.branch_id,q.final_amount,q.tax_amount FROM '.Schema::table('sales').' s INNER JOIN '.Schema::table('vehicles').' v ON v.id=s.vehicle_id INNER JOIN '.Schema::table('quotations').' q ON q.id=s.quotation_id WHERE s.id=%d'.($lock?' FOR UPDATE':''),$id),ARRAY_A);
		return $row&&BranchScope::allows((int)$row['branch_id'])?$row:null;
	}
	public static function attach(int $sale_id,string $kind,string $reference,string $date,int $total,int $tax,string $pdf,bool $verified,int $parent_id=0) {
		global $wpdb;
		if(!current_user_can('adc_approve_sales')&&!current_user_can('adc_record_payments')&&!current_user_can('manage_options')){return new \WP_Error('adc_document_forbidden');}
		$reference=strtoupper(trim(sanitize_text_field($reference)));
		$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
		if(!in_array($kind,array('invoice','credit_note','contract'),true)||!$verified||!$parsed||$parsed->format('Y-m-d')!==$date||$date>gmdate('Y-m-d')||$reference===''||mb_strlen($reference)>100||strlen($pdf)>self::MAX_BYTES||strlen($pdf)<8||!str_starts_with($pdf,'%PDF-')||$total<1||$tax<0||$tax>$total){return new \WP_Error('adc_document_invalid');}
		if(!Transaction::begin()){return new \WP_Error('adc_document_storage');}
		$sale=self::sale($sale_id,true);
		if(!$sale){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_forbidden');}
		if('invoice'===$kind&&($total!==(int)$sale['final_amount']||$tax!==(int)$sale['tax_amount'])){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_amount_mismatch');}
		$table=Schema::table('onyx_documents');
		$hash=hash('sha256',$pdf);$parent_id='credit_note'===$kind?$parent_id:0;$key=hash('sha256','onyx|'.$kind.'|'.$reference);
		$existing=$wpdb->get_row($wpdb->prepare("SELECT id,sale_id,checksum,document_date,total_amount,tax_amount,parent_id FROM $table WHERE reference_key=%s FOR UPDATE",$key),ARRAY_A);
		if($wpdb->last_error){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_storage');}
		if($existing){$wpdb->query('ROLLBACK');return (int)$existing['sale_id']===$sale_id&&hash_equals($existing['checksum'],$hash)&&$existing['document_date']===$date&&(int)$existing['total_amount']===$total&&(int)$existing['tax_amount']===$tax&&(int)$existing['parent_id']===$parent_id?array('id'=>(int)$existing['id'],'existing'=>true):new \WP_Error('adc_document_duplicate');}
		if('credit_note'===$kind){
			$parent=$wpdb->get_row($wpdb->prepare("SELECT id,total_amount FROM $table WHERE id=%d AND sale_id=%d AND document_kind='invoice' FOR UPDATE",$parent_id,$sale_id),ARRAY_A);
			$credited=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_amount),0) FROM $table WHERE parent_id=%d AND document_kind='credit_note'",$parent_id));
			if($wpdb->last_error){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_storage');}
			if(!$parent||$total>(int)$parent['total_amount']-$credited){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_parent');}
		}else{$parent_id=0;}

		if('invoice'===$kind&&$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE sale_id=%d AND document_kind='invoice' LIMIT 1",$sale_id))){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_invoice_exists');}
		if($wpdb->last_error){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_storage');}
		$ok=$wpdb->insert($table,array('sale_id'=>$sale_id,'branch_id'=>$sale['branch_id'],'document_kind'=>$kind,'reference'=>$reference,'reference_key'=>$key,'document_date'=>$date,'total_amount'=>$total,'tax_amount'=>$tax,'parent_id'=>$parent_id,'checksum'=>$hash,'file_bytes'=>$pdf,'verified_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)));
		$id=(int)$wpdb->insert_id;
		if(1!==$ok||!Transaction::commit(static fn()=>AuditLog::record('onyx.document_linked','onyx_document',$id,'Source PDF and amounts verified by staff',null,array('sale_id'=>$sale_id,'document_kind'=>$kind,'checksum'=>$hash)))){$wpdb->query('ROLLBACK');return new \WP_Error('adc_document_storage');}
		return array('id'=>$id,'existing'=>false);
	}
	/** Called under the sale approval lock; legacy approved sales are never rewritten. */
	public static function invoice_matches(int $sale_id,string $reference,int $total,int $tax): bool {
		global $wpdb;
		$row=$wpdb->get_row($wpdb->prepare('SELECT checksum,file_bytes,total_amount,tax_amount FROM '.Schema::table('onyx_documents')." WHERE sale_id=%d AND document_kind='invoice' AND reference=%s FOR UPDATE",$sale_id,strtoupper(trim($reference))),ARRAY_A);
		return $row&&(int)$row['total_amount']===$total&&(int)$row['tax_amount']===$tax&&hash_equals($row['checksum'],hash('sha256',$row['file_bytes']));
	}
	public static function render(): void {
		global $wpdb;
		$id=absint($_GET['sale_id']??0);
		if(!self::can_read()){wp_die('Forbidden','',array('response'=>403));}
		echo '<div class="wrap"><h1>'.esc_html(self::text('مستندات أونكس المالية','Onyx financial documents')).'</h1><p>'.esc_html(self::text('أونكس مصدر الإصدار. ارفع نسخة PDF الأصلية بعد مطابقة العميل والمركبة والمبالغ. ربط مستند لا يسجّل دفعة أو استردادًا.','Onyx is the issuer. Upload the original PDF after verifying the customer, vehicle and amounts. Linking a document does not record a payment or refund.')).'</p>';
		if(!$id){echo '<form method="get"><input type="hidden" name="page" value="adc-onyx-documents"><label>'.esc_html(self::text('رقم البيع','Sale ID')).' <input type="number" min="1" required name="sale_id"></label><button class="button">'.esc_html(self::text('فتح','Open')).'</button></form></div>';return;}
		$sale=self::sale($id);if(!$sale){wp_die('Sale unavailable','',array('response'=>404));}
		if(isset($_GET['error'])){echo '<p role="alert">'.esc_html(self::text('تعذر حفظ المستند: ','Unable to save document: ').sanitize_key($_GET['error'])).'</p>';}
		$rows=$wpdb->get_results($wpdb->prepare('SELECT id,document_kind,reference,document_date,checksum FROM '.Schema::table('onyx_documents').' WHERE sale_id=%d ORDER BY id DESC',$id),ARRAY_A)?:array();
		echo '<h2>#'.absint($id).'</h2>';
		foreach($rows as $row){$url=wp_nonce_url(add_query_arg(array('action'=>'adc_download_onyx_document','id'=>$row['id']),admin_url('admin-post.php')),'adc_download_onyx_document_'.$row['id']);echo '<p><a href="'.esc_url($url).'">'.esc_html($row['document_kind'].' / '.$row['reference'].' / '.$row['document_date']).'</a> <code>'.esc_html($row['checksum']).'</code></p>';}
		if(current_user_can('adc_approve_sales')||current_user_can('adc_record_payments')||current_user_can('manage_options')){
			echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_onyx_document"><input type="hidden" name="sale_id" value="'.absint($id).'">';wp_nonce_field('adc_onyx_document_'.$id);
			echo '<p><label>'.esc_html(self::text('نوع المستند','Document type')).' <select name="kind">';foreach(array('invoice'=>self::text('فاتورة','Invoice'),'credit_note'=>self::text('إشعار دائن','Credit note'),'contract'=>self::text('عقد','Contract')) as $key=>$label){echo '<option value="'.$key.'">'.esc_html($label).'</option>';}echo '</select></label></p>';
			foreach(array('reference'=>array(self::text('مرجع أونكس','Onyx reference'),'text',''),'date'=>array(self::text('تاريخ المستند','Document date'),'date',gmdate('Y-m-d')),'total'=>array(self::text('الإجمالي (ريال)','Total (SAR)'),'number',Money::decimal($sale['final_amount'])),'tax'=>array(self::text('الضريبة (ريال)','Tax (SAR)'),'number',Money::decimal($sale['tax_amount']))) as $key=>$field){echo '<p><label>'.esc_html($field[0]).' <input required name="'.$key.'" type="'.$field[1].'" value="'.esc_attr($field[2]).'"'.($field[1]==='number'?' min="0" step="0.01"':'').'></label></p>';}
			echo '<p><label>'.esc_html(self::text('رقم الفاتورة المؤرشفة للإشعار الدائن','Archived invoice ID for credit note')).' <input type="number" name="parent_id" min="1"></label></p><p><input type="file" name="document" accept="application/pdf" required></p><p><label><input type="checkbox" name="verified" value="1" required> '.esc_html(self::text('طابقت المستند الأصلي مع العميل والمركبة والمرجع والمبالغ.','I verified the original document against the customer, vehicle, reference and amounts.')).'</label></p><button class="button button-primary">'.esc_html(self::text('حفظ النسخة وربطها','Archive and link')).'</button></form>';
		}
		echo '</div>';
	}
	public static function submit(): void {
		$id=absint($_POST['sale_id']??0);check_admin_referer('adc_onyx_document_'.$id);
		$file=$_FILES['document']??array();$input=wp_unslash($_POST);
		$total=Money::from_sar($input['total']??'');$tax=Money::from_sar($input['tax']??'');
		if(($file['error']??-1)!==UPLOAD_ERR_OK||($file['size']??0)>self::MAX_BYTES||!is_uploaded_file($file['tmp_name']??'')||null===$total||null===$tax){$result=new \WP_Error('adc_document_upload');}
		else{$pdf=file_get_contents($file['tmp_name']);$result=self::attach($id,sanitize_key($input['kind']??''),$input['reference']??'',$input['date']??'',$total,$tax,$pdf?:'',!empty($input['verified']),absint($input['parent_id']??0));}
		wp_safe_redirect(add_query_arg(array('page'=>'adc-onyx-documents','sale_id'=>$id,is_wp_error($result)?'error':'saved'=>is_wp_error($result)?$result->get_error_code():1),admin_url('admin.php')));exit;
	}
	public static function download(): void {
		global $wpdb;
		$id=absint($_GET['id']??0);check_admin_referer('adc_download_onyx_document_'.$id);
		$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('onyx_documents').' WHERE id=%d',$id),ARRAY_A);
		if(!$row||!self::sale((int)$row['sale_id'])||!hash_equals($row['checksum'],hash('sha256',$row['file_bytes']))){wp_die('Document unavailable','',array('response'=>404));}
		nocache_headers();header('Content-Type: application/pdf');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="onyx-'.$id.'.pdf"');header('Content-Length: '.strlen($row['file_bytes']));echo $row['file_bytes'];exit;
	}
}
