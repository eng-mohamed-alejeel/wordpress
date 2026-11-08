<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Shared presentation primitives for scoped, printable administration reports. */
final class ReportView {
	private const PAGES = array(
		'adc-affiliate-reports',
		'adc-operational-reports',
		'adc-financial-export',
		'adc-area-reports',
		'adc-crm-reports',
		'adc-lead-performance',
		'adc-marketing-reports',
	);

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( ! in_array( $page, self::PAGES, true ) ) {
			return;
		}
		$css = ADC_PATH . 'assets/css/admin-reports.css';
		$js = ADC_PATH . 'assets/js/admin-reports.js';
		wp_enqueue_style( 'adc-admin-reports', plugins_url( 'assets/css/admin-reports.css', ADC_FILE ), array( 'adc-admin' ), (string) ( @filemtime( $css ) ?: ADC_VERSION ) );
		wp_enqueue_script( 'adc-admin-reports', plugins_url( 'assets/js/admin-reports.js', ADC_FILE ), array(), (string) ( @filemtime( $js ) ?: ADC_VERSION ), true );
	}

	public static function print_button(): void {
		echo '<div class="adc-report-actions no-print"><button type="button" class="button button-secondary" data-adc-report-print>' . esc_html__( 'طباعة التقرير', 'auto-dealership-core' ) . '</button></div>';
	}

	/** Render compact, accessible KPI cards. */
	public static function summary_cards( array $cards ): void {
		if ( ! $cards ) {
			return;
		}
		echo '<div class="adc-report-summary" role="list">';
		foreach ( $cards as $card ) {
			$label = (string) ( $card['label'] ?? '' );
			$value = (string) ( $card['value'] ?? '—' );
			$note = (string) ( $card['note'] ?? '' );
			echo '<section class="adc-report-kpi card" role="listitem"><strong>' . esc_html( $value ) . '</strong><span>' . esc_html( $label ) . '</span>';
			if ( '' !== $note ) {
				echo '<small>' . esc_html( $note ) . '</small>';
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * Render a CSS bar chart with a text table fallback for print and accessibility.
	 * Rows must contain a display label and a numeric value.
	 */
	public static function bar_chart( string $title, array $rows, string $label_key = 'label', string $value_key = 'value', int $limit = 12 ): void {
		$rows = array_slice( $rows, 0, max( 1, $limit ) );
		$max = 1.0;
		foreach ( $rows as $row ) {
			$max = max( $max, abs( (float) ( $row[ $value_key ] ?? 0 ) ) );
		}
		echo '<section class="adc-report-chart card"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'لا توجد بيانات كافية لعرض الرسم.', 'auto-dealership-core' ) . '</p></section>';
			return;
		}
		echo '<div class="adc-report-bars" role="img" aria-label="' . esc_attr( $title ) . '">';
		foreach ( $rows as $row ) {
			$label = (string) ( $row[ $label_key ] ?? '' );
			$value = (float) ( $row[ $value_key ] ?? 0 );
			$width = min( 100, max( 0, round( abs( $value ) * 100 / $max, 1 ) ) );
			echo '<div class="adc-report-bar-row"><div class="adc-report-bar-label"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value, 2 ) ) . '</strong></div><div class="adc-report-bar-track"><span style="--adc-report-bar-width:' . esc_attr( (string) $width ) . '%"></span></div></div>';
		}
		echo '</div><table class="widefat striped adc-report-chart-data"><thead><tr><th>' . esc_html__( 'التصنيف', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'القيمة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( (string) ( $row[ $label_key ] ?? '' ) ) . '</td><td>' . esc_html( number_format_i18n( (float) ( $row[ $value_key ] ?? 0 ), 2 ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}
}
