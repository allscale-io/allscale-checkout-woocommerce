<?php

if (!defined('ABSPATH')) {
    exit;
}

class Allscale_Webhook {

    private $api_secret;

    // Allscale status codes
    const STATUS_CONFIRMED = 20;
    const STATUS_ON_CHAIN  = 10;
    const STATUS_FAILED    = -1;
    const STATUS_REJECTED  = -2;
    const STATUS_UNDERPAID = -3;
    const STATUS_CANCELED  = -4;

    public function __construct($api_secret) {
        $this->api_secret = $api_secret;
    }

    /**
     * Register the webhook endpoint with WooCommerce.
     */
    public function register() {
        add_action('woocommerce_api_allscale_checkout', [$this, 'handle']);
    }

    /**
     * Handle incoming webhook from Allscale.
     */
    public function handle() {
        $raw_body = file_get_contents('php://input');

        $webhook_id = isset($_SERVER['HTTP_X_WEBHOOK_ID']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_ID'])) : '';
        $timestamp = isset($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'])) : '';
        $nonce = isset($_SERVER['HTTP_X_WEBHOOK_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_NONCE'])) : '';
        $signature = isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) : '';

        // Validate required headers
        if (empty($webhook_id) || empty($timestamp) || empty($nonce) || empty($signature)) {
            status_header(401);
            exit('Missing webhook headers');
        }

        // Validate timestamp (±5 minutes)
        if (abs(time() - intval($timestamp)) > 300) {
            status_header(401);
            exit('Webhook timestamp expired');
        }

        // Check nonce deduplication
        $transient_key = 'allscale_nonce_' . md5($nonce);
        if (get_transient($transient_key)) {
            status_header(200);
            exit('Already processed');
        }

        // Verify signature
        if (!$this->verify_signature($raw_body, $webhook_id, $timestamp, $nonce, $signature)) {
            status_header(401);
            exit('Invalid signature');
        }

        // Mark nonce as used (10-minute TTL)
        set_transient($transient_key, true, 600);

        // Parse payload
        $data = json_decode($raw_body, true);
        if (empty($data)) {
            status_header(400);
            exit('Invalid payload');
        }

        $this->process_payment_update($data);

        status_header(200);
        exit('OK');
    }

    /**
     * Verify the HMAC-SHA256 webhook signature.
     */
    private function verify_signature($body, $webhook_id, $timestamp, $nonce, $signature) {
        // Parse the request URI path
        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '';

        $body_hash = hash('sha256', $body);

        $canonical = implode("\n", [
            'allscale:webhook:v1',
            'POST',
            $path,
            '',  // query string
            $webhook_id,
            $timestamp,
            $nonce,
            $body_hash,
        ]);

        $expected = 'v1=' . base64_encode(
            hash_hmac('sha256', $canonical, $this->api_secret, true)
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Update WooCommerce order based on Allscale webhook data.
     *
     * The documented webhook payload fields are:
     *   all_scale_transaction_id, all_scale_checkout_intent_id,
     *   amount_cents, amount_coins, currency, chain_id, tx_hash,
     *   tx_from, coin_contract_address, user_id, order_id,
     *   user_name, extra_obj
     *
     * Note: A "status" field is not documented. Webhooks appear to fire
     * on successful payment confirmation. If a "status" field is present,
     * we handle it; otherwise we treat the webhook as a payment confirmation.
     */
    private function process_payment_update($data) {
        $intent_id = isset($data['all_scale_checkout_intent_id']) ? sanitize_text_field($data['all_scale_checkout_intent_id']) : '';
        if (empty($intent_id)) {
            return;
        }

        // Find the WC order by the stored intent ID
        $orders = wc_get_orders([
            'meta_key'   => '_allscale_checkout_intent_id',
            'meta_value' => $intent_id,
            'limit'      => 1,
        ]);

        if (empty($orders)) {
            return;
        }

        $order = $orders[0];

        // Don't update orders that are already in a terminal state
        if (in_array($order->get_status(), ['completed', 'refunded', 'processing'], true)) {
            return;
        }

        $tx_hash = isset($data['tx_hash']) ? sanitize_text_field($data['tx_hash']) : '';

        if ($tx_hash) {
            $order->update_meta_data('_allscale_tx_hash', $tx_hash);
            $order->save();
        }

        // If a status field is present, use it; otherwise treat as confirmation
        $status = isset($data['status']) ? intval($data['status']) : self::STATUS_CONFIRMED;

        switch ($status) {
            case self::STATUS_CONFIRMED:
                // Verify the amount matches
                $expected_cents = intval(round($order->get_total() * 100));
                $paid_cents = isset($data['amount_cents']) ? intval($data['amount_cents']) : 0;

                if ($paid_cents >= $expected_cents) {
                    $order->payment_complete($tx_hash);
                    $order->add_order_note('Allscale payment confirmed. Tx: ' . $tx_hash);
                } else {
                    $order->update_status('on-hold', sprintf(
                        'Allscale payment amount mismatch. Expected: %d cents, Received: %d cents.',
                        $expected_cents,
                        $paid_cents
                    ));
                }
                break;

            case self::STATUS_ON_CHAIN:
                $order->add_order_note('Allscale: Payment detected on-chain, awaiting confirmation.');
                break;

            case self::STATUS_FAILED:
                $order->update_status('failed', 'Allscale payment failed.');
                break;

            case self::STATUS_REJECTED:
                $order->update_status('failed', 'Allscale payment rejected by compliance check.');
                break;

            case self::STATUS_UNDERPAID:
                $paid = isset($data['amount_cents']) ? intval($data['amount_cents']) : 'unknown';
                $order->update_status('on-hold', 'Allscale: Underpaid. Received ' . $paid . ' cents.');
                break;

            case self::STATUS_CANCELED:
                $order->update_status('cancelled', 'Allscale payment was cancelled by the customer.');
                break;
        }
    }
}
