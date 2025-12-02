<?php
/**
 * Analytics Status Page - Dashboard for BOM Analytics integration
 *
 * @package AtumPLAnalyticsHelper\Analytics
 * @author  BE REBEL - https://berebel.studio
 * @since   1.0.0
 */

namespace AtumPLAnalyticsHelper\Analytics;

defined( 'ABSPATH' ) || die;

class StatusPage {

	/**
	 * The singleton instance holder
	 *
	 * @var StatusPage
	 */
	private static $instance;

	/**
	 * The admin page hook
	 *
	 * @var string
	 */
	private $page_hook;

	/**
	 * StatusPage constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Register admin menu hook - use priority 100 to ensure it runs after ATUM/WooCommerce menus
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 100 );
		
		// Register other admin hooks
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_atum_pl_analytics_backfill', array( $this, 'ajax_backfill' ) );
		add_action( 'wp_ajax_atum_pl_analytics_progress', array( $this, 'ajax_get_progress' ) );
		add_action( 'wp_ajax_atum_pl_analytics_clear', array( $this, 'ajax_clear' ) );
		add_action( 'wp_ajax_atum_pl_analytics_test_sync', array( $this, 'ajax_test_sync' ) );
	}

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cheatin&#8217; huh?', ATUM_PL_ANALYTICS_TEXT_DOMAIN ), '1.0.0' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cheatin&#8217; huh?', ATUM_PL_ANALYTICS_TEXT_DOMAIN ), '1.0.0' );
	}

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 *
	 * @return StatusPage
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Add menu page under ATUM or WooCommerce
	 *
	 * @since 1.0.0
	 */
	public function add_menu_page() {

		// Final check - if BOMOrderItemsModel still doesn't exist, don't register menu
		// This allows menu to register even if class wasn't found at plugins_loaded time
		if ( ! class_exists( '\AtumLevels\Models\BOMOrderItemsModel' ) && ! class_exists( 'AtumLevels\Models\BOMOrderItemsModel' ) ) {
			return;
		}

		// Check if ATUM menu exists, otherwise use WooCommerce menu.
		global $menu, $submenu;
		$parent_slug = 'woocommerce'; // Default to WooCommerce
		
		// Check if ATUM menu exists by checking both menu and submenu arrays.
		// ATUM typically registers its menu with 'atum-dashboard' slug.
		$atum_menu_exists = false;
		if ( isset( $menu ) && is_array( $menu ) ) {
			foreach ( $menu as $menu_item ) {
				if ( isset( $menu_item[2] ) && 'atum-dashboard' === $menu_item[2] ) {
					$atum_menu_exists = true;
					break;
				}
			}
		}
		
		if ( $atum_menu_exists || ( isset( $submenu['atum-dashboard'] ) && is_array( $submenu['atum-dashboard'] ) ) ) {
			$parent_slug = 'atum-dashboard';
		}

		// Try to add as submenu first.
		$hook = add_submenu_page(
			$parent_slug,
			__( 'BOM Analytics Status', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			__( 'BOM Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			'manage_woocommerce',
			'atum-bom-analytics',
			array( $this, 'render_page' )
		);

		// If submenu failed (parent doesn't exist), create as top-level menu.
		if ( ! $hook ) {
			$hook = add_menu_page(
				__( 'BOM Analytics Status', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				__( 'BOM Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				'manage_woocommerce',
				'atum-bom-analytics',
				array( $this, 'render_page' ),
				'dashicons-chart-line',
				56
			);
		}

		// Store the hook for script enqueuing.
		if ( $hook ) {
			$this->page_hook = $hook;
		}
	}

	/**
	 * Enqueue scripts and styles
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {

		// Check for all possible hook names (ATUM menu, WooCommerce menu, or top-level menu).
		// Also check against stored page hook if available.
		$valid_hooks = array(
			'atum-inventory_page_atum-bom-analytics',
			'woocommerce_page_atum-bom-analytics',
			'toplevel_page_atum-bom-analytics',
		);
		
		if ( ! empty( $this->page_hook ) ) {
			$valid_hooks[] = $this->page_hook;
		}

		if ( ! in_array( $hook, $valid_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'atum-pl-analytics-status', ATUM_PL_ANALYTICS_URL . 'assets/css/analytics-status.css', array(), ATUM_PL_ANALYTICS_VERSION );
		wp_enqueue_script( 'atum-pl-analytics-status', ATUM_PL_ANALYTICS_URL . 'assets/js/analytics-status.js', array( 'jquery' ), ATUM_PL_ANALYTICS_VERSION, true );

		wp_localize_script( 'atum-pl-analytics-status', 'atumPLAnalytics', array(
			'nonce'    => wp_create_nonce( 'atum-pl-analytics' ),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'messages' => array(
				'backfillStarted'  => __( 'Backfill started...', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				'backfillComplete' => __( 'Backfill completed!', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				'clearComplete'    => __( 'Analytics cleared!', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				'testComplete'     => __( 'Test sync completed!', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				'error'            => __( 'An error occurred.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			),
		) );
	}

	/**
	 * Render the status page
	 *
	 * @since 1.0.0
	 */
	public function render_page() {

		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) );
		}

		$sync_status     = Sync::get_instance()->get_sync_status();
		$backfill_status = BackfillTool::get_instance()->get_progress();
		$health_checks   = $this->run_health_checks();

		?>
		<div class="wrap atum-pl-analytics-status">
			<h1><?php esc_html_e( 'BOM Analytics Integration Status', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h1>

			<!-- Health Checks -->
			<div class="atum-pl-status-section">
				<h2><?php esc_html_e( 'Integration Health', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $health_checks as $check ) : ?>
							<tr>
								<td style="width: 50px;">
									<span class="atum-status-indicator <?php echo $check['status'] ? 'success' : 'error'; ?>">
										<?php echo $check['status'] ? '&check;' : '&cross;'; ?>
									</span>
								</td>
								<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
								<td><?php echo esc_html( $check['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Sync Statistics -->
			<div class="atum-pl-status-section">
				<h2><?php esc_html_e( 'Sync Statistics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h2>
				<table class="widefat">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Total BOM Records:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $sync_status['total_boms'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Synced to Analytics:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $sync_status['synced_boms'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Sync Coverage:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
							<td>
								<strong><?php echo esc_html( $sync_status['sync_percent'] ); ?>%</strong>
								<div class="atum-progress-bar">
									<div class="atum-progress-fill" style="width: <?php echo esc_attr( $sync_status['sync_percent'] ); ?>%;"></div>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Backfill Status -->
			<div class="atum-pl-status-section">
				<h2><?php esc_html_e( 'Historical Data Backfill', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h2>
				<table class="widefat">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Status:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
							<td>
								<span class="atum-backfill-status <?php echo esc_attr( $backfill_status['status'] ); ?>">
									<?php
									switch ( $backfill_status['status'] ) {
										case 'idle':
											esc_html_e( 'Not Started', ATUM_PL_ANALYTICS_TEXT_DOMAIN );
											break;
										case 'running':
											esc_html_e( 'In Progress', ATUM_PL_ANALYTICS_TEXT_DOMAIN );
											break;
										case 'completed':
											esc_html_e( 'Completed', ATUM_PL_ANALYTICS_TEXT_DOMAIN );
											break;
									}
									?>
								</span>
							</td>
						</tr>
						<?php if ( 'idle' !== $backfill_status['status'] ) : ?>
							<tr>
								<th><?php esc_html_e( 'Progress:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
								<td id="backfill-progress-text">
									<?php
									echo esc_html( sprintf(
										/* translators: %1$d: processed count, %2$d: total count, %3$s: percentage */
										__( '%1$d / %2$d orders (%3$s%%)', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
										$backfill_status['processed'],
										$backfill_status['total'],
										$backfill_status['percent']
									) );
									?>
									<div class="atum-progress-bar" style="margin-top: 8px;">
										<div class="atum-progress-fill" id="backfill-progress-bar" style="width: <?php echo esc_attr( $backfill_status['percent'] ); ?>%;"></div>
									</div>
								</td>
							</tr>
							<?php if ( $backfill_status['started'] ) : ?>
								<tr>
									<th><?php esc_html_e( 'Started:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
									<td><?php echo esc_html( $backfill_status['started'] ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( $backfill_status['completed'] ) : ?>
								<tr>
									<th><?php esc_html_e( 'Completed:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
									<td><?php echo esc_html( $backfill_status['completed'] ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Recent Synced BOMs -->
			<?php $recent_boms = $this->get_recent_synced_boms(); ?>
			<?php if ( ! empty( $recent_boms ) ) : ?>
				<div class="atum-pl-status-section">
					<h2><?php esc_html_e( 'Recently Synced BOMs', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h2>
					<p><?php esc_html_e( 'Use these to verify BOMs appear in WooCommerce Analytics:', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product ID', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
								<th><?php esc_html_e( 'Product Name', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
								<th><?php esc_html_e( 'Product Type', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
								<th><?php esc_html_e( 'Order', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
								<th><?php esc_html_e( 'Quantity', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
								<th><?php esc_html_e( 'View in Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_boms as $bom ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $bom->product_id ); ?></strong></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $bom->product_id ) ); ?>" target="_blank">
											<?php echo esc_html( $bom->product_name ); ?>
										</a>
									</td>
									<td><span class="atum-product-type-badge"><?php echo esc_html( ucwords( str_replace( '-', ' ', $bom->product_type ) ) ); ?></span></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $bom->order_id . '&action=edit' ) ); ?>" target="_blank">
											#<?php echo esc_html( $bom->order_id ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $bom->product_qty ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/products&filter=single_product&products=' . $bom->product_id ) ); ?>"
										   class="button button-small" target="_blank">
											<?php esc_html_e( 'View in Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<!-- Actions -->
			<div class="atum-pl-status-section">
				<h2><?php esc_html_e( 'Actions', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h2>
				<p>
					<button type="button" class="button button-primary" id="atum-backfill-btn">
						<?php esc_html_e( 'Run Historical Backfill', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?>
					</button>
					<button type="button" class="button" id="atum-test-sync-btn">
						<?php esc_html_e( 'Test Sync (Latest Order)', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?>
					</button>
					<button type="button" class="button button-secondary" id="atum-clear-analytics-btn">
						<?php esc_html_e( 'Clear BOM Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?>
					</button>
				</p>
				<div id="atum-action-result" class="notice" style="display: none;"></div>
			</div>

			<!-- Troubleshooting -->
			<div class="atum-pl-status-section">
				<h2><?php esc_html_e( 'Troubleshooting', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'If sync coverage is low, run the historical backfill.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></li>
					<li><?php esc_html_e( 'Test sync will process the most recent order to verify integration.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></li>
					<li><?php esc_html_e( 'Clear analytics will remove all BOM data from WooCommerce Analytics (can be restored with backfill).', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: WooCommerce Analytics URL */
							esc_html__( 'View BOM products in %s', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/products' ) ) . '" target="_blank">' . esc_html__( 'WooCommerce Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) . '</a>'
						);
						?>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Get recently synced BOMs for display
	 *
	 * @since 1.0.0
	 *
	 * @return array Recent BOM records.
	 */
	private function get_recent_synced_boms() {

		global $wpdb;

		// First, get the 10 most recent distinct orders with BOMs
		// This ensures we get 10 different orders, not 10 BOM items (which could be from 2 orders)
		$recent_order_ids = $wpdb->get_col(
			"SELECT DISTINCT wpl.order_id
			FROM {$wpdb->prefix}wc_order_product_lookup wpl
			INNER JOIN {$wpdb->prefix}atum_product_data apd ON wpl.product_id = apd.product_id
			WHERE apd.is_bom = 1
			ORDER BY wpl.date_created DESC
			LIMIT 10"
		);

		if ( empty( $recent_order_ids ) ) {
			return array();
		}

		// Then get one BOM item from each of those orders
		// Using a subquery to get the first (lowest order_item_id) BOM from each order
		$placeholders = implode( ',', array_fill( 0, count( $recent_order_ids ), '%d' ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					wpl.product_id,
					wpl.order_id,
					wpl.product_qty,
					wpl.date_created,
					p.post_title as product_name,
					CASE
						WHEN p.post_type = 'product_variation' THEN 'Product Part Variation'
						ELSE COALESCE(t.slug, 'product-part')
					END as product_type
				FROM {$wpdb->prefix}wc_order_product_lookup wpl
				INNER JOIN {$wpdb->posts} p ON wpl.product_id = p.ID
				INNER JOIN {$wpdb->prefix}atum_product_data apd ON wpl.product_id = apd.product_id
				LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_type'
				LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				INNER JOIN (
					SELECT order_id, MIN(order_item_id) as first_item_id
					FROM {$wpdb->prefix}wc_order_product_lookup wpl2
					INNER JOIN {$wpdb->prefix}atum_product_data apd2 ON wpl2.product_id = apd2.product_id
					WHERE apd2.is_bom = 1
						AND wpl2.order_id IN ($placeholders)
					GROUP BY wpl2.order_id
				) first_bom ON wpl.order_id = first_bom.order_id 
					AND wpl.order_item_id = first_bom.first_item_id
				WHERE apd.is_bom = 1
				ORDER BY wpl.date_created DESC",
				$recent_order_ids
			)
		);

		return $results;
	}

	/**
	 * Run health checks
	 *
	 * @since 1.0.0
	 *
	 * @return array Health check results.
	 */
	private function run_health_checks() {

		global $wpdb;

		$checks = array();

		// 1. Check if hooks are registered.
		$hooks_registered = has_filter( 'woocommerce_analytics_products_excluded_product_types' );
		$checks[]         = array(
			'label'   => __( 'Product Types Registered', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			'status'  => $hooks_registered,
			'message' => $hooks_registered
				? __( 'Product parts are registered with Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN )
				: __( 'Product parts are NOT registered', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
		);

		// 2. Check if Analytics table exists.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_order_product_lookup'" ) === "{$wpdb->prefix}wc_order_product_lookup";
		$checks[]     = array(
			'label'   => __( 'Analytics Tables Exist', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			'status'  => $table_exists,
			'message' => $table_exists
				? __( 'WooCommerce Analytics tables found', ATUM_PL_ANALYTICS_TEXT_DOMAIN )
				: __( 'Analytics tables missing - please enable WooCommerce Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
		);

		// 3. Check if BOM table exists.
		$bom_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}atum_order_boms'" ) === "{$wpdb->prefix}atum_order_boms";
		$checks[]         = array(
			'label'   => __( 'BOM Tables Exist', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			'status'  => $bom_table_exists,
			'message' => $bom_table_exists
				? __( 'ATUM BOM tables found', ATUM_PL_ANALYTICS_TEXT_DOMAIN )
				: __( 'BOM tables missing', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
		);

		// 4. Check recent order sync.
		if ( $table_exists && $bom_table_exists ) {
			$recent_order = wc_get_orders( array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
			) );

			if ( ! empty( $recent_order ) ) {
				$order_id  = $recent_order[0]->get_id();
				$has_boms  = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}atum_order_boms aob
						INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON aob.order_item_id = oi.order_item_id
						WHERE oi.order_id = %d",
						$order_id
					)
				);
				$synced    = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_product_lookup
						WHERE order_id = %d",
						$order_id
					)
				);
				$is_synced = $has_boms > 0 && $synced > 0;

				$checks[] = array(
					'label'   => __( 'Recent Orders Tracking', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
					'status'  => $is_synced || $has_boms == 0,
					'message' => $has_boms > 0
						? ( $is_synced ? __( 'Latest order BOMs are synced', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) : __( 'Latest order BOMs are NOT synced', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) )
						: __( 'Latest order has no BOMs', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				);
			}
		}

		// 5. Check sync coverage.
		$sync_status      = Sync::get_instance()->get_sync_status();
		$coverage_healthy = $sync_status['sync_percent'] >= 80;
		$checks[]         = array(
			'label'   => __( 'Sync Coverage', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
			'status'  => $coverage_healthy,
			'message' => sprintf(
				/* translators: %s: sync percentage */
				__( '%s%% of BOMs are synced', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				$sync_status['sync_percent']
			),
		);

		return $checks;
	}

	/**
	 * AJAX handler for backfill
	 *
	 * @since 1.0.0
	 */
	public function ajax_backfill() {

		check_ajax_referer( 'atum-pl-analytics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) ) );
		}

		$result = BackfillTool::get_instance()->start_backfill();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX handler for getting backfill progress
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_progress() {

		check_ajax_referer( 'atum-pl-analytics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) ) );
		}

		$progress    = BackfillTool::get_instance()->get_progress();
		$sync_status = Sync::get_instance()->get_sync_status();

		wp_send_json_success( array(
			'progress' => $progress,
			'sync'     => $sync_status,
		) );
	}

	/**
	 * AJAX handler for clearing analytics
	 *
	 * @since 1.0.0
	 */
	public function ajax_clear() {

		check_ajax_referer( 'atum-pl-analytics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) ) );
		}

		$result = BackfillTool::get_instance()->clear_analytics();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX handler for test sync
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_sync() {

		check_ajax_referer( 'atum-pl-analytics', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) ) );
		}

		// Get latest order.
		$orders = wc_get_orders( array(
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		if ( empty( $orders ) ) {
			wp_send_json_error( array( 'message' => __( 'No orders found.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ) ) );
		}

		$order_id = $orders[0]->get_id();
		$result   = Sync::get_instance()->sync_bom_to_analytics( $order_id );

		if ( $result ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %d: order ID */
					__( 'Test sync completed for order #%d', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
					$order_id
				),
			) );
		} else {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %d: order ID */
					__( 'No BOMs found in order #%d', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
					$order_id
				),
			) );
		}
	}

}

