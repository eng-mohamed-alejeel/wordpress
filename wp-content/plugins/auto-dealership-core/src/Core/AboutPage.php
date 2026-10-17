<?php
namespace AutoDealership\Core;
defined('ABSPATH') || exit;

/** Dynamic links and branches for the editable bilingual About page. */
final class AboutPage {
	public static function boot(): void {
		add_shortcode('adc_about_logo', array(self::class, 'logo'));
		add_shortcode('adc_management',array(self::class,'management'));
		add_filter('the_content', static function(string $content): string {
			if (!is_admin() && is_page('about') && in_the_loop() && is_main_query()) {
				$content = self::normalize_legacy_logo($content);
				$content = str_replace(array('<div class="adc-about-visual-copy"><strong>[adc_brand_name]</strong>', '<div class="adc-about-visual-copy">[adc_about_logo]'), '<div class="adc-about-visual-copy">', $content);
				if (!str_contains($content,'adc_management') && !str_contains($content,'adc_team') && !str_contains($content,'ab_team_section') && !str_contains($content,'adc-editorial-team')) {
					$team=self::management();
					if ($team) { $position=strpos($content,'<section class="adc-about-cta"'); $content=false===$position?$content.$team:substr_replace($content,$team,$position,0); }
				}
			}
			return $content;
		}, 9);
		add_shortcode('adc_about_actions', array(self::class, 'actions'));
		add_shortcode('adc_about_branches', array(self::class, 'branches'));
	}
	public static function management(): string {
		return \AutoDealership\Content\SiteContent::render('team',array('title'=>ProductSetup::t('الإدارة','Our leadership'),'description'=>str_replace('[adc_brand_name]',ProductSetup::brand(Localization::language()),ProductSetup::t('تتولى إدارة [adc_brand_name] توجيه أعمال المعرض والإشراف على المبيعات والعمليات والموارد البشرية، بما يدعم تنظيم العمل ومتابعة احتياجات العملاء.', 'The leadership of [adc_brand_name] oversees the dealership’s direction, sales, operations and human resources, supporting coordinated work and attentive customer service.'))));
	}
	/** Visual editors remove empty decorative children; accept both saved legacy shapes. */
	public static function normalize_legacy_logo(string $content): string {
		return preg_replace('~<div\b(?=[^>]*\bclass\s*=\s*(["\'])adc-about-car\1)[^>]*>(?:\s|<(span|i|b)\b[^>]*>\s*</\2>|<br\s*/?>)*</div>~i', '[adc_about_logo]', $content) ?? $content;
	}
	public static function logo(): string {
		$logo = (int)get_theme_mod('custom_logo');
		$image = $logo ? wp_get_attachment_image($logo, 'medium', false, array('class'=>'adc-about-site-logo','alt'=>ProductSetup::brand(Localization::language()))) : '';
		return $image ? '<span class="adc-about-logo">' . $image . '</span>' : '<strong>' . esc_html(ProductSetup::brand(Localization::language())) . '</strong>';
	}
	private static function url(string $slug): string {
		$page = get_page_by_path($slug);
		$url = 'cars' === $slug ? get_post_type_archive_link('car') : ($page && 'publish' === $page->post_status ? get_permalink($page) : '');
		return $url ? add_query_arg('lang', Localization::language(), $url) : '';
	}
	public static function actions(): string {
		$html = '<div class="adc-about-actions">';
		foreach (array('cars'=>array('استعرض السيارات','Browse vehicles'),'contact'=>array('تواصل مع فريقنا','Talk to our team')) as $slug=>$label) {
			$url = self::url($slug); if (!$url) { continue; }
			$html .= '<a class="btn ' . ('cars' === $slug ? 'btn-primary' : 'adc-about-secondary') . '" href="' . esc_url($url) . '">' . esc_html(ProductSetup::t(...$label)) . '<span aria-hidden="true"> ↗</span></a>';
		}
		return $html . '</div>';
	}
	public static function branches(): string {
		$rows = array_filter(BranchLocations::all(), static fn($row) => !empty($row['active']));
		if (!$rows) { return ''; }
		$lang = Localization::language(); $url = self::url('contact');
		$html = '<section class="adc-about-section adc-about-locations"><div class="adc-about-section-heading"><p class="adc-about-eyebrow">' . esc_html(ProductSetup::t('قريبون منك','Find us')) . '</p><h2>' . esc_html(ProductSetup::t('يسعدنا استقبالكم في فروعنا','Visit our branches')) . '</h2><p>' . esc_html(ProductSetup::t('اختر الفرع الأقرب إليك وتواصل مع الفريق لتنسيق زيارتك.','Find your nearest branch and contact our team to arrange your visit.')) . '</p></div><div class="adc-about-location-grid">';
		foreach ($rows as $row) {
			$html .= '<article class="adc-about-location"><span class="adc-about-location-symbol" aria-hidden="true">↗</span><h3>' . esc_html($row['name_' . $lang]) . '</h3><p>' . esc_html($row['address_' . $lang]) . '</p>';
			if ($url) { $html .= '<a href="' . esc_url($url . '#branch-locations') . '">' . esc_html(ProductSetup::t('الموقع وبيانات التواصل','Location and contact details')) . '</a>'; }
			$html .= '</article>';
		}
		return $html . '</div></section>';
	}
	/** Upgrade only the old shipped one-paragraph default; preserve custom editorial copy. */
	public static function upgrade(\WP_Post $post, array $blueprint) {
		$old = '<p>تعرف على [adc_brand_name]. استعرض السيارات المتاحة وتواصل مع فريقنا للحصول على التفاصيل.</p>';
		if (trim($post->post_content) !== $old) { return true; }
		$result = wp_update_post(wp_slash(array('ID'=>$post->ID,'post_content'=>$blueprint['content'])), true);
		if (is_wp_error($result)) { return $result; }
		$previous = (string)get_post_meta($post->ID,'_adc_content_en',true);
		if (!$previous || '<p>Meet [adc_brand_name]. Explore available vehicles and contact our team for details.</p>' === trim($previous)) {
			update_post_meta($post->ID,'_adc_content_en',wp_slash($blueprint['content_en']));
			update_post_meta($post->ID,'_adc_content_en_source_hash',hash('sha256',$blueprint['content']));
		}
		return true;
	}
}
