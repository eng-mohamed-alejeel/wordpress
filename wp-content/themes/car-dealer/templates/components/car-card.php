<?php
/** Reusable vehicle card. */
defined( 'ABSPATH' ) || exit;

$id         = get_the_ID();
$view       = car_dealer_vehicle_view( $id, is_preview() );
if ( ! $view ) { return; }
$vehicle    = $view['vehicle'];
$price      = $view['price'];
$year       = $view['year'];
$kilometers = $view['kilometers'];
$monthly    = $view['monthly'];
$featured   = $view['featured'];
$status     = $view['status'];
$brand_name = $view['brand_name'];
$category   = $view['category'];
$permalink  = function_exists( 'car_dealer_catalog_localized_url' ) ? car_dealer_catalog_localized_url( get_permalink( $id ) ) : get_permalink( $id );
?>
<article class="car-card" data-category="<?php echo esc_attr( $category ); ?>">
	<?php if ( $featured ) : ?>
		<span class="car-badge"><?php esc_html_e( 'مميزة', 'car-dealer' ); ?></span>
	<?php endif; ?>
	<a class="car-image" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'عرض تفاصيل %s', 'car-dealer' ), get_the_title() ) ); ?>">
		<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'car-card', array( 'loading' => 'lazy', 'alt' => get_post_meta( get_post_thumbnail_id(), '_wp_attachment_image_alt', true ) ?: get_the_title() ) ); } else { ?><span class="car-image-placeholder"><?php echo car_dealer_vehicle_placeholder(); ?><span class="car-image-placeholder-label"><?php echo esc_html( car_dealer_text( 'صورة افتراضية للسيارة', 'Default vehicle image' ) ); ?></span></span><?php } ?>
	</a>
	<div class="car-details">
		<div class="car-card-meta">
		<p class="car-brand"><?php echo $brand_name ? esc_html( car_dealer_catalog_value_label( (string) $brand_name ) ) : esc_html__( 'سيارة متاحة', 'car-dealer' ); ?></p>
		<?php if ( class_exists( '\AutoDealership\Portal\CustomerJourney' ) ) { \AutoDealership\Portal\CustomerJourney::favorite_button( (int) $id, true ); } ?>
		</div>
		<h2 class="car-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a></h2>
		<?php if ( $price ) : ?><p class="car-price"><?php if ( ! empty( $view['price_from'] ) ) { echo esc_html( car_dealer_text( 'يبدأ من ', 'From ' ) ); } echo esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $price ) : car_dealer_format_price( $price ) ); ?></p><?php endif; ?>
		<?php
		$card_colors = isset( $view['catalog_model'] ) ? (array) $view['catalog_model']['colors'] : array( $view['color'] ?? '' );
		$card_swatches = array();
		foreach ( $card_colors as $card_color ) {
			if ( ! is_string( $card_color ) || '' === trim( $card_color ) ) { continue; }
			$resolved = class_exists( '\AutoDealership\Inventory\VehicleColors' ) ? \AutoDealership\Inventory\VehicleColors::resolve( $card_color ) : array( 'key' => $card_color, 'label' => car_dealer_catalog_value_label( $card_color ), 'hex' => '', 'second' => '' );
			$card_swatches[ $resolved['key'] ] = $resolved;
		}
		if ( $card_swatches ) : ?>
		<ul class="car-model-colors car-card-swatches" aria-label="<?php echo esc_attr( car_dealer_text( 'ألوان السيارة', 'Vehicle colors' ) ); ?>">
			<?php foreach ( $card_swatches as $card_swatch ) :
				$swatch_hex = sanitize_hex_color( $card_swatch['hex'] ) ?: '';
				$swatch_second = sanitize_hex_color( $card_swatch['second'] ) ?: $swatch_hex;
				$swatch_style = $swatch_hex ? '--card-color:' . $swatch_hex . ';--card-color-second:' . $swatch_second . ';' : '';
				?><li><span class="car-card-swatch<?php echo $swatch_hex ? '' : ' is-unknown'; ?>" role="img" aria-label="<?php echo esc_attr( $card_swatch['label'] ); ?>" title="<?php echo esc_attr( $card_swatch['label'] ); ?>" style="<?php echo esc_attr( $swatch_style ); ?>"><?php if ( ! $swatch_hex ) : ?><span aria-hidden="true">?</span><?php endif; ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php if ( $price && ! empty( $view['price_label'] ) ) : ?><p class="public-price-inclusions"><?php echo esc_html( $view['price_label'] ); ?></p><?php endif; ?>
		<?php if ( $monthly ) : ?><p class="car-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $monthly ) : car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
		<ul class="car-specs">
			<?php if ( isset( $view['catalog_model'] ) ) : $trims = array_values( array_unique( array_filter( wp_list_pluck( $view['catalog_model']['options'], 'trim_name' ) ) ) ); if ( $trims ) : ?><li><?php echo esc_html( car_dealer_text( 'الفئة: ', 'Trim: ' ) . implode( ' / ', array_map( 'car_dealer_catalog_value_label', $trims ) ) ); ?></li><?php endif; endif; ?>
			<?php if ( $year ) : ?><li><?php echo esc_html( $year ); ?></li><?php endif; ?>
			<?php if ( null !== $kilometers && '' !== $kilometers && false !== $kilometers ) : ?><li><?php echo esc_html( function_exists( 'car_dealer_catalog_distance' ) ? car_dealer_catalog_distance( $kilometers ) : number_format_i18n( $kilometers ) . ' كم' ); ?></li><?php endif; ?>
			<?php if ( $vehicle && $vehicle['branch_name'] ) : ?><li><?php echo esc_html( $vehicle['branch_name'] ); ?></li><?php endif; ?>
			<li><?php echo esc_html( function_exists( 'car_dealer_inventory_status_label' ) ? car_dealer_inventory_status_label( $status ) : $status ); ?></li>
		</ul>
		<div class="car-card-actions">
			<a href="<?php echo esc_url( $permalink ); ?>" class="btn btn-primary"><?php esc_html_e( 'عرض التفاصيل', 'car-dealer' ); ?></a>
			<?php if ( function_exists( 'car_dealer_comparison_button' ) ) { car_dealer_comparison_button( $id ); } ?>
		</div>
	</div>
</article>
