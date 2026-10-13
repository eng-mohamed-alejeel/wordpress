<?php
defined('ABSPATH') || exit;
get_header(); ?>
<div class="container cd-comparison-page">
    <h1><?php echo esc_html(car_dealer_text('مقارنة السيارات','Compare vehicles')); ?></h1>
    <?php echo do_shortcode('[car_dealer_comparison]'); ?>
</div>
<?php get_footer(); ?>
