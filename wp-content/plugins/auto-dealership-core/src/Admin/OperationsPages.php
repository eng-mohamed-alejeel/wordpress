<?php
namespace AutoDealership\Admin;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Branches\BranchService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Leads\LeadService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Capability-scoped staff screens over the same application services used by REST. */
final class OperationsPages {
	private const CRM_EXPORT_MAX_ROWS = 5000;
	private const CRM_EXPORT_PAGE_SIZE = 100;

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
		add_action( 'admin_post_adc_release_lead_assignment', array( self::class, 'release_lead_assignment' ) );
		add_action( 'admin_post_adc_claim_lead', array( self::class, 'claim_lead' ) );
		add_action( 'admin_post_adc_record_first_contact', array( self::class, 'record_first_contact' ) );
		add_action( 'admin_post_adc_update_lead_priority', array( self::class, 'update_lead_priority' ) );
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
		if ( current_user_can( 'adc_view_reports' ) || current_user_can( 'manage_options' ) ) { $report_capability = current_user_can( 'adc_view_reports' ) ? 'adc_view_reports' : 'manage_options'; add_submenu_page( 'adc-workspace', __( 'CRM performance', 'auto-dealership-core' ), __( 'CRM performance', 'auto-dealership-core' ), $report_capability, 'adc-lead-performance', array( self::class, 'render_lead_performance' ) ); }
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
		$filters = array( 'search' => $_GET['s'] ?? '', 'stage' => $_GET['stage'] ?? '', 'priority'=>$_GET['priority'] ?? '', 'branch_id'=>absint( $_GET['branch_id'] ?? 0 ), 'source' => $_GET['source'] ?? '', 'campaign' => $_GET['campaign'] ?? '', 'platform' => $_GET['platform'] ?? '', 'created_by' => absint( $_GET['created_by'] ?? 0 ), 'overdue' => ! empty( $_GET['overdue'] ) );
		if ( isset( $_GET['owner'] ) && '' !== (string) $_GET['owner'] ) { $filters['owner'] = absint( $_GET['owner'] ); }
		$filter_branches = array_values( array_filter( BranchService::public_list(), static fn( $branch ) => BranchScope::allows( (int) $branch['id'] ) ) );
		$is_sales_employee = current_user_can( 'adc_manage_own_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' );
		$claim_queue = array();
		$queue_total = 0;
		$queue_page = max( 1, absint( $_GET['queue_page'] ?? 1 ) );
		$queue_pages = 1;
		$board_filters = $is_sales_employee ? array_diff_key( $filters, array( 'owner'=>true ) ) : $filters;
		$board_total = LeadService::count_for_current_user( $board_filters );
		$board_page = max( 1, absint( $_GET['board_page'] ?? 1 ) );
		$board_per_page = 100;
		$board_pages = max( 1, (int) ceil( $board_total / $board_per_page ) );
		$board_page = min( $board_page, $board_pages );
		if ( $is_sales_employee ) {
			$queue_filters = $filters;
			unset( $queue_filters['owner'] );
			$queue_filters['stage'] = 'new';
			$queue_total = LeadService::count_for_current_user( $queue_filters );
			$queue_pages = max( 1, (int) ceil( $queue_total / 100 ) );
			$queue_page = min( $queue_page, $queue_pages );
			$claim_queue = array_values( array_filter( LeadService::list_for_current_user( $queue_page, 100, $queue_filters ), static fn( $lead ) => 0 === (int) $lead['owner_user_id'] ) );
			$filters['owner'] = get_current_user_id();
		}
		$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) ); $per_page = 25;
		$total_leads = LeadService::count_for_current_user( $filters );
		$total_pages = max( 1, (int) ceil( $total_leads / $per_page ) );
		$current_page = min( $current_page, $total_pages );
		$leads = LeadService::list_for_current_user( $current_page, $per_page, $filters );
		$board_leads = LeadService::list_for_current_user( $board_page, $board_per_page, $board_filters );
		$summary = LeadService::pipeline_summary();
		if ( $is_sales_employee ) {
			echo '<section class="card adc-lead-claim-queue"><h2>' . esc_html__( 'الفرص المتاحة للاستلام', 'auto-dealership-core' ) . ' (' . esc_html( number_format_i18n( $queue_total ) ) . ')</h2><ul>';
			foreach ( $claim_queue as $claimable ) {
				if ( 'new' !== $claimable['stage'] || (int) $claimable['owner_user_id'] !== 0 ) { continue; }
				echo '<li><strong>' . esc_html( $claimable['full_name'] ) . '</strong> — <a href="tel:' . esc_attr( $claimable['mobile'] ) . '">' . esc_html( $claimable['mobile'] ) . '</a> — ' . esc_html( $claimable['created_at'] ) . ' — ' . esc_html( \AutoDealership\Core\Localization::label( (string) ( $claimable['priority'] ?? 'normal' ) ) );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:4px"><input type="hidden" name="action" value="adc_claim_lead"><input type="hidden" name="lead_id" value="' . absint( $claimable['id'] ) . '">' . wp_nonce_field( 'adc_claim_lead_' . (int) $claimable['id'], '_wpnonce', true, false ) . '<button class="button button-primary">' . esc_html__( 'استلام الفرصة', 'auto-dealership-core' ) . '</button></form></li>';
			}
			if ( ! $claim_queue ) { echo '<li>' . esc_html__( 'لا توجد فرص متاحة في هذه الصفحة.', 'auto-dealership-core' ) . '</li>'; }
			echo '</ul>';
			if ( $queue_pages > 1 ) {
				echo '<nav class="tablenav" aria-label="' . esc_attr__( 'صفحات فرص الاستلام', 'auto-dealership-core' ) . '">';
				for ( $page_number = max( 1, $queue_page - 2 ); $page_number <= min( $queue_pages, $queue_page + 2 ); ++$page_number ) {
					$url = add_query_arg( array_merge( self::crm_filter_query_args(), array( 'page'=>'adc-crm', 'queue_page'=>$page_number ) ), admin_url( 'admin.php' ) );
					echo '<a class="button ' . ( $page_number === $queue_page ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . absint( $page_number ) . '</a> ';
				}
				echo '<span class="description">' . esc_html( sprintf( __( 'صفحة %1$d من %2$d', 'auto-dealership-core' ), $queue_page, $queue_pages ) ) . '</span></nav>';
			}
			echo '</section>';
		}
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'فرص العملاء CRM', 'auto-dealership-core' ); ?></h1><?php self::notice(); ?>
			<?php self::render_marketing_intake_v2(); ?>
			<?php self::render_marketing_filters(); ?>
			<div class="adc-crm-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0"><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></strong><br><?php esc_html_e( 'إجمالي الفرص', 'auto-dealership-core' ); ?></div><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['new'] ) ); ?></strong><br><?php esc_html_e( 'فرص جديدة', 'auto-dealership-core' ); ?></div><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['overdue'] ) ); ?></strong><br><?php esc_html_e( 'متابعات متأخرة', 'auto-dealership-core' ); ?></div><div class="card"><strong><?php echo esc_html( number_format_i18n( $summary['unassigned'] ) ); ?></strong><br><?php esc_html_e( 'غير مسندة', 'auto-dealership-core' ); ?></div></div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tablenav top"><input type="hidden" name="page" value="adc-crm"><input type="hidden" name="campaign" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['campaign'] ?? '' ) ) ); ?>"><input type="hidden" name="platform" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['platform'] ?? '' ) ) ); ?>"><input type="hidden" name="created_by" value="<?php echo absint( $_GET['created_by'] ?? 0 ); ?>"><input type="search" name="s" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ); ?>" placeholder="<?php esc_attr_e( 'بحث بالاسم أو الجوال أو البريد أو الرقم', 'auto-dealership-core' ); ?>"><select name="stage"><option value=""><?php esc_html_e( 'كل المراحل', 'auto-dealership-core' ); ?></option><?php foreach ( array( 'new','assigned','contacted','qualified','quotation','finance','negotiation','reserved','won','lost' ) as $filter_stage ) : ?><option value="<?php echo esc_attr( $filter_stage ); ?>" <?php selected( sanitize_key( $_GET['stage'] ?? '' ), $filter_stage ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( $filter_stage ) ); ?></option><?php endforeach; ?></select><select name="priority"><option value=""><?php esc_html_e( 'كل الأولويات', 'auto-dealership-core' ); ?></option><?php foreach ( array( 'urgent','high','normal','low' ) as $filter_priority ) : ?><option value="<?php echo esc_attr( $filter_priority ); ?>" <?php selected( sanitize_key( $_GET['priority'] ?? '' ), $filter_priority ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( $filter_priority ) ); ?></option><?php endforeach; ?></select><?php if ( current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ) ) : ?><select name="branch_id"><option value="0"><?php esc_html_e( 'كل الفروع المسموحة', 'auto-dealership-core' ); ?></option><?php foreach ( $filter_branches as $filter_branch ) : ?><option value="<?php echo absint( $filter_branch['id'] ); ?>" <?php selected( absint( $_GET['branch_id'] ?? 0 ), (int) $filter_branch['id'] ); ?>><?php echo esc_html( $filter_branch['name'] ); ?></option><?php endforeach; ?></select><select name="owner"><option value=""><?php esc_html_e( 'كل الموظفين', 'auto-dealership-core' ); ?></option><option value="0" <?php selected( isset( $_GET['owner'] ) && '0' === (string) $_GET['owner'] ); ?>><?php esc_html_e( 'غير مسندة', 'auto-dealership-core' ); ?></option><?php foreach ( self::sales_staff( absint( $_GET['branch_id'] ?? 0 ) ) as $filter_staff ) : ?><option value="<?php echo absint( $filter_staff->ID ); ?>" <?php selected( absint( $_GET['owner'] ?? 0 ), (int) $filter_staff->ID ); ?>><?php echo esc_html( $filter_staff->display_name ); ?></option><?php endforeach; ?></select><?php endif; ?><select name="source"><option value=""><?php esc_html_e( 'كل المصادر', 'auto-dealership-core' ); ?></option><?php foreach ( array( 'website','whatsapp','phone','walk_in','instagram','tiktok','snapchat','google','referral','campaign','other' ) as $filter_source ) : ?><option value="<?php echo esc_attr( $filter_source ); ?>" <?php selected( sanitize_key( $_GET['source'] ?? '' ), $filter_source ); ?>><?php echo esc_html( \AutoDealership\Core\Localization::label( $filter_source ) ); ?></option><?php endforeach; ?></select><label><input type="checkbox" name="overdue" value="1" <?php checked( ! empty( $_GET['overdue'] ) ); ?>><?php esc_html_e( 'المتابعات المتأخرة', 'auto-dealership-core' ); ?></label><button class="button"><?php esc_html_e( 'تصفية', 'auto-dealership-core' ); ?></button></form>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="adc-crm">
				<?php foreach ( self::crm_filter_query_args() as $filter_key=>$filter_value ) : ?><input type="hidden" name="<?php echo esc_attr( $filter_key ); ?>" value="<?php echo esc_attr( $filter_value ); ?>"><?php endforeach; ?>
				<label for="adc-history-lead"><?php esc_html_e( 'سجل متابعة الفرصة', 'auto-dealership-core' ); ?></label>
				<select id="adc-history-lead" name="lead_id" required><option value=""><?php esc_html_e( 'اختر فرصة', 'auto-dealership-core' ); ?></option>
				<?php foreach ( $leads as $item ) : ?><option value="<?php echo absint( $item['id'] ); ?>" <?php selected( absint( $_GET['lead_id'] ?? 0 ), (int) $item['id'] ); ?>><?php echo esc_html( '#' . $item['id'] . ' — ' . $item['full_name'] ); ?></option><?php endforeach; ?>
				</select><button class="button"><?php esc_html_e( 'عرض السجل', 'auto-dealership-core' ); ?></button>
			</form>
			<?php self::render_activity_history(); ?>
			<?php if ( ! absint( $_GET['lead_id'] ?? 0 ) && current_user_can( 'adc_export_crm_leads' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
					<input type="hidden" name="action" value="adc_export_leads">
					<?php foreach ( self::crm_filter_query_args() as $filter_key => $filter_value ) : ?><input type="hidden" name="<?php echo esc_attr( $filter_key ); ?>" value="<?php echo esc_attr( $filter_value ); ?>"><?php endforeach; ?>
					<?php wp_nonce_field( 'adc_export_leads' ); ?>
					<label for="adc-crm-export-purpose"><?php esc_html_e( 'غرض تصدير بيانات CRM', 'auto-dealership-core' ); ?></label>
					<select id="adc-crm-export-purpose" name="purpose" required>
						<option value=""><?php esc_html_e( 'اختر الغرض', 'auto-dealership-core' ); ?></option>
						<?php foreach ( self::crm_export_purpose_options() as $purpose_code => $purpose_label ) : ?><option value="<?php echo esc_attr( $purpose_code ); ?>"><?php echo esc_html( $purpose_label ); ?></option><?php endforeach; ?>
					</select>
					<label for="adc-crm-export-reason"><?php esc_html_e( 'سبب تصدير بيانات CRM', 'auto-dealership-core' ); ?></label>
					<select id="adc-crm-export-reason" name="reason" required>
						<option value=""><?php esc_html_e( 'اختر السبب', 'auto-dealership-core' ); ?></option>
						<?php foreach ( self::crm_export_reason_options() as $reason_code => $reason_label ) : ?><option value="<?php echo esc_attr( $reason_code ); ?>"><?php echo esc_html( $reason_label ); ?></option><?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'تختلف أعمدة الملف حسب الغرض المختار. يُسجّل النظام فئتي الغرض والسبب فقط؛ لا تُدخل بيانات العملاء في وصف حر.', 'auto-dealership-core' ); ?></p>
					<button class="button"><?php esc_html_e( 'تصدير النتائج CSV', 'auto-dealership-core' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( ! absint( $_GET['lead_id'] ?? 0 ) ) : ?>
				<section class="adc-crm-board" aria-label="<?php esc_attr_e( 'لوحة مراحل الفرص', 'auto-dealership-core' ); ?>">
					<p class="description" data-crm-board-status role="status" aria-live="polite"></p>
					<?php foreach ( array( 'new', 'assigned', 'contacted', 'qualified', 'quotation', 'finance', 'negotiation', 'reserved', 'won', 'lost' ) as $board_stage ) : ?>
						<div class="adc-crm-board-column" data-lead-stage="<?php echo esc_attr( $board_stage ); ?>">
							<h2><?php echo esc_html( \AutoDealership\Core\Localization::label( $board_stage ) ); ?></h2>
							<?php foreach ( $board_leads as $board_lead ) : ?>
								<?php if ( $board_stage !== $board_lead['stage'] ) { continue; } ?>
								<?php $allowed_stages = LeadService::allowed_stage_transitions( (string) $board_lead['stage'] ); ?>
								<?php if ( 'assigned' === $board_lead['stage'] && empty( $board_lead['first_contact_at'] ) ) { $allowed_stages = array_values( array_diff( $allowed_stages, array( 'contacted' ) ) ); } ?>
								<article class="adc-crm-board-card" draggable="<?php echo 'new' === $board_stage ? 'false' : 'true'; ?>" data-lead-card data-lead-id="<?php echo absint( $board_lead['id'] ); ?>" data-stage="<?php echo esc_attr( $board_lead['stage'] ); ?>" data-allowed="<?php echo esc_attr( wp_json_encode( $allowed_stages ) ); ?>">
									<strong><?php echo esc_html( $board_lead['full_name'] ); ?></strong><span>#<?php echo absint( $board_lead['id'] ); ?></span>
									<?php if ( 'new' === $board_lead['stage'] && 0 === (int) $board_lead['owner_user_id'] && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) : ?>
										<a href="tel:<?php echo esc_attr( $board_lead['mobile'] ); ?>"><?php echo esc_html( $board_lead['mobile'] ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_claim_lead"><input type="hidden" name="lead_id" value="<?php echo absint( $board_lead['id'] ); ?>"><?php wp_nonce_field( 'adc_claim_lead_' . (int) $board_lead['id'] ); ?><button class="button button-primary"><?php esc_html_e( 'استلام الفرصة', 'auto-dealership-core' ); ?></button></form>
									<?php else : ?>
										<a href="<?php echo esc_url( add_query_arg( array_merge( self::crm_filter_query_args(), array( 'page'=>'adc-crm','lead_id'=>(int) $board_lead['id'] ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'التفاصيل', 'auto-dealership-core' ); ?></a>
									<?php endif; ?>
									<?php if ( $allowed_stages && ( (int) $board_lead['owner_user_id'] > 0 || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ) ) ) : ?><form data-lead-stage-form><label><span class="screen-reader-text"><?php esc_html_e( 'المرحلة التالية', 'auto-dealership-core' ); ?></span><select name="stage" required><option value=""><?php esc_html_e( 'اختر المرحلة التالية', 'auto-dealership-core' ); ?></option><?php foreach ( $allowed_stages as $next_stage ) : ?><option value="<?php echo esc_attr( $next_stage ); ?>"><?php echo esc_html( \AutoDealership\Core\Localization::label( $next_stage ) ); ?></option><?php endforeach; ?></select></label><button class="button" type="submit"><?php esc_html_e( 'تغيير المرحلة', 'auto-dealership-core' ); ?></button></form><?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</section>
				<?php if ( $board_pages > 1 ) : ?><nav class="tablenav" aria-label="<?php esc_attr_e( 'صفحات لوحة المراحل', 'auto-dealership-core' ); ?>"><?php for ( $page_number = max( 1, $board_page - 2 ); $page_number <= min( $board_pages, $board_page + 2 ); ++$page_number ) : $board_url = add_query_arg( array_merge( self::crm_filter_query_args(), array( 'page'=>'adc-crm', 'board_page'=>$page_number ) ), admin_url( 'admin.php' ) ); ?><a class="button <?php echo $page_number === $board_page ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $board_url ); ?>"><?php echo absint( $page_number ); ?></a> <?php endfor; ?><span class="description"><?php echo esc_html( sprintf( __( 'عرض %1$d–%2$d من %3$d فرصة في اللوحة', 'auto-dealership-core' ), ( ( $board_page - 1 ) * $board_per_page ) + 1, min( $board_page * $board_per_page, $board_total ), $board_total ) ); ?></span></nav><?php endif; ?>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['owner'] ) ) : ?><p class="description"><?php echo esc_html( sprintf( __( 'تصفية حسب المسؤول رقم %d', 'auto-dealership-core' ), absint( $_GET['owner'] ) ) ); ?></p><?php endif; ?>
			<?php if ( absint( $_GET['lead_id'] ?? 0 ) ) : ?><p><a class="button" href="<?php echo esc_url( remove_query_arg( array( 'lead_id', 'activity_page' ) ) ); ?>"><?php esc_html_e( 'العودة إلى قائمة الفرص', 'auto-dealership-core' ); ?></a></p></div><?php return; endif; ?>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'العميل', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الجوال', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'البريد', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المصدر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المرحلة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'إضافة نشاط', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $leads as $lead ) : ?><tr><td><?php echo absint( $lead['id'] ); ?></td><td><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>(int) $lead['customer_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $lead['full_name'] ); ?></a></td><td><?php echo esc_html( $lead['mobile'] ); ?></td><td><?php echo esc_html( $lead['email'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $lead['source'] ) ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $lead['stage'] ) ); ?><?php if ( current_user_can( 'adc_manage_branch_leads' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_assign_lead"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_assign_lead_' . (int) $lead['id'] ); ?><select name="staff_id" aria-label="<?php esc_attr_e( 'إسناد إلى', 'auto-dealership-core' ); ?>" required><option value="0"><?php esc_html_e( 'إسناد إلى...', 'auto-dealership-core' ); ?></option><?php foreach ( self::sales_staff( (int) $lead['branch_id'] ) as $staff ) : ?><option value="<?php echo absint( $staff->ID ); ?>" <?php selected( (int) $lead['owner_user_id'], (int) $staff->ID ); ?>><?php echo esc_html( $staff->display_name ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'إسناد', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_update_lead_stage"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_update_lead_stage_' . (int) $lead['id'] ); ?><select name="stage" aria-label="<?php esc_attr_e( 'المرحلة', 'auto-dealership-core' ); ?>"><?php foreach ( array( 'contacted', 'qualified', 'quotation', 'finance', 'negotiation', 'reserved', 'won', 'lost' ) as $stage ) : ?><option value="<?php echo esc_attr( $stage ); ?>"><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $stage ) ); ?></option><?php endforeach; ?></select><input name="reason" aria-label="<?php esc_attr_e( 'سبب الخسارة عند الحاجة', 'auto-dealership-core' ); ?>" placeholder="<?php esc_attr_e( 'سبب الخسارة عند الحاجة', 'auto-dealership-core' ); ?>"><button class="button"><?php esc_html_e( 'تحديث', 'auto-dealership-core' ); ?></button></form></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_add_activity"><input type="hidden" name="lead_id" value="<?php echo absint( $lead['id'] ); ?>"><?php wp_nonce_field( 'adc_add_activity_' . (int) $lead['id'] ); ?><select name="type" aria-label="<?php esc_attr_e( 'نوع النشاط', 'auto-dealership-core' ); ?>"><option value="call"><?php echo esc_html__( 'call', 'auto-dealership-core' ); ?></option><option value="whatsapp"><?php echo esc_html__( 'WhatsApp', 'auto-dealership-core' ); ?></option><option value="meeting"><?php echo esc_html__( 'meeting', 'auto-dealership-core' ); ?></option><option value="follow_up"><?php echo esc_html__( 'follow-up', 'auto-dealership-core' ); ?></option><option value="note"><?php echo esc_html__( 'note', 'auto-dealership-core' ); ?></option></select><input name="notes" aria-label="<?php esc_attr_e( 'ملاحظات النشاط', 'auto-dealership-core' ); ?>" required><label><?php echo esc_html__( 'Follow-up (UTC)', 'auto-dealership-core' ); ?> <input name="next_action_at" type="datetime-local"></label><button class="button"><?php esc_html_e( 'إضافة', 'auto-dealership-core' ); ?></button></form></td></tr><?php endforeach; ?>
			<?php if ( ! $leads ) : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد فرص في نطاق صلاحيتك.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<?php if ( $total_pages > 1 ) : ?><nav class="tablenav" aria-label="<?php esc_attr_e( 'صفحات فرص العملاء', 'auto-dealership-core' ); ?>"><span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s فرصة', '%s فرص', $total_leads, 'auto-dealership-core' ), number_format_i18n( $total_leads ) ) ); ?></span><?php for ( $page_number = max( 1, $current_page - 2 ); $page_number <= min( $total_pages, $current_page + 2 ); ++$page_number ) : $page_url = add_query_arg( array_merge( self::crm_filter_query_args(), array( 'page'=>'adc-crm', 'paged'=>$page_number ) ), admin_url( 'admin.php' ) ); ?><a class="button <?php echo $page_number === $current_page ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $page_url ); ?>"><?php echo absint( $page_number ); ?></a> <?php endfor; ?></nav><?php endif; ?>
		</div>
		<?php
	}

	private static function crm_filter_query_args(): array {
		$args = array();
		foreach ( array( 's'=>'text', 'stage'=>'key', 'priority'=>'key', 'source'=>'key', 'campaign'=>'text', 'platform'=>'text' ) as $key=>$type ) {
			if ( ! isset( $_GET[$key] ) || ! is_scalar( $_GET[$key] ) ) { continue; }
			$value = wp_unslash( (string) $_GET[$key] );
			$args[$key] = 'key' === $type ? sanitize_key( $value ) : sanitize_text_field( $value );
		}
		foreach ( array( 'branch_id', 'created_by' ) as $key ) {
			if ( isset( $_GET[$key] ) && is_scalar( $_GET[$key] ) ) { $args[$key] = absint( $_GET[$key] ); }
		}
		if ( isset( $_GET['owner'] ) && is_scalar( $_GET['owner'] ) && '' !== (string) $_GET['owner'] ) { $args['owner'] = absint( $_GET['owner'] ); }
		if ( ! empty( $_GET['overdue'] ) ) { $args['overdue'] = 1; }
		foreach ( array( 'paged', 'queue_page', 'board_page' ) as $key ) {
			if ( isset( $_GET[$key] ) && is_scalar( $_GET[$key] ) ) { $args[$key] = max( 1, absint( $_GET[$key] ) ); }
		}
		return $args;
	}

	private static function render_marketing_intake_v2(): void {
		if ( ! current_user_can( 'adc_create_marketing_leads' ) ) { return; }
		echo '<details class="card adc-marketing-intake-v2"><summary>' . esc_html__( 'إضافة فرصة من فريق التسويق', 'auto-dealership-core' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_create_marketing_lead"><input type="hidden" name="idempotency_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'adc_create_marketing_lead' );
		echo '<p><label>' . esc_html__( 'اسم العميل', 'auto-dealership-core' ) . ' <input name="name" required></label> <label>' . esc_html__( 'الجوال', 'auto-dealership-core' ) . ' <input name="mobile" required></label> <label>' . esc_html__( 'البريد', 'auto-dealership-core' ) . ' <input type="email" name="email"></label> <label>' . esc_html__( 'المدينة', 'auto-dealership-core' ) . ' <input name="city"></label></p>';
		echo '<p><label>' . esc_html__( 'الفرع', 'auto-dealership-core' ) . ' <select name="branch_id"><option value="0" data-branch="' . absint( BranchScope::assigned_branch() ) . '">' . esc_html__( 'الفرع الافتراضي', 'auto-dealership-core' ) . '</option>';
		foreach ( BranchService::public_list() as $branch ) { if ( ! BranchScope::allows( (int) $branch['id'] ) ) { continue; } echo '<option value="' . absint( $branch['id'] ) . '">' . esc_html( $branch['name'] ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . ' <input name="campaign" required></label> <label>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . ' <input name="platform" required></label></p>';
		echo '<p><label>' . esc_html__( 'المصدر', 'auto-dealership-core' ) . ' <select name="source"><option value="marketing">Marketing</option><option value="whatsapp">WhatsApp</option><option value="phone">Phone</option><option value="walk_in">Walk-in</option></select></label> <label>' . esc_html__( 'موظف المبيعات', 'auto-dealership-core' ) . ' <select name="staff_id"><option value="0">' . esc_html__( 'إسناد لاحقًا', 'auto-dealership-core' ) . '</option>';
		foreach ( get_users( array( 'role'=>'dealership_sales', 'number'=>-1, 'fields'=>array( 'ID','display_name' ) ) ) as $staff ) { $staff_branch = BranchScope::assigned_branch( (int) $staff->ID ); if ( ! BranchScope::allows( $staff_branch ) ) { continue; } echo '<option data-branch="' . absint( $staff_branch ) . '" value="' . absint( $staff->ID ) . '">' . esc_html( $staff->display_name ) . '</option>'; }
		echo '</select></label></p><p><label>' . esc_html__( 'رابط الإعلان', 'auto-dealership-core' ) . ' <input type="url" name="ad_url"></label> <label>' . esc_html__( 'ملاحظات', 'auto-dealership-core' ) . ' <textarea name="notes" rows="2"></textarea></label></p><p><label><input type="checkbox" name="consent_marketing" value="1" required> ' . esc_html__( 'تم الحصول على موافقة العميل للتواصل', 'auto-dealership-core' ) . '</label></p><button class="button button-primary">' . esc_html__( 'إنشاء الفرصة', 'auto-dealership-core' ) . '</button></form></details><script>(function(){var b=document.querySelector(\".adc-marketing-intake-v2 select[name=branch_id]\"),s=document.querySelector(\".adc-marketing-intake-v2 select[name=staff_id]\");if(!b||!s)return;function f(){var branch=b.value||b.options[0].dataset.branch||\"0\";Array.prototype.forEach.call(s.options,function(o){if(!o.value)return;o.hidden=o.dataset.branch!==branch;});if(s.selectedOptions[0]&&s.selectedOptions[0].hidden)s.value=\"0\";}b.addEventListener(\"change\",f);f();})();</script>';
	}

	private static function render_marketing_filters(): void {
		if ( ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) { return; }
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="adc-marketing-filters"><input type="hidden" name="page" value="adc-crm">';
		foreach ( array( 's', 'stage', 'priority', 'branch_id', 'owner', 'source', 'overdue' ) as $key ) {
			if ( ! isset( $_GET[$key] ) || ! is_scalar( $_GET[$key] ) || ( 'owner' === $key && '' === (string) $_GET[$key] ) ) { continue; }
			$raw_value = (string) wp_unslash( $_GET[$key] );
			$value = 's' === $key ? sanitize_text_field( $raw_value ) : ( in_array( $key, array( 'stage', 'priority', 'source' ), true ) ? sanitize_key( $raw_value ) : absint( $raw_value ) );
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<label>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . ' <input name="campaign" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['campaign'] ?? '' ) ) ) . '"></label> <label>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . ' <input name="platform" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['platform'] ?? '' ) ) ) . '"></label> <label>' . esc_html__( 'منشئ الفرصة', 'auto-dealership-core' ) . ' <input type="number" min="1" name="created_by" value="' . absint( $_GET['created_by'] ?? 0 ) . '"></label> <button class="button">' . esc_html__( 'تصفية التسويق', 'auto-dealership-core' ) . '</button></form>';
	}

	public static function render_lead_performance(): void {
		if ( ! current_user_can( 'adc_view_reports' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض تقرير أداء CRM.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$today = current_time( 'Y-m-d' );
		$from = isset( $_GET['from'] ) && is_string( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
		$to = isset( $_GET['to'] ) && is_string( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $today;
		$report = \AutoDealership\Reports\LeadPerformanceReport::summary( $from, $to );
		echo '<div class="wrap" dir="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl' ) . '"><h1>' . esc_html__( 'أداء العملاء المحتملين CRM', 'auto-dealership-core' ) . '</h1>';
		if ( is_wp_error( $report ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $report->get_error_message() ) . '</p></div></div>';
			return;
		}
		echo '<form method="get" class="adc-marketing-filters"><input type="hidden" name="page" value="adc-lead-performance"><label>' . esc_html__( 'من تاريخ', 'auto-dealership-core' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '" required></label> <label>' . esc_html__( 'إلى تاريخ', 'auto-dealership-core' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '" required></label> <button class="button">' . esc_html__( 'تطبيق', 'auto-dealership-core' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'تعتمد حدود التاريخ على المنطقة الزمنية للموقع. تشمل المؤشرات الفرص المنشأة خلال الفترة المحددة، بينما تعكس المرحلة الحالية والمتابعات المتأخرة حالتها عند إعداد التقرير، لا الأحداث التي وقعت داخل الفترة فقط.', 'auto-dealership-core' ) . '</p>';
		$backfill_complete = (bool) get_option( 'adc_lead_stage_history_backfill_complete', false ) && (bool) get_option( 'adc_lead_stage_history_duration_complete', false );
		$backfill_health = get_option( 'adc_lead_stage_history_backfill_health', array() );
		if ( ! is_array( $backfill_health ) ) { $backfill_health = array(); }
		$backfill_status = $backfill_complete ? __( 'مكتملة', 'auto-dealership-core' ) : ( ! empty( $backfill_health['last_error_at'] ) ? __( 'تعذر إكمال آخر دفعة وستتم إعادة المحاولة', 'auto-dealership-core' ) : __( 'قيد التنفيذ أو بانتظار المجدول', 'auto-dealership-core' ) );
		echo '<p class="description">' . esc_html( sprintf( __( 'استعادة سجل المراحل: %1$s. عولج %2$s حدث تدقيق، وأعيد حساب مدد %3$s فرصة؛ مؤشر الأحداث %4$s.', 'auto-dealership-core' ), $backfill_status, number_format_i18n( absint( $backfill_health['processed_total'] ?? 0 ) ), number_format_i18n( absint( $backfill_health['duration_processed_total'] ?? 0 ) ), number_format_i18n( absint( $backfill_health['cursor'] ?? 0 ) ) ) ) . '</p>';
		$totals = $report['totals'];
		echo '<div class="adc-crm-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0">';
		foreach ( array( 'total'=>'إجمالي الفرص', 'new'=>'جديدة', 'first_contact'=>'تم أول تواصل', 'overdue'=>'متابعات متأخرة', 'won'=>'مباعة', 'lost'=>'خاسرة' ) as $key=>$label ) {
			echo '<div class="card"><strong>' . esc_html( number_format_i18n( (int) $totals[$key] ) ) . '</strong><br>' . esc_html__( $label, 'auto-dealership-core' ) . '</div>';
		}
		$avg_minutes = null === $totals['avg_first_contact_seconds'] ? null : (float) $totals['avg_first_contact_seconds'] / MINUTE_IN_SECONDS;
		echo '</div><p class="description">' . esc_html( sprintf( __( 'متوسط الزمن من إنشاء الفرصة إلى أول تواصل: %s دقيقة', 'auto-dealership-core' ), null === $avg_minutes ? '—' : number_format_i18n( $avg_minutes, 1 ) ) ) . '</p>';

		echo '<h2>' . esc_html__( 'مؤشرات الفروع', 'auto-dealership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'الفرع', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'إجمالي الفرص', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'جديدة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'تم أول تواصل', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'متوسط أول تواصل (دقيقة)', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'متأخرة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'مباعة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'خاسرة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $report['branches'] as $row ) {
			echo '<tr><td>' . esc_html( $row['branch_name'] ?: __( 'بدون فرع', 'auto-dealership-core' ) ) . '</td><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['new_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['first_contact_count'] ) ) . '</td><td>' . esc_html( null === $row['avg_first_contact_seconds'] ? '—' : number_format_i18n( $row['avg_first_contact_seconds'] / MINUTE_IN_SECONDS, 1 ) ) . '</td><td>' . esc_html( number_format_i18n( $row['overdue_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['won_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['lost_count'] ) ) . '</td></tr>';
		}
		if ( ! $report['branches'] ) { echo '<tr><td colspan="8">' . esc_html__( 'لا توجد بيانات للفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'تعرض مؤشرات الأداء مجمعة حسب الفرع فقط؛ لا تتضمن هوية الموظف قبل اعتماد الجمهور والغرض.', 'auto-dealership-core' ) . '</p>';
		echo '<h2>' . esc_html__( 'توزيع المراحل حسب الفرع', 'auto-dealership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'الفرع', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المرحلة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'العدد', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $report['pipeline'] as $row ) {
			echo '<tr><td>' . esc_html( (string) ( array_column( $report['branches'], 'branch_name', 'branch_id' )[$row['branch_id']] ?? __( 'بدون فرع', 'auto-dealership-core' ) ) ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( $row['stage'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td></tr>';
		}
		if ( ! $report['pipeline'] ) { echo '<tr><td colspan="3">' . esc_html__( 'لا توجد مراحل مسجلة للفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table><h2>' . esc_html__( 'التحولات التاريخية بين المراحل حسب الفرع', 'auto-dealership-core' ) . '</h2><p class="description">' . esc_html__( 'يعرض الانتقالات المسجلة خلال الفترة؛ لا تتوفر بيانات انتقالات ما قبل تفعيل سجل المراحل. حصة كل وجهة هي نسبة الفرص الفريدة التي غادرت المرحلة المصدر ووصلت إليها؛ وقد يتجاوز مجموع حصص الوجهات 100% إذا انتقلت الفرصة إلى وجهات مختلفة خلال الفترة.', 'auto-dealership-core' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'الفرع', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'من مرحلة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'إلى مرحلة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'عدد الانتقالات', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'عدد الفرص', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'حصة الوجهة من المغادرين', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'متوسط الزمن بالساعة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $report['transitions'] as $row ) {
			echo '<tr><td>' . esc_html( $row['branch_name'] ?: __( 'بدون فرع', 'auto-dealership-core' ) ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( $row['from_stage'] ) ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( $row['to_stage'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['transition_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['lead_count'] ) ) . '</td><td>' . esc_html( null === $row['destination_share_percent'] ? '—' : number_format_i18n( $row['destination_share_percent'], 1 ) . '%' ) . '</td><td>' . esc_html( null === $row['avg_duration_seconds'] ? '—' : number_format_i18n( $row['avg_duration_seconds'] / HOUR_IN_SECONDS, 1 ) ) . '</td></tr>';
		}
		if ( ! $report['transitions'] ) { echo '<tr><td colspan="7">' . esc_html__( 'لا توجد انتقالات مسجلة للفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}

	public static function render_marketing_reports(): void {
		if ( ! current_user_can( 'adc_view_campaign_metrics' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض تقارير التسويق.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		global $wpdb; $table = \AutoDealership\Database\Schema::table( 'leads' ); list( $scope, $args ) = \AutoDealership\Security\BranchScope::predicate( 'l.branch_id' );
		$where = ' WHERE ' . $scope . ' AND l.campaign<>""'; $period = sanitize_key( $_GET['period'] ?? '' ); if ( 'today' === $period ) { $where .= ' AND l.created_at>=%s'; $args[] = gmdate( 'Y-m-d 00:00:00' ); } elseif ( '7days' === $period ) { $where .= ' AND l.created_at>=%s'; $args[] = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ); } elseif ( 'month' === $period ) { $where .= ' AND l.created_at>=%s'; $args[] = gmdate( 'Y-m-01 00:00:00' ); } $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.campaign,l.platform,l.stage,COUNT(*) total FROM ' . $table . ' l' . $where . ' GROUP BY l.campaign,l.platform,l.stage ORDER BY total DESC', $args ), ARRAY_A );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: campaign metrics query failed: ' . $wpdb->last_error ); wp_die( esc_html__( 'تعذر تحميل تقارير التسويق.', 'auto-dealership-core' ), '', array( 'response'=>503 ) ); return; }
		if ( ! is_array( $rows ) ) { wp_die( esc_html__( 'تعذر تحميل تقارير التسويق.', 'auto-dealership-core' ), '', array( 'response'=>503 ) ); return; }
		$summary = array( 'total'=>0, 'new'=>0, 'contacted'=>0, 'qualified'=>0, 'reserved'=>0, 'won'=>0, 'lost'=>0, 'overdue'=>0 );
		$activity_table = \AutoDealership\Database\Schema::table( 'activities' );
		$avg_first_followup = $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(TIMESTAMPDIFF(SECOND,l.created_at,a.first_activity)) FROM ' . $table . ' l INNER JOIN (SELECT lead_id,MIN(created_at) first_activity FROM ' . $activity_table . ' GROUP BY lead_id) a ON a.lead_id=l.id' . $where, $args ) );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: campaign follow-up metric query failed: ' . $wpdb->last_error ); wp_die( esc_html__( 'تعذر تحميل متوسط متابعة الحملات.', 'auto-dealership-core' ), '', array( 'response'=>503 ) ); return; }
		self::render_report_filters( $period );
		echo '<p class="description">' . esc_html__( 'مقاييس الأداء المعروضة مجمعة للحملة؛ لا تعرض اسم الموظف أو معرفه قبل اعتماد الجمهور والغرض.', 'auto-dealership-core' ) . '</p>';
		echo '<p class="description">' . esc_html( sprintf( __( 'متوسط زمن أول متابعة: %s ساعة', 'auto-dealership-core' ), $avg_first_followup ? number_format_i18n( (float) $avg_first_followup / HOUR_IN_SECONDS, 1 ) : '0' ) ) . '</p>';
		foreach ( $rows as $row ) { $count = (int) $row['total']; $summary['total'] += $count; if ( isset( $summary[ $row['stage'] ] ) ) { $summary[ $row['stage'] ] += $count; } }
		$overdue = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' l' . $where . " AND l.next_action_at IS NOT NULL AND l.next_action_at<=%s AND l.stage NOT IN ('won','lost')", array_merge( $args, array( current_time( 'mysql', true ) ) ) ) );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: campaign overdue metric query failed: ' . $wpdb->last_error ); wp_die( esc_html__( 'تعذر تحميل مؤشرات المتابعة المتأخرة.', 'auto-dealership-core' ), '', array( 'response'=>503 ) ); return; }
		$summary['overdue'] = (int) $overdue;
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
		if ( current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ) ) {
			echo '<form class="card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_update_lead_priority"><input type="hidden" name="lead_id" value="' . absint( $lead_id ) . '">';
			wp_nonce_field( 'adc_update_lead_priority_' . $lead_id );
			echo '<label>' . esc_html__( 'الأولوية', 'auto-dealership-core' ) . ' <select name="priority"><option value="low" ' . selected( 'low', $lead['priority'], false ) . '>' . esc_html__( 'منخفضة', 'auto-dealership-core' ) . '</option><option value="normal" ' . selected( 'normal', $lead['priority'], false ) . '>' . esc_html__( 'عادية', 'auto-dealership-core' ) . '</option><option value="high" ' . selected( 'high', $lead['priority'], false ) . '>' . esc_html__( 'عالية', 'auto-dealership-core' ) . '</option><option value="urgent" ' . selected( 'urgent', $lead['priority'], false ) . '>' . esc_html__( 'عاجلة', 'auto-dealership-core' ) . '</option></select></label> <button class="button">' . esc_html__( 'حفظ الأولوية', 'auto-dealership-core' ) . '</button></form>';
		}
		RequestPage::render( $lead_id );
		if ( 'assigned' === $lead['stage'] && empty( $lead['first_contact_at'] ) && ( current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ) ) ) {
			echo '<section class="card"><h2>' . esc_html__( 'تسجيل أول تواصل فعلي', 'auto-dealership-core' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_record_first_contact"><input type="hidden" name="lead_id" value="' . absint( $lead_id ) . '">';
			wp_nonce_field( 'adc_record_first_contact_' . $lead_id );
			echo '<p><label>' . esc_html__( 'نوع التواصل', 'auto-dealership-core' ) . ' <select name="type" required><option value="call">' . esc_html__( 'اتصال هاتفي', 'auto-dealership-core' ) . '</option><option value="whatsapp">WhatsApp</option><option value="email">' . esc_html__( 'رسالة', 'auto-dealership-core' ) . '</option><option value="meeting">' . esc_html__( 'زيارة', 'auto-dealership-core' ) . '</option><option value="other">' . esc_html__( 'أخرى', 'auto-dealership-core' ) . '</option></select></label> ';
			echo '<label>' . esc_html__( 'تاريخ ووقت التواصل', 'auto-dealership-core' ) . ' <input type="datetime-local" name="contacted_at" value="' . esc_attr( current_time( 'Y-m-d\TH:i' ) ) . '" required></label></p>';
			echo '<p><label>' . esc_html__( 'نتيجة التواصل', 'auto-dealership-core' ) . ' <input name="result" maxlength="500" required></label></p><p><label>' . esc_html__( 'ملاحظات مختصرة', 'auto-dealership-core' ) . ' <textarea name="notes" maxlength="4000" rows="3" required></textarea></label></p>';
			echo '<p><label>' . esc_html__( 'موعد المتابعة التالية (اختياري)', 'auto-dealership-core' ) . ' <input name="next_action_at" type="datetime-local"></label></p><button class="button button-primary">' . esc_html__( 'حفظ التواصل وتحديث المرحلة', 'auto-dealership-core' ) . '</button></form></section>';
		}
		if ( current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' ) ) {
			echo '<section class="card"><h2>' . esc_html__( 'إدارة الإسناد', 'auto-dealership-core' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_assign_lead"><input type="hidden" name="lead_id" value="' . absint( $lead_id ) . '">';
			wp_nonce_field( 'adc_assign_lead_' . $lead_id );
			echo '<label>' . esc_html__( 'الموظف الجديد', 'auto-dealership-core' ) . ' <select name="staff_id" required>';
			foreach ( self::sales_staff( (int) $lead['branch_id'] ) as $staff ) { echo '<option value="' . absint( $staff->ID ) . '" ' . selected( (int) $lead['owner_user_id'], (int) $staff->ID, false ) . '>' . esc_html( $staff->display_name ) . '</option>'; }
			echo '</select></label> <label>' . esc_html__( 'سبب الإسناد أو إعادة الإسناد', 'auto-dealership-core' ) . ' <input name="reason" maxlength="2000" required></label> <button class="button">' . esc_html__( 'حفظ الإسناد', 'auto-dealership-core' ) . '</button></form>';
			if ( 'assigned' === $lead['stage'] && empty( $lead['first_contact_at'] ) && (int) $lead['owner_user_id'] > 0 ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_release_lead_assignment"><input type="hidden" name="lead_id" value="' . absint( $lead_id ) . '">';
				wp_nonce_field( 'adc_release_lead_assignment_' . $lead_id );
				echo '<label>' . esc_html__( 'سبب إعادة الفرصة إلى قائمة الاستلام', 'auto-dealership-core' ) . ' <input name="reason" maxlength="2000" required></label> <button class="button">' . esc_html__( 'إعادة إلى القائمة المشتركة', 'auto-dealership-core' ) . '</button></form>';
			}
			echo '</section>';
		}
		CustomerIdentityPage::link_for_lead( $lead_id );
		echo '<h2>' . esc_html__( 'سجل المتابعة', 'auto-dealership-core' ) . ' #' . $lead_id . '</h2><p>' . esc_html__( 'التواريخ أدناه بالتوقيت العالمي UTC.', 'auto-dealership-core' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'التاريخ', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'النشاط', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'التفاصيل', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'النتيجة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المتابعة التالية', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'بواسطة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$actor = ! empty( $item['actor_user_id'] ) ? get_userdata( (int) $item['actor_user_id'] ) : null;
			echo '<tr><td>' . esc_html( $item['occurred_at'] ?: $item['created_at'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( (string) $item['type'] ) ) . '</td><td>' . nl2br( esc_html( $item['notes'] ) ) . '</td><td>' . esc_html( $item['result'] ?? '' ) . '</td><td>' . esc_html( $item['next_action_at'] ?? '—' ) . '</td><td>' . esc_html( $actor ? $actor->display_name : __( 'النظام', 'auto-dealership-core' ) ) . '</td></tr>';
		}
		if ( ! $items ) { echo '<tr><td colspan="6">' . esc_html__( 'لا توجد أنشطة في هذه الصفحة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table><p>';
		foreach ( array( $page - 1=>__( 'السابق', 'auto-dealership-core' ), $page + 1=>__( 'التالي', 'auto-dealership-core' ) ) as $target => $label ) {
			if ( $target < 1 || ( $target > $page && count( $items ) < 20 ) ) { continue; }
			$url = add_query_arg( array( 'page'=>'adc-crm', 'lead_id'=>$lead_id, 'activity_page'=>$target, 'paged'=>max( 1, absint( $_GET['paged'] ?? 1 ) ) ), admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( $label, 'auto-dealership-core' ) . '</a> ';
		}
		echo '</p>';
	}

	private static function sales_staff( int $branch_id ): array {
		$staff = get_users( array( 'role' => 'dealership_sales', 'number' => 500, 'fields' => array( 'ID', 'display_name' ) ) );
		return array_values( array_filter( $staff, static function ( $user ) use ( $branch_id ): bool {
			$user_id = (int) $user->ID;
			return user_can( $user_id, 'adc_manage_own_leads' ) && ( $branch_id > 0 ? BranchScope::allows( $branch_id, $user_id ) : ( current_user_can( 'manage_options' ) || (bool) array_filter( BranchScope::assigned_branches(), static fn( $allowed_branch ) => BranchScope::allows( (int) $allowed_branch, $user_id ) ) ) );
		} ) );
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
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_manage_own_leads' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية تغيير مراحل الفرص.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$result = LeadService::update_stage( $id, sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::redirect( 'adc-crm', $result );
	}

	public static function move_lead_stage_ajax(): void {
		check_ajax_referer( 'adc_crm_board', 'nonce' );
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_manage_own_leads' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => __( 'لا تملك صلاحية تغيير مراحل الفرص.', 'auto-dealership-core' ) ), 403 ); }
		$lead_id = absint( $_POST['lead_id'] ?? 0 );
		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) );
		$result = LeadService::update_stage( $lead_id, $stage, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) ); }
		wp_send_json_success( array( 'id'=>$lead_id, 'stage'=>$stage, 'allowed_stages'=>LeadService::allowed_stage_transitions( $stage ) ) );
	}

	public static function export_leads(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { wp_die( esc_html__( 'يجب إرسال طلب التصدير بطريقة POST.', 'auto-dealership-core' ), '', array( 'response' => 405 ) ); }
		if ( ! current_user_can( 'adc_export_crm_leads' ) || ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) ) { wp_die( esc_html__( 'لا تملك صلاحية تصدير الفرص.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_export_leads' );
		$post = wp_unslash( $_POST );
		$purpose = isset( $post['purpose'] ) && is_scalar( $post['purpose'] ) ? sanitize_key( (string) $post['purpose'] ) : '';
		$reason = isset( $post['reason'] ) && is_scalar( $post['reason'] ) ? sanitize_key( (string) $post['reason'] ) : '';
		if ( ! array_key_exists( $purpose, self::crm_export_purpose_options() ) || ! array_key_exists( $reason, self::crm_export_reason_options() ) ) {
			wp_die( esc_html__( 'اختر غرضًا وسببًا صالحين من القائمتين.', 'auto-dealership-core' ), '', array( 'response' => 400 ) );
		}
		$filters = self::crm_export_filters( $post );
		$audit_context = self::crm_export_audit_context( $filters, $purpose, $reason );
		global $wpdb;
		$count_exception_code = null;
		$previous_error_suppression = $wpdb->suppress_errors( true );
		try {
			$total_rows = LeadService::count_for_current_user( $filters );
		} catch ( \mysqli_sql_exception $exception ) {
			$count_exception_code = (int) $exception->getCode();
		} finally {
			$wpdb->suppress_errors( $previous_error_suppression );
		}
		if ( null !== $count_exception_code ) {
			error_log( 'Auto Dealership Core: CRM lead export count failed with database error ' . $count_exception_code . '.' );
			wp_die( esc_html__( 'تعذر حساب صفوف التصدير؛ لم يتم إنشاء الملف.', 'auto-dealership-core' ), '', array( 'response' => 503 ) );
		}
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: CRM lead export count failed: ' . $wpdb->last_error );
			wp_die( esc_html__( 'تعذر حساب صفوف التصدير؛ لم يتم إنشاء الملف.', 'auto-dealership-core' ), '', array( 'response' => 503 ) );
		}
		if ( $total_rows > self::CRM_EXPORT_MAX_ROWS ) {
			self::reject_crm_export_row_limit( $audit_context, $total_rows );
		}
		$export_rows = array();
		$max_pages = (int) ceil( ( self::CRM_EXPORT_MAX_ROWS + 1 ) / self::CRM_EXPORT_PAGE_SIZE );
		for ( $page = 1; $page <= $max_pages; ++$page ) {
			$page_exception_code = null;
			$previous_error_suppression = $wpdb->suppress_errors( true );
			try {
				$rows = LeadService::list_for_current_user( $page, self::CRM_EXPORT_PAGE_SIZE, $filters );
			} catch ( \mysqli_sql_exception $exception ) {
				$page_exception_code = (int) $exception->getCode();
			} finally {
				$wpdb->suppress_errors( $previous_error_suppression );
			}
			if ( null !== $page_exception_code ) {
				error_log( 'Auto Dealership Core: CRM lead export page failed with database error ' . $page_exception_code . '.' );
				wp_die( esc_html__( 'تعذر تحميل صفوف التصدير؛ لم يتم إنشاء الملف.', 'auto-dealership-core' ), '', array( 'response' => 503 ) );
			}
			if ( $wpdb->last_error ) {
				error_log( 'Auto Dealership Core: CRM lead export page failed: ' . $wpdb->last_error );
				wp_die( esc_html__( 'تعذر تحميل صفوف التصدير؛ لم يتم إنشاء الملف.', 'auto-dealership-core' ), '', array( 'response' => 503 ) );
			}
			if ( count( $export_rows ) + count( $rows ) > self::CRM_EXPORT_MAX_ROWS ) {
				self::reject_crm_export_row_limit( $audit_context, count( $export_rows ) + count( $rows ) );
			}
			array_push( $export_rows, ...$rows );
			if ( count( $rows ) < self::CRM_EXPORT_PAGE_SIZE ) { break; }
		}
		$audit_context['row_count'] = count( $export_rows );
		$audit_context['row_limit'] = self::CRM_EXPORT_MAX_ROWS;
		$export_columns = self::crm_export_columns( $purpose );
		$audit_context['export_fields'] = array_keys( $export_columns );
		if ( ! AuditLog::record( 'leads.csv_exported', 'lead_export', 0, 'CRM lead CSV export', null, $audit_context ) ) {
			wp_die( esc_html__( 'تعذر تسجيل عملية التصدير؛ لم يتم إنشاء الملف.', 'auto-dealership-core' ), '', array( 'response' => 500 ) );
		}
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename=crm-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo "\xEF\xBB\xBF"; $out = fopen( 'php://output', 'w' ); fputcsv( $out, array_values( $export_columns ) );
		foreach ( $export_rows as $row ) { fputcsv( $out, array_map( array( self::class, 'safe_csv_cell' ), array_map( static fn( string $field ) => $row[$field] ?? '', array_keys( $export_columns ) ) ) ); }
		fclose( $out ); exit;
	}

	private static function crm_export_purpose_options(): array {
		return array(
			'customer_follow_up' => __( 'متابعة العملاء', 'auto-dealership-core' ),
			'campaign_analysis' => __( 'تحليل الحملات', 'auto-dealership-core' ),
			'management_reporting' => __( 'تقارير الإدارة', 'auto-dealership-core' ),
			'compliance_review' => __( 'مراجعة الالتزام', 'auto-dealership-core' ),
			'data_quality_review' => __( 'مراجعة جودة البيانات', 'auto-dealership-core' ),
		);
	}

	private static function crm_export_reason_options(): array {
		return array(
			'assigned_work' => __( 'مهمة عمل مسندة', 'auto-dealership-core' ),
			'campaign_review' => __( 'مراجعة حملة', 'auto-dealership-core' ),
			'management_request' => __( 'طلب إداري', 'auto-dealership-core' ),
			'compliance_requirement' => __( 'متطلب امتثال', 'auto-dealership-core' ),
			'data_correction' => __( 'تصحيح بيانات', 'auto-dealership-core' ),
		);
	}

	private static function crm_export_columns( string $purpose ): array {
		$column_labels = array(
			'id' => 'ID',
			'full_name' => 'Customer',
			'mobile' => 'Mobile',
			'email' => 'Email',
			'source' => 'Source',
			'campaign' => 'Campaign',
			'platform' => 'Platform',
			'referred_at' => 'Referred at',
			'stage' => 'Stage',
			'created_at' => 'Created',
			'next_action_at' => 'Next follow-up',
		);
		$fields_by_purpose = array(
			'customer_follow_up' => array( 'id', 'full_name', 'mobile', 'source', 'stage', 'created_at', 'next_action_at' ),
			'campaign_analysis' => array( 'source', 'campaign', 'platform', 'stage', 'referred_at', 'created_at' ),
			'management_reporting' => array( 'source', 'stage', 'created_at' ),
			'compliance_review' => array( 'id', 'source', 'stage', 'referred_at', 'created_at' ),
			'data_quality_review' => array( 'id', 'full_name', 'mobile', 'email', 'source', 'campaign', 'platform', 'stage', 'created_at', 'next_action_at' ),
		);
		$columns = array();
		foreach ( $fields_by_purpose[$purpose] ?? array() as $field ) {
			$columns[$field] = $column_labels[$field];
		}
		return $columns;
	}

	private static function crm_export_filters( array $post ): array {
		$filters = array();
		foreach ( array( 's' => 'search', 'campaign' => 'campaign', 'platform' => 'platform' ) as $input_key => $filter_key ) {
			if ( isset( $post[$input_key] ) && is_scalar( $post[$input_key] ) ) { $filters[$filter_key] = sanitize_text_field( (string) $post[$input_key] ); }
		}
		foreach ( array( 'stage', 'priority', 'source' ) as $key ) {
			if ( isset( $post[$key] ) && is_scalar( $post[$key] ) ) { $filters[$key] = sanitize_key( (string) $post[$key] ); }
		}
		foreach ( array( 'branch_id', 'created_by' ) as $key ) {
			if ( isset( $post[$key] ) && is_scalar( $post[$key] ) ) { $filters[$key] = absint( $post[$key] ); }
		}
		if ( isset( $post['owner'] ) && is_scalar( $post['owner'] ) && '' !== (string) $post['owner'] ) { $filters['owner'] = absint( $post['owner'] ); }
		if ( ! empty( $post['overdue'] ) ) { $filters['overdue'] = 1; }
		return $filters;
	}

	private static function crm_export_audit_context( array $filters, string $purpose, string $reason ): array {
		$scope = 'owned_and_unassigned_new';
		$branch_ids = array();
		if ( current_user_can( 'manage_options' ) ) {
			$scope = 'all_branches';
		} elseif ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) ) {
			$scope = 'creator_owned_marketing_leads';
		} elseif ( current_user_can( 'adc_view_branch_leads' ) ) {
			$scope = 'assigned_branches';
			$branch_ids = array_values( array_filter( BranchScope::assigned_branches(), array( BranchScope::class, 'is_active' ) ) );
		}
		$safe_filter_keys = array( 'stage', 'priority', 'branch_id', 'source', 'created_by', 'owner', 'overdue' );
		return array(
			'purpose' => $purpose,
			'reason_code' => $reason,
			'scope' => $scope,
			'branch_ids' => $branch_ids,
			'filters' => array_intersect_key( $filters, array_flip( $safe_filter_keys ) ),
		);
	}

	private static function reject_crm_export_row_limit( array $audit_context, int $row_count ): void {
		$audit_context['row_count'] = $row_count;
		$audit_context['row_limit'] = self::CRM_EXPORT_MAX_ROWS;
		$audit_context['rejection'] = 'row_limit';
		if ( ! AuditLog::record( 'leads.csv_export_rejected', 'lead_export', 0, 'CRM lead CSV export exceeded technical row limit', null, $audit_context ) ) {
			wp_die( esc_html__( 'تعذر تسجيل رفض التصدير؛ لم يتم إنشاء الملف.', 'auto-dealership-core' ), '', array( 'response' => 500 ) );
		}
		wp_die( esc_html( sprintf( __( 'يتجاوز التصدير الحد التقني البالغ %d صف؛ قلّص التصفية ثم أعد المحاولة.', 'auto-dealership-core' ), self::CRM_EXPORT_MAX_ROWS ) ), '', array( 'response' => 413 ) );
	}

	private static function safe_csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	public static function create_marketing_lead(): void {
		if ( ! current_user_can( 'adc_create_marketing_leads' ) ) { wp_die( esc_html__( 'لا تملك صلاحية إنشاء فرص تسويقية.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_create_marketing_lead' );
		$campaign = sanitize_text_field( wp_unslash( $_POST['campaign'] ?? '' ) );
		$platform = sanitize_text_field( wp_unslash( $_POST['platform'] ?? '' ) );
		$branch_id = absint( $_POST['branch_id'] ?? 0 ); if ( ! $branch_id ) { $branch_id = \AutoDealership\Security\BranchScope::assigned_branch(); } $source = sanitize_key( wp_unslash( $_POST['source'] ?? 'marketing' ) ); $ad_url = esc_url_raw( wp_unslash( $_POST['ad_url'] ?? '' ) ); $consent = ! empty( $_POST['consent_marketing'] );
		if ( ! $consent ) { self::redirect( 'adc-crm', new \WP_Error( 'adc_consent_required', __( 'موافقة التواصل مطلوبة.', 'auto-dealership-core' ) ) ); }
		$idempotency_key = sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ?? '' ) );
		if ( '' !== $idempotency_key && ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $idempotency_key ) ) { self::redirect( 'adc-crm', new \WP_Error( 'adc_invalid_intake_key', __( 'مفتاح إعادة إرسال الطلب غير صالح.', 'auto-dealership-core' ) ) ); }
		if ( $branch_id && ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( $branch_id ) && ! current_user_can( 'manage_options' ) ) { self::redirect( 'adc-crm', new \WP_Error( 'adc_branch_forbidden', __( 'الفرع المحدد غير متاح لصلاحيتك.', 'auto-dealership-core' ) ) ); }
		global $wpdb; $mobile = sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ); $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$request_key = '' !== $idempotency_key ? hash_hmac( 'sha256', get_current_user_id() . '|' . strtolower( $idempotency_key ), wp_salt( 'auth' ) ) : '';
		$existing_request = null;
		if ( $request_key ) {
			$existing_request = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \AutoDealership\Database\Schema::table( 'leads' ) . ' WHERE public_request_key=%s LIMIT 1', $request_key ) );
			if ( $wpdb->last_error ) {
				error_log( 'Auto Dealership Core: CRM marketing retry lookup failed: ' . $wpdb->last_error );
				self::redirect( 'adc-crm', new \WP_Error( 'adc_marketing_duplicate_check_failed', __( 'تعذر التحقق من الفرص المشابهة؛ لم يتم إنشاء فرصة جديدة.', 'auto-dealership-core' ) ) );
			}
		}
		$duplicate = null;
		if ( ! $existing_request ) {
			list( $duplicate_scope, $duplicate_args ) = \AutoDealership\Security\BranchScope::predicate( 'l.branch_id' );
			if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $duplicate_scope = 'l.created_by=%d'; $duplicate_args = array( get_current_user_id() ); }
			$duplicate_sql = 'SELECT l.id FROM ' . \AutoDealership\Database\Schema::table( 'leads' ) . ' l INNER JOIN ' . \AutoDealership\Database\Schema::table( 'customers' ) . ' c ON c.id=l.customer_id WHERE ' . $duplicate_scope . ' AND l.stage NOT IN (\'won\',\'lost\') AND (c.mobile=%s OR (c.email<>"" AND c.email=%s)) LIMIT 1';
			$duplicate = $wpdb->get_var( $wpdb->prepare( $duplicate_sql, array_merge( $duplicate_args, array( $mobile, $email ) ) ) );
			if ( $wpdb->last_error ) {
				error_log( 'Auto Dealership Core: CRM marketing duplicate lookup failed: ' . $wpdb->last_error );
				self::redirect( 'adc-crm', new \WP_Error( 'adc_marketing_duplicate_check_failed', __( 'تعذر التحقق من الفرص المشابهة؛ لم يتم إنشاء فرصة جديدة.', 'auto-dealership-core' ) ) );
			}
		}
		if ( $duplicate ) {
			$activity = LeadService::add_activity( (int) $duplicate, 'note', trim( 'Duplicate marketing touchpoint. Campaign: ' . $campaign . "\nPlatform: " . $platform . "\nSource: " . $source . "\nAd URL: " . $ad_url ) );
			self::redirect( 'adc-crm', $activity );
		}
		$result = LeadService::create_marketing(
			array(
				'name'=>sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'mobile'=>$mobile,
				'email'=>$email,
				'city'=>sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
				'branch_id'=>$branch_id,
				'consent_marketing'=>true,
				'idempotency_key'=>$idempotency_key,
			),
			array(
				'campaign'=>$campaign,
				'platform'=>$platform,
				'source'=>$source,
				'ad_url'=>$ad_url,
				'notes'=>sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
				'staff_id'=>absint( $_POST['staff_id'] ?? 0 ),
			)
		);
		self::redirect( 'adc-crm', $result );
	}

	public static function assign_lead(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_assign_lead_' . $id );
		if ( ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية إسناد الفرص.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		self::redirect( 'adc-crm', LeadService::assign( $id, absint( $_POST['staff_id'] ?? 0 ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) ) );
	}

	public static function release_lead_assignment(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_release_lead_assignment_' . $id );
		self::redirect( 'adc-crm', LeadService::release_assignment( $id, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) ) );
	}

	public static function claim_lead(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_claim_lead_' . $id );
		self::redirect( 'adc-crm', LeadService::claim( $id ) );
	}

	public static function record_first_contact(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_record_first_contact_' . $id );
		$result = LeadService::record_first_contact(
			$id,
			sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['contacted_at'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['result'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			self::follow_up_input()
		);
		self::redirect( 'adc-crm', $result );
	}

	public static function update_lead_priority(): void {
		$id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'adc_update_lead_priority_' . $id );
		$result = LeadService::set_priority( $id, sanitize_key( wp_unslash( $_POST['priority'] ?? '' ) ) );
		self::redirect( 'adc-crm', $result );
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
