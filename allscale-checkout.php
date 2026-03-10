<?php
/**
 * Plugin Name: Allscale Checkout for WooCommerce
 * Description: Accept crypto payments via Allscale. Prices in local currency, settled as USDT stablecoin.
 * Version: 1.0.0
 * Author: Shawn Pang
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.6
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALLSCALE_CHECKOUT_VERSION', '1.0.0');
define('ALLSCALE_CHECKOUT_PATH', plugin_dir_path(__FILE__));

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Add Settings link on the Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=allscale_checkout');
    array_unshift($links, '<a href="' . esc_url($settings_url) . '">Settings</a>');
    return $links;
});

add_action('plugins_loaded', 'allscale_checkout_init');

function allscale_checkout_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Allscale Checkout</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-allscale-api.php';
    require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-allscale-webhook.php';
    require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-allscale-gateway.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Allscale_Gateway';
        return $gateways;
    });
}
