<?php
/** Synthetic PDF evidence for disposable test databases only. */
if(PHP_SAPI!=='cli'||!defined('DB_NAME')||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
function adc_fixture_onyx_invoice(int $sale_id,string $reference): array {
	global $wpdb;
	$row=$wpdb->get_row($wpdb->prepare('SELECT q.final_amount,q.tax_amount FROM '.\AutoDealership\Database\Schema::table('sales').' s INNER JOIN '.\AutoDealership\Database\Schema::table('quotations').' q ON q.id=s.quotation_id WHERE s.id=%d',$sale_id),ARRAY_A);
	$pdf="%PDF-1.4\n";$offsets=array(0);
	$objects=array('<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] >>');
	foreach($objects as $index=>$object){$offsets[]=strlen($pdf);$pdf.=($index+1)." 0 obj\n".$object."\nendobj\n";}
	$xref=strlen($pdf);$pdf.="xref\n0 4\n0000000000 65535 f \n";
	foreach(array_slice($offsets,1) as $offset){$pdf.=sprintf('%010d 00000 n ',$offset)."\n";}
	$pdf.="trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
	$result=\AutoDealership\Sales\OnyxDocuments::attach($sale_id,'invoice',$reference,gmdate('Y-m-d'),(int)$row['final_amount'],(int)$row['tax_amount'],$pdf,true);
	if(is_wp_error($result)){throw new RuntimeException('Synthetic invoice fixture failed: '.$result->get_error_code());}
	return $result;
}
