<?php
/** Public not-found view; WordPress selects this template for a 404 query. */
defined( 'ABSPATH' ) || exit;
status_header( 404 );
nocache_headers();
get_header();
$catalog_url = function_exists( 'car_dealer_archive_url' ) ? car_dealer_archive_url( 'car' ) : '';
?>
<section class="container cd-not-found" aria-labelledby="cd-not-found-title">
	<p class="eyebrow">404</p>
	<h1 id="cd-not-found-title"><?php echo esc_html( car_dealer_text( 'الصفحة غير موجودة', 'Page not found' ) ); ?></h1>
	<p><?php echo esc_html( car_dealer_text( 'قد يكون الرابط تغيّر. ابحث عن سيارة أو عد إلى الصفحة الرئيسية.', 'This link may have changed. Search for a vehicle or return to the home page.' ) ); ?></p>
	<?php if ( $catalog_url ) : ?>
		<form class="cd-not-found-search" role="search" method="get" action="<?php echo esc_url( $catalog_url ); ?>">
			<label for="cd-not-found-query"><?php echo esc_html( car_dealer_text( 'ابحث في السيارات', 'Search vehicles' ) ); ?></label>
			<div class="cd-not-found-search-row">
				<input id="cd-not-found-query" type="search" name="search" autocomplete="off" required>
				<?php if ( 'en' === car_dealer_catalog_language() ) : ?><input type="hidden" name="lang" value="en"><?php endif; ?>
				<button class="btn btn-primary" type="submit"><?php echo esc_html( car_dealer_text( 'بحث', 'Search' ) ); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<a class="btn btn-outline" href="<?php echo esc_url( car_dealer_site_url() ); ?>"><?php echo esc_html( car_dealer_text( 'العودة إلى الرئيسية', 'Back to home' ) ); ?></a>
</section>
<?php get_footer(); ?>
