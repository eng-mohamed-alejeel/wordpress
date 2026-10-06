<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Renders one immutable quote revision as a standalone, printable branded document. */
final class QuoteDocument {
	public static function render( array $quote ): string {
		$money = static fn( $value ): string => esc_html( Money::format( (int) $value ) . ' SAR' );
		$rate = null === $quote['tax_rate_bps'] ? __( 'Historically unknown', 'auto-dealership-core' ) : number_format( (int) $quote['tax_rate_bps'] / 100, 2 ) . '%';
		$e = static fn( $value ): string => esc_html( (string) $value );
		ob_start();
		?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $e( __( 'Vehicle quotation', 'auto-dealership-core' ) . ' ' . $quote['quote_number'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( \AutoDealership\Core\Typography::stylesheet_url() ); ?>">
<style>body{font-family:Tajawal,sans-serif;color:#172033;max-width:900px;margin:32px auto;padding:0 24px;line-height:1.7}.head{display:flex;justify-content:space-between;gap:24px;border-bottom:3px solid #1d4ed8;padding-bottom:18px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:24px 0}.card{border:1px solid #cbd5e1;border-radius:8px;padding:16px}table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:right}.total{font-size:1.25rem;font-weight:700}.actions{margin:20px 0}@media print{.actions{display:none}body{margin:0;max-width:none}}@media(max-width:600px){.grid{grid-template-columns:1fr}.head{display:block}}</style></head><body>
<div class="actions"><button type="button" onclick="window.print()"><?php echo $e( __( 'Print or save PDF', 'auto-dealership-core' ) ); ?></button></div>
<header class="head"><div><h1><?php echo $e( $quote['seller_name'] ?: __( 'Vehicle quotation', 'auto-dealership-core' ) ); ?></h1><div><?php echo nl2br( $e( $quote['seller_address'] ?? '' ) ); ?></div><div class="muted"><?php echo $e( $quote['seller_phone'] ?? '' ); ?></div><?php if ( ! empty( $quote['seller_tax_number'] ) ) : ?><div class="muted"><?php echo $e( __( 'Tax number', 'auto-dealership-core' ) . ': ' . $quote['seller_tax_number'] ); ?></div><?php endif; ?><div class="muted"><?php echo $e( $quote['quote_number'] . ' — ' . __( 'Version', 'auto-dealership-core' ) . ' ' . $quote['version'] ); ?></div></div><div><strong><?php echo $e( __( 'Valid until', 'auto-dealership-core' ) ); ?></strong><br><?php echo $e( $quote['valid_until'] ); ?></div></header>
<section class="grid"><div class="card"><strong><?php echo $e( __( 'Customer', 'auto-dealership-core' ) ); ?></strong><br><?php echo $e( $quote['customer_name'] ); ?></div><div class="card"><strong><?php echo $e( __( 'Vehicle', 'auto-dealership-core' ) ); ?></strong><br><?php echo $e( $quote['vehicle_description'] ); ?><br><span class="muted"><?php echo $e( __( 'Stock number', 'auto-dealership-core' ) . ': ' . $quote['vehicle_stock_number'] ); ?></span></div></section>
<table><tbody>
<tr><th><?php echo $e( __( 'Base price', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['base_amount'] ); ?></td></tr>
<tr><th><?php echo $e( __( 'Fees', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['fee_amount'] ?? 0 ); ?></td></tr>
<?php if ( (int) ( $quote['promotion_amount'] ?? 0 ) > 0 ) : ?><tr><th><?php echo $e( __( 'Promotion', 'auto-dealership-core' ) . ( ! empty( $quote['promotion_code'] ) ? ' (' . $quote['promotion_code'] . ')' : '' ) ); ?></th><td>-<?php echo $money( $quote['promotion_amount'] ); ?></td></tr><?php endif; ?>
<tr><th><?php echo $e( __( 'Approved discount', 'auto-dealership-core' ) ); ?></th><td>-<?php echo $money( $quote['discount_amount'] ); ?></td></tr>
<tr><th><?php echo $e( __( 'Subtotal before tax', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['subtotal_amount'] ?? ( (int) $quote['base_amount'] - (int) $quote['discount_amount'] ) ); ?></td></tr>
<tr><th><?php echo $e( __( 'Tax rate', 'auto-dealership-core' ) ); ?></th><td><?php echo $e( $rate ); ?></td></tr>
<tr><th><?php echo $e( __( 'Tax amount', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['tax_amount'] ); ?></td></tr>
<tr class="total"><th><?php echo $e( __( 'Total', 'auto-dealership-core' ) ); ?></th><td><?php echo $money( $quote['final_amount'] ); ?></td></tr>
</tbody></table>
<p class="muted"><?php echo $e( __( 'This document is an immutable financial revision of the quotation. Validity and vehicle availability remain subject to the recorded system state.', 'auto-dealership-core' ) ); ?></p>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
