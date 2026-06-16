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

		// Translations for WordPress.org-hosted plugins are loaded automatically
		// since WP 4.6 — no load_plugin_textdomain() call is needed.
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		// WC is required.
		if ( ! class_exists( '\WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'render_wc_required_notice' ) );
			return;
		}

		// Now that we know WC_Payment_Gateway exists, load our gateway class.
		// Doing this eagerly at the top of the main plugin file produced a
		// fatal in installs where this plugin loads before WooCommerce — a
		// folder name beginning with "a" (e.g. "allscale-checkout-...") sorts
		// before "woocommerce" so WP's alphabetical plugin loader hits us
		// first, and our `class Gateway extends \WC_Payment_Gateway` can't
		// resolve its parent at declaration time.
		require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-gateway.php';

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
		// Lazy-load so the class declaration (which extends a WC-Blocks parent)
		// only runs after we know that parent class is available — avoids a
		// fatal-error race when this plugin loads before WC.
		require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-blocks-integration.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Blocks_Integration() );
			}
		);
	}

	public function render_wc_required_notice() {
		// Only show to users who could act on it.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Distinguish "not installed" from "installed but inactive" so the CTA
		// can deep-link to the right thing.
		$wc_plugin_file = 'woocommerce/woocommerce.php';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$is_installed = isset( $installed[ $wc_plugin_file ] );

		if ( ! $is_installed && current_user_can( 'install_plugins' ) ) {
			$cta_url   = wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ),
				'install-plugin_woocommerce'
			);
			$cta_label = __( 'Install WooCommerce', 'allscale-checkout' );
			$sub_text  = __( "Allscale Checkout is a WooCommerce payment gateway. WooCommerce isn't installed on this site yet — install it once, and we'll take it from there.", 'allscale-checkout' );
		} elseif ( $is_installed ) {
			$cta_url   = wp_nonce_url(
				self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $wc_plugin_file ) ),
				'activate-plugin_' . $wc_plugin_file
			);
			$cta_label = __( 'Activate WooCommerce', 'allscale-checkout' );
			$sub_text  = __( "Allscale Checkout is a WooCommerce payment gateway. You already have WooCommerce installed — activate it and you're ready to go.", 'allscale-checkout' );
		} else {
			// No capability to install + not installed — degrade to plain notice.
			$cta_url   = '';
			$cta_label = '';
			$sub_text  = __( 'Allscale Checkout is a WooCommerce payment gateway. Ask your site administrator to install WooCommerce.', 'allscale-checkout' );
		}

		$icon_url = plugins_url( 'assets/icon.png', ALLSCALE_CHECKOUT_FILE );

		// Self-contained inline styles — admin.css isn't enqueued on every
		// admin page, and we want this notice to render correctly anywhere.
		?>
		<div class="notice allscale-wc-required-notice" style="
			position: relative;
			border: 1px solid #c3c4c7;
			border-left: 4px solid #0f9b8e;
			background: linear-gradient(135deg, #e6f6f4 0%, #ffffff 60%);
			padding: 18px 22px;
			margin: 14px 20px 5px 2px;
			overflow: hidden;
			border-radius: 4px;
		">
			<div aria-hidden="true" style="position: absolute; right: -12px; bottom: -18px; opacity: 0.07; pointer-events: none;">
				<img src="<?php echo esc_url( $icon_url ); ?>" alt="" style="width: 140px; height: auto; display: block;" />
			</div>
			<div style="display: flex; gap: 14px; align-items: flex-start; position: relative;">
				<img src="<?php echo esc_url( $icon_url ); ?>" alt="" style="height: 36px; width: auto; flex: 0 0 auto;" />
				<div style="flex: 1; min-width: 0;">
					<div style="font-size: 16px; font-weight: 600; color: #1d2327; margin-bottom: 4px;">
						<?php esc_html_e( 'Allscale Checkout needs WooCommerce', 'allscale-checkout' ); ?>
					</div>
					<div style="font-size: 13.5px; color: #50575e; line-height: 1.5; margin-bottom: 12px; max-width: 640px;">
						<?php echo esc_html( $sub_text ); ?>
					</div>
					<?php if ( $cta_url ) : ?>
						<a href="<?php echo esc_url( $cta_url ); ?>" style="
							display: inline-flex;
							align-items: center;
							height: 36px;
							padding: 0 16px;
							background: #0f9b8e;
							color: #fff;
							font-size: 14px;
							font-weight: 500;
							text-decoration: none;
							border-radius: 4px;
							border: 1px solid #0f9b8e;
							box-shadow: none;
							line-height: 1;
						" onmouseover="this.style.background='#0c857a';this.style.borderColor='#0c857a';" onmouseout="this.style.background='#0f9b8e';this.style.borderColor='#0f9b8e';">
							<?php echo esc_html( $cta_label ); ?> &rarr;
						</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return bool
	 */
	private function is_debug_enabled() {
		$s = self::settings();
		return ! empty( $s['debug_logging'] ) && 'yes' === $s['debug_logging'];
	}
}
