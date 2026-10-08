<?php
/** Read-only comparison of role-visible navigation and rendered workspace cards. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true );
define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';

use AutoDealership\Admin\Navigation;
use AutoDealership\Admin\WorkspacePage;
use AutoDealership\Core\Capabilities;

$ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $ids ) { throw new RuntimeException( 'Local administrator is required.' ); }
$administrator = wp_set_current_user( (int) $ids[0] );
$roles = array_merge( array( 'administrator' => null ), Capabilities::role_matrix() );
$_GET['page'] = 'adc-workspace';
$total = 0;
foreach ( $roles as $role => $caps ) {
    $user = clone $administrator;
    if ( null !== $caps ) {
        $user = new WP_User();
        $user->ID = PHP_INT_MAX;
        $user->allcaps = array_fill_keys( $caps, true );
    }
    $GLOBALS['current_user'] = $user;
    if ( ! current_user_can( 'adc_view_workspace' ) ) { continue; }
    foreach ( array( 'ar', 'en_US' ) as $locale ) {
        $user->locale = $locale;
        $expected = array();
        foreach ( Navigation::groups() as $group ) {
            foreach ( $group['items'] as $item ) {
                if ( Navigation::allowed( $item ) ) { $expected[] = admin_url( $item['path'] ); }
            }
        }
        ob_start();
        WorkspacePage::render();
        $html = ob_get_clean();
        preg_match_all( '/<a class="adc-workspace-card" href="([^"]+)"/', $html, $matches );
        $actual = array_map( static fn( string $url ): string => html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), $matches[1] );
        sort( $expected ); sort( $actual );
        if ( $expected !== $actual || count( $actual ) !== count( array_unique( $actual ) ) ) {
            throw new RuntimeException( 'Missing, duplicate or unauthorized workspace cards: ' . $role . '/' . $locale );
        }
        ++$total;
        echo 'PASS ' . $role . '/' . $locale . ': ' . count( $actual ) . " matching workspace destinations.\n";
    }
}
echo 'Workspace completeness: ' . $total . " role/language combinations passed.\n";
