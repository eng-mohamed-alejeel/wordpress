<?php
/**
 * قالب صفحة تواصل معنا
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 2.0
 * Template Name: صفحة تواصل معنا
 */
get_header(); ?>

<div class="page-template-contact">
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
						<?php echo do_shortcode('[ab_contact_hero][ab_contact_grid][ab_contact_map][ab_contact_form_section][ab_faq_section][ab_social_section]'); ?>
					</div>
				</article>
			<?php endwhile; ?>
		</main>
	</div>
</div>
</div>

<style>
.page-template-contact .content-area { max-width: 100%; padding: 0; }
.page-template-contact .entry-header { display: none; }
.page-template-contact .entry-content { max-width: 100%; }
</style>

<?php get_footer(); ?>
