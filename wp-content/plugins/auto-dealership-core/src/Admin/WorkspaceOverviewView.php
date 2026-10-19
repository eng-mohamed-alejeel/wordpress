<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Accessible server-rendered dashboard without a shared user-data cache. */
final class WorkspaceOverviewView {
	private static function t( string $ar, string $en ): string { return WorkspaceOverview::t( $ar, $en ); }
	public static function render(): void {
		$data = WorkspaceOverview::snapshot();
		echo '<section class="adc-overview" aria-labelledby="adc-overview-heading"><div class="adc-overview-heading"><h2 id="adc-overview-heading">' . esc_html( self::t( 'ملخص العمل', 'Operational overview' ) ) . '</h2><span>' . esc_html( self::t( 'آخر تحديث: ', 'Updated: ' ) . wp_date( 'Y-m-d H:i' ) . ' · ' . wp_timezone_string() ) . '</span></div>';
		if ( is_wp_error( $data ) ) { echo '<p class="notice notice-error inline" role="alert">' . esc_html( $data->get_error_message() ) . '</p></section>'; return; }
		$args = array( 'workspace_branch'=>$data['branch'], 'workspace_period'=>$data['period'], 'workspace_search'=>$data['search'] );
		echo '<form class="adc-overview-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="adc-workspace"><label>' . esc_html( self::t( 'الفرع', 'Branch' ) ) . ' <select name="workspace_branch"><option value="0">' . esc_html( self::t( 'كل الفروع ضمن صلاحيتي', 'All permitted branches' ) ) . '</option>';
		foreach ( $data['branches'] as $branch ) { echo '<option value="' . absint( $branch['id'] ) . '" ' . selected( $data['branch'], (int) $branch['id'], false ) . '>' . esc_html( $branch['name'] ) . '</option>'; }
		echo '</select></label><label>' . esc_html( self::t( 'فترة الفرص الجديدة', 'New leads period' ) ) . ' <select name="workspace_period">';
		foreach ( array( 'today'=>self::t( 'اليوم', 'Today' ), 'week'=>self::t( 'آخر 7 أيام', 'Last 7 days' ), 'month'=>self::t( 'آخر 30 يومًا', 'Last 30 days' ) ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $data['period'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><input type="hidden" name="workspace_task" value="' . esc_attr( $data['selected'] ) . '"><label>' . esc_html( self::t( 'البحث باسم العميل', 'Search by customer name' ) ) . ' <input type="search" name="workspace_search" maxlength="120" value="' . esc_attr( $data['search'] ) . '"></label><button class="button">' . esc_html( self::t( 'تحديث الملخص', 'Update overview' ) ) . '</button></form><p class="description">' . esc_html( self::t( 'تعرض القوائم الأعمال ضمن صلاحياتك وفروعك. البحث يرشح القوائم باسم العميل. الفترة تخص الفرص الجديدة فقط؛ بقية المؤشرات تعرض الحالة الحالية. الاعتماد النهائي يبقى خاضعًا لشروط العملية.', 'Queues respect your permissions and branches. Search filters queues by customer name. The period applies only to new leads; other indicators show current work. Final actions remain subject to workflow checks.' ) ) . '</p>';
		if ( ! $data['branches'] && ! \AutoDealership\Security\BranchScope::is_global() ) { echo '<p class="notice notice-warning inline">' . esc_html( self::t( 'لا يوجد فرع نشط معيّن لحسابك. اطلب من مسؤول المنصة مراجعة تعيين الفروع.', 'No active branch is assigned to your account. Ask the administrator to review your branch assignments.' ) ) . '</p>'; }
		if ( ! $data['queues'] ) { echo '<p>' . esc_html( self::t( 'لا توجد قوائم تشغيل متاحة لدورك. استخدم الوحدات المسموحة أدناه.', 'No operational queues are available for your role. Use the permitted modules below.' ) ) . '</p></section>'; return; }
		echo '<nav class="adc-overview-grid" aria-label="' . esc_attr( self::t( 'مؤشرات العمل وقوائمه', 'Operational indicators and queues' ) ) . '">';
		foreach ( $data['queues'] as $key=>$queue ) {
			echo '<a class="adc-overview-metric' . ( $key === $data['selected'] ? ' is-current' : '' ) . '" href="' . esc_url( WorkspaceOverview::url( array_merge( $args, array( 'workspace_task'=>$key ) ) ) . '#adc-work-list' ) . '"' . ( $key === $data['selected'] ? ' aria-current="true"' : '' ) . '><span>' . esc_html( $queue['title'] ) . '</span><strong>' . esc_html( null === $queue['count'] ? '—' : number_format_i18n( $queue['count'] ) ) . '</strong>';
			if ( null === $queue['count'] ) { echo '<small>' . esc_html( self::t( 'تعذر التحميل؛ أعد المحاولة', 'Could not load; try again' ) ) . '</small>'; } echo '</a>';
		}
		echo '</nav><div id="adc-work-list" class="adc-work-list" tabindex="-1"><h3>' . esc_html( self::t( 'أعمال تحتاج متابعة', 'Work to follow up' ) ) . ' — ' . esc_html( $data['queues'][$data['selected']]['title'] ) . '</h3>';
		$q = $data['queues'][$data['selected']];
		if ( $data['error'] || null === $q['count'] ) { echo '<p role="alert">' . esc_html( self::t( 'تعذر تحميل هذه القائمة. لم نعرض الخطأ على أنه قائمة فارغة.', 'This queue could not be loaded. A loading error is not an empty queue.' ) ) . '</p>'; }
		elseif ( ! $data['rows'] ) { echo '<p class="adc-overview-empty">' . esc_html( self::t( 'لا توجد أعمال مطابقة لهذه القائمة ضمن نطاقك الحالي.', 'No matching work in this queue within your current scope.' ) ) . '</p>'; }
		else {
			echo '<p>' . esc_html( sprintf( self::t( 'إجمالي الأعمال: %s', 'Total items: %s' ), number_format_i18n( $q['count'] ) ) ) . '</p><p class="adc-work-table-hint">' . esc_html( self::t( 'مرّر الجدول أفقيًا لعرض بقية الأعمدة وروابط المتابعة.', 'Scroll the table horizontally to see the remaining columns and follow-up links.' ) ) . '</p><div class="adc-work-table" tabindex="0" role="region" aria-label="' . esc_attr( self::t( 'جدول الأعمال القابل للتمرير', 'Scrollable work table' ) ) . '"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html( self::t( 'السجل', 'Record' ) ) . '</th><th scope="col">' . esc_html( self::t( 'العميل', 'Customer' ) ) . '</th><th scope="col">' . esc_html( self::t( 'وقت السجل / الاستحقاق', 'Record / due time' ) ) . '</th>';
			if ( ! empty( $q['has_owner'] ) ) { echo '<th scope="col">' . esc_html( self::t( 'المسؤول', 'Owner' ) ) . '</th>'; }
			echo '<th scope="col">' . esc_html( self::t( 'المتابعة', 'Follow up' ) ) . '</th></tr></thead><tbody>';
			foreach ( $data['rows'] as $row ) {
				$url = add_query_arg( array_merge( array( 'page'=>$q['target'], $q['target_key']=>(int) $row['target_id'] ), $q['extra'] ), admin_url( 'admin.php' ) );
				$label = esc_html( $row['label'] );
				if ( ! empty( $row['customer_id'] ) ) { $label = '<a href="' . esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>(int) $row['customer_id'] ), admin_url( 'admin.php' ) ) ) . '">' . $label . '</a>'; }
				echo '<tr><td>#' . absint( $row['id'] ) . '</td><td>' . $label . '</td><td>' . esc_html( self::date( $row['event_at'] ) ) . '</td>';
				if ( ! empty( $q['has_owner'] ) ) { echo '<td>#' . absint( $row['owner_id'] ) . '</td>'; }
				echo '<td><a class="button" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( self::t( 'فتح السجل ', 'Open record ' ) . '#' . (int) $row['id'] ) . '">' . esc_html( self::t( 'فتح', 'Open' ) ) . '</a></td></tr>';
			}
			echo '</tbody></table></div>';
			if ( $data['pages'] > 1 ) {
				$base = WorkspaceOverview::url( array_merge( $args, array( 'workspace_task'=>$data['selected'], 'workspace_page'=>999999999 ) ) );
				echo '<nav class="adc-overview-pagination" aria-label="' . esc_attr( self::t( 'صفحات الأعمال', 'Work pages' ) ) . '">' . wp_kses_post( paginate_links( array( 'base'=>str_replace( '999999999', '%#%', $base ) . '#adc-work-list', 'format'=>'', 'current'=>$data['page'], 'total'=>$data['pages'] ) ) ) . '</nav>';
			}
		}
		echo '</div></section>';
	}
	private static function date( string $value ): string {
		try { return wp_date( 'Y-m-d H:i', ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->getTimestamp() ); }
		catch ( \Exception $error ) { return '—'; }
	}
}
