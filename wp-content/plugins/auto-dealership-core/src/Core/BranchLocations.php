<?php
namespace AutoDealership\Core;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;

defined('ABSPATH') || exit;

/** Public branch directory, independent of inventory and staff branch assignments. */
final class BranchLocations {
	private const OPTION = 'adc_branch_locations';
	public static function boot(): void {
		add_action('admin_menu', static function(): void { add_submenu_page('options-general.php', self::t('الفروع والمواقع', 'Branches and locations'), self::t('الفروع والمواقع', 'Branches and locations'), 'manage_options', 'adc-branch-locations', array(self::class, 'admin')); });
		add_action('admin_post_adc_branch_locations', array(self::class, 'submit'));
		add_action('wp_enqueue_scripts', static function(): void { if (is_page('contact') || (is_singular() && has_block('auto-dealership/component')) || (is_singular() && has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'adc_branch_locations'))) { wp_enqueue_style('adc-branches', plugins_url('assets/css/branch-locations.css', ADC_FILE), array(), ADC_VERSION); } });
		add_shortcode('adc_branch_locations', array(self::class, 'render'));
		add_shortcode('adc_contact_intro', array(self::class, 'contact_intro'));
		add_filter('the_content', static function(string $content): string {
			if (str_contains($content, 'adc-component-output')) { return $content; }
			if (!is_admin() && is_page('contact') && in_the_loop() && is_main_query() && !has_shortcode($content, 'adc_branch_locations')) {
				if (preg_match('/\[(?:car_dealer_contact_form|ab_contact_form_section)\b/', $content)) { $content = preg_replace('/\[(?:car_dealer_contact_form|ab_contact_form_section)\b/', "[adc_branch_locations]\n\n$0", $content, 1); }
				else { $content .= "\n\n[adc_branch_locations]"; }
			}
			if (!is_admin() && is_page('contact') && in_the_loop() && is_main_query() && !has_shortcode($content, 'adc_contact_intro') && preg_match('/\[(?:car_dealer_contact_form|ab_contact_form_section)\b/', $content)) {
				$content = preg_replace('/\[(?:car_dealer_contact_form|ab_contact_form_section)\b/', "[adc_contact_intro]\n\n$0", $content, 1);
			}
			return $content;
		}, 9);
	}
	private static function t(string $ar, string $en): string { return ProductSetup::t($ar, $en); }
	public static function all(): array { return (array) get_option(self::OPTION, array()); }
	public static function revision(): string { return hash('sha256', wp_json_encode(self::all())); }
	public static function normalize($rows) {
		if (!is_array($rows) || count($rows) > 50) { return new \WP_Error('adc_branches_input', self::t('الحد الأقصى 50 فرعًا.', 'Maximum 50 branches.')); }
		$clean = array(); $seen = array();
		foreach ($rows as $row) {
			if (!is_array($row)) { return new \WP_Error('adc_branches_input', 'Invalid branch.'); }
			$item = array();
			foreach (array('id', 'name_ar', 'name_en', 'address_ar', 'address_en', 'embed_url', 'directions_url', 'phone') as $key) {
				if (!is_scalar($row[$key] ?? '') || strlen((string)($row[$key] ?? '')) > ('embed_url' === $key ? 4096 : 1000)) { return new \WP_Error('adc_branches_input', 'Invalid branch field.'); }
				$item[$key] = trim((string)($row[$key] ?? ''));
			}
			if (!$item['name_ar'] || !$item['name_en']) { return new \WP_Error('adc_branches_name', self::t('أدخل اسم كل فرع بالعربية والإنجليزية.', 'Enter each branch name in Arabic and English.')); }
			foreach (array('name_ar', 'name_en', 'address_ar', 'address_en', 'phone') as $key) { $item[$key] = sanitize_text_field($item[$key]); }
			$item['id'] = $item['id'] ?: wp_generate_uuid4();
			if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $item['id']) || isset($seen[$item['id']])) { return new \WP_Error('adc_branches_id', 'Invalid or duplicate branch ID.'); }
			$seen[$item['id']] = true;
			if (preg_match('/\A\s*<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $item['embed_url'], $match)) { $item['embed_url'] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'); }
			$url = wp_parse_url($item['embed_url']);
			if ('https' !== ($url['scheme'] ?? '') || !in_array(strtolower($url['host'] ?? ''), array('www.google.com','google.com'), true) || '/maps/embed' !== rtrim($url['path'] ?? '', '/') || isset($url['user']) || isset($url['pass']) || isset($url['port']) || empty($url['query'])) { return new \WP_Error('adc_branches_map', self::t('استخدم رابط تضمين خرائط Google الصحيح لكل فرع.', 'Use a valid Google Maps embed URL for each branch.')); }
			$item['embed_url'] = esc_url_raw($item['embed_url']);
			if ($item['directions_url']) {
				$link = wp_parse_url($item['directions_url']);
				if ('https' !== ($link['scheme'] ?? '') || !in_array(strtolower($link['host'] ?? ''), array('www.google.com','google.com','maps.google.com','maps.app.goo.gl'), true) || isset($link['user']) || isset($link['pass']) || isset($link['port'])) { return new \WP_Error('adc_branches_directions', self::t('رابط الاتجاهات يجب أن يكون رابط خرائط Google آمنًا.', 'Directions must use a secure Google Maps URL.')); }
				$item['directions_url'] = esc_url_raw($item['directions_url']);
			}
			$item['active'] = !empty($row['active']); $clean[] = $item;
		}
		return $clean;
	}
	public static function save($rows, string $revision) {
		if (!current_user_can('manage_options')) { return new \WP_Error('adc_branches_forbidden', 'Forbidden.'); }
		$clean = self::normalize($rows); if (is_wp_error($clean)) { return $clean; }
		global $wpdb; $lock = 'adc_branch_locations_' . md5(DB_NAME . $wpdb->prefix);
		if ('1' !== (string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) { return new \WP_Error('adc_branches_busy', self::t('يجري حفظ الفروع. حاول مجددًا.', 'Branches are being saved. Try again.')); }
		try {
			wp_cache_delete(self::OPTION, 'options'); wp_cache_delete('alloptions', 'options');
			if (!hash_equals(self::revision(), $revision)) { return new \WP_Error('adc_branches_stale', self::t('تغيّرت بيانات الفروع. أعد تحميل الصفحة قبل الحفظ.', 'Branches changed. Reload before saving.')); }
			if (!Transaction::begin()) { return new \WP_Error('adc_branches_storage', 'Storage unavailable.'); }
			update_option(self::OPTION, $clean, false);
			$ok = !$wpdb->last_error && get_option(self::OPTION) === $clean && Transaction::commit(static fn() => AuditLog::record('platform.branches_updated', 'settings', 0, 'Public branch locations updated', null, array('count'=>count($clean))));
			if (!$ok) { $wpdb->query('ROLLBACK'); wp_cache_delete(self::OPTION, 'options'); wp_cache_delete('notoptions', 'options'); return new \WP_Error('adc_branches_storage', self::t('تعذر حفظ الفروع.', 'Could not save branches.')); }
			if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }
			if (function_exists('car_dealer_clear_public_page_cache')) { car_dealer_clear_public_page_cache(); }
			return true;
		} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
	}
	public static function submit(): void {
		if (!current_user_can('manage_options')) { wp_die('Forbidden', '', array('response'=>403)); }
		check_admin_referer('adc_branch_locations');
		$result = self::save(wp_unslash($_POST['branches'] ?? array()), sanitize_text_field(wp_unslash($_POST['revision'] ?? '')));
		set_transient('adc_branch_notice_' . get_current_user_id(), array('ok'=>!is_wp_error($result), 'text'=>is_wp_error($result) ? $result->get_error_message() : self::t('تم حفظ الفروع والمواقع.', 'Branches and locations saved.')), MINUTE_IN_SECONDS);
		wp_safe_redirect(admin_url('options-general.php?page=adc-branch-locations')); exit;
	}
	private static function row(array $row, string $index): void {
		echo '<fieldset class="adc-branch-editor" style="background:#fff;border:1px solid #c3c4c7;padding:20px;margin:16px 0"><legend><strong>' . esc_html(self::t('بيانات الفرع', 'Branch details')) . '</strong></legend><input type="hidden" name="branches[' . esc_attr($index) . '][id]" value="' . esc_attr($row['id'] ?? '') . '">';
		foreach (array('name_ar'=>array('اسم الفرع بالعربية','Branch name (Arabic)'), 'name_en'=>array('اسم الفرع بالإنجليزية','Branch name (English)'), 'address_ar'=>array('العنوان بالعربية','Address (Arabic)'), 'address_en'=>array('العنوان بالإنجليزية','Address (English)'), 'phone'=>array('هاتف الفرع (اختياري)','Branch phone (optional)'), 'embed_url'=>array('رابط التضمين أو كود iframe من Google Maps','Google Maps embed URL or iframe code'), 'directions_url'=>array('رابط الاتجاهات (اختياري)','Directions URL (optional)')) as $key=>$label) {
			echo '<p><label>' . esc_html(self::t(...$label)) . '<br><input class="large-text" type="text" name="branches[' . esc_attr($index) . '][' . esc_attr($key) . ']" value="' . esc_attr($row[$key] ?? '') . '"' . (in_array($key,array('name_ar','name_en','embed_url'),true) ? ' required' : '') . (str_ends_with($key,'_en') || str_contains($key,'url') ? ' dir="ltr"' : '') . '></label></p>';
		}
		echo '<p><label><input type="checkbox" name="branches[' . esc_attr($index) . '][active]" value="1" ' . checked(!empty($row['active']),true,false) . '> ' . esc_html(self::t('إظهار الفرع في صفحة تواصل معنا','Show branch on the contact page')) . '</label></p><p><button type="button" class="button" data-branch-up>' . esc_html(self::t('تحريك لأعلى','Move up')) . '</button> <button type="button" class="button" data-branch-down>' . esc_html(self::t('تحريك لأسفل','Move down')) . '</button> <button type="button" class="button-link-delete" data-branch-remove>' . esc_html(self::t('إزالة الفرع','Remove branch')) . '</button></p></fieldset>';
	}
	public static function admin(): void {
		if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
		wp_enqueue_script('adc-branch-editor', plugins_url('assets/js/branch-locations-admin.js', ADC_FILE), array(), ADC_VERSION, true);
		echo '<div class="wrap"><h1>' . esc_html(self::t('الفروع والمواقع','Branches and locations')) . '</h1>';
		$notice = get_transient('adc_branch_notice_' . get_current_user_id()); delete_transient('adc_branch_notice_' . get_current_user_id());
		if (is_array($notice)) { echo '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html($notice['text']) . '</p></div>'; }
		echo '<p>' . esc_html(self::t('أضف الفروع ورتّبها كما ترغب. تُعرض الفروع المفعّلة تلقائيًا في صفحة تواصل معنا، وتُحفظ التغييرات عند الضغط على حفظ.','Add and order branches. Enabled branches appear automatically on the contact page. Changes apply when saved.')) . '</p><form id="adc-branches-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="adc_branch_locations"><input type="hidden" name="revision" value="' . esc_attr(self::revision()) . '">'; wp_nonce_field('adc_branch_locations');
		echo '<div id="adc-branch-rows">'; foreach (self::all() as $index=>$row) { self::row($row,(string)$index); } echo '</div><template id="adc-branch-template">'; self::row(array('active'=>true),'__INDEX__'); echo '</template><button type="button" class="button" id="adc-add-branch">' . esc_html(self::t('إضافة فرع','Add branch')) . '</button>'; submit_button(self::t('حفظ الفروع','Save branches')); echo '</form></div>';
	}
	public static function contact_intro(): string {
		$html = '<section class="adc-contact-intro" aria-labelledby="adc-contact-intro-heading"><div class="adc-contact-intro-copy"><h2 id="adc-contact-intro-heading">' . esc_html(self::t('كيف يمكننا مساعدتك؟','How can we help?')) . '</h2><p>' . esc_html(self::t('يسعد فريقنا مساعدتك في الاستفسار عن السيارات وخدمات المعرض. اذكر السيارة أو الخدمة التي تهمك، ووضّح استفسارك، وأدخل بيانات تواصل صحيحة ليتمكن الفريق من متابعة طلبك.','Our team can help with vehicle and dealership service enquiries. Tell us which vehicle or service interests you, describe your question, and provide accurate contact details so we can follow up.')) . '</p></div><aside class="adc-contact-guidance"><h3>' . esc_html(self::t('قبل إرسال طلبك','Before sending your enquiry')) . '</h3><ul>';
		foreach (array(array('إرسال الاستفسار لا يُعد حجزًا للسيارة أو اتفاق شراء.','Submitting an enquiry does not reserve a vehicle or create a purchase agreement.'),array('تُنسّق زيارة الفرع وتجربة القيادة مع الفريق بعد تأكيد توفر السيارة والموعد.','Branch visits and test drives are arranged with our team after confirming vehicle availability and the appointment.'),array('حفاظًا على خصوصيتك، لا تُضمّن بيانات البطاقات البنكية أو كلمات المرور أو المستندات الحساسة في الرسالة.','To protect your privacy, do not include bank card details, passwords or sensitive documents in your message.')) as $line) { $html .= '<li>' . esc_html(self::t(...$line)) . '</li>'; }
		return $html . '</ul></aside></section>';
	}
	public static function contact_cards(): string {
		$rows = array_filter(self::all(), static fn($row) => !empty($row['active'])); if (!$rows) { return ''; }
		$lang = Localization::language(); $settings = (array)get_option('car_dealer_theme_settings', array());
		$email = sanitize_email((string)($settings['email'] ?? ''));
		$html = '<section class="ab-contact-grid-section ab-section adc-contact-branches" aria-label="' . esc_attr(self::t('بيانات التواصل للفروع','Branch contact details')) . '"><div class="container"><div class="ab-contact-grid adc-contact-branch-grid">';
		foreach ($rows as $row) {
			$html .= '<article class="ab-contact-info-card adc-contact-branch-card"><h3>' . esc_html($row['name_' . $lang]) . '</h3><dl class="adc-contact-branch-details">';
			if ($row['address_' . $lang]) { $html .= '<div><dt>' . esc_html(self::t('العنوان','Address')) . '</dt><dd>' . esc_html($row['address_' . $lang]) . '</dd></div>'; }
			$phone = preg_replace('/[^+0-9]/', '', $row['phone']);
			if ($phone) { $html .= '<div><dt>' . esc_html(self::t('الهاتف','Phone')) . '</dt><dd><a href="' . esc_url('tel:' . $phone) . '" dir="ltr">' . esc_html($row['phone']) . '</a></dd></div>'; }
			if ($email) { $html .= '<div><dt>' . esc_html(self::t('البريد الإلكتروني','Email')) . '</dt><dd><a href="' . esc_url('mailto:' . $email) . '" dir="ltr">' . esc_html($email) . '</a></dd></div>'; }
			$html .= '</dl></article>';
		}
		return $html . '</div></div></section>';
	}
	public static function render(): string {
		$rows = array_filter(self::all(), static fn($row) => !empty($row['active'])); if (!$rows) { return ''; }
		$lang = Localization::language();
		$html = '<section id="branch-locations" class="adc-branch-directory" aria-labelledby="adc-branches-heading"><header class="adc-branches-intro"><p>' . esc_html(self::t('يسعدنا استقبالكم','Visit us')) . '</p><h2 id="adc-branches-heading">' . esc_html(self::t('فروعنا ومواقعنا','Our branches and locations')) . '</h2><p>' . esc_html(self::t('اختر الفرع الأقرب إليك وافتح الاتجاهات لتخطيط زيارتك.','Find your nearest branch and open directions to plan your visit.')) . '</p></header><div class="adc-branch-grid">';
		foreach ($rows as $row) {
			$name = $row['name_' . $lang]; $address = $row['address_' . $lang];
			$src = add_query_arg('hl', $lang, $row['embed_url']);
			$link = $row['directions_url'] ?: 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($name . ' ' . $address);
			$html .= '<article class="adc-branch-card"><div class="adc-branch-map"><iframe src="' . esc_url($src) . '" title="' . esc_attr(self::t('خريطة موقع ','Location map: ') . $name) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div><div class="adc-branch-info"><h3>' . esc_html($name) . '</h3>' . ($address ? '<p class="adc-branch-address">' . esc_html($address) . '</p>' : '') . '<div class="adc-branch-actions"><a class="btn btn-primary" href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::t('الاتجاهات إلى الفرع','Get directions')) . '<span aria-hidden="true"> ↗</span></a>';
			$phone = preg_replace('/[^+0-9]/','',$row['phone']); if ($phone) { $html .= '<a class="adc-branch-phone" href="' . esc_url('tel:' . $phone) . '" dir="ltr">' . esc_html($row['phone']) . '</a>'; }
			$html .= '</div></div></article>';
		}
		return $html . '</div></section>';
	}
}
