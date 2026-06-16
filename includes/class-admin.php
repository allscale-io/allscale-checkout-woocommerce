<?php
/**
 * WordPress admin surface: settings page render, order meta box,
 * test-connection AJAX endpoint, admin notices.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	const AJAX_TEST_CONNECTION = 'allscale_test_connection';
	const NONCE_ACTION         = 'allscale_admin';

	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_TEST_CONNECTION, array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		// Settings link on the Plugins page.
		add_filter(
			'plugin_action_links_' . plugin_basename( ALLSCALE_CHECKOUT_FILE ),
			array( __CLASS__, 'plugin_action_links' )
		);

		// Order meta box.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
	}

	// ----------------------------------------------------------------------
	// Plugin action links (Plugins page)
	// ----------------------------------------------------------------------

	public static function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID );
		$wizard_url   = Setup_Wizard::url( 1 );
		$opts         = Plugin::settings();
		$has_creds    = ! empty( $opts['api_key'] ) && ! empty( $opts['api_secret'] );

		$new = array(
			'settings' => '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'allscale-checkout' ) . '</a>',
		);
		// Surface the wizard prominently until credentials are configured.
		if ( ! $has_creds ) {
			$new['wizard'] = '<a href="' . esc_url( $wizard_url ) . '" style="font-weight: 600;">' . esc_html__( 'Setup wizard', 'allscale-checkout' ) . '</a>';
		}
		return array_merge( $new, $links );
	}

	// ----------------------------------------------------------------------
	// Asset enqueue
	// ----------------------------------------------------------------------

	/**
	 * @param string $hook_suffix Current admin page slug.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		// Only on the WC settings page for our section. These read-only $_GET
		// values are for page detection (no state change), but we still unslash
		// and sanitize them to satisfy static analysis and defense-in-depth.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_settings_page = ( $hook_suffix === 'woocommerce_page_wc-settings' )
			&& 'checkout' === $tab
			&& Gateway::ID === $section;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_order_screen = $screen && ( $screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders' );

		if ( ! $is_settings_page && ! $is_order_screen ) {
			return;
		}

		wp_enqueue_style(
			'allscale-checkout-admin',
			plugins_url( 'assets/css/admin.css', ALLSCALE_CHECKOUT_FILE ),
			array(),
			ALLSCALE_CHECKOUT_VERSION
		);

		if ( $is_settings_page ) {
			wp_enqueue_script(
				'allscale-checkout-admin',
				plugins_url( 'assets/js/admin.js', ALLSCALE_CHECKOUT_FILE ),
				array(),
				ALLSCALE_CHECKOUT_VERSION,
				true
			);
			wp_localize_script(
				'allscale-checkout-admin',
				'AllscaleAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
					'action'  => self::AJAX_TEST_CONNECTION,
					'i18n'    => array(
						'testConnection' => __( 'Test connection', 'allscale-checkout' ),
						'testing'        => __( 'Testing connection…', 'allscale-checkout' ),
						'notTested'      => __( 'Not tested', 'allscale-checkout' ),
						'connected'      => __( 'Connected', 'allscale-checkout' ),
						'testFailed'     => __( 'Test failed', 'allscale-checkout' ),
						'copied'         => __( 'Copied', 'allscale-checkout' ),
						'copy'           => __( 'Copy', 'allscale-checkout' ),
						'show'           => __( 'Show', 'allscale-checkout' ),
						'hide'           => __( 'Hide', 'allscale-checkout' ),
						'networkErr'     => __( "Couldn't reach Allscale — try again in a moment.", 'allscale-checkout' ),
					),
				)
			);
		}
	}

	// ----------------------------------------------------------------------
	// AJAX: Test connection
	// ----------------------------------------------------------------------

	public static function ajax_test_connection() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'allscale-checkout' ) ), 403 );
		}

		$api_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$api_secret = isset( $_POST['api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['api_secret'] ) ) : '';

		// Empty field → fall back to stored values (the merchant can test the
		// already-saved credentials without re-pasting the secret).
		$stored = Plugin::settings();
		if ( $api_key === '' ) {
			$api_key = isset( $stored['api_key'] ) ? (string) $stored['api_key'] : '';
		}
		if ( $api_secret === '' ) {
			$api_secret = isset( $stored['api_secret'] ) ? (string) $stored['api_secret'] : '';
		}

		if ( $api_key === '' || $api_secret === '' ) {
			wp_send_json_error(
				array(
					'code'    => null,
					'message' => __( 'Enter both API key and API secret first.', 'allscale-checkout' ),
				)
			);
		}

		$logger = new Logger( false );
		$api    = new Api_Client( $api_key, $api_secret, $logger );
		$result = $api->test_ping();

		if ( $result->success ) {
			update_option( Plugin::OPT_LAST_PING_AT, time(), false );
			wp_send_json_success(
				array(
					'message' => __( 'Connected', 'allscale-checkout' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'code'    => $result->error_code,
				'message' => Error_Messages::for_admin( $result->error_code, $result->error_message ),
			)
		);
	}

	// ----------------------------------------------------------------------
	// Settings page render (called from Gateway::admin_options)
	// ----------------------------------------------------------------------

	/**
	 * Render the full settings UI matching the design.
	 *
	 * @param Gateway $gateway The gateway instance.
	 */
	public static function render_settings_page( Gateway $gateway ) {
		$opts = Plugin::settings();
		$has_credentials = ! empty( $opts['api_key'] ) && ! empty( $opts['api_secret'] );

		$store_currency = get_woocommerce_currency();
		$currency_supported = Currency::is_supported( $store_currency )
			|| 'yes' === $gateway->get_option( 'use_stable_coin_pricing' );

		$webhook_url = self::webhook_url();
		$webhook_status = self::webhook_status_info();

		// Only treat the cached "ping OK" as authoritative if credentials are present;
		// otherwise the pill resets to "Not tested" rather than stale-claiming "Connected".
		$last_ping_ok = $has_credentials && (int) get_option( Plugin::OPT_LAST_PING_AT, 0 ) > 0;

		// Echo using esc_* on every dynamic value.
		?>
		<div class="allscale-admin">
			<header class="as-page-header">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>" class="as-back-link">
					&larr; <?php esc_html_e( 'Back to Payments', 'allscale-checkout' ); ?>
				</a>
				<div class="as-page-title-row">
					<img class="as-mark" src="<?php echo esc_url( plugins_url( 'assets/icon.png', ALLSCALE_CHECKOUT_FILE ) ); ?>" alt="" width="36" />
					<div>
						<h2 class="as-page-title"><?php esc_html_e( 'Allscale Checkout', 'allscale-checkout' ); ?></h2>
						<div class="as-page-tagline">
							<?php esc_html_e( 'Accept crypto payments — 0.6% fees (min $0.10), instant USDT settlement to your own wallet.', 'allscale-checkout' ); ?>
						</div>
					</div>
				</div>
			</header>

			<?php if ( ! $has_credentials ) : ?>
				<?php self::render_welcome_banner(); ?>
			<?php endif; ?>

			<?php if ( ! $currency_supported ) : ?>
				<div class="as-notice as-notice-warning">
					<div class="as-notice-icon"><span class="dashicons dashicons-warning"></span></div>
					<div class="as-notice-body">
						<div class="as-notice-title">
							<?php
							printf(
								/* translators: %s is the ISO currency code */
								esc_html__( "Your store currency %s isn't supported by Allscale.", 'allscale-checkout' ),
								'<strong>' . esc_html( $store_currency ) . '</strong>'
							);
							?>
						</div>
						<div class="as-notice-text">
							<?php esc_html_e( 'Supported currencies: USD, EUR, GBP, CAD, AUD, JPY, CNY, SGD, HKD.', 'allscale-checkout' ); ?>
							<?php
							printf(
								wp_kses(
									/* translators: %s is the WooCommerce General settings link */
									__( 'Change your store currency in %s, or enable native USDT pricing in the Payment configuration section below.', 'allscale-checkout' ),
									array( 'a' => array( 'href' => array() ) )
								),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">'
								. esc_html__( 'WooCommerce → Settings → General', 'allscale-checkout' ) . '</a>'
							);
							?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php /* WooCommerce wraps the entire admin_options() output in its own <form>. We do not open another one — that would nest forms and break the save. WC also emits its own "Save changes" button and nonce at the bottom of the page. */ ?>
				<?php // Section 1: Status & visibility ?>
				<section class="as-card">
					<header class="as-card-header">
						<h3><?php esc_html_e( 'Status & visibility', 'allscale-checkout' ); ?></h3>
					</header>
					<div class="as-card-body">
						<?php
						self::form_row(
							__( 'Enable / disable', 'allscale-checkout' ),
							self::render_toggle(
								'woocommerce_' . Gateway::ID . '_enabled',
								'yes' === $gateway->get_option( 'enabled' ),
								__( 'Enable Allscale Checkout', 'allscale-checkout' ),
								__( 'Allow customers to select Allscale at checkout. When off, the gateway is hidden.', 'allscale-checkout' )
							)
						);
						self::form_row(
							__( 'Title shown to customers', 'allscale-checkout' ),
							'<input type="text" class="regular-text" name="woocommerce_' . esc_attr( Gateway::ID ) . '_title" value="'
								. esc_attr( $gateway->get_option( 'title' ) ) . '" />'
								. '<p class="description">' . esc_html__( 'This appears in the payment method list at checkout.', 'allscale-checkout' ) . '</p>'
						);
						self::form_row(
							__( 'Description shown to customers', 'allscale-checkout' ),
							'<textarea rows="2" class="regular-text" name="woocommerce_' . esc_attr( Gateway::ID ) . '_description">'
								. esc_textarea( $gateway->get_option( 'description' ) ) . '</textarea>'
						);
						?>
					</div>
				</section>

				<?php // Section 2: API credentials ?>
				<section class="as-card <?php echo ! $has_credentials ? 'as-card-attention' : ''; ?>">
					<header class="as-card-header">
						<?php if ( ! $has_credentials ) : ?>
							<span class="as-step-num">1</span>
						<?php endif; ?>
						<h3><?php esc_html_e( 'API credentials', 'allscale-checkout' ); ?></h3>
					</header>
					<div class="as-card-body">
						<?php
						$key_value = (string) $gateway->get_option( 'api_key' );
						self::form_row(
							__( 'API key', 'allscale-checkout' ),
							'<input type="text" class="regular-text" name="woocommerce_' . esc_attr( Gateway::ID ) . '_api_key" '
								. 'value="' . esc_attr( $key_value ) . '" placeholder="st_live_…" autocomplete="off" />'
								. '<p class="description">'
								. ( $has_credentials
									? wp_kses(
										__( 'Saved. To replace, paste a new key above.', 'allscale-checkout' ),
										array()
									)
									: wp_kses(
										__( 'Find this in your Allscale dashboard → Developers → API keys.', 'allscale-checkout' ),
										array( 'a' => array( 'href' => array() ) )
									)
								)
								. '</p>'
						);

						$secret_value = (string) $gateway->get_option( 'api_secret' );
						self::form_row(
							__( 'API secret', 'allscale-checkout' ),
							'<div class="as-secret-wrap">'
								. '<input type="password" class="regular-text" name="woocommerce_' . esc_attr( Gateway::ID ) . '_api_secret" '
								. 'value="' . esc_attr( $secret_value ) . '" placeholder="st_live_…" autocomplete="off" data-as-secret-input />'
								. '<button type="button" class="button button-secondary as-toggle-secret" data-as-toggle-secret>'
								. esc_html__( 'Show', 'allscale-checkout' )
								. '</button>'
								. '</div>'
								. '<p class="description">'
								. wp_kses(
									sprintf(
										/* translators: %s is the URL to allscale.io */
										__( "Don't have credentials yet? <a href=\"%s\" target=\"_blank\" rel=\"noopener\">Sign up at allscale.io &rarr;</a>", 'allscale-checkout' ),
										'https://allscale.io'
									),
									array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
								)
								. '</p>'
						);

						$initial_pill = $last_ping_ok ? 'success' : 'idle';
						self::form_row(
							__( 'Connection', 'allscale-checkout' ),
							'<div class="as-test-conn" data-as-test-conn data-initial-state="' . esc_attr( $initial_pill ) . '">'
								. '<button type="button" class="button button-secondary as-test-btn" data-as-test-btn>'
								. '<span class="as-test-btn-label">' . esc_html__( 'Test connection', 'allscale-checkout' ) . '</span>'
								. '</button>'
								. '<span class="as-pill" data-as-test-pill>'
								. '<span class="as-pill-dot dot-' . ( $last_ping_ok ? 'green' : 'gray' ) . '"></span>'
								. '<span class="as-pill-text">'
								. esc_html( $last_ping_ok ? __( 'Connected', 'allscale-checkout' ) : __( 'Not tested', 'allscale-checkout' ) )
								. '</span>'
								. '</span>'
								. '<div class="as-test-error" data-as-test-error hidden></div>'
								. '</div>'
						);
						?>
					</div>
				</section>

				<?php // Section 3: Webhook setup ?>
				<?php
				$webhook_emphasis = ( ! $has_credentials )
					? 'muted'
					: ( 'stale' === $webhook_status['tone'] ? 'attention' : 'normal' );
				?>
				<section class="as-card as-card-<?php echo esc_attr( $webhook_emphasis ); ?>">
					<header class="as-card-header">
						<?php if ( ! $has_credentials ) : ?>
							<span class="as-step-num">2</span>
						<?php endif; ?>
						<h3><?php esc_html_e( 'Webhook setup', 'allscale-checkout' ); ?></h3>
					</header>
					<div class="as-card-body">
						<?php
						self::form_row(
							__( 'Webhook URL', 'allscale-checkout' ),
							'<div class="as-codeblock">'
								. '<code class="as-code" data-as-webhook-url>' . esc_html( $webhook_url ) . '</code>'
								. '<button type="button" class="button button-secondary as-copy-btn" data-as-copy="' . esc_attr( $webhook_url ) . '">'
								. esc_html__( 'Copy', 'allscale-checkout' )
								. '</button>'
								. '</div>'
								. '<p class="description">'
								. esc_html__( "Paste this URL into your Allscale store's webhook setting. We can't configure it for you — Allscale requires you to do it from your dashboard.", 'allscale-checkout' )
								. '</p>'
						);
						self::form_row(
							__( 'Webhook status', 'allscale-checkout' ),
							'<span class="as-pill">'
								. '<span class="as-pill-dot dot-' . esc_attr( $webhook_status['dot'] ) . '"></span>'
								. '<span class="as-pill-text">' . esc_html( $webhook_status['label'] ) . '</span>'
								. '</span>'
								. ( $webhook_status['tone'] === 'stale'
									? '<div class="as-inline-warning">'
										. esc_html__( "Your gateway is enabled but Allscale hasn't sent a webhook in over a week. Check that the URL above is still pasted in your Allscale dashboard, your firewall isn't blocking POSTs, and Allscale hasn't paused webhook delivery.", 'allscale-checkout' )
										. '</div>'
									: '' )
						);
						?>
					</div>
				</section>

				<?php // Section 4: Payment configuration ?>
				<section class="as-card <?php echo ! $has_credentials ? 'as-card-muted' : ''; ?>">
					<header class="as-card-header">
						<h3><?php esc_html_e( 'Payment configuration', 'allscale-checkout' ); ?></h3>
					</header>
					<div class="as-card-body">
						<?php
						$is_curr_supported = Currency::is_supported( $store_currency );
						$currency_cell = '<span class="as-pill">'
							. '<span class="as-pill-dot dot-' . ( $is_curr_supported ? 'green' : 'red' ) . '"></span>'
							. '<span class="as-pill-text">' . esc_html( $store_currency )
							. ( $is_curr_supported ? '' : ' — ' . esc_html__( 'not supported', 'allscale-checkout' ) )
							. '</span>'
							. '</span>'
							. '<p class="description">'
							. esc_html__( 'Inherited from your store currency. Allscale converts to USDT at checkout time.', 'allscale-checkout' )
							. '</p>';
						self::form_row( __( 'Pricing currency', 'allscale-checkout' ), $currency_cell );

						self::form_row(
							__( 'Pricing mode', 'allscale-checkout' ),
							self::render_toggle(
								'woocommerce_' . Gateway::ID . '_use_stable_coin_pricing',
								'yes' === $gateway->get_option( 'use_stable_coin_pricing' ),
								__( 'Use native USDT pricing instead of fiat conversion', 'allscale-checkout' ),
								__( 'Advanced — for stores that want to display USDT amounts directly to customers. Most stores leave this off.', 'allscale-checkout' )
							)
						);
						?>
					</div>
				</section>

				<?php // Section 5: Advanced (collapsible) ?>
				<section class="as-card as-card-collapsible" data-as-collapsible>
					<header class="as-card-header" data-as-collapsible-toggle>
						<h3><?php esc_html_e( 'Advanced', 'allscale-checkout' ); ?></h3>
						<span class="as-chevron" data-as-chevron>&#8964;</span>
					</header>
					<div class="as-card-body" data-as-collapsible-body hidden>
						<?php
						self::form_row(
							__( 'Logging', 'allscale-checkout' ),
							self::render_toggle(
								'woocommerce_' . Gateway::ID . '_debug_logging',
								'yes' === $gateway->get_option( 'debug_logging' ),
								__( 'Enable debug logging', 'allscale-checkout' ),
								sprintf(
									wp_kses(
										/* translators: %s is the linked path to WooCommerce logs */
										__( 'Writes detailed activity to %s.', 'allscale-checkout' ),
										array( 'a' => array( 'href' => array() ) )
									),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">'
									. esc_html__( 'WooCommerce → Status → Logs', 'allscale-checkout' ) . '</a>'
								)
							)
						);
						?>
					</div>
				</section>

			<?php /* No save button: WooCommerce renders its own at the bottom of the wrapping form. */ ?>
		</div>
		<?php
	}

	/**
	 * Render the welcome banner shown in the empty state.
	 */
	private static function render_welcome_banner() {
		?>
		<div class="as-welcome">
			<div class="as-welcome-bg" aria-hidden="true">
				<img src="<?php echo esc_url( plugins_url( 'assets/icon.png', ALLSCALE_CHECKOUT_FILE ) ); ?>" alt="" />
			</div>
			<h3 class="as-welcome-title"><?php esc_html_e( 'Welcome to Allscale Checkout', 'allscale-checkout' ); ?></h3>
			<div class="as-welcome-subtitle"><?php esc_html_e( 'Three steps to start accepting crypto payments:', 'allscale-checkout' ); ?></div>
			<ol class="as-welcome-steps">
				<li><span class="as-step-bullet">1</span><?php esc_html_e( 'Enter your API credentials below', 'allscale-checkout' ); ?></li>
				<li><span class="as-step-bullet">2</span><?php esc_html_e( 'Test the connection', 'allscale-checkout' ); ?></li>
				<li><span class="as-step-bullet">3</span><?php esc_html_e( 'Paste your webhook URL into your Allscale dashboard', 'allscale-checkout' ); ?></li>
			</ol>
			<div class="as-welcome-cta">
				<a class="button button-primary" href="<?php echo esc_url( Setup_Wizard::url( 1 ) ); ?>">
					<?php esc_html_e( 'Run guided setup →', 'allscale-checkout' ); ?>
				</a>
				<span class="as-welcome-cta-sub"><?php esc_html_e( '…or configure manually below.', 'allscale-checkout' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a labeled form row matching the design's two-column grid.
	 *
	 * @param string $label_html  Already-translated label text.
	 * @param string $control_html Fully-rendered control markup.
	 */
	private static function form_row( $label_html, $control_html ) {
		echo '<div class="as-form-row">';
		echo '<div class="as-form-label">' . esc_html( $label_html ) . '</div>';
		// $control_html is composed via esc_* helpers at construction; run it through
		// wp_kses with an allowlist of the form-control elements it can contain.
		echo '<div class="as-form-control">' . wp_kses( $control_html, self::control_allowed_html() ) . '</div>';
		echo '</div>';
	}

	/**
	 * Allowed HTML for form-control markup passed to form_row() / render_toggle().
	 *
	 * @return array
	 */
	private static function control_allowed_html() {
		return array(
			'input'    => array(
				'type'                 => array(),
				'class'                => array(),
				'name'                 => array(),
				'id'                   => array(),
				'value'                => array(),
				'placeholder'          => array(),
				'autocomplete'         => array(),
				'checked'              => array(),
				'data-as-secret-input' => array(),
			),
			'textarea' => array(
				'rows'  => array(),
				'class' => array(),
				'name'  => array(),
			),
			'button'   => array(
				'type'                  => array(),
				'class'                 => array(),
				'data-as-toggle-secret' => array(),
			),
			'label'    => array( 'class' => array(), 'for' => array() ),
			'span'     => array( 'class' => array() ),
			'div'      => array( 'class' => array() ),
			'p'        => array( 'class' => array() ),
			'a'        => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'strong'   => array(),
			'br'       => array(),
		);
	}

	/**
	 * Render a checkbox-as-toggle with label and help text.
	 */
	private static function render_toggle( $name, $checked, $label, $help_html = '' ) {
		$checked_attr = $checked ? 'checked' : '';
		return '<label class="as-toggle">'
			. '<input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" ' . $checked_attr . ' />'
			. '<span class="as-toggle-label">' . esc_html( $label ) . '</span>'
			. ( $help_html ? '<span class="as-toggle-help">' . wp_kses_post( $help_html ) . '</span>' : '' )
			. '</label>';
	}

	// ----------------------------------------------------------------------
	// Webhook URL + status
	// ----------------------------------------------------------------------

	public static function webhook_url() {
		return home_url( '/wc-api/' . Webhook_Handler::WC_API_SLUG );
	}

	/**
	 * Decide what to show in the webhook status pill.
	 *
	 * @return array{tone:string,dot:string,label:string}
	 */
	private static function webhook_status_info() {
		$last = (int) get_option( Plugin::OPT_LAST_WEBHOOK_AT, 0 );
		if ( $last === 0 ) {
			return array(
				'tone'  => 'never',
				'dot'   => 'gray',
				'label' => __( 'Never received yet', 'allscale-checkout' ),
			);
		}

		$elapsed = time() - $last;
		$days    = (int) floor( $elapsed / DAY_IN_SECONDS );

		if ( $days >= 7 ) {
			return array(
				'tone'  => 'stale',
				'dot'   => 'yellow',
				'label' => sprintf(
					/* translators: %d is the number of days */
					_n( 'No webhook in %d day', 'No webhook in %d days', $days, 'allscale-checkout' ),
					$days
				),
			);
		}

		return array(
			'tone'  => 'healthy',
			'dot'   => 'green',
			'label' => sprintf(
				/* translators: %s is a human-readable time difference, e.g. "4 minutes" */
				__( 'Received %s ago', 'allscale-checkout' ),
				human_time_diff( $last, time() )
			),
		);
	}

	// ----------------------------------------------------------------------
	// Admin notices
	// ----------------------------------------------------------------------

	public static function render_notices() {
		// Save notice from Settings_Validator.
		$save_notice = get_transient( 'allscale_settings_save_notice' );
		if ( is_array( $save_notice ) && isset( $save_notice['text'] ) ) {
			delete_transient( 'allscale_settings_save_notice' );
			$type  = isset( $save_notice['type'] ) && in_array( $save_notice['type'], array( 'success', 'error', 'warning', 'info' ), true )
				? $save_notice['type']
				: 'info';
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $save_notice['text'] )
			);
		}

		// First-webhook celebration (one-time).
		$first = (int) get_option( Plugin::OPT_FIRST_WEBHOOK_AT, 0 );
		$first_dismissed = (bool) get_option( Plugin::OPT_FIRST_WEBHOOK_DISMISSED, false );
		if ( $first > 0 && ! $first_dismissed ) {
			echo '<div class="notice notice-success is-dismissible allscale-first-webhook"><p>';
			echo '<strong>' . esc_html__( 'Your store just received its first Allscale webhook.', 'allscale-checkout' ) . '</strong> ';
			esc_html_e( 'Payments will now confirm automatically.', 'allscale-checkout' );
			echo '</p></div>';
			// Self-dismiss on next page load to keep the implementation simple.
			update_option( Plugin::OPT_FIRST_WEBHOOK_DISMISSED, true, false );
		}

		// Sandbox-retired migration notice.
		if ( get_option( Plugin::OPT_SHOW_SANDBOX_NOTICE, false ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>';
			echo '<strong>' . esc_html__( "You've upgraded Allscale Checkout.", 'allscale-checkout' ) . '</strong> ';
			esc_html_e( 'Sandbox mode has been retired — to test without real payments, create a test store in your Allscale dashboard and use its credentials here.', 'allscale-checkout' );
			echo '</p></div>';
			delete_option( Plugin::OPT_SHOW_SANDBOX_NOTICE );
		}

		// Credentials missing notice (only on WC settings list page or our own page).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			$opts = Plugin::settings();
			if ( ! empty( $opts['enabled'] ) && $opts['enabled'] === 'yes' && empty( $opts['api_key'] ) ) {
				$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID );
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Allscale Checkout is enabled but missing API credentials.', 'allscale-checkout' ),
					esc_url( $url ),
					esc_html__( 'Add credentials', 'allscale-checkout' )
				);
			}
		}
	}

	// ----------------------------------------------------------------------
	// Order meta box
	// ----------------------------------------------------------------------

	public static function register_meta_box() {
		// Both legacy CPT shop_order screen and HPOS orders screen.
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_container' )
			&& class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			try {
				$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
				if ( $controller && method_exists( $controller, 'custom_orders_table_usage_is_enabled' ) && $controller->custom_orders_table_usage_is_enabled() ) {
					if ( function_exists( 'wc_get_page_screen_id' ) ) {
						$screens[] = wc_get_page_screen_id( 'shop-order' );
					}
				}
			} catch ( \Throwable $e ) {
				// Container resolution failed — fall back to legacy screen only.
			}
		}

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'allscale_payment',
				__( 'Allscale Payment', 'allscale-checkout' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param mixed $post_or_order Either a WP_Post or a WC_Order depending on HPOS.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof \WC_Order )
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );

		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'allscale-checkout' ) . '</p>';
			return;
		}

		// Only render for orders paid via this gateway.
		if ( Gateway::ID !== $order->get_payment_method() ) {
			echo '<p style="color: var(--wp-text2, #50575e); font-size: 12px;">' . esc_html__( 'This order did not use Allscale.', 'allscale-checkout' ) . '</p>';
			return;
		}

		$intent_id = (string) $order->get_meta( Status_Mapper::META_INTENT_ID );
		if ( $intent_id === '' ) {
			$intent_id = (string) $order->get_meta( '_allscale_checkout_intent_id' );
		}

		$status = (int) $order->get_meta( Status_Mapper::META_STATUS );
		$tx_hash = (string) $order->get_meta( Status_Mapper::META_TX_HASH );
		$chain_id = (int) $order->get_meta( Status_Mapper::META_CHAIN_ID );
		$pmt = (int) $order->get_meta( Status_Mapper::META_PAYMENT_METHOD_TYPE );
		$coin_symbol = (string) $order->get_meta( Status_Mapper::META_COIN_SYMBOL );
		$amount_coins = (string) $order->get_meta( Status_Mapper::META_AMOUNT_COINS );
		$paid = (string) $order->get_meta( Status_Mapper::META_ACTUAL_PAID_AMOUNT );
		$fee = (string) $order->get_meta( Status_Mapper::META_SERVICE_FEE_AMOUNT );
		$net = (string) $order->get_meta( Status_Mapper::META_NET_INCOME_AMOUNT );

		$dot = 'gray';
		if ( $status === Status_Codes::CONFIRMED ) {
			$dot = 'green';
		} elseif ( Status_Codes::is_failure( $status ) ) {
			$dot = 'red';
		} elseif ( $status === Status_Codes::ON_CHAIN || $status === Status_Codes::PENDING_MANUAL_OPERATION ) {
			$dot = 'yellow';
		}

		echo '<div class="allscale-metabox">';
		echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Status', 'allscale-checkout' ) . '</span>';
		echo '<span class="meta-val"><span class="as-pill"><span class="as-pill-dot dot-' . esc_attr( $dot ) . '"></span>'
			. '<span class="as-pill-text">' . esc_html( Status_Codes::label( $status ?: Status_Codes::CREATED ) ) . '</span></span></span></div>';

		// Prefer actual_paid_amount when available (only present after on-chain confirmation).
		$paid_display = '' !== $paid ? $paid : $amount_coins;
		if ( $paid_display !== '' && $coin_symbol !== '' ) {
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Paid', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val"><strong>' . esc_html( $paid_display ) . '</strong> '
				. '<span class="meta-unit">' . esc_html( $coin_symbol ) . '</span></span></div>';
		}
		if ( $fee !== '' ) {
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Fee', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val">' . esc_html( $fee ) . ( $coin_symbol ? ' <span class="meta-unit">' . esc_html( $coin_symbol ) . '</span>' : '' ) . '</span></div>';
		}
		if ( $net !== '' ) {
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Net', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val">' . esc_html( $net ) . ( $coin_symbol ? ' <span class="meta-unit">' . esc_html( $coin_symbol ) . '</span>' : '' ) . '</span></div>';
		}

		if ( $tx_hash !== '' || $chain_id > 0 ) {
			echo '<hr class="meta-hr" />';
		}

		if ( $tx_hash !== '' ) {
			$short = strlen( $tx_hash ) > 12 ? substr( $tx_hash, 0, 6 ) . '…' . substr( $tx_hash, -4 ) : $tx_hash;
			$explorer = self::explorer_url( $chain_id, $tx_hash );
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Tx hash', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val mono">' . esc_html( $short ) . '</span>';
			if ( $explorer ) {
				echo ' <a class="meta-link" href="' . esc_url( $explorer ) . '" target="_blank" rel="noopener">'
					. esc_html__( 'View on chain', 'allscale-checkout' ) . ' &#8599;</a>';
			}
			echo '</div>';
		}

		if ( $chain_id > 0 ) {
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Chain', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val">' . esc_html( self::chain_name( $chain_id ) ) . ' <span class="meta-unit">(' . esc_html( (string) $chain_id ) . ')</span></span></div>';
		}

		if ( $pmt > 0 ) {
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Method', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val">' . esc_html( self::payment_method_name( $pmt ) ) . '</span></div>';
		}

		if ( $intent_id !== '' ) {
			$short_id = strlen( $intent_id ) > 12 ? substr( $intent_id, 0, 4 ) . '…' . substr( $intent_id, -4 ) : $intent_id;
			echo '<div class="meta-row"><span class="meta-label">' . esc_html__( 'Intent ID', 'allscale-checkout' ) . '</span>'
				. '<span class="meta-val mono" title="' . esc_attr( $intent_id ) . '">' . esc_html( $short_id ) . '</span></div>';
		}

		echo '</div>';
	}

	private static function explorer_url( $chain_id, $tx_hash ) {
		$map = array(
			1     => 'https://etherscan.io/tx/',
			10    => 'https://optimistic.etherscan.io/tx/',
			56    => 'https://bscscan.com/tx/',
			137   => 'https://polygonscan.com/tx/',
			8453  => 'https://basescan.org/tx/',
			42161 => 'https://arbiscan.io/tx/',
		);
		return isset( $map[ (int) $chain_id ] ) ? $map[ (int) $chain_id ] . $tx_hash : null;
	}

	private static function chain_name( $chain_id ) {
		$map = array(
			1     => 'Ethereum',
			10    => 'Optimism',
			56    => 'BNB Chain',
			137   => 'Polygon',
			8453  => 'Base',
			42161 => 'Arbitrum',
		);
		return isset( $map[ (int) $chain_id ] ) ? $map[ (int) $chain_id ] : sprintf(
			/* translators: %d is the EIP-155 chain id */
			__( 'Chain %d', 'allscale-checkout' ),
			$chain_id
		);
	}

	private static function payment_method_name( $type ) {
		switch ( (int) $type ) {
			case 1: return __( 'Wallet scan', 'allscale-checkout' );
			case 2: return __( 'WalletConnect', 'allscale-checkout' );
			case 3: return __( 'Allscale Pay', 'allscale-checkout' );
			default: return __( 'Unknown', 'allscale-checkout' );
		}
	}
}
