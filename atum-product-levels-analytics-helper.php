<?php
/**
 * Plugin Name: ATUM Product Levels Analytics Helper
 * Plugin URI: https://github.com/your-repo/atum-product-levels-analytics-helper
 * Description: Adds BOM Analytics integration for ATUM Product Levels without modifying the main plugin. Syncs BOM consumption data to WooCommerce Analytics.
 * Version: 1.0.0
 * Author: WooCommerce CS Team
 * Author URI: https://woocommerce.com
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, atum-product-levels
 * Text Domain: atum-product-levels-analytics-helper
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AtumPLAnalyticsHelper
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

if ( ! defined( 'ATUM_PL_ANALYTICS_BASENAME' ) ) {
	define( 'ATUM_PL_ANALYTICS_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Initialize the plugin
 *
 * @since 1.0.0
 */
function atum_pl_analytics_helper_init() {

	// Check if main plugin is active
	// Try multiple possible class names/paths
	$bom_class_exists = class_exists( '\AtumLevels\Models\BOMOrderItemsModel' );
	
	if ( ! $bom_class_exists ) {
		// Try alternative class path (without leading backslash)
		$bom_class_exists = class_exists( 'AtumLevels\Models\BOMOrderItemsModel' );
	}
	
	if ( ! $bom_class_exists ) {
		// Even if class not found, still initialize StatusPage so menu can register
		// The menu registration will check for the class when it runs
		// Don't return - continue to initialize StatusPage
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
	// Must initialize immediately - admin_menu fires before admin_init
	// StatusPage will handle admin checks internally
	require_once ATUM_PL_ANALYTICS_PATH . 'includes/Analytics/StatusPage.php';
	
	\AtumPLAnalyticsHelper\Analytics\StatusPage::get_instance();
}

// Initialize after plugins are loaded - use higher priority to ensure ATUM is loaded first
// ATUM typically loads on priority 10, so we use 20+ to ensure it's ready
add_action( 'plugins_loaded', 'atum_pl_analytics_helper_init', 30 );

/**
 * Load plugin textdomain
 *
 * @since 1.0.0
 */
function atum_pl_analytics_helper_load_textdomain() {
	load_plugin_textdomain(
		ATUM_PL_ANALYTICS_TEXT_DOMAIN,
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'atum_pl_analytics_helper_load_textdomain' );

