<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Called inside the import transaction, after its revision lock is acquired. */
final class OnyxModelDrafts {
	private static array $terms=array();
	public static function transactional_tables(): bool {
		return ! self::storage_issues();
	}
	/** Must run before starting any import transaction: ALTER TABLE commits implicitly. */
	public static function ensure_transactional_tables(): bool {
		global $wpdb;
		if (!current_user_can('manage_options') || !Schema::is_ready()) { return false; }
		$issues = self::storage_issues();
		if (!$issues) { return true; }
		// Never guess missing tables or silently convert an unknown storage engine.
		foreach ($issues as $table => $engine) {
			if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table) || !in_array(strtolower($engine), array('myisam', 'aria'), true)) { return false; }
		}
		$lock = 'adc_onyx_storage_' . md5(DB_NAME . $wpdb->prefix);
		if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) { return false; }
		try {
			// Recheck after acquiring the lock; another admin may have finished already.
			foreach (self::storage_issues() as $table => $engine) {
				if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table) || !in_array(strtolower($engine), array('myisam', 'aria'), true)) { return false; }
				$previous = $wpdb->suppress_errors(true);
				try { $converted = $wpdb->query("ALTER TABLE `$table` ENGINE=InnoDB"); }
				finally { $wpdb->suppress_errors($previous); }
				if (false === $converted) { return false; }
			}
			return self::transactional_tables();
		} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
	}
	/** Use the site's database metadata, without relying on information_schema access. */
	public static function storage_issues(): array {
		global $wpdb;
		$issues = array();
		foreach (array($wpdb->posts,$wpdb->postmeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->termmeta,$wpdb->options,$wpdb->users,$wpdb->usermeta) as $table) {
			$status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
			if ($wpdb->last_error || !$status || $status['Name'] !== $table) { $issues[$table] = 'unknown'; continue; }
			if ('innodb' !== strtolower((string) $status['Engine'])) { $issues[$table] = (string) $status['Engine']; }
		}
		return $issues;
	}
	public static function create(array &$touched) {
		global $wpdb;
		$models=Schema::table('catalog_models');$stock=Schema::table('onyx_stock');
		self::$terms=array();
		$rows=$wpdb->get_results("SELECT m.* FROM $models m WHERE EXISTS (SELECT 1 FROM $stock s WHERE s.model_id=m.id AND s.present=1) ORDER BY m.id FOR UPDATE",ARRAY_A);
		if ($wpdb->last_error) { return new \WP_Error('adc_onyx_database'); }
		$created=0;$retained=0;$linked=0;
		foreach ($rows as $model) {
			if ($model['public_post_id'] && 'car'===get_post_type((int)$model['public_post_id'])) { ++$retained; continue; }
			$type=get_post_type_object('car');
			if (!$type || !current_user_can($type->cap->create_posts) || !current_user_can($type->cap->edit_posts)) { return new \WP_Error('adc_onyx_draft_forbidden'); }
			// Only an explicit source identity can adopt an unlinked page; titles are not unique identifiers.
			$matches=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_adc_onyx_model_key' WHERE p.post_type='car' AND pm.meta_value=%s AND NOT EXISTS (SELECT 1 FROM $models linked WHERE linked.public_post_id=p.ID) FOR UPDATE",$model['model_key']));
			if ($wpdb->last_error || count($matches)>1) { return new \WP_Error('adc_onyx_draft_identity_conflict'); }
			$post=$matches ? (int)$matches[0] : 0;
			if (!$post) {
				$post=wp_insert_post(array('post_type'=>'car','post_status'=>'draft','post_title'=>OnyxCatalogNames::for_model((int)$model['id'])),true);
				if (is_wp_error($post) || !$post) { return new \WP_Error('adc_onyx_draft_failed'); }
				$touched[]=(int)$post;
				if (!add_post_meta($post,'_adc_onyx_model_key',$model['model_key'],true)) { return new \WP_Error('adc_onyx_database'); }
				$term=term_exists($model['brand'],'car_brand');
				if (!$term) { $term=wp_insert_term($model['brand'],'car_brand',array('slug'=>'onyx-brand-'.substr(hash('sha256',$model['brand']),0,16))); }
				if (is_wp_error($term)) { return new \WP_Error('adc_onyx_taxonomy'); }
				$term_id=(int)(is_array($term)?$term['term_id']:$term);self::$terms[]=$term_id;
				if (is_wp_error(wp_set_object_terms($post,array($term_id),'car_brand'))) { return new \WP_Error('adc_onyx_taxonomy'); }
				++$created;
			} else { ++$retained; }
			if (1!==$wpdb->update($models,array('public_post_id'=>$post),array('id'=>$model['id']))) { return new \WP_Error('adc_onyx_database'); }
			++$linked;
		}
		OnyxCatalogNames::invalidate();
		return array('created'=>$created,'retained'=>$retained,'linked'=>$linked);
	}
	public static function clear_caches(array $posts): void {
		foreach (array_unique($posts) as $post) { clean_post_cache($post); wp_cache_delete($post,'post_meta'); }
		if (self::$terms) { clean_term_cache(array_unique(self::$terms),'car_brand'); }
		// Rolled-back term creation must not survive in WordPress's object cache.
		wp_cache_set_terms_last_changed();
		wp_cache_delete('all_ids','car_brand');wp_cache_delete('get','car_brand');
		OnyxCatalogNames::invalidate();
	}
}
