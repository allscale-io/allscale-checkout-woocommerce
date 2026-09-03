<?php
/**
 * The WooCommerce payment gateway for Allscale Checkout.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gateway extends \WC_Payment_Gateway {

	const ID = 'allscale_checkout';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Allscale Checkout', 'allscale-checkout' );
		$this->method_description = __(
			'Accept crypto payments — 0.6% fees with a $0.10 minimum, instant USDT settlement to your own wallet. Non-custodial: funds go directly to your wallet and are never held by a third party. Requires a free Allscale account.',
			'allscale-checkout'
		);
		$this->has_fields         = false;
		$this->icon               = plugins_url( 'assets/icon.png', ALLSCALE_CHECKOUT_FILE );

		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Return-URL fallback — fires when the customer lands on the thank-you page.
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'handle_thankyou' ) );
	}

	// ----------------------------------------------------------------------
	// Settings UI
	// ----------------------------------------------------------------------

	public function init_form_fields() {
		// Field definitions used by WC for save/validate; the actual UI is
		// rendered by Admin::render_settings_page when WC routes to our
		// section — but WC also uses these for AJAX field saves, so we keep
		// them complete.
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'allscale-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Allscale Checkout', 'allscale-checkout' ),
				'default' => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title shown to customers', 'allscale-checkout' ),
				'type'        => 'text',
				'description' => __( 'This appears in the payment method list at checkout.', 'allscale-checkout' ),
				'default'     => __( 'Pay with Crypto (Allscale)', 'allscale-checkout' ),
				'desc_tip'    => true,
			),
			'description'          => array(
				'title'       => __( 'Description shown to customers', 'allscale-checkout' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely with your crypto wallet. Powered by Allscale.', 'allscale-checkout' ),
			),
			'api_key'              => array(
				'title' => __( 'API key', 'allscale-checkout' ),
				'type'  => 'text',
			),
			'api_secret'           => array(
				'title' => __( 'API secret', 'allscale-checkout' ),
				'type'  => 'password',
			),
			'use_stable_coin_pricing' => array(
				'title'   => __( 'Pricing mode', 'allscale-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use native USDT pricing instead of fiat conversion', 'allscale-checkout' ),
				'default' => 'no',
			),
			'debug_logging'        => array(
				'title'   => __( 'Logging', 'allscale-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable debug logging', 'allscale-checkout' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Override WC's generic table render so the section settings page is
	 * rendered by Admin::render_settings_page (matching the design).
	 *
	 * WC calls this when displaying the gateway-specific settings page.
	 */
	public function admin_options() {
		Admin::render_settings_page( $this );
	}

	// ----------------------------------------------------------------------
	// Availability
	// ----------------------------------------------------------------------

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( '' === (string) $this->get_option( 'api_key' ) || '' === (string) $this->get_option( 'api_secret' ) ) {
			return false;
		}

		// If the store uses native USDT pricing we don't care about fiat currency support.
		if ( 'yes' === $this->get_option( 'use_stable_coin_pricing' ) ) {
			return true;
		}

		$store_currency = get_woocommerce_currency();
		return Currency::is_supported( $store_currency );
	}

	// ----------------------------------------------------------------------
	// Payment processing
	// ----------------------------------------------------------------------

	/**
	 * @param int $order_id WooCommerce order id.
	 * @return array {result: string, redirect?: string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'allscale-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount_cents = (int) round( (float) $order->get_total() * 100 );
		// Allscale's documented minimum is 0.10 USDT. We can't precisely
		// convert fiat → USDT without a live rate, so this is a sanity floor;
		// the API will return a specific error for anything that still falls
		// below the actual minimum after FX conversion.
		if ( $amount_cents < 10 ) {
			wc_add_notice(
				__( 'Order total is too small for Allscale (minimum is 0.10 USDT, or its equivalent in your store currency).', 'allscale-checkout' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$use_stable_coin = ( 'yes' === $this->get_option( 'use_stable_coin_pricing' ) );

		if ( ! $use_stable_coin ) {
			$currency_iso  = get_woocommerce_currency();
			$currency_enum = Currency::to_enum( $currency_iso );
			if ( $currency_enum === null ) {
				wc_add_notice(
					sprintf(
						/* translators: %s is the unsupported ISO currency code */
						__( 'Currency %s is not supported by Allscale.', 'allscale-checkout' ),
						esc_html( $currency_iso )
					),
					'error'
				);
				return array( 'result' => 'failure' );
			}
		}

		$payload = array();
		if ( $use_stable_coin ) {
			$payload['stable_coin'] = Currency::STABLE_COIN_USDT;
		} else {
			$payload['currency'] = $currency_enum;
		}
		$payload['amount_cents'] = $amount_cents;
		$payload['order_id']         = (string) $order->get_order_number();
		$payload['order_description'] = self::build_description( $order );
		$payload['redirect_url']     = $this->get_return_url( $order );

		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			$payload['user_id'] = (string) $user_id;
		}
		$user_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( $user_name !== '' ) {
			$payload['user_name'] = $user_name;
		}

		$payload['extra'] = array(
			'wc_order_id' => $order->get_id(),
		);

		$payload = apply_filters( 'allscale_checkout_intent_request_payload', $payload, $order );

		$logger = new Logger( 'yes' === $this->get_option( 'debug_logging' ) );
		$api    = new Api_Client( (string) $this->get_option( 'api_key' ), (string) $this->get_option( 'api_secret' ), $logger );

		$result = $api->create_intent( $payload );

		if ( ! $result->success ) {
			$logger->error(
				'create_intent failed',
				array(
					'order_id'   => $order->get_id(),
					'code'       => $result->error_code,
					'message'    => $result->error_message,
					'request_id' => $result->request_id,
				)
			);
			wc_add_notice(
				Error_Messages::for_customer( $result->error_code ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$data = is_array( $result->data ) ? $result->data : array();
		$intent_id    = isset( $data['allscale_checkout_intent_id'] ) ? (string) $data['allscale_checkout_intent_id'] : '';
		$checkout_url = isset( $data['checkout_url'] ) ? (string) $data['checkout_url'] : '';

		if ( $intent_id === '' || $checkout_url === '' ) {
			$logger->error( 'create_intent response missing required fields', array( 'order_id' => $order->get_id() ) );
			wc_add_notice( Error_Messages::for_customer( null ), 'error' );
			return array( 'result' => 'failure' );
		}

		// A retry (double-click, pay-for-order) reaches here with an intent
		// already on the order. That intent is still live on Allscale and the
		// customer may still pay it, so archive it rather than overwrite it —
		// Webhook_Handler::find_order_by_intent and handle_thankyou both look
		// through the archived ids.
		$previous_intent = (string) $order->get_meta( Status_Mapper::META_INTENT_ID );
		if ( $previous_intent !== '' && $previous_intent !== $intent_id ) {
			$order->add_meta_data( Status_Mapper::META_PRIOR_INTENT_ID, $previous_intent, false );
			$logger->info(
				'Superseding an existing checkout intent',
				array(
					'order_id'   => $order->get_id(),
					'previous'   => $previous_intent,
					'new'        => $intent_id,
				)
			);
		}
		$order->update_meta_data( Status_Mapper::META_INTENT_ID, $intent_id );
		$order->save();

		// Don't redundantly set status — new orders are already 'pending'.
		// We do let WC clear the cart and redirect.

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	/**
	 * Return-URL fallback. Runs when the customer lands on the thank-you page.
	 *
	 * Reads the full intent details from Allscale (status endpoint returns a
	 * bare integer so it can't tell us tx_hash or paid amount), then routes
	 * through Status_Mapper just like the webhook does.
	 *
	 * @param int $order_id Order id.
	 */
	public function handle_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Only run the API fallback if the order isn't already done. Already-paid
		// orders (webhook landed in time) still get the status block rendered below.
		if ( ! $order->is_paid() ) {
			$logger = new Logger( 'yes' === $this->get_option( 'debug_logging' ) );
			$api    = new Api_Client( (string) $this->get_option( 'api_key' ), (string) $this->get_option( 'api_secret' ), $logger );

			// Current intent first, then any superseded ones (see process_payment).
			// A customer who paid an earlier intent from a still-open tab gets
			// recognised here even if the webhook hasn't landed.
			foreach ( self::intent_ids_for( $order ) as $intent_id ) {
				$result = $api->get_intent_details( $intent_id );
				if ( $result->success && is_array( $result->data ) ) {
					$data   = $result->data;
					$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
					if ( $status !== 0 ) {
						$context = array(
							'tx_hash'             => isset( $data['tx_hash'] ) ? (string) $data['tx_hash'] : '',
							'paid_cents'          => isset( $data['amount_cents'] ) ? (int) $data['amount_cents'] : 0,
							'payment_method_type' => isset( $data['payment_method_type'] ) ? (int) $data['payment_method_type'] : null,
							'chain_id'            => isset( $data['chain_id'] ) ? (int) $data['chain_id'] : null,
							'coin_symbol'         => isset( $data['coin_symbol'] ) ? (string) $data['coin_symbol'] : '',
							'amount_coins'        => isset( $data['amount_coins'] ) ? (string) $data['amount_coins'] : '',
							'actual_paid_amount'  => isset( $data['actual_paid_amount'] ) ? (string) $data['actual_paid_amount'] : '',
							'service_fee_amount'  => isset( $data['service_fee_amount'] ) ? (string) $data['service_fee_amount'] : '',
							'net_income_amount'   => isset( $data['net_income_amount'] ) ? (string) $data['net_income_amount'] : '',
							'source'              => 'return_url',
						);

						Order_Locker::with_lock(
							$order->get_id(),
							function () use ( $order, $status, $context, $logger ) {
								$fresh = wc_get_order( $order->get_id() );
								if ( $fresh ) {
									Status_Mapper::apply( $fresh, $status, $context, $logger );
								}
							},
							$logger
						);
					}
				}

				// Stop at the first intent that settled the order.
				$refreshed = wc_get_order( $order->get_id() );
				if ( $refreshed && $refreshed->is_paid() ) {
					break;
				}
			}
		}

		// Always render the customer-facing status block, even when the order
		// was already paid via webhook or the API call failed. We render from
		// local order state so the customer always sees a clear confirmation.
		$fresh = wc_get_order( $order_id );
		if ( $fresh ) {
			self::render_thankyou_block( $fresh );
		}
	}

	/**
	 * Every Allscale intent id ever attached to the order — current first,
	 * then superseded ones, then the legacy 0.1.x key.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[] De-duplicated, empty strings removed.
	 */
	public static function intent_ids_for( \WC_Order $order ) {
		$ids   = array();
		$ids[] = (string) $order->get_meta( Status_Mapper::META_INTENT_ID );
		foreach ( (array) $order->get_meta( Status_Mapper::META_PRIOR_INTENT_ID, false ) as $meta ) {
			$ids[] = is_object( $meta ) && isset( $meta->value ) ? (string) $meta->value : (string) $meta;
		}
		$ids[] = (string) $order->get_meta( '_allscale_checkout_intent_id' );

		return array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
	}

	/**
	 * Render the friendly status block on the order-received page.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function render_thankyou_block( \WC_Order $order ) {
		$status = (int) $order->get_meta( Status_Mapper::META_STATUS );

		if ( $order->is_paid() || $status === Status_Codes::CONFIRMED ) {
			$tone     = 'confirmed';
			$bg       = '#e6f4ea';
			$border   = '#34a853';
			$title    = __( 'Payment confirmed', 'allscale-checkout' );
			$amount_coins = (string) $order->get_meta( Status_Mapper::META_AMOUNT_COINS );
			$coin_symbol  = (string) $order->get_meta( Status_Mapper::META_COIN_SYMBOL );
			if ( $amount_coins && $coin_symbol ) {
				$body = sprintf(
					/* translators: 1: USDT amount, 2: coin symbol */
					__( 'We received your payment of %1$s %2$s.', 'allscale-checkout' ),
					$amount_coins,
					$coin_symbol
				);
			} else {
				$body = __( "We've received your payment.", 'allscale-checkout' );
			}
			$tx_hash = (string) $order->get_meta( Status_Mapper::META_TX_HASH );
			$sub = $tx_hash
				? sprintf(
					/* translators: %s is the on-chain transaction hash */
					__( 'Transaction: %s', 'allscale-checkout' ),
					'<code style="font-family: monospace;">' . esc_html( substr( $tx_hash, 0, 8 ) . '…' . substr( $tx_hash, -4 ) ) . '</code>'
				)
				: '';
		} elseif ( Status_Codes::is_failure( $status ) ) {
			$tone   = 'failed';
			$bg     = '#fce8e6';
			$border = '#d93025';
			$title  = __( "Payment didn't go through", 'allscale-checkout' );
			$body   = __( "Your payment didn't complete. Please contact the store if you've been charged.", 'allscale-checkout' );
			$sub    = '';
		} else {
			$tone   = 'pending';
			$bg     = '#fef7e0';
			$border = '#f9ab00';
			$title  = __( 'Confirming your payment', 'allscale-checkout' );
			$body   = __( "We're confirming your payment on-chain. This page will update automatically.", 'allscale-checkout' );
			$sub    = '';
		}

		?>
		<div class="allscale-thankyou allscale-thankyou-<?php echo esc_attr( $tone ); ?>" style="
			background: <?php echo esc_attr( $bg ); ?>;
			border-left: 4px solid <?php echo esc_attr( $border ); ?>;
			border-radius: 6px;
			padding: 18px 22px;
			margin-bottom: 24px;
			max-width: 620px;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		">
			<div style="font-size: 17px; font-weight: 600; color: #1f1f1f;">
				<?php echo esc_html( $title ); ?>
			</div>
			<div style="font-size: 14px; color: #3c4043; margin-top: 4px; line-height: 1.5;">
				<?php echo esc_html( $body ); ?>
			</div>
			<?php if ( $sub ) : ?>
				<div style="font-size: 13px; color: #5f6368; margin-top: 8px;">
					<?php echo wp_kses_post( $sub ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		// If pending, refresh every 10 seconds for up to 5 minutes TOTAL across
		// reloads. We persist the original start time in sessionStorage so the
		// 5-minute cap actually holds (not "5 minutes per reload").
		if ( $tone === 'pending' ) {
			$intent_id = (string) $order->get_meta( Status_Mapper::META_INTENT_ID );
			$session_key = 'allscale_thankyou_started_' . md5( $intent_id !== '' ? $intent_id : (string) $order->get_id() );
			?>
			<script>
			(function(){
				var key = <?php echo wp_json_encode( $session_key ); ?>;
				var maxMs = 5 * 60 * 1000;       // 5 minutes total polling window
				var intervalMs = 10000;          // poll every 10 seconds

				var started;
				try {
					started = parseInt(window.sessionStorage.getItem(key), 10);
					if (!started || isNaN(started)) {
						started = Date.now();
						window.sessionStorage.setItem(key, String(started));
					}
				} catch (_) {
					started = Date.now();
				}

				if (Date.now() - started >= maxMs) {
					return; // hit the cap — stop reloading
				}

				setTimeout(function(){
					if (Date.now() - started < maxMs) {
						window.location.reload();
					}
				}, intervalMs);
			})();
			</script>
			<?php
		}
	}

	/**
	 * Build the order_description we send to Allscale.
	 *
	 * Uses mb_strimwidth so multi-byte product names (CJK, emoji) don't get
	 * sliced mid-character.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private static function build_description( \WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		$desc = implode( ', ', $items );
		return mb_strimwidth( $desc, 0, 200, '…', 'UTF-8' );
	}
}
