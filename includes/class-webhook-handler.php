<?php
/**
 * Inbound webhook handler.
 *
 * Registered unconditionally from Plugin::boot() — does not depend on the
 * gateway being instantiated. Reads the api_secret from options at request
 * time so credential rotation is supported.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhook_Handler {

	const WC_API_SLUG       = 'allscale_checkout';
	const TIMESTAMP_TOLERANCE_SECONDS = 300; // ±5 minutes per spec.
	const NONCE_TTL_SECONDS = 600;           // 10-minute nonce TTL.

	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Wire up the WC API endpoint.
	 */
	public function register() {
		add_action( 'woocommerce_api_' . self::WC_API_SLUG, array( $this, 'handle' ) );
	}

	/**
	 * Webhook entrypoint.
	 *
	 * Exits with an appropriate HTTP status; never returns normally.
	 */
	public function handle() {
		$settings = Plugin::settings();
		$api_secret = isset( $settings['api_secret'] ) ? (string) $settings['api_secret'] : '';

		if ( $api_secret === '' ) {
			$this->logger->warning( 'Webhook received but no api_secret is configured' );
			status_header( 503 );
			exit( 'Allscale Checkout not configured' );
		}

		$raw_body = file_get_contents( 'php://input' );
		$webhook_id = isset( $_SERVER['HTTP_X_WEBHOOK_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_ID'] ) ) : '';
		$timestamp  = isset( $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ) ) : '';
		$nonce      = isset( $_SERVER['HTTP_X_WEBHOOK_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_NONCE'] ) ) : '';
		$signature  = isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ) : '';

		if ( $webhook_id === '' || $timestamp === '' || $nonce === '' || $signature === '' ) {
			$this->logger->warning( 'Webhook missing required headers', array( 'webhook_id' => $webhook_id ) );
			status_header( 401 );
			exit( 'Missing webhook headers' );
		}

		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE_SECONDS ) {
			$this->logger->warning(
				'Webhook timestamp outside tolerance',
				array(
					'webhook_id'  => $webhook_id,
					'timestamp'   => $timestamp,
					'server_now'  => time(),
				)
			);
			status_header( 401 );
			exit( 'Webhook timestamp expired' );
		}

		// Idempotency: skip if we've already processed this nonce.
		$nonce_key = 'allscale_nonce_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			$this->logger->debug( 'Webhook nonce already processed', array( 'webhook_id' => $webhook_id ) );
			status_header( 200 );
			exit( 'Already processed' );
		}

		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		$verified = Signer::verify_webhook(
			'POST',
			$path,
			'', // query string ignored
			$webhook_id,
			$timestamp,
			$nonce,
			(string) $raw_body,
			$signature,
			$api_secret
		);

		if ( ! $verified ) {
			$this->logger->warning( 'Webhook signature verification failed', array( 'webhook_id' => $webhook_id, 'path' => $path ) );
			status_header( 401 );
			exit( 'Invalid signature' );
		}

		set_transient( $nonce_key, time(), self::NONCE_TTL_SECONDS );

		$payload = json_decode( (string) $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->logger->warning( 'Webhook body not valid JSON', array( 'webhook_id' => $webhook_id ) );
			status_header( 400 );
			exit( 'Invalid payload' );
		}

		// Verify webhook_id in body matches header (defense in depth).
		if ( isset( $payload['webhook_id'] ) && (string) $payload['webhook_id'] !== $webhook_id ) {
			$this->logger->warning(
				'Webhook id mismatch between header and body',
				array(
					'header_id' => $webhook_id,
					'body_id'   => $payload['webhook_id'],
				)
			);
			status_header( 400 );
			exit( 'Webhook id mismatch' );
		}

		$this->process( $payload );

		// Health signals.
		update_option( Plugin::OPT_LAST_WEBHOOK_AT, time(), false );
		if ( ! get_option( Plugin::OPT_FIRST_WEBHOOK_AT, 0 ) ) {
			update_option( Plugin::OPT_FIRST_WEBHOOK_AT, time(), false );
		}

		status_header( 200 );
		exit( 'OK' );
	}

	/**
	 * Find the matching order and apply the status.
	 *
	 * @param array $payload Decoded webhook body.
	 */
	private function process( array $payload ) {
		$intent_id = isset( $payload['all_scale_checkout_intent_id'] )
			? sanitize_text_field( (string) $payload['all_scale_checkout_intent_id'] )
			: '';

		if ( $intent_id === '' ) {
			$this->logger->warning( 'Webhook payload missing checkout intent id' );
			return;
		}

		$order = self::find_order_by_intent( $intent_id );
		if ( ! $order ) {
			$this->logger->warning( 'No order matches webhook intent id', array( 'intent_id' => $intent_id ) );
			return;
		}

		// Webhooks fire only on successful payment per the spec; if a status
		// field is present we honor it, otherwise default to CONFIRMED.
		$status = isset( $payload['status'] ) ? (int) $payload['status'] : Status_Codes::CONFIRMED;

		$context = array(
			'tx_hash'             => isset( $payload['tx_hash'] ) ? (string) $payload['tx_hash'] : '',
			'paid_cents'          => isset( $payload['amount_cents'] ) ? (int) $payload['amount_cents'] : 0,
			'payment_method_type' => isset( $payload['payment_method_type'] ) ? (int) $payload['payment_method_type'] : null,
			'chain_id'            => isset( $payload['chain_id'] ) ? (int) $payload['chain_id'] : null,
			'coin_symbol'         => isset( $payload['coin_symbol'] ) ? (string) $payload['coin_symbol'] : '',
			'amount_coins'        => isset( $payload['amount_coins'] ) ? (string) $payload['amount_coins'] : '',
			'source'              => 'webhook',
		);

		Order_Locker::with_lock(
			$order->get_id(),
			function () use ( $order, $status, $context ) {
				$fresh = wc_get_order( $order->get_id() );
				if ( $fresh ) {
					Status_Mapper::apply( $fresh, $status, $context, $this->logger );
				}
			},
			$this->logger
		);

		do_action( 'allscale_checkout_webhook_after_process', $order, $payload );
	}

	/**
	 * Look up the WooCommerce order that owns this intent id.
	 *
	 * Reads both the current meta key and the legacy 0.1.x key for in-flight
	 * orders that were created before the upgrade.
	 *
	 * @param string $intent_id Allscale intent id.
	 * @return \WC_Order|null
	 */
	public static function find_order_by_intent( $intent_id ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => Status_Mapper::META_INTENT_ID,
						'value' => $intent_id,
					),
					array(
						'key'   => '_allscale_checkout_intent_id', // legacy 0.1.x key.
						'value' => $intent_id,
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}
		$order = $orders[0];
		return ( $order instanceof \WC_Order ) ? $order : null;
	}
}
