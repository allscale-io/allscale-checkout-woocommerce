<?php
/**
 * Uninstall — clean up plugin-owned options and transients.
 *
 * Order meta is intentionally preserved so historical orders keep their
 * Allscale transaction details after the plugin is removed.
 *
 * @package Allscale\Checkout
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Standalone options.
delete_option( 'allscale_checkout_version' );
delete_option( 'allscale_checkout_last_webhook_at' );
delete_option( 'allscale_checkout_first_webhook_at' );
delete_option( 'allscale_checkout_first_webhook_dismissed' );
delete_option( 'allscale_checkout_last_ping_at' );
delete_option( 'allscale_checkout_show_sandbox_notice' );
delete_option( 'allscale_checkout_wizard_completed_at' );
delete_option( 'allscale_checkout_wizard_dismissed_at' );

// Gateway settings array.
delete_option( 'woocommerce_allscale_checkout_settings' );

// Transients (best-effort).
delete_transient( 'allscale_settings_save_notice' );
delete_transient( 'allscale_wizard_error' );
delete_transient( 'allscale_do_activation_redirect' );

// Best-effort: clean up nonce dedupe transients. There's no WP API to bulk
// delete by prefix, so we leave them to expire naturally.
