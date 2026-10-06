<?php
namespace AutoDealership\Admin;

use AutoDealership\Leads\EngagementQuery;
use AutoDealership\Leads\RequestWorkflow;

defined( 'ABSPATH' ) || exit;

/** Plugin-owned staff pages for messages, test-drive bookings and subscribers. */
final class EngagementPages {
	private const PAGES = array( 'car-dealer-messages', 'car-dealer-bookings', 'car-dealer-subscribers' );

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_engagement_admin_enabled', true );
	}

	public static function owns_theme_pages(): bool {
		return self::enabled();
	}

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function menu(): void {
		if ( EngagementQuery::can_view( 'message' ) ) {
			$capability = current_user_can( 'adc_view_branch_leads' ) ? 'adc_view_branch_leads' : 'adc_view_own_leads';
			add_submenu_page( 'adc-workspace', __( 'رسائل العملاء', 'auto-dealership-core' ), __( 'الرسائل', 'auto-dealership-core' ), $capability, 'car-dealer-messages', array( self::class, 'render_messages' ) );
			add_submenu_page( 'adc-workspace', __( 'حجوزات تجربة القيادة', 'auto-dealership-core' ), __( 'حجوزات التجربة', 'auto-dealership-core' ), $capability, 'car-dealer-bookings', array( self::class, 'render_bookings' ) );
		}
		if ( EngagementQuery::can_view( 'subscriber' ) ) {
			add_submenu_page( 'adc-workspace', __( 'اشتراكات النشرة البريدية', 'auto-dealership-core' ), __( 'النشرة البريدية', 'auto-dealership-core' ), 'adc_view_marketing_subscribers', 'car-dealer-subscribers', array( self::class, 'render_subscribers' ) );
		}
	}

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( in_array( $page, self::PAGES, true ) ) {
			wp_enqueue_style( 'adc-admin-engagement', plugins_url( 'assets/css/admin-engagement.css', ADC_FILE ), array( 'adc-admin' ), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-engagement.css' ) );
		}
	}

	public static function render_messages(): void {
		self::render_table(
			'message',
			'car-dealer-messages',
			__( 'رسائل العملاء وطلبات البيع', 'auto-dealership-core' ),
			array(
				'created_at' => __( 'التاريخ', 'auto-dealership-core' ),
				'lead_type'  => __( 'نوع الطلب', 'auto-dealership-core' ),
				'car_id'     => __( 'السيارة', 'auto-dealership-core' ),
				'name'       => __( 'الاسم', 'auto-dealership-core' ),
				'email'      => __( 'البريد', 'auto-dealership-core' ),
				'phone'      => __( 'الهاتف', 'auto-dealership-core' ),
				'message'    => __( 'الرسالة', 'auto-dealership-core' ),
				'status'     => __( 'الحالة', 'auto-dealership-core' ),
				'workflow'   => __( 'إدارة الطلب', 'auto-dealership-core' ),
			)
		);
	}

	public static function render_bookings(): void {
		self::render_table(
			'booking',
			'car-dealer-bookings',
			__( 'حجوزات تجربة القيادة', 'auto-dealership-core' ),
			array(
				'created_at'     => __( 'التاريخ', 'auto-dealership-core' ),
				'car_id'        => __( 'السيارة', 'auto-dealership-core' ),
				'name'          => __( 'الاسم', 'auto-dealership-core' ),
				'email'         => __( 'البريد', 'auto-dealership-core' ),
				'phone'         => __( 'الهاتف', 'auto-dealership-core' ),
				'requested_date'=> __( 'اليوم', 'auto-dealership-core' ),
				'requested_time'=> __( 'الوقت', 'auto-dealership-core' ),
				'status'        => __( 'الحالة', 'auto-dealership-core' ),
				'workflow'      => __( 'إدارة الطلب', 'auto-dealership-core' ),
			)
		);
	}

	public static function render_subscribers(): void {
		self::render_table(
			'subscriber',
			'car-dealer-subscribers',
			__( 'اشتراكات النشرة البريدية', 'auto-dealership-core' ),
			array(
				'created_at' => __( 'تاريخ الموافقة', 'auto-dealership-core' ),
				'email'      => __( 'البريد', 'auto-dealership-core' ),
				'status'     => __( 'الحالة', 'auto-dealership-core' ),
			)
		);
	}

	private static function render_table( string $type, string $slug, string $title, array $columns ): void {
		$request_id = 'subscriber' === $type ? 0 : absint( $_GET['request_id'] ?? 0 );
		$result = EngagementQuery::page( $type, max( 1, absint( $_GET['paged'] ?? 1 ) ), $request_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 500 ) ) );
		}

		echo '<div class="wrap adc-engagement" dir="rtl"><h1>' . esc_html( $title ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'تم تحديث الطلب وحفظ سجل المتابعة.', 'auto-dealership-core' ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html( 'subscriber' === $type ? __( 'قائمة موافقات الاشتراك الحالية للقراءة التشغيلية.', 'auto-dealership-core' ) : __( 'تعرض القائمة الطلبات الواقعة ضمن نطاق الفرع أو المسؤول الحالي فقط.', 'auto-dealership-core' ) ) . '</p>';
		echo '<div class="adc-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( $columns as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $result['items'] as $row ) {
			echo '<tr>';
			foreach ( $columns as $key => $label ) {
				echo '<td class="adc-engagement-' . esc_attr( sanitize_html_class( $key ) ) . '">';
				self::render_cell( $type, $slug, $key, $row );
				echo '</td>';
			}
			echo '</tr>';
		}
		if ( ! $result['items'] ) {
			echo '<tr><td colspan="' . esc_attr( (string) count( $columns ) ) . '"><div class="adc-empty"><span class="dashicons dashicons-inbox" aria-hidden="true"></span><p>' . esc_html__( 'لا توجد سجلات ضمن نطاقك حتى الآن.', 'auto-dealership-core' ) . '</p></div></td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $result['pages'] > 1 ) {
			$base = str_replace( '999999999', '%#%', add_query_arg( array( 'page' => $slug, 'paged' => 999999999 ), admin_url( 'admin.php' ) ) );
			if ( $request_id ) {
				$base = add_query_arg( 'request_id', $request_id, $base );
			}
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $result['page'], 'total' => $result['pages'] ) ) ) . '</div></div>';
		}
		echo '</div>';
	}

	private static function render_cell( string $type, string $slug, string $key, array $row ): void {
		$value = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		if ( 'workflow' === $key ) {
			$lead_id = RequestWorkflow::linked_lead( $type, (int) $row['id'] );
			if ( is_wp_error( $lead_id ) ) {
				echo '<p role="alert">' . esc_html( $lead_id->get_error_message() ) . '</p>';
			} elseif ( $lead_id ) {
				RequestPage::render( $lead_id, $slug );
			} else {
				echo '<p class="description">' . esc_html__( 'سجل تاريخي غير مرتبط بمسار Core؛ متاح للقراءة فقط.', 'auto-dealership-core' ) . '</p>';
			}
			return;
		}
		if ( 'status' === $key ) {
			$labels = array( 'new'=>__( 'جديد', 'auto-dealership-core' ), 'pending'=>__( 'قيد الانتظار', 'auto-dealership-core' ), 'active'=>__( 'نشط', 'auto-dealership-core' ), 'confirmed'=>__( 'مؤكد', 'auto-dealership-core' ), 'completed'=>__( 'مكتمل', 'auto-dealership-core' ), 'cancelled'=>__( 'ملغى', 'auto-dealership-core' ), 'read'=>__( 'قيد المتابعة', 'auto-dealership-core' ), 'inactive'=>__( 'غير نشط', 'auto-dealership-core' ) );
			echo '<span class="adc-status adc-status-' . esc_attr( sanitize_html_class( $value ) ) . '">' . esc_html( $labels[ $value ] ?? $value ) . '</span>';
			return;
		}
		if ( 'car_id' === $key && absint( $value ) && 'car' === get_post_type( absint( $value ) ) ) {
			$title = get_the_title( absint( $value ) );
			echo current_user_can( 'edit_post', absint( $value ) ) ? '<a href="' . esc_url( get_edit_post_link( absint( $value ) ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
			return;
		}
		if ( 'email' === $key && is_email( $value ) ) {
			echo '<a dir="ltr" href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
			return;
		}
		if ( 'phone' === $key && $value ) {
			echo '<a dir="ltr" href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $value ) ) . '">' . esc_html( $value ) . '</a>';
			return;
		}
		if ( 'message' === $key ) {
			echo '<details><summary>' . esc_html( wp_html_excerpt( $value, 80, '…' ) ) . '</summary><p>' . nl2br( esc_html( $value ) ) . '</p></details>';
			return;
		}
		if ( 'lead_type' === $key ) {
			$types = array( 'contact'=>__( 'تواصل عام', 'auto-dealership-core' ), 'finance'=>__( 'طلب تمويل', 'auto-dealership-core' ), 'finance_request'=>__( 'طلب تمويل', 'auto-dealership-core' ), 'price_request'=>__( 'طلب سعر', 'auto-dealership-core' ), 'test_drive'=>__( 'تجربة قيادة', 'auto-dealership-core' ), 'purchase'=>__( 'طلب شراء', 'auto-dealership-core' ) );
			echo esc_html( $types[ $value ] ?? $value );
			return;
		}
		echo esc_html( '' !== $value && ! ( 'car_id' === $key && '0' === $value ) ? $value : '—' );
	}
}
