<?php
namespace AutoDealership\Security;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Customer use in a transaction requires an accessible lead in the vehicle's branch. */
final class CustomerScope {
	public static function allows( int $customer_id, int $branch_id ): bool {
		global $wpdb;
		if ( $customer_id < 1 || $branch_id < 1 || ! BranchScope::allows( $branch_id ) ) {
			return false;
		}
		// Services call this inside their transaction before inserting operational references.
		// The lock serializes new references against reviewed CRM consolidation.
		$active = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d AND merged_into_id IS NULL FOR UPDATE', $customer_id ) );
		if ( $wpdb->last_error || ! $active ) { return false; }
		if ( BranchScope::is_global() ) {
			return true;
		}
		if ( ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'adc_manage_own_leads' ) ) {
			return false;
		}
		$sql = 'SELECT c.id FROM ' . Schema::table( 'customers' ) . ' c INNER JOIN ' . Schema::table( 'leads' ) . ' l ON l.customer_id = c.id WHERE c.id = %d AND l.branch_id = %d';
		$args = array( $customer_id, $branch_id );
		if ( ! current_user_can( 'adc_manage_branch_leads' ) ) {
			$sql .= ' AND l.owner_user_id = %d';
			$args[] = get_current_user_id();
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) );
	}
}
