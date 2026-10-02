<?php
namespace AutoDealership\Content;

use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Inventory\CatalogPresentation;
use AutoDealership\Leads\PublicIntake;
use AutoDealership\Tools\LoanCalculator;
use AutoDealership\Tools\VehicleComparison;

defined( 'ABSPATH' ) || exit;

/** Durable public shortcode contracts with replaceable presentation adapters. */
final class PublicShortcodes {
	private const DEFINITIONS = array(
		'car_dealer_cars' => array( 'count' => 6, 'featured' => '' ),
		'car_dealer_loan_calculator' => array( 'price' => 0 ),
		'car_dealer_comparison' => array(),
		'car_dealer_testimonials' => array( 'count' => 3 ),
		'car_dealer_contact_form' => array(),
		'ab_hero_section' => array(),
		'ab_mission_vision' => array(),
		'ab_stats_section' => array(),
		'ab_values_section' => array(),
		'ab_team_section' => array(),
		'ab_cta_section' => array(),
		'ab_testimonials_section' => array(),
		'ab_contact_hero' => array(),
		'ab_contact_grid' => array(),
		'ab_contact_map' => array(),
		'ab_contact_form_section' => array(),
		'ab_faq_section' => array(),
		'ab_social_section' => array(),
	);

	private static bool $booted = false;

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_public_shortcodes_enabled', true );
	}

	public static function owns_theme_registration(): bool {
		return self::enabled();
	}

	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return;
		}
		self::$booted = true;
		add_action( 'init', array( self::class, 'register' ), 5 );
	}

	public static function register(): void {
		foreach ( array_keys( self::DEFINITIONS ) as $tag ) {
			add_shortcode( $tag, array( self::class, 'render' ) );
		}
	}

	/** @param array<string,mixed>|string $attributes */
	public static function render( $attributes = array(), ?string $content = null, string $tag = '' ): string {
		if ( ! isset( self::DEFINITIONS[ $tag ] ) ) {
			return '';
		}
		$attributes = is_array( $attributes ) ? $attributes : array();
		$attributes = shortcode_atts( self::DEFINITIONS[ $tag ], $attributes, $tag );
		$model = self::view_model( $tag, $attributes );
		$fallback = self::fallback( $tag, $model );
		$html = apply_filters( 'adc_shortcode_' . $tag . '_html', $fallback, $model, $attributes, $content, $tag );
		return is_string( $html ) ? $html : $fallback;
	}

	/** @param array<string,mixed> $attributes */
	private static function view_model( string $tag, array $attributes ): array {
		switch ( $tag ) {
			case 'car_dealer_cars':
				$count = min( 48, max( 1, absint( $attributes['count'] ) ) );
				$featured = is_scalar( $attributes['featured'] ) ? trim( (string) $attributes['featured'] ) : '';
				$args = array(
					'post_type' => 'car',
					'post_status' => 'publish',
					'posts_per_page' => $count,
					'fields' => 'ids',
					'no_found_rows' => true,
					'ignore_sticky_posts' => true,
				);
				if ( PublicCatalog::is_authoritative() ) {
					$args['adc_public_catalog'] = true;
					$args['adc_catalog_filters'] = array( 'sort' => 'newest' );
				} elseif ( '' !== $featured ) {
					$args['meta_query'] = array( array( 'key' => '_car_featured', 'value' => absint( $featured ) ) );
				}
				$query = new \WP_Query( $args );
				return array( 'post_ids' => array_values( array_map( 'absint', $query->posts ) ), 'count' => $count, 'featured' => $featured );

			case 'car_dealer_comparison':
				return array( 'post_ids' => VehicleComparison::current(), 'limit' => VehicleComparison::LIMIT );

			case 'car_dealer_loan_calculator':
				return LoanCalculator::view_model( absint( $attributes['price'] ) );

			case 'car_dealer_contact_form':
				$user = is_user_logged_in() ? wp_get_current_user() : null;
				return array(
					'available' => PublicIntake::enabled(),
					'authenticated' => $user instanceof \WP_User,
					'name' => $user instanceof \WP_User ? (string) $user->display_name : '',
					'email' => $user instanceof \WP_User ? (string) $user->user_email : '',
					'phone' => $user instanceof \WP_User ? (string) get_user_meta( $user->ID, 'car_dealer_phone', true ) : '',
					'account_url' => $user instanceof \WP_User ? esc_url_raw( (string) apply_filters( 'adc_customer_account_url', home_url( '/' ), '' ) ) : '',
					'language' => CatalogPresentation::language(),
				);

			case 'car_dealer_testimonials':
				return array( 'count' => min( 12, max( 1, absint( $attributes['count'] ) ) ) );
		}

		return array( 'tag' => $tag );
	}

	private static function fallback( string $tag, array $model ): string {
		if ( in_array( $tag, array( 'car_dealer_cars', 'car_dealer_comparison' ), true ) ) {
			return self::vehicle_list( (array) ( $model['post_ids'] ?? array() ), 'car_dealer_comparison' === $tag );
		}
		if ( 'car_dealer_loan_calculator' === $tag ) {
			return self::loan_calculator( $model );
		}
		if ( 'car_dealer_contact_form' === $tag ) {
			return self::contact_form( $model );
		}
		return '';
	}

	/** @param int[] $post_ids */
	private static function vehicle_list( array $post_ids, bool $comparison ): string {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( ! $post_ids ) {
			return $comparison ? '<p class="adc-empty-state">' . esc_html__( 'لم تضف سيارات للمقارنة بعد.', 'auto-dealership-core' ) . '</p>' : '';
		}
		$html = '<div class="adc-vehicle-list">';
		foreach ( $post_ids as $post_id ) {
			if ( 'car' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}
			$title = get_the_title( $post_id );
			$url = get_permalink( $post_id );
			$image = get_the_post_thumbnail( $post_id, 'medium_large', array( 'loading' => 'lazy' ) );
			$html .= '<article class="adc-vehicle-card">';
			if ( $image ) {
				$html .= '<a href="' . esc_url( $url ) . '">' . wp_kses_post( $image ) . '</a>';
			}
			$html .= '<h2><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h2></article>';
		}
		return $html . '</div>';
	}

	private static function loan_calculator( array $model ): string {
		return '<div class="adc-loan-calculator"><h2>' . esc_html__( 'حاسبة التمويل', 'auto-dealership-core' ) . '</h2><form data-loan-calculator>'
			. '<label>' . esc_html__( 'سعر السيارة', 'auto-dealership-core' ) . '<input type="number" name="price" data-loan-price value="' . esc_attr( $model['price'] ) . '" min="1" max="' . esc_attr( $model['max_amount'] ) . '" required></label>'
			. '<label>' . esc_html__( 'الدفعة الأولى', 'auto-dealership-core' ) . '<input type="number" name="down_payment" data-loan-down value="' . esc_attr( $model['down_payment'] ) . '" min="0" max="' . esc_attr( $model['max_amount'] ) . '" required></label>'
			. '<label>' . esc_html__( 'النسبة السنوية التقديرية %', 'auto-dealership-core' ) . '<input type="number" name="annual_rate" data-loan-rate value="' . esc_attr( $model['annual_rate'] ) . '" min="0" max="' . esc_attr( $model['max_rate'] ) . '" step="0.01" required></label>'
			. '<label>' . esc_html__( 'المدة بالأشهر', 'auto-dealership-core' ) . '<input type="number" name="months" data-loan-months value="' . esc_attr( $model['months'] ) . '" min="1" max="' . esc_attr( $model['max_months'] ) . '" required></label>'
			. '<button type="submit">' . esc_html__( 'احسب', 'auto-dealership-core' ) . '</button><p>' . esc_html__( 'القسط الشهري التقديري:', 'auto-dealership-core' ) . ' <strong data-loan-result>0 SAR</strong></p><p data-loan-status role="status"></p><small>' . esc_html__( 'هذا تقدير إرشادي وليس عرض تمويل أو موافقة.', 'auto-dealership-core' ) . '</small></form></div>';
	}

	private static function contact_form( array $model ): string {
		if ( empty( $model['available'] ) ) {
			return '<p class="adc-empty-state">' . esc_html__( 'نموذج التواصل غير متاح حاليًا.', 'auto-dealership-core' ) . '</p>';
		}
		$readonly = ! empty( $model['authenticated'] ) ? ' readonly' : '';
		$html = '<form method="post" class="cd-ajax-form adc-contact-form" data-action="car_dealer_contact">'
			. '<input type="hidden" name="lang" value="' . esc_attr( $model['language'] ) . '">'
			. '<label>' . esc_html__( 'الاسم', 'auto-dealership-core' ) . '<input name="name" type="text" autocomplete="name" value="' . esc_attr( $model['name'] ) . '" required' . $readonly . '></label>'
			. '<label>' . esc_html__( 'البريد الإلكتروني', 'auto-dealership-core' ) . '<input name="email" type="email" dir="ltr" autocomplete="email" value="' . esc_attr( $model['email'] ) . '" required' . $readonly . '></label>'
			. '<label>' . esc_html__( 'الهاتف', 'auto-dealership-core' ) . '<input name="phone" type="tel" dir="ltr" autocomplete="tel" value="' . esc_attr( $model['phone'] ) . '" required' . $readonly . '></label>'
			. '<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" hidden>'
			. '<textarea name="message" required aria-label="' . esc_attr__( 'رسالتك', 'auto-dealership-core' ) . '" placeholder="' . esc_attr__( 'رسالتك', 'auto-dealership-core' ) . '"></textarea><button type="submit">' . esc_html__( 'إرسال', 'auto-dealership-core' ) . '</button><p class="cd-form-status" role="status"></p>';
		if ( ! empty( $model['authenticated'] ) && ! empty( $model['account_url'] ) ) {
			$html .= '<p><a href="' . esc_url( $model['account_url'] ) . '">' . esc_html__( 'تحديث بيانات حسابي', 'auto-dealership-core' ) . '</a></p>';
		}
		return $html . '</form>';
	}
}
