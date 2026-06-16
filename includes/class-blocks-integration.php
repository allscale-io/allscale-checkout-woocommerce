<?php
/**
 * Block-based checkout integration.
 *
 * Registers the gateway as a payment method type for WooCommerce Blocks.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This file is required only from Plugin::register_blocks_integration, which
// itself runs on woocommerce_blocks_loaded — i.e. only after we've confirmed
// AbstractPaymentMethodType is available. The class declaration here is
// therefore safe even though it extends a WC-Blocks parent.

final class Blocks_Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	protected $name = Gateway::ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
	}

	public function is_active() {
		if ( 'yes' !== $this->get_setting( 'enabled' ) ) {
			return false;
		}
		if ( '' === (string) $this->get_setting( 'api_key' ) || '' === (string) $this->get_setting( 'api_secret' ) ) {
			return false;
		}
		if ( 'yes' === $this->get_setting( 'use_stable_coin_pricing' ) ) {
			return true;
		}
		return Currency::is_supported( get_woocommerce_currency() );
	}

	public function get_payment_method_script_handles() {
		$handle = 'allscale-checkout-blocks';
		wp_register_script(
			$handle,
			plugins_url( 'assets/js/blocks.js', ALLSCALE_CHECKOUT_FILE ),
			array( 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			ALLSCALE_CHECKOUT_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'allscale-checkout' );
		}
		return array( $handle );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Pay with Crypto (Allscale)', 'allscale-checkout' ) ),
			'description' => $this->get_setting(
				'description',
				__( 'Pay securely with your crypto wallet.', 'allscale-checkout' )
			),
			'icon'        => plugins_url( 'assets/icon.png', ALLSCALE_CHECKOUT_FILE ),
			'supports'    => $this->get_supported_features(),
		);
	}
}
