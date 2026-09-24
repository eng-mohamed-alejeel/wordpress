<?php
/**
 * صفحات عامة لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

get_header(); ?>

<div class="container">
	<div id="primary" class="content-area">
		<?php
		// إضافة محتوى مخصص للصفحات إذا كان موجوداً
		$custom_page_content = car_dealer_get_setting('custom_page_content', '', 'display');
		if (!empty($custom_page_content)) {
			echo '<div class="custom-page-content">';
			echo wp_kses_post($custom_page_content);
			echo '</div>';
		}
		?>
		<main id="main" class="site-main">

			<?php
			while ( have_posts() ) :
				the_post();
				?>

				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<header class="entry-header">
						<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
					</header><!-- .entry-header -->

					<?php if ( has_post_thumbnail() ) : ?>
						<div class="entry-image">
							<?php the_post_thumbnail(); ?>
						</div>
					<?php endif; ?>

					<div class="entry-content">
						<?php
						the_content(
							sprintf(
								wp_kses(
									/* translators: %s: Name of current post. Only visible to screen readers */
									__( 'Continue reading<span class="screen-reader-text"> "%s"</span>', 'car-dealer' ),
									array(
										'span' => array(
											'class' => array(),
										),
									)
								),
								get_the_title()
							)
						);

						wp_link_pages(
							array(
								'before' => '<div class="page-links">' . __( 'Pages:', 'car-dealer' ),
								'after'  => '</div>',
							)
						);
						?>
					</div><!-- .entry-content -->

					<footer class="entry-footer">
						<?php edit_post_link( __( 'Edit', 'car-dealer' ), '<span class="edit-link">', '</span>' ); ?>
					</footer><!-- .entry-footer -->
				</article><!-- #post-<?php the_ID(); ?> -->

			<?php endwhile; // End of the loop. ?>

		</main><!-- #main -->
	</div><!-- #primary -->
</div><!-- .container -->

<?php get_footer(); ?>
