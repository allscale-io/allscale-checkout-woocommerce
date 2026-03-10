<?php

if (!defined('ABSPATH')) {
    exit;
}

class Allscale_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'allscale_checkout';
        $this->method_title       = 'Allscale Checkout (Beta)';
        $this->method_description = '<strong>Community beta</strong> — developed by the AllScale community, not an official release. '
            . 'Your assets are always safe thanks to Allscale\'s non-custodial design (funds go directly to your wallet, never held by a third party). '
            . '0.5% fees, instant USDT settlement, no account freezes. '
            . '<a href="https://allscale.io" target="_blank">Create a free Allscale account</a> to get started.'
            . '<br><br><strong>Refunds:</strong> Allscale is non-custodial — funds settle directly to your wallet, so automatic refunds are not supported. '
            . 'To refund a customer, send the amount back manually from your wallet and update the order status in WooCommerce.';
        $this->has_fields         = false;
        $this->icon               = plugins_url('assets/icon.png', dirname(__FILE__));

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

        // Check payment status when customer returns to thank-you page
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'check_payment_on_return']);
    }

    /**
     * Check payment status via API when customer returns to the thank-you page.
     * Acts as a fallback in case the webhook hasn't arrived yet.
     */
    public function check_payment_on_return($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->is_paid()) {
            return;
        }

        $intent_id = $order->get_meta('_allscale_checkout_intent_id');
        if (empty($intent_id)) {
            return;
        }

        $api = new Allscale_API(
            $this->get_option('api_key'),
            $this->get_option('api_secret'),
            $this->get_option('environment') === 'sandbox'
        );

        $result = $api->get_checkout_intent_status($intent_id);

        if (!$result['success'] || empty($result['data'])) {
            return;
        }

        $status = isset($result['data']['status']) ? intval($result['data']['status']) : 0;

        // Only complete the order if Allscale confirms payment
        if ($status === Allscale_Webhook::STATUS_CONFIRMED) {
            $tx_hash = isset($result['data']['tx_hash']) ? sanitize_text_field($result['data']['tx_hash']) : '';
            $order->payment_complete($tx_hash);
            $order->add_order_note('Allscale payment confirmed via return check. Tx: ' . $tx_hash);
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

        // Mark as pending payment — WooCommerce will auto-cancel and restore stock
        // for unpaid orders based on the "Hold stock" setting (WooCommerce → Settings → Products → Inventory).
        $order->update_status('pending', 'Awaiting Allscale payment.');

        // Empty the cart
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $result['data']['checkout_url'],
        ];
    }
}
