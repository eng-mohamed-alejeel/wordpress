<?php
/** Reviewed translations for exact development fixtures; never a general translation dictionary. */
defined( 'ABSPATH' ) || exit;

function adc_apply_development_translations( bool $apply ): array {
	global $wpdb;
	$schema = '\AutoDealership\Database\Schema';
	$service = '\AutoDealership\Content\StoredTranslations';
	$pairs = array(
		'فرع الرياض التجريبي' => 'Demo Riyadh branch', 'فرع جدة التجريبي' => 'Demo Jeddah branch', 'فرع الدمام التجريبي' => 'Demo Dammam branch',
		'الرياض' => 'Riyadh', 'جدة' => 'Jeddah', 'الدمام' => 'Dammam',
		'موقع تجريبي — لا يمثل عنوان معرض فعليًا' => 'Demo location — this is not an actual dealership address.',
		'صالة العرض التجريبية RYD' => 'Demo showroom RYD', 'صالة العرض التجريبية JED' => 'Demo showroom JED', 'صالة العرض التجريبية DMM' => 'Demo showroom DMM',
		'مورد مركبات محلي تجريبي' => 'Demo local vehicle supplier', 'مورد استيراد تجريبي' => 'Demo import supplier', 'مورد مبادلات تجريبي' => 'Demo trade-in supplier',
		'بيانات تطوير اصطناعية؛ لا يوجد عقد أو اعتماد مزود.' => 'Synthetic development data; no supplier contract or approval exists.',
		'وصف تجريبي؛ شروط الضمان الفعلية يحددها المعرض.' => 'Demo description; actual warranty terms are determined by the dealership.',
		'تكاليف افتراضية لأغراض التطوير فقط.' => 'Fictional costs for development purposes only.',
		'فحص إطارات تجريبي؛ يلزم إصلاح قبل العرض.' => 'Demo tire inspection; repairs are required before listing.',
		'مزود تمويل تجريبي أ' => 'Demo finance provider A', 'مزود تمويل تجريبي ب' => 'Demo finance provider B',
		'تم اجتياز الفحص التجريبي' => 'Passed development inspection', 'فحص تجريبي للتطوير' => 'Development inspection fixture',
	);
	$vehicles = $schema::table( 'vehicles' );
	$stock = "stock_number REGEXP '^DEMO-2026-(0[1-9]|1[0-2])$'";
	$scopes = array(
		'branches' => "code IN ('DEMO-RYD','DEMO-JED','DEMO-DMM')",
		'locations' => "code IN ('DEMO-RYD-SHOWROOM','DEMO-JED-SHOWROOM','DEMO-DMM-SHOWROOM')",
		'suppliers' => "supplier_code IN ('DEMO-LOCAL','DEMO-IMPORT','DEMO-TRADE')",
		'vehicles' => $stock,
		'vehicle_inspections' => "vehicle_id IN (SELECT id FROM $vehicles WHERE $stock)",
		'vehicle_receipts' => "vehicle_id IN (SELECT id FROM $vehicles WHERE $stock)",
		'vehicle_issues' => "vehicle_id IN (SELECT id FROM $vehicles WHERE $stock)",
		'vehicle_movements' => "vehicle_id IN (SELECT id FROM $vehicles WHERE $stock)",
		'finance_requests' => 'sale_id IN (SELECT id FROM ' . $schema::table( 'sales' ) . " WHERE vehicle_id IN (SELECT id FROM $vehicles WHERE $stock))",
	);
	$counts = array( 'business_fields' => 0, 'post_fields' => 0, 'term_fields' => 0 );
	foreach ( $scopes as $type => $where ) {
		$rows = $wpdb->get_results( 'SELECT id,' . implode( ',', $service::FIELDS[$type] ) . ' FROM ' . $schema::table( $type ) . ' WHERE ' . $where, ARRAY_A ) ?: array();
		foreach ( $rows as $row ) {
			foreach ( array( 'en', 'ar' ) as $language ) {
				$input = array(); $hashes = array();
				foreach ( $service::FIELDS[$type] as $field ) {
					$source = (string) ( $row[$field] ?? '' );
					$map = 'en' === $language ? $pairs : array_flip( $pairs );
					if ( ! isset( $map[$source] ) || isset( $service::copies( $type, (int) $row['id'] )[$language][$field] ) ) { continue; }
					$input[$field] = $map[$source]; $hashes[$field] = $service::fingerprint( $source );
				}
				$counts['business_fields'] += count( $input );
				if ( $apply && $input ) {
					$result = $service::save( $type, (int) $row['id'], $language, $input, $hashes );
					if ( is_wp_error( $result ) ) { throw new \RuntimeException( $result->get_error_code() ); }
				}
			}
		}
	}
	$content_pairs = array(
		'<p>سيارة معروضة لأغراض تطوير النظام فقط. المواصفات والسعر ورقم المخزون بيانات تجريبية وليست عرض بيع فعليًا.</p>' => '<p>This vehicle is listed solely for system development. Specifications, price and stock number are demo data, not an actual sales offer.</p>',
		'<p>عرض تجريبي لتطوير الموقع فقط؛ غير صالح للبيع أو الحجز بسعر فعلي.</p>' => '<p>This is a demo offer for website development only. It is not valid for an actual sale or reservation.</p>',
	);
	$posts = get_posts( array( 'post_type' => array( 'car', 'car_offer' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_adc_demo_seed', 'meta_value' => '2026-10-02' ) );
	foreach ( $posts as $post ) {
		if ( ! preg_match( '/^adc-demo-(?:offer-)?(?:0[1-9]|1[0-2])$/', $post->post_name ) ) { continue; }
		$title = str_replace( array( '[عرض تجريبي]', '[تجريبي]' ), array( '[Demo offer]', '[Demo]' ), $post->post_title );
		$values = array();
		if ( ! preg_match( '/\p{Arabic}/u', $title ) && $title !== $post->post_title ) { $values['_adc_title_en'] = $title; }
		if ( isset( $content_pairs[$post->post_content] ) ) { $values['_adc_content_en'] = $content_pairs[$post->post_content]; }
		$changes = array();
		foreach ( $values as $key => $value ) {
			if ( '' !== (string) get_post_meta( $post->ID, $key, true ) ) { continue; }
			$counts['post_fields']++;
			$changes[] = array( 'post_id' => $post->ID, 'key' => $key, 'value' => $value );
			$changes[] = array( 'post_id' => $post->ID, 'key' => $key . '_source_hash', 'value' => hash( 'sha256', '_adc_title_en' === $key ? $post->post_title : $post->post_content ) );
		}
		if ( $apply && $changes ) {
			$result = \AutoDealership\Content\PostMetaStore::apply( $changes, 'content.demo_translations_saved', 'post', $post->ID );
			if ( is_wp_error( $result ) ) { throw new \RuntimeException( $result->get_error_code() ); }
		}
	}
	foreach ( array( 'car_brand' => array( 'toyota' => array( 'تويوتا', 'Toyota' ), 'hyundai' => array( 'هيونداي', 'Hyundai' ), 'kia' => array( 'كيا', 'Kia' ), 'nissan' => array( 'نيسان', 'Nissan' ), 'mazda' => array( 'مازدا', 'Mazda' ) ), 'car_category' => array( 'sedan' => array( 'سيدان', 'Sedan' ), 'suv' => array( 'دفع رباعي', 'SUV' ), 'pickup' => array( 'بيك أب', 'Pickup' ) ) ) as $taxonomy => $definitions ) {
		foreach ( $definitions as $slug => $names ) {
			$term = get_term_by( 'slug', 'demo-' . $slug, $taxonomy );
			if ( ! $term || ! in_array( $term->name, array( '[تجريبي] ' . $names[0], '[تجريبي] ' . $names[1] ), true ) ) { continue; }
			foreach ( array( 'ar' => '[تجريبي] ' . $names[0], 'en' => '[Demo] ' . $names[1] ) as $language => $value ) {
				$key = '_adc_name_' . $language;
				if ( '' !== (string) get_term_meta( $term->term_id, $key, true ) ) { continue; }
				$counts['term_fields']++;
				if ( $apply ) {
					if ( false === update_term_meta( $term->term_id, $key, $value ) || false === update_term_meta( $term->term_id, $key . '_source_hash', hash( 'sha256', $term->name ) ) ) { throw new \RuntimeException( 'Term translation write failed.' ); }
				}
			}
		}
	}
	return $counts;
}
