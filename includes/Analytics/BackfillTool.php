<?php
/**
 * Analytics Backfill Tool - Retroactively sync historical BOM data
 *
 * @package AtumPLAnalyticsHelper\Analytics
 * @author  BE REBEL - https://berebel.studio
 * @since   1.0.0
 */

namespace AtumPLAnalyticsHelper\Analytics;

defined( 'ABSPATH' ) || die;

class BackfillTool {

	/**
	 * The singleton instance holder
	 *
	 * @var BackfillTool
	 */
	private static $instance;

	/**
	 * Batch size for processing
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Option name for storing backfill progress
	 *
	 * @var string
	 */
	const PROGRESS_OPTION = 'atum_pl_analytics_helper_backfill_progress';

	/**
	 * Action Scheduler hook name
	 *
	 * @var string
	 */
	const SCHEDULER_HOOK = 'atum_pl_analytics_helper_backfill_batch';

	/**
	 * BackfillTool constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Register Action Scheduler hook for background processing.
		add_action( self::SCHEDULER_HOOK, array( $this, 'process_scheduled_batch' ), 10, 1 );
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
	 * @return BackfillTool
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get total number of orders with BOMs to backfill
	 *
	 * @since 1.0.0
	 *
	 * @return int Total order count.
	 */
	public function get_total_orders() {

		global $wpdb;

		$total = $wpdb->get_var(
			"SELECT COUNT(DISTINCT oi.order_id)
			FROM {$wpdb->prefix}atum_order_boms aob
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON aob.order_item_id = oi.order_item_id
			WHERE oi.order_id IS NOT NULL"
		);

		return (int) $total;
	}

	/**
	 * Get backfill progress
	 *
	 * @since 1.0.0
	 *
	 * @return array Progress data.
	 */
	public function get_progress() {

		$progress = get_option( self::PROGRESS_OPTION, array(
			'processed' => 0,
			'total'     => 0,
			'status'    => 'idle',
			'started'   => null,
			'completed' => null,
			'errors'    => 0,
			'percent'   => 0,
		) );

		// Update total if idle.
		if ( 'idle' === $progress['status'] ) {
			$progress['total'] = $this->get_total_orders();
		}

		// Calculate percentage.
		if ( $progress['total'] > 0 ) {
			$progress['percent'] = round( ( $progress['processed'] / $progress['total'] ) * 100, 1 );
		}

		return $progress;
	}

	/**
	 * Update backfill progress
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Progress data to update.
	 *
	 * @return bool Success status.
	 */
	private function update_progress( $data ) {

		$progress = $this->get_progress();
		$progress = array_merge( $progress, $data );

		// Calculate percentage.
		if ( $progress['total'] > 0 ) {
			$progress['percent'] = round( ( $progress['processed'] / $progress['total'] ) * 100, 1 );
		}

		return update_option( self::PROGRESS_OPTION, $progress, false );
	}

	/**
	 * Reset backfill progress
	 *
	 * @since 1.0.0
	 *
	 * @return bool Success status.
	 */
	public function reset_progress() {

		// Cancel all pending scheduled actions.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SCHEDULER_HOOK );
		}

		return delete_option( self::PROGRESS_OPTION );
	}

	/**
	 * Start backfill process (schedules background jobs)
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Force restart even if in progress.
	 *
	 * @return array Result with status and message.
	 */
	public function start_backfill( $force = false ) {

		$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

		$progress = $this->get_progress();

		// Check if already in progress.
		if ( ! $force && 'running' === $progress['status'] ) {
			if ( $logger ) {
				$logger->info( 'BOM Analytics Backfill: Already in progress, skipping start.', array( 'source' => 'atum-pl-analytics-helper' ) );
			}
			return array(
				'success' => false,
				'message' => __( 'Backfill is already in progress.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				'data'    => $progress,
			);
		}

		// Cancel any existing scheduled actions.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SCHEDULER_HOOK );
		}

		// Initialize progress.
		$total = $this->get_total_orders();

		if ( $logger ) {
			$logger->info( sprintf( 'BOM Analytics Backfill: Starting backfill for %d orders.', $total ), array( 'source' => 'atum-pl-analytics-helper' ) );
		}

		$this->update_progress( array(
			'processed' => 0,
			'total'     => $total,
			'status'    => 'running',
			'started'   => current_time( 'mysql' ),
			'completed' => null,
			'errors'    => 0,
			'percent'   => 0,
		) );

		// Clean up any existing duplicates before starting backfill
		$sync = Sync::get_instance();
		$duplicate_result = $sync->remove_duplicates();
		
		if ( $logger && isset( $duplicate_result['removed'] ) && $duplicate_result['removed'] > 0 ) {
			$logger->info( sprintf( 'BOM Analytics Backfill: Removed %d duplicate records before starting.', $duplicate_result['removed'] ), array( 'source' => 'atum-pl-analytics-helper' ) );
		}

		// Process synchronously (Action Scheduler unreliable in some setups)
		// Frontend JS will poll for progress updates
		return $this->backfill_all_sync();
	}

	/**
	 * Process a scheduled batch (called by Action Scheduler)
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Offset for the batch.
	 */
	public function process_scheduled_batch( $offset ) {

		$progress = $this->get_progress();

		// Only process if status is running.
		if ( 'running' !== $progress['status'] ) {
			return;
		}

		// Process the batch.
		$result = $this->backfill_batch( $offset, self::BATCH_SIZE );

		// Update progress.
		$new_processed = $progress['processed'] + $result['processed'];
		$new_errors    = $progress['errors'] + $result['errors'];

		$this->update_progress( array(
			'processed' => $new_processed,
			'errors'     => $new_errors,
		) );

		// Check if this was the last batch.
		$progress = $this->get_progress();
		if ( $progress['processed'] >= $progress['total'] ) {
			$this->update_progress( array(
				'status'    => 'completed',
				'completed' => current_time( 'mysql' ),
			) );
		}
	}

	/**
	 * Backfill all data synchronously (fallback method)
	 *
	 * @since 1.0.0
	 *
	 * @return array Result with status and message.
	 */
	private function backfill_all_sync() {

		$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

		$progress = $this->get_progress();
		$total    = $progress['total'];

		// Process all batches synchronously.
		$offset    = 0;
		$processed = 0;
		$errors    = 0;
		$batch_num = 0;

		while ( $offset < $total ) {
			$batch_num++;

			if ( $logger ) {
				$logger->info( sprintf( 'BOM Analytics Backfill: Processing batch %d (offset %d, limit %d)', $batch_num, $offset, self::BATCH_SIZE ), array( 'source' => 'atum-pl-analytics-helper' ) );
			}

			$result = $this->backfill_batch( $offset, self::BATCH_SIZE );

			$processed += $result['processed'];
			$errors    += $result['errors'];
			$offset    += self::BATCH_SIZE;

			if ( $logger ) {
				$logger->info( sprintf( 'BOM Analytics Backfill: Batch %d completed - %d processed, %d errors. Total progress: %d/%d (%.1f%%)', $batch_num, $result['processed'], $result['errors'], $processed, $total, round( ( $processed / $total ) * 100, 1 ) ), array( 'source' => 'atum-pl-analytics-helper' ) );
			}

			// Update progress.
			$this->update_progress( array(
				'processed' => $processed,
				'errors'     => $errors,
			) );

			// Prevent timeout.
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 30 );
			}
		}

		// Mark as completed.
		$this->update_progress( array(
			'status'    => 'completed',
			'completed' => current_time( 'mysql' ),
		) );

		// Log final result.
		if ( $logger ) {
			$logger->info( sprintf( 'BOM Analytics Backfill: COMPLETED - Processed %d orders, %d errors, %d successful. Total: %d orders.', $processed, $errors, $processed - $errors, $total ), array( 'source' => 'atum-pl-analytics-helper' ) );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %1$d: processed count, %2$d: error count */
				__( 'Backfill completed. Processed %1$d orders with %2$d errors.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				$processed,
				$errors
			),
			'data'    => array(
				'processed' => $processed,
				'errors'     => $errors,
			),
		);
	}

	/**
	 * Backfill a batch of orders
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Offset for batch.
	 * @param int $limit  Limit for batch.
	 *
	 * @return array Result with processed and error counts.
	 */
	public function backfill_batch( $offset, $limit ) {

		global $wpdb;

		$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

		// Get order IDs with BOMs.
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT oi.order_id
				FROM {$wpdb->prefix}atum_order_boms aob
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON aob.order_item_id = oi.order_item_id
				WHERE oi.order_id IS NOT NULL
				ORDER BY oi.order_id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		$processed = 0;
		$errors    = 0;
		$sync      = Sync::get_instance();

		foreach ( $order_ids as $order_id ) {

			$result = $sync->sync_bom_to_analytics( $order_id, true ); // Pass true to indicate backfill operation

			if ( $result ) {
				$processed++;
				if ( $logger ) {
					$logger->info( sprintf( 'BOM Analytics Backfill: Order #%d synced successfully', $order_id ), array( 'source' => 'atum-pl-analytics-helper' ) );
				}
			} else {
				$errors++;
				if ( $logger ) {
					$logger->warning( sprintf( 'BOM Analytics Backfill: Order #%d sync failed (no BOMs found or error occurred)', $order_id ), array( 'source' => 'atum-pl-analytics-helper' ) );
				}
			}
		}

		return array(
			'processed' => $processed,
			'errors'     => $errors,
		);
	}

	/**
	 * Clear all BOM analytics data
	 *
	 * @since 1.0.0
	 *
	 * @return array Result with status and message.
	 */
	public function clear_analytics() {

		global $wpdb;

		// Delete all BOM analytics records.
		$result = $wpdb->query(
			"DELETE wpl FROM {$wpdb->prefix}wc_order_product_lookup wpl
			INNER JOIN {$wpdb->prefix}atum_product_data apd ON wpl.product_id = apd.product_id
			WHERE apd.is_bom = 1"
		);

		// Reset progress.
		$this->reset_progress();

		return array(
			'success' => false !== $result,
			'message' => sprintf(
				/* translators: %d: number of records deleted */
				__( 'Cleared %d BOM analytics records.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
				$result
			),
			'data'    => array(
				'deleted' => $result,
			),
		);
	}

}

