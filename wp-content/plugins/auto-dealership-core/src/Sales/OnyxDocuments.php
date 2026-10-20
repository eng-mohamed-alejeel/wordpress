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
		// Navigation owns menu registration; the historical page slug stays valid.
		add_action('admin_post_adc_onyx_document',array(self::class,'submit'));
		add_action('admin_post_adc_download_onyx_document',array(self::class,'download'));
	}
	private static function can_read(): bool {return current_user_can('manage_options')||current_user_can('adc_view_finance')||current_user_can('adc_approve_sales');}
	/** Reject inaccessible records before WordPress emits the admin page header. */
	public static function validate_request(): void {
		if(!self::can_read()){wp_die('Forbidden','',array('response'=>403));}
		$id=absint($_GET['sale_id']??0);
		if($id && !self::sale($id)){wp_die('Sale unavailable','',array('response'=>404));}
	}
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
	/** Accessible sales index; never requires guessing a sale identifier. */
	public static function sales_page( int $page = 1, string $search = '' ) {
		global $wpdb;
		if ( ! self::can_read() ) { return new \WP_Error( 'adc_document_forbidden' ); }
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		$search = mb_substr( sanitize_text_field( $search ), 0, 100 );
		if ( '' !== $search ) {
			$scope .= ' AND (c.full_name LIKE %s OR v.stock_number LIKE %s OR s.invoice_reference LIKE %s)';
			$like = '%' . $wpdb->esc_like( $search ) . '%'; $args = array_merge( $args, array( $like, $like, $like ) );
		}
		$from = Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=s.customer_id';
		$sql = "SELECT COUNT(*) FROM $from WHERE $scope";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_document_list_unavailable' ); }
		$pages = max( 1, (int) ceil( $total / 20 ) ); $page = min( $pages, max( 1, $page ) );
		$sql = 'SELECT s.id,s.status,s.created_at,c.full_name,v.stock_number,(SELECT COUNT(*) FROM ' . Schema::table( 'onyx_documents' ) . " doc WHERE doc.sale_id=s.id) documents FROM $from WHERE $scope ORDER BY s.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( 20, ( $page - 1 ) * 20 ) ) ), ARRAY_A ) ?: array();
		return $wpdb->last_error ? new \WP_Error( 'adc_document_list_unavailable' ) : compact( 'rows','total','page','pages','search' );
	}

	private static function render_sales(): void {
		$search = isset( $_GET['document_search'] ) && is_string( $_GET['document_search'] ) ? wp_unslash( $_GET['document_search'] ) : '';
		$data = self::sales_page( absint( $_GET['document_page'] ?? 1 ), $search );
		echo '<h2>' . esc_html( self::text( 'اختيار البيع', 'Select a sale' ) ) . '</h2>';
		if ( is_wp_error( $data ) ) { echo '<p role="alert">' . esc_html( self::text( 'تعذر تحميل المبيعات. أعد المحاولة.', 'Could not load sales. Try again.' ) ) . '</p>'; return; }
		echo '<form method="get"><input type="hidden" name="page" value="adc-onyx-documents"><label>' . esc_html( self::text( 'بحث العميل أو رقم المخزون أو مرجع الفاتورة', 'Search customer, stock number or invoice reference' ) ) . ' <input type="search" name="document_search" maxlength="100" value="' . esc_attr( $data['search'] ) . '"></label> <button class="button">' . esc_html( self::text( 'بحث', 'Search' ) ) . '</button></form><p>' . esc_html( self::text( 'إجمالي المبيعات ضمن نطاقك: ', 'Sales within your scope: ' ) . number_format_i18n( $data['total'] ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'البيع', 'Sale' ) ) . '</th><th>' . esc_html( self::text( 'العميل / رقم المخزون', 'Customer / stock number' ) ) . '</th><th>' . esc_html( self::text( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( self::text( 'المستندات', 'Documents' ) ) . '</th><th>' . esc_html( self::text( 'المتابعة', 'Follow up' ) ) . '</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			$url = add_query_arg( array( 'page'=>'adc-onyx-documents', 'sale_id'=>(int) $row['id'], 'document_search'=>$data['search'], 'document_page'=>$data['page'] ), admin_url( 'admin.php' ) );
			echo '<tr><td>#' . absint( $row['id'] ) . '</td><td>' . esc_html( $row['full_name'] . ' / ' . $row['stock_number'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( $row['status'] ) ) . '</td><td>' . absint( $row['documents'] ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">' . esc_html( self::text( 'فتح المستندات', 'Open documents' ) ) . '</a></td></tr>';
		}
		if ( ! $data['rows'] ) { echo '<tr><td colspan="5">' . esc_html( self::text( 'لا توجد مبيعات مطابقة ضمن صلاحياتك.', 'No matching sales within your permissions.' ) ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( $data['pages'] > 1 ) { $base = add_query_arg( array( 'page'=>'adc-onyx-documents', 'document_search'=>$data['search'], 'document_page'=>999999999 ), admin_url( 'admin.php' ) ); echo '<nav aria-label="' . esc_attr( self::text( 'صفحات المبيعات', 'Sales pages' ) ) . '">' . wp_kses_post( paginate_links( array( 'base'=>str_replace( '999999999', '%#%', $base ), 'format'=>'', 'current'=>$data['page'], 'total'=>$data['pages'] ) ) ) . '</nav>'; }
	}

	public static function render(): void {
		self::validate_request();
		global $wpdb;
		$id=absint($_GET['sale_id']??0);
		if(!self::can_read()){wp_die('Forbidden','',array('response'=>403));}
		echo '<div class="wrap"><h1>'.esc_html(self::text('مستندات أونكس المالية','Onyx financial documents')).'</h1><p>'.esc_html(self::text('أونكس مصدر الإصدار. ارفع نسخة PDF الأصلية بعد مطابقة العميل والمركبة والمبالغ. ربط مستند لا يسجّل دفعة أو استردادًا.','Onyx is the issuer. Upload the original PDF after verifying the customer, vehicle and amounts. Linking a document does not record a payment or refund.')).'</p>';
		if(!$id){self::render_sales();echo '</div>';return;}
		echo '<p><a class="button" href="'.esc_url(remove_query_arg(array('sale_id','error'))).'">'.esc_html(self::text('العودة إلى قائمة المبيعات','Back to sales')).'</a></p>';
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
