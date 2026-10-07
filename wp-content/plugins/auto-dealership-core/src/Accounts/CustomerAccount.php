<?php
namespace AutoDealership\Accounts;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Leads\ContactIdentity;
use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Leads\RequestWorkflow;
use AutoDealership\Security\PublicRequestGuard;

defined( 'ABSPATH' ) || exit;

/** Owns customer registration, sign-in and account mutations behind the theme view. */
final class CustomerAccount {
	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_customer_account_enabled', true );
	}

	public static function owns_theme_actions(): bool {
		return self::enabled();
	}

	/** Account workspace category; the theme supplies only its visible label. */
	public static function kind( \WP_User $user ): string {
		if ( user_can( $user, 'manage_options' ) ) { return 'administrator'; }
		if ( user_can( $user, 'adc_view_branch_leads' ) || ( user_can( $user, 'edit_others_cars' ) && user_can( $user, 'manage_car_dealer' ) ) ) { return 'manager'; }
		if ( user_can( $user, 'adc_view_own_leads' ) || user_can( $user, 'manage_car_dealer' ) ) { return 'sales'; }
		if ( user_can( $user, 'adc_view_workspace' ) ) { return 'staff'; }
		return 'customer';
	}

	/** Whether a customer-only account should use the public account workspace. */
	public static function should_redirect_admin( \WP_User $user ): bool {
		return (bool) array_intersect( array( 'car_dealer_customer', 'subscriber' ), (array) $user->roles )
			&& ! user_can( $user, 'edit_posts' )
			&& ! user_can( $user, 'manage_car_dealer' );
	}

	/** Allowed account navigation targets, scoped by the plugin's capabilities. */
	public static function workspace_targets( \WP_User $user ): array {
		$rules = array(
			'workspace'   => array( 'adc_view_workspace' ),
			'crm'         => array( 'adc_view_own_leads', 'adc_view_branch_leads' ),
			'messages'    => array( 'adc_view_own_leads', 'adc_view_branch_leads' ),
			'bookings'    => array( 'adc_view_own_leads', 'adc_view_branch_leads' ),
			'subscribers' => array( 'adc_view_marketing_subscribers' ),
			'car_editor'  => array( 'edit_cars' ),
			'inventory'   => array( 'adc_view_inventory' ),
			'users'       => array( 'manage_options' ),
			'settings'    => array( 'manage_options' ),
		);
		$allowed = array();
		foreach ( $rules as $target => $capabilities ) {
			foreach ( $capabilities as $capability ) {
				if ( user_can( $user, $capability ) ) { $allowed[] = $target; break; }
			}
		}
		return $allowed;
	}

	/** Processes the existing account form contract and returns a localized error. */
	public static function process_request( string $view ): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( self::field( '_wpnonce' ), 'cd_account_' . $view ) ) {
			return __( 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مجددًا.', 'auto-dealership-core' );
		}
		if ( 'dashboard' === $view && is_user_logged_in() ) {
			return self::process_dashboard();
		}
		if ( ! in_array( $view, array( 'login', 'register' ), true ) || is_user_logged_in() ) {
			return '';
		}

		$limited = PublicRequestGuard::consume( 'account_auth' );
		if ( is_wp_error( $limited ) ) {
			return 'adc_rate_limited' === $limited->get_error_code()
				? __( 'محاولات كثيرة. يرجى المحاولة لاحقًا.', 'auto-dealership-core' )
				: __( 'حماية الحساب غير متاحة مؤقتًا. حاول لاحقًا.', 'auto-dealership-core' );
		}

		if ( 'login' === $view ) {
			$user = wp_signon(
				array(
					'user_login'    => sanitize_text_field( self::field( 'login' ) ),
					'user_password' => self::field( 'password' ),
					'remember'      => (bool) self::field( 'remember' ),
				),
				is_ssl()
			);
			if ( is_wp_error( $user ) ) {
				return __( 'تعذر تسجيل الدخول. تحقق من بياناتك أو استخدم استعادة كلمة المرور.', 'auto-dealership-core' );
			}
		} else {
			$result = self::register();
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}
			$user = get_userdata( $result );
			if ( ! $user ) {
				return __( 'تعذر إنشاء الحساب. حاول مجددًا أو تواصل مع المعرض.', 'auto-dealership-core' );
			}
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, false, is_ssl() );
			do_action( 'wp_login', $user->user_login, $user );
		}

		wp_safe_redirect( self::account_url() );
		exit;
	}

	private static function process_dashboard(): string {
		$action  = self::field( 'account_action' );
		$user_id = get_current_user_id();
		if ( 'cancel_booking' === $action ) {
			$lead_id = RequestWorkflow::linked_lead( 'booking', absint( self::field( 'request_id' ) ) );
			if ( is_wp_error( $lead_id ) || ! $lead_id ) {
				return is_wp_error( $lead_id ) ? $lead_id->get_error_message() : __( 'هذا الحجز تاريخي وغير مرتبط بمسار Core؛ لا يمكن تغييره من الحساب.', 'auto-dealership-core' );
			}
			$result = RequestWorkflow::update( $lead_id, array(), true );
			if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
			wp_safe_redirect( add_query_arg( 'request_updated', 1, self::account_url() ) );
			exit;
		}
		if ( 'save_preferences' === $action ) {
			$value = self::field( 'consent_marketing' );
			if ( ! in_array( $value, array( '0', '1' ), true ) ) {
				return __( 'تعذر حفظ التفضيلات. اختر أحد الخيارين وحاول مجددًا.', 'auto-dealership-core' );
			}
			$result = CustomerIdentity::update_preferences( '1' === $value );
			if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
			wp_safe_redirect( add_query_arg( 'preferences_saved', 1, self::account_url() ) );
			exit;
		}

		$name = sanitize_text_field( self::field( 'display_name' ) );
		$phone = ContactIdentity::normalize_mobile( sanitize_text_field( self::field( 'phone' ) ), false );
		if ( '' === $name || self::length( $name ) > 250 ) { return __( 'يرجى إدخال اسم صالح.', 'auto-dealership-core' ); }
		if ( is_wp_error( $phone ) ) { return __( 'أدخل رقم هاتف صحيحًا بصيغة محلية أو دولية.', 'auto-dealership-core' ); }
		$result = CustomerIdentity::update_account_profile( $user_id, $name, $phone );
		if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
		wp_safe_redirect( add_query_arg( 'saved', 1, self::account_url() ) );
		exit;
	}

	/** @return int|\WP_Error */
	private static function register() {
		global $wpdb;
		$name     = sanitize_text_field( self::field( 'display_name' ) );
		$email    = strtolower( sanitize_email( self::field( 'email' ) ) );
		$password = self::field( 'password' );
		if ( self::field( 'company_website' ) ) {
			return self::error( 'adc_account_rejected', __( 'تعذر إنشاء الحساب.', 'auto-dealership-core' ) );
		}
		if ( empty( $_POST['privacy_consent'] ) ) {
			return self::error( 'adc_account_consent', __( 'يجب الموافقة على سياسة الخصوصية وشروط الاستخدام لإنشاء حساب.', 'auto-dealership-core' ) );
		}
		if ( '' === $name || self::length( $name ) > 250 || ! is_email( $email ) || strlen( $email ) > 100 || strlen( $password ) < 10 || strlen( $password ) > 4096 || ! hash_equals( $password, self::field( 'password_confirm' ) ) ) {
			return self::error( 'adc_account_invalid', __( 'أدخل اسمًا وبريدًا صحيحًا وكلمة مرور من 10 أحرف على الأقل مع تأكيد مطابق.', 'auto-dealership-core' ) );
		}
		$phone = ContactIdentity::normalize_mobile( sanitize_text_field( self::field( 'phone' ) ), true );
		if ( is_wp_error( $phone ) ) {
			return self::error( 'adc_account_invalid_phone', __( 'أدخل رقم هاتف صحيحًا بصيغة محلية أو دولية.', 'auto-dealership-core' ) );
		}
		if ( email_exists( $email ) || ! get_role( 'car_dealer_customer' ) || ! Schema::is_ready() ) {
			return self::error( 'adc_account_unavailable', __( 'تعذر استخدام هذا البريد. جرّب تسجيل الدخول أو استعادة كلمة المرور.', 'auto-dealership-core' ) );
		}
		foreach ( array( $wpdb->users, $wpdb->usermeta ) as $table ) {
			if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) {
				return self::error( 'adc_account_unavailable', __( 'تعذر إنشاء الحساب حاليًا.', 'auto-dealership-core' ) );
			}
		}
		if ( ! Transaction::begin() ) {
			return self::error( 'adc_account_unavailable', __( 'تعذر إنشاء الحساب حاليًا.', 'auto-dealership-core' ) );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email=%s LIMIT 1 FOR UPDATE", $email ) );
		if ( $wpdb->last_error || $existing ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_account_unavailable', __( 'تعذر استخدام هذا البريد. جرّب تسجيل الدخول أو استعادة كلمة المرور.', 'auto-dealership-core' ) );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'customer_' . strtolower( wp_generate_password( 24, false, false ) ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'role'         => 'car_dealer_customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_account_unavailable', __( 'تعذر إنشاء الحساب. حاول مجددًا أو تواصل مع المعرض.', 'auto-dealership-core' ) );
		}
		$meta = CustomerIdentity::without_profile_sync( static fn() => update_user_meta( $user_id, 'car_dealer_phone', $phone ) );
		if ( false === $meta || (string) get_user_meta( $user_id, 'car_dealer_phone', true ) !== $phone ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			return self::error( 'adc_account_unavailable', __( 'تعذر إنشاء الحساب. حاول مجددًا أو تواصل مع المعرض.', 'auto-dealership-core' ) );
		}
		$committed = Transaction::commit( static fn() => AuditLog::record( 'customer.account_registered', 'user', $user_id, '', null, array( 'role'=>'car_dealer_customer', 'privacy_consent'=>true ) ) );
		clean_user_cache( $user_id );
		return $committed ? (int) $user_id : self::error( 'adc_account_unavailable', __( 'تعذر إنشاء الحساب. حاول مجددًا أو تواصل مع المعرض.', 'auto-dealership-core' ) );
	}

	private static function field( string $name ): string {
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private static function account_url(): string {
		return (string) apply_filters( 'adc_customer_account_url', home_url( '/' ), '' );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}
