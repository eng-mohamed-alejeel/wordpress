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
		if ( current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) ) {
			add_submenu_page( 'adc-workspace', __( 'CRM Leads', 'auto-dealership-core' ), __( 'CRM Leads', 'auto-dealership-core' ), current_user_can( 'adc_view_branch_leads' ) ? 'adc_view_branch_leads' : 'adc_view_own_leads', 'adc-crm', array( self::class, 'render_leads' ) );
		}
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
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض الفرص.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$leads = LeadService::list_for_current_user( max( 1, absint( $_GET['paged'] ?? 1 ) ), 50 );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'فرص العملاء CRM', 'auto-dealership-core' ); ?></h1><?php self::notice(); ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="adc-crm">
				<input type="hidden" name="paged" value="<?php echo max( 1, absint( $_GET['paged'] ?? 1 ) ); ?>">
				<label for="adc-history-lead"><?php esc_html_e( 'سجل متابعة الفرصة', 'auto-dealership-core' ); ?></label>
				<select id="adc-history-lead" name="lead_id" required><option value=""><?php esc_html_e( 'اختر فرصة', 'auto-dealership-core' ); ?></option>
				<?php foreach ( $leads as $item ) : ?><option value="<?php echo absint( $item['id'] ); ?>" <?php selected( absint( $_GET['lead_id'] ?? 0 ), (int) $item['id'] ); ?>><?php echo esc_html( '#' . $item['id'] . ' — ' . $item['full_name'] ); ?></option><?php endforeach; ?>
				</select><button class="button"><?php esc_html_e( 'عرض السجل', 'auto-dealership-core' ); ?></button>
			</form>
			<?php self::render_activity_history(); ?>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'العميل', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الجوال', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'البريد', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المصدر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المرحلة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'إضافة نشاط', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $leads as $lead ) : ?><tr><td><?php echo absint( $lead['id'] ); ?></td><td><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>(int) $lead['customer_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $lead['full_name'] ); ?></a></td><td><?php echo esc_html( $lead['mobile'] ); ?></td><td><?php echo esc_html( $lead['email'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $lead['source'] ) ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $lead['stage'] ) ); ?><?php if ( current_user_can( 'adc_manage_branch_leads' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_assign_lead"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_assign_lead_' . (int) $lead['id'] ); ?><select name="staff_id" aria-label="<?php esc_attr_e( 'إسناد إلى', 'auto-dealership-core' ); ?>" required><option value="0"><?php esc_html_e( 'إسناد إلى...', 'auto-dealership-core' ); ?></option><?php foreach ( self::sales_staff( (int) $lead['branch_id'] ) as $staff ) : ?><option value="<?php echo absint( $staff->ID ); ?>" <?php selected( (int) $lead['owner_user_id'], (int) $staff->ID ); ?>><?php echo esc_html( $staff->display_name ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'إسناد', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_update_lead_stage"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_update_lead_stage_' . (int) $lead['id'] ); ?><select name="stage" aria-label="<?php esc_attr_e( 'المرحلة', 'auto-dealership-core' ); ?>"><?php foreach ( array( 'contacted', 'qualified', 'quotation', 'finance', 'negotiation', 'reserved', 'won', 'lost' ) as $stage ) : ?><option value="<?php echo esc_attr( $stage ); ?>"><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $stage ) ); ?></option><?php endforeach; ?></select><input name="reason" aria-label="<?php esc_attr_e( 'سبب الخسارة عند الحاجة', 'auto-dealership-core' ); ?>" placeholder="<?php esc_attr_e( 'سبب الخسارة عند الحاجة', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></button></form></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_add_activity"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_add_activity_' . (int) $lead['id'] ); ?><select name="type" aria-label="<?php esc_attr_e( 'نوع النشاط', 'auto-dealership-core' ); ?>"><option value="call"><?php echo esc_html__( 'call', 'auto-dealership-core' ); ?></option><option value="whatsapp"><?php echo esc_html__( 'WhatsApp', 'auto-dealership-core' ); ?></option><option value="meeting"><?php echo esc_html__( 'meeting', 'auto-dealership-core' ); ?></option><option value="follow_up"><?php echo esc_html__( 'follow-up', 'auto-dealership-core' ); ?></option><option value="note"><?php echo esc_html__( 'note', 'auto-dealership-core' ); ?></option></select><input name="notes" aria-label="<?php esc_attr_e( 'ملاحظات النشاط', 'auto-dealership-core' ); ?>" required><label><?php echo esc_html__( 'Follow-up (UTC)', 'auto-dealership-core' ); ?> <input name="next_action_at" type="datetime-local"></label><button class="button"><?php esc_html_e( 'إضافة', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?>
			<?php if ( ! $leads ) : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد فرص في نطاق صلاحيتك.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	private static function render_activity_history(): void {
		$lead_id = absint( $_GET['lead_id'] ?? 0 );
		if ( ! $lead_id ) { return; }
		$page = max( 1, absint( $_GET['activity_page'] ?? 1 ) );
		$items = LeadService::activity_history( $lead_id, $page, 20 );
		if ( is_wp_error( $items ) ) { echo '<p role="alert">' . esc_html( $items->get_error_message() ) . '</p>'; return; }
		RequestPage::render( $lead_id );
		CustomerIdentityPage::link_for_lead( $lead_id );
		echo '<h2>' . esc_html__( 'سجل المتابعة', 'auto-dealership-core' ) . ' #' . $lead_id . '</h2><p>' . esc_html__( 'التواريخ أدناه بالتوقيت العالمي UTC.', 'auto-dealership-core' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'التاريخ', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'النشاط', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'التفاصيل', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المتابعة التالية', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td>' . esc_html( $item['created_at'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( (string) $item['type'] ) ) . '</td><td>' . nl2br( esc_html( $item['notes'] ) ) . '</td><td>' . esc_html( $item['next_action_at'] ?? '—' ) . '</td></tr>';
		}
		if ( ! $items ) { echo '<tr><td colspan="4">' . esc_html__( 'لا توجد أنشطة في هذه الصفحة.', 'auto-dealership-core' ) . '</td></tr>'; }
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

	public static function assign_lead(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_assign_lead_' . $id );
		self::redirect( 'adc-crm', LeadService::assign( $id, absint( $_POST['staff_id'] ?? 0 ) ) );
	}

	private static function follow_up_input(): string {
		$value = sanitize_text_field( wp_unslash( $_POST['next_action_at'] ?? '' ) );
		return '' === $value ? '' : $value . ':00Z';
	}

	public static function add_activity(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_add_activity_' . $id );
		$result = LeadService::add_activity( $id, sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ), self::follow_up_input() );
		self::redirect( 'adc-crm', $result );
	}
}
