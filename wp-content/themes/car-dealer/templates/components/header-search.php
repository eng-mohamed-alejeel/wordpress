<?php
defined('ABSPATH') || exit;
$search_url = $args['url'] ?? '';
$search_value = $args['value'] ?? '';
$search_language = $args['language'] ?? 'ar';
?>
<details class="header-search-disclosure" data-header-search>
    <summary id="header-search-trigger" class="header-search-toggle" aria-controls="header-search-panel" aria-label="<?php echo esc_attr(car_dealer_text('البحث عن سيارة','Search vehicles')); ?>" title="<?php echo esc_attr(car_dealer_text('البحث عن سيارة','Search vehicles')); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg>
    </summary>
    <section id="header-search-panel" class="header-search-panel" aria-label="<?php echo esc_attr(car_dealer_text('البحث في السيارات المتاحة','Search available vehicles')); ?>">
        <form class="header-search" role="search" aria-label="<?php echo esc_attr(car_dealer_text('البحث عن السيارات','Vehicle search')); ?>" method="get" action="<?php echo esc_url($search_url ?: home_url('/')); ?>">
            <?php if ($search_url && !get_option('permalink_structure')) : ?><input type="hidden" name="post_type" value="car"><?php endif; ?>
            <?php if (is_post_type_archive('car')) { foreach (array('brand','model','trim','exterior_color','min_year','max_year','min_price','max_price','sort') as $filter) { if (isset($_GET[$filter]) && is_scalar($_GET[$filter]) && '' !== (string)$_GET[$filter]) { echo '<input type="hidden" name="'.esc_attr($filter).'" value="'.esc_attr(sanitize_text_field(wp_unslash((string)$_GET[$filter]))).'">'; } } } ?>
            <label class="screen-reader-text" for="site-search"><?php echo esc_html(car_dealer_text('اسم السيارة أو الماركة أو الموديل','Vehicle name, brand or model')); ?></label>
            <div class="header-search-field"><input id="site-search" type="search" name="<?php echo esc_attr($search_url ? 'search' : 's'); ?>" value="<?php echo esc_attr($search_value); ?>" placeholder="<?php echo esc_attr(car_dealer_text('ابحث عن سيارة بالاسم أو الماركة أو الموديل','Search vehicles by name, brand or model')); ?>" maxlength="200" required aria-describedby="header-search-status"><button type="button" data-search-clear hidden aria-label="<?php echo esc_attr(car_dealer_text('مسح البحث','Clear search')); ?>">×</button></div>
            <?php if ('en' === $search_language) : ?><input type="hidden" name="lang" value="en"><?php endif; ?>
            <button type="submit"><?php echo esc_html(car_dealer_text('بحث','Search')); ?></button>
        </form>
        <p id="header-search-status" role="status" data-empty-message="<?php echo esc_attr(car_dealer_text('اكتب عبارة البحث أولًا.','Enter a search term first.')); ?>"></p>
    </section>
</details>
