<?php
/**
 * التكامل مع مواقع التواصل الاجتماعي لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة أزرار المشاركة على وسائل التواصل الاجتماعي
 */
function car_dealer_social_share_buttons() {
	if ( is_singular() ) {
		?>
		<div class="social-share">
			<h3><?php _e( 'مشاركة', 'car-dealer' ); ?></h3>
			<div class="share-buttons">
				<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( get_permalink() ); ?>" target="_blank" class="share-btn facebook">
					<i class="fab fa-facebook-f"></i>
					<span><?php _e( 'فيسبوك', 'car-dealer' ); ?></span>
				</a>
				<a href="https://twitter.com/intent/tweet?url=<?php echo urlencode( get_permalink() ); ?>&text=<?php echo urlencode( get_the_title() ); ?>" target="_blank" class="share-btn twitter">
					<i class="fab fa-twitter"></i>
					<span><?php _e( 'تويتر', 'car-dealer' ); ?></span>
				</a>
				<a href="https://www.linkedin.com/shareArticle?url=<?php echo urlencode( get_permalink() ); ?>&title=<?php echo urlencode( get_the_title() ); ?>" target="_blank" class="share-btn linkedin">
					<i class="fab fa-linkedin-in"></i>
					<span><?php _e( 'لينكدإن', 'car-dealer' ); ?></span>
				</a>
				<a href="https://api.whatsapp.com/send?text=<?php echo urlencode( get_the_title() . ' ' . get_permalink() ); ?>" target="_blank" class="share-btn whatsapp">
					<i class="fab fa-whatsapp"></i>
					<span><?php _e( 'واتساب', 'car-dealer' ); ?></span>
				</a>
			</div>
		</div>
		<?php
	}
}
add_action( 'single_car_after_content', 'car_dealer_social_share_buttons' );

/**
- إضافة أزرار متابعة وسائل التواصل الاجتماعي
 */
function car_dealer_social_follow_buttons() {
	?>
	<div class="social-follow">
		<h3><?php _e( 'تابعنا', 'car-dealer' ); ?></h3>
		<div class="follow-buttons">
			<a href="https://facebook.com/cardealer" target="_blank" class="follow-btn facebook">
				<i class="fab fa-facebook-f"></i>
				<span><?php _e( 'فيسبوك', 'car-dealer' ); ?></span>
			</a>
			<a href="https://twitter.com/cardealer" target="_blank" class="follow-btn twitter">
				<i class="fab fa-twitter"></i>
				<span><?php _e( 'تويتر', 'car-dealer' ); ?></span>
			</a>
			<a href="https://instagram.com/cardealer" target="_blank" class="follow-btn instagram">
				<i class="fab fa-instagram"></i>
				<span><?php _e( 'إنستجرام', 'car-dealer' ); ?></span>
			</a>
			<a href="https://youtube.com/cardealer" target="_blank" class="follow-btn youtube">
				<i class="fab fa-youtube"></i>
				<span><?php _e( 'يوتيوب', 'car-dealer' ); ?></span>
			</a>
		</div>
	</div>
	<?php
}
add_action( 'footer_before_copyright', 'car_dealer_social_follow_buttons' );

/**
- إضافة أزرار المشاركة في صفحات أرشيف السيارات
 */
function car_dealer_archive_social_share() {
	if ( is_post_type_archive( 'car' ) ) {
		?>
		<div class="archive-social-share">
			<h3><?php _e( 'مشاركة هذه الصفحة', 'car-dealer' ); ?></h3>
			<div class="share-buttons">
				<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( get_post_type_archive_link( 'car' ) ); ?>" target="_blank" class="share-btn facebook">
					<i class="fab fa-facebook-f"></i>
					<span><?php _e( 'فيسبوك', 'car-dealer' ); ?></span>
				</a>
				<a href="https://twitter.com/intent/tweet?url=<?php echo urlencode( get_post_type_archive_link( 'car' ) ); ?>&text=<?php echo __( 'تصفح سياراتنا', 'car-dealer' ); ?>" target="_blank" class="share-btn twitter">
					<i class="fab fa-twitter"></i>
					<span><?php _e( 'تويتر', 'car-dealer' ); ?></span>
				</a>
				<a href="https://www.linkedin.com/shareArticle?url=<?php echo urlencode( get_post_type_archive_link( 'car' ) ); ?>&title=<?php echo __( 'سياراتنا', 'car-dealer' ); ?>" target="_blank" class="share-btn linkedin">
					<i class="fab fa-linkedin-in"></i>
					<span><?php _e( 'لينكدإن', 'car-dealer' ); ?></span>
				</a>
			</div>
		</div>
		<?php
	}
}
add_action( 'archive_car_after_title', 'car_dealer_archive_social_share' );

/**
- إضافة دعم لبطاقات Open Graph
 */
function car_dealer_add_open_graph_tags() {
	if ( is_singular() ) {
		global $post;

		// الحصول على الصفة المصغرة
		$image = has_post_thumbnail( $post->ID ) ? wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'og:image' ) : '';

		// الحصول على الوصف
		$description = get_the_excerpt( $post->ID );
		if ( empty( $description ) ) {
			$description = get_bloginfo( 'description' );
		}

		?>
		<meta property="og:type" content="article">
		<meta property="og:title" content="<?php echo esc_attr( get_the_title() ); ?>">
		<meta property="og:description" content="<?php echo esc_attr( $description ); ?>">
		<meta property="og:url" content="<?php echo esc_url( get_permalink() ); ?>">
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<?php if ( $image ) : ?>
			<meta property="og:image" content="<?php echo esc_url( $image[0] ); ?>">
		<?php endif; ?>
		<?php if ( is_singular( 'car' ) ) : ?>
			<meta property="og:article:section" content="سيارات">
		<?php endif; ?>
		<?php
	} elseif ( is_home() || is_front_page() ) {
		// الصفحة الرئيسية
		$image = get_header_image();
		?>
		<meta property="og:type" content="website">
		<meta property="og:title" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<meta property="og:description" content="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>">
		<meta property="og:url" content="<?php echo esc_url( home_url() ); ?>">
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<?php if ( $image ) : ?>
			<meta property="og:image" content="<?php echo esc_url( $image ); ?>">
		<?php endif; ?>
		<?php
	} elseif ( is_post_type_archive( 'car' ) ) {
		// أرشيف السيارات
		?>
		<meta property="og:type" content="website">
		<meta property="og:title" content="<?php echo esc_attr( post_type_archive_title( '', false ) ); ?>">
		<meta property="og:description" content="<?php echo esc_attr( __( 'تصفح مجموعتنا المميزة من السيارات', 'car-dealer' ) ); ?>">
		<meta property="og:url" content="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<?php
	}
}
add_action( 'wp_head', 'car_dealer_add_open_graph_tags' );

/**
- إضافة دعم لبطاقات Twitter Cards
 */
function car_dealer_add_twitter_card_tags() {
	if ( is_singular() ) {
		global $post;

		// الحصول على الصفة المصغرة
		$image = has_post_thumbnail( $post->ID ) ? wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'twitter:image' ) : '';

		// الحصول على الوصف
		$description = get_the_excerpt( $post->ID );
		if ( empty( $description ) ) {
			$description = get_bloginfo( 'description' );
		}

		?>
		<meta name="twitter:card" content="summary_large_image">
		<meta name="twitter:title" content="<?php echo esc_attr( get_the_title() ); ?>">
		<meta name="twitter:description" content="<?php echo esc_attr( $description ); ?>">
		<?php if ( $image ) : ?>
			<meta name="twitter:image" content="<?php echo esc_url( $image[0] ); ?>">
		<?php endif; ?>
		<meta name="twitter:site" content="@cardealer">
		<?php
	} elseif ( is_home() || is_front_page() ) {
		// الصفحة الرئيسية
		$image = get_header_image();
		?>
		<meta name="twitter:card" content="summary_large_image">
		<meta name="twitter:title" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<meta name="twitter:description" content="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>">
		<?php if ( $image ) : ?>
			<meta name="twitter:image" content="<?php echo esc_url( $image ); ?>">
		<?php endif; ?>
		<meta name="twitter:site" content="@cardealer">
		<?php
	} elseif ( is_post_type_archive( 'car' ) ) {
		// أرشيف السيارات
		?>
		<meta name="twitter:card" content="summary">
		<meta name="twitter:title" content="<?php echo esc_attr( post_type_archive_title( '', false ) ); ?>">
		<meta name="twitter:description" content="<?php echo esc_attr( __( 'تصفح مجموعتنا المميزة من السيارات', 'car-dealer' ) ); ?>">
		<meta name="twitter:site" content="@cardealer">
		<?php
	}
}
add_action( 'wp_head', 'car_dealer_add_twitter_card_tags' );

/**
- إضافة دعم لروابط Canonical
 */
function car_dealer_add_canonical_tag() {
	if ( is_singular() ) {
		?>
		<link rel="canonical" href="<?php echo esc_url( get_permalink() ); ?>">
		<?php
	} elseif ( is_post_type_archive( 'car' ) ) {
		?>
		<link rel="canonical" href="<?php echo esc_url( get_post_type_archive_link( 'car' ) ); ?>">
		<?php
	} elseif ( is_home() || is_front_page() ) {
		?>
		<link rel="canonical" href="<?php echo esc_url( home_url() ); ?>">
		<?php
	}
}
add_action( 'wp_head', 'car_dealer_add_canonical_tag' );

/**
- إضافة دعم لروابط Prev/Next
 */
function car_dealer_add_adjacent_posts_links() {
	if ( is_singular( 'car' ) ) {
		$prev_post = get_previous_post();
		$next_post = get_next_post();

		if ( $prev_post || $next_post ) {
			?>
			<div class="adjacent-posts-links">
				<?php if ( $prev_post ) : ?>
					<a href="<?php echo esc_url( get_permalink( $prev_post->ID ) ); ?>" class="prev-post">
						<i class="fas fa-chevron-left"></i>
						<?php echo esc_html( __( 'السيارة السابقة', 'car-dealer' ) ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $next_post ) : ?>
					<a href="<?php echo esc_url( get_permalink( $next_post->ID ) ); ?>" class="next-post">
						<?php echo esc_html( __( 'السيارة التالية', 'car-dealer' ) ); ?>
						<i class="fas fa-chevron-right"></i>
					</a>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
add_action( 'single_car_after_content', 'car_dealer_add_adjacent_posts_links' );
