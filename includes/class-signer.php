<?php
/**
 * HMAC-SHA256 signing and verification.
 *
 * Pure cryptography — no HTTP, no WordPress side effects.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Signer {

	/**
	 * Sign an outbound API request.
	 *
	 * Returns the headers the caller must attach to the request (the caller
	 * adds X-API-Key and Content-Type themselves).
	 *
	 * @param string $method Uppercase HTTP method, e.g. "POST".
	 * @param string $path   Path with leading slash and any trailing slash, e.g. "/v1/checkout_intents/".
	 * @param string $query  Query string without the leading "?". Pass "" when there is none.
	 * @param string $body   Raw request body as it will be sent over the wire. "" for GET.
	 * @param string $secret API secret.
	 * @return array{X-Timestamp:string,X-Nonce:string,X-Signature:string}
	 */
	public static function sign_request( $method, $path, $query, $body, $secret ) {
		$timestamp = (string) time();
		$nonce     = wp_generate_uuid4();
		$body_hash = hash( 'sha256', $body );

		$canonical = implode(
			"\n",
			array(
				strtoupper( $method ),
				$path,
				$query,
				$timestamp,
				$nonce,
				$body_hash,
			)
		);

		$signature = base64_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );

		return array(
			'X-Timestamp' => $timestamp,
			'X-Nonce'     => $nonce,
			'X-Signature' => 'v1=' . $signature,
		);
	}

	/**
	 * Verify an inbound webhook signature.
	 *
	 * Uses hash_equals for timing-safe comparison.
	 *
	 * @param string $method     Uppercase HTTP method ("POST").
	 * @param string $path       Path of the webhook request.
	 * @param string $query      Query string without leading "?". "" when none.
	 * @param string $webhook_id Value of the X-Webhook-Id header.
	 * @param string $timestamp  Value of the X-Webhook-Timestamp header.
	 * @param string $nonce      Value of the X-Webhook-Nonce header.
	 * @param string $body       Raw request body, before JSON parsing.
	 * @param string $signature  Value of the X-Webhook-Signature header (including the "v1=" prefix).
	 * @param string $secret     API secret.
	 * @return bool
	 */
	public static function verify_webhook( $method, $path, $query, $webhook_id, $timestamp, $nonce, $body, $signature, $secret ) {
		$body_hash = hash( 'sha256', $body );

		$canonical = implode(
			"\n",
			array(
				'allscale:webhook:v1',
				strtoupper( $method ),
				$path,
				$query,
				$webhook_id,
				$timestamp,
				$nonce,
				$body_hash,
			)
		);

		$expected = 'v1=' . base64_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );

		return hash_equals( $expected, (string) $signature );
	}
}
