# Historical Backfill Function - Code Path Analysis

## Overview

The Historical Backfill function retroactively syncs BOM (Bill of Materials) consumption data from historical orders into WooCommerce Analytics. This is necessary when:
- The analytics integration is added to an existing site with historical orders
- Orders were processed before the sync functionality was active
- Data needs to be restored after clearing analytics

---

## Complete Code Path

### 1. User Initiates Backfill

**Entry Point:** Admin clicks "Run Historical Backfill" button

```
User Action (Admin Dashboard)
    │
    ▼
JavaScript: analytics-status.js
    │
    ▼
AJAX Request → wp_ajax_atum_pl_analytics_backfill
    │
    ▼
StatusPage::ajax_backfill()
```

**File:** `includes/Analytics/StatusPage.php` (line 580-595)

```php
public function ajax_backfill() {
    // 1. Verify nonce (security)
    check_ajax_referer( 'atum-pl-analytics', 'nonce' );
    
    // 2. Check user permissions
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error();
    }
    
    // 3. Start the backfill process
    $result = BackfillTool::get_instance()->start_backfill();
    
    // 4. Return result to frontend
    wp_send_json_success( $result );
}
```

---

### 2. BackfillTool::start_backfill()

**File:** `includes/Analytics/BackfillTool.php` (line 185-219)

**Purpose:** Initializes and starts the backfill process

**Process:**

```php
public function start_backfill( $force = false ) {
    // Step 1: Check if already running
    $progress = $this->get_progress();
    if ( ! $force && 'running' === $progress['status'] ) {
        return array( 'success' => false, 'message' => 'Already in progress' );
    }
    
    // Step 2: Cancel any existing scheduled actions
    as_unschedule_all_actions( self::SCHEDULER_HOOK );
    
    // Step 3: Get total count of orders with BOMs
    $total = $this->get_total_orders();
    
    // Step 4: Initialize progress tracking
    $this->update_progress( array(
        'processed' => 0,
        'total'     => $total,
        'status'    => 'running',
        'started'   => current_time( 'mysql' ),
        'errors'    => 0,
    ) );
    
    // Step 5: Process all orders synchronously
    return $this->backfill_all_sync();
}
```

**Key Operations:**
- **Progress Tracking:** Stores progress in WordPress option `atum_pl_analytics_helper_backfill_progress`
- **State Management:** Sets status to 'running', records start time
- **Total Calculation:** Counts distinct orders with BOM data

---

### 3. get_total_orders()

**File:** `includes/Analytics/BackfillTool.php` (line 91-103)

**Purpose:** Counts how many orders need to be processed

**SQL Query:**
```sql
SELECT COUNT(DISTINCT oi.order_id)
FROM wp_atum_order_boms aob
INNER JOIN wp_woocommerce_order_items oi 
    ON aob.order_item_id = oi.order_item_id
WHERE oi.order_id IS NOT NULL
```

**What it does:**
- Joins `atum_order_boms` (BOM consumption records) with `woocommerce_order_items` (order items)
- Counts distinct order IDs that have BOM data
- Returns the total number of orders to process

**Example:** If you have 500 orders, but only 200 have BOMs, this returns `200`

---

### 4. backfill_all_sync()

**File:** `includes/Analytics/BackfillTool.php` (line 266-315)

**Purpose:** Processes all orders in batches synchronously

**Process Flow:**

```php
private function backfill_all_sync() {
    $progress = $this->get_progress();
    $total = $progress['total'];  // e.g., 200 orders
    
    $offset = 0;
    $processed = 0;
    $errors = 0;
    
    // Loop through all orders in batches
    while ( $offset < $total ) {
        // Process batch of 50 orders
        $result = $this->backfill_batch( $offset, 50 );
        
        $processed += $result['processed'];
        $errors    += $result['errors'];
        $offset    += 50;  // Move to next batch
        
        // Update progress after each batch
        $this->update_progress( array(
            'processed' => $processed,
            'errors'    => $errors,
        ) );
        
        // Prevent PHP timeout
        set_time_limit( 30 );
    }
    
    // Mark as completed
    $this->update_progress( array(
        'status'    => 'completed',
        'completed' => current_time( 'mysql' ),
    ) );
}
```

**Key Features:**
- **Batch Processing:** Processes 50 orders at a time (BATCH_SIZE = 50)
- **Progress Updates:** Updates progress after each batch (frontend polls this)
- **Timeout Protection:** Extends PHP time limit to prevent script timeout
- **Error Tracking:** Counts failed orders separately

**Example Execution:**
- Total: 200 orders
- Batch 1: Orders 0-49 (50 orders)
- Batch 2: Orders 50-99 (50 orders)
- Batch 3: Orders 100-149 (50 orders)
- Batch 4: Orders 150-199 (50 orders)
- Complete!

---

### 5. backfill_batch()

**File:** `includes/Analytics/BackfillTool.php` (line 327-364)

**Purpose:** Processes a single batch of orders

**Process:**

```php
public function backfill_batch( $offset, $limit ) {
    // Step 1: Get order IDs for this batch
    $order_ids = $wpdb->get_col(
        "SELECT DISTINCT oi.order_id
        FROM wp_atum_order_boms aob
        INNER JOIN wp_woocommerce_order_items oi 
            ON aob.order_item_id = oi.order_item_id
        WHERE oi.order_id IS NOT NULL
        ORDER BY oi.order_id DESC
        LIMIT 50 OFFSET 0"  // First batch: offset 0, limit 50
    );
    
    // Step 2: Process each order
    $processed = 0;
    $errors = 0;
    $sync = Sync::get_instance();
    
    foreach ( $order_ids as $order_id ) {
        // Sync this order's BOMs to analytics
        $result = $sync->sync_bom_to_analytics( $order_id );
        
        if ( $result ) {
            $processed++;  // Success
        } else {
            $errors++;    // Failed (no BOMs or error)
        }
    }
    
    return array(
        'processed' => $processed,
        'errors'    => $errors,
    );
}
```

**SQL Query Breakdown:**
- **SELECT DISTINCT oi.order_id:** Gets unique order IDs
- **FROM atum_order_boms:** Source table (BOM consumption records)
- **INNER JOIN woocommerce_order_items:** Links to WooCommerce order items
- **ORDER BY DESC:** Processes newest orders first
- **LIMIT/OFFSET:** Pagination (50 orders per batch)

**Returns:**
- `processed`: Number of orders successfully synced
- `errors`: Number of orders that failed (no BOMs found or errors)

---

### 6. Sync::sync_bom_to_analytics()

**File:** `includes/Analytics/Sync.php` (line 186-226)

**Purpose:** Syncs BOM data for a single order to Analytics

**Process:**

```php
public function sync_bom_to_analytics( $order_id ) {
    // Step 1: Get WooCommerce order object
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return false;  // Order doesn't exist
    }
    
    // Step 2: Get all order items
    $order_items = $order->get_items();
    if ( empty( $order_items ) ) {
        return false;  // No items in order
    }
    
    // Step 3: For each order item, get its BOMs
    $synced = 0;
    foreach ( $order_items as $item_id => $item ) {
        // Get BOM consumption data for this order item
        $bom_items = BOMOrderItemsModel::get_bom_order_items( $item_id, 1, true );
        
        if ( empty( $bom_items ) ) {
            continue;  // No BOMs for this item
        }
        
        // Step 4: Sync each BOM item
        foreach ( $bom_items as $bom_item ) {
            $this->sync_single_bom_item( $order_id, $order, $item_id, $bom_item );
            $synced++;
        }
    }
    
    return $synced > 0;  // True if any BOMs were synced
}
```

**Data Flow:**
```
Order #12345
    │
    ├─ Order Item #100 (Product: Widget)
    │   ├─ BOM Item: Screw (qty: 4)
    │   ├─ BOM Item: Bolt (qty: 2)
    │   └─ BOM Item: Washer (qty: 8)
    │
    └─ Order Item #101 (Product: Gadget)
        └─ BOM Item: Wire (qty: 10)
```

**Result:** 4 BOM items synced to Analytics for this order

---

### 7. Sync::sync_single_bom_item()

**File:** `includes/Analytics/Sync.php` (line 240-336)

**Purpose:** Syncs a single BOM item to the Analytics lookup table

**This is the core operation - here's what happens:**

#### Step 1: Create Synthetic ID

```php
// Original order item ID: 793171
// BOM product ID: 342255
// Synthetic ID: 793171342255 (concatenated)
$synthetic_item_id = (int) ( $item_id . $bom_item->bom_id );
```

**Why?** Multiple BOMs can be consumed by a single order item. The Analytics table uses `order_item_id` as part of the primary key, so we need unique IDs for each BOM.

**Example:**
- Order Item #100 consumes:
  - Screw (BOM ID: 100) → Synthetic ID: `100100`
  - Bolt (BOM ID: 200) → Synthetic ID: `100200`
  - Washer (BOM ID: 300) → Synthetic ID: `100300`

#### Step 2: Check if Already Synced

```php
$exists = $wpdb->get_var(
    "SELECT order_item_id FROM wp_wc_order_product_lookup
    WHERE order_id = 12345 
      AND product_id = 342255 
      AND order_item_id = 793171342255"
);
```

**Purpose:** Prevents duplicate entries. If already synced, we update instead of insert.

#### Step 3: Prepare Data

```php
$data = array(
    'order_id'              => 12345,
    'product_id'            => 342255,  // BOM product ID
    'variation_id'          => 0,
    'customer_id'           => 567,     // Order customer
    'order_item_id'         => 793171342255,  // Synthetic ID
    'product_qty'           => 4,       // Quantity consumed
    'product_net_revenue'   => 0,        // BOMs aren't sold
    'product_gross_revenue' => 0,
    'date_created'          => '2025-01-15 10:30:00',  // Order date
    'coupon_amount'         => 0,
    'tax_amount'            => 0,
    'shipping_amount'       => 0,
    'shipping_tax_amount'   => 0,
);
```

**Key Points:**
- **Revenue = 0:** BOMs are components, not products sold directly
- **Date = Order Date:** Preserves historical order date
- **Quantity:** Actual BOM consumption quantity

#### Step 4: Insert or Update

```php
if ( $exists ) {
    // Update existing record
    $wpdb->update( 'wp_wc_order_product_lookup', $data, $where );
} else {
    // Insert new record
    $wpdb->insert( 'wp_wc_order_product_lookup', $data );
}
```

**Result:** BOM data is now in `wc_order_product_lookup` table, visible in WooCommerce Analytics!

---

## Progress Tracking

### Storage

Progress is stored in WordPress options table:
- **Option Name:** `atum_pl_analytics_helper_backfill_progress`
- **Structure:**
```php
array(
    'processed' => 150,        // Orders processed so far
    'total'     => 200,        // Total orders to process
    'status'    => 'running',  // idle | running | completed
    'started'   => '2025-12-02 10:00:00',
    'completed' => null,
    'errors'    => 2,          // Failed orders
    'percent'   => 75.0,       // Completion percentage
)
```

### Frontend Polling

**File:** `assets/js/analytics-status.js`

```javascript
// Polls every 2 seconds when backfill is running
setInterval(function() {
    $.ajax({
        url: ajaxUrl,
        data: {
            action: 'atum_pl_analytics_progress',
            nonce: nonce
        },
        success: function(response) {
            // Update progress bar
            // Update statistics
            // Reload page when complete
        }
    });
}, 2000);
```

**Flow:**
1. User clicks "Run Historical Backfill"
2. AJAX request starts backfill
3. Frontend polls every 2 seconds for progress
4. Progress bar updates in real-time
5. Page reloads when status = 'completed'

---

## Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│  User Clicks "Run Historical Backfill"                      │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  JavaScript AJAX Request                                     │
│  → wp_ajax_atum_pl_analytics_backfill                        │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  StatusPage::ajax_backfill()                                 │
│  - Verify nonce & permissions                                │
│  - Call BackfillTool::start_backfill()                       │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  BackfillTool::start_backfill()                              │
│  ├─ Check if already running                                 │
│  ├─ Get total orders: get_total_orders()                     │
│  ├─ Initialize progress tracking                             │
│  └─ Call backfill_all_sync()                                 │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  BackfillTool::backfill_all_sync()                           │
│  ┌───────────────────────────────────────────────────────┐  │
│  │  while (offset < total) {                              │  │
│  │    backfill_batch(offset, 50)  ← Process 50 orders     │  │
│  │    update_progress()           ← Save progress        │  │
│  │    set_time_limit(30)          ← Prevent timeout      │  │
│  │    offset += 50                ← Next batch           │  │
│  │  }                                                      │  │
│  └───────────────────────────────────────────────────────┘  │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  BackfillTool::backfill_batch(offset, limit)                 │
│  ├─ Query: Get 50 order IDs with BOMs                        │
│  └─ For each order_id:                                        │
│      └─ Sync::sync_bom_to_analytics(order_id)                 │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  Sync::sync_bom_to_analytics(order_id)                        │
│  ├─ Get order object                                          │
│  ├─ Get order items                                           │
│  └─ For each order item:                                      │
│      ├─ Get BOM items: BOMOrderItemsModel::get_bom_order_items()
│      └─ For each BOM item:                                    │
│          └─ sync_single_bom_item()                           │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  Sync::sync_single_bom_item()                                 │
│  ├─ Create synthetic order_item_id                           │
│  ├─ Check if already exists in Analytics                      │
│  ├─ Prepare data array                                        │
│  └─ INSERT or UPDATE wc_order_product_lookup                 │
└───────────────────────┬─────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│  BOM Data Now in WooCommerce Analytics!                       │
│  - Visible in Analytics reports                               │
│  - Trackable by product                                       │
│  - Historical data preserved                                  │
└─────────────────────────────────────────────────────────────┘
```

---

## Key Database Operations

### Source Data Query

**Table:** `wp_atum_order_boms`
```sql
-- Get BOM consumption for an order item
SELECT bom_id, bom_type, qty
FROM wp_atum_order_boms
WHERE order_item_id = 100
  AND order_type = 1
```

**Returns:**
```
bom_id | bom_type      | qty
-------|---------------|-----
342255 | product_part  | 4
342256 | raw_material  | 2
342257 | product_part  | 8
```

### Destination Insert/Update

**Table:** `wp_wc_order_product_lookup`
```sql
-- Insert new BOM record
INSERT INTO wp_wc_order_product_lookup (
    order_id, product_id, order_item_id, product_qty, 
    product_net_revenue, date_created, ...
) VALUES (
    12345, 342255, 793171342255, 4, 0, '2025-01-15 10:30:00', ...
)
```

---

## Performance Considerations

### Batch Size (50 orders)

**Why 50?**
- Balance between speed and memory usage
- Prevents PHP timeouts
- Allows progress updates between batches
- Reasonable database query size

### Timeout Protection

```php
set_time_limit( 30 );  // Extend to 30 seconds per batch
```

**Why?**
- Default PHP timeout is often 30 seconds
- Each batch may take 5-10 seconds
- Extending prevents script termination mid-batch

### Progress Updates

- Updated after **each batch** (not each order)
- Stored in WordPress options (fast, cached)
- Frontend polls every 2 seconds
- Minimal database overhead

---

## Error Handling

### Order-Level Errors

If an order fails to sync:
- Error count increments
- Processing continues
- Order is skipped
- No data corruption

**Common Failure Reasons:**
- Order deleted
- BOM product deleted
- Invalid order data
- Database connection issues

### Batch-Level Protection

- Each batch is independent
- Failure of one batch doesn't stop others
- Progress continues even with errors
- Final report shows error count

---

## Example Execution

### Scenario: 200 Historical Orders

**Step 1: Initialization**
```
Total orders found: 200
Status: running
Started: 2025-12-02 10:00:00
```

**Step 2: Batch Processing**
```
Batch 1: Orders 0-49   → 48 processed, 2 errors (10 seconds)
Batch 2: Orders 50-99  → 50 processed, 0 errors (8 seconds)
Batch 3: Orders 100-149 → 49 processed, 1 error (9 seconds)
Batch 4: Orders 150-199 → 50 processed, 0 errors (7 seconds)
```

**Step 3: Completion**
```
Total processed: 197 orders
Total errors: 3 orders
Status: completed
Completed: 2025-12-02 10:00:34
Duration: 34 seconds
```

**Result:**
- 197 orders successfully synced
- ~500-1000 BOM records now in Analytics
- Historical data visible in WooCommerce Analytics reports

---

## Summary

The Historical Backfill function:

1. **Finds** all historical orders with BOM consumption data
2. **Processes** them in batches of 50 orders
3. **Syncs** each order's BOM data to WooCommerce Analytics
4. **Tracks** progress in real-time
5. **Preserves** historical order dates and quantities
6. **Handles** errors gracefully without stopping
7. **Completes** synchronously (no background jobs needed)

**Key Benefit:** Makes all historical BOM consumption data visible in WooCommerce Analytics, enabling proper reporting and analysis of manufacturing operations over time.

