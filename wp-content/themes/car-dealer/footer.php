	</main>
	<footer id="colophon" class="site-footer">
		<div class="container footer-container">
			<div class="footer-grid">
				
				<!-- Brand Column -->
				<div class="footer-col footer-brand-col">
					<div class="footer-brand">
						<?php if ( has_custom_logo() ) { the_custom_logo(); } else { ?><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a><?php } ?>
					</div>
					<?php if ( function_exists( 'car_dealer_newsletter_form' ) ) { car_dealer_newsletter_form(); } ?>
					<p class="footer-description"><?php bloginfo( 'description' ); ?></p>
					<div class="footer-contact">
						<?php $cd_options = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array(); ?>
						<?php if ( ! empty( $cd_options['phone'] ) || ! empty( $cd_options['email'] ) ) : ?>
							<ul class="cd-contact-list">
								<?php if ( ! empty( $cd_options['phone'] ) ) : ?><li><span>الهاتف:</span> <?php echo esc_html( $cd_options['phone'] ); ?></li><?php endif; ?>
								<?php if ( ! empty( $cd_options['email'] ) ) : ?><li><span>البريد:</span> <?php echo esc_html( $cd_options['email'] ); ?></li><?php endif; ?>
							</ul>
						<?php endif; ?>
					</div>
					<?php if ( function_exists( 'car_dealer_footer_social_links' ) ) { car_dealer_footer_social_links(); } ?>
				</div>

				<!-- Quick Links Column -->
				<div class="footer-col">
					<h4 class="footer-title">روابط سريعة</h4>
					<ul class="footer-links">
						<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">الرئيسية</a></li>
						<?php $catalog_url = car_dealer_archive_url( 'car' ); if ( $catalog_url ) : ?><li><a href="<?php echo esc_url( $catalog_url ); ?>">السيارات</a></li><?php endif; ?>
						<?php $finance_url = car_dealer_page_url( 'finance' ); if ( $finance_url ) : ?><li><a href="<?php echo esc_url( $finance_url ); ?>">التمويل</a></li><?php endif; ?>
						<?php $contact_url = car_dealer_page_url( 'contact' ); if ( $contact_url ) : ?><li><a href="<?php echo esc_url( $contact_url ); ?>">تواصل معنا</a></li><?php endif; ?>
						<?php $about_url = car_dealer_page_url( 'about' ); if ( $about_url ) : ?><li><a href="<?php echo esc_url( $about_url ); ?>">من نحن</a></li><?php endif; ?>
					</ul>
				</div>

				<!-- Legal Column (Privacy & Terms) -->
				<div class="footer-col">
					<h4 class="footer-title">قانوني</h4>
					<ul class="footer-links footer-legal-links">
						<?php $privacy_url = get_privacy_policy_url(); if ( $privacy_url ) : ?><li>
							<a href="<?php echo esc_url( $privacy_url ); ?>">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
								سياسة الخصوصية
							</a>
						</li><?php endif; ?>
						<?php $terms_url = car_dealer_page_url( 'terms' ); if ( $terms_url ) : ?><li>
							<a href="<?php echo esc_url( $terms_url ); ?>">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
								شروط الاستخدام
							</a>
						</li><?php endif; ?>
					</ul>
				</div>

				<!-- Contact/Social Column -->
				<div class="footer-col">
					<h4 class="footer-title">تابعنا</h4>
					<div class="footer-social">
					</div>
				</div>

			</div>

			<!-- Copyright Bar -->
			<div class="footer-bottom">
				<div class="footer-bottom-content">
					<p class="footer-copyright">
						© <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?> — جميع الحقوق محفوظة.
					</p>
				</div>
			</div>
		</div>
	</footer>
</div>
<?php
$cd_opts = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array();
$wa_msg = rawurlencode( 'مرحباً AUTO BRANDS، أريد الاستفسار عن السيارات المتاحة.' );
$wa_phone = preg_replace( '/\D+/', '', $cd_opts['whatsapp'] ?? '' );
$wa_url = $wa_phone ? 'https://wa.me/' . $wa_phone . '?text=' . $wa_msg : '';
?>

<!-- Floating WhatsApp Button -->
<?php if ( $wa_url ) : ?>
<a href="<?php echo esc_url( $wa_url ); ?>" class="ab-float-wa" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'تواصل عبر واتساب', 'car-dealer' ); ?>">
	<svg viewBox="0 0 64 64" fill="currentColor"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4zm14.08 38.56c-.64 1.28-2.88 2.4-4 2.56-1.12.16-2.56.16-4.16-.48-1.6-.64-3.52-1.44-5.92-2.88-5.12-3.04-8.32-8.16-8.64-8.64-.32-.48-2.56-3.36-2.56-6.4 0-3.04 1.6-4.48 2.24-5.12.64-.64 1.28-.64 1.76-.64h1.28c.48 0 1.12 0 1.6 1.12.48 1.28 1.76 4.32 1.92 4.64.16.32.16.64 0 .96-.16.32-.32.64-.64.96-.32.32-.64.8-.96 1.12-.32.32-.64.64-.32 1.28.32.64 1.6 2.88 3.52 4.8 2.24 2.24 4 3.04 4.64 3.36.64.32 1.12.32 1.44-.16.32-.48 1.6-1.92 2.08-2.56.48-.64.96-.48 1.6-.16.64.32 4 1.92 4.64 2.24.64.32 1.12.48 1.28.8.16.32.16 1.6-.48 2.88z"/></svg>
	<span class="ab-float-wa-tooltip"><?php esc_html_e( 'تواصل معنا', 'car-dealer' ); ?></span>
	<span class="ab-float-wa-pulse"></span>
</a>
<?php endif; ?>

<!-- Back to Top Button -->
<button class="ab-back-top" aria-label="<?php esc_attr_e( 'العودة إلى الأعلى', 'car-dealer' ); ?>" id="abBackTop">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</button>

<?php wp_footer(); ?>
</body>
</html>
