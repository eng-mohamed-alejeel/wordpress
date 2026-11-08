<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Renders one immutable quote revision as a standalone, printable branded document. */
/** Quotation-2 includes the frozen plate-fee line. */
final class ArchivedQuotationTemplateV2 {
	public static function render( array $quote, ?string $language = null ): string {
		$language = \AutoDealership\Core\Localization::normalize_document_language( $language );
		$t = static fn( string $ar, string $en ): string => 'en' === $language ? $en : $ar;
		$money = static fn( $value ): string => esc_html( Money::format( (int) $value ) . ' SAR' );
		$rate = null === $quote['tax_rate_bps'] ? $t( 'غير معروف تاريخيًا', 'Historically unknown' ) : number_format( (int) $quote['tax_rate_bps'] / 100, 2 ) . '%';
		$vehicle_reference_label = 'en' === $language ? 'Vehicle reference' : 'مرجع السيارة';
		$e = static fn( $value ): string => esc_html( (string) $value );
		ob_start();
		?>
<!doctype html><html lang="<?php echo esc_attr( $language ); ?>" dir="<?php echo 'en' === $language ? 'ltr' : 'rtl'; ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $e( $t( 'عرض سعر المركبة', 'Vehicle quotation' ) . ' ' . $quote['quote_number'] ); ?></title>
<style>body{font-family:Tajawal,sans-serif;color:#172033;max-width:900px;margin:32px auto;padding:0 24px;line-height:1.7}.head{display:flex;justify-content:space-between;gap:24px;border-bottom:3px solid #1d4ed8;padding-bottom:18px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:24px 0}.card{border:1px solid #cbd5e1;border-radius:8px;padding:16px}table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:start}.total{font-size:1.25rem;font-weight:700}.actions{margin:20px 0}@media print{.actions{display:none}body{margin:0;max-width:none}}@media(max-width:600px){.grid{grid-template-columns:1fr}.head{display:block}}</style></head><body>
<div class="actions"><p><?php echo $e( $t( 'هذا مستند HTML مؤرشف قابل للطباعة من النسخة الثابتة لعرض السعر. استخدم خيار الطباعة في المتصفح لحفظه كملف PDF.', 'This is a printable archived HTML document from the immutable quotation revision. Use the browser print dialog to save it as PDF.' ) ); ?></p></div>
<header class="head"><div><h1><?php echo $e( $quote['seller_name'] ?: $t( 'عرض سعر المركبة', 'Vehicle quotation' ) ); ?></h1><div><?php echo nl2br( $e( $quote['seller_address'] ?? '' ) ); ?></div><div class="muted"><?php echo $e( $quote['seller_phone'] ?? '' ); ?></div><?php if ( ! empty( $quote['seller_tax_number'] ) ) : ?><div class="muted"><?php echo $e( $t( 'الرقم الضريبي', 'Tax number' ) . ': ' . $quote['seller_tax_number'] ); ?></div><?php endif; ?><div class="muted"><?php echo $e( $quote['quote_number'] . ' — ' . $t( 'الإصدار', 'Version' ) . ' ' . $quote['version'] ); ?></div></div><div><strong><?php echo $e( $t( 'صالح حتى', 'Valid until' ) ); ?></strong><br><?php echo $e( $quote['valid_until'] ); ?></div></header>
<section class="grid"><div class="card"><strong><?php echo $e( $t( 'العميل', 'Customer' ) ); ?></strong><br><?php echo $e( $quote['customer_name'] ); ?></div><div class="card"><strong><?php echo $e( $t( 'السيارة', 'Vehicle' ) ); ?></strong><br><?php echo $e( $quote['vehicle_description'] ); ?><br><span class="muted"><?php echo $e( $vehicle_reference_label . ': ' . $quote['vehicle_stock_number'] ); ?></span></div></section>
<table><tbody>
<tr><th><?php echo $e( $t( 'السعر الأساسي', 'Base price' ) ); ?></th><td><?php echo $money( $quote['base_amount'] ); ?></td></tr>
<tr><th><?php echo $e( $t( 'الرسوم', 'Fees' ) ); ?></th><td><?php echo $money( $quote['fee_amount'] ?? 0 ); ?></td></tr>
<?php if ( (int) ( $quote['promotion_amount'] ?? 0 ) > 0 ) : ?><tr><th><?php echo $e( $t( 'العرض الترويجي', 'Promotion' ) . ( ! empty( $quote['promotion_code'] ) ? ' (' . $quote['promotion_code'] . ')' : '' ) ); ?></th><td>-<?php echo $money( $quote['promotion_amount'] ); ?></td></tr><?php endif; ?>
<tr><th><?php echo $e( $t( 'الخصم المعتمد', 'Approved discount' ) ); ?></th><td>-<?php echo $money( $quote['discount_amount'] ); ?></td></tr>
<tr><th><?php echo $e( $t( 'المجموع قبل الضريبة', 'Subtotal before tax' ) ); ?></th><td><?php echo $money( $quote['subtotal_amount'] ?? ( (int) $quote['base_amount'] - (int) $quote['discount_amount'] ) ); ?></td></tr>
<tr><th><?php echo $e( $t( 'نسبة الضريبة', 'Tax rate' ) ); ?></th><td><?php echo $e( $rate ); ?></td></tr>
<tr><th><?php echo $e( $t( 'مبلغ الضريبة', 'Tax amount' ) ); ?></th><td><?php echo $money( $quote['tax_amount'] ); ?></td></tr>
<?php if (!empty($quote['plate_amount'])) : ?><tr><th><?php echo $e('en' === $language ? 'Plate fees' : 'رسوم اللوحات'); ?></th><td><?php echo $money($quote['plate_amount']); ?></td></tr><?php endif; ?>
<tr class="total"><th><?php echo $e( $t( 'الإجمالي', 'Total' ) ); ?></th><td><?php echo $money( $quote['final_amount'] ); ?></td></tr>
</tbody></table>
<p class="muted"><?php echo $e( $t( 'هذا المستند نسخة مالية ثابتة من عرض السعر. تبقى الصلاحية وتوفر المركبة مرتبطين بالحالة المسجلة في النظام.', 'This document is an immutable financial revision of the quotation. Validity and vehicle availability remain subject to the recorded system state.' ) ); ?></p>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
