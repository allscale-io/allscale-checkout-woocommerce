<?php
/**
 * Validate API credentials at save time by calling /v1/test/ping.
 *
 * Mirrors the Step 4.5 multi-tenant validation pattern from the skill,
 * adapted to single-tenant WP admin: if the ping fails, the save is
 * rejected and the merchant is shown a specific reason.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Validator {

	/**
	 * Wire up the WC sanitization hook.
	 */
	public static function register() {
		add_filter(
			'woocommerce_settings_api_sanitized_fields_' . Gateway::ID,
			array( __CLASS__, 'validate' ),
			10,
			1
		);
	}

	/**
	 * @param array $settings Sanitized fields about to be persisted.
	 * @return array Possibly mutated.
	 */
	public static function validate( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$new_key    = isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
		$new_secret = isset( $settings['api_secret'] ) ? trim( (string) $settings['api_secret'] ) : '';

		// Skip validation if credentials are empty (merchant is clearing them).
		if ( $new_key === '' && $new_secret === '' ) {
			return $settings;
		}

		// If both are unchanged from stored values, skip — don't burn a ping
		// call on every Save when only title/description changed.
		$stored = Plugin::settings();
		$stored_key    = isset( $stored['api_key'] ) ? (string) $stored['api_key'] : '';
		$stored_secret = isset( $stored['api_secret'] ) ? (string) $stored['api_secret'] : '';

		if ( $new_key === $stored_key && $new_secret === $stored_secret ) {
			return $settings;
		}

		// Either field changed — must validate.
		if ( $new_key === '' || $new_secret === '' ) {
			self::reject(
				__( 'Both API key and API secret are required.', 'allscale-checkout' )
			);
			// Roll back to stored values so a partial change isn't persisted.
			$settings['api_key']    = $stored_key;
			$settings['api_secret'] = $stored_secret;
			return $settings;
		}

		$logger = new Logger( false );
		$api    = new Api_Client( $new_key, $new_secret, $logger );
		$result = $api->test_ping();

		if ( $result->success ) {
			// Surface a one-shot success notice via transient.
			set_transient(
				'allscale_settings_save_notice',
				array(
					'type' => 'success',
					'text' => __( 'API credentials verified with Allscale.', 'allscale-checkout' ),
				),
				30
			);
			update_option( Plugin::OPT_LAST_PING_AT, time(), false );
			return $settings;
		}

		// Ping failed — reject the save.
		$friendly = Error_Messages::for_admin( $result->error_code, $result->error_message );
		self::reject( $friendly );

		// Persist the old credentials, not the bad new ones.
		$settings['api_key']    = $stored_key;
		$settings['api_secret'] = $stored_secret;
		return $settings;
	}

	/**
	 * Stash an error message for display via Admin::queue_save_notices.
	 *
	 * @param string $message User-facing copy.
	 */
	private static function reject( $message ) {
		set_transient(
			'allscale_settings_save_notice',
			array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: %s is the specific error reason */
					__( 'Could not save your Allscale credentials: %s', 'allscale-checkout' ),
					$message
				),
			),
			30
		);
	}
}
