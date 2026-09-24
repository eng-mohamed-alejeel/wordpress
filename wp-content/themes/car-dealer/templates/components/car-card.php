<?php
/** Reusable vehicle card. */
defined( 'ABSPATH' ) || exit;

$id         = get_the_ID();
$price      = get_post_meta( $id, '_car_price', true );
$year       = get_post_meta( $id, '_car_year', true );
$kilometers = get_post_meta( $id, '_car_kilometers', true );
$monthly    = get_post_meta( $id, '_car_monthly_payment', true );
$featured   = get_post_meta( $id, '_car_featured', true );
$status     = get_post_meta( $id, '_car_inventory_status', true ) ?: 'available';
$brands     = get_the_terms( $id, 'car_brand' );
$categories = get_the_terms( $id, 'car_category' );
$category   = ! is_wp_error( $categories ) && ! empty( $categories ) ? $categories[0]->slug : '';
?>
<article class="car-card" data-category="<?php echo esc_attr( $category ); ?>">
	<?php if ( $featured ) : ?>
		<span class="car-badge"><?php esc_html_e( 'مميزة', 'car-dealer' ); ?></span>
	<?php endif; ?>
	<a class="car-image" href="<?php the_permalink(); ?>">
		<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'car-card', array( 'loading' => 'lazy' ) ); } else { ?><span class="car-image-placeholder">🚘</span><?php } ?>
	</a>
	<div class="car-details">
		<p class="car-brand"><?php echo ! is_wp_error( $brands ) && ! empty( $brands ) ? esc_html( $brands[0]->name ) : esc_html__( 'سيارة متاحة', 'car-dealer' ); ?></p>
		<h2 class="car-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<?php if ( $price ) : ?><p class="car-price"><?php echo esc_html( car_dealer_format_price( $price ) ); ?></p><?php endif; ?>
		<?php if ( $monthly ) : ?><p class="car-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
		<ul class="car-specs">
			<?php if ( $year ) : ?><li><?php echo esc_html( $year ); ?></li><?php endif; ?>
			<?php if ( $kilometers ) : ?><li><?php echo esc_html( number_format_i18n( $kilometers ) . ' كم' ); ?></li><?php endif; ?>
			<li><?php echo esc_html( function_exists( 'car_dealer_inventory_status_label' ) ? car_dealer_inventory_status_label( $status ) : $status ); ?></li>
		</ul>
		<div class="car-card-actions">
			<a href="<?php the_permalink(); ?>" class="btn btn-primary"><?php esc_html_e( 'عرض التفاصيل', 'car-dealer' ); ?></a>
			<?php if ( function_exists( 'car_dealer_comparison_button' ) ) { car_dealer_comparison_button( $id ); } ?>
		</div>
	</div>
</article>
