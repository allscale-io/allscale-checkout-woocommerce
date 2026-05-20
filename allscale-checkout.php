<?php
/**
 * Plugin Name: Allscale Checkout for WooCommerce
 * Plugin URI: https://github.com/allscale-io/allscale-checkout-woocommerce
 * Description: Accept crypto payments — 0.5% fees, instant USDT settlement directly to your wallet. Non-custodial: your funds are never held by a third party. Requires an <a href="https://allscale.io">Allscale account</a>.
 * Version: 1.0.0
 * Author: AllScale community
 * Author URI: https://allscale.io
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: allscale-checkout
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.6
 *
 * @package Allscale\Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALLSCALE_CHECKOUT_VERSION', '1.0.0' );
define( 'ALLSCALE_CHECKOUT_FILE', __FILE__ );
define( 'ALLSCALE_CHECKOUT_PATH', plugin_dir_path( __FILE__ ) );

// Load all classes. No autoloader — the file count is small and explicit
// require_once is the most diagnosable path for a single-purpose plugin.
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-signer.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-currency.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-status-codes.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-logger.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-error-messages.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-api-result.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-api-client.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-order-locker.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-status-mapper.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-webhook-handler.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-gateway.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-settings-validator.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-admin.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-blocks-integration.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-migrations.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-setup-wizard.php';
require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		\Allscale\Checkout\Plugin::instance()->boot();
	}
);

// Activation hook — flag a one-time redirect to the setup wizard.
register_activation_hook( __FILE__, array( '\Allscale\Checkout\Setup_Wizard', 'on_activation' ) );
