# Analytics Integration Extraction Strategy

## Overview

This document outlines the strategy for extracting the BOM Analytics integration code from the main ATUM Product Levels plugin into a standalone helper plugin that runs alongside the original plugin without modifying it.

---

## Goals

1. ✅ Extract all analytics functionality into standalone plugin
2. ✅ Maintain full compatibility with original plugin
3. ✅ Zero modifications to original plugin files
4. ✅ Use WordPress hooks/actions instead of direct code modifications
5. ✅ Graceful degradation if main plugin is deactivated

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│         ATUM Product Levels (Original Plugin)                │
│  - Provides: BOMOrderItemsModel, Globals, Database Tables   │
└─────────────────────────────────────────────────────────────┘
                          │
                          │ Uses (via hooks/class_exists)
                          ▼
┌─────────────────────────────────────────────────────────────┐
│    ATUM Product Levels Analytics Helper (New Plugin)        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │   Sync.php   │  │ BackfillTool  │  │  StatusPage   │   │
│  └──────────────┘  └──────────────┘  └──────────────┘   │
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

## Key Dependencies

### From Main Plugin (Accessed via class_exists/static methods)

1. **`AtumLevels\Models\BOMOrderItemsModel`**
   - Method: `get_bom_order_items($order_item_id, $order_type_id, $sum_items)`
   - Purpose: Retrieve BOM consumption data for order items
   - Access: Static method, safe to call if class exists

2. **`AtumLevels\Inc\Globals`**
   - Method: `get_product_levels()`
   - Purpose: Get array of BOM product types
   - Returns: `['product-part', 'variable-product-part', 'raw-material', 'variable-raw-material']`
   - Access: Static method, safe to call if class exists

3. **Database Tables**
   - `{prefix}atum_order_boms` - Source data
   - `{prefix}atum_product_data` - Product metadata (`is_bom` flag)
   - Access: Direct SQL queries (safe if tables exist)

### From WooCommerce

- `wc_order_product_lookup` table (Analytics destination)
- `wc_get_order()` function
- `wc_get_orders()` function
- Order hooks/actions

---

## Integration Points (Replacing Direct Code Modifications)

### Original Integration Point 1: Hooks.php

**Original Code:**
```php
// In Hooks.php constructor
private function init_analytics() {
    if ( class_exists( '\AtumLevels\Inc\Analytics\Sync' ) ) {
        \AtumLevels\Inc\Analytics\Sync::get_instance();
    }
    if ( is_admin() && class_exists( '\AtumLevels\Inc\Analytics\StatusPage' ) ) {
        \AtumLevels\Inc\Analytics\StatusPage::get_instance();
    }
}
```

**Replacement Strategy:**
- Initialize Sync and StatusPage in helper plugin's bootstrap
- Hook into `plugins_loaded` with priority 20 (after main plugin loads)
- Check for main plugin classes before initializing

**Implementation:**
```php
// In helper plugin bootstrap
add_action( 'plugins_loaded', function() {
    // Check main plugin is active
    if ( ! class_exists( '\AtumLevels\Models\BOMOrderItemsModel' ) ) {
        return;
    }
    
    // Initialize Sync
    AtumPLAnalyticsHelper\Analytics\Sync::get_instance();
    
    // Initialize Status Page (admin only)
    if ( is_admin() ) {
        AtumPLAnalyticsHelper\Analytics\StatusPage::get_instance();
    }
}, 20 );
```

---

### Original Integration Point 2: Orders.php

**Original Code:**
```php
// In Orders.php::after_order_stock_change()
public function after_order_stock_change( $order ) {
    // ... existing code ...
    
    // Sync BOM data to WooCommerce Analytics
    if ( class_exists( '\AtumLevels\Inc\Analytics\Sync' ) ) {
        \AtumLevels\Inc\Analytics\Sync::get_instance()->sync_bom_to_analytics( $order->get_id() );
    }
}
```

**Replacement Strategy:**
- Hook into the same actions that trigger `after_order_stock_change`
- Use WordPress hooks instead of modifying Orders.php

**Actions to Hook Into:**
1. `atum/purchase_orders/po/after_decrease_stock_levels` (priority 20+)
2. `atum/purchase_orders/po/after_increase_stock_levels` (priority 20+)
3. `woocommerce_saved_order_items` (priority 20+)
4. `woocommerce_order_status_changed` (for status transitions)
5. `woocommerce_payment_complete` (for payment completion)

**Implementation:**
```php
// In Sync.php constructor or separate hook file
add_action( 'atum/purchase_orders/po/after_decrease_stock_levels', function( $order ) {
    self::get_instance()->sync_bom_to_analytics( $order->get_id() );
}, 20 );

add_action( 'atum/purchase_orders/po/after_increase_stock_levels', function( $order ) {
    self::get_instance()->sync_bom_to_analytics( $order->get_id() );
}, 20 );

add_action( 'woocommerce_saved_order_items', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order ) {
        self::get_instance()->sync_bom_to_analytics( $order_id );
    }
}, 20 );

add_action( 'woocommerce_order_status_changed', function( $order_id, $old_status, $new_status, $order ) {
    // Only sync on status changes that affect stock
    $sync_statuses = ['processing', 'completed', 'cancelled', 'refunded'];
    if ( in_array( $new_status, $sync_statuses ) ) {
        self::get_instance()->sync_bom_to_analytics( $order_id );
    }
}, 20, 4 );
```

---

## File Structure for Helper Plugin

```
atum-product-levels-analytics-helper/
├── atum-product-levels-analytics-helper.php  (Main plugin file)
├── README.md
├── LICENSE
├── assets/
│   ├── css/
│   │   └── analytics-status.css
│   └── js/
│       └── analytics-status.js
└── includes/
    └── Analytics/
        ├── Sync.php
        ├── BackfillTool.php
        └── StatusPage.php
```

---

## Code Modifications Required

### 1. Namespace Changes

**Original:** `AtumLevels\Inc\Analytics`
**New:** `AtumPLAnalyticsHelper\Analytics`

**Files Affected:**
- `Sync.php`
- `BackfillTool.php`
- `StatusPage.php`

---

### 2. Constant Changes

**Original Constants:**
- `ATUM_LEVELS_TEXT_DOMAIN` → `ATUM_PL_ANALYTICS_TEXT_DOMAIN`
- `ATUM_LEVELS_URL` → `ATUM_PL_ANALYTICS_URL`
- `ATUM_LEVELS_VERSION` → `ATUM_PL_ANALYTICS_VERSION`

**Implementation:**
```php
// In main plugin file
if ( ! defined( 'ATUM_PL_ANALYTICS_VERSION' ) ) {
    define( 'ATUM_PL_ANALYTICS_VERSION', '1.0.0' );
}

if ( ! defined( 'ATUM_PL_ANALYTICS_URL' ) ) {
    define( 'ATUM_PL_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ATUM_PL_ANALYTICS_PATH' ) ) {
    define( 'ATUM_PL_ANALYTICS_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ATUM_PL_ANALYTICS_TEXT_DOMAIN' ) ) {
    define( 'ATUM_PL_ANALYTICS_TEXT_DOMAIN', 'atum-product-levels-analytics-helper' );
}
```

---

### 3. Dependency Access Pattern

**Pattern for accessing main plugin classes:**

```php
// Instead of direct use statements
use AtumLevels\Inc\Globals;
use AtumLevels\Models\BOMOrderItemsModel;

// Use class_exists checks
if ( class_exists( '\AtumLevels\Inc\Globals' ) ) {
    $product_levels = \AtumLevels\Inc\Globals::get_product_levels();
}

if ( class_exists( '\AtumLevels\Models\BOMOrderItemsModel' ) ) {
    $bom_items = \AtumLevels\Models\BOMOrderItemsModel::get_bom_order_items( $item_id, 1, TRUE );
}
```

---

### 4. Sync.php Modifications

**Key Changes:**

1. **Namespace:**
   ```php
   namespace AtumPLAnalyticsHelper\Analytics;
   ```

2. **Dependency Checks:**
   ```php
   public function ensure_product_part_tracking( $product, $item ) {
       if ( ! class_exists( '\AtumLevels\Inc\Globals' ) ) {
           return $product;
       }
       
       if ( $product && in_array( $product->get_type(), \AtumLevels\Inc\Globals::get_product_levels(), TRUE ) ) {
           return $product;
       }
       return $product;
   }
   ```

3. **BOM Data Access:**
   ```php
   public function sync_bom_to_analytics( $order_id ) {
       // ... existing code ...
       
       foreach ( $order_items as $item_id => $item ) {
           // Check class exists before calling
           if ( ! class_exists( '\AtumLevels\Models\BOMOrderItemsModel' ) ) {
               continue;
           }
           
           $bom_items = \AtumLevels\Models\BOMOrderItemsModel::get_bom_order_items( $item_id, 1, TRUE );
           // ... rest of code ...
       }
   }
   ```

4. **Hook Registration:**
   ```php
   private function __construct() {
       // Register WooCommerce Analytics hooks
       add_filter( 'woocommerce_analytics_products_excluded_product_types', array( $this, 'include_product_parts_in_analytics' ) );
       add_filter( 'woocommerce_order_item_product', array( $this, 'ensure_product_part_tracking' ), 10, 2 );
       
       // Hook into order processing (replacing Orders.php integration)
       $this->register_order_hooks();
   }
   
   private function register_order_hooks() {
       // Hook into same actions that Orders.php uses
       add_action( 'atum/purchase_orders/po/after_decrease_stock_levels', array( $this, 'sync_order_boms' ), 20 );
       add_action( 'atum/purchase_orders/po/after_increase_stock_levels', array( $this, 'sync_order_boms' ), 20 );
       add_action( 'woocommerce_saved_order_items', array( $this, 'sync_order_boms_by_id' ), 20 );
       add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order_boms_on_status_change' ), 20, 4 );
       add_action( 'woocommerce_payment_complete', array( $this, 'sync_order_boms_by_id' ), 20 );
   }
   
   public function sync_order_boms( $order ) {
       if ( is_numeric( $order ) ) {
           $order = wc_get_order( $order );
       }
       if ( $order ) {
           $this->sync_bom_to_analytics( $order->get_id() );
       }
   }
   
   public function sync_order_boms_by_id( $order_id ) {
       $this->sync_bom_to_analytics( $order_id );
   }
   
   public function sync_order_boms_on_status_change( $order_id, $old_status, $new_status, $order ) {
       $sync_statuses = array( 'processing', 'completed', 'cancelled', 'refunded' );
       if ( in_array( $new_status, $sync_statuses ) ) {
           $this->sync_bom_to_analytics( $order_id );
       }
   }
   ```

---

### 5. BackfillTool.php Modifications

**Key Changes:**

1. **Namespace:**
   ```php
   namespace AtumPLAnalyticsHelper\Analytics;
   ```

2. **Sync Class Reference:**
   ```php
   // Change from:
   $sync = Sync::get_instance();
   
   // To:
   $sync = \AtumPLAnalyticsHelper\Analytics\Sync::get_instance();
   ```

3. **Option Name:**
   ```php
   // Change option name to avoid conflicts
   const PROGRESS_OPTION = 'atum_pl_analytics_helper_backfill_progress';
   const SCHEDULER_HOOK = 'atum_pl_analytics_helper_backfill_batch';
   ```

---

### 6. StatusPage.php Modifications

**Key Changes:**

1. **Namespace:**
   ```php
   namespace AtumPLAnalyticsHelper\Analytics;
   ```

2. **Menu Integration:**
   ```php
   // Hook into ATUM menu (check if exists)
   public function add_menu_page() {
       // Check if ATUM menu exists
       if ( ! menu_page_url( 'atum-dashboard', false ) ) {
           // Fallback: Add to WooCommerce menu
           add_submenu_page(
               'woocommerce',
               __( 'BOM Analytics Status', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
               __( 'BOM Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
               'manage_woocommerce',
               'atum-bom-analytics',
               array( $this, 'render_page' )
           );
       } else {
           // Use ATUM menu
           add_submenu_page(
               'atum-dashboard',
               __( 'BOM Analytics Status', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
               __( 'BOM Analytics', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
               'manage_woocommerce',
               'atum-bom-analytics',
               array( $this, 'render_page' )
           );
       }
   }
   ```

3. **Asset URLs:**
   ```php
   public function enqueue_scripts( $hook ) {
       if ( 'atum-inventory_page_atum-bom-analytics' !== $hook && 'woocommerce_page_atum-bom-analytics' !== $hook ) {
           return;
       }
       
       wp_enqueue_style( 
           'atum-pl-analytics-status', 
           ATUM_PL_ANALYTICS_URL . 'assets/css/analytics-status.css', 
           array(), 
           ATUM_PL_ANALYTICS_VERSION 
       );
       wp_enqueue_script( 
           'atum-pl-analytics-status', 
           ATUM_PL_ANALYTICS_URL . 'assets/js/analytics-status.js', 
           array( 'jquery' ), 
           ATUM_PL_ANALYTICS_VERSION, 
           TRUE 
       );
       
       wp_localize_script( 'atum-pl-analytics-status', 'atumPLAnalytics', array(
           'nonce'    => wp_create_nonce( 'atum-pl-analytics' ),
           'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
           'messages' => array(
               'backfillStarted'  => __( 'Backfill started...', ATUM_PL_ANALYTICS_TEXT_DOMAIN ),
               // ... etc
           ),
       ) );
   }
   ```

4. **Class References:**
   ```php
   // Update all Sync::get_instance() calls
   $sync_status = \AtumPLAnalyticsHelper\Analytics\Sync::get_instance()->get_sync_status();
   $backfill_status = \AtumPLAnalyticsHelper\Analytics\BackfillTool::get_instance()->get_progress();
   ```

---

### 7. JavaScript Modifications

**File:** `assets/js/analytics-status.js`

**Changes:**
- No changes needed (already uses localized object `atumPLAnalytics`)
- Ensure localization uses correct text domain

---

### 8. CSS Modifications

**File:** `assets/css/analytics-status.css`

**Changes:**
- No changes needed (pure CSS, no PHP dependencies)

---

## Main Plugin Bootstrap File

**File:** `atum-product-levels-analytics-helper.php`

```php
<?php
/**
 * Plugin Name: ATUM Product Levels Analytics Helper
 * Plugin URI: https://github.com/your-repo/atum-product-levels-analytics-helper
 * Description: Adds BOM Analytics integration for ATUM Product Levels without modifying the main plugin.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://your-site.com
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, atum-product-levels
 * Text Domain: atum-product-levels-analytics-helper
 * License: GPL v2 or later
 */

defined( 'ABSPATH' ) || die;

// Define constants
if ( ! defined( 'ATUM_PL_ANALYTICS_VERSION' ) ) {
    define( 'ATUM_PL_ANALYTICS_VERSION', '1.0.0' );
}

if ( ! defined( 'ATUM_PL_ANALYTICS_URL' ) ) {
    define( 'ATUM_PL_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ATUM_PL_ANALYTICS_PATH' ) ) {
    define( 'ATUM_PL_ANALYTICS_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ATUM_PL_ANALYTICS_TEXT_DOMAIN' ) ) {
    define( 'ATUM_PL_ANALYTICS_TEXT_DOMAIN', 'atum-product-levels-analytics-helper' );
}

/**
 * Initialize the plugin
 */
function atum_pl_analytics_helper_init() {
    // Check if main plugin is active
    if ( ! class_exists( '\AtumLevels\Models\BOMOrderItemsModel' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'ATUM Product Levels Analytics Helper', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></strong>: 
                    <?php esc_html_e( 'This plugin requires ATUM Product Levels to be installed and activated.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?>
                </p>
            </div>
            <?php
        } );
        return;
    }
    
    // Check if WooCommerce is active
    if ( ! function_exists( 'wc' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'ATUM Product Levels Analytics Helper', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?></strong>: 
                    <?php esc_html_e( 'This plugin requires WooCommerce to be installed and activated.', ATUM_PL_ANALYTICS_TEXT_DOMAIN ); ?>
                </p>
            </div>
            <?php
        } );
        return;
    }
    
    // Load plugin classes
    require_once ATUM_PL_ANALYTICS_PATH . 'includes/Analytics/Sync.php';
    require_once ATUM_PL_ANALYTICS_PATH . 'includes/Analytics/BackfillTool.php';
    
    // Initialize Sync (handles hooks for product type registration and order syncing)
    \AtumPLAnalyticsHelper\Analytics\Sync::get_instance();
    
    // Initialize Status Page (admin menu and AJAX handlers)
    if ( is_admin() ) {
        require_once ATUM_PL_ANALYTICS_PATH . 'includes/Analytics/StatusPage.php';
        \AtumPLAnalyticsHelper\Analytics\StatusPage::get_instance();
    }
}

// Initialize after plugins are loaded (priority 20 = after main plugin)
add_action( 'plugins_loaded', 'atum_pl_analytics_helper_init', 20 );

/**
 * Load plugin textdomain
 */
function atum_pl_analytics_helper_load_textdomain() {
    load_plugin_textdomain( 
        ATUM_PL_ANALYTICS_TEXT_DOMAIN, 
        false, 
        dirname( plugin_basename( __FILE__ ) ) . '/languages' 
    );
}
add_action( 'plugins_loaded', 'atum_pl_analytics_helper_load_textdomain' );
```

---

## Testing Checklist

### Pre-Implementation
- [ ] Verify main plugin is active
- [ ] Verify WooCommerce is active
- [ ] Verify Analytics tables exist
- [ ] Verify BOM tables exist

### Functionality Tests
- [ ] Sync runs on new orders
- [ ] Sync runs on order status changes
- [ ] Sync runs on purchase orders
- [ ] Backfill tool works
- [ ] Status page displays correctly
- [ ] AJAX handlers work
- [ ] Progress polling works

### Compatibility Tests
- [ ] Works when main plugin is deactivated (graceful degradation)
- [ ] Works when WooCommerce is deactivated (graceful degradation)
- [ ] No conflicts with other plugins
- [ ] No PHP errors/warnings

### Edge Cases
- [ ] Orders with no BOMs
- [ ] Orders with multiple BOMs
- [ ] Refunded orders
- [ ] Cancelled orders
- [ ] Large backfill operations

---

## Migration Path

### For Existing Installations

1. **If analytics integration already exists in main plugin:**
   - Deactivate helper plugin initially
   - Remove analytics code from main plugin
   - Activate helper plugin
   - Run backfill to ensure data consistency

2. **If starting fresh:**
   - Install helper plugin
   - Run backfill to sync historical data

---

## Rollback Plan

If issues occur:

1. Deactivate helper plugin
2. Original functionality remains intact (no modifications to main plugin)
3. Analytics data remains in `wc_order_product_lookup` table
4. Can reactivate after fixes

---

## Future Enhancements

1. **CLI Commands:** WP-CLI commands for syncing
2. **Scheduled Sync:** Automatic periodic backfills
3. **Better Error Logging:** Detailed error logs per order
4. **Performance Optimization:** Batch processing improvements
5. **Multi-site Support:** Enhanced multisite compatibility

---

## Summary

This strategy extracts the analytics integration into a standalone plugin by:

1. ✅ Using WordPress hooks instead of code modifications
2. ✅ Accessing main plugin classes via `class_exists()` checks
3. ✅ Maintaining all original functionality
4. ✅ Providing graceful degradation
5. ✅ Using separate namespace and constants
6. ✅ Zero impact on original plugin

The helper plugin will work seamlessly alongside the main plugin, providing the same analytics integration functionality without requiring any modifications to the original codebase.

