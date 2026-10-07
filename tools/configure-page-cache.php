<?php
/** Configure anonymous-only page caching; dynamic query routes are excluded. */
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--apply'){exit(1);}
define('DISABLE_WP_CRON',true);require dirname(__DIR__).'/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);if(!$admins){exit(2);}wp_set_current_user((int)$admins[0]);
$result=activate_plugin('wp-super-cache/wp-cache.php');if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
require_once WP_PLUGIN_DIR.'/wp-super-cache/wp-cache.php';
wp_cache_verify_config_file();wp_cache_create_advanced_cache();
foreach(['cache_enabled'=>true,'super_cache_enabled'=>true,'cache_max_time'=>300,'wp_cache_not_logged_in'=>1,'wp_cache_anon_only'=>1,'wp_cache_no_cache_for_get'=>1,'wp_cache_clear_on_post_edit'=>1,'wp_cache_mod_rewrite'=>0,'cache_rejected_uri'=>['wp-.*\.php','index\.php','cd_account','customize_changeset_uuid','/account/','/login/','/register/']] as $key=>$value){wp_cache_setting($key,$value);}
wp_cache_enable();echo "Anonymous public caching enabled; logged-in and query requests bypass cache\n";
