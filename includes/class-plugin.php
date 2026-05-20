<?php
/**
 * Singleton bootstrap — the single place every WP hook is registered.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	// Standalone option keys (separate from WC's gateway-settings array).
	const OPT_VERSION                = 'allscale_checkout_version';
	const OPT_LAST_WEBHOOK_AT        = 'allscale_checkout_last_webhook_at';
	const OPT_FIRST_WEBHOOK_AT       = 'allscale_checkout_first_webhook_at';
	const OPT_FIRST_WEBHOOK_DISMISSED = 'allscale_checkout_first_webhook_dismissed';
	const OPT_LAST_PING_AT           = 'allscale_checkout_last_ping_at';
	const OPT_SHOW_SANDBOX_NOTICE    = 'allscale_checkout_show_sandbox_notice';

	/** @var Plugin|null */
	private static $instance = null;

	private $booted = false;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Read the gateway's stored settings from the WC options array.
	 *
	 * @return array
	 */
	public static function settings() {
		$opts = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
		return is_array( $opts ) ? $opts : array();
	}

	/**
	 * Wire up the plugin.
	 *
	 * Called from the main plugin file at the bottom of `plugins_loaded`.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		// WC is required.
		if ( ! class_exists( '\WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'render_wc_required_notice' ) );
			return;
		}

		// Webhook handler is registered unconditionally — does not depend on
		// the gateway constructor running.
		$webhook = new Webhook_Handler( new Logger( $this->is_debug_enabled() ) );
		$webhook->register();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_integration' ) );

		Settings_Validator::register();

		if ( is_admin() ) {
			Admin::register();
			Setup_Wizard::register();
		}

		Migrations::maybe_run();
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'allscale-checkout',
			false,
			dirname( plugin_basename( ALLSCALE_CHECKOUT_FILE ) ) . '/languages'
		);
	}

	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ALLSCALE_CHECKOUT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ALLSCALE_CHECKOUT_FILE, true );
		}
	}

	public function register_gateway( $gateways ) {
		$gateways[] = '\Allscale\Checkout\Gateway';
		return $gateways;
	}

	public function register_blocks_integration() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Blocks_Integration() );
			}
		);
	}

	public function render_wc_required_notice() {
		echo '<div class="notice notice-error"><p><strong>Allscale Checkout</strong> ';
		esc_html_e( 'requires WooCommerce to be installed and active.', 'allscale-checkout' );
		echo '</p></div>';
	}

	/**
	 * @return bool
	 */
	private function is_debug_enabled() {
		$s = self::settings();
		return ! empty( $s['debug_logging'] ) && 'yes' === $s['debug_logging'];
	}
}
