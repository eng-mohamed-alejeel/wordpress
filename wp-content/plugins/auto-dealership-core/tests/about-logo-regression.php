<?php
/** Regression: classic editors strip empty legacy car decoration when saving. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../src/Core/AboutPage.php';
use AutoDealership\Core\AboutPage;
$cases = array(
	'<div class="adc-about-car" aria-hidden="true"><span></span><i></i><b></b></div>' => '[adc_about_logo]',
	'<div class="adc-about-car" aria-hidden="true"></div>' => '[adc_about_logo]',
	"<div aria-hidden='true' class='adc-about-car'>\n<br />\n</div>" => '[adc_about_logo]',
	'<div class="adc-about-car">Custom editorial content</div>' => '<div class="adc-about-car">Custom editorial content</div>',
	'<div class="other">Unrelated content</div>[adc_about_logo]' => '<div class="other">Unrelated content</div>[adc_about_logo]',
);
foreach ($cases as $input => $expected) {
	$actual = AboutPage::normalize_legacy_logo($input);
	if ($actual !== $expected || AboutPage::normalize_legacy_logo($actual) !== $expected) { throw new RuntimeException('Legacy logo normalization regression'); }
}
echo "PASS: 5 logo regression cases; editorial content preserved; normalization is idempotent.\n";
