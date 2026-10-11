<?php
/** Read-only verification of the six public Onyx fields on already-published local pages. */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--inspect') { exit(1); }
require dirname(__DIR__).'/wp-load.php';
if (!in_array(parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) { exit(2); }
global $wpdb;
$models = \AutoDealership\Database\Schema::table('catalog_models');
$pages = $wpdb->get_results("SELECT m.public_post_id FROM $models m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' AND p.post_status='publish'", ARRAY_A);
$output = array();
foreach ($pages ?: array() as $page) {
	$id = (int)$page['public_post_id'];
	$view = \AutoDealership\Content\PublicVehicleView::for_post($id);
	$output[] = array('post_id'=>$id, 'title'=>get_the_title($id), 'url'=>get_permalink($id), 'reads_live_inventory'=>isset($view['catalog_model']['options']), 'options'=>$view['catalog_model']['options'] ?? array());
}
echo wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
