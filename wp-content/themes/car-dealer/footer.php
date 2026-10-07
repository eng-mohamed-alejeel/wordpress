	</main>
	<?php
	$cd_options = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array();
	$cd_phone = trim( (string) ( $cd_options['phone'] ?? '' ) );
	$cd_email = sanitize_email( (string) ( $cd_options['email'] ?? '' ) );
	$cd_whatsapp_url = function_exists( 'car_dealer_whatsapp_number' ) && car_dealer_whatsapp_number() ? car_dealer_whatsapp_url( car_dealer_text( 'مرحباً، أود الاستفسار عن السيارات المتاحة.', 'Hello, I would like to ask about your available vehicles.' ) ) : '';
	$cd_social = array_filter( array(
		'Facebook' => esc_url( (string) ( $cd_options['facebook'] ?? '' ) ),
		'Instagram' => esc_url( (string) ( $cd_options['instagram'] ?? '' ) ),
	) );
	$cd_legal_links = car_dealer_legal_links();
	$cd_has_legal = (bool) $cd_legal_links || has_nav_menu( 'footer' );
	?>
	<footer id="colophon" class="site-footer">
		<div class="container footer-container">
			<div class="footer-grid footer-grid-no-legal">
				<div class="footer-col footer-brand-col">
					<div class="footer-brand">
						<?php if ( has_custom_logo() ) : ?><?php the_custom_logo(); ?><?php else : ?><a href="<?php echo esc_url( car_dealer_site_url() ); ?>"><?php bloginfo( 'name' ); ?></a><?php endif; ?>
					</div>
					<p class="footer-description"><?php echo esc_html( car_dealer_text( get_bloginfo( 'description' ), $cd_options['description_en'] ?? 'Explore available vehicles and contact our team.' ) ); ?></p>

				</div>
				<?php if ( $cd_has_legal ) : ?><nav class="footer-col footer-policy-col" aria-label="<?php echo esc_attr( car_dealer_text( 'السياسات والمعلومات القانونية', 'Policies and legal information' ) ); ?>">
					<h2 class="footer-title"><?php echo esc_html( car_dealer_text( 'السياسات والمعلومات القانونية', 'Policies and legal information' ) ); ?></h2>
					<?php if ( has_nav_menu( 'footer' ) ) : wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'menu_class' => 'footer-links footer-legal-links', 'depth' => 1, 'fallback_cb' => false ) ); else : ?>
					<ul class="footer-links footer-legal-links">
						<?php foreach ( $cd_legal_links as $cd_legal_link ) : ?><li><a href="<?php echo esc_url( $cd_legal_link['url'] ); ?>"><?php echo esc_html( $cd_legal_link['label'] ); ?></a></li><?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</nav><?php endif; ?>
				<div class="footer-col footer-contact-col">
					<h2 class="footer-title"><?php echo esc_html( car_dealer_text( 'التواصل', 'Contact' ) ); ?></h2>
					<ul class="footer-links footer-contact-links">
						<?php if ( $cd_phone ) : ?><li><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $cd_phone ) ); ?>" dir="ltr"><?php echo esc_html( $cd_phone ); ?></a></li><?php endif; ?>
						<?php if ( $cd_email ) : ?><li><a href="<?php echo esc_url( 'mailto:' . $cd_email ); ?>" dir="ltr"><?php echo esc_html( $cd_email ); ?></a></li><?php endif; ?>
						<?php if ( $cd_whatsapp_url ) : ?><li><a href="<?php echo esc_url( $cd_whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( car_dealer_text( 'راسلنا عبر واتساب', 'Message us on WhatsApp' ) ); ?></a></li><?php endif; ?>
					</ul>
					<?php if ( $cd_social ) : ?><div class="footer-social" aria-label="<?php echo esc_attr( car_dealer_text( 'وسائل التواصل الاجتماعي', 'Social media' ) ); ?>"><?php foreach ( $cd_social as $cd_label => $cd_url ) : ?><a href="<?php echo esc_url( $cd_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( $cd_label, 'car-dealer' ); ?></a><?php endforeach; ?></div><?php endif; ?>
				</div>
					<?php if ( function_exists( 'car_dealer_newsletter_form' ) && function_exists( 'adc_core_owns_marketing_subscription_actions' ) && adc_core_owns_marketing_subscription_actions() ) : ?>
						<div class="footer-col footer-newsletter">
							<h2 class="footer-newsletter-title"><?php echo esc_html( car_dealer_text( 'ابقَ على اطلاع', 'Stay informed' ) ); ?></h2>
							<p><?php echo esc_html( car_dealer_text( 'اشترك في تحديثات المعرض إذا رغبت.', 'Opt in to dealership updates.' ) ); ?></p>
							<?php car_dealer_newsletter_form(); ?>
						</div>
					<?php endif; ?>
			</div>
				<div class="footer-bottom"><div class="footer-bottom-content">
				<p class="footer-copyright" dir="<?php echo esc_attr( car_dealer_catalog_direction() ); ?>"><bdi dir="ltr">© <?php echo esc_html( wp_date( 'Y' ) ); ?></bdi> <?php echo esc_html( car_dealer_text( 'شركة أوتو براندز — جميع الحقوق محفوظة.', 'Auto Brands Company — All rights reserved.' ) ); ?></p>
			</div></div>
		</div>
	</footer>
</div>
<?php if ( $cd_whatsapp_url ) : ?>
	<a href="<?php echo esc_url( $cd_whatsapp_url ); ?>" class="ab-float-wa" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( car_dealer_text( 'تواصل عبر واتساب', 'Contact us on WhatsApp' ) ); ?>">
		<svg viewBox="0 0 64 64" fill="currentColor" aria-hidden="true"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4zm14.08 38.56c-.64 1.28-2.88 2.4-4 2.56-1.12.16-2.56.16-4.16-.48-1.6-.64-3.52-1.44-5.92-2.88-5.12-3.04-8.32-8.16-8.64-8.64-.32-.48-2.56-3.36-2.56-6.4 0-3.04 1.6-4.48 2.24-5.12.64-.64 1.28-.64 1.76-.64h1.28c.48 0 1.12 0 1.6 1.12.48 1.28 1.76 4.32 1.92 4.64.16.32.16.64 0 .96-.16.32-.32.64-.64.96-.32.32-.64.8-.96 1.12-.32.32-.64.64-.32 1.28.32.64 1.6 2.88 3.52 4.8 2.24 2.24 4 3.04 4.64 3.36.64.32 1.12.32 1.44-.16.32-.48 1.6-1.92 2.08-2.56.48-.64.96-.48 1.6-.16.64.32 4 1.92 4.64 2.24.64.32 1.12.48 1.28.8.16.32.16 1.6-.48 2.88z"/></svg>
		<span class="ab-float-wa-tooltip"><?php echo esc_html( car_dealer_text( 'تواصل معنا', 'Chat with us' ) ); ?></span>
		<span class="ab-float-wa-pulse" aria-hidden="true"></span>
	</a>
<?php endif; ?>
<button class="ab-back-top" type="button" aria-label="<?php echo esc_attr( car_dealer_text( 'العودة إلى الأعلى', 'Back to top' ) ); ?>" id="abBackTop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
<?php wp_footer(); ?>
</body>
</html>
