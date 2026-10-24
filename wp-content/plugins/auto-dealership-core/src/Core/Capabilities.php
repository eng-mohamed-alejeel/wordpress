<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Registers scoped dealership roles and capabilities. */
final class Capabilities {
	private static array $request_migration_failures = array();

	private static function refresh_roles(): void {
		$roles = wp_roles();
		wp_cache_delete( $roles->role_key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$roles->for_site();
	}

	private static function role_has_caps( string $slug, array $caps ): bool {
		self::refresh_roles();
		$role = get_role( $slug );
		if ( ! $role ) { return false; }
		foreach ( $caps as $cap ) {
			if ( ! array_key_exists( $cap, $role->capabilities ) || ! $role->capabilities[$cap] ) { return false; }
		}
		return true;
	}

	private static function option_matches( string $name, $expected ): bool {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( $name, null ) === $expected;
	}

	private static function migration_failed( string $name ): void {
		error_log( 'Auto Dealership Core: permission migration failed (' . $name . '); it will retry on a later request.' );
		$failures = get_option( 'adc_permission_migration_failures', array() );
		if ( ! is_array( $failures ) ) { $failures = array(); }
		$failures[$name] = time();
		self::$request_migration_failures[$name] = $failures[$name];
		update_option( 'adc_permission_migration_failures', $failures, false );
		if ( ! self::option_matches( 'adc_permission_migration_failures', $failures ) ) {
			error_log( 'Auto Dealership Core: unable to persist permission migration failure status (' . $name . '); the administrator notice is limited to this request.' );
		}
	}

	private static function complete_migration( string $version_option ): bool {
		update_option( $version_option, '1', false );
		if ( ! self::option_matches( $version_option, '1' ) ) { return false; }
		$names = array(
			'adc_newsletter_permissions_version'=>'newsletter',
			'adc_marketing_permissions_version'=>'marketing',
			'adc_purchase_permissions_version'=>'purchasing',
			'adc_sales_permissions_version'=>'sales',
		);
		$name = $names[$version_option] ?? '';
		if ( '' !== $name ) {
			$failures = get_option( 'adc_permission_migration_failures', array() );
			if ( is_array( $failures ) && isset( $failures[$name] ) ) {
				unset( $failures[$name] );
				if ( $failures ) { update_option( 'adc_permission_migration_failures', $failures, false ); }
				else { delete_option( 'adc_permission_migration_failures' ); }
			}
		}
		return true;
	}

	public static function migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$failures = get_option( 'adc_permission_migration_failures', array() );
		if ( ! is_array( $failures ) ) { $failures = array(); }
		$failures = array_merge( $failures, self::$request_migration_failures );
		$versions = array(
			'newsletter'=>'adc_newsletter_permissions_version',
			'marketing'=>'adc_marketing_permissions_version',
			'purchasing'=>'adc_purchase_permissions_version',
			'sales'=>'adc_sales_permissions_version',
		);
		$labels = array(
			'newsletter'=>__( 'النشرة البريدية', 'auto-dealership-core' ),
			'marketing'=>__( 'التسويق', 'auto-dealership-core' ),
			'purchasing'=>__( 'المشتريات', 'auto-dealership-core' ),
			'sales'=>__( 'المبيعات', 'auto-dealership-core' ),
		);
		$pending = array();
		foreach ( $versions as $name => $option ) {
			if ( isset( $failures[$name] ) && '1' !== get_option( $option ) ) { $pending[] = $labels[$name]; }
		}
		if ( ! $pending ) { return; }
		echo '<div class="notice notice-error"><p>' .
			esc_html( sprintf( __( 'تعذر إكمال ترحيل صلاحيات: %s. قد تكون بعض الإضافات قد حُفظت جزئيًا؛ سيُعاد التحقق والمحاولة تلقائيًا في الطلب التالي. افحص سجل أخطاء PHP وصلاحية الكتابة في قاعدة البيانات، ولا تعدّل المنح يدويًا قبل مراجعة الحالة.', 'auto-dealership-core' ), implode( '، ', $pending ) ) ) .
			'</p></div>';
	}

	public static function migrate_newsletter_permissions(): void {
		if ( '1' === get_option( 'adc_newsletter_permissions_version' ) ) { return; }
		$definitions = get_option( 'adc_role_definitions', array() );
		foreach ( array( 'dealership_marketing', 'dealership_general_manager' ) as $slug ) {
			$role = get_role( $slug );
			if ( $role && ! isset( $definitions[$slug] ) ) {
				$required = array();
				if ( ! array_key_exists( 'adc_manage_newsletter', $role->capabilities ) ) { $role->add_cap( 'adc_manage_newsletter' ); $required[] = 'adc_manage_newsletter'; }
				if ( $required && ! self::role_has_caps( $slug, $required ) ) { self::migration_failed( 'newsletter' ); return; }
			}
		}
		if ( ! self::complete_migration( 'adc_newsletter_permissions_version' ) ) { self::migration_failed( 'newsletter' ); }
	}
	private const MARKETING_CAPABILITIES = array( 'adc_create_marketing_leads', 'adc_view_marketing_leads', 'adc_edit_marketing_lead_identity', 'adc_view_campaign_metrics', 'adc_add_marketing_activity' );
	public static function migrate_marketing_permissions(): void {
		if ( '1' === get_option( 'adc_marketing_permissions_version' ) ) { return; }
		$definitions = get_option( 'adc_role_definitions', array() );
		$role = get_role( 'dealership_marketing' );
		if ( $role && ! isset( $definitions['dealership_marketing'] ) ) { foreach ( self::MARKETING_CAPABILITIES as $cap ) { $role->add_cap( $cap ); } }
		if ( $role && ! isset( $definitions['dealership_marketing'] ) && ! self::role_has_caps( 'dealership_marketing', self::MARKETING_CAPABILITIES ) ) { self::migration_failed( 'marketing' ); return; }
		if ( ! self::complete_migration( 'adc_marketing_permissions_version' ) ) { self::migration_failed( 'marketing' ); }
	}
	private const PURCHASE_CAPABILITIES = array( 'adc_view_purchase_orders', 'adc_create_purchase_orders', 'adc_approve_purchase_orders', 'adc_approve_high_purchase_orders', 'adc_receive_purchase_orders', 'adc_cancel_purchase_orders' );
	private const PURCHASE_ROLES = array(
		'dealership_purchasing'=>array( 'adc_view_purchase_orders', 'adc_create_purchase_orders', 'adc_receive_purchase_orders', 'adc_cancel_purchase_orders' ),
		'dealership_sales_manager'=>array( 'adc_view_purchase_orders', 'adc_approve_purchase_orders' ),
		'dealership_general_manager'=>array( 'adc_view_purchase_orders', 'adc_approve_purchase_orders', 'adc_approve_high_purchase_orders', 'adc_cancel_purchase_orders' ),
		'dealership_finance'=>array( 'adc_view_purchase_orders' ),
	);
	public static function migrate_purchase_permissions(): void {
		if ( '1' === get_option( 'adc_purchase_permissions_version' ) ) { return; }
		// Existing administrator-managed role definitions remain authoritative.
		$definitions = get_option( 'adc_role_definitions', array() );
		foreach ( self::PURCHASE_ROLES as $slug=>$caps ) {
			$role = get_role( $slug ); if ( ! $role || isset( $definitions[$slug] ) ) { continue; }
			$required = array();
			foreach ( $caps as $cap ) { if ( ! array_key_exists( $cap, $role->capabilities ) ) { $role->add_cap( $cap ); $required[] = $cap; } }
			if ( $required && ! self::role_has_caps( $slug, $required ) ) { self::migration_failed( 'purchasing' ); return; }
		}
		if ( ! self::complete_migration( 'adc_purchase_permissions_version' ) ) { self::migration_failed( 'purchasing' ); }
	}
	private const SALES_CAPABILITIES = array( 'adc_create_quotes', 'adc_request_discounts', 'adc_start_sales' );

	/** One-time additive split preserves legacy grants, including custom roles and direct grants. */
	public static function migrate_sales_permissions(): void {
		if ( get_option( 'adc_sales_permissions_version' ) === '1' ) { return; }
		$definitions = get_option( 'adc_role_definitions', array() );
		foreach ( wp_roles()->roles as $slug => $definition ) {
			if ( empty( $definition['capabilities']['adc_create_reservations'] ) && empty( $definition['capabilities']['adc_manage_branch_leads'] ) && empty( $definition['capabilities']['manage_options'] ) ) { continue; }
			$role = get_role( $slug );
			foreach ( self::SALES_CAPABILITIES as $cap ) {
				if ( array_key_exists( $cap, $definition['capabilities'] ) && ! $definition['capabilities'][$cap] ) { continue; }
				if ( ! array_key_exists( $cap, $definition['capabilities'] ) ) { $role->add_cap( $cap ); }
				if ( ! self::role_has_caps( $slug, array( $cap ) ) ) { self::migration_failed( 'sales' ); return; }
				if ( isset( $definitions[$slug]['caps'] ) && ! in_array( $cap, $definitions[$slug]['caps'], true ) ) { $definitions[$slug]['caps'][] = $cap; }
			}
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND (meta_value LIKE %s OR meta_value LIKE %s)", $wpdb->get_blog_prefix() . 'capabilities', '%adc_create_reservations%', '%adc_manage_branch_leads%' ) );
		if ( $wpdb->last_error ) { self::migration_failed( 'sales' ); return; }
		foreach ( $ids as $id ) {
			$user = new \WP_User( (int) $id );
			if ( empty( $user->caps['adc_create_reservations'] ) && empty( $user->caps['adc_manage_branch_leads'] ) ) { continue; }
			foreach ( self::SALES_CAPABILITIES as $cap ) { if ( ! array_key_exists( $cap, $user->caps ) ) { $user->add_cap( $cap ); } }
			clean_user_cache( (int) $id );
			$stored_user = get_userdata( (int) $id );
			if ( ! $stored_user ) { self::migration_failed( 'sales' ); return; }
			foreach ( self::SALES_CAPABILITIES as $cap ) {
				if ( ! empty( $user->caps[$cap] ) && empty( $stored_user->caps[$cap] ) ) { self::migration_failed( 'sales' ); return; }
			}
		}
		update_option( 'adc_role_definitions', $definitions, false );
		if ( ! self::option_matches( 'adc_role_definitions', $definitions ) ) { self::migration_failed( 'sales' ); return; }
		if ( ! self::complete_migration( 'adc_sales_permissions_version' ) ) { self::migration_failed( 'sales' ); }
	}
	private const CONTENT_CAPABILITIES = array(
		'edit_car', 'read_car', 'delete_car', 'edit_cars', 'edit_others_cars', 'publish_cars',
		'read_private_cars', 'delete_cars', 'delete_private_cars', 'delete_published_cars',
		'delete_others_cars', 'edit_private_cars', 'edit_published_cars',
		'edit_car_offer', 'read_car_offer', 'delete_car_offer', 'edit_car_offers', 'edit_others_car_offers',
		'publish_car_offers', 'read_private_car_offers', 'delete_car_offers', 'delete_private_car_offers',
		'delete_published_car_offers', 'delete_others_car_offers', 'edit_private_car_offers', 'edit_published_car_offers',
		'manage_car_brands', 'assign_car_brands', 'manage_car_categories', 'assign_car_categories',
	);

	private const ROLE_CAPABILITIES = array(
		'car_dealer_customer' => array( 'read' ),
		'dealership_sales' => array( 'read', 'adc_view_workspace', 'adc_view_own_leads', 'adc_manage_own_leads', 'adc_create_reservations' ),
		'dealership_sales_manager' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_assign_marketing_leads', 'adc_create_reservations', 'adc_review_discounts', 'adc_manage_reservations', 'adc_approve_sales', 'adc_cancel_sales', 'adc_approve_delivery', 'adc_process_returns', 'adc_view_reports' ),
		'dealership_general_manager' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_assign_marketing_leads', 'adc_view_inventory', 'adc_change_vehicle_vin', 'adc_create_reservations', 'adc_review_discounts', 'adc_approve_high_discounts', 'adc_manage_reservations', 'adc_approve_sales', 'adc_cancel_sales', 'adc_approve_delivery', 'adc_process_returns', 'adc_manage_pricing', 'adc_view_finance', 'adc_view_vehicle_costs', 'adc_manage_vehicle_costs', 'adc_view_suppliers', 'adc_manage_suppliers', 'adc_view_audit', 'adc_view_outbox', 'adc_manage_outbox', 'adc_view_integrations', 'adc_manage_integrations', 'adc_view_reports', 'adc_view_marketing_subscribers' ),
		'dealership_inventory' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_manage_inventory', 'adc_transfer_inventory', 'adc_confirm_vehicle_vin', 'adc_view_suppliers' ),
		'dealership_finance' => array( 'read', 'adc_view_workspace', 'adc_view_finance', 'adc_manage_finance', 'adc_record_payments', 'adc_verify_payments', 'adc_record_refunds', 'adc_verify_refunds' ),
		'dealership_purchasing' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_view_suppliers', 'adc_manage_suppliers', 'adc_view_vehicle_costs', 'adc_manage_vehicle_costs' ),
		'dealership_delivery' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_confirm_vehicle_vin', 'adc_process_returns' ),
		'dealership_customer_service' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_create_reservations' ),
		'dealership_marketing' => array( 'read', 'adc_view_workspace', 'adc_view_marketing_subscribers', 'adc_create_marketing_leads', 'adc_view_marketing_leads', 'adc_edit_marketing_lead_identity', 'adc_view_campaign_metrics', 'adc_add_marketing_activity' ),
		'dealership_auditor' => array( 'read', 'adc_view_workspace', 'adc_view_audit', 'adc_view_outbox', 'adc_view_integrations', 'adc_view_reports' ),
	);

	public static function activate(): void {
		$definitions = get_option( 'adc_role_definitions', array() );
		foreach ( self::role_matrix() as $slug => $capabilities ) {
			if ( isset( $definitions[$slug]['caps'] ) ) { $capabilities = array_merge( array( 'read' ), $definitions[$slug]['caps'] ); }
			$role = get_role( $slug );
			if ( ! $role ) {
				$role = add_role( $slug, self::role_label( $slug ), array( 'read' => true ) );
			}
			if ( $role ) {
				foreach ( $capabilities as $capability ) {
					if ( ! array_key_exists( $capability, $role->capabilities ) || $role->capabilities[$capability] ) { $role->add_cap( $capability ); }
				}
			}
		}

		self::sync_administrator();
		update_option( 'adc_roles_version', ADC_VERSION, false );
	}

	/** Keep the system administrator complete when new dealership permissions are added. */
	public static function sync_administrator(): void {
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::all_capabilities() as $capability ) {
				if ( ! $administrator->has_cap( $capability ) ) {
					$administrator->add_cap( $capability );
				}
			}
		}
	}

	public static function role_label( string $slug ): string {
		$definitions = get_option( 'adc_role_definitions', array() );
		$lang = Localization::language();
		if ( ! empty( $definitions[$slug][$lang] ) ) { return $definitions[$slug][$lang]; }
		$labels = array(
			'car_dealer_customer' => __( 'Dealership Customer', 'auto-dealership-core' ),
			'dealership_sales' => __( 'Dealership Sales', 'auto-dealership-core' ),
			'dealership_sales_manager' => __( 'Dealership Sales Manager', 'auto-dealership-core' ),
			'dealership_general_manager' => __( 'Dealership General Manager', 'auto-dealership-core' ),
			'dealership_inventory' => __( 'Dealership Inventory', 'auto-dealership-core' ),
			'dealership_finance' => __( 'Dealership Finance', 'auto-dealership-core' ),
			'dealership_purchasing' => __( 'Dealership Purchasing', 'auto-dealership-core' ),
			'dealership_delivery' => __( 'Dealership Delivery', 'auto-dealership-core' ),
			'dealership_customer_service' => __( 'Dealership Customer Service', 'auto-dealership-core' ),
			'dealership_chat_adviser' => Localization::language()==='en'?'Chat adviser':'مستشار المحادثات',
			'dealership_marketing' => __( 'Dealership Marketing', 'auto-dealership-core' ),
			'dealership_auditor' => __( 'Dealership Auditor', 'auto-dealership-core' ),
		);
		return $labels[ $slug ] ?? $slug;
	}

	/** Translate display names per request without changing stored role identifiers. */
	public static function localize_roles(): void {
		$roles = wp_roles();
		$definitions = get_option( 'adc_role_definitions', array() );
		foreach ( array_unique( array_merge( array_keys( self::role_matrix() ), array_keys( $definitions ) ) ) as $slug ) {
			if ( isset( $roles->roles[$slug] ) ) {
				$roles->roles[$slug]['name'] = self::role_label( $slug );
				$roles->role_names[$slug] = self::role_label( $slug );
			}
		}
	}

	/** Meta capabilities require an object ID and must never be assigned directly. */
	public static function assignable_capabilities(): array {
		return array_values( array_diff( self::all_capabilities(), array( 'read', 'edit_car', 'read_car', 'delete_car', 'edit_car_offer', 'read_car_offer', 'delete_car_offer' ) ) );
	}

	/** Factory defaults; registered roles may have administrator-managed overrides. */
	public static function role_matrix(): array {
		$matrix = self::ROLE_CAPABILITIES;
		$matrix['dealership_chat_adviser']=array('read','adc_view_workspace','adc_view_own_leads','adc_manage_own_leads','adc_view_conversations','adc_reply_conversations','adc_close_conversations');
		$matrix['dealership_marketing'][] = 'adc_manage_newsletter';
		$matrix['dealership_general_manager'][] = 'adc_manage_newsletter';
		foreach ( $matrix as &$caps ) { if ( in_array( 'adc_create_reservations', $caps, true ) || in_array( 'adc_manage_branch_leads', $caps, true ) ) { $caps = array_merge( $caps, self::SALES_CAPABILITIES ); } }
		unset( $caps );
		foreach ( self::PURCHASE_ROLES as $role=>$caps ) { $matrix[$role] = array_merge( $matrix[$role], $caps ); }
		foreach ( \AutoDealership\Portal\PortalAccess::role_caps() as $role=>$caps ) { $matrix[$role] = array_merge( $matrix[$role] ?? array(), $caps ); }
		foreach(array('dealership_general_manager','dealership_finance','dealership_sales_manager') as $role){$matrix[$role][]='adc_publish_customer_documents';}
		return $matrix;
	}

	public static function all_capabilities(): array {
		$caps = array_merge( array( 'adc_manage_newsletter','adc_publish_customer_documents' ), self::MARKETING_CAPABILITIES, self::CONTENT_CAPABILITIES, self::SALES_CAPABILITIES, self::PURCHASE_CAPABILITIES, \AutoDealership\Portal\PortalAccess::CAPS );
		foreach ( self::ROLE_CAPABILITIES as $role_caps ) {
			$caps = array_merge( $caps, $role_caps );
		}
		return array_values( array_unique( $caps ) );
	}
}
