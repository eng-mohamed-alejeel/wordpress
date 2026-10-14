<?php
namespace AutoDealership\Admin;

use AutoDealership\Leads\NewsletterService as Newsletter;

defined( 'ABSPATH' ) || exit;

/** Campaign composer, previews, delivery results and subscriber management. */
final class NewsletterPage {
	private static function t( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	private static function url( array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => 'car-dealer-subscribers' ), $args ), admin_url( 'admin.php' ) ); }
	public static function boot(): void {
		add_action( 'admin_post_adc_newsletter', array( self::class, 'submit' ) );
		add_action( 'wp_ajax_adc_newsletter_preview', array( self::class, 'preview' ) );
		add_action( 'admin_enqueue_scripts', static function (): void {
			if ( 'car-dealer-subscribers' === ( $_GET['page'] ?? '' ) ) {
				wp_enqueue_style( 'adc-newsletter', plugins_url( 'assets/css/admin-newsletter.css', ADC_FILE ), array( 'adc-admin' ), (string) filemtime( ADC_PATH . 'assets/css/admin-newsletter.css' ) );
				wp_enqueue_script( 'adc-newsletter-preview', plugins_url( 'assets/js/admin-newsletter.js', ADC_FILE ), array( 'jquery' ), (string) filemtime( ADC_PATH . 'assets/js/admin-newsletter.js' ), true );
				wp_localize_script( 'adc-newsletter-preview', 'adcNewsletterPreview', array( 'url'=>admin_url( 'admin-ajax.php' ), 'nonce'=>wp_create_nonce( 'adc_newsletter_preview' ), 'interfaceLanguage'=>\AutoDealership\Core\Localization::language(), 'updating'=>self::t( 'جارٍ تحديث المعاينة…', 'Updating preview…' ), 'ready'=>self::t( 'المعاينة مباشرة وتعكس آخر تعديلاتك.', 'Live preview shows your latest changes.' ), 'failed'=>self::t( 'تعذر تحديث المعاينة. حاول مجددًا.', 'Preview could not be updated. Please try again.' ) ) );
			}
		} );
	}
	private static function field( string $name ): string { return isset( $_POST[$name] ) && is_string( $_POST[$name] ) ? wp_unslash( $_POST[$name] ) : ''; }
	private static function input(): array {
		$input = array( 'creation_key'=>self::field( 'creation_key' ) );
		foreach ( array( 'ar', 'en' ) as $lang ) { foreach ( array( 'subject', 'body', 'button_text', 'button_url' ) as $field ) { $input[$field . '_' . $lang] = self::field( $field . '_' . $lang ); } }
		return $input;
	}
	public static function preview(): void {
		if ( ! Newsletter::can_manage() ) { wp_send_json_error( array( 'message'=>self::t( 'ليست لديك صلاحية إدارة الحملات.', 'You cannot manage campaigns.' ) ), 403 ); }
		check_ajax_referer( 'adc_newsletter_preview', 'nonce' );
		$input = self::input();
		if ( strlen( $input['body_ar'] ) > 100000 || strlen( $input['body_en'] ) > 100000 ) { wp_send_json_error( array( 'message'=>self::t( 'محتوى الرسالة طويل جدًا.', 'The email content is too long.' ) ), 400 ); }
		wp_send_json_success( array( 'html'=>Newsletter::preview( $input, self::field( 'preview_language' ) ) ) );
	}
	private static function redirect( int $id, string $notice, string $error = '' ): void {
		if ( $error ) { set_transient( 'adc_newsletter_notice_' . get_current_user_id(), $error, MINUTE_IN_SECONDS ); }
		wp_safe_redirect( self::url( array( 'campaign' => $id, 'notice' => $notice ) ) ); exit;
	}
	public static function submit(): void {
		if ( ! Newsletter::can_manage() ) { wp_die( esc_html( self::t( 'ليست لديك صلاحية إدارة الحملات.', 'You cannot manage campaigns.' ) ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_newsletter' );
		$id = absint( self::field( 'campaign' ) ); $op = sanitize_key( self::field( 'operation' ) );
		$result = true; $notice = 'saved';
		if ( in_array( $op, array( 'save', 'send' ), true ) ) {
			$result = Newsletter::save( self::input(), $id );
			if ( is_wp_error( $result ) ) { self::redirect( $id, 'error', $result->get_error_message() ); }
			$id = $result;
			if ( 'send' === $op ) { $result = Newsletter::queue( $id ); $notice = 'queued'; }
		} elseif ( 'cancel' === $op ) {
			$result = Newsletter::cancel( $id ); $notice = 'cancelled';
		} elseif ( 'retry' === $op ) {
			$result = Newsletter::retry( $id ); $notice = 'retried';
		} elseif ( 'process' === $op ) {
			$result = Newsletter::run_mail();
			$notice = 'processed';
		} elseif ( 'copy' === $op ) {
			$result = Newsletter::duplicate( $id, self::field( 'copy_key' ) );
			if ( ! is_wp_error( $result ) ) { $id=$result; } $notice='copied';
		} elseif ( in_array( $op, array( 'archive', 'restore' ), true ) ) {
			$result=Newsletter::archive( $id, 'restore' === $op ); $notice='archive' === $op ? 'archived' : 'restored';
		} elseif ( 'delete' === $op ) {
			$result=Newsletter::delete_draft( $id ); if ( ! is_wp_error( $result ) ) { $id=0; } $notice='deleted';
		} elseif ( 'stop_subscriber' === $op ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Newsletter::subscribers() . ' WHERE id=%d', absint( self::field( 'subscriber' ) ) ), ARRAY_A );
			if ( $row ) {
				parse_str( (string) wp_parse_url( Newsletter::unsubscribe_url( $row ), PHP_URL_QUERY ), $query );
				$result = Newsletter::unsubscribe( (int) $row['id'], $query['token'] );
			} else { $result = new \WP_Error( 'adc_subscriber_missing', self::t( 'المشترك غير موجود.', 'Subscriber not found.' ) ); }
			$notice = 'stopped';
		} else { wp_die( 'Invalid action', '', array( 'response' => 400 ) ); }
		self::redirect( $id, is_wp_error( $result ) ? 'error' : $notice, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}
	private static function form_start( int $id = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_newsletter"><input type="hidden" name="campaign" value="' . esc_attr( (string) $id ) . '">'; wp_nonce_field( 'adc_newsletter' );
	}
	private static function action( int $id, string $op, string $label, string $class = 'button' ): void {
		self::form_start( $id );
		if ( 'copy' === $op ) { echo '<input type="hidden" name="copy_key" value="' . esc_attr( wp_generate_uuid4() ) . '">'; }
		$confirm = 'delete' === $op ? ' data-newsletter-confirm="' . esc_attr( self::t( 'هل تريد حذف هذه المسودة نهائيًا؟', 'Permanently delete this draft?' ) ) . '"' : '';
		echo '<button class="' . esc_attr( $class ) . '" name="operation" value="' . esc_attr( $op ) . '"' . $confirm . '>' . esc_html( $label ) . '</button></form>';
	}
	private static function management( array $campaign, array $stats ): void {
		if ( ! Newsletter::can_manage() ) { return; }
		$id=(int)$campaign['id'];
		echo '<div class="adc-nl-management">';
		self::action( $id, 'copy', self::t( 'نسخ إلى حملة جديدة', 'Copy to a new campaign' ) );
		if ( $campaign['archived_at'] ) { self::action( $id, 'restore', self::t( 'إلغاء الأرشفة', 'Restore from archive' ) ); }
		elseif ( 'draft' === $campaign['status'] ) {
			echo '<a class="button" href="' . esc_url( self::url( array( 'campaign'=>$id ) ) ) . '">' . esc_html( self::t( 'تعديل المسودة', 'Edit draft' ) ) . '</a>';
			self::action( $id, 'delete', self::t( 'حذف المسودة', 'Delete draft' ), 'button adc-nl-delete' );
		} elseif ( Newsletter::can_archive( $campaign, $stats ) ) { self::action( $id, 'archive', self::t( 'أرشفة الحملة', 'Archive campaign' ) ); }
		echo '</div>';
	}
	public static function render(): void {
		if ( ! current_user_can( 'adc_view_marketing_subscribers' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		global $wpdb;
		$active = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::subscribers() . " WHERE status='active'" );
		$inactive = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::subscribers() . " WHERE status<>'active'" );
		$sent = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::table( 'deliveries' ) . " WHERE status='sent'" );
		$campaign_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::table( 'campaigns' ) );
		$id = absint( $_GET['campaign'] ?? 0 ); $campaign = $id ? Newsletter::campaign( $id ) : null;
		$dir = 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl';
		echo '<div class="wrap adc-newsletter" dir="' . $dir . '"><h1>' . esc_html( self::t( 'النشرة البريدية', 'Newsletter' ) ) . '</h1><div class="adc-nl-heading"><p class="description">' . esc_html( self::t( 'إدارة المشتركين والحملات البريدية ونتائج الإرسال.', 'Manage subscribers, email campaigns and sending results.' ) ) . '</p>';
		if ( Newsletter::can_manage() ) { echo '<a class="button button-primary" href="' . esc_url( self::url( array( 'new' => 1 ) ) ) . '">' . esc_html( self::t( 'إنشاء حملة جديدة', 'New campaign' ) ) . '</a>'; }
		echo '</div>';
		self::notices();
		echo '<div class="adc-nl-stats">';
		foreach ( array( array( $active, self::t( 'مشتركون نشطون', 'Active subscribers' ) ), array( $inactive, self::t( 'اشتراكات متوقفة', 'Inactive subscriptions' ) ), array( $campaign_count, self::t( 'الحملات', 'Campaigns' ) ), array( $sent, self::t( 'رسائل قُبلت للإرسال', 'Emails accepted for sending' ) ) ) as $stat ) { echo '<div class="card adc-nl-stat"><strong>' . esc_html( number_format_i18n( $stat[0] ) ) . '</strong><span>' . esc_html( $stat[1] ) . '</span></div>'; }
		echo '</div>';
		if ( Newsletter::can_manage() && ( isset( $_GET['new'] ) || $campaign ) ) { self::composer( $campaign, $active ); }
		self::campaigns(); self::subscriber_list(); echo '</div>';
	}
	private static function notices(): void {
		$messages = array( 'saved' => self::t( 'تم حفظ مسودة الحملة.', 'Campaign draft saved.' ), 'queued' => self::t( 'بدأت الحملة. تُرسل الرسائل على دفعات للمشتركين النشطين.', 'Campaign started. Emails are sent in batches to active subscribers.' ), 'cancelled' => self::t( 'تم إيقاف الإرسال المتبقي للحملة.', 'Remaining campaign deliveries cancelled.' ), 'retried' => self::t( 'أُعيدت جدولة الرسائل المتعثرة، حتى 100 رسالة.', 'Failed deliveries requeued, up to 100 emails.' ), 'processed' => self::t( 'تمت معالجة دفعة. حدّث الصفحة لمتابعة النتائج.', 'A batch was processed. Refresh to view progress.' ), 'stopped' => self::t( 'تم إيقاف اشتراك البريد المحدد.', 'Subscription stopped.' ) );
		$notice = sanitize_key( $_GET['notice'] ?? '' );
		$messages += array( 'copied'=>self::t( 'تم إنشاء مسودة مستقلة من الحملة. راجع النسختين قبل الإرسال.', 'An independent draft was created. Review both versions before sending.' ), 'archived'=>self::t( 'تمت أرشفة الحملة مع الاحتفاظ بمحتواها ونتائجها.', 'Campaign archived with its content and results retained.' ), 'restored'=>self::t( 'أُعيدت الحملة إلى القائمة الرئيسية.', 'Campaign restored to the main list.' ), 'deleted'=>self::t( 'تم حذف المسودة التي لم تُرسل.', 'Unsent draft deleted.' ) );
		if ( 'error' === $notice ) { $error = get_transient( 'adc_newsletter_notice_' . get_current_user_id() ); delete_transient( 'adc_newsletter_notice_' . get_current_user_id() ); if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; } }
		elseif ( isset( $messages[$notice] ) ) { echo '<div class="notice notice-success" role="status" data-success-notice data-notice-param="notice"><p>' . esc_html( $messages[$notice] ) . '</p></div>'; }
	}
	private static function composer( ?array $campaign, int $active ): void {
		$id = (int) ( $campaign['id'] ?? 0 ); $draft = ! $campaign || 'draft' === $campaign['status'];
		$language = \AutoDealership\Core\Localization::language();
		$preview_language = $campaign && empty( $campaign['bilingual'] ) ? Newsletter::language( $campaign['locale'] ) : $language;
		$fields = Newsletter::content_fields( $campaign ?? array() );
		// Legacy drafts retain their original language; translations are authored explicitly.
		if ( $campaign && empty( $campaign['bilingual'] ) ) {
			$lang = Newsletter::language( $campaign['locale'] );
			foreach ( array( 'subject', 'body', 'button_text', 'button_url' ) as $field ) { $fields[$field . '_' . $lang] = $campaign[$field]; }
		}
		echo '<div class="adc-nl-layout"><section class="card adc-nl-panel"><h2>' . esc_html( self::t( $draft ? 'إنشاء حملة باللغتين' : 'متابعة الحملة', $draft ? 'Create a bilingual campaign' : 'Campaign progress' ) ) . '</h2>';
		if ( $draft ) {
			self::form_start( $id );
			echo '<input type="hidden" name="creation_key" value="' . esc_attr( $campaign['creation_key'] ?? wp_generate_uuid4() ) . '">';
			echo '<p class="description">' . esc_html( self::t( 'اكتب النسختين؛ يتلقى كل مشترك الرسالة بحسب لغة البريد التي يفضلها.', 'Write both versions. Each subscriber receives the email in their preferred language.' ) ) . '</p>';
			foreach ( array( 'ar', 'en' ) as $lang ) {
				$dir = 'ar' === $lang ? 'rtl' : 'ltr';
				echo '<fieldset class="adc-nl-language-block" data-newsletter-language="' . $lang . '"><legend>' . esc_html( self::t( 'ar' === $lang ? 'النسخة العربية' : 'النسخة الإنجليزية', 'ar' === $lang ? 'Arabic version' : 'English version' ) ) . '</legend><div dir="' . $dir . '">';
				echo '<label for="adc-nl-subject-' . $lang . '">' . esc_html( self::t( 'عنوان الرسالة', 'Email subject' ) ) . '</label><input id="adc-nl-subject-' . $lang . '" name="subject_' . $lang . '" type="text" maxlength="190" value="' . esc_attr( $fields['subject_' . $lang] ) . '" placeholder="' . esc_attr( 'ar' === $lang ? 'عنوان الرسالة باللغة العربية' : 'Email subject in English' ) . '">';
				echo '<label for="adc_nl_body_' . $lang . '">' . esc_html( self::t( 'محتوى الرسالة', 'Email content' ) ) . '</label>';
				wp_editor( $fields['body_' . $lang], 'adc_nl_body_' . $lang, array( 'textarea_name'=>'body_' . $lang, 'textarea_rows'=>8, 'media_buttons'=>false, 'teeny'=>true, 'quicktags'=>true, 'tinymce'=>array( 'directionality'=>$dir ) ) );
				echo '<div class="adc-nl-field-row"><div><label for="adc-nl-button-' . $lang . '">' . esc_html( self::t( 'نص الزر (اختياري)', 'Button text (optional)' ) ) . '</label><input id="adc-nl-button-' . $lang . '" name="button_text_' . $lang . '" maxlength="100" value="' . esc_attr( $fields['button_text_' . $lang] ) . '"></div><div><label for="adc-nl-url-' . $lang . '">' . esc_html( self::t( 'رابط الزر', 'Button URL' ) ) . '</label><input id="adc-nl-url-' . $lang . '" name="button_url_' . $lang . '" type="url" dir="ltr" value="' . esc_attr( $fields['button_url_' . $lang] ) . '" placeholder="https://"></div></div></div></fieldset>';
			}
			echo '<div class="adc-nl-send"><p>' . esc_html( sprintf( self::t( 'ستُرسل الحملة إلى %s مشترك نشط، بنسخة عربية أو إنجليزية بحسب التفضيل المحفوظ.', 'This campaign will be sent to %s active subscribers in Arabic or English, according to their saved preference.' ), number_format_i18n( $active ) ) ) . '</p><p class="description">' . esc_html( self::t( 'أكمل عنوان ومحتوى النسختين قبل الإرسال. زر الإرسال يحفظ آخر تعديلاتك تلقائيًا.', 'Complete both subjects and messages before sending. Sending automatically saves your latest changes.' ) ) . '</p><div class="adc-nl-actions"><button class="button" name="operation" value="save" formnovalidate>' . esc_html( self::t( 'حفظ المسودة', 'Save draft' ) ) . '</button><button class="button button-primary" name="operation" value="send" data-newsletter-send data-active-subscribers="' . esc_attr( (string) $active ) . '" ' . disabled( $active, 0, false ) . '>' . esc_html( self::t( 'إرسال إلى جميع المشتركين', 'Send to all subscribers' ) ) . '</button></div></div></form>';
		} else {
			$stats = Newsletter::stats( $id );
			$variant = Newsletter::variant( $campaign, $language );
			echo '<h3>' . esc_html( $variant['subject'] ?: $campaign['subject'] ) . '</h3><p>' . esc_html( self::status( $campaign, $stats ) ) . '</p><div class="adc-nl-results">';
			foreach ( array( 'total'=>self::t( 'المستلمون', 'Recipients' ), 'sent'=>self::t( 'تم تسليمها لخادم البريد', 'Accepted by mail server' ), 'pending'=>self::t( 'بانتظار الإرسال', 'Pending' ), 'failed'=>self::t( 'تعذّر إرسالها', 'Failed' ), 'skipped'=>self::t( 'تم تجاوزها', 'Skipped' ) ) as $key=>$label ) { echo '<p><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $stats[$key] ) ) . '</strong></p>'; }
			echo '</div><p class="description">' . esc_html( self::t( 'قبول الرسالة من خادم البريد لا يضمن وصولها إلى صندوق الوارد. تُتجاوز الاشتراكات الملغاة قبل إرسال الرسالة.', 'Acceptance by the mail server does not guarantee inbox delivery. Cancelled subscriptions are skipped before sending.' ) ) . '</p><div class="adc-nl-actions">';
			if ( 'cancelled' !== $campaign['status'] && ! $campaign['archived_at'] ) {
				if ( $stats['pending'] ) { self::action( $id, 'process', self::t( 'إرسال الدفعة التالية الآن', 'Send the next batch now' ), 'button button-primary' ); self::action( $id, 'cancel', self::t( 'إيقاف الإرسال المتبقي', 'Cancel remaining sends' ) ); }
				if ( $stats['failed'] || ! $campaign['expanded'] ) { self::action( $id, 'retry', self::t( 'إعادة محاولة الرسائل المتعثرة', 'Retry failed emails' ) ); }
			}
			echo '<a class="button" href="' . esc_url( self::url( array( 'campaign'=>$id ) ) ) . '">' . esc_html( self::t( 'تحديث النتائج', 'Refresh results' ) ) . '</a></div>';
		}
		if ( $campaign ) { self::management( $campaign, Newsletter::stats( $id ) ); }
		echo '</section><section class="card adc-nl-panel adc-nl-preview"><h2>' . esc_html( self::t( 'معاينة مباشرة', 'Live preview' ) ) . '</h2><div class="adc-nl-preview-controls" role="group" aria-label="' . esc_attr( self::t( 'لغة المعاينة', 'Preview language' ) ) . '">';
		foreach ( array( 'ar'=>'العربية', 'en'=>'English' ) as $lang=>$label ) {
			if ( ! $draft && empty( $campaign['bilingual'] ) && $lang !== $preview_language ) { continue; }
			echo '<button class="button" type="button" data-preview-language="' . $lang . '" aria-pressed="' . ( $lang === $preview_language ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</div><p data-preview-status role="status" aria-live="polite">' . esc_html( self::t( 'تتحدث المعاينة تلقائيًا أثناء الكتابة.', 'The preview updates automatically as you type.' ) ) . '</p>';
		$html = $draft ? Newsletter::preview( $fields, $preview_language ) : Newsletter::html( $campaign, null, $preview_language );
		echo '<iframe title="' . esc_attr( self::t( 'معاينة الحملة البريدية', 'Campaign preview' ) ) . '" sandbox="" referrerpolicy="no-referrer" data-preview-frame data-live-preview="' . ( $draft ? '1' : '0' ) . '" srcdoc="' . esc_attr( $html ) . '"></iframe>';
		if ( ! $draft && ! empty( $campaign['bilingual'] ) ) {
			foreach ( array( 'ar', 'en' ) as $lang ) { echo '<template data-saved-preview="' . $lang . '">' . esc_html( Newsletter::html( $campaign, null, $lang ) ) . '</template>'; }
		}
		echo '</section></div>';
		if ( $campaign && ! $draft ) { self::delivery_list( $id ); }
	}

	private static function status( array $campaign, array $stats ): string {
		if ( $campaign['archived_at'] ) { return self::t( 'مؤرشفة', 'Archived' ); }
		if ( 'draft' === $campaign['status'] ) { return self::t( 'مسودة', 'Draft' ); }
		if ( 'cancelled' === $campaign['status'] ) { return self::t( 'متوقفة', 'Cancelled' ); }
		if ( $campaign['expanded'] && 0 === $stats['pending'] ) { return $stats['failed'] ? self::t( 'اكتملت مع رسائل متعثرة', 'Completed with failures' ) : self::t( 'اكتملت', 'Completed' ); }
		return self::t( 'جارٍ الإرسال', 'Sending' );
	}
	private static function campaigns(): void {
		global $wpdb;
		$view = isset($_GET['campaign_view']) && is_string($_GET['campaign_view']) ? sanitize_key($_GET['campaign_view']) : 'current';
		if (!in_array($view,array('current','archived','all'),true)) { $view='current'; }
		$where = 'archived'===$view ? ' WHERE archived_at IS NOT NULL' : ('current'===$view ? ' WHERE archived_at IS NULL' : '');
		$page=max(1,absint($_GET['campaign_page']??1));
		$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Newsletter::table('campaigns').$where);
		$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Newsletter::table('campaigns').$where.' ORDER BY id DESC LIMIT 10 OFFSET %d',($page-1)*10),ARRAY_A);
		echo '<section class="card adc-nl-panel"><h2>'.esc_html(self::t('سجل الحملات','Campaign history')).'</h2><nav class="adc-nl-campaign-tabs" aria-label="'.esc_attr(self::t('عرض الحملات','Campaign views')).'">';
		foreach(array('current'=>self::t('الحملات الحالية','Current campaigns'),'archived'=>self::t('الأرشيف','Archive'),'all'=>self::t('جميع الحملات','All campaigns')) as $key=>$label) {
			echo '<a class="button" href="'.esc_url(self::url(array('campaign_view'=>$key))).'" '.($view===$key?'aria-current="page"':'').'>'.esc_html($label).'</a>';
		}
		echo '</nav><div class="adc-nl-scroll adc-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach(array(self::t('الحملة','Campaign'),self::t('الحالة','Status'),self::t('المستلمون','Recipients'),self::t('قُبلت للإرسال','Accepted'),self::t('متعثر','Failed'),self::t('التاريخ','Created'),self::t('إجراءات الحملة','Campaign actions')) as $label) { echo '<th>'.esc_html($label).'</th>'; }
		echo '</tr></thead><tbody>';
		foreach($rows?:array() as $row) {
			$stats=Newsletter::stats((int)$row['id']);$variant=Newsletter::variant($row,\AutoDealership\Core\Localization::language());
			echo '<tr><td><a href="'.esc_url(self::url(array('campaign'=>$row['id'],'campaign_view'=>$view))).'"><strong>'.esc_html($variant['subject']?:$row['subject']).'</strong></a></td><td><span class="adc-status adc-nl-badge">'.esc_html(self::status($row,$stats)).'</span></td><td>'.esc_html((string)$stats['total']).'</td><td>'.esc_html((string)$stats['sent']).'</td><td>'.esc_html((string)$stats['failed']).'</td><td>'.esc_html(get_date_from_gmt($row['created_at'],'Y-m-d H:i')).'</td><td>';
			self::management($row,$stats);echo '</td></tr>';
		}
		if(!$rows){echo '<tr><td colspan="7">'.esc_html(self::t('archived'===$view?'لا توجد حملات مؤرشفة.':'لا توجد حملات في هذه القائمة.','archived'===$view?'No archived campaigns.':'No campaigns in this list.')).'</td></tr>';}
		echo '</tbody></table></div>';self::pagination('campaign_page',$page,$total,10);echo '</section>';
	}

	private static function subscriber_list(): void {
		global $wpdb;
		$search = isset( $_GET['search'] ) && is_string( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$status = isset( $_GET['subscriber_status'] ) && is_string( $_GET['subscriber_status'] ) ? sanitize_key( $_GET['subscriber_status'] ) : '';
		$page = max( 1, absint( $_GET['subscriber_page'] ?? 1 ) ); $where = ' WHERE 1=1';
		if ( $search ) { $where .= $wpdb->prepare( ' AND email LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ); }
		if ( in_array( $status, array( 'active', 'unsubscribed' ), true ) ) { $where .= $wpdb->prepare( ' AND status=%s', $status ); }
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::subscribers() . $where );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Newsletter::subscribers() . $where . ' ORDER BY id DESC LIMIT 25 OFFSET %d', ( $page - 1 ) * 25 ), ARRAY_A );
		echo '<section class="card adc-nl-panel"><h2>' . esc_html( self::t( 'إدارة المشتركين', 'Subscriber management' ) ) . '</h2><form method="get" class="adc-nl-filter"><input type="hidden" name="page" value="car-dealer-subscribers"><label class="screen-reader-text" for="adc-nl-search">' . esc_html( self::t( 'البحث بالبريد', 'Search by email' ) ) . '</label><input id="adc-nl-search" name="search" type="search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( self::t( 'البحث بعنوان البريد', 'Search email address' ) ) . '"><label class="screen-reader-text" for="adc-nl-status">' . esc_html( self::t( 'حالة الاشتراك', 'Subscription status' ) ) . '</label><select id="adc-nl-status" name="subscriber_status">';
		foreach ( array( '' => self::t( 'جميع الاشتراكات', 'All subscriptions' ), 'active' => self::t( 'نشط', 'Active' ), 'unsubscribed' => self::t( 'ملغي', 'Unsubscribed' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><button class="button">' . esc_html( self::t( 'بحث', 'Search' ) ) . '</button></form><div class="adc-nl-scroll adc-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( array( self::t( 'البريد الإلكتروني', 'Email address' ), self::t( 'لغة البريد المفضلة', 'Preferred email language' ), self::t( 'حالة الاشتراك', 'Status' ), self::t( 'تاريخ الاشتراك', 'Subscribed' ), self::t( 'إجراء', 'Action' ) ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows ?: array() as $row ) {
			echo '<tr><td dir="ltr">' . esc_html( $row['email'] ) . '</td><td>' . esc_html( 'en' === Newsletter::language( $row['locale'] ) ? self::t( 'الإنجليزية', 'English' ) : self::t( 'العربية', 'Arabic' ) ) . '</td><td><span class="adc-status adc-nl-badge">' . esc_html( 'active' === $row['status'] ? self::t( 'نشط', 'Active' ) : self::t( 'ملغي', 'Unsubscribed' ) ) . '</span></td><td>' . esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ) ) . '</td><td>';
			if ( Newsletter::can_manage() && 'active' === $row['status'] ) { self::form_start(); echo '<input type="hidden" name="subscriber" value="' . esc_attr( $row['id'] ) . '"><button class="button" name="operation" value="stop_subscriber">' . esc_html( self::t( 'إيقاف الاشتراك', 'Unsubscribe' ) ) . '</button></form>'; }
			else { echo '—'; } echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="5">' . esc_html( self::t( 'لا توجد اشتراكات مطابقة.', 'No matching subscribers.' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( 'subscriber_page', $page, $total, 25 ); echo '</section>';
	}
	private static function delivery_list( int $id ): void {
		global $wpdb; $page = max( 1, absint( $_GET['delivery_page'] ?? 1 ) ); $stats = Newsletter::stats( $id );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT d.status,d.sent_at,d.last_error,d.locale,s.email FROM ' . Newsletter::table( 'deliveries' ) . ' d LEFT JOIN ' . Newsletter::subscribers() . ' s ON s.id=d.subscriber_id WHERE d.campaign_id=%d ORDER BY d.id LIMIT 25 OFFSET %d', $id, ( $page - 1 ) * 25 ), ARRAY_A );
		echo '<section class="card adc-nl-panel"><h2>' . esc_html( self::t( 'نتائج الإرسال حسب المستلم', 'Results by recipient' ) ) . '</h2><div class="adc-nl-scroll adc-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html( self::t( 'البريد', 'Email' ) ) . '</th><th>' . esc_html( self::t( 'لغة الرسالة', 'Email language' ) ) . '</th><th>' . esc_html( self::t( 'النتيجة', 'Result' ) ) . '</th><th>' . esc_html( self::t( 'وقت الإرسال', 'Sent at' ) ) . '</th></tr></thead><tbody>';
		$labels = array( 'pending' => self::t( 'بانتظار الإرسال', 'Pending' ), 'sent' => self::t( 'قُبلت للإرسال', 'Accepted' ), 'failed' => self::t( 'تعذر الإرسال', 'Failed' ), 'skipped' => self::t( 'تم التجاوز', 'Skipped' ) );
		foreach ( $rows ?: array() as $row ) { echo '<tr><td dir="ltr">' . esc_html( $row['email'] ?: self::t( 'مشترك محذوف', 'Deleted subscriber' ) ) . '</td><td>' . esc_html( $row['locale'] ? ( 'en' === Newsletter::language( $row['locale'] ) ? self::t( 'الإنجليزية', 'English' ) : self::t( 'العربية', 'Arabic' ) ) : '—' ) . '</td><td>' . esc_html( $labels[$row['status']] ?? $row['status'] ) . '</td><td>' . esc_html( $row['sent_at'] ? get_date_from_gmt( $row['sent_at'], 'Y-m-d H:i' ) : '—' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( 'delivery_page', $page, $stats['total'], 25 ); echo '</section>';
	}
	private static function pagination( string $key, int $page, int $total, int $limit ): void {
		if ( $total <= $limit ) { return; }
		$base = remove_query_arg( array( 'notice', 'new' ) );
		echo '<div class="adc-nl-pagination">' . wp_kses_post( paginate_links( array( 'base' => str_replace( '999999999', '%#%', add_query_arg( $key, 999999999, $base ) ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $total / $limit ) ) ) ) . '</div>';
	}
}
