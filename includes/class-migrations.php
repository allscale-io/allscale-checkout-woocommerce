<?php
/**
 * Plugin version migrations.
 *
 * Runs on every page load via Plugin::boot but no-ops unless the stored
 * version is behind the constant.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrations {

	public static function maybe_run() {
		$stored = (string) get_option( Plugin::OPT_VERSION, '' );

		if ( $stored === ALLSCALE_CHECKOUT_VERSION ) {
			return;
		}

		// Fresh install — no migrations to run, just stamp the version.
		if ( $stored === '' ) {
			update_option( Plugin::OPT_VERSION, ALLSCALE_CHECKOUT_VERSION, false );
			return;
		}

		// Upgrade path from anything 0.x to 1.0.
		if ( version_compare( $stored, '1.0.0', '<' ) ) {
			self::migrate_to_1_0_0();
		}

		update_option( Plugin::OPT_VERSION, ALLSCALE_CHECKOUT_VERSION, false );
	}

	/**
	 * 0.1.x → 1.0.0:
	 * - If the old "environment" setting was "sandbox", queue the sandbox-retired
	 *   admin notice. The setting itself is no longer rendered, so the stored
	 *   value is harmless but we surface it for the merchant.
	 * - Drop the now-defunct `environment` setting from stored options.
	 * - Legacy `_allscale_checkout_intent_id` order meta keys keep working via
	 *   dual-read in Webhook_Handler and Gateway; we don't rewrite them.
	 */
	private static function migrate_to_1_0_0() {
		$settings = Plugin::settings();
		if ( ! empty( $settings['environment'] ) && 'sandbox' === $settings['environment'] ) {
			update_option( Plugin::OPT_SHOW_SANDBOX_NOTICE, true, false );
		}
		if ( isset( $settings['environment'] ) ) {
			unset( $settings['environment'] );
			update_option( 'woocommerce_' . Gateway::ID . '_settings', $settings );
		}
	}
}
