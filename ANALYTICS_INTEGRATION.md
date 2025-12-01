# BOM Analytics Integration

## Overview

This commit adds a comprehensive integration between ATUM Product Levels BOM (Bill of Materials) products and WooCommerce Analytics. Previously, BOM products (Product Parts and Raw Materials) were excluded from WooCommerce Analytics reports. This integration ensures that BOM consumption data is tracked and visible in WooCommerce Analytics, enabling proper reporting and analysis of manufacturing operations.

**Commit:** `6132f96 - Cons code commit`  
**Version:** 1.9.14

---

## What Problem Does This Solve?

WooCommerce Analytics by default excludes certain product types from reporting. BOM products (Product Parts, Raw Materials, Variable Product Parts, Variable Raw Materials) were being excluded, which meant:

- BOM products didn't appear in Analytics reports
- No visibility into BOM consumption/sales trends
- Historical data was missing
- Manufacturing insights were unavailable

This integration:
- ✅ Includes BOM products in Analytics
- ✅ Syncs BOM consumption data automatically
- ✅ Provides historical data backfill
- ✅ Offers admin dashboard for monitoring

---

## Architecture

The integration consists of three main components:

```
┌─────────────────────────────────────────────────────────────┐
│                    Analytics Integration                     │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐ │
│  │   Sync.php   │───▶│ BackfillTool │───▶│  StatusPage  │ │
│  │              │    │     .php     │    │     .php     │ │
│  │ Core sync    │    │ Historical   │    │ Admin UI     │ │
│  │ logic        │    │ backfill     │    │ Dashboard    │ │
│  └──────────────┘    └──────────────┘    └──────────────┘ │
│         │                   │                       │         │
│         └───────────────────┴───────────────────────┘         │
│                           │                                   │
│         ┌─────────────────▼─────────────────┐                │
│         │  WooCommerce Analytics Tables     │                │
│         │  (wc_order_product_lookup)       │                │
│         └───────────────────────────────────┘                │
└─────────────────────────────────────────────────────────────┘
```

---

## Components

### 1. Sync.php - Core Synchronization Engine

**Location:** `classes/Inc/Analytics/Sync.php`

**Purpose:** Handles the core logic for syncing BOM data to WooCommerce Analytics tables.

#### Key Methods:

##### `include_product_parts_in_analytics($excluded_types)`
- **Hook:** `woocommerce_analytics_products_excluded_product_types`
- **Purpose:** Removes BOM product types from the excluded list
- **BOM Types Included:**
  - `product-part`
  - `variable-product-part`
  - `raw-material`
  - `variable-raw-material`

##### `sync_bom_to_analytics($order_id)`
- **Purpose:** Main sync method called when orders are processed
- **Process:**
  1. Retrieves all order items
  2. For each item, gets associated BOM data from `atum_order_boms` table
  3. Calls `sync_single_bom_item()` for each BOM
  4. Returns success status

##### `sync_single_bom_item($order_id, $order, $item_id, $bom_item)`
- **Purpose:** Syncs a single BOM item to Analytics
- **Key Feature:** Uses synthetic `order_item_id` to avoid conflicts
  - Format: `{original_item_id}{bom_id}` (e.g., `793171342255`)
  - Ensures uniqueness in `wc_order_product_lookup` table
- **Data Synced:**
  - `order_id` - The WooCommerce order ID
  - `product_id` - The BOM product ID
  - `order_item_id` - Synthetic ID (original + BOM ID)
  - `product_qty` - Quantity consumed
  - `product_net_revenue` - Set to 0 (BOMs are components, not sold)
  - `product_gross_revenue` - Set to 0
  - `date_created` - Order creation date
  - Other standard Analytics fields (customer_id, tax, shipping, etc.)

##### `remove_bom_from_analytics($order_id)`
- **Purpose:** Removes BOM data when orders are refunded/cancelled
- **Use Case:** Cleanup for order status changes

##### `get_sync_status()`
- **Purpose:** Returns sync statistics
- **Returns:**
  - `total_boms` - Total BOM records in `atum_order_boms`
  - `synced_boms` - Count of synced records in Analytics
  - `hooks_active` - Whether integration hooks are registered
  - `sync_percent` - Percentage coverage

---

### 2. BackfillTool.php - Historical Data Processor

**Location:** `classes/Inc/Analytics/BackfillTool.php`

**Purpose:** Retroactively syncs historical order data containing BOMs to Analytics.

#### Key Features:

- **Batch Processing:** Processes orders in batches of 50
- **Progress Tracking:** Stores progress in WordPress options
- **Synchronous Processing:** Processes all batches in one request (with timeout protection)
- **Action Scheduler Support:** Can use Action Scheduler for background processing (though sync method is preferred)

#### Key Methods:

##### `get_total_orders()`
- Counts distinct orders with BOM data
- Query joins `atum_order_boms` with `woocommerce_order_items`

##### `start_backfill($force = false)`
- **Purpose:** Initiates the backfill process
- **Process:**
  1. Checks if already running (unless forced)
  2. Cancels any existing scheduled actions
  3. Initializes progress tracking
  4. Calls `backfill_all_sync()` to process all orders

##### `backfill_all_sync()`
- **Purpose:** Processes all orders synchronously
- **Process:**
  1. Loops through all orders in batches
  2. Calls `backfill_batch()` for each batch
  3. Updates progress after each batch
  4. Extends time limit to prevent timeout
  5. Marks as completed when done

##### `backfill_batch($offset, $limit)`
- **Purpose:** Processes a single batch of orders
- **Process:**
  1. Retrieves order IDs with BOMs (LIMIT/OFFSET)
  2. For each order, calls `Sync::sync_bom_to_analytics()`
  3. Tracks processed/error counts
  4. Returns results

##### `get_progress()`
- Returns current backfill status:
  - `processed` - Number of orders processed
  - `total` - Total orders to process
  - `status` - `idle`, `running`, or `completed`
  - `started` - Start timestamp
  - `completed` - Completion timestamp
  - `errors` - Error count
  - `percent` - Completion percentage

##### `clear_analytics()`
- **Purpose:** Removes all BOM data from Analytics tables
- **Use Case:** Reset/cleanup before re-syncing

---

### 3. StatusPage.php - Admin Dashboard

**Location:** `classes/Inc/Analytics/StatusPage.php`

**Purpose:** Provides an admin interface for monitoring and managing the Analytics integration.

#### Features:

##### Admin Menu
- **Location:** ATUM → BOM Analytics
- **Capability:** `manage_woocommerce`
- **Slug:** `atum-bom-analytics`

##### Dashboard Sections:

1. **Integration Health**
   - Product Types Registered (hook check)
   - Analytics Tables Exist
   - BOM Tables Exist
   - Recent Orders Tracking
   - Sync Coverage (should be ≥80%)

2. **Sync Statistics**
   - Total BOM Records
   - Synced to Analytics
   - Sync Coverage (with progress bar)

3. **Historical Data Backfill**
   - Status indicator (idle/running/completed)
   - Progress bar (processed/total/percentage)
   - Start/completion timestamps

4. **Recently Synced BOMs**
   - Table showing last 10 synced BOM products
   - Links to edit product and view in Analytics
   - Product type badges

5. **Actions**
   - **Run Historical Backfill** - Starts backfill process
   - **Test Sync (Latest Order)** - Tests sync on most recent order
   - **Clear BOM Analytics** - Removes all BOM data from Analytics

6. **Troubleshooting**
   - Helpful tips and links

#### AJAX Handlers:

- `ajax_backfill()` - Starts backfill process
- `ajax_get_progress()` - Returns current progress (polled by frontend)
- `ajax_clear()` - Clears Analytics data
- `ajax_test_sync()` - Tests sync on latest order

#### Health Checks:

The `run_health_checks()` method verifies:
1. Hook registration status
2. Analytics table existence
3. BOM table existence
4. Recent order sync status
5. Overall sync coverage

---

### 4. Frontend Assets

#### analytics-status.js

**Location:** `assets/js/analytics-status.js`

**Purpose:** Handles frontend interactions and progress polling.

**Key Features:**
- Progress polling every 2 seconds when backfill is running
- AJAX handlers for all actions (backfill, clear, test sync)
- Real-time progress bar updates
- Auto-reload on completion
- User-friendly confirmations and error handling

**Methods:**
- `init()` - Initializes on page load
- `startProgressPolling()` - Begins polling for updates
- `updateProgress()` - Fetches and updates progress display
- `handleBackfill()` - Initiates backfill via AJAX
- `handleClear()` - Clears analytics data
- `handleTestSync()` - Tests sync on latest order

#### analytics-status.css

**Location:** `assets/css/analytics-status.css`

**Purpose:** Styles for the admin dashboard.

**Key Styles:**
- Status indicators (success/error)
- Progress bars with animations
- Backfill status badges (idle/running/completed)
- Table styling
- Button states

---

## Integration Points

### 1. Hooks.php Integration

**File:** `classes/Inc/Hooks.php`

**Change:** Added `init_analytics()` method called in constructor.

```php
private function init_analytics() {
    // Initialize Sync (handles hooks for product type registration)
    if ( class_exists( '\AtumLevels\Inc\Analytics\Sync' ) ) {
        \AtumLevels\Inc\Analytics\Sync::get_instance();
    }

    // Initialize Status Page (admin menu and AJAX handlers)
    if ( is_admin() && class_exists( '\AtumLevels\Inc\Analytics\StatusPage' ) ) {
        \AtumLevels\Inc\Analytics\StatusPage::get_instance();
    }
}
```

**Purpose:** Ensures Analytics integration is initialized when plugin loads.

---

### 2. Orders.php Integration

**File:** `classes/Inc/Orders.php`

**Change:** Added sync call in order processing hook.

```php
// Sync BOM data to WooCommerce Analytics
if ( class_exists( '\AtumLevels\Inc\Analytics\Sync' ) ) {
    \AtumLevels\Inc\Analytics\Sync::get_instance()->sync_bom_to_analytics( $order->get_id() );
}
```

**Location:** Called when orders are processed/completed.

**Purpose:** Automatically syncs BOM data for new orders.

---

## Database Schema

### Source Tables

#### `{prefix}atum_order_boms`
Stores BOM consumption data for orders:
- `id` - Primary key
- `order_item_id` - Links to WooCommerce order item
- `bom_id` - The BOM product ID
- `bom_type` - Type of BOM (product_part, raw_material)
- `qty` - Quantity consumed

#### `{prefix}atum_product_data`
Stores product metadata:
- `is_bom` - Flag indicating if product is a BOM type

### Destination Table

#### `{prefix}wc_order_product_lookup`
WooCommerce Analytics table:
- `order_id` - Order ID
- `product_id` - Product ID (BOM product)
- `order_item_id` - **Synthetic ID** (original_item_id + bom_id)
- `product_qty` - Quantity
- `product_net_revenue` - Revenue (0 for BOMs)
- `product_gross_revenue` - Revenue (0 for BOMs)
- `date_created` - Order date
- Other standard Analytics fields

**Important:** The synthetic `order_item_id` ensures uniqueness since multiple BOMs can be consumed by a single order item.

---

## How It Works

### Automatic Sync Flow

```
Order Created/Completed
         │
         ▼
Orders.php Hook Triggered
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

### Backfill Flow

```
Admin Clicks "Run Historical Backfill"
         │
         ▼
BackfillTool::start_backfill()
         │
         ▼
Get Total Orders with BOMs
         │
         ▼
Process in Batches (50 orders each)
         │
         ▼
For Each Order:
  Sync::sync_bom_to_analytics($order_id)
         │
         ▼
Update Progress (polled by frontend)
         │
         ▼
Mark Complete When All Processed
```

### Synthetic ID Generation

To avoid conflicts in `wc_order_product_lookup`, synthetic IDs are created:

```php
$synthetic_item_id = (int) ($item_id . $bom_item->bom_id);
```

**Example:**
- Original order item ID: `793171`
- BOM product ID: `342255`
- Synthetic ID: `793171342255`

This ensures:
- ✅ Uniqueness (each BOM gets unique ID)
- ✅ Traceability (can identify original item)
- ✅ No conflicts with existing data

---

## Usage

### For Administrators

1. **Access Dashboard:**
   - Navigate to: ATUM → BOM Analytics
   - View integration health and sync statistics

2. **Run Historical Backfill:**
   - Click "Run Historical Backfill"
   - Wait for completion (progress shown in real-time)
   - Process may take 10-30 seconds depending on order count

3. **Test Sync:**
   - Click "Test Sync (Latest Order)"
   - Verifies sync works on most recent order
   - Useful for troubleshooting

4. **Clear Analytics:**
   - Click "Clear BOM Analytics"
   - Removes all BOM data from Analytics
   - Can be restored with backfill

5. **View in Analytics:**
   - Click "View in Analytics" links in Recently Synced table
   - Opens WooCommerce Analytics filtered to that product

### For Developers

#### Hooks Available

```php
// After BOM synced to Analytics
do_action( 'atum/product_levels/analytics/bom_synced', $order_id, $bom_id, $result );

// After BOM removed from Analytics
do_action( 'atum/product_levels/analytics/bom_removed', $order_id, $bom_product_ids );
```

#### Programmatic Usage

```php
// Sync a specific order
$sync = \AtumLevels\Inc\Analytics\Sync::get_instance();
$result = $sync->sync_bom_to_analytics( $order_id );

// Get sync status
$status = $sync->get_sync_status();
// Returns: ['total_boms', 'synced_boms', 'hooks_active', 'sync_percent']

// Start backfill
$backfill = \AtumLevels\Inc\Analytics\BackfillTool::get_instance();
$result = $backfill->start_backfill( $force = false );

// Get backfill progress
$progress = $backfill->get_progress();
// Returns: ['processed', 'total', 'status', 'started', 'completed', 'errors', 'percent']
```

---

## Technical Considerations

### Performance

- **Batch Processing:** Orders processed in batches of 50 to prevent timeouts
- **Synthetic IDs:** Efficient string concatenation for unique IDs
- **Progress Tracking:** Stored in WordPress options (not database queries)
- **Time Limits:** Extended during backfill to prevent PHP timeouts

### Error Handling

- Errors are tracked but don't stop the process
- Failed orders increment error counter
- Progress continues even if individual orders fail

### Data Integrity

- **Synthetic IDs:** Ensure no conflicts with existing order items
- **Update vs Insert:** Checks for existing records before inserting
- **Order Validation:** Verifies order and product exist before syncing

### Compatibility

- **WooCommerce HPOS:** Compatible with High-Performance Order Storage
- **Action Scheduler:** Optional background processing support
- **Multisite:** Works in WordPress multisite installations

---

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
4. Reset progress: `BackfillTool::get_instance()->reset_progress()`

### Synthetic ID Conflicts

**Rare:** If conflicts occur with synthetic IDs

**Solution:**
- Synthetic IDs use concatenation which should prevent conflicts
- If issues occur, check for extremely large order_item_ids or bom_ids
- Consider using hash-based approach if needed

---

## Future Enhancements

Potential improvements for future versions:

1. **Incremental Sync:** Only sync new/changed orders
2. **Scheduled Backfill:** Automatic periodic backfills
3. **Better Error Reporting:** Detailed error logs per order
4. **Bulk Operations:** Sync multiple orders via CLI
5. **Analytics Dashboard:** Custom Analytics views for BOMs
6. **Revenue Tracking:** Option to track BOM costs as revenue

---

## Files Changed

### Added Files:
- `classes/Inc/Analytics/BackfillTool.php` - Historical backfill processor
- `classes/Inc/Analytics/StatusPage.php` - Admin dashboard
- `classes/Inc/Analytics/Sync.php` - Core sync engine
- `assets/css/analytics-status.css` - Dashboard styles
- `assets/js/analytics-status.js` - Frontend JavaScript

### Modified Files:
- `classes/Inc/Hooks.php` - Added analytics initialization
- `classes/Inc/Orders.php` - Added automatic sync on order processing

---

## Version History

- **1.9.14** - Initial Analytics Integration
  - Core sync functionality
  - Historical backfill tool
  - Admin dashboard
  - Automatic order sync

---

## Support

For issues or questions:
1. Check Integration Health in BOM Analytics dashboard
2. Review troubleshooting section above
3. Check WordPress error logs
4. Contact support with sync status data

---

## License

©2025 Stock Management Labs™

