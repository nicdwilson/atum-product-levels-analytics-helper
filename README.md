# ATUM Product Levels Analytics Helper

A standalone WordPress plugin that adds BOM (Bill of Materials) Analytics integration for ATUM Product Levels without modifying the main plugin.

## Description

This helper plugin extracts the analytics integration functionality from ATUM Product Levels and provides it as a separate plugin. It syncs BOM consumption data to WooCommerce Analytics, enabling proper reporting and analysis of manufacturing operations.

**Key Features:**
- ✅ Syncs BOM products to WooCommerce Analytics automatically
- ✅ Historical data backfill tool
- ✅ Admin dashboard for monitoring sync status
- ✅ Zero modifications to the main ATUM Product Levels plugin
- ✅ Works alongside the original plugin seamlessly

## Requirements

- WordPress 5.9 or higher
- PHP 7.4 or higher
- WooCommerce 5.0 or higher
- ATUM Product Levels plugin (installed and activated)

## Installation

1. Upload the plugin files to `/wp-content/plugins/atum-product-levels-analytics-helper/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **ATUM → BOM Analytics** (or **WooCommerce → BOM Analytics** if ATUM menu is not available)
4. Run the historical backfill to sync existing order data

## Usage

### Initial Setup

1. **Access the Dashboard:**
   - Navigate to: **ATUM → BOM Analytics** (or **WooCommerce → BOM Analytics**)

2. **Run Historical Backfill:**
   - Click "Run Historical Backfill" to sync all historical order data
   - Wait for completion (progress shown in real-time)
   - Process may take 10-30 seconds depending on order count

3. **Verify Sync:**
   - Check "Sync Coverage" percentage (should be ≥80%)
   - Review "Recently Synced BOMs" table
   - Click "View in Analytics" to verify in WooCommerce Analytics

### Features

#### Automatic Sync
- Automatically syncs BOM data when orders are processed
- Works with WooCommerce orders and ATUM Purchase Orders
- Syncs on order status changes (processing, completed, cancelled, refunded)

#### Historical Backfill
- Retroactively syncs all historical order data
- Processes orders in batches of 50
- Real-time progress tracking
- Can be run multiple times safely

#### Status Dashboard
- Integration health checks
- Sync statistics and coverage
- Recently synced BOMs list
- Test sync functionality

## How It Works

### Architecture

The plugin hooks into WordPress actions instead of modifying the main plugin code:

- **Product Type Registration:** Hooks into `woocommerce_analytics_products_excluded_product_types` to include BOM product types
- **Order Processing:** Hooks into order processing actions:
  - `atum/purchase_orders/po/after_decrease_stock_levels`
  - `atum/purchase_orders/po/after_increase_stock_levels`
  - `woocommerce_saved_order_items`
  - `woocommerce_order_status_changed`
  - `woocommerce_payment_complete`

### Data Flow

```
Order Created/Completed
         │
         ▼
WordPress Hooks Triggered
         │
         ▼
Sync::sync_bom_to_analytics($order_id)
         │
         ▼
Get Order Items → Get BOM Items for Each
         │
         ▼
For Each BOM Item:
  Create Synthetic order_item_id
  Insert/Update wc_order_product_lookup
         │
         ▼
BOM Data Now Visible in Analytics
```

### Synthetic IDs

To avoid conflicts in `wc_order_product_lookup`, synthetic IDs are created:
- Format: `{original_item_id}{bom_id}` (e.g., `793171342255`)
- Ensures uniqueness since multiple BOMs can be consumed by a single order item

## Troubleshooting

### Low Sync Coverage

**Symptom:** Sync coverage shows <80%

**Solution:**
1. Run Historical Backfill
2. Check for errors in progress
3. Verify BOM products exist in orders

### BOMs Not Appearing in Analytics

**Checklist:**
1. Verify hooks are registered (check Integration Health)
2. Ensure Analytics tables exist
3. Check that BOM products have `is_bom = 1` in `atum_product_data`
4. Run Test Sync to verify current sync works
5. Check WooCommerce Analytics filters (BOM products should be included)

### Backfill Stuck

**Symptom:** Backfill status shows "running" but no progress

**Solution:**
1. Check PHP error logs
2. Verify database connectivity
3. Check for memory/timeout issues
4. Refresh the page and try again

## Development

### File Structure

```
atum-product-levels-analytics-helper/
├── atum-product-levels-analytics-helper.php  (Main plugin file)
├── includes/
│   └── Analytics/
│       ├── Sync.php          (Core sync engine)
│       ├── BackfillTool.php  (Historical backfill)
│       └── StatusPage.php    (Admin dashboard)
└── assets/
    ├── css/
    │   └── analytics-status.css
    └── js/
        └── analytics-status.js
```

### Hooks Available

```php
// After BOM synced to Analytics
do_action( 'atum/product_levels/analytics/bom_synced', $order_id, $bom_id, $result );

// After BOM removed from Analytics
do_action( 'atum/product_levels/analytics/bom_removed', $order_id, $bom_product_ids );
```

### Programmatic Usage

```php
// Sync a specific order
$sync = \AtumPLAnalyticsHelper\Analytics\Sync::get_instance();
$result = $sync->sync_bom_to_analytics( $order_id );

// Get sync status
$status = $sync->get_sync_status();
// Returns: ['total_boms', 'synced_boms', 'hooks_active', 'sync_percent']

// Start backfill
$backfill = \AtumPLAnalyticsHelper\Analytics\BackfillTool::get_instance();
$result = $backfill->start_backfill( $force = false );

// Get backfill progress
$progress = $backfill->get_progress();
// Returns: ['processed', 'total', 'status', 'started', 'completed', 'errors', 'percent']
```

## Compatibility

- **WooCommerce HPOS:** Compatible with High-Performance Order Storage
- **Action Scheduler:** Optional background processing support
- **Multisite:** Works in WordPress multisite installations
- **ATUM Product Levels:** Requires version 1.9.13 or higher

## Changelog

### 1.0.0
- Initial release
- Core sync functionality
- Historical backfill tool
- Admin dashboard
- Automatic order sync

## Support

For issues or questions:
1. Check Integration Health in BOM Analytics dashboard
2. Review troubleshooting section above
3. Check WordPress error logs
4. Contact support with sync status data

## License

GPL v2 or later

## Credits

- Original ATUM Product Levels code by BE REBEL - https://berebel.studio
- Extracted and adapted for standalone plugin use
