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
<?php wp_footer(); ?>
</body>
</html>
