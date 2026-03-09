<?php

if (!defined('ABSPATH')) {
    exit;
}

class Allscale_API {

    private $api_key;
    private $api_secret;
    private $base_url;

    private static $currency_map = [
        'USD' => 1,
        'AUD' => 9,
        'CAD' => 27,
        'CNY' => 31,
        'EUR' => 44,
        'GBP' => 48,
        'HKD' => 57,
        'JPY' => 72,
        'SGD' => 126,
    ];

    public function __construct($api_key, $api_secret, $sandbox = true) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->base_url = $sandbox
            ? 'https://openapi-sandbox.allscale.io'
            : 'https://openapi.allscale.io';
    }

    /**
     * Map ISO 4217 currency code to Allscale integer enum.
     */
    public static function get_currency_code($iso_code) {
        $iso_code = strtoupper($iso_code);
        return isset(self::$currency_map[$iso_code]) ? self::$currency_map[$iso_code] : null;
    }

    /**
     * Check if a currency is supported.
     */
    public static function is_currency_supported($iso_code) {
        return self::get_currency_code($iso_code) !== null;
    }

    /**
     * Test API connectivity.
     */
    public function ping() {
        return $this->request('GET', '/v1/test/ping');
    }

    /**
     * Create a checkout intent.
     */
    public function create_checkout_intent($currency_code, $amount_cents, $extra = []) {
        $body = [
            'currency' => $currency_code,
            'amount_cents' => $amount_cents,
        ];

        if (!empty($extra['order_id'])) {
            $body['order_id'] = (string) $extra['order_id'];
        }
        if (!empty($extra['order_description'])) {
            $body['order_description'] = $extra['order_description'];
        }
        if (!empty($extra['extra'])) {
            $body['extra'] = $extra['extra'];
        }

        return $this->request('POST', '/v1/checkout_intents/', $body);
    }

    /**
     * Get checkout intent status (lightweight).
     */
    public function get_checkout_intent_status($intent_id) {
        return $this->request('GET', '/v1/checkout_intents/' . urlencode($intent_id) . '/status');
    }

    /**
     * Make a signed API request.
     */
    private function request($method, $path, $body = null) {
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $body_str = ($body !== null) ? wp_json_encode($body) : '';
        $body_hash = hash('sha256', $body_str);

        $canonical = implode("\n", [
            $method,
            $path,
            '',  // query string (empty for our use cases)
            $timestamp,
            $nonce,
            $body_hash,
        ]);

        $signature = base64_encode(
            hash_hmac('sha256', $canonical, $this->api_secret, true)
        );

        $headers = [
            'X-API-Key'    => $this->api_key,
            'X-Timestamp'  => $timestamp,
            'X-Nonce'      => $nonce,
            'X-Signature'  => 'v1=' . $signature,
            'Content-Type' => 'application/json',
        ];

        $url = $this->base_url . $path;

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        if ($body !== null) {
            $args['body'] = $body_str;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 200 && $status_code < 300 && isset($response_body['payload'])) {
            return [
                'success' => true,
                'data'    => $response_body['payload'],
            ];
        }

        return [
            'success' => false,
            'error'   => isset($response_body['error_message'])
                ? $response_body['error_message']
                : 'API request failed with status ' . $status_code,
            'code'    => isset($response_body['error_code']) ? $response_body['error_code'] : null,
        ];
    }
}
