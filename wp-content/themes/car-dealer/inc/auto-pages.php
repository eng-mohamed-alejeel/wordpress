<?php
/** Creates the core AUTO BRANDS pages when missing. */
defined( 'ABSPATH' ) || exit;

function car_dealer_maybe_create_auto_pages() {
	$pages = array(
		'finance' => array(
			'title' => 'التمويل',
			'content' => '<section class="ab-page-band"><h1>حلول تمويل AUTO BRANDS</h1><p>تمويل بنكي وتمويل شركات مع متطلبات واضحة وطريقة تقديم سهلة.</p></section>[car_dealer_loan_calculator][car_dealer_contact_form]',
		),
		'about' => array(
			'title' => 'من نحن',
			'content' => '
[ab_hero_section]
[ab_mission_vision]
[ab_stats_section]
[ab_values_section]
[ab_team_section]
[ab_cta_section]
[ab_testimonials_section]
',
		),
		'contact' => array(
			'title' => 'تواصل معنا',
			'content' => '
[ab_contact_hero]
[ab_contact_grid]
[ab_contact_map]
[ab_contact_form_section]
[ab_faq_section]
[ab_social_section]
',
		),
	);
	foreach ( $pages as $slug => $page ) {
		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			wp_update_post( array(
				'ID' => $existing->ID,
				'post_title' => $page['title'],
				'post_content' => $page['content'],
			) );
		} else {
			wp_insert_post( array(
				'post_type' => 'page',
				'post_status' => 'publish',
				'post_name' => $slug,
				'post_title' => $page['title'],
				'post_content' => $page['content'],
			) );
		}
	}
}
add_action( 'admin_init', 'car_dealer_maybe_create_auto_pages' );
add_action( 'after_switch_theme', 'car_dealer_maybe_create_auto_pages' );
