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

		// Always run the legacy migration check on a version mismatch. The
		// check is keyed on the presence of the now-removed `environment`
		// setting, NOT on version comparison — so it's safe to call on every
		// upgrade (idempotent: after first run, the signal is gone) and we
		// avoid any ordering pitfalls between the prior 0.1.x community beta
		// and the current 0.0.x rewrite.
		self::maybe_migrate_from_legacy_beta();

		update_option( Plugin::OPT_VERSION, ALLSCALE_CHECKOUT_VERSION, false );
	}

	/**
	 * Migrate from the prior 0.1.x community beta.
	 *
	 * Signal: the stored gateway settings still contain the `environment`
	 * field. The new plugin doesn't render or use that field, so its presence
	 * uniquely identifies "we just upgraded from 0.1.x".
	 *
	 * Actions:
	 * - If `environment` was "sandbox", queue the sandbox-retired admin notice.
	 * - Drop the defunct `environment` key from stored options (makes this
	 *   migration idempotent — second run finds no signal, no-ops).
	 * - Legacy `_allscale_checkout_intent_id` order meta keys keep working
	 *   via dual-read in Webhook_Handler and Gateway; we don't rewrite them.
	 */
	private static function maybe_migrate_from_legacy_beta() {
		$settings = Plugin::settings();
		if ( ! isset( $settings['environment'] ) ) {
			return;
		}
		if ( 'sandbox' === $settings['environment'] ) {
			update_option( Plugin::OPT_SHOW_SANDBOX_NOTICE, true, false );
		}
		unset( $settings['environment'] );
		update_option( 'woocommerce_' . Gateway::ID . '_settings', $settings );
	}
}
