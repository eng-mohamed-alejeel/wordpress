<?php
namespace AutoDealership\Portal;

use AutoDealership\Core\Capabilities;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Frontend presentation; all mutations remain on their existing protected screens. */
final class WorkDashboardView {
	private static function t( string $ar, string $en ): string { return esc_html( PortalAccess::t( $ar, $en ) ); }
	private static function link( string $url, string $label, string $class = 'adc-p-button is-secondary' ): void {
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
	public static function render(): void {
		if ( ! AccountDashboard::available() ) { echo '<p role="alert">' . self::t( 'مساحة العمل غير متاحة.', 'Work dashboard unavailable.' ) . '</p>'; return; }
		$user = wp_get_current_user(); $data = WorkDashboard::snapshot();
		echo '<div class="container adc-portal adc-work-dashboard" dir="' . ( 'ar' === PortalAccess::language() ? 'rtl' : 'ltr' ) . '" lang="' . esc_attr( PortalAccess::language() ) . '"><header class="adc-p-welcome"><div><span class="adc-p-eyebrow">' . self::t( 'لوحة عملي', 'My work dashboard' ) . '</span><h1>' . self::t( 'مرحبًا، ', 'Welcome, ' ) . '<bdi>' . esc_html( $user->display_name ) . '</bdi></h1><p>' . self::t( 'أعمالك ووحداتك المتاحة حسب صلاحيات حسابك.', 'Your work and modules, based on your account permissions.' ) . '</p><p class="adc-work-roles">';
		$roles = array(); foreach ( $user->roles as $role ) { $roles[] = 'administrator' === $role ? PortalAccess::t( 'مدير الموقع', 'Site administrator' ) : Capabilities::role_label( $role ); }
		echo esc_html( implode( ' · ', $roles ) ) . '</p></div><div class="adc-p-actions">';
		self::link( PortalAccess::url('profile'), PortalAccess::t( 'إعدادات حسابي', 'My account settings' ) );
		self::link( wp_logout_url( home_url( '/' ) ), PortalAccess::t( 'تسجيل الخروج', 'Sign out' ) );
		echo '</div></header><nav class="adc-work-context" aria-label="' . self::t( 'مساحات الحساب', 'Account spaces' ) . '"><a aria-current="page" href="' . esc_url( AccountDashboard::url() ) . '">' . self::t( 'لوحة عملي', 'My work dashboard' ) . '</a><a href="' . esc_url( PortalAccess::url('profile') ) . '">' . self::t( 'إعدادات حسابي', 'My account settings' ) . '</a></nav>';
		if ( is_wp_error( $data ) ) { echo '<p class="adc-p-notice is-error" role="alert">' . esc_html( $data->get_error_message() ) . '</p></div>'; return; }
		echo '<p class="adc-work-scope">' . self::t( 'نطاق العمل: ', 'Work scope: ' ) . esc_html( BranchScope::is_global() ? PortalAccess::t( 'إدارة النظام وجميع الفروع المسموحة', 'System administration and all permitted branches' ) : implode( '، ', array_column( $data['branches'], 'name' ) ) ) . '</p>';
		if ( ! $data['branches'] && ! BranchScope::is_global() ) { echo '<p class="adc-p-notice" role="status">' . self::t( 'لا يوجد فرع نشط معيّن لحسابك. اطلب من المسؤول مراجعة تعيين الفروع. لا تتوسع صلاحياتك تلقائيًا.', 'No active branch is assigned to your account. Ask the administrator to review branch assignments. Access is not automatically expanded.' ) . '</p>'; }
		if ( $data['cards'] ) {
			echo '<section class="adc-work-section" aria-labelledby="adc-work-units"><h2 id="adc-work-units">' . self::t( 'متابعة وحداتي', 'My unit summaries' ) . '</h2><p>' . self::t( 'المؤشرات ضمن جميع فروعك المسموحة، ومؤشر الإرسال يخص النظام بحسب صلاحيته. فتح الوحدة يعرض قائمتها وأدوات تصفيتها.', 'Indicators cover all your permitted branches; delivery jobs use their system-level permission. Opening a module shows its list and filters.' ) . '</p><div class="adc-work-indicators">';
			foreach ( $data['cards'] as $key=>$card ) { self::card( $card['url'], $card['title'], $card['count'], $key ); }
			echo '</div></section>';
		}
		if ( null !== $data['overview'] ) { self::overview( $data['overview'] ); }
		echo '<section class="adc-work-section" aria-labelledby="adc-work-modules"><h2 id="adc-work-modules">' . self::t( 'وحدات العمل المتاحة', 'Available work modules' ) . '</h2><p>' . self::t( 'تفتح الإجراءات صفحاتها المحمية. تعرض كل وحدة ما تسمح به صلاحياتك وفروعك.', 'Actions open their protected screens. Each module enforces your permissions and branches.' ) . '</p><div class="adc-work-modules">';
		foreach ( $data['groups'] as $group ) {
			echo '<section class="adc-p-card"><h3>' . esc_html( $group['title'] ) . '</h3><ul>';
			foreach ( $group['items'] as $item ) { echo '<li><a href="' . esc_url( admin_url( $item['path'] ) ) . '">' . esc_html( $item['title'] ) . '</a></li>'; }
			echo '</ul></section>';
		}
		echo '</div></section></div>';
	}
	private static function card( string $url, string $title, ?int $count, string $key, bool $selected = false ): void {
		echo '<a class="adc-work-indicator" data-work-indicator="' . esc_attr( $key ) . '" href="' . esc_url( $url ) . '"' . ( $selected ? ' aria-current="page"' : '' ) . '><span>' . esc_html( $title ) . '</span><strong>' . esc_html( null === $count ? '—' : number_format_i18n( $count ) ) . '</strong>';
		if ( null === $count ) { echo '<small>' . self::t( 'تعذر التحميل؛ أعد المحاولة', 'Could not load; please retry' ) . '</small>'; }
		echo '</a>';
	}
	private static function overview( array $data ): void {
		$args = array( 'workspace_branch'=>$data['branch'], 'workspace_period'=>$data['period'], 'workspace_search'=>$data['search'], 'workspace_task'=>$data['selected'] );
		echo '<section class="adc-work-section" aria-labelledby="adc-work-overview"><h2 id="adc-work-overview">' . self::t( 'أعمال تحتاج متابعتك', 'Work needing your attention' ) . '</h2><form class="adc-work-filters" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
		foreach ( array( 'cd_account'=>'dashboard', 'account_context'=>'work', 'lang'=>PortalAccess::language() ) as $key=>$value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '<label>' . self::t( 'الفرع', 'Branch' ) . '<select name="workspace_branch"><option value="0">' . self::t( 'كل فروعك المسموحة', 'All permitted branches' ) . '</option>';
		foreach ( $data['branches'] as $branch ) { echo '<option value="' . (int) $branch['id'] . '" ' . selected( $data['branch'], (int) $branch['id'], false ) . '>' . esc_html( $branch['name'] ) . '</option>'; }
		echo '</select></label><label>' . self::t( 'فترة الفرص الجديدة', 'New leads period' ) . '<select name="workspace_period">';
		foreach ( array( 'today'=>array( 'اليوم', 'Today' ), 'week'=>array( 'آخر 7 أيام', 'Last 7 days' ), 'month'=>array( 'آخر 30 يومًا', 'Last 30 days' ) ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $data['period'], $key, false ) . '>' . self::t( $label[0], $label[1] ) . '</option>'; }
		echo '</select></label><label>' . self::t( 'بحث باسم العميل', 'Search by customer name' ) . '<input name="workspace_search" type="search" maxlength="120" value="' . esc_attr( $data['search'] ) . '"></label><button class="adc-p-button" type="submit">' . self::t( 'تحديث', 'Update' ) . '</button></form><p>' . self::t( 'الفترة تخص الفرص الجديدة فقط. بقية القوائم تعرض الأعمال الحالية. الاعتماد النهائي يخضع لشروط العملية.', 'The period applies only to new leads. Other queues show current work. Final approval remains subject to workflow checks.' ) . '</p><div class="adc-work-indicators">';
		foreach ( $data['queues'] as $key=>$queue ) { self::card( AccountDashboard::url( array_merge( $args, array( 'workspace_task'=>$key ) ) ) . '#adc-work-list', $queue['title'], $queue['count'], $key, $key === $data['selected'] ); }
		echo '</div><div id="adc-work-list" tabindex="-1">';
		$queue = $data['queues'][$data['selected']] ?? null;
		if ( ! $queue ) { echo '<p>' . self::t( 'لا توجد قوائم متابعة إضافية لصلاحياتك الحالية. استخدم وحداتك المتاحة.', 'No additional follow-up queues are available with your current permissions. Use your available modules.' ) . '</p>'; }
		elseif ( $data['error'] || null === $queue['count'] ) { echo '<p role="alert">' . self::t( 'تعذر تحميل القائمة. أعد المحاولة؛ هذا لا يعني عدم وجود أعمال.', 'The list could not be loaded. Retry; this does not mean there is no work.' ) . '</p>'; }
		else {
			echo '<h3>' . esc_html( $queue['title'] ) . '</h3>';
			if ( ! $data['rows'] ) { echo '<p>' . self::t( 'لا توجد أعمال مطابقة ضمن نطاقك الحالي.', 'No matching work in your current scope.' ) . '</p>'; }
			else {
				echo '<div class="adc-work-table" role="region" tabindex="0" aria-label="' . self::t( 'قائمة الأعمال القابلة للتمرير', 'Scrollable work list' ) . '"><table><thead><tr><th scope="col">' . self::t( 'السجل', 'Record' ) . '</th><th scope="col">' . self::t( 'العميل', 'Customer' ) . '</th><th scope="col">' . self::t( 'وقت السجل', 'Record time' ) . '</th><th scope="col">' . self::t( 'المتابعة', 'Follow up' ) . '</th></tr></thead><tbody>';
				foreach ( $data['rows'] as $row ) {
					$url = admin_url( 'admin.php?' . http_build_query( array_merge( array( 'page'=>$queue['target'], $queue['target_key']=>(int) $row['target_id'] ), $queue['extra'] ), '', '&' ) );
					echo '<tr><td>#' . (int) $row['id'] . '</td><td>' . esc_html( $row['label'] ) . '</td><td><bdi>' . esc_html( get_date_from_gmt( $row['event_at'], 'Y-m-d H:i' ) ) . '</bdi></td><td>';
					self::link( $url, PortalAccess::t( 'فتح السجل #', 'Open record #' ) . (int) $row['id'] ); echo '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
			if ( $data['pages'] > 1 ) { echo '<nav class="adc-p-pagination" aria-label="' . self::t( 'صفحات الأعمال', 'Work pages' ) . '">'; foreach ( array( $data['page']-1=>array( 'السابق', 'Previous' ), $data['page']+1=>array( 'التالي', 'Next' ) ) as $page=>$label ) { if ( $page > 0 && $page <= $data['pages'] ) { self::link( AccountDashboard::url( array_merge( $args, array( 'workspace_page'=>$page ) ) ) . '#adc-work-list', PortalAccess::t( $label[0], $label[1] ) ); } } echo '</nav>'; }
		}
		echo '</div></section>';
	}
}
