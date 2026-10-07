<?php
/** Template Name: Legal information / المعلومات القانونية */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="container legal-page-shell">
	<?php while ( have_posts() ) : the_post(); ?>
	<article class="content-area legal-page" dir="<?php echo esc_attr( car_dealer_catalog_direction() ); ?>">
		<header class="entry-header">
			<p class="legal-eyebrow"><?php echo esc_html( car_dealer_text( 'شركة أوتو براندز · المعلومات القانونية', 'Auto Brands Company · Legal information' ) ); ?></p>
			<h1 class="entry-title"><?php the_title(); ?></h1>
			<p class="legal-updated"><?php echo esc_html( car_dealer_text( 'آخر تحديث: ', 'Last updated: ' ) . get_the_modified_date( 'Y-m-d' ) ); ?></p>
		</header>
		<div class="entry-content"><?php the_content(); ?></div>
		<nav class="legal-related" aria-label="<?php echo esc_attr( car_dealer_text( 'صفحات قانونية أخرى', 'Related legal pages' ) ); ?>">
			<?php foreach ( car_dealer_legal_links() as $link ) : if ( get_the_ID() === $link['id'] ) { continue; } ?>
			<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php edit_post_link( car_dealer_text( 'تحرير الصفحة', 'Edit page' ), '<p class="legal-edit">', '</p>' ); ?>
	</article>
	<?php endwhile; ?>
</div>
<?php get_footer(); ?>
