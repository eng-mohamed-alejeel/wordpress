<?php
/** Populated upgrade/restore rehearsal. Never boots the source wp-config or sends mail. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = dirname( __DIR__ );
if ( '--worker' === ( $argv[1] ?? '' ) ) {
	$config = json_decode( (string) file_get_contents( $argv[2] ), true ); $stage = $argv[3] ?? '';
	if ( ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' ) ) { exit( 2 ); }
	define( 'ABSPATH', $root . '/' ); define( 'DB_NAME', $config['database'] ); define( 'DB_HOST', '127.0.0.1:' . $config['port'] ); define( 'DB_USER', 'root' ); define( 'DB_PASSWORD', '' ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
	define( 'WP_CONTENT_DIR', $config['content'] ); define( 'WP_CONTENT_URL', 'http://rehearsal.invalid/content' ); define( 'WP_HOME', 'http://rehearsal.invalid' ); define( 'WP_SITEURL', WP_HOME );
	define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'DISABLE_WP_CRON', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_DEBUG', false ); define( 'ADC_MAIL_MODE', 'disabled' ); define( 'FS_METHOD', 'direct' ); define( 'WP_TEMP_DIR', $config['temporary'] );
	foreach ( $config['salts'] as $key=>$value ) { define( $key, $value ); }
	$table_prefix = 'wp_'; $_SERVER['HTTP_HOST'] = 'rehearsal.invalid'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	require ABSPATH . 'wp-includes/plugin.php'; add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static fn()=>new WP_Error( 'rehearsal_offline', 'Rehearsal network disabled.' ), PHP_INT_MAX );
	require ABSPATH . 'wp-settings.php'; require_once ABSPATH . 'wp-admin/includes/admin.php';
	$admins = get_users( array( 'role'=>'administrator', 'number'=>1 ) ); if ( ! $admins ) { throw new RuntimeException( 'Populated snapshot has no administrator.' ); } wp_set_current_user( $admins[0]->ID );
	if ( in_array( $stage, array( 'plugin', 'theme', 'reject' ), true ) ) {
		// A pre-guard release needs an explicit offline preflight for the first upgrade.
		if ( ! class_exists( '\AutoDealership\Core\ProductSetup' ) ) { require $config['candidate'] . '/plugins/auto-dealership-core/src/Core/ProductSetup.php'; }
		if ( ! class_exists( '\AutoDealership\Core\UpdateCompatibility' ) ) { require $config['candidate'] . '/plugins/auto-dealership-core/src/Core/UpdateCompatibility.php'; \AutoDealership\Core\UpdateCompatibility::boot(); }
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$kind = 'theme' === $stage ? 'theme' : 'plugin';
		$skin = new Automatic_Upgrader_Skin(); $upgrader = 'theme' === $kind ? new Theme_Upgrader( $skin ) : new Plugin_Upgrader( $skin );
		$package = 'reject' === $stage ? $config['bad_package'] : $config[$kind . '_package'];
		$result = $upgrader->install( $package, array( 'overwrite_package'=>true, 'clear_update_cache'=>false ) );
		if ( 'reject' === $stage ) {
			$error = is_wp_error( $upgrader->result ) ? $upgrader->result : ( is_wp_error( $result ) ? $result : ( is_wp_error( $skin->result ) ? $skin->result : null ) );
			if ( ! $error || 'adc_update_contract' !== $error->get_error_code() ) { throw new RuntimeException( 'Incompatible package was not rejected by the real updater: ' . ( $error ? $error->get_error_code() : 'no error' ) ); }
			echo "PASS Upgrade: real WordPress updater rejected incompatible contract before replacement.\n";
		} elseif ( true !== $result ) { throw new RuntimeException( 'WordPress updater failed: ' . ( is_wp_error( $upgrader->result ) ? $upgrader->result->get_error_message() : wp_json_encode( $skin->get_upgrade_messages() ) ) ); }
		else { echo 'PASS Upgrade: WordPress replaced compatible ' . $kind . " package.\n"; }
	} else {
		$home = \AutoDealership\Content\HomePageView::view();
		if ( empty( $home['published_car_count'] ) || ! \AutoDealership\Database\Schema::is_ready() ) { throw new RuntimeException( 'Populated runtime catalog/schema is not ready.' ); }
		echo 'PASS Upgrade: runtime ' . ADC_VERSION . ', published cars=' . $home['published_car_count'] . ", mail and network blocked.\n";
		if ( 'new' === $stage ) { require $root . '/wp-content/plugins/auto-dealership-core/tests/update-compatibility-scenarios.php'; }
	}
	exit;
}
if ( '--isolated' !== ( $argv[1] ?? '' ) || count( $argv ) !== 6 ) { fwrite( STDERR, "Usage: php tools/rehearse-product-upgrade.php --isolated PORT SNAPSHOT_SQL OLD_CODE_ZIP NEW_PACKAGE_DIRECTORY\n" ); exit( 1 ); }
$port = (int) $argv[2]; $sql = realpath( $argv[3] ); $old = realpath( $argv[4] ); $package = realpath( $argv[5] );
if ( $port < 1024 || in_array( $port, array( 3306, 3307 ), true ) || ! $sql || ! $old || ! $package ) { exit( 2 ); }
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); $db = new mysqli( '127.0.0.1', 'root', '', '', $port ); $db->set_charset( 'utf8mb4' );
foreach ( $db->query( 'SHOW DATABASES' )->fetch_all() as $row ) { if ( ! in_array( $row[0], array( 'information_schema', 'mysql', 'performance_schema', 'sys', 'test' ), true ) ) { throw new RuntimeException( 'Dedicated test server must be empty.' ); } }
$directory = sys_get_temp_dir() . '/adc-upgrade-' . bin2hex( random_bytes( 12 ) ); mkdir( $directory, 0700 ); mkdir( $directory . '/content', 0700 ); mkdir( $directory . '/temporary', 0700 );
$database = 'adc_verify_' . bin2hex( random_bytes( 8 ) );
$remove = static function ( string $dir ) use ( &$remove, $directory ): void {
	$real = realpath( $dir ); $base = realpath( $directory ); if ( ! $real || ! $base || ( $real !== $base && ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) || is_link( $dir ) ) { throw new RuntimeException( 'Unsafe cleanup target.' ); }
	foreach ( new DirectoryIterator( $dir ) as $item ) { if ( $item->isDot() ) { continue; } $item->isDir() && ! $item->isLink() ? $remove( $item->getPathname() ) : unlink( $item->getPathname() ); } rmdir( $dir );
};
$copy = static function ( string $from, string $to ) use ( &$copy ): void { if ( ! is_dir( $to ) ) { mkdir( $to, 0700, true ); } foreach ( new DirectoryIterator( $from ) as $item ) { if ( $item->isDot() || $item->isLink() ) { continue; } $item->isDir() ? $copy( $item->getPathname(), $to . '/' . $item->getFilename() ) : copy( $item->getPathname(), $to . '/' . $item->getFilename() ); } };
$restore_files = static function () use ( $old, $directory ): void {
	$zip = new ZipArchive(); if ( true !== $zip->open( $old ) ) { throw new RuntimeException( 'Invalid old code archive.' ); }
	for ( $i=0; $i<$zip->numFiles; ++$i ) {
		$entry = $zip->getNameIndex( $i ); $name = str_replace( '\\', '/', $entry );
		if ( str_contains( $name, '..' ) || str_starts_with( $name, '/' ) || str_contains( $name, ':' ) ) { throw new RuntimeException( 'Unsafe old archive path.' ); }
		$target = null;
		foreach ( array( 'auto-dealership-core/'=>'plugins/', 'car-dealer/'=>'themes/', 'mu-plugins/'=>'' ) as $prefix=>$parent ) { if ( str_starts_with( $name, $prefix ) ) { $target = $directory . '/content/' . $parent . $name; break; } }
		if ( ! $target || str_ends_with( $name, '/' ) ) { continue; } if ( ! is_dir( dirname( $target ) ) ) { mkdir( dirname( $target ), 0700, true ); } file_put_contents( $target, $zip->getFromIndex( $i ) );
	} $zip->close();
};
$restore_sql = static function () use ( $db, $database, $sql ): void { $db->query( "DROP DATABASE IF EXISTS `$database`" ); $db->query( "CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ); $db->select_db( $database ); $db->multi_query( file_get_contents( $sql ) ); do { if ( $r=$db->store_result() ) { $r->free(); } } while ( $db->more_results() && $db->next_result() ); };
$fingerprint = static function ( bool $all = false ) use ( $db ): array {
	$result = array(); foreach ( $db->query( 'SHOW TABLES' )->fetch_all() as $row ) {
		$table = $row[0]; if ( ! preg_match( '/\Awp_[a-zA-Z0-9_]+\z/', $table ) ) { throw new RuntimeException( 'Unexpected snapshot table.' ); }
		if ( ! $all && 'wp_options' === $table ) { continue; } $rows = array();
		foreach ( $db->query( "SELECT * FROM `$table`" )->fetch_all( MYSQLI_ASSOC ) as $values ) { $raw=''; foreach ( $values as $value ) { $raw .= null === $value ? 'N;' : strlen( $value ) . ':' . $value . ';'; } $rows[] = hash( 'sha256', $raw ); }
		sort( $rows ); $result[$table] = array( 'rows'=>count( $rows ), 'sha256'=>hash( 'sha256', implode( '', $rows ) ) );
	} ksort( $result ); return $result;
};
$files = static function () use ( $directory ): array {
	$r = array(); foreach ( array( 'plugins', 'themes', 'mu-plugins', 'uploads' ) as $part ) { $base = $directory . '/content/' . $part; if ( ! is_dir( $base ) ) { continue; } foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() ) { $r[str_replace( '\\', '/', substr( $file->getPathname(), strlen( $directory ) + 1 ) )] = hash_file( 'sha256', $file->getPathname() ); } } } ksort( $r ); return $r;
};
$retains_baseline=static function(array $baseline)use($fingerprint):bool {
	$current=$fingerprint();foreach($baseline as $table=>$state){if(($current[$table]??null)!==$state){return false;}}
	foreach(array_diff_key($current,$baseline) as $table=>$state){if(!preg_match('/\Awp_adc_portal_(threads|messages|reads|notifications|reviews|review_versions|review_actions)\z/',$table)||$state['rows']!==0){return false;}}return true;
};
$settings = static function () use ( $db ): array {
	$rows=$db->query( "SELECT option_name,option_value FROM wp_options WHERE option_name IN ('blogname','blogdescription','active_plugins','template','stylesheet','timezone_string','wp_user_roles','adc_role_definitions') OR option_name LIKE 'car_dealer_%' OR option_name LIKE 'theme_mods_%' OR option_name LIKE 'adc_seller_%' OR option_name LIKE 'adc_reservation_%' OR option_name LIKE 'adc_vat_%' ORDER BY option_name" )->fetch_all( MYSQLI_ASSOC );
	foreach($rows as &$row){if('wp_user_roles'!==$row['option_name']){continue;}$roles=unserialize($row['option_value'],array('allowed_classes'=>false));foreach($roles as &$role){foreach(array('adc_view_conversations','adc_reply_conversations','adc_close_conversations','adc_assign_conversations','adc_moderate_reviews','adc_publish_reviews') as $cap){unset($role['capabilities'][$cap]);}}unset($role);$row['option_value']=serialize($roles);}unset($row);return $rows;
};
try {
	$restore_files(); if ( is_dir( $root . '/wp-content/uploads' ) ) { $copy( $root . '/wp-content/uploads', $directory . '/content/uploads' ); }
	$restore_sql(); $original_data = $fingerprint( true ); $original_files = $files();
	// Parse static salt literals without executing or logging source wp-config/SMTP settings.
	$salts = array(); $tokens = token_get_all( file_get_contents( $root . '/wp-config.php' ) ); $flat = array_values( array_filter( $tokens, static fn( $t )=>! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
	$decode = static function ( string $literal ): string { $raw = substr( $literal, 1, -1 ); return "'" === $literal[0] ? preg_replace_callback( '/\\\\([\\\\\'])/', static fn( $m )=>$m[1], $raw ) : stripcslashes( $raw ); };
	$names = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
	foreach ( $flat as $i=>$token ) { if ( ! is_array( $token ) || T_STRING !== $token[0] || 'define' !== strtolower( $token[1] ) ) { continue; } $name_token=$flat[$i+2] ?? null; $value_token=$flat[$i+4] ?? null; if ( is_array( $name_token ) && is_array( $value_token ) && T_CONSTANT_ENCAPSED_STRING === $name_token[0] && T_CONSTANT_ENCAPSED_STRING === $value_token[0] ) { $key=$decode( $name_token[1] ); if ( in_array( $key, $names, true ) ) { $salts[$key]=$decode( $value_token[1] ); } } }
	if ( count( $salts ) !== 8 ) { throw new RuntimeException( 'Static WordPress salts could not be copied safely.' ); }
	$config = array( 'database'=>$database, 'port'=>$port, 'content'=>$directory . '/content', 'temporary'=>$directory . '/temporary', 'candidate'=>$directory . '/candidate', 'salts'=>$salts );
	$manifest = json_decode( file_get_contents( $package . '/manifest.json' ), true ); if ( ! is_array( $manifest ) ) { throw new RuntimeException( 'Invalid release manifest.' ); }
	foreach ( $manifest['artifacts'] as $artifact ) {
		$path=$package . '/' . basename( $artifact['name'] ); if ( ! hash_equals( $artifact['sha256'], hash_file( 'sha256', $path ) ) ) { throw new RuntimeException( 'Release package checksum mismatch.' ); }
		$kind = str_starts_with( $artifact['name'], 'auto-dealership-core-' ) ? 'plugin' : 'theme'; $config[$kind . '_package']=$path; $target=$config['candidate'] . '/' . ( 'plugin' === $kind ? 'plugins' : 'themes' ); mkdir( $target, 0700, true ); $zip=new ZipArchive(); $zip->open( $path ); for ( $i=0; $i<$zip->numFiles; ++$i ) { $name=$zip->getNameIndex( $i ); if ( str_contains( $name, '..' ) || str_contains( $name, '\\' ) || ! str_starts_with( $name, 'plugin' === $kind ? 'auto-dealership-core/' : 'car-dealer/' ) ) { throw new RuntimeException( 'Unsafe release archive.' ); } } $zip->extractTo( $target ); $zip->close();
	}
	$config['bad_package']=$directory . '/incompatible.zip'; copy( $config['plugin_package'], $config['bad_package'] ); $bad=new ZipArchive(); $bad->open( $config['bad_package'] ); $metadata=json_decode( $bad->getFromName( 'auto-dealership-core/resources/release.json' ), true ); $metadata['contract']=999; $bad->addFromString( 'auto-dealership-core/resources/release.json', json_encode( $metadata ) ); $bad->close();
	$config_file=$directory . '/configuration.json'; file_put_contents( $config_file, json_encode( $config ) ); chmod( $config_file, 0600 );
	$run=static function( string $stage ) use( $config_file ):void { $process=proc_open( array( PHP_BINARY, __FILE__, '--worker', $config_file, $stage ), array( 0=>array('pipe','r'),1=>STDOUT,2=>STDERR ),$pipes ); fclose($pipes[0]); if(0!==proc_close($process)){throw new RuntimeException('Rehearsal stage failed: '.$stage);} };
	$run('old'); $baseline=$fingerprint(); $baseline_settings=$settings();
	$run('plugin'); $run('theme'); $run('new');
	if(!$retains_baseline($baseline) || $baseline_settings!==$settings()){throw new RuntimeException('Populated application data or owner settings changed during upgrade.');} echo 'PASS Upgrade: all '.count($baseline)." existing application tables and identity/contact/policy/role settings retain exact fingerprints; added portal tables are empty.\n";
	$upgraded_files=$files();foreach($original_files as $file=>$hash){if(str_starts_with($file,'content/uploads/')&&($upgraded_files[$file]??'')!==$hash){throw new RuntimeException('Uploaded media changed during upgrade.');}}echo "PASS Upgrade: uploaded media retained exact file hashes.\n";
	$before_reject=$files();$before_reject_data=$fingerprint();$run('reject');if($before_reject!==$files()||$before_reject_data!==$fingerprint()){throw new RuntimeException('Rejected update altered installed files or data.');}echo "PASS Upgrade: rejected update left files and data unchanged.\n";
	foreach(array('plugins','themes','mu-plugins') as $part){$path=$directory.'/content/'.$part;if(is_dir($path))$remove($path);} $restore_files();$restore_sql();
	if($original_data!==$fingerprint(true)||$original_files!==$files()){throw new RuntimeException('Full rollback fingerprints differ from the original snapshot.');} echo 'PASS Restore: all '.count($original_data).' database tables and '.count($original_files)." runtime/media files restored exactly.\n";
	$run('restored'); if($baseline!==$fingerprint()){throw new RuntimeException('Restored old runtime altered application data.');}
	echo "PASS Restore: original runtime boots after full rollback; database/source SMTP configuration was never bootstrapped.\n";
} finally { $db->query("DROP DATABASE IF EXISTS `$database`");$db->close();$remove($directory);echo "Disposable rehearsal database and files removed. Source database was not contacted.\n"; }
