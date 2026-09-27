	</main>
	<footer id="colophon" class="site-footer">
		<div class="container footer-content">
			<div>
				<a class="footer-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
				<p><?php bloginfo( 'description' ); ?></p>
				<?php $cd_options = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array(); ?>
				<?php if ( ! empty( $cd_options['phone'] ) || ! empty( $cd_options['email'] ) || ! empty( $cd_options['address'] ) ) : ?>
					<ul class="cd-contact-list">
						<?php if ( ! empty( $cd_options['phone'] ) ) : ?><li><?php echo esc_html( $cd_options['phone'] ); ?></li><?php endif; ?>
						<?php if ( ! empty( $cd_options['email'] ) ) : ?><li><?php echo esc_html( $cd_options['email'] ); ?></li><?php endif; ?>
						<?php if ( ! empty( $cd_options['address'] ) ) : ?><li><?php echo esc_html( $cd_options['address'] ); ?></li><?php endif; ?>
					</ul>
				<?php endif; ?>
				<?php if ( function_exists( 'car_dealer_footer_social_links' ) ) { car_dealer_footer_social_links(); } ?>
				<?php if ( function_exists( 'car_dealer_newsletter_form' ) ) { car_dealer_newsletter_form(); } ?>
			</div>
			<nav aria-label="<?php esc_attr_e( 'روابط التذييل', 'car-dealer' ); ?>">
				<?php wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'fallback_cb' => false ) ); ?>
			</nav>
			<p class="footer-copyright">© <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
		</div>
	</footer>
</div>
<?php
$cd_opts = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array();
$wa_msg = rawurlencode( 'مرحباً AUTO BRANDS، أريد الاستفسار عن السيارات المتاحة.' );
$wa_phone = preg_replace( '/\D+/', '', $cd_opts['whatsapp'] ?? '' );
$wa_url = $wa_phone ? 'https://wa.me/' . $wa_phone . '?text=' . $wa_msg : '#';
?>

<!-- Floating WhatsApp Button -->
<a href="<?php echo esc_url( $wa_url ); ?>" class="ab-float-wa" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'تواصل عبر واتساب', 'car-dealer' ); ?>">
	<svg viewBox="0 0 64 64" fill="currentColor"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4zm14.08 38.56c-.64 1.28-2.88 2.4-4 2.56-1.12.16-2.56.16-4.16-.48-1.6-.64-3.52-1.44-5.92-2.88-5.12-3.04-8.32-8.16-8.64-8.64-.32-.48-2.56-3.36-2.56-6.4 0-3.04 1.6-4.48 2.24-5.12.64-.64 1.28-.64 1.76-.64h1.28c.48 0 1.12 0 1.6 1.12.48 1.28 1.76 4.32 1.92 4.64.16.32.16.64 0 .96-.16.32-.32.64-.64.96-.32.32-.64.8-.96 1.12-.32.32-.64.64-.32 1.28.32.64 1.6 2.88 3.52 4.8 2.24 2.24 4 3.04 4.64 3.36.64.32 1.12.32 1.44-.16.32-.48 1.6-1.92 2.08-2.56.48-.64.96-.48 1.6-.16.64.32 4 1.92 4.64 2.24.64.32 1.12.48 1.28.8.16.32.16 1.6-.48 2.88z"/></svg>
	<span class="ab-float-wa-tooltip"><?php esc_html_e( 'تواصل معنا', 'car-dealer' ); ?></span>
	<span class="ab-float-wa-pulse"></span>
</a>

<!-- Back to Top Button -->
<button class="ab-back-top" aria-label="<?php esc_attr_e( 'العودة إلى الأعلى', 'car-dealer' ); ?>" id="abBackTop">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</button>

<?php wp_footer(); ?>
</body>
</html>
