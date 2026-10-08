<?php
namespace AutoDealership\Admin;

use AutoDealership\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Profile permissions use WordPress grants/denials, including REST authorization. */
final class UserPermissions {
	private static array $pending = array();

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'user_profile_update_errors', array( self::class, 'validate' ), 20, 3 );
		add_action( 'profile_update', array( self::class, 'save' ), 20, 1 );
		add_action( 'user_new_form', array( self::class, 'render_new' ) );
		add_action( 'user_register', array( self::class, 'save_new' ), 20, 1 );
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( $screen && ( str_ends_with( $screen->id, '_page_adc-roles' ) || str_ends_with( $screen->id, '_page_adc-access-review' ) ) ) {
			$css = 'assets/css/user-permissions.css';
			wp_enqueue_style( 'adc-user-permissions', plugins_url( $css, ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/' . $css ) );
			$file = 'assets/js/user-permissions.js';
			wp_enqueue_script( 'adc-user-permissions', plugins_url( $file, ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/' . $file ), true );
			wp_localize_script( 'adc-user-permissions', 'adcRolePermissions', array( 'roles' => self::role_defaults(), 'protectedRoles' => array( 'administrator' ) ) );
		}
		if ( $screen && in_array( $screen->base, array( 'profile', 'user-edit', 'user' ), true ) ) {
			$file = 'assets/js/user-permissions.js';
			wp_enqueue_script( 'adc-user-permissions', plugins_url( $file, ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/' . $file ), true );
			if ( in_array( $screen->base, array( 'user', 'user-edit', 'profile' ), true ) ) {
				wp_localize_script( 'adc-user-permissions', 'adcRolePermissions', array(
					'roles' => self::role_defaults(),
					'protectedRoles' => array( 'administrator' ),
					'title' => __( 'Default role permissions', 'auto-dealership-core' ),
					'empty' => __( 'This role has no default permissions.', 'auto-dealership-core' ),
					'note' => __( 'Role defaults apply unless individual permissions are customized. Branch restrictions still apply.', 'auto-dealership-core' ),
				) );
				$css = 'assets/css/user-permissions.css';
				wp_enqueue_style( 'adc-user-permissions', plugins_url( $css, ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/' . $css ) );
			}
		}
		if ( wp_script_is( 'adc-user-permissions', 'enqueued' ) ) {
			wp_localize_script( 'adc-user-permissions', 'adcPermissionFilters', array( 'search' => __( 'Search permissions', 'auto-dealership-core' ), 'all' => __( 'All permissions', 'auto-dealership-core' ), 'granted' => __( 'Granted only', 'auto-dealership-core' ), 'custom' => __( 'Customized only', 'auto-dealership-core' ), 'denied' => __( 'Denied only', 'auto-dealership-core' ) ) );
		}
	}

	/** Preview the actual registered defaults, including roles changed by other plugins. */
	public static function role_defaults(): array {
		if ( ! function_exists( 'get_editable_roles' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
		$catalog = self::catalog();
		$result = array();
		foreach ( get_editable_roles() as $slug => $role ) {
			$groups = array();
			foreach ( $role['capabilities'] as $cap => $granted ) {
				// Object permissions and legacy levels are represented by their primitive permissions.
				if ( ! $granted || preg_match( '/^level_\d+$/', $cap ) || in_array( $cap, array( 'edit_car', 'read_car', 'delete_car', 'edit_car_offer', 'read_car_offer', 'delete_car_offer' ), true ) ) { continue; }
				$entry = $catalog[$cap] ?? array( 'WordPress permissions', self::native_label( $cap ) );
				$groups[__( $entry[0], 'auto-dealership-core' )][] = array( 'key' => $cap, 'label' => __( $entry[1], 'auto-dealership-core' ) );
			}
			$result[$slug] = array( 'name' => translate_user_role( $role['name'] ), 'groups' => $groups );
		}
		return $result;
	}

	public static function native_label( string $cap ): string {
		$labels = array(
			'read' => array( 'القراءة الأساسية والدخول إلى الحساب', 'Basic reading and account access' ),
			'manage_options' => array( 'إدارة إعدادات الموقع', 'Manage site settings' ),
			'upload_files' => array( 'رفع ملفات الوسائط', 'Upload media files' ),
			'edit_dashboard' => array( 'تحرير لوحة التحكم', 'Edit dashboard' ),
			'moderate_comments' => array( 'الإشراف على التعليقات', 'Moderate comments' ),
			'manage_categories' => array( 'إدارة التصنيفات', 'Manage categories' ),
			'manage_links' => array( 'إدارة الروابط', 'Manage links' ),
			'edit_theme_options' => array( 'تحرير خيارات القالب', 'Edit theme options' ),
			'unfiltered_html' => array( 'استخدام HTML غير المصفّى', 'Use unfiltered HTML' ),
			'unfiltered_upload' => array( 'رفع ملفات دون تصفية إضافية', 'Unfiltered uploads' ),
			'edit_files' => array( 'تحرير الملفات', 'Edit files' ),
			'import' => array( 'استيراد البيانات', 'Import data' ),
			'export' => array( 'تصدير البيانات', 'Export data' ),
			'update_core' => array( 'تحديث WordPress', 'Update WordPress' ),
		);
		$arabic = 'ar' === \AutoDealership\Core\Localization::language();
		if ( isset( $labels[$cap] ) ) { return $labels[$cap][$arabic ? 0 : 1]; }
		$actions = array(
			'edit_others' => array( 'تحرير محتوى الآخرين:', 'Edit others:' ),
			'edit_published' => array( 'تحرير المحتوى المنشور:', 'Edit published:' ),
			'edit_private' => array( 'تحرير المحتوى الخاص:', 'Edit private:' ),
			'delete_others' => array( 'حذف محتوى الآخرين:', 'Delete others:' ),
			'delete_published' => array( 'حذف المحتوى المنشور:', 'Delete published:' ),
			'delete_private' => array( 'حذف المحتوى الخاص:', 'Delete private:' ),
			'read_private' => array( 'قراءة المحتوى الخاص:', 'Read private:' ),
			'switch' => array( 'تبديل', 'Switch' ), 'activate' => array( 'تفعيل', 'Activate' ),
			'edit' => array( 'تحرير', 'Edit' ), 'publish' => array( 'نشر', 'Publish' ),
			'delete' => array( 'حذف', 'Delete' ), 'create' => array( 'إنشاء', 'Create' ),
			'update' => array( 'تحديث', 'Update' ), 'install' => array( 'تثبيت', 'Install' ),
			'list' => array( 'عرض قائمة', 'List' ), 'remove' => array( 'إزالة', 'Remove' ),
			'promote' => array( 'تغيير أدوار', 'Change roles of' ),
		);
		$objects = array( 'posts' => array( 'المقالات', 'posts' ), 'pages' => array( 'الصفحات', 'pages' ), 'users' => array( 'المستخدمين', 'users' ), 'themes' => array( 'القوالب', 'themes' ), 'plugins' => array( 'الإضافات', 'plugins' ) );
		foreach ( $actions as $action => $label ) {
			foreach ( $objects as $object => $name ) {
				if ( $cap === $action . '_' . $object ) { return $label[$arabic ? 0 : 1] . ' ' . $name[$arabic ? 0 : 1]; }
			}
		}
		return ucwords( str_replace( '_', ' ', $cap ) );
	}

	private static function authorized( \WP_User $user ): bool {
		$actor = wp_get_current_user();
		return $user->exists() && ( in_array( 'administrator', $actor->roles, true ) || ( is_multisite() && is_super_admin( $actor->ID ) ) )
			&& current_user_can( 'promote_users' ) && current_user_can( 'edit_user', $user->ID );
	}

	private static function protected_user( \WP_User $user ): bool {
		return in_array( 'administrator', $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
	}

	public static function catalog(): array {
		return require __DIR__ . '/../../languages/permissions.php';
	}

	public static function render_new( string $type ): void {
		if ( 'add-new-user' !== $type || is_multisite() || ! self::can_create() ) { return; }
		$user = new \WP_User();
		$role = get_role( get_option( 'default_role', 'subscriber' ) );
		$user->allcaps = $role ? $role->capabilities : array();
		self::render( $user, true );
	}

	private static function can_create(): bool {
		return current_user_can( 'create_users' ) && current_user_can( 'promote_users' ) && ( in_array( 'administrator', wp_get_current_user()->roles, true ) || ( is_multisite() && is_super_admin() ) );
	}

	public static function render( \WP_User $user, bool $creating = false ): void {
		if ( $creating ? ! self::can_create() : ! self::authorized( $user ) ) { return; }
		if ( ! $creating ) { echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'adc-access-review', 'user_id' => $user->ID ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Effective permissions and account controls', 'auto-dealership-core' ) . '</a></p>'; }
		echo '<section class="adc-user-permissions" data-creating="' . ( $creating ? '1' : '0' ) . '"><h2>' . esc_html__( 'User permissions', 'auto-dealership-core' ) . '</h2>';
		if ( self::protected_user( $user ) ) {
			echo '<p>' . esc_html__( 'Administrator accounts are protected from individual permission changes.', 'auto-dealership-core' ) . '</p></section>';
			return;
		}
		wp_nonce_field( 'adc_user_permissions_' . $user->ID, 'adc_permissions_nonce' );
		$custom = (bool) array_intersect( array_keys( $user->caps ), Capabilities::assignable_capabilities() );
		$posted = isset( $_POST['adc_permissions_present'] ) && is_array( $_POST['adc_permissions'] ?? array() );
		if ( $posted ) { $custom = '1' === ( $_POST['adc_permissions_custom'] ?? '0' ); }
		echo '<input type="hidden" name="adc_permissions_present" value="1"><p><label><input type="checkbox" name="adc_permissions_custom" value="1" ' . checked( $custom, true, false ) . '> ' . esc_html__( 'Customize permissions for this user', 'auto-dealership-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'When customization is disabled, overrides are removed and role permissions apply. When enabled, checked permissions are granted and unchecked permissions are denied. Branch restrictions still apply.', 'auto-dealership-core' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Checkboxes edit permanent permissions. Temporary grants and suspension are shown in the effective access report.', 'auto-dealership-core' ) . '</p>';
		$groups = array();
		foreach ( self::catalog() as $cap => $entry ) { $groups[$entry[0]][$cap] = $entry[1]; }
		foreach ( $groups as $group => $items ) {
			echo '<fieldset class="adc-permission-group"><legend>' . esc_html__( $group, 'auto-dealership-core' ) . '</legend><div class="adc-permission-grid">';
			foreach ( $items as $cap => $label ) {
				$origin = array_key_exists( $cap, $user->caps ) ? 'User permission override' : 'Inherited role permission';
				$enabled = $posted && $custom ? in_array( $cap, $_POST['adc_permissions'] ?? array(), true ) : ! empty( $user->allcaps[$cap] );
				echo '<label data-custom="' . ( array_key_exists( $cap, $user->caps ) ? '1' : '0' ) . '"><input type="checkbox" name="adc_permissions[]" value="' . esc_attr( $cap ) . '" ' . checked( $enabled, true, false ) . '> <span>' . esc_html__( $label, 'auto-dealership-core' ) . '<small>' . esc_html__( $origin, 'auto-dealership-core' ) . '</small></span></label>';
			}
			echo '</div></fieldset>';
		}
		echo '</section>';
		echo '<p><label>' . esc_html__( 'Change reason', 'auto-dealership-core' ) . '<br><textarea name="adc_permissions_reason" maxlength="2000" rows="2" class="large-text"></textarea></label></p>';
	}

	/** Validate before WordPress writes the profile; commit only after successful save. */
	public static function validate( \WP_Error $errors, bool $update, $data ): void {
		if ( ! isset( $_POST['adc_permissions_present'] ) ) { return; }
		$id = (int) ( $data->ID ?? 0 );
		unset( self::$pending[$id] );
		$user = get_userdata( $id );
		$nonce = $_POST['adc_permissions_nonce'] ?? '';
		$selected = $_POST['adc_permissions'] ?? array();
		$custom = $_POST['adc_permissions_custom'] ?? '0';
		$valid = ( $update ? ( $user && self::authorized( $user ) && ! self::protected_user( $user ) ) : self::can_create() )
			&& ( ( $data->role ?? '' ) !== 'administrator' || '0' === $custom )
			&& is_string( $nonce ) && wp_verify_nonce( wp_unslash( $nonce ), 'adc_user_permissions_' . $id )
			&& in_array( $custom, array( '0', '1' ), true ) && is_array( $selected );
		if ( $valid ) {
			foreach ( $selected as $cap ) {
				if ( ! is_string( $cap ) || ! in_array( $cap, Capabilities::assignable_capabilities(), true ) ) { $valid = false; break; }
			}
		}
		if ( ! $valid ) {
			$errors->add( 'adc_permissions_invalid', __( 'Permissions could not be saved. Check administrator access and the request, then try again.', 'auto-dealership-core' ) );
			return;
		}
		$reason = $_POST['adc_permissions_reason'] ?? '';
		if ( ! is_string( $reason ) || mb_strlen( $reason ) > 2000 ) { $errors->add( 'adc_reason_invalid', __( 'A change reason is required.', 'auto-dealership-core' ) ); return; }
		$planned = $user ? $user->caps : array();
		foreach ( Capabilities::assignable_capabilities() as $cap ) { if ( '1' === $custom ) { $planned[$cap] = in_array( $cap, $selected, true ); } else { unset( $planned[$cap] ); } }
		if ( $planned !== ( $user ? $user->caps : array() ) && '' === trim( sanitize_textarea_field( wp_unslash( $reason ) ) ) ) { $errors->add( 'adc_reason_required', __( 'A change reason is required.', 'auto-dealership-core' ) ); return; }
		if ( ! $errors->has_errors() ) { self::$pending[$id] = array( 'custom' => '1' === $custom, 'selected' => $selected, 'login' => (string) ( $data->user_login ?? '' ), 'reason' => sanitize_textarea_field( wp_unslash( $reason ) ) ); }
	}

	public static function save_new( int $id ): void {
		if ( ! isset( self::$pending[0] ) || ! self::can_create() ) { return; }
		$user = get_userdata( $id );
		if ( ! $user || $user->user_login !== self::$pending[0]['login'] ) { return; }
		self::$pending[$id] = self::$pending[0];
		unset( self::$pending[0] );
		self::save( $id );
	}

	public static function save( int $id ): void {
		if ( ! isset( self::$pending[$id] ) ) { return; }
		$request = self::$pending[$id];
		unset( self::$pending[$id] );
		$user = get_userdata( $id );
		if ( ! $user || ! self::authorized( $user ) || self::protected_user( $user ) ) { return; }
		$caps = $user->caps;
		// Keep a full explicit snapshot in custom mode so later role changes cannot silently grant access.
		foreach ( Capabilities::assignable_capabilities() as $cap ) {
			if ( $request['custom'] ) {
				$value = in_array( $cap, $request['selected'], true );
				$caps[$cap] = $value;
			} else {
				unset( $caps[$cap] );
			}
		}
		// One metadata update produces one existing SecurityAudit event for the complete change.
		if ( $caps !== $user->caps ) {
			$before = $user->caps;
			if ( ! \AutoDealership\Database\Transaction::begin() ) { wp_die( esc_html__( 'The access change could not be saved.', 'auto-dealership-core' ), '', array( 'response' => 500 ) ); }
			update_user_meta( $id, $user->cap_key, $caps );
			$verified = get_user_meta( $id, $user->cap_key, true ) === $caps;
			if ( ! \AutoDealership\Database\Transaction::commit( static fn() => $verified && \AutoDealership\Audit\AuditLog::record( 'security.user_permissions_changed', 'user', $id, $request['reason'], $before, $caps ) ) ) {
				clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' );
				wp_die( esc_html__( 'The access change could not be saved.', 'auto-dealership-core' ), '', array( 'response' => 500 ) );
			}
			$user->caps = $caps;
			$user->get_role_caps();
		}
	}
}
