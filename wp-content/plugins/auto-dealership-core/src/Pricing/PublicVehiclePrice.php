<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Display calculation only: imported and operational net prices remain unchanged. */
final class PublicVehiclePrice {
	public static function rate(): int {
		return min( 10000, max( 0, (int) get_option( 'adc_vat_rate_bps', 0 ) ) );
	}

	public static function plates( int $post_id ): int {
		return array( 'private_transport'=>90000, 'commercial_transport'=>120000 )[get_post_meta( $post_id, '_car_plate_type', true )] ?? 0;
	}

	public static function calculate( int $net, int $post_id ): array {
		$p=ProfitMargin::policy();$margin=0;$taxable=$net;
		if($net>0&&$p['stage']==='before_tax'){$margin=ProfitMargin::amount($net,$p);if($net>PHP_INT_MAX-$margin){throw new \OverflowException('Public price exceeds the amount limit.');}$taxable=$net+$margin;}
		$amounts = $net > 0 ? Money::calculate( $taxable, 0, self::rate() ) : array( 'tax_amount'=>0, 'final_amount'=>0 );
		if($net>0&&$p['stage']==='after_tax'){
			$margin=ProfitMargin::amount($amounts['final_amount'],$p);
			// After-tax margin is VAT-inclusive. Extract its net component before taxing.
			$denominator=10000+self::rate();
			$net_margin=intdiv($margin,$denominator)*10000+intdiv(($margin%$denominator)*10000+intdiv($denominator,2),$denominator);
			if($net>PHP_INT_MAX-$net_margin){throw new \OverflowException('Public price exceeds the amount limit.');}
			$taxable=$net+$net_margin;
			$amounts=Money::calculate($taxable,0,self::rate());
		}
		$fee=$net>0?Money::parse(get_option('adc_pricing_fee_amount',0)):0;
		if(null===$fee){throw new \InvalidArgumentException('Invalid pricing fee.');}
		if($taxable>PHP_INT_MAX-$fee){throw new \OverflowException('Public price exceeds the amount limit.');}
		$amounts=$net>0?Money::calculate($taxable+$fee,0,self::rate()):array('tax_amount'=>0,'final_amount'=>0);
		$plates = $net > 0 ? self::plates( $post_id ) : 0;
		if ( $amounts['final_amount'] > PHP_INT_MAX - $plates ) { throw new \OverflowException( 'Public price exceeds the amount limit.' ); }
		return array( 'base_amount'=>$net, 'taxable_amount'=>$taxable+$fee, 'fee_amount'=>$fee, 'margin_amount'=>$margin, 'margin_stage'=>$p['stage'], 'tax_amount'=>$amounts['tax_amount'], 'tax_rate_bps'=>self::rate(), 'plate_amount'=>$plates, 'final_amount'=>$amounts['final_amount'] + $plates );
	}

	public static function label( int $post_id ): string {
		$parts = array();
		if ( self::rate() > 0 ) { $parts[] = sprintf( __( 'Includes VAT (%s%%)', 'auto-dealership-core' ), rtrim( rtrim( Money::decimal( self::rate() ), '0' ), '.' ) ); }
		if ( self::plates( $post_id ) > 0 ) { $parts[] = sprintf( __( 'Includes plate fees: %s', 'auto-dealership-core' ), Money::display( self::plates( $post_id ) ) ); }
		$fee=Money::parse(get_option('adc_pricing_fee_amount',0));
		if($fee>0){$parts[]=(\AutoDealership\Core\Localization::language()==='en'?'Includes fees: ':'يشمل الرسوم: ').Money::display($fee);}
		return implode( ' · ', $parts );
	}

	/** Preserve the existing public contract without exposing internal margin policy fields. */
	public static function public_breakdown(array $amounts):array {
		return array_intersect_key($amounts,array_flip(array('base_amount','tax_amount','tax_rate_bps','plate_amount','final_amount')));
	}

	/** Same integer half-up arithmetic in SQL for displayed-price search and sorting. */
	public static function sql( string $net, string $post_id ): string {
		global $wpdb;
		$plates = "COALESCE((SELECT CASE pm.meta_value WHEN 'private_transport' THEN 90000 WHEN 'commercial_transport' THEN 120000 ELSE 0 END FROM {$wpdb->postmeta} pm WHERE pm.post_id=$post_id AND pm.meta_key='_car_plate_type' ORDER BY pm.meta_id ASC LIMIT 1),0)";
		$p=ProfitMargin::policy();$margin=static fn($base)=>$p['type']==='fixed'?(string)$p['value']:($p['type']==='percentage'?'FLOOR((CAST(('.$base.') AS DECIMAL(30,0))*'.$p['value'].'+5000)/10000)':'0');
		$base=$p['stage']==='before_tax'?'('.$net.'+'.$margin($net).')':$net;
		$gross='('.$base.'+FLOOR((CAST(('.$base.') AS DECIMAL(30,0))*'.self::rate().'+5000)/10000))';
		if($p['stage']==='after_tax'){
			$denominator=10000+self::rate();
			$base='('.$net.'+FLOOR((CAST(('.$margin($gross).') AS DECIMAL(30,0))*10000+'.intdiv($denominator,2).')/'.$denominator.'))';
			$gross='('.$base.'+FLOOR((CAST(('.$base.') AS DECIMAL(30,0))*'.self::rate().'+5000)/10000))';
		}
		$fee=Money::parse(get_option('adc_pricing_fee_amount',0));
		if(null===$fee){throw new \InvalidArgumentException('Invalid pricing fee.');}
		$base='('.$base.'+'.$fee.')';
		$gross='('.$base.'+FLOOR((CAST(('.$base.') AS DECIMAL(30,0))*'.self::rate().'+5000)/10000))';
		return '(CASE WHEN '.$net.'>0 THEN '.$gross.'+'.$plates.' ELSE 0 END)';
	}
}
