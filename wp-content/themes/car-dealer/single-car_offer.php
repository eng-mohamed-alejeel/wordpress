<?php get_header(); ?>
<?php while ( have_posts() ) : the_post();
$offer = car_dealer_offer_view( get_the_ID(), is_preview(), true );
if ( ! $offer ) { echo '<div class="container empty-state">' . esc_html__( 'بيانات العرض غير متاحة حاليًا.', 'car-dealer' ) . '</div>'; continue; }
$is_expired = ! empty( $offer['is_expired'] );
$monthly = $offer['monthly_payment'];
$new = $offer['price'];
$old = $offer['old_price'];
$car_id = $offer['car_id'];
$expires = isset( $offer['expires'] ) && is_string( $offer['expires'] ) ? $offer['expires'] : '';
$summary = trim( (string) get_the_excerpt() );
$content = trim( (string) get_post_field( 'post_content', get_the_ID() ) );
$discount_percent = $old > $new && $new > 0 ? (int) round( ( ( $old - $new ) / $old ) * 100 ) : 0;
$offer_image = get_the_post_thumbnail( get_the_ID(), 'large', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'sizes' => '(max-width: 900px) 100vw, 55vw' ) );
if ( ! $offer_image && $car_id ) { $offer_image = get_the_post_thumbnail( $car_id, 'large', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'sizes' => '(max-width: 900px) 100vw, 55vw' ) ); }
$canonical_offer_url = function_exists( 'car_dealer_catalog_localized_url' ) ? car_dealer_catalog_localized_url( (string) get_permalink( get_the_ID() ) ) : (string) get_permalink( get_the_ID() );
$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
$canonical_path = wp_parse_url( $canonical_offer_url, PHP_URL_PATH );
$is_legacy_offer_url = is_string( $request_path ) && is_string( $canonical_path ) && untrailingslashit( $request_path ) !== untrailingslashit( $canonical_path );
$catalog_language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar';
$catalog_direction = function_exists( 'car_dealer_catalog_direction' ) ? car_dealer_catalog_direction() : 'rtl';
?>
<?php if ( $is_legacy_offer_url ) : ?><script>
if ( window.history && window.history.replaceState ) {
	window.history.replaceState( null, document.title, <?php echo wp_json_encode( $canonical_offer_url ); ?> + window.location.hash );
}
</script><?php endif; ?>
<?php
$offer_context_links = array_filter( array(
	array( 'url' => car_dealer_site_url(), 'label' => car_dealer_text( 'الرئيسية', 'Home' ) ),
	array( 'url' => car_dealer_archive_url( 'car_offer' ), 'label' => car_dealer_text( 'العروض', 'Offers' ) ),
) );
?>
<div class="cd-breadcrumb-shell" dir="<?php echo esc_attr( $catalog_direction ); ?>" lang="<?php echo esc_attr( $catalog_language ); ?>">
	<div class="container"><?php car_dealer_context_navigation( $offer_context_links, get_the_title() ); ?></div>
</div>
<section class="ab-car-hero ab-offer-single" aria-labelledby="offer-title">
	<div class="container ab-car-hero-grid">
		<div class="ab-offer-visual">
			<div class="ab-offer-media-frame">
				<?php if ( $offer_image ) : ?>
					<?php echo $offer_image; ?>
				<?php else : ?>
					<div class="offer-hero-placeholder" aria-label="<?php echo esc_attr( car_dealer_text( 'لا توجد صورة للعرض حاليًا', 'No offer image is available yet' ) ); ?>">
						<?php echo car_dealer_vehicle_placeholder(); ?>
						<span><?php echo esc_html( car_dealer_text( 'عرض مميز', 'Featured offer' ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $is_expired ) : ?><span class="ab-offer-expired-badge" role="status"><?php echo esc_html( car_dealer_text( 'انتهى العرض', 'Offer expired' ) ); ?></span><?php elseif ( $discount_percent > 0 ) : ?><span class="ab-offer-badge"><?php echo esc_html( car_dealer_text( 'خصم ', 'Save ' ) . $discount_percent . '%' ); ?></span><?php endif; ?>
			<?php if ( $car_id && get_the_title( $car_id ) ) : ?><p class="ab-offer-vehicle-link"><?php echo esc_html( car_dealer_text( 'على سيارة ', 'For ' ) ); ?><a href="<?php echo esc_url( car_dealer_catalog_localized_url( (string) get_permalink( $car_id ) ) ); ?>"><?php echo esc_html( get_the_title( $car_id ) ); ?></a></p><?php endif; ?>
		</div>
		<div class="car-single-content ab-offer-content">
			<p class="eyebrow"><?php echo esc_html( car_dealer_text( 'عرض {site_name}', '{site_name} Offer' ) ); ?></p>
			<h1 id="offer-title"><?php the_title(); ?></h1>
			<?php if ( $summary ) : ?><p class="ab-offer-summary"><?php echo esc_html( $summary ); ?></p><?php endif; ?>
			<div class="ab-offer-price-panel">
				<?php if ( $old ) : ?><p class="offer-old"><span><?php echo esc_html( car_dealer_text( 'السعر السابق', 'Previous price' ) ); ?></span><del><?php echo esc_html( car_dealer_format_price( $old ) ); ?></del></p><?php endif; ?>
				<?php if ( $new ) : ?><p class="car-single-price"><span><?php echo esc_html( car_dealer_text( 'سعر العرض', 'Offer price' ) ); ?></span><strong><?php echo esc_html( car_dealer_format_price( $new ) ); ?></strong></p><?php endif; ?>
			</div>
			<?php if ( $monthly ) : ?><p class="car-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
			<?php if ( '' !== $expires ) : ?><p class="ab-offer-validity<?php echo $is_expired ? ' ab-offer-validity--expired' : ''; ?>"><?php echo esc_html( $is_expired ? car_dealer_text( 'انتهى العرض في ', 'Offer ended on ' ) : car_dealer_text( 'ساري حتى ', 'Valid until ' ) ); ?><time datetime="<?php echo esc_attr( $expires ); ?>"><?php echo esc_html( $expires ); ?></time></p><?php endif; ?>
			<?php if ( ! $is_expired ) : ?>
				<div class="car-single-actions">
					<a class="btn btn-primary" href="#offer-lead"><?php esc_html_e( 'احصل على العرض', 'car-dealer' ); ?></a>
					<?php $wa_link = car_dealer_whatsapp_url( car_dealer_text( 'أريد الاستفادة من عرض: ', 'I would like to ask about this offer: ' ) . get_the_title() ); if ( '#' !== $wa_link ) : ?><a class="btn btn-whatsapp" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener"><?php echo car_dealer_whatsapp_icon(); ?><?php esc_html_e( 'تواصل واتساب', 'car-dealer' ); ?></a><?php endif; ?>
				</div>
				<p class="ab-offer-reassurance"><?php echo esc_html( car_dealer_text( 'أرسل بياناتك وسيتواصل معك فريق المبيعات لتأكيد التفاصيل والتوفر.', 'Send your details and our sales team will confirm availability and next steps.' ) ); ?></p>
			<?php else : ?>
				<div class="car-single-actions ab-offer-expired-actions">
					<a class="btn btn-outline" href="<?php echo esc_url( car_dealer_archive_url( 'car_offer' ) ); ?>"><?php echo esc_html( car_dealer_text( 'تصفح العروض الحالية', 'Browse current offers' ) ); ?></a>
				</div>
				<p class="ab-offer-reassurance ab-offer-expired-message" role="status"><?php echo esc_html( car_dealer_text( 'انتهى هذا العرض ولا يمكن استقبال طلبات جديدة عليه. يمكنك استكشاف العروض الحالية أو مراجعة السيارة المرتبطة أعلاه.', 'This offer has ended and can no longer accept new requests. Explore current offers or review the related vehicle above.' ) ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
<section class="container ab-offer-details<?php echo $is_expired ? ' ab-offer-details--expired' : ''; ?>" aria-label="<?php echo esc_attr( $is_expired ? car_dealer_text( 'تفاصيل العرض المنتهي', 'Expired offer details' ) : car_dealer_text( 'تفاصيل العرض وطلب التواصل', 'Offer details and enquiry' ) ); ?>">
	<div class="ab-offer-description car-description">
		<div class="ab-offer-section-heading"><span><?php echo esc_html( car_dealer_text( 'التفاصيل', 'Details' ) ); ?></span><h2><?php echo esc_html( car_dealer_text( 'تفاصيل العرض', 'Offer details' ) ); ?></h2></div>
		<?php if ( $content ) : ?><?php the_content(); ?><?php else : ?><p><?php echo esc_html( car_dealer_text( 'استفسر عن العرض لمعرفة التفاصيل الكاملة وخيارات التوفر.', 'Contact us to confirm the full offer details and availability options.' ) ); ?></p><?php endif; ?>
		<?php if ( ! $is_expired && function_exists( 'car_dealer_social_share_buttons' ) ) : ?><div class="ab-offer-share"><?php car_dealer_social_share_buttons(); ?></div><?php endif; ?>
	</div>
	<?php if ( ! $is_expired ) : ?><aside id="offer-lead" class="ab-offer-request-card">
		<div class="ab-offer-section-heading"><span><?php echo esc_html( car_dealer_text( 'خطوة واحدة', 'One step' ) ); ?></span><h2><?php echo esc_html( car_dealer_text( 'اطلب هذا العرض', 'Request this offer' ) ); ?></h2></div>
		<p class="ab-offer-form-intro"><?php echo esc_html( car_dealer_text( 'اترك بيانات التواصل وسنعود إليك بأقرب وقت.', 'Leave your contact details and we will get back to you shortly.' ) ); ?></p>
		<?php if ( function_exists( 'car_dealer_lead_form' ) ) { car_dealer_lead_form( $car_id, 'offer_request', __( 'احصل على العرض', 'car-dealer' ), __( 'إرسال الطلب', 'car-dealer' ) ); } ?>
	</aside><?php endif; ?>
</section>
<?php if ( $is_expired && function_exists( 'car_dealer_related_offers' ) ) : $related_offer_ids = car_dealer_related_offers( (int) $car_id, 3 ); if ( $related_offer_ids ) : ?>
	<section class="container cd-related-content ab-expired-related" aria-labelledby="expired-related-title">
		<div class="cd-related-heading">
			<div><p class="eyebrow"><?php echo esc_html( car_dealer_text( 'بدائل متاحة', 'Available alternatives' ) ); ?></p><h2 id="expired-related-title"><?php echo esc_html( car_dealer_text( 'عروض حالية قد تناسبك', 'Current offers you may like' ) ); ?></h2></div>
		</div>
		<div class="car-grid"><?php foreach ( $related_offer_ids as $related_offer_id ) { car_dealer_offer_card( $related_offer_id ); } ?></div>
	</section>
<?php endif; endif; ?>
<?php if ( ! $is_expired ) : ?><script>
(function () {
	function focusOfferForm() {
		if ( '#offer-lead' !== window.location.hash ) { return; }
		var target = document.getElementById( 'offer-lead' );
		if ( ! target ) { return; }
		var header = document.querySelector( '.site-header' );
		var offset = header ? header.getBoundingClientRect().height + 24 : 96;
		window.scrollTo( 0, Math.max( 0, target.getBoundingClientRect().top + window.scrollY - offset ) );
	}
	if ( 'complete' === document.readyState ) {
		window.setTimeout( focusOfferForm, 100 );
	} else {
		window.addEventListener( 'load', function () { window.setTimeout( focusOfferForm, 100 ); }, { once: true } );
	}
})();
</script><?php endif; ?>
<?php endwhile; ?>
<?php get_footer(); ?>
