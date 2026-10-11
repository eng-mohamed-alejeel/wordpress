<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Search and paging preserve the caller's existing branch and workflow predicates. */
final class QueueTable {
	public static function search(): string {
		return mb_substr( sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) ), 0, 120 );
	}

	public static function fetch( string $sql, array $args, array $columns ): array {
		global $wpdb;
		if ( ! preg_match( '/^(.*) ORDER BY (.*) LIMIT 100$/s', $sql, $parts ) ) { throw new \InvalidArgumentException( 'Expected an ordered queue query.' ); }
		$base = $parts[1]; $search = self::search();
		if ( '' !== $search ) {
			$clauses = array();
			foreach ( $columns as $column ) {
				if ( 'v.vin' === $column && ! current_user_can( 'adc_view_inventory' ) && ! current_user_can( 'manage_options' ) ) { continue; }
				if ( ! preg_match( '/\A[a-z]+\.[a-z_]+\z/', $column ) ) { throw new \InvalidArgumentException( 'Invalid search column.' ); }
				$clauses[] = $column . ' LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$base .= ' AND (' . implode( ' OR ', $clauses ) . ')';
		}
		$count_sql = 'SELECT COUNT(*) FROM (' . $base . ') adc_queue_count';
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		if ( $wpdb->last_error ) { wp_die( esc_html__( 'Unable to load this list. Please retry.', 'auto-dealership-core' ), '', array( 'response'=>500 ) ); }
		$pages = max( 1, (int) ceil( $total / 50 ) );
		$page = min( $pages, max( 1, absint( $_GET['queue_page'] ?? 1 ) ) );
		$query = $base . ' ORDER BY ' . $parts[2] . ' LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( 50, ( $page - 1 ) * 50 ) ) ), ARRAY_A ) ?: array();
		if ( $wpdb->last_error ) { wp_die( esc_html__( 'Unable to load this list. Please retry.', 'auto-dealership-core' ), '', array( 'response'=>500 ) ); }
		return compact( 'rows', 'total', 'pages', 'page', 'search' );
	}

	public static function controls( string $slug, string $section = '', array $filters = array() ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="adc-queue-search"><input type="hidden" name="page" value="' . esc_attr( $slug ) . '">';
		if ( $section ) { echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '">'; }
		echo '<label>' . esc_html__( 'البحث في القائمة', 'auto-dealership-core' ) . ' <input type="search" name="search" maxlength="120" value="' . esc_attr( self::search() ) . '"></label> ';
		foreach ( $filters as $key => $definition ) {
			echo '<label>' . esc_html__( $definition['label'], 'auto-dealership-core' ) . ' <select name="' . esc_attr( $key ) . '">';
			foreach ( $definition['options'] as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( sanitize_key( $_GET[$key] ?? '' ), $value, false ) . '>' . esc_html( \AutoDealership\Core\Localization::label( $label ) ) . '</option>'; }
			echo '</select></label> ';
		}
		submit_button( __( 'بحث', 'auto-dealership-core' ), 'secondary', '', false );
		echo '</form>';
	}

	public static function footer( array $result, string $slug, string $section = '', array $filters = array() ): void {
		echo '<div class="tablenav"><span>' . esc_html( sprintf( __( 'إجمالي النتائج: %d', 'auto-dealership-core' ), $result['total'] ) ) . '</span> ';
		$base = add_query_arg( array_merge( $filters, array( 'page'=>$slug, 'section'=>$section, 'search'=>$result['search'], 'queue_page'=>999999999 ) ), admin_url( 'admin.php' ) );
		echo wp_kses_post( paginate_links( array( 'base'=>str_replace( '999999999', '%#%', $base ), 'format'=>'', 'current'=>$result['page'], 'total'=>$result['pages'] ) ) );
		echo '</div>';
	}
}
