<?php
namespace AutoDealership\Reports;

defined( 'ABSPATH' ) || exit;

/** Converts user-facing WordPress dates into an explicit UTC query window. */
final class ReportDateRange {
	public static function normalize( array $filters, int $default_days = 30 ) {
		$timezone = wp_timezone();
		$today = ( new \DateTimeImmutable( 'now', $timezone ) )->setTime( 0, 0, 0 );
		$default_from = $today->modify( '-' . max( 1, $default_days ) . ' days' )->format( 'Y-m-d' );
		$from_input = sanitize_text_field( (string) ( $filters['from'] ?? '' ) );
		$to_input = sanitize_text_field( (string) ( $filters['to'] ?? '' ) );
		$from = '' !== trim( $from_input ) ? $from_input : $default_from;
		$to = '' !== trim( $to_input ) ? $to_input : $today->format( 'Y-m-d' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $timezone );
		$end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $timezone );
		if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $end < $start || $start->diff( $end )->days > 366 ) {
			return new \WP_Error( 'adc_report_dates', 'Choose a valid date range of at most 366 days.', array( 'status'=>400 ) );
		}
		$start = $start->setTime( 0, 0, 0 );
		$exclusive = $end->modify( '+1 day' )->setTime( 0, 0, 0 );
		$utc = new \DateTimeZone( 'UTC' );
		return array(
			'from'=>$from,
			'to'=>$to,
			'from_utc'=>$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to_utc'=>$exclusive->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'timezone'=>$timezone->getName(),
		);
	}
}
