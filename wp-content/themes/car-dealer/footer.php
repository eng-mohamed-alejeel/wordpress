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
	$cd_privacy_url = get_privacy_policy_url();
	$cd_terms_url = car_dealer_page_url( 'terms' );
	$cd_has_legal = (bool) ( $cd_privacy_url || $cd_terms_url );
	?>
	<footer id="colophon" class="site-footer">
		<div class="container footer-container">
			<div class="footer-grid <?php echo $cd_has_legal ? 'footer-grid-has-legal' : 'footer-grid-no-legal'; ?>">
				<div class="footer-col footer-brand-col">
					<div class="footer-brand">
						<?php if ( has_custom_logo() ) : ?><?php the_custom_logo(); ?><?php else : ?><a href="<?php echo esc_url( car_dealer_site_url() ); ?>"><?php bloginfo( 'name' ); ?></a><?php endif; ?>
					</div>
					<p class="footer-description"><?php echo esc_html( car_dealer_text( get_bloginfo( 'description' ), 'Explore available vehicles and contact our team.' ) ); ?></p>
					<?php if ( function_exists( 'car_dealer_newsletter_form' ) && function_exists( 'adc_core_owns_marketing_subscription_actions' ) && adc_core_owns_marketing_subscription_actions() ) : ?>
						<div class="footer-newsletter">
							<h2 class="footer-newsletter-title"><?php echo esc_html( car_dealer_text( 'ابقَ على اطلاع', 'Stay informed' ) ); ?></h2>
							<p><?php echo esc_html( car_dealer_text( 'اشترك في تحديثات المعرض إذا رغبت.', 'Opt in to dealership updates.' ) ); ?></p>
							<?php car_dealer_newsletter_form(); ?>
						</div>
					<?php endif; ?>
				</div>
				<nav class="footer-col" aria-label="<?php echo esc_attr( car_dealer_text( 'روابط الموقع', 'Site links' ) ); ?>">
					<h2 class="footer-title"><?php echo esc_html( car_dealer_text( 'روابط سريعة', 'Explore' ) ); ?></h2>
					<ul class="footer-links">
						<li><a href="<?php echo esc_url( car_dealer_site_url() ); ?>"><?php echo esc_html( car_dealer_text( 'الرئيسية', 'Home' ) ); ?></a></li>
						<?php $cd_catalog = car_dealer_archive_url( 'car' ); if ( $cd_catalog ) : ?><li><a href="<?php echo esc_url( $cd_catalog ); ?>"><?php echo esc_html( car_dealer_text( 'السيارات', 'Vehicles' ) ); ?></a></li><?php endif; ?>
						<?php $cd_finance = car_dealer_page_url( 'finance' ); if ( $cd_finance ) : ?><li><a href="<?php echo esc_url( $cd_finance ); ?>"><?php echo esc_html( car_dealer_text( 'حاسبة التمويل', 'Finance Calculator' ) ); ?></a></li><?php endif; ?>
						<?php $cd_about = car_dealer_page_url( 'about' ); if ( $cd_about ) : ?><li><a href="<?php echo esc_url( $cd_about ); ?>"><?php echo esc_html( car_dealer_text( 'من نحن', 'About us' ) ); ?></a></li><?php endif; ?>
						<?php $cd_contact = car_dealer_page_url( 'contact' ); if ( $cd_contact ) : ?><li><a href="<?php echo esc_url( $cd_contact ); ?>"><?php echo esc_html( car_dealer_text( 'تواصل معنا', 'Contact us' ) ); ?></a></li><?php endif; ?>
						<?php $cd_faq = car_dealer_page_url( 'faq' ); if ( $cd_faq ) : ?><li><a href="<?php echo esc_url( $cd_faq ); ?>"><?php echo esc_html( car_dealer_text( 'الأسئلة الشائعة', 'Frequently asked questions' ) ); ?></a></li><?php endif; ?>
						<?php $cd_guide = car_dealer_page_url( 'buying-guide' ); if ( $cd_guide ) : ?><li><a href="<?php echo esc_url( $cd_guide ); ?>"><?php echo esc_html( car_dealer_text( 'دليل اختيار السيارة', 'Vehicle buying guide' ) ); ?></a></li><?php endif; ?>
					</ul>
				</nav>
				<div class="footer-col">
					<h2 class="footer-title"><?php echo esc_html( car_dealer_text( 'التواصل', 'Contact' ) ); ?></h2>
					<ul class="footer-links footer-contact-links">
						<?php if ( $cd_phone ) : ?><li><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $cd_phone ) ); ?>" dir="ltr"><?php echo esc_html( $cd_phone ); ?></a></li><?php endif; ?>
						<?php if ( $cd_email ) : ?><li><a href="<?php echo esc_url( 'mailto:' . $cd_email ); ?>" dir="ltr"><?php echo esc_html( $cd_email ); ?></a></li><?php endif; ?>
						<?php if ( $cd_whatsapp_url ) : ?><li><a href="<?php echo esc_url( $cd_whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( car_dealer_text( 'راسلنا عبر واتساب', 'Message us on WhatsApp' ) ); ?></a></li><?php endif; ?>
					</ul>
					<?php if ( $cd_social ) : ?><div class="footer-social" aria-label="<?php echo esc_attr( car_dealer_text( 'وسائل التواصل الاجتماعي', 'Social media' ) ); ?>"><?php foreach ( $cd_social as $cd_label => $cd_url ) : ?><a href="<?php echo esc_url( $cd_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cd_label ); ?></a><?php endforeach; ?></div><?php endif; ?>
				</div>
				<?php if ( $cd_has_legal ) : ?><div class="footer-col">
					<h2 class="footer-title"><?php echo esc_html( car_dealer_text( 'معلومات مهمة', 'Information' ) ); ?></h2>
					<ul class="footer-links footer-legal-links">
						<?php if ( $cd_privacy_url ) : ?><li><a href="<?php echo esc_url( car_dealer_catalog_localized_url( $cd_privacy_url ) ); ?>"><?php echo esc_html( car_dealer_text( 'سياسة الخصوصية', 'Privacy policy' ) ); ?></a></li><?php endif; ?>
						<?php if ( $cd_terms_url ) : ?><li><a href="<?php echo esc_url( $cd_terms_url ); ?>"><?php echo esc_html( car_dealer_text( 'شروط الاستخدام', 'Terms of use' ) ); ?></a></li><?php endif; ?>
					</ul>
				</div><?php endif; ?>
			</div>
			<div class="footer-bottom"><div class="footer-bottom-content">
				<p class="footer-copyright">© <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?> — <?php echo esc_html( car_dealer_text( 'جميع الحقوق محفوظة.', 'All rights reserved.' ) ); ?></p>
				<?php if ( function_exists( 'car_dealer_catalog_language_switch' ) ) { car_dealer_catalog_language_switch(); } ?>
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
