<?php
/** Creates the core AUTO BRANDS pages when missing. */
defined( 'ABSPATH' ) || exit;

function car_dealer_maybe_create_auto_pages() {
	if ( get_option( 'car_dealer_auto_pages_version' ) === '1.0.0' ) { return; }
	$pages = array(
		'finance' => array(
			'title' => 'التمويل',
			'content' => '<section class="ab-page-band"><h1>حلول تمويل AUTO BRANDS</h1><p>تمويل بنكي وتمويل شركات مع متطلبات واضحة وطريقة تقديم سهلة.</p></section>[car_dealer_loan_calculator][car_dealer_contact_form]',
		),
		'about' => array(
			'title' => 'من نحن',
			'content' => '<section class="ab-page-band"><h1>من نحن</h1><p>AUTO BRANDS تجمع بين خبرة قطاع السيارات، اختيار متنوع، خدمة عميل سريعة، وحلول تمويل تساعدك على اتخاذ القرار بثقة.</p></section>',
		),
		'contact' => array(
			'title' => 'تواصل معنا',
			'content' => '<section class="ab-page-band"><h1>تواصل معنا</h1><p>الهاتف، واتساب، ساعات العمل، ومواقع التواصل في مكان واحد.</p></section>[car_dealer_contact_form]',
		),
	);
	foreach ( $pages as $slug => $page ) {
		if ( get_page_by_path( $slug ) ) { continue; }
		wp_insert_post( array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_name' => $slug,
			'post_title' => $page['title'],
			'post_content' => $page['content'],
		) );
	}
	update_option( 'car_dealer_auto_pages_version', '1.0.0' );
}
add_action( 'admin_init', 'car_dealer_maybe_create_auto_pages' );
add_action( 'after_switch_theme', 'car_dealer_maybe_create_auto_pages' );
