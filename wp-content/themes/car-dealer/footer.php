	</main>
	<?php
	$cd_options = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array();
	$cd_phone = trim( (string) ( $cd_options['phone'] ?? '' ) );
	$cd_email = sanitize_email( (string) ( $cd_options['email'] ?? '' ) );
	$cd_whatsapp_url = function_exists( 'car_dealer_whatsapp_number' ) && car_dealer_whatsapp_number() ? car_dealer_whatsapp_url( car_dealer_text( 'مرحباً، أود الاستفسار عن السيارات المتاحة.', 'Hello, I would like to ask about your available vehicles.' ) ) : '';
	$cd_legal_links = car_dealer_legal_links();
	$cd_has_legal = (bool) $cd_legal_links || has_nav_menu( 'footer' );
	?>
	<footer id="colophon" class="site-footer">
		<div class="container footer-container">
			<div class="footer-grid footer-grid-no-legal">
				<div class="footer-col footer-brand-col">
					<div class="footer-brand">
						<?php if ( has_custom_logo() ) : ?><?php if(class_exists('\AutoDealership\Core\BrandLogo')){echo \AutoDealership\Core\BrandLogo::public_logo('footer');}else{the_custom_logo();} ?><?php else : ?><a href="<?php echo esc_url( car_dealer_site_url() ); ?>"><?php echo esc_html( car_dealer_brand_name() ); ?></a><?php endif; ?>
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
					<?php car_dealer_footer_social_links(); ?>
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
				<?php if ( class_exists('\\AutoDealership\\Core\\BrandIdentity') ) : ?><details class="ab-footer-appearance"><summary><?php echo esc_html(car_dealer_text('تفضيلات المظهر','Appearance preferences')); ?></summary><?php \AutoDealership\Core\BrandIdentity::preferences(); ?></details><?php endif; ?>
				<p class="footer-copyright" dir="<?php echo esc_attr( car_dealer_catalog_direction() ); ?>"><bdi dir="ltr">© <?php echo esc_html( wp_date( 'Y' ) ); ?></bdi> <?php echo esc_html( car_dealer_text( '{legal_name} — جميع الحقوق محفوظة.', '{legal_name} — All rights reserved.' ) ); ?></p>
			</div></div>
		</div>
	</footer>
</div>
<?php if ( $cd_whatsapp_url ) : ?>
	<aside aria-label="<?php echo esc_attr( car_dealer_text( 'التواصل السريع', 'Quick contact' ) ); ?>">
	<a href="<?php echo esc_url( $cd_whatsapp_url ); ?>" class="ab-float-wa" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( car_dealer_text( 'تواصل عبر واتساب', 'Contact us on WhatsApp' ) ); ?>">
		<?php echo car_dealer_whatsapp_icon(); ?>
		<span class="ab-float-wa-tooltip"><?php echo esc_html( car_dealer_text( 'تواصل معنا', 'Chat with us' ) ); ?></span>
	</a>
	</aside>
<?php endif; ?>
<button class="ab-back-top" type="button" aria-label="<?php echo esc_attr( car_dealer_text( 'العودة إلى الأعلى', 'Back to top' ) ); ?>" id="abBackTop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
<?php wp_footer(); ?>
</body>
</html>
