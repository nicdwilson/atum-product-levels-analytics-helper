<?php
/**
 * Analytics Sync - Syncs BOM data to WooCommerce Analytics
 *
 * @package AtumPLAnalyticsHelper\Analytics
 * @author  BE REBEL - https://berebel.studio
 * @since   1.0.0
 */

namespace AtumPLAnalyticsHelper\Analytics;

defined( 'ABSPATH' ) || die;

class Sync {

	/**
	 * The singleton instance holder
	 *
	 * @var Sync
	 */
	private static $instance;

	/**
	 * Sync constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Hook into WooCommerce Analytics to include product parts.
		add_filter( 'woocommerce_analytics_products_excluded_product_types', array( $this, 'include_product_parts_in_analytics' ) );

		// Ensure product parts are tracked in order items.
		add_filter( 'woocommerce_order_item_product', array( $this, 'ensure_product_part_tracking' ), 10, 2 );

		// Register order processing hooks (replacing Orders.php integration).
		$this->register_order_hooks();
	}

	/**
	 * Register hooks for order processing
	 * These hooks replace the direct code modification in Orders.php
	 *
	 * @since 1.0.0
	 */
	private function register_order_hooks() {
		// Hook into Purchase Order stock level changes.
		add_action( 'atum/purchase_orders/po/after_decrease_stock_levels', array( $this, 'sync_order_boms' ), 20 );
		add_action( 'atum/purchase_orders/po/after_increase_stock_levels', array( $this, 'sync_order_boms' ), 20 );

		// Hook into WooCommerce order item saves.
		add_action( 'woocommerce_saved_order_items', array( $this, 'sync_order_boms_by_id' ), 20 );

		// Hook into order status changes.
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order_boms_on_status_change' ), 20, 4 );

		// Hook into payment completion.
		add_action( 'woocommerce_payment_complete', array( $this, 'sync_order_boms_by_id' ), 20 );
	}

	/**
	 * Sync order BOMs (callback for order object hooks)
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order|int $order Order object or order ID.
	 */
	public function sync_order_boms( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( $order ) {
			$this->sync_bom_to_analytics( $order->get_id() );
		}
	}

	/**
	 * Sync order BOMs by order ID (callback for order ID hooks)
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function sync_order_boms_by_id( $order_id ) {
		$this->sync_bom_to_analytics( $order_id );
	}

	/**
	 * Sync order BOMs on status change
	 *
	 * @since 1.0.0
	 *
	 * @param int       $order_id   Order ID.
	 * @param string    $old_status  Old order status.
	 * @param string    $new_status  New order status.
	 * @param \WC_Order $order       Order object.
	 */
	public function sync_order_boms_on_status_change( $order_id, $old_status, $new_status, $order ) {
		// Only sync on status changes that affect stock.
		$sync_statuses = array( 'processing', 'completed', 'cancelled', 'refunded' );
		if ( in_array( $new_status, $sync_statuses, true ) ) {
			$this->sync_bom_to_analytics( $order_id );
		}
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
	 * @return Sync
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Include product parts in WooCommerce Analytics
	 *
	 * @since 1.0.0
	 *
	 * @param array $excluded_types Excluded product types.
	 *
	 * @return array
	 */
	public function include_product_parts_in_analytics( $excluded_types ) {

		// Ensure our product types are NOT in the excluded list.
		$bom_types = array( 'product-part', 'variable-product-part', 'raw-material', 'variable-raw-material' );

		return array_diff( $excluded_types, $bom_types );
	}

	/**
	 * Ensure product parts are tracked in order items
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product|false $product Product object or false.
	 * @param \WC_Order_Item    $item    Order item object.
	 *
	 * @return \WC_Product|false
	 */
	public function ensure_product_part_tracking( $product, $item ) {

		// Check if main plugin class exists.
		if ( ! class_exists( '\AtumLevels\Inc\Globals' ) ) {
			return $product;
		}

		if ( $product && in_array( $product->get_type(), \AtumLevels\Inc\Globals::get_product_levels(), true ) ) {
			// Product parts should be tracked.
			return $product;
		}

		return $product;
	}

	/**
	 * Sync BOM consumption to WooCommerce Analytics
	 *
	 * @since 1.0.0
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $is_backfill Optional. Whether this is called from backfill operation. Default false.
	 *
	 * @return bool Success status.
	 */
	public function sync_bom_to_analytics( $order_id, $is_backfill = false ) {

		global $wpdb;

		// Check if main plugin class exists.
		if ( ! class_exists( '\AtumLevels\Models\BOMOrderItemsModel' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		// Get all order items.
		$order_items = $order->get_items();

		if ( empty( $order_items ) ) {
			return false;
		}

		$synced = 0;

		foreach ( $order_items as $item_id => $item ) {

			// Get BOM data for this order item.
			$bom_items = \AtumLevels\Models\BOMOrderItemsModel::get_bom_order_items( $item_id, 1, true );

			if ( empty( $bom_items ) ) {
				continue;
			}

			foreach ( $bom_items as $bom_item ) {
				$this->sync_single_bom_item( $order_id, $order, $item_id, $bom_item );
				$synced++;
			}
		}

		return $synced > 0;
	}

	/**
	 * Sync a single BOM item to analytics
	 *
	 * @since 1.0.0
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order object.
	 * @param int       $item_id  Order item ID.
	 * @param object    $bom_item BOM item data.
	 *
	 * @return bool Success status.
	 */
	private function sync_single_bom_item( $order_id, $order, $item_id, $bom_item ) {

		global $wpdb;

		// Ensure item_id and bom_id are integers for consistent synthetic ID calculation
		$item_id_int = (int) $item_id;
		$bom_id_int  = (int) $bom_item->bom_id;

		// Create synthetic order_item_id to avoid PRIMARY KEY conflict
		// Format: original_item_id + bom_id (ensures uniqueness)
		// Example: 793171 + 342255 = 793171342255
		$synthetic_item_id = (int) ( $item_id_int . $bom_id_int );

		// Check if already synced using synthetic ID (primary check)
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT order_item_id FROM {$wpdb->prefix}wc_order_product_lookup
				WHERE order_id = %d AND product_id = %d AND order_item_id = %d",
				$order_id,
				$bom_id_int,
				$synthetic_item_id
			)
		);

		// If not found by synthetic ID, check for any existing records with same order_id + product_id
		// This handles cases where synthetic ID might have been calculated differently or duplicates exist
		if ( ! $exists ) {
			// Delete any existing records for this order_id + product_id combination
			// We'll insert the correct one with the proper synthetic ID
			$wpdb->delete(
				"{$wpdb->prefix}wc_order_product_lookup",
				array(
					'order_id'   => $order_id,
					'product_id' => $bom_id_int,
				),
				array( '%d', '%d' )
			);
			$exists = false; // Will insert new record with correct synthetic ID
		}

		// Get product to check if it still exists.
		$product = wc_get_product( $bom_id_int );

		if ( ! $product ) {
			return false;
		}

		$date_created = $order->get_date_created();

		if ( ! $date_created ) {
			$date_created = new \WC_DateTime();
		}

		$data = array(
			'order_id'              => $order_id,
			'product_id'            => $bom_id_int,
			'variation_id'          => 0,
			'customer_id'           => $order->get_customer_id() ?: 0,
			'order_item_id'         => $synthetic_item_id,
			'product_qty'           => (float) $bom_item->qty,
			'product_net_revenue'  => 0, // BOMs are components, not sold directly.
			'product_gross_revenue' => 0,
			'date_created'          => $date_created->date( 'Y-m-d H:i:s' ),
			'coupon_amount'         => 0,
			'tax_amount'            => 0,
			'shipping_amount'       => 0,
			'shipping_tax_amount'   => 0,
		);

		if ( $exists ) {
			// Update existing record.
			$result = $wpdb->update(
				"{$wpdb->prefix}wc_order_product_lookup",
				$data,
				array(
					'order_id'      => $order_id,
					'product_id'    => $bom_id_int,
					'order_item_id' => $synthetic_item_id,
				),
				array(
					'%d', // order_id.
					'%d', // product_id.
					'%d', // variation_id.
					'%d', // customer_id.
					'%d', // order_item_id.
					'%f', // product_qty.
					'%f', // product_net_revenue.
					'%f', // product_gross_revenue.
					'%s', // date_created.
					'%f', // coupon_amount.
					'%f', // tax_amount.
					'%f', // shipping_amount.
					'%f', // shipping_tax_amount.
				),
				array( '%d', '%d', '%d' )
			);
		} else {
			// Insert new record.
			$result = $wpdb->insert(
				"{$wpdb->prefix}wc_order_product_lookup",
				$data,
				array(
					'%d', // order_id.
					'%d', // product_id.
					'%d', // variation_id.
					'%d', // customer_id.
					'%d', // order_item_id.
					'%f', // product_qty.
					'%f', // product_net_revenue.
					'%f', // product_gross_revenue.
					'%s', // date_created.
					'%f', // coupon_amount.
					'%f', // tax_amount.
					'%f', // shipping_amount.
					'%f', // shipping_tax_amount.
				)
			);
		}

		do_action( 'atum/product_levels/analytics/bom_synced', $order_id, $bom_id_int, $result );

		return false !== $result;
	}

	/**
	 * Remove BOM analytics data for an order (for refunds/cancellations)
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool Success status.
	 */
	public function remove_bom_from_analytics( $order_id ) {

		global $wpdb;

		// Get all BOM product IDs for this order.
		$bom_product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT bom_id FROM {$wpdb->prefix}atum_order_boms aob
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON aob.order_item_id = oi.order_item_id
				WHERE oi.order_id = %d",
				$order_id
			)
		);

		if ( empty( $bom_product_ids ) ) {
			return false;
		}

		// Delete analytics records for these BOMs.
		$placeholders = implode( ',', array_fill( 0, count( $bom_product_ids ), '%d' ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wc_order_product_lookup
				WHERE order_id = %d AND product_id IN ($placeholders)",
				array_merge( array( $order_id ), $bom_product_ids )
			)
		);

		do_action( 'atum/product_levels/analytics/bom_removed', $order_id, $bom_product_ids );

		return false !== $result;
	}

	/**
	 * Remove duplicate BOM records from analytics
	 * For each order_id + product_id combination, keeps only one record
	 * The sync process will recreate them with correct synthetic IDs
	 *
	 * @since 1.0.0
	 *
	 * @return array Result with count of removed duplicates.
	 */
	public function remove_duplicates() {

		global $wpdb;

		// Find duplicates: same order_id + product_id but different order_item_id
		// Delete all but one (we'll keep the one with the lowest order_item_id as a placeholder)
		$duplicates_removed = $wpdb->query(
			"DELETE wpl1 FROM {$wpdb->prefix}wc_order_product_lookup wpl1
			INNER JOIN {$wpdb->prefix}atum_product_data apd ON wpl1.product_id = apd.product_id
			INNER JOIN (
				SELECT order_id, product_id, MIN(order_item_id) as min_item_id
				FROM {$wpdb->prefix}wc_order_product_lookup wpl2
				INNER JOIN {$wpdb->prefix}atum_product_data apd2 ON wpl2.product_id = apd2.product_id
				WHERE apd2.is_bom = 1
				GROUP BY order_id, product_id
				HAVING COUNT(*) > 1
			) dup ON wpl1.order_id = dup.order_id 
				AND wpl1.product_id = dup.product_id
				AND wpl1.order_item_id > dup.min_item_id
			WHERE apd.is_bom = 1"
		);

		return array(
			'success' => true,
			'removed' => (int) $duplicates_removed,
			'message' => sprintf(
				/* translators: %d: number of duplicates removed */
				__( 'Removed %d duplicate BOM records.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				$duplicates_removed
			),
		);
	}

	/**
	 * Get sync status for analytics integration
	 *
	 * @since 1.0.0
	 *
	 * @return array Status information.
	 */
	public function get_sync_status() {

		global $wpdb;

		// Count total BOM records (distinct order_item_id + bom_id combinations).
		$total_boms = $wpdb->get_var(
			"SELECT COUNT(DISTINCT CONCAT(aob.order_item_id, '-', aob.bom_id))
			FROM {$wpdb->prefix}atum_order_boms aob"
		);

		// Count synced BOM records in analytics (distinct by order_id + product_id + order_item_id).
		// This ensures we count actual synced records, not duplicates.
		$synced_boms = $wpdb->get_var(
			"SELECT COUNT(DISTINCT CONCAT(wpl.order_id, '-', wpl.product_id, '-', wpl.order_item_id))
			FROM {$wpdb->prefix}wc_order_product_lookup wpl
			INNER JOIN {$wpdb->prefix}atum_product_data apd ON wpl.product_id = apd.product_id
			WHERE apd.is_bom = 1"
		);

		// Check if hooks are registered.
		$hooks_active = has_filter( 'woocommerce_analytics_products_excluded_product_types', array( $this, 'include_product_parts_in_analytics' ) );

		// Calculate sync percentage (cap at 100% to handle duplicates during cleanup).
		$sync_percent = $total_boms > 0 ? min( 100, round( ( $synced_boms / $total_boms ) * 100, 2 ) ) : 0;

		return array(
			'total_boms'   => (int) $total_boms,
			'synced_boms'  => (int) $synced_boms,
			'hooks_active' => (bool) $hooks_active,
			'sync_percent' => $sync_percent,
		);
	}

}

