<?php
/**
 * Template Name: صفحة شروط الاستخدام
 * Template Post Type: page
 * Description: قالب احترافي لصفحة شروط الاستخدام
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="container">
    <div class="content-area page-terms">
        <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
            <header class="entry-header">
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <span class="entry-date"><?php echo get_the_date('F j, Y'); ?></span>
            </header>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        <?php endwhile; endif; ?>
    </div>
</div>
<?php get_footer(); ?>
