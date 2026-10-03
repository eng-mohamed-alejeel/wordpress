<?php
$cd_language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar';
$cd_direction = 'en' === $cd_language ? 'ltr' : 'rtl';
$cd_catalog_search_url = function_exists( 'car_dealer_archive_url' ) ? car_dealer_archive_url( 'car' ) : '';
$cd_search_value = isset( $_GET['search'] ) && is_scalar( $_GET['search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search'] ) ) : get_search_query();
?>
<!doctype html>
<html lang="<?php echo esc_attr( $cd_language ); ?>" dir="<?php echo esc_attr( $cd_direction ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'site-language-' . $cd_language ); ?>>
<?php wp_body_open(); ?>
<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#primary"><?php echo esc_html( car_dealer_text( 'الانتقال إلى المحتوى', 'Skip to content' ) ); ?></a>
	<header id="masthead" class="site-header">
		<div class="container header-content">
			<div class="brand-section">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<a class="site-title" href="<?php echo esc_url( car_dealer_site_url() ); ?>"><?php bloginfo( 'name' ); ?></a>
				<?php endif; ?>
			</div>
			<button class="menu-toggle" type="button" aria-controls="primary-menu" aria-expanded="false" aria-label="<?php echo esc_attr( car_dealer_text( 'فتح القائمة', 'Open menu' ) ); ?>">
				<span></span><span></span><span></span>
			</button>
			<nav id="site-navigation" class="main-navigation" aria-label="<?php echo esc_attr( car_dealer_text( 'القائمة الرئيسية', 'Main navigation' ) ); ?>">
				<?php wp_nav_menu( array( 'theme_location' => 'primary', 'menu_id' => 'primary-menu', 'container' => false, 'fallback_cb' => 'car_dealer_account_menu_fallback' ) ); ?>
			</nav>
			<div class="header-actions">
				<form class="header-search" role="search" method="get" action="<?php echo esc_url( $cd_catalog_search_url ?: home_url( '/' ) ); ?>">
					<label class="screen-reader-text" for="site-search"><?php echo esc_html( car_dealer_text( 'ابحث', 'Search' ) ); ?></label>
					<input id="site-search" type="search" name="<?php echo esc_attr( $cd_catalog_search_url ? 'search' : 's' ); ?>" value="<?php echo esc_attr( $cd_search_value ); ?>" placeholder="<?php echo esc_attr( car_dealer_text( 'ابحث عن سيارة', 'Search cars' ) ); ?>">
					<?php if ( 'en' === $cd_language ) : ?><input type="hidden" name="lang" value="en"><?php endif; ?>
					<button type="submit" aria-label="<?php echo esc_attr( car_dealer_text( 'بحث', 'Search' ) ); ?>">⌕</button>
				</form>
				<?php if ( function_exists( 'car_dealer_catalog_language_switch' ) ) { car_dealer_catalog_language_switch(); } ?>
			</div>
		</div>
	</header>
	<main id="primary" class="site-main">
