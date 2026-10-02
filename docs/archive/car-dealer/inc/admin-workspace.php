<?php
/** Shared navigation and live inventory overview for the admin workspace. */
defined( 'ABSPATH' ) || exit;

function car_dealer_workspace_navigation() {
	$screen = get_current_screen();
	if ( ! $screen || ( false === strpos( $screen->id, 'car-dealer' ) && ! in_array( $screen->post_type, array( 'car', 'car_offer', 'testimonial' ), true ) ) ) { return; }
	echo '<div class="cd-workspace-bar" dir="rtl"><a class="cd-workspace-brand" href="' . esc_url( admin_url( 'admin.php?page=car-dealer-dashboard' ) ) . '"><span class="dashicons dashicons-car" aria-hidden="true"></span><span>' . esc_html( get_bloginfo( 'name' ) ) . '<small>مساحة إدارة المعرض</small></span></a><span class="cd-workspace-date">' . esc_html( wp_date( 'l، j F Y' ) ) . '</span></div>';
}
add_action( 'in_admin_header', 'car_dealer_workspace_navigation' );

function car_dealer_workspace_recent_cars() {
	if ( ! current_user_can( 'edit_cars' ) ) { return; }
	$args = array( 'post_type' => 'car', 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => 6, 'orderby' => 'modified', 'order' => 'DESC' );
	if ( ! current_user_can( 'edit_others_cars' ) ) { $args['author'] = get_current_user_id(); }
	$cars = get_posts( $args );
	?>
	<section class="cd-recent-panel">
		<div class="cd-recent-heading"><div><span class="cd-eyebrow">متابعة المخزون</span><h2>آخر السيارات تحديثاً</h2></div><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=car' ) ); ?>">عرض كل السيارات <span aria-hidden="true">←</span></a></div>
		<?php if ( $cars ) : ?>
		<div class="cd-table-scroll" tabindex="0" role="region" aria-label="آخر السيارات"><table class="widefat cd-recent-table"><thead><tr><th scope="col">السيارة</th><th scope="col">حالة النشر</th><th scope="col">آخر تحديث</th><th scope="col">الإجراء</th></tr></thead><tbody>
		<?php foreach ( $cars as $car ) : $status = get_post_status_object( $car->post_status ); ?>
		<tr><td><div class="cd-car-identity"><?php echo get_the_post_thumbnail( $car->ID, 'thumbnail', array( 'class' => 'cd-car-thumb', 'alt' => '' ) ) ?: '<span class="cd-car-thumb dashicons dashicons-car" aria-hidden="true"></span>'; ?><strong><?php echo esc_html( get_the_title( $car ) ?: __( 'بدون عنوان', 'car-dealer' ) ); ?></strong></div></td><td><span class="cd-status cd-status-<?php echo esc_attr( $car->post_status ); ?>"><?php echo esc_html( $status ? $status->label : $car->post_status ); ?></span></td><td><?php echo esc_html( get_the_modified_date( get_option( 'date_format' ), $car ) ); ?></td><td><?php if ( current_user_can( 'edit_post', $car->ID ) ) : ?><a class="button" aria-label="<?php echo esc_attr( 'تعديل ' . get_the_title( $car ) ); ?>" href="<?php echo esc_url( get_edit_post_link( $car->ID ) ); ?>">تعديل</a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php else : ?>
		<div class="cd-empty-state"><span class="dashicons dashicons-car" aria-hidden="true"></span><h3>ابدأ بإضافة أول سيارة</h3><p>ستظهر هنا آخر السيارات لتتابع تحديثاتها وتصل إلى بياناتها بسرعة.</p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=car-dealer-add-car' ) ); ?>">إضافة سيارة</a></div>
		<?php endif; ?>
	</section>
	<?php
}
