<?php

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Allscale_Blocks_Integration extends AbstractPaymentMethodType {

    protected $name = 'allscale_checkout';

    public function initialize() {
        $this->settings = get_option('woocommerce_allscale_checkout_settings', []);
    }

    public function is_active() {
        return ($this->get_setting('enabled') === 'yes')
            && !empty($this->get_setting('api_key'))
            && !empty($this->get_setting('api_secret'));
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'allscale-checkout-blocks',
            plugins_url('assets/js/allscale-blocks.js', dirname(__FILE__)),
            ['wp-element', 'wp-html-entities', 'wp-i18n'],
            ALLSCALE_CHECKOUT_VERSION,
            true
        );
        return ['allscale-checkout-blocks'];
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting('title', 'Pay with Crypto (Allscale)'),
            'description' => $this->get_setting('description', 'Pay securely with your crypto wallet. Powered by Allscale.'),
            'icon'        => plugins_url('assets/icon.png', dirname(__FILE__)),
            'supports'    => $this->get_supported_features(),
        ];
    }
}
