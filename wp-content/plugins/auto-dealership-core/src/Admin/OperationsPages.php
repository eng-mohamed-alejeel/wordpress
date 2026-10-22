<?php
namespace AutoDealership\Admin;

use AutoDealership\Branches\BranchService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Leads\LeadService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Capability-scoped staff screens over the same application services used by REST. */
final class OperationsPages {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'wp_ajax_adc_move_lead_stage', array( self::class, 'move_lead_stage_ajax' ) );
		add_action( 'admin_post_adc_export_leads', array( self::class, 'export_leads' ) );
		add_action( 'admin_post_adc_create_marketing_lead', array( self::class, 'create_marketing_lead' ) );
		add_action( 'admin_enqueue_scripts', static function(): void { if ( 'adc-crm' === ( $_GET['page'] ?? '' ) ) { wp_enqueue_style( 'adc-crm-admin', plugins_url( 'assets/css/crm-admin.css', ADC_FILE ), array( 'adc-admin' ), ADC_VERSION ); wp_enqueue_script( 'adc-crm-board', plugins_url( 'assets/js/crm-board.js', ADC_FILE ), array(), ADC_VERSION, true ); wp_localize_script( 'adc-crm-board', 'adcCrmBoard', array( 'ajaxUrl'=>admin_url( 'admin-ajax.php' ), 'nonce'=>wp_create_nonce( 'adc_crm_board' ) ) ); } } );
		add_action('admin_post_adc_inventory_map',array(InventoryWorkspacePage::class,'submit_mapping'));
		add_action( 'admin_post_adc_create_vehicle', array( self::class, 'create_vehicle' ) );
		add_action( 'admin_post_adc_transition_vehicle', array( self::class, 'transition_vehicle' ) );
		add_action( 'admin_post_adc_transfer_vehicle', array( self::class, 'transfer_vehicle' ) );
		add_action( 'admin_post_adc_move_vehicle_location', array( self::class, 'move_vehicle_location' ) );
		add_action( 'admin_post_adc_change_vehicle_vin', array( self::class, 'change_vehicle_vin' ) );
		add_action( 'admin_post_adc_update_lead_stage', array( self::class, 'update_lead_stage' ) );
		add_action( 'admin_post_adc_assign_lead', array( self::class, 'assign_lead' ) );
		add_action( 'admin_post_adc_add_activity', array( self::class, 'add_activity' ) );
	}

	public static function menu(): void {
		if ( current_user_can( 'adc_view_workspace' ) ) {
			add_menu_page( __( 'مساحة عمليات المعرض', 'auto-dealership-core' ), __( 'عمليات المعرض', 'auto-dealership-core' ), 'adc_view_workspace', 'adc-workspace', array( WorkspacePage::class, 'render' ), 'dashicons-car', 57 );
			add_submenu_page( 'adc-workspace', __( 'مساحة العمل', 'auto-dealership-core' ), __( 'مساحة العمل', 'auto-dealership-core' ), 'adc_view_workspace', 'adc-workspace', array( WorkspacePage::class, 'render' ) );
		}
		if ( current_user_can( 'adc_view_inventory' ) ) {
			add_submenu_page( 'adc-workspace', __( 'إدارة السيارات', 'auto-dealership-core' ), __( 'إدارة السيارات', 'auto-dealership-core' ), 'adc_view_inventory', 'adc-inventory', array( self::class, 'render' ) );
		}
		if ( current_user_can( 'adc_transfer_inventory' ) ) {
			add_submenu_page( 'adc-workspace', __( 'Branch Transfers', 'auto-dealership-core' ), __( 'Branch Transfers', 'auto-dealership-core' ), 'adc_transfer_inventory', 'adc-transfers', array( self::class, 'render_transfers' ) );
		}
		if ( current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'adc_view_marketing_leads' ) ) {
			add_submenu_page( 'adc-workspace', __( 'CRM Leads', 'auto-dealership-core' ), __( 'CRM Leads', 'auto-dealership-core' ), current_user_can( 'adc_view_branch_leads' ) ? 'adc_view_branch_leads' : 'adc_view_own_leads', 'adc-crm', array( self::class, 'render_leads' ) );
		}
		if ( current_user_can( 'adc_view_campaign_metrics' ) || current_user_can( 'manage_options' ) ) { add_submenu_page( 'adc-workspace', __( 'Marketing reports', 'auto-dealership-core' ), __( 'Marketing reports', 'auto-dealership-core' ), 'adc_view_campaign_metrics', 'adc-marketing-reports', array( self::class, 'render_marketing_reports' ) ); }
	}

	public static function render(): void {
		if(empty($_GET['new'])&&empty($_GET['legacy'])){InventoryWorkspacePage::render();return;}
		$source=null;if(!empty($_GET['source_id'])){$source=\AutoDealership\Inventory\InventoryWorkspace::source(absint($_GET['source_id']));if(is_wp_error($source)||!$source['present']||$source['vehicle_id']||!current_user_can('adc_manage_inventory')){wp_die('Source unavailable','',array('response'=>403));}}
		ob_start();self::render_operational();$html=ob_get_clean();
		if($source){$html=str_replace('إضافة سيارة إلى المخزون','استكمال سيارة من أونكس',$html);$values=array('onyx_identifier'=>$source['source_identifier'],'brand'=>$source['brand'],'model'=>$source['model'],'trim'=>$source['trim_name'],'model_year'=>(string)$source['model_year'],'stock_number'=>'ONYX-'.$source['source_identifier'],'retail_price'=>\AutoDealership\Pricing\Money::decimal((int)$source['listed_price']),'branch_id'=>(string)$source['mapped_branch_id']);
			foreach($values as$name=>$value){if($name==='branch_id'){continue;}$html=preg_replace_callback('/(<input[^>]*name="'.preg_quote($name,'/').'"[^>]*)(>)/',static fn($match)=>$match[1].' value="'.esc_attr($value).'"'.(in_array($name,array('brand','model','trim','model_year','retail_price','onyx_identifier'),true)?' readonly':'').$match[2],$html,1);}
			echo $html;echo '<script>document.addEventListener("DOMContentLoaded",()=>{const form=document.querySelector("input[value=adc_create_vehicle]").form;const branch='.wp_json_encode($values['branch_id']).';if(branch!=="0"){form.elements.branch_id.value=branch;}form.elements.onyx_identifier.dispatchEvent(new Event("blur"));});</script>';
		}else{echo $html;}
	}
	private static function render_operational(): void {
		if ( ! current_user_can( 'adc_view_inventory' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض المخزون.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$vehicle_page=VehicleService::page_for_current_user(max(1,absint($_GET['paged']??1)));$vehicles=is_wp_error($vehicle_page)?array():$vehicle_page['rows'];
		$branches = current_user_can( 'manage_options' ) ? BranchService::public_list() : array();
		$own_branch = BranchScope::assigned_branch();
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php echo esc_html('en'===\AutoDealership\Core\Localization::language()?'Operational records':'السجلات التشغيلية'); ?></h1>
			<?php self::notice(); ?><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=adc-inventory')); ?>"><?php echo esc_html('en'===\AutoDealership\Core\Localization::language()?'Back to vehicle management':'العودة إلى إدارة السيارات'); ?></a></p>
			<?php if ( current_user_can( 'adc_manage_inventory' ) ) : ?><h2><?php esc_html_e( 'إضافة سيارة إلى المخزون', 'auto-dealership-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
				<input type="hidden" name="action" value="adc_create_vehicle"><input type="hidden" name="adc_money_unit" value="SAR"><?php wp_nonce_field( 'adc_create_vehicle' ); ?>
				<p><label><?php echo esc_html__( 'VIN', 'auto-dealership-core' ); ?> <input name="vin" required minlength="17" maxlength="17"></label> <label><?php esc_html_e( 'رقم المخزون', 'auto-dealership-core' ); ?> <input name="stock_number" required></label></p>
				<p><label><?php echo esc_html( 'en' === \AutoDealership\Core\Localization::language() ? 'Onyx chassis identifier (optional)' : 'معرّف الهيكل المختصر في أونكس (اختياري)' ); ?> <input name="onyx_identifier" maxlength="32" autocomplete="off"></label></p><p data-vin-status role="status" aria-live="polite"></p><input type="hidden" name="onyx_stock_id" value=""><label data-onyx-link hidden><input type="checkbox" name="onyx_link_confirmed" value="1"><?php echo esc_html( 'en' === \AutoDealership\Core\Localization::language() ? 'Complete and link the existing Onyx vehicle' : 'استكمال بيانات السيارة الموجودة في أونكس وربطها' ); ?></label>
				<p><label><?php esc_html_e( 'الماركة', 'auto-dealership-core' ); ?> <input name="brand" required></label> <label><?php esc_html_e( 'الموديل', 'auto-dealership-core' ); ?> <input name="model" required></label> <label><?php esc_html_e( 'الفئة', 'auto-dealership-core' ); ?> <input name="trim"></label></p>
				<p><label><?php esc_html_e( 'السنة', 'auto-dealership-core' ); ?> <input name="model_year" type="number" min="1900" required></label> <label><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?> <select name="condition"><option value="new"><?php echo esc_html__( 'جديدة', 'auto-dealership-core' ); ?></option><option value="used"><?php echo esc_html__( 'مستعملة', 'auto-dealership-core' ); ?></option></select></label> <label><?php esc_html_e( 'السعر بالريال', 'auto-dealership-core' ); ?> <input step="0.01" name="retail_price" type="number" min="0.01" required></label>
				<?php if ( current_user_can( 'manage_options' ) ) : ?><label><?php esc_html_e( 'الفرع', 'auto-dealership-core' ); ?> <select name="branch_id" required><?php foreach ( $branches as $branch ) : ?><option value="<?php echo absint( $branch['id'] ); ?>"><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) ); ?></option><?php endforeach; ?></select></label><?php else : ?><input type="hidden" name="branch_id" value="<?php echo absint( $own_branch ); ?>"><?php endif; ?></p>
				<?php submit_button( __( 'إضافة السيارة', 'auto-dealership-core' ), 'primary', 'submit', false ); ?>
			</form><?php endif; ?>
			<?php if(empty($_GET['new'])): ?><h2><?php esc_html_e( 'السيارات', 'auto-dealership-core' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'VIN', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المخزون', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'السنة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'السعر (ريال)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'تغيير الحالة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $vehicles as $vehicle ) : ?><tr><td><?php echo absint( $vehicle['id'] ); ?></td><td><code><?php echo esc_html( $vehicle['vin'] ); ?></code></td><td><?php echo esc_html( $vehicle['stock_number'] ); ?></td><td><?php echo esc_html( trim( \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'brand', (string) $vehicle['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'model', (string) $vehicle['model'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'trim_name', (string) $vehicle['trim_name'] ) ) ); ?></td><td><?php echo absint( $vehicle['model_year'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string ) $vehicle['status'] ) ); ?></td><td><?php echo esc_html( \AutoDealership\Pricing\Money::display( $vehicle['retail_price'] ) ); ?></td><td><?php if ( current_user_can( 'adc_manage_inventory' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_transition_vehicle"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_transition_vehicle_' . (int) $vehicle['id'] ); ?><select name="status"><option value="inspection"><?php echo esc_html__( 'inspection', 'auto-dealership-core' ); ?></option><option value="available"><?php echo esc_html__( 'available', 'auto-dealership-core' ); ?></option><option value="hold"><?php echo esc_html__( 'hold', 'auto-dealership-core' ); ?></option><option value="maintenance"><?php echo esc_html__( 'maintenance', 'auto-dealership-core' ); ?></option></select><input name="reason" placeholder="<?php esc_attr_e( 'سبب التغيير', 'auto-dealership-core' ); ?>" required><button class="button"><?php esc_html_e( 'حفظ', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $vehicles ) : ?><tr><td colspan="8"><?php InventoryListState::render($vehicle_page); ?></td></tr><?php endif; ?>
			</tbody></table><?php InventoryListState::pagination($vehicle_page,'adc-inventory');endif; ?>
		</div>
		<?php
	}

	public static function render_transfers(): void {
		if ( ! current_user_can( 'adc_transfer_inventory' ) ) {
			wp_die( esc_html__( 'You are not allowed to transfer inventory.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$vehicle_page=VehicleService::page_for_current_user(max(1,absint($_GET['paged']??1)),'available');$vehicles=is_wp_error($vehicle_page)?array():$vehicle_page['rows'];
		$branches = BranchService::public_list();
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php echo esc_html( \AutoDealership\Core\ProductSetup::t( 'نقل المركبات بين الفروع', 'Vehicle transfers between branches' ) ); ?></h1><?php TransferPages::tabs( 'new' ); self::notice(); ?>
			<p><?php esc_html_e( 'Submitting a request places the vehicle on transfer hold until the destination branch decision and receipt.', 'auto-dealership-core' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'VIN', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Vehicle', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Current status', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Destination branch', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $vehicles as $vehicle ) : ?><tr><td><?php echo absint( $vehicle['id'] ); ?></td><td><code><?php echo esc_html( $vehicle['vin'] ); ?></code></td><td><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'brand', (string) $vehicle['brand'] ) . ' ' . \AutoDealership\Content\StoredTranslations::text( 'vehicles', (int) $vehicle['id'], 'model', (string) $vehicle['model'] ) . ' (' . $vehicle['stock_number'] . ' )' ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string ) $vehicle['status'] ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_transfer_vehicle"><input type="hidden" name="id" value="<?php echo absint( $vehicle['id'] ); ?>"><?php wp_nonce_field( 'adc_transfer_vehicle_' . (int) $vehicle['id'] ); ?><select name="target_branch_id" required><option value=""><?php esc_html_e( 'Select branch', 'auto-dealership-core' ); ?></option><?php foreach ( $branches as $branch ) : if ( (int) $branch['id'] === (int) $vehicle['branch_id'] ) { continue; } ?><option value="<?php echo absint( $branch['id'] ); ?>"><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'name', (string) $branch['name'] ) . ' — ' . \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $branch['id'], 'city', (string) $branch['city'] ) ); ?></option><?php endforeach; ?></select><input name="reason" required maxlength="500" placeholder="<?php esc_attr_e( 'Transfer reason', 'auto-dealership-core' ); ?>"><button class="button button-primary"><?php esc_html_e( 'Transfer', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?>
			<?php if ( ! $vehicles ) : ?><tr><td colspan="5"><?php InventoryListState::render($vehicle_page,'available'); ?></td></tr><?php endif; ?></tbody></table><?php InventoryListState::pagination($vehicle_page,'adc-transfers','new'); ?>
		</div>
		<?php
	}

	public static function render_leads(): void {
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض الفرص.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$filters = array( 'search' => $_GET['s'] ?? '', 'stage' => $_GET['stage'] ?? '', 'source' => $_GET['source'] ?? '', 'campaign' => $_GET['campaign'] ?? '', 'platform' => $_GET['platform'] ?? '', 'created_by' => absint( $_GET['created_by'] ?? 0 ), 'owner' => absint( $_GET['owner'] ?? 0 ), 'overdue' => ! empty( $_GET['overdue'] ) );
		$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) ); $per_page = 25;
		$total_leads = LeadService::count_for_current_user( $filters );
		$total_pages = max( 1, (int) ceil( $total_leads / $per_page ) );
		$current_page = min( $current_page, $total_pages );
		$leads = LeadService::list_for_current_user( $current_page, $per_page, $filters );
		$board_leads = LeadService::list_for_current_user( 1, 100, $filters );
		$summary = LeadService::pipeline_summary();
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'فرص العملاء CRM', 'auto-dealership-core' ); ?></h1><?php self::notice(); ?>
			<?php self::render_marketing_intake_v2(); ?>
			<?php self::render_marketing_filters(); ?>
			<?php if ( current_user_can( 'adc_create_marketing_leads' ) ) : ?><details class="card adc-marketing-intake"><summary><?php esc_html_e( 'إضافة فرصة من فريق التسويق', 'auto-dealership-core' ); ?></summary><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_create_marketing_lead"><?php wp_nonce_field( 'adc_create_marketing_lead' ); ?><p><label><?php esc_html_e( 'اسم العميل', 'auto-dealership-core' ); ?> <input name="name" required></label> <label><?php esc_html_e( 'الجوال', 'auto-dealership-core' ); ?> <input name="mobile" required></label> <label><?php esc_html_e( 'البريد', 'auto-dealership-core' ); ?> <input type="email" name="email"></label></p><p><label><?php esc_html_e( 'المدينة', 'auto-dealership-core' ); ?> <input name="city"></label> <label><?php esc_html_e( 'الحملة', 'auto-dealership-core' ); ?> <input name="campaign" required></label> <label><?php esc_html_e( 'المنصة', 'auto-dealership-core' ); ?> <input name="platform" required placeholder="Meta / Google / TikTok"></label> <label><?php esc_html_e( 'المصدر', 'auto-dealership-core' ); ?> <select name="source"><option value="marketing">Marketing</option><option value="whatsapp">WhatsApp</option><option value="phone">Phone</option><option value="walk_in">Walk-in</option></select></label></p><p><label><?php esc_html_e( 'رابط الإعلان', 'auto-dealership-core' ); ?> <input type="url" name="ad_url"></label></p><p><label><?php esc_html_e( 'ملاحظات', 'auto-dealership-core' ); ?> <textarea name="notes" rows="2"></textarea></label></p><p><label><input type="checkbox" name="consent_marketing" value="1" required> <?php esc_html_e( 'تم الحصول على موافقة العميل للتواصل', 'auto-dealership-core' ); ?></label></p><button class="button button-primary"><?php esc_html_e( 'إنشاء الفرصة', 'auto-dealership-core' ); ?></button></form></details><?php endif; ?>
			<div class="adc-crm-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0"><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></strong><br><?php esc_html_e( 'إجمالي الفرص', 'auto-dealership-core' ); ?></div><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['new'] ) ); ?></strong><br><?php esc_html_e( 'فرص جديدة', 'auto-dealership-core' ); ?></div><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['overdue'] ) ); ?></strong><br><?php esc_html_e( 'متابعات متأخرة', 'auto-dealership-core' ); ?></div><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['unassigned'] ) ); ?></strong><br><?php esc_html_e( 'غير مسندة', 'auto-dealership-core' ); ?></div></div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tablenav top"><input type="hidden" name="page" value="adc-crm"><input type="search" name="s" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ); ?>" placeholder="<?php esc_attr_e( 'بحث بالاسم أو الجوال أو البريد أو الرقم', 'auto-dealership-core' ); ?>"><select name="stage"><option value=""><?php esc_html_e( 'كل المراحل', 'auto-dealership-core' ); ?></option><?php foreach ( array( 'new','contacted','qualified','quotation','finance','negotiation','reserved','won','lost' ) as $filter_stage ) : ?><option value="<?php echo esc_attr( $filter_stage ); ?>" <?php selected( sanitize_key( $_GET['stage'] ?? '' ), $filter_stage ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( $filter_stage ) ); ?></option><?php endforeach; ?></select><select name="source"><option value=""><?php esc_html_e( 'كل المصادر', 'auto-dealership-core' ); ?></option><?php foreach ( array( 'website','whatsapp','phone','walk_in','instagram','tiktok','snapchat','google','referral','campaign','other' ) as $filter_source ) : ?><option value="<?php echo esc_attr( $filter_source ); ?>" <?php selected( sanitize_key( $_GET['source'] ?? '' ), $filter_source ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( $filter_source ) ); ?></option><?php endforeach; ?></select><label><input type="checkbox" name="overdue" value="1" <?php checked( ! empty( $_GET['overdue'] ) ); ?>><?php esc_html_e( 'المتابعات المتأخرة', 'auto-dealership-core' ); ?></label><button class="button"><?php esc_html_e( 'تصفية', 'auto-dealership-core' ); ?></button></form>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="adc-crm">
				<input type="hidden" name="paged" value="<?php echo max( 1, absint( $_GET['paged'] ?? 1 ) ); ?>">
				<label for="adc-history-lead"><?php esc_html_e( 'سجل متابعة الفرصة', 'auto-dealership-core' ); ?></label>
				<select id="adc-history-lead" name="lead_id" required><option value=""><?php esc_html_e( 'اختر فرصة', 'auto-dealership-core' ); ?></option>
				<?php foreach ( $leads as $item ) : ?><option value="<?php echo absint( $item['id'] ); ?>" <?php selected( absint( $_GET['lead_id'] ?? 0 ), (int) $item['id'] ); ?>><?php echo esc_html( '#' . $item['id'] . ' — ' . $item['full_name'] ); ?></option><?php endforeach; ?>
				</select><button class="button"><?php esc_html_e( 'عرض السجل', 'auto-dealership-core' ); ?></button>
			</form>
			<?php self::render_activity_history(); ?>
			<?php if ( ! absint( $_GET['lead_id'] ?? 0 ) ) : $export_url = wp_nonce_url( add_query_arg( array( 'action'=>'adc_export_leads', 's'=>sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), 'stage'=>sanitize_key( $_GET['stage'] ?? '' ), 'source'=>sanitize_key( $_GET['source'] ?? '' ), 'overdue'=>! empty( $_GET['overdue'] ) ? 1 : null ), admin_url( 'admin-post.php' ) ), 'adc_export_leads' ); ?><p><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'تصدير النتائج CSV', 'auto-dealership-core' ); ?></a></p><?php endif; ?>
			<?php if ( ! absint( $_GET['lead_id'] ?? 0 ) ) : ?><section class="adc-crm-board" aria-label="<?php esc_attr_e( 'لوحة مراحل الفرص', 'auto-dealership-core' ); ?>"><?php foreach ( array( 'new','contacted','qualified','quotation','finance','negotiation','reserved','won','lost' ) as $board_stage ) : ?><div class="adc-crm-board-column" data-lead-stage="<?php echo esc_attr( $board_stage ); ?>"><h2><?php echo esc_html( \AutoDealership\Core\Localization::label( $board_stage ) ); ?></h2><?php foreach ( $board_leads as $board_lead ) : if ( $board_stage !== $board_lead['stage'] ) { continue; } ?><article class="adc-crm-board-card" draggable="true" data-lead-card data-lead-id="<?php echo absint( $board_lead['id'] ); ?>" data-stage="<?php echo esc_attr( $board_lead['stage'] ); ?>" data-allowed="<?php echo esc_attr( wp_json_encode( LeadService::allowed_stage_transitions( (string) $board_lead['stage'] ) ) ); ?>"><strong><?php echo esc_html( $board_lead['full_name'] ); ?></strong><span>#<?php echo absint( $board_lead['id'] ); ?></span><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'adc-crm','lead_id'=>(int) $board_lead['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'التفاصيل', 'auto-dealership-core' ); ?></a></article><?php endforeach; ?></div><?php endforeach; ?></section><?php endif; ?>
			<?php if ( ! empty( $_GET['owner'] ) ) : ?><p class="description"><?php echo esc_html( sprintf( __( 'تصفية حسب المسؤول رقم %d', 'auto-dealership-core' ), absint( $_GET['owner'] ) ) ); ?></p><?php endif; ?>
			<?php if ( absint( $_GET['lead_id'] ?? 0 ) ) : ?><p><a class="button" href="<?php echo esc_url( remove_query_arg( array( 'lead_id', 'activity_page' ) ) ); ?>"><?php esc_html_e( 'العودة إلى قائمة الفرص', 'auto-dealership-core' ); ?></a></p></div><?php return; endif; ?>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'العميل', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الجوال', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'البريد', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المصدر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المرحلة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'إضافة نشاط', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $leads as $lead ) : ?><tr><td><?php echo absint( $lead['id'] ); ?></td><td><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>(int) $lead['customer_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $lead['full_name'] ); ?></a></td><td><?php echo esc_html( $lead['mobile'] ); ?></td><td><?php echo esc_html( $lead['email'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $lead['source'] ) ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $lead['stage'] ) ); ?><?php if ( current_user_can( 'adc_manage_branch_leads' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_assign_lead"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_assign_lead_' . (int) $lead['id'] ); ?><select name="staff_id" aria-label="<?php esc_attr_e( 'إسناد إلى', 'auto-dealership-core' ); ?>" required><option value="0"><?php esc_html_e( 'إسناد إلى...', 'auto-dealership-core' ); ?></option><?php foreach ( self::sales_staff( (int) $lead['branch_id'] ) as $staff ) : ?><option value="<?php echo absint( $staff->ID ); ?>" <?php selected( (int) $lead['owner_user_id'], (int) $staff->ID ); ?>><?php echo esc_html( $staff->display_name ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'إسناد', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_update_lead_stage"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_update_lead_stage_' . (int) $lead['id'] ); ?><select name="stage" aria-label="<?php esc_attr_e( 'المرحلة', 'auto-dealership-core' ); ?>"><?php foreach ( array( 'contacted', 'qualified', 'quotation', 'finance', 'negotiation', 'reserved', 'won', 'lost' ) as $stage ) : ?><option value="<?php echo esc_attr( $stage ); ?>"><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $stage ) ); ?></option><?php endforeach; ?></select><input name="reason" aria-label="<?php esc_attr_e( 'سبب الخسارة عند الحاجة', 'auto-dealership-core' ); ?>" placeholder="<?php esc_attr_e( 'سبب الخسارة عند الحاجة', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></button></form></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_add_activity"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_add_activity_' . (int) $lead['id'] ); ?><select name="type" aria-label="<?php esc_attr_e( 'نوع النشاط', 'auto-dealership-core' ); ?>"><option value="call"><?php echo esc_html__( 'call', 'auto-dealership-core' ); ?></option><option value="whatsapp"><?php echo esc_html__( 'WhatsApp', 'auto-dealership-core' ); ?></option><option value="meeting"><?php echo esc_html__( 'meeting', 'auto-dealership-core' ); ?></option><option value="follow_up"><?php echo esc_html__( 'follow-up', 'auto-dealership-core' ); ?></option><option value="note"><?php echo esc_html__( 'note', 'auto-dealership-core' ); ?></option></select><input name="notes" aria-label="<?php esc_attr_e( 'ملاحظات النشاط', 'auto-dealership-core' ); ?>" required><label><?php echo esc_html__( 'Follow-up (UTC)', 'auto-dealership-core' ); ?> <input name="next_action_at" type="datetime-local"></label><button class="button"><?php esc_html_e( 'إضافة', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?>
			<?php if ( ! $leads ) : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد فرص في نطاق صلاحيتك.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<?php if ( $total_pages > 1 ) : ?><nav class="tablenav"><span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s فرصة', '%s فرص', $total_leads, 'auto-dealership-core' ), number_format_i18n( $total_leads ) ) ); ?></span><?php for ( $page_number = 1; $page_number <= $total_pages; $page_number++ ) : $page_url = add_query_arg( array( 'page'=>'adc-crm', 'paged'=>$page_number, 's'=>sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), 'stage'=>sanitize_key( $_GET['stage'] ?? '' ), 'overdue'=>! empty( $_GET['overdue'] ) ? 1 : null ), admin_url( 'admin.php' ) ); ?><a class="button <?php echo $page_number === $current_page ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $page_url ); ?>"><?php echo absint( $page_number ); ?></a> <?php endfor; ?></nav><?php endif; ?>
		</div>
		<?php
	}

	private static function render_marketing_intake_v2(): void {
		if ( ! current_user_can( 'adc_create_marketing_leads' ) ) { return; }
		echo '<details class="card adc-marketing-intake-v2"><summary>' . esc_html__( 'إضافة فرصة من فريق التسويق', 'auto-dealership-core' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_create_marketing_lead">';
		wp_nonce_field( 'adc_create_marketing_lead' );
		echo '<p><label>' . esc_html__( 'اسم العميل', 'auto-dealership-core' ) . ' <input name="name" required></label> <label>' . esc_html__( 'الجوال', 'auto-dealership-core' ) . ' <input name="mobile" required></label> <label>' . esc_html__( 'البريد', 'auto-dealership-core' ) . ' <input type="email" name="email"></label></p>';
		echo '<p><label>' . esc_html__( 'الفرع', 'auto-dealership-core' ) . ' <select name="branch_id"><option value="0">' . esc_html__( 'الفرع الافتراضي', 'auto-dealership-core' ) . '</option>';
		foreach ( BranchService::public_list() as $branch ) { echo '<option value="' . absint( $branch['id'] ) . '">' . esc_html( $branch['name'] ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . ' <input name="campaign" required></label> <label>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . ' <input name="platform" required></label></p>';
		echo '<p><label>' . esc_html__( 'المصدر', 'auto-dealership-core' ) . ' <select name="source"><option value="marketing">Marketing</option><option value="whatsapp">WhatsApp</option><option value="phone">Phone</option><option value="walk_in">Walk-in</option></select></label> <label>' . esc_html__( 'موظف المبيعات', 'auto-dealership-core' ) . ' <select name="staff_id"><option value="0">' . esc_html__( 'إسناد لاحقًا', 'auto-dealership-core' ) . '</option>';
		foreach ( get_users( array( 'role'=>'dealership_sales', 'number'=>200, 'fields'=>array( 'ID','display_name' ) ) ) as $staff ) { echo '<option data-branch="' . absint( \AutoDealership\Security\BranchScope::assigned_branch( (int) $staff->ID ) ) . '" value="' . absint( $staff->ID ) . '">' . esc_html( $staff->display_name ) . '</option>'; }
		echo '</select></label></p><p><label>' . esc_html__( 'رابط الإعلان', 'auto-dealership-core' ) . ' <input type="url" name="ad_url"></label> <label>' . esc_html__( 'ملاحظات', 'auto-dealership-core' ) . ' <textarea name="notes" rows="2"></textarea></label></p><p><label><input type="checkbox" name="consent_marketing" value="1" required> ' . esc_html__( 'تم الحصول على موافقة العميل للتواصل', 'auto-dealership-core' ) . '</label></p><button class="button button-primary">' . esc_html__( 'إنشاء الفرصة', 'auto-dealership-core' ) . '</button></form></details><script>(function(){var b=document.querySelector(\".adc-marketing-intake-v2 select[name=branch_id]\"),s=document.querySelector(\".adc-marketing-intake-v2 select[name=staff_id]\");if(!b||!s)return;function f(){Array.prototype.forEach.call(s.options,function(o){if(!o.value)return;o.hidden=b.value!==\"0\"&&o.dataset.branch!==b.value;});if(s.selectedOptions[0]&&s.selectedOptions[0].hidden)s.value=\"0\";}b.addEventListener(\"change\",f);f();})();</script>';
	}

	private static function render_marketing_filters(): void {
		if ( ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) { return; }
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="adc-marketing-filters"><input type="hidden" name="page" value="adc-crm"><label>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . ' <input name="campaign" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['campaign'] ?? '' ) ) ) . '"></label> <label>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . ' <input name="platform" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['platform'] ?? '' ) ) ) . '"></label> <label>' . esc_html__( 'منشئ الفرصة', 'auto-dealership-core' ) . ' <input type="number" min="1" name="created_by" value="' . absint( $_GET['created_by'] ?? 0 ) . '"></label> <button class="button">' . esc_html__( 'تصفية التسويق', 'auto-dealership-core' ) . '</button></form>';
	}

	public static function render_marketing_reports(): void {
		if ( ! current_user_can( 'adc_view_campaign_metrics' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض تقارير التسويق.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		global $wpdb; $table = \AutoDealership\Database\Schema::table( 'leads' ); list( $scope, $args ) = \AutoDealership\Security\BranchScope::predicate( 'l.branch_id' );
		$where = ' WHERE ' . $scope . ' AND l.campaign<>""'; $period = sanitize_key( $_GET['period'] ?? '' ); if ( 'today' === $period ) { $where .= ' AND l.created_at>=%s'; $args[] = gmdate( 'Y-m-d 00:00:00' ); } elseif ( '7days' === $period ) { $where .= ' AND l.created_at>=%s'; $args[] = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ); } elseif ( 'month' === $period ) { $where .= ' AND l.created_at>=%s'; $args[] = gmdate( 'Y-m-01 00:00:00' ); } $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.campaign,l.platform,l.stage,COUNT(*) total FROM ' . $table . ' l' . $where . ' GROUP BY l.campaign,l.platform,l.stage ORDER BY total DESC', $args ), ARRAY_A ) ?: array();
		$summary = array( 'total'=>0, 'new'=>0, 'contacted'=>0, 'qualified'=>0, 'reserved'=>0, 'won'=>0, 'lost'=>0, 'overdue'=>0 );
		$activity_table = \AutoDealership\Database\Schema::table( 'activities' );
		$avg_first_followup = $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(TIMESTAMPDIFF(SECOND,l.created_at,a.first_activity)) FROM ' . $table . ' l INNER JOIN (SELECT lead_id,MIN(created_at) first_activity FROM ' . $activity_table . ' GROUP BY lead_id) a ON a.lead_id=l.id' . $where, $args ) );
		$staff_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.owner_user_id,COUNT(*) total,SUM(l.stage="contacted") contacted,SUM(l.stage="qualified") qualified,SUM(l.stage="reserved") reserved,SUM(l.stage="won") won,SUM(l.stage="lost") lost FROM ' . $table . ' l' . $where . ' GROUP BY l.owner_user_id ORDER BY total DESC', $args ), ARRAY_A ) ?: array();
		self::render_report_filters( $period );
		echo '<h2>' . esc_html__( 'أداء موظفي المبيعات', 'auto-dealership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'الموظف', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الإجمالي', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'تم التواصل', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'مؤهلة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'محجوزة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'مباعة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'خاسرة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $staff_rows as $staff_row ) { $staff = $staff_row['owner_user_id'] ? get_userdata( (int) $staff_row['owner_user_id'] ) : null; echo '<tr><td>' . esc_html( $staff ? $staff->display_name : __( 'غير مسندة', 'auto-dealership-core' ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $staff_row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $staff_row['contacted'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $staff_row['qualified'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $staff_row['reserved'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $staff_row['won'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $staff_row['lost'] ) ) . '</td></tr>'; }
		if ( ! $staff_rows ) { echo '<tr><td colspan="7">' . esc_html__( 'لا توجد بيانات موظفين ضمن الفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( __( 'متوسط زمن أول متابعة: %s ساعة', 'auto-dealership-core' ), $avg_first_followup ? number_format_i18n( (float) $avg_first_followup / HOUR_IN_SECONDS, 1 ) : '0' ) ) . '</p>';
		foreach ( $rows as $row ) { $count = (int) $row['total']; $summary['total'] += $count; if ( isset( $summary[ $row['stage'] ] ) ) { $summary[ $row['stage'] ] += $count; } }
		$overdue = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' l' . $where . " AND l.next_action_at IS NOT NULL AND l.next_action_at<=%s AND l.stage NOT IN ('won','lost')", array_merge( $args, array( current_time( 'mysql', true ) ) ) ) ); $summary['overdue'] = (int) $overdue;
		echo '<div class="wrap"><h1>' . esc_html__( 'تقارير التسويق', 'auto-dealership-core' ) . '</h1><p>' . esc_html__( 'توزيع الفرص التسويقية حسب الحملة والمنصة والمرحلة ضمن نطاق صلاحيتك.', 'auto-dealership-core' ) . '</p><div class="adc-crm-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:16px 0">';
		foreach ( array( 'total'=>'الإجمالي','new'=>'جديدة','contacted'=>'تم التواصل','qualified'=>'مؤهلة','reserved'=>'محجوزة','won'=>'مباعة','lost'=>'خاسرة','overdue'=>'متأخرة' ) as $key=>$label ) { echo '<div class="card"><strong>' . esc_html( number_format_i18n( $summary[ $key ] ) ) . '</strong><br>' . esc_html__( $label, 'auto-dealership-core' ) . '</div>'; }
		$conversion = static function ( int $value, int $base ): string { return $base > 0 ? number_format_i18n( ( $value / $base ) * 100, 1 ) . '%' : '0%'; };
		echo '</div><div class="card"><h2>' . esc_html__( 'نسب التحويل', 'auto-dealership-core' ) . '</h2><p>' . esc_html( sprintf( __( 'التواصل من الإجمالي: %s — التأهيل من التواصل: %s — البيع من الإجمالي: %s — الخسارة من الإجمالي: %s', 'auto-dealership-core' ), $conversion( $summary['contacted'], $summary['total'] ), $conversion( $summary['qualified'], $summary['contacted'] ), $conversion( $summary['won'], $summary['total'] ), $conversion( $summary['lost'], $summary['total'] ) ) ) . '</p></div><table class="widefat striped"><thead><tr><th>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المرحلة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'العدد', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['campaign'] ) . '</td><td>' . esc_html( $row['platform'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( $row['stage'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['total'] ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="4">' . esc_html__( 'لا توجد بيانات تسويقية بعد.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}

	private static function render_report_filters( string $period ): void {
		echo '<form method="get" class="adc-marketing-filters"><input type="hidden" name="page" value="adc-marketing-reports"><label>' . esc_html__( 'الفترة', 'auto-dealership-core' ) . ' <select name="period"><option value="">كل الفترات</option><option value="today" ' . selected( $period, 'today', false ) . '>اليوم</option><option value="7days" ' . selected( $period, '7days', false ) . '>آخر 7 أيام</option><option value="month" ' . selected( $period, 'month', false ) . '>هذا الشهر</option></select></label><button class="button">' . esc_html__( 'تطبيق', 'auto-dealership-core' ) . '</button></form>';
	}

	private static function render_activity_history(): void {
		$lead_id = absint( $_GET['lead_id'] ?? 0 );
		if ( ! $lead_id ) { return; }
		$page = max( 1, absint( $_GET['activity_page'] ?? 1 ) );
		$items = LeadService::activity_history( $lead_id, $page, 20 );
		if ( is_wp_error( $items ) ) { echo '<p role="alert">' . esc_html( $items->get_error_message() ) . '</p>'; return; }
		$lead = LeadService::find_for_current_user( $lead_id );
		if ( is_wp_error( $lead ) ) { echo '<p role="alert">' . esc_html( $lead->get_error_message() ) . '</p>'; return; }
		echo '<section class="card adc-crm-lead-summary"><h2>' . esc_html__( 'ملخص الفرصة', 'auto-dealership-core' ) . '</h2><dl><dt>' . esc_html__( 'العميل', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( $lead['full_name'] ) . '</dd><dt>' . esc_html__( 'الجوال', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( $lead['mobile'] ) . '</dd><dt>' . esc_html__( 'البريد', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( $lead['email'] ?: '—' ) . '</dd><dt>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( $lead['campaign'] ?: '—' ) . '</dd><dt>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( $lead['platform'] ?: '—' ) . '</dd><dt>' . esc_html__( 'المرحلة', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( \AutoDealership\Core\Localization::label( (string) $lead['stage'] ) ) . '</dd><dt>' . esc_html__( 'المتابعة التالية', 'auto-dealership-core' ) . '</dt><dd>' . esc_html( $lead['next_action_at'] ?: '—' ) . '</dd></dl></section>';
		RequestPage::render( $lead_id );
		CustomerIdentityPage::link_for_lead( $lead_id );
		echo '<h2>' . esc_html__( 'سجل المتابعة', 'auto-dealership-core' ) . ' #' . $lead_id . '</h2><p>' . esc_html__( 'التواريخ أدناه بالتوقيت العالمي UTC.', 'auto-dealership-core' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'التاريخ', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'النشاط', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'التفاصيل', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المتابعة التالية', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'بواسطة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$actor = ! empty( $item['actor_user_id'] ) ? get_userdata( (int) $item['actor_user_id'] ) : null;
			echo '<tr><td>' . esc_html( $item['created_at'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( (string) $item['type'] ) ) . '</td><td>' . nl2br( esc_html( $item['notes'] ) ) . '</td><td>' . esc_html( $item['next_action_at'] ?? '—' ) . '</td><td>' . esc_html( $actor ? $actor->display_name : __( 'النظام', 'auto-dealership-core' ) ) . '</td></tr>';
		}
		if ( ! $items ) { echo '<tr><td colspan="5">' . esc_html__( 'لا توجد أنشطة في هذه الصفحة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table><p>';
		foreach ( array( $page - 1=>__( 'السابق', 'auto-dealership-core' ), $page + 1=>__( 'التالي', 'auto-dealership-core' ) ) as $target => $label ) {
			if ( $target < 1 || ( $target > $page && count( $items ) < 20 ) ) { continue; }
			$url = add_query_arg( array( 'page'=>'adc-crm', 'lead_id'=>$lead_id, 'activity_page'=>$target, 'paged'=>max( 1, absint( $_GET['paged'] ?? 1 ) ) ), admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( $label, 'auto-dealership-core' ) . '</a> ';
		}
		echo '</p>';
	}

	private static function sales_staff( int $branch_id ): array {
		$staff = get_users( array( 'role' => 'dealership_sales', 'number' => 200, 'fields' => array( 'ID', 'display_name' ) ) );
		if ( 0 === $branch_id && current_user_can( 'manage_options' ) ) {
			return $staff;
		}
		return array_values( array_filter( $staff, static fn( $user ) => $branch_id > 0 && $branch_id === BranchScope::assigned_branch( (int) $user->ID ) ) );
	}

	private static function notice(): void {
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'تم حفظ العملية.', 'auto-dealership-core' ) . '</p></div>'; }
		if ( isset( $_GET['error'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'تعذر حفظ العملية. تحقق من الصلاحيات والبيانات وحالة سير العمل.', 'auto-dealership-core' ) . '</p></div>'; }
	}

	/**
	 * @param mixed $result
	 */
	private static function redirect( string $page, $result ): void {
		$url = add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=' . $page ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function create_vehicle(): void {
		\AutoDealership\Pricing\Money::require_sar_form();
		check_admin_referer( 'adc_create_vehicle' );
		$data = array();
		foreach ( array( 'vin', 'stock_number', 'brand', 'model', 'trim', 'condition', 'model_year', 'branch_id', 'retail_price', 'onyx_stock_id', 'onyx_link_confirmed' ) as $key ) {
			$data[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		self::redirect( 'adc-inventory', VehicleService::create( \AutoDealership\Pricing\Money::form_values( $data, array( 'retail_price' ) ) ) );
	}

	public static function transition_vehicle(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_transition_vehicle_' . $id );
		$result = VehicleService::transition( $id, sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::redirect( 'adc-inventory', $result );
	}

	public static function transfer_vehicle(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'adc_transfer_vehicle_' . $id );
		$result = VehicleService::transfer( $id, absint( $_POST['target_branch_id'] ?? 0 ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::redirect( 'adc-transfers', $result );
	}

	public static function move_vehicle_location(): void { $id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_move_vehicle_location_' . $id ); self::redirect( 'adc-inventory-identity&section=locations', VehicleService::move_location( $id, absint( $_POST['location_id'] ?? 0 ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) ) ); }
	public static function change_vehicle_vin(): void { $id = absint( $_POST['id'] ?? 0 ); check_admin_referer( 'adc_change_vehicle_vin_' . $id ); self::redirect( 'adc-vehicle-vin', VehicleService::change_vin( $id, sanitize_text_field( wp_unslash( $_POST['vin'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) ) ); }

	public static function update_lead_stage(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_update_lead_stage_' . $id );
		$result = LeadService::update_stage( $id, sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::redirect( 'adc-crm', $result );
	}

	public static function move_lead_stage_ajax(): void {
		check_ajax_referer( 'adc_crm_board', 'nonce' );
		$lead_id = absint( $_POST['lead_id'] ?? 0 );
		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) );
		$result = LeadService::update_stage( $lead_id, $stage, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) ); }
		wp_send_json_success( array( 'id' => $lead_id, 'stage' => $stage ) );
	}

	public static function export_leads(): void {
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية تصدير الفرص.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_export_leads' );
		$filters = array( 'search'=>$_GET['s'] ?? '', 'stage'=>$_GET['stage'] ?? '', 'source'=>$_GET['source'] ?? '', 'campaign'=>$_GET['campaign'] ?? '', 'platform'=>$_GET['platform'] ?? '', 'created_by'=>absint( $_GET['created_by'] ?? 0 ), 'overdue'=>! empty( $_GET['overdue'] ) );
		$rows = LeadService::list_for_current_user( 1, 100, $filters );
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename=crm-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo "\xEF\xBB\xBF"; $out = fopen( 'php://output', 'w' ); fputcsv( $out, array( 'ID','Customer','Mobile','Email','Source','Campaign','Platform','Created by','Referred at','Stage','Created','Next follow-up' ) );
		foreach ( $rows as $row ) { $creator = ! empty( $row['created_by'] ) ? get_userdata( (int) $row['created_by'] ) : null; fputcsv( $out, array( $row['id'],$row['full_name'],$row['mobile'],$row['email'],$row['source'],$row['campaign'],$row['platform'],$creator ? $creator->display_name : '',$row['referred_at'],$row['stage'],$row['created_at'],$row['next_action_at'] ) ); } fclose( $out ); exit;
	}

	public static function create_marketing_lead(): void {
		if ( ! current_user_can( 'adc_create_marketing_leads' ) ) { wp_die( esc_html__( 'لا تملك صلاحية إنشاء فرص تسويقية.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_create_marketing_lead' );
		$campaign = sanitize_text_field( wp_unslash( $_POST['campaign'] ?? '' ) );
		$platform = sanitize_text_field( wp_unslash( $_POST['platform'] ?? '' ) );
		$branch_id = absint( $_POST['branch_id'] ?? 0 ); if ( ! $branch_id ) { $branch_id = \AutoDealership\Security\BranchScope::assigned_branch(); } $source = sanitize_key( wp_unslash( $_POST['source'] ?? 'marketing' ) ); $ad_url = esc_url_raw( wp_unslash( $_POST['ad_url'] ?? '' ) ); $consent = ! empty( $_POST['consent_marketing'] );
		if ( ! $consent ) { self::redirect( 'adc-crm', new \WP_Error( 'adc_consent_required', __( 'موافقة التواصل مطلوبة.', 'auto-dealership-core' ) ) ); }
		if ( $branch_id && ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( $branch_id ) && ! current_user_can( 'manage_options' ) ) { self::redirect( 'adc-crm', new \WP_Error( 'adc_branch_forbidden', __( 'الفرع المحدد غير متاح لصلاحيتك.', 'auto-dealership-core' ) ) ); }
		global $wpdb; $mobile = sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ); $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT l.id FROM ' . \AutoDealership\Database\Schema::table( 'leads' ) . ' l INNER JOIN ' . \AutoDealership\Database\Schema::table( 'customers' ) . ' c ON c.id=l.customer_id WHERE l.stage NOT IN (\'won\',\'lost\') AND (c.mobile=%s OR (c.email<>"" AND c.email=%s)) LIMIT 1', $mobile, $email ) );
		if ( $duplicate ) {
			$activity = LeadService::add_activity( (int) $duplicate, 'note', trim( 'Duplicate marketing touchpoint. Campaign: ' . $campaign . "\nPlatform: " . $platform . "\nSource: " . $source . "\nAd URL: " . $ad_url ) );
			self::redirect( 'adc-crm', $activity );
		}
		$result = LeadService::create_public( array( 'name'=>sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 'mobile'=>sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ), 'email'=>sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), 'city'=>sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ), 'branch_id'=>$branch_id, 'consent_marketing'=>true ), null, trim( 'Marketing campaign: ' . $campaign . "\nPlatform: " . $platform . "\nSource: " . $source . "\n" . sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ) ) );
		if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) { $wpdb->update( \AutoDealership\Database\Schema::table( 'leads' ), array( 'source'=>$source, 'campaign'=>$campaign, 'platform'=>$platform, 'ad_url'=>$ad_url, 'consent_marketing'=>1, 'created_by'=>get_current_user_id(), 'referred_at'=>current_time( 'mysql', true ) ), array( 'id'=>(int) $result['id'] ), array( '%s','%s','%s','%s','%d','%d','%s' ), array( '%d' ) ); $staff_id = absint( $_POST['staff_id'] ?? 0 ); if ( $staff_id ) { $assigned = LeadService::assign( (int) $result['id'], $staff_id ); if ( is_wp_error( $assigned ) ) { self::redirect( 'adc-crm', $assigned ); } } }
		self::redirect( 'adc-crm', $result );
	}

	public static function assign_lead(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_assign_lead_' . $id );
		self::redirect( 'adc-crm', LeadService::assign( $id, absint( $_POST['staff_id'] ?? 0 ) ) );
	}

	private static function follow_up_input(): string {
		$value = sanitize_text_field( wp_unslash( $_POST['next_action_at'] ?? '' ) );
		if ( '' === $value ) { return ''; }
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $date ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	public static function add_activity(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_add_activity_' . $id );
		$result = LeadService::add_activity( $id, sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ), self::follow_up_input() );
		self::redirect( 'adc-crm', $result );
	}
}
