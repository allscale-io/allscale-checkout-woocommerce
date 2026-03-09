<?php

if (!defined('ABSPATH')) {
    exit;
}

class Allscale_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'allscale_checkout';
        $this->method_title       = 'Allscale Checkout';
        $this->method_description = 'Accept crypto payments via Allscale. Prices in local currency, settled as USDT.';
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');

        // Save admin settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // Register webhook handler
        if ($this->get_option('api_secret')) {
            $webhook = new Allscale_Webhook($this->get_option('api_secret'));
            $webhook->register();
        }
    }

    /**
     * Admin settings fields.
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Allscale Checkout',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'Payment method name shown to customers at checkout.',
                'default'     => 'Pay with Crypto (Allscale)',
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'Description shown to customers at checkout.',
                'default'     => 'Pay securely with your crypto wallet. Powered by Allscale.',
            ],
            'environment' => [
                'title'       => 'Environment',
                'type'        => 'select',
                'description' => 'Use Sandbox for testing, Production for live payments.',
                'default'     => 'sandbox',
                'options'     => [
                    'sandbox'    => 'Sandbox',
                    'production' => 'Production',
                ],
            ],
            'api_key' => [
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => 'Your Allscale API key.',
            ],
            'api_secret' => [
                'title'       => 'API Secret',
                'type'        => 'password',
                'description' => 'Your Allscale API secret.',
            ],
            'webhook_url' => [
                'title'       => 'Webhook URL',
                'type'        => 'title',
                'description' => 'Set this URL in your Allscale dashboard: <br><code>'
                    . home_url('/wc-api/allscale_checkout')
                    . '</code>',
            ],
        ];
    }

    /**
     * Check if the gateway is available for use.
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (empty($this->get_option('api_key')) || empty($this->get_option('api_secret'))) {
            return false;
        }

        // Check currency support
        $currency = get_woocommerce_currency();
        if (!Allscale_API::is_currency_supported($currency)) {
            return false;
        }

        return true;
    }

    /**
     * Process the payment — create checkout intent and redirect.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Order not found.', 'error');
            return ['result' => 'failure'];
        }

        $api = new Allscale_API(
            $this->get_option('api_key'),
            $this->get_option('api_secret'),
            $this->get_option('environment') === 'sandbox'
        );

        $currency = get_woocommerce_currency();
        $currency_code = Allscale_API::get_currency_code($currency);

        if ($currency_code === null) {
            wc_add_notice('Currency ' . esc_html($currency) . ' is not supported by Allscale.', 'error');
            return ['result' => 'failure'];
        }

        $amount_cents = intval(round($order->get_total() * 100));

        // Build item description
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $description = implode(', ', $items);
        if (strlen($description) > 200) {
            $description = substr($description, 0, 197) . '...';
        }

        $result = $api->create_checkout_intent($currency_code, $amount_cents, [
            'order_id'          => (string) $order->get_order_number(),
            'order_description' => $description,
            'extra' => [
                'wc_order_id' => $order_id,
                'return_url'  => $this->get_return_url($order),
            ],
        ]);

        if (!$result['success']) {
            $error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
            wc_add_notice('Payment error: ' . esc_html($error_msg), 'error');
            return ['result' => 'failure'];
        }

        // Store the checkout intent ID on the order
        $order->update_meta_data('_allscale_checkout_intent_id', $result['data']['allscale_checkout_intent_id']);
        $order->update_meta_data('_allscale_checkout_url', $result['data']['checkout_url']);
        $order->save();

        // Mark as pending payment
        $order->update_status('pending', 'Awaiting Allscale payment.');

        // Reduce stock
        wc_reduce_stock_levels($order_id);

        // Empty the cart
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $result['data']['checkout_url'],
        ];
    }
}
