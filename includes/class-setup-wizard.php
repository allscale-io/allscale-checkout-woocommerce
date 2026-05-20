<?php
/**
 * Setup wizard — first-run onboarding.
 *
 * Four-step flow that lives at admin.php?page=allscale-checkout-setup.
 * Registered as a hidden submenu (parent slug = null), reached via the
 * post-activation redirect or the "Run setup wizard" link in settings.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Setup_Wizard {

	const PAGE_SLUG          = 'allscale-checkout-setup';
	const NONCE              = 'allscale_wizard';
	const ACTIVATION_FLAG    = 'allscale_do_activation_redirect';
	const OPT_COMPLETED_AT   = 'allscale_checkout_wizard_completed_at';
	const OPT_DISMISSED_AT   = 'allscale_checkout_wizard_dismissed_at';
	const TRANSIENT_ERROR    = 'allscale_wizard_error';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_form' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
	}

	/**
	 * Set a transient on plugin activation so we know to redirect to the
	 * wizard on the next admin pageload. Called from the activation hook in
	 * the main plugin file.
	 */
	public static function on_activation() {
		// 30 seconds is enough: the redirect fires on the very next admin
		// pageload after activation. Any later and the merchant has clearly
		// navigated past activation, so skipping the wizard is correct.
		set_transient( self::ACTIVATION_FLAG, 1, 30 );
	}

	public static function register_page() {
		add_submenu_page(
			'', // No parent — hidden from menus, URL-accessible only.
			__( 'Allscale Checkout — Setup', 'allscale-checkout' ),
			__( 'Allscale Setup', 'allscale-checkout' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Redirect once after activation to the wizard if it hasn't been
	 * completed or dismissed.
	 */
	public static function maybe_redirect_after_activation() {
		if ( ! get_transient( self::ACTIVATION_FLAG ) ) {
			return;
		}
		delete_transient( self::ACTIVATION_FLAG );

		// Defensive: don't redirect during bulk activations, AJAX, network admin, REST.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( is_network_admin() ) {
			return;
		}
		// Bulk plugin activation — multiple plugins activated at once.
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( get_option( self::OPT_COMPLETED_AT, 0 ) || get_option( self::OPT_DISMISSED_AT, 0 ) ) {
			return;
		}
		// If credentials are already configured (e.g., a reactivation), skip.
		$settings = Plugin::settings();
		if ( ! empty( $settings['api_key'] ) && ! empty( $settings['api_secret'] ) ) {
			return;
		}

		wp_safe_redirect( self::url( 1 ) );
		exit;
	}

	/**
	 * Handle form POSTs and skip-link clicks on the wizard page.
	 */
	public static function maybe_handle_form() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Skip link — GET with nonce.
		if ( isset( $_GET['action'] ) && 'skip' === $_GET['action'] ) {
			check_admin_referer( self::NONCE );
			update_option( self::OPT_DISMISSED_AT, time(), false );
			wp_safe_redirect( self::settings_url() );
			exit;
		}

		if ( ! isset( $_POST['allscale_wizard_action'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$action = sanitize_key( (string) $_POST['allscale_wizard_action'] );

		switch ( $action ) {
			case 'go_step_2':
				wp_safe_redirect( self::url( 2 ) );
				exit;

			case 'save_credentials':
				self::handle_save_credentials();
				return;

			case 'webhook_done':
				wp_safe_redirect( self::url( 4 ) );
				exit;

			case 'finish':
				update_option( self::OPT_COMPLETED_AT, time(), false );
				wp_safe_redirect( self::settings_url() );
				exit;
		}
	}

	/**
	 * Step 2 form post — validate via /v1/test/ping, persist if good.
	 */
	private static function handle_save_credentials() {
		$api_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$api_secret = isset( $_POST['api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['api_secret'] ) ) : '';

		if ( $api_key === '' || $api_secret === '' ) {
			set_transient( self::TRANSIENT_ERROR, __( 'Both API key and API secret are required.', 'allscale-checkout' ), 30 );
			wp_safe_redirect( self::url( 2 ) );
			exit;
		}

		$logger = new Logger( false );
		$api    = new Api_Client( $api_key, $api_secret, $logger );
		$result = $api->test_ping();

		if ( ! $result->success ) {
			set_transient(
				self::TRANSIENT_ERROR,
				Error_Messages::for_admin( $result->error_code, $result->error_message ),
				30
			);
			wp_safe_redirect( self::url( 2 ) );
			exit;
		}

		// Persist credentials + enable the gateway.
		$settings = Plugin::settings();
		$settings['api_key']    = $api_key;
		$settings['api_secret'] = $api_secret;
		$settings['enabled']    = 'yes';
		// Keep defaults for title/description if they haven't been set yet.
		if ( empty( $settings['title'] ) ) {
			$settings['title'] = __( 'Pay with Crypto (Allscale)', 'allscale-checkout' );
		}
		if ( empty( $settings['description'] ) ) {
			$settings['description'] = __( 'Pay securely with your crypto wallet. Powered by Allscale.', 'allscale-checkout' );
		}
		update_option( 'woocommerce_' . Gateway::ID . '_settings', $settings );
		update_option( Plugin::OPT_LAST_PING_AT, time(), false );

		wp_safe_redirect( self::url( 3 ) );
		exit;
	}

	public static function admin_body_class( $classes ) {
		if ( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes .= ' allscale-wizard-page';
		}
		return $classes;
	}

	public static function url( $step ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . (int) $step );
	}

	public static function settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID );
	}

	public static function skip_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'skip', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			self::NONCE
		);
	}

	// ----------------------------------------------------------------------
	// Render
	// ----------------------------------------------------------------------

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'allscale-checkout' ) );
		}

		// Enqueue both admin.css (shared form/button rules) and admin.js
		// (test-connection AJAX still works inside the wizard).
		wp_enqueue_style(
			'allscale-checkout-admin',
			plugins_url( 'assets/css/admin.css', ALLSCALE_CHECKOUT_FILE ),
			array(),
			ALLSCALE_CHECKOUT_VERSION
		);
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
				'nonce'   => wp_create_nonce( Admin::NONCE_ACTION ),
				'action'  => Admin::AJAX_TEST_CONNECTION,
				'i18n'    => array(
					'testing'    => __( 'Testing connection…', 'allscale-checkout' ),
					'notTested'  => __( 'Not tested', 'allscale-checkout' ),
					'connected'  => __( 'Connected', 'allscale-checkout' ),
					'testFailed' => __( 'Test failed', 'allscale-checkout' ),
					'copied'     => __( 'Copied', 'allscale-checkout' ),
					'copy'       => __( 'Copy', 'allscale-checkout' ),
					'show'       => __( 'Show', 'allscale-checkout' ),
					'hide'       => __( 'Hide', 'allscale-checkout' ),
					'networkErr' => __( "Couldn't reach Allscale — try again in a moment.", 'allscale-checkout' ),
				),
			)
		);

		$step = isset( $_GET['step'] ) ? max( 1, min( 4, (int) $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="allscale-wizard">
			<header class="aw-header">
				<img class="aw-logo" src="<?php echo esc_url( plugins_url( 'assets/logo.svg', ALLSCALE_CHECKOUT_FILE ) ); ?>" alt="Allscale" />
				<a class="aw-skip" href="<?php echo esc_url( self::skip_url() ); ?>">
					<?php esc_html_e( "Skip wizard — I'll configure manually", 'allscale-checkout' ); ?>
				</a>
			</header>

			<?php self::render_progress( $step ); ?>

			<div class="aw-card">
				<?php
				switch ( $step ) {
					case 1: self::render_step_welcome(); break;
					case 2: self::render_step_credentials(); break;
					case 3: self::render_step_webhook(); break;
					case 4: self::render_step_done(); break;
				}
				?>
			</div>

			<div class="aw-step-count">
				<?php
				printf(
					/* translators: 1: current step, 2: total steps */
					esc_html__( 'Step %1$d of %2$d', 'allscale-checkout' ),
					(int) $step,
					4
				);
				?>
			</div>
		</div>
		<?php
	}

	private static function render_progress( $current_step ) {
		$labels = array(
			1 => __( 'Welcome', 'allscale-checkout' ),
			2 => __( 'Credentials', 'allscale-checkout' ),
			3 => __( 'Webhook', 'allscale-checkout' ),
			4 => __( 'Done', 'allscale-checkout' ),
		);
		?>
		<div class="aw-progress">
			<?php
			$total = count( $labels );
			$i = 0;
			foreach ( $labels as $n => $label ) :
				$i++;
				$state = ( $n < $current_step ) ? 'done' : ( ( $n === $current_step ) ? 'active' : 'pending' );
				?>
				<div class="aw-progress-step aw-progress-<?php echo esc_attr( $state ); ?>">
					<span class="aw-progress-bubble">
						<?php if ( $state === 'done' ) : ?>
							&#10003;
						<?php else : ?>
							<?php echo (int) $n; ?>
						<?php endif; ?>
					</span>
					<span class="aw-progress-label"><?php echo esc_html( $label ); ?></span>
				</div>
				<?php if ( $i < $total ) : ?>
					<div class="aw-progress-line aw-progress-line-<?php echo $n < $current_step ? 'done' : 'pending'; ?>"></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_step_welcome() {
		?>
		<h2 class="aw-title"><?php esc_html_e( 'Welcome to Allscale Checkout.', 'allscale-checkout' ); ?></h2>
		<p class="aw-subtitle">
			<?php esc_html_e( 'Accept crypto payments — 0.5% fees, instant USDT settlement to your own wallet. This setup takes about 3 minutes.', 'allscale-checkout' ); ?>
		</p>

		<div class="aw-shield">
			<svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">
				<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z" fill="currentColor" />
				<path d="M8.5 12.5L11 15l4.5-4.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none" />
			</svg>
			<div>
				<strong><?php esc_html_e( 'Non-custodial.', 'allscale-checkout' ); ?></strong>
				<?php esc_html_e( "Payments settle directly to your wallet — Allscale never holds your funds.", 'allscale-checkout' ); ?>
			</div>
		</div>

		<div class="aw-checklist-heading"><?php esc_html_e( "What you'll need:", 'allscale-checkout' ); ?></div>
		<ul class="aw-checklist">
			<li>
				<span class="aw-check">&#10003;</span>
				<?php
				printf(
					/* translators: %s is the Allscale signup link */
					wp_kses( __( 'An <a href="%s" target="_blank" rel="noopener">Allscale account</a> (free)', 'allscale-checkout' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
					'https://allscale.io'
				);
				?>
			</li>
			<li>
				<span class="aw-check">&#10003;</span>
				<?php esc_html_e( 'Your API key and secret from the Allscale dashboard', 'allscale-checkout' ); ?>
			</li>
			<li>
				<span class="aw-check">&#10003;</span>
				<?php esc_html_e( 'About 3 minutes to set up the webhook in your dashboard', 'allscale-checkout' ); ?>
			</li>
		</ul>

		<form method="post">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="allscale_wizard_action" value="go_step_2" />
			<div class="aw-actions">
				<span></span>
				<button type="submit" class="button button-primary button-large aw-btn-brand">
					<?php esc_html_e( 'Continue →', 'allscale-checkout' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	private static function render_step_credentials() {
		$error = get_transient( self::TRANSIENT_ERROR );
		if ( $error ) {
			delete_transient( self::TRANSIENT_ERROR );
		}
		$prev_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$prev_secret = isset( $_POST['api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['api_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		?>
		<h2 class="aw-title"><?php esc_html_e( 'Enter your API credentials', 'allscale-checkout' ); ?></h2>
		<p class="aw-subtitle">
			<?php
			printf(
				/* translators: %s is the Allscale dashboard link */
				wp_kses( __( 'Find these in your <a href="%s" target="_blank" rel="noopener">Allscale dashboard → Developers → API keys</a>.', 'allscale-checkout' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
				'https://allscale.io'
			);
			?>
		</p>

		<?php if ( $error ) : ?>
			<div class="aw-error">
				<?php echo esc_html( $error ); ?>
			</div>
		<?php endif; ?>

		<form method="post" class="aw-form">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="allscale_wizard_action" value="save_credentials" />

			<div class="aw-field">
				<label for="aw-api-key"><?php esc_html_e( 'API key', 'allscale-checkout' ); ?></label>
				<input type="text" id="aw-api-key" name="api_key" placeholder="st_live_…" value="<?php echo esc_attr( $prev_key ); ?>" autocomplete="off" required />
			</div>

			<div class="aw-field">
				<label for="aw-api-secret"><?php esc_html_e( 'API secret', 'allscale-checkout' ); ?></label>
				<div class="as-secret-wrap">
					<input type="password" id="aw-api-secret" name="api_secret" placeholder="st_live_…" value="<?php echo esc_attr( $prev_secret ); ?>" autocomplete="off" data-as-secret-input required />
					<button type="button" class="button button-secondary as-toggle-secret" data-as-toggle-secret>
						<?php esc_html_e( 'Show', 'allscale-checkout' ); ?>
					</button>
				</div>
			</div>

			<div class="aw-field">
				<label><?php esc_html_e( 'Connection', 'allscale-checkout' ); ?></label>
				<div class="as-test-conn" data-as-test-conn data-initial-state="idle">
					<button type="button" class="button button-secondary as-test-btn" data-as-test-btn>
						<span class="as-test-btn-label"><?php esc_html_e( 'Test connection', 'allscale-checkout' ); ?></span>
					</button>
					<span class="as-pill" data-as-test-pill>
						<span class="as-pill-dot dot-gray"></span>
						<span class="as-pill-text"><?php esc_html_e( 'Not tested', 'allscale-checkout' ); ?></span>
					</span>
					<div class="as-test-error" data-as-test-error hidden></div>
				</div>
				<p class="aw-hint">
					<?php esc_html_e( 'Optional — Continue will also validate your credentials with Allscale before saving them.', 'allscale-checkout' ); ?>
				</p>
			</div>

			<div class="aw-actions">
				<a class="aw-back" href="<?php echo esc_url( self::url( 1 ) ); ?>">&larr; <?php esc_html_e( 'Back', 'allscale-checkout' ); ?></a>
				<button type="submit" class="button button-primary button-large aw-btn-brand">
					<?php esc_html_e( 'Continue →', 'allscale-checkout' ); ?>
				</button>
			</div>
		</form>

		<script>
			// The test-connection JS in admin.js reads inputs by their WC settings
			// names. In the wizard, our inputs use plain `api_key` / `api_secret`,
			// so mirror their values into a fake WC-named hidden field so the
			// shared JS can pick them up without modification.
			(function () {
				var keyInput = document.getElementById('aw-api-key');
				var secretInput = document.getElementById('aw-api-secret');
				if (!keyInput || !secretInput) { return; }
				keyInput.setAttribute('name', 'api_key');
				secretInput.setAttribute('name', 'api_secret');
				// Inject mirrors so admin.js can find them.
				function inject() {
					if (!document.querySelector('input[name="woocommerce_allscale_checkout_api_key"]')) {
						var k = document.createElement('input');
						k.type = 'hidden';
						k.name = 'woocommerce_allscale_checkout_api_key';
						document.body.appendChild(k);
					}
					if (!document.querySelector('input[name="woocommerce_allscale_checkout_api_secret"]')) {
						var s = document.createElement('input');
						s.type = 'hidden';
						s.name = 'woocommerce_allscale_checkout_api_secret';
						document.body.appendChild(s);
					}
				}
				function sync() {
					inject();
					document.querySelector('input[name="woocommerce_allscale_checkout_api_key"]').value = keyInput.value;
					document.querySelector('input[name="woocommerce_allscale_checkout_api_secret"]').value = secretInput.value;
				}
				keyInput.addEventListener('input', sync);
				secretInput.addEventListener('input', sync);
				sync();
			})();
		</script>
		<?php
	}

	private static function render_step_webhook() {
		$url = Admin::webhook_url();
		?>
		<h2 class="aw-title"><?php esc_html_e( 'Paste your webhook URL into Allscale', 'allscale-checkout' ); ?></h2>
		<p class="aw-subtitle">
			<?php esc_html_e( "Allscale requires you to register the webhook URL from their dashboard — we can't do it for you. It takes 30 seconds.", 'allscale-checkout' ); ?>
		</p>

		<div class="aw-webhook-block">
			<div class="aw-webhook-label"><?php esc_html_e( 'Your webhook URL', 'allscale-checkout' ); ?></div>
			<div class="as-codeblock">
				<code class="as-code"><?php echo esc_html( $url ); ?></code>
				<button type="button" class="button button-secondary as-copy-btn" data-as-copy="<?php echo esc_attr( $url ); ?>">
					<?php esc_html_e( 'Copy', 'allscale-checkout' ); ?>
				</button>
			</div>
		</div>

		<ol class="aw-instructions">
			<li>
				<span class="aw-num">1</span>
				<span><?php
					printf(
						/* translators: %s is the inline <strong> tag wrapping "Copy" */
						esc_html__( 'Click %s above.', 'allscale-checkout' ),
						'<strong>' . esc_html__( 'Copy', 'allscale-checkout' ) . '</strong>'
					);
				?></span>
			</li>
			<li>
				<span class="aw-num">2</span>
				<span><?php
					printf(
						/* translators: %s is the Allscale dashboard link */
						wp_kses( __( 'Open your <a href="%s" target="_blank" rel="noopener">Allscale dashboard</a> in a new tab.', 'allscale-checkout' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
						'https://allscale.io'
					);
				?></span>
			</li>
			<li>
				<span class="aw-num">3</span>
				<span><?php
					printf(
						/* translators: %s is the inline path "Store settings → Webhooks" */
						esc_html__( 'Go to %s and paste the URL.', 'allscale-checkout' ),
						'<strong>' . esc_html__( 'Store settings → Webhooks', 'allscale-checkout' ) . '</strong>'
					);
				?></span>
			</li>
			<li>
				<span class="aw-num">4</span>
				<span><?php esc_html_e( 'Save the change in your Allscale dashboard.', 'allscale-checkout' ); ?></span>
			</li>
		</ol>

		<form method="post">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="allscale_wizard_action" value="webhook_done" />
			<div class="aw-actions">
				<a class="aw-back" href="<?php echo esc_url( self::url( 2 ) ); ?>">&larr; <?php esc_html_e( 'Back', 'allscale-checkout' ); ?></a>
				<button type="submit" class="button button-primary button-large aw-btn-brand">
					<?php esc_html_e( "I've done this →", 'allscale-checkout' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	private static function render_step_done() {
		?>
		<div class="aw-done">
			<div class="aw-done-burst">
				<svg width="42" height="42" viewBox="0 0 24 24" aria-hidden="true">
					<circle cx="12" cy="12" r="10" fill="currentColor" />
					<path d="M7 12.5L11 16.5L17 8.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none" />
				</svg>
			</div>
			<h2 class="aw-title aw-title-large">
				<?php esc_html_e( 'Your store is ready to accept crypto payments.', 'allscale-checkout' ); ?>
			</h2>
			<p class="aw-subtitle">
				<?php esc_html_e( 'Place a test order to verify everything works end-to-end, then announce Allscale Checkout to your customers.', 'allscale-checkout' ); ?>
			</p>

			<div class="aw-actions aw-actions-center">
				<a class="button button-large aw-btn-brand" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" target="_blank">
					<?php esc_html_e( 'Place a test order →', 'allscale-checkout' ); ?>
				</a>
				<form method="post" style="display: inline;">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="allscale_wizard_action" value="finish" />
					<button type="submit" class="button button-large">
						<?php esc_html_e( 'Finish & go to settings', 'allscale-checkout' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}
}
