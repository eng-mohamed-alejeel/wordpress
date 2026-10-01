<?php
namespace AutoDealership\Leads;

use AutoDealership\Content\PostMetaStore;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Registers legacy CRM records as read-only history and retires linked profiles. */
final class LegacyCrmBridge {
	private static bool $booted = false;

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_legacy_crm_bridge_enabled', true );
	}

	public static function owns_theme_workflow(): bool {
		return self::enabled();
	}

	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return;
		}
		self::$booted = true;
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 20, 4 );
		add_action( 'adc_customer_account_linked', array( self::class, 'retire_account_profiles' ), 10, 2 );
		add_action( 'adc_customer_profile_synced', array( self::class, 'retire_account_profiles' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'reconcile' ), 30 );
	}

	public static function register(): void {
		if ( post_type_exists( 'cd_crm' ) ) {
			return;
		}
		register_post_type( 'cd_crm', array(
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_rest'       => false,
			'rewrite'            => false,
			'query_var'          => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => false,
			'capabilities'       => array(
				'edit_post'         => 'do_not_allow',
				'read_post'         => 'manage_options',
				'delete_post'       => 'do_not_allow',
				'edit_posts'        => 'do_not_allow',
				'edit_others_posts' => 'do_not_allow',
				'publish_posts'     => 'do_not_allow',
				'read_private_posts'=> 'manage_options',
				'delete_posts'      => 'do_not_allow',
				'create_posts'      => 'do_not_allow',
			),
		) );
	}

	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( absint( $args[0] ) );
		if ( ! $post || 'cd_crm' !== $post->post_type ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		return 'read_post' === $cap && $user && ! empty( $user->allcaps['manage_options'] ) ? array( 'manage_options' ) : array( 'do_not_allow' );
	}

	public static function retire_account_profiles( int $user_id, int $customer_id ): void {
		if ( $user_id < 1 || $customer_id < 1 ) {
			return;
		}
		$ids = get_posts( array(
			'post_type'      => 'cd_crm',
			'post_status'    => 'private',
			'meta_key'       => '_crm_user_id',
			'meta_value'     => (string) $user_id,
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( self::is_retired( $id ) ) {
				continue;
			}
			$result = PostMetaStore::apply(
				array(
					array( 'post_id'=>$id, 'key'=>'_crm_retired_core_customer_id', 'value'=>(string) $customer_id ),
					array( 'post_id'=>$id, 'key'=>'_crm_retired_at', 'value'=>current_time( 'mysql', true ) ),
					array( 'post_id'=>$id, 'key'=>'_crm_due', 'delete'=>true ),
					array( 'post_id'=>$id, 'key'=>'_crm_task', 'delete'=>true ),
				),
				'legacy_crm.profile_retired',
				'legacy_crm_post',
				$id
			);
			if ( is_wp_error( $result ) ) {
				break;
			}
		}
	}

	/** Retires older account-linked profiles in a bounded administrator request. */
	public static function reconcile(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Schema::is_ready() ) {
			return;
		}
		global $wpdb;
		$customers = Schema::table( 'customers' );
		$rows = $wpdb->get_results( "SELECT account.meta_value user_id,c.id customer_id,MIN(p.ID) first_id FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} account ON account.post_id=p.ID AND account.meta_key='_crm_user_id' INNER JOIN $customers c ON c.account_user_id=CAST(account.meta_value AS UNSIGNED) AND c.merged_into_id IS NULL LEFT JOIN {$wpdb->postmeta} retired ON retired.post_id=p.ID AND retired.meta_key IN ('_crm_retired_core_customer_id','_crm_privacy_erased') WHERE p.post_type='cd_crm' AND p.post_status='private' AND retired.meta_id IS NULL GROUP BY account.meta_value,c.id ORDER BY first_id ASC LIMIT 100", ARRAY_A ) ?: array();
		if ( $wpdb->last_error ) {
			return;
		}
		foreach ( $rows as $row ) {
			self::retire_account_profiles( absint( $row['user_id'] ), absint( $row['customer_id'] ) );
		}
	}

	private static function is_retired( int $post_id ): bool {
		return metadata_exists( 'post', $post_id, '_crm_retired_core_customer_id' ) || '1' === (string) get_post_meta( $post_id, '_crm_privacy_erased', true );
	}
}
