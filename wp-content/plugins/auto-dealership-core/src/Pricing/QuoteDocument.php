<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Renders one immutable quote revision as a standalone, printable HTML document. */
final class QuoteDocument {
	public static function render( array $quote ): string {
		$money = static fn( $value ): string => esc_html( Money::format( (int) $value ) . ' SAR' );
		$rate = null === $quote['tax_rate_bps'] ? __( 'غير معروف تاريخيًا', 'auto-dealership-core' ) : number_format( (int) $quote['tax_rate_bps'] / 100, 2 ) . '%';
		$e = static fn( $value ): string => esc_html( (string) $value );
		ob_start();
		?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $e( __( 'عرض سعر', 'auto-dealership-core' ) . ' ' . $quote['quote_number'] ); ?></title>
<style>body{font-family:Tahoma,Arial,sans-serif;color:#172033;max-width:900px;margin:32px auto;padding:0 24px;line-height:1.7}.head{display:flex;justify-content:space-between;gap:24px;border-bottom:3px solid #1d4ed8;padding-bottom:18px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:24px 0}.card{border:1px solid #cbd5e1;border-radius:8px;padding:16px}table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:right}.total{font-size:1.25rem;font-weight:700}.actions{margin:20px 0}@media print{.actions{display:none}body{margin:0;max-width:none}}@media(max-width:600px){.grid{grid-template-columns:1fr}.head{display:block}}</style></head><body>
<div class="actions"><button type="button" onclick="window.print()"><?php echo $e( __( 'طباعة أو حفظ PDF', 'auto-dealership-core' ) ); ?></button></div>
<header class="head"><div><h1><?php echo $e( __( 'عرض سعر مركبة', 'auto-dealership-core' ) ); ?></h1><div class="muted"><?php echo $e( $quote['quote_number'] ); ?> — <?php echo $e( __( 'النسخة', 'auto-dealership-core' ) . ' ' . $quote['version'] ); ?></div></div><div><strong><?php echo $e( __( 'صالح حتى', 'auto-dealership-core' ) ); ?></strong><br><?php echo $e( $quote['valid_until'] ); ?></div></header>
<section class="grid"><div class="card"><strong><?php echo $e( __( 'العميل', 'auto-dealership-core' ) ); ?></strong><br><?php echo $e( $quote['customer_name'] ); ?></div><div class="card"><strong><?php echo $e( __( 'المركبة', 'auto-dealership-core' ) ); ?></strong><br><?php echo $e( $quote['vehicle_description'] ); ?><br><span class="muted"><?php echo $e( __( 'رقم المخزون', 'auto-dealership-core' ) . ': ' . $quote['vehicle_stock_number'] ); ?></span></div></section>
<table><tbody><tr><th><?php echo $e( __( 'السعر الأساسي', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['base_amount'] ); ?></td></tr><tr><th><?php echo $e( __( 'الخصم', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['discount_amount'] ); ?></td></tr><tr><th><?php echo $e( __( 'نسبة الضريبة', 'auto-dealership-core' ) ); ?></th><td><?php echo $e( $rate ); ?></td></tr><tr><th><?php echo $e( __( 'قيمة الضريبة', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['tax_amount'] ); ?></td></tr><tr class="total"><th><?php echo $e( __( 'الإجمالي', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['final_amount'] ); ?></td></tr></tbody></table>
<p class="muted"><?php echo $e( __( 'هذه الوثيقة تمثل النسخة المالية المحددة من العرض. تخضع صلاحية العرض وتوفر المركبة لحالة النظام المعتمدة.', 'auto-dealership-core' ) ); ?></p>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
