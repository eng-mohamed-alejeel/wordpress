<?php
/**
 * قالب صفحة من نحن
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 2.0
 * Template Name: صفحة من نحن
 */
get_header(); ?>

<div class="page-template-about">
<div class="container">
	<div id="primary" class="content-area">
		<main id="main" class="site-main">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="entry-image">
							<?php the_post_thumbnail( 'large' ); ?>
						</div>
					<?php endif; ?>
					<div class="entry-content">
						<?php the_content(); ?>
					</div>
				</article>
			<?php endwhile; ?>
		</main>
	</div>
</div>
</div>

<style>
.page-template-about .content-area { max-width: 100%; padding: 0; }
.page-template-about .entry-header { display: none; }
.page-template-about .entry-content { max-width: 100%; }
</style>

<?php get_footer(); ?>
