<?php
/**
 * Signed HTTP client for the Allscale Checkout API.
 *
 * No WordPress UI concerns — only request signing, transport, and response
 * parsing.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api_Client {

	const BASE_URL = 'https://openapi.allscale.io';

	/** @var string */
	private $api_key;

	/** @var string */
	private $api_secret;

	/** @var Logger */
	private $logger;

	/**
	 * @param string $api_key    Allscale API key (starts with st_).
	 * @param string $api_secret Allscale API secret (starts with st_).
	 * @param Logger $logger     Logger instance.
	 */
	public function __construct( $api_key, $api_secret, Logger $logger ) {
		$this->api_key    = (string) $api_key;
		$this->api_secret = (string) $api_secret;
		$this->logger     = $logger;
	}

	/**
	 * GET /v1/test/ping — credential probe.
	 *
	 * @return Api_Result
	 */
	public function test_ping() {
		return $this->request( 'GET', '/v1/test/ping', null );
	}

	/**
	 * POST /v1/checkout_intents/ — create a payment intent.
	 *
	 * Trailing slash is required by the API.
	 *
	 * @param array $payload Validated intent body.
	 * @return Api_Result
	 */
	public function create_intent( array $payload ) {
		return $this->request( 'POST', '/v1/checkout_intents/', $payload );
	}

	/**
	 * GET /v1/checkout_intents/{id} — full intent details, used by the
	 * thank-you page fallback to read tx_hash + actual_paid_amount.
	 *
	 * @param string $intent_id Allscale intent id.
	 * @return Api_Result
	 */
	public function get_intent_details( $intent_id ) {
		$path = '/v1/checkout_intents/' . rawurlencode( (string) $intent_id );
		return $this->request( 'GET', $path, null );
	}

	/**
	 * Make a signed request and parse the response into an Api_Result.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path with leading slash.
	 * @param array|null $body   JSON-serializable body, or null for GET.
	 * @return Api_Result
	 */
	private function request( $method, $path, $body ) {
		$method = strtoupper( $method );
		$body_string = ( $body === null ) ? '' : wp_json_encode( $body );
		if ( $body_string === false ) {
			$this->logger->error( 'Failed to JSON-encode request body', array( 'path' => $path ) );
			return Api_Result::err( null, __( 'Failed to encode request body.', 'allscale-checkout' ) );
		}

		$signing_headers = Signer::sign_request( $method, $path, '', $body_string, $this->api_secret );

		$headers = array_merge(
			$signing_headers,
			array(
				'X-API-Key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			)
		);

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( $body !== null ) {
			$args['body'] = $body_string;
		}

		$start    = microtime( true );
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'API transport error',
				array(
					'path'    => $path,
					'method'  => $method,
					'error'   => $response->get_error_message(),
					'elapsed' => $elapsed_ms,
				)
			);
			return Api_Result::err( null, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		$request_id = is_array( $parsed ) && isset( $parsed['request_id'] ) ? (string) $parsed['request_id'] : null;

		$this->logger->debug(
			'API response',
			array(
				'path'       => $path,
				'method'     => $method,
				'status'     => $status,
				'elapsed_ms' => $elapsed_ms,
				'request_id' => $request_id,
			)
		);

		// Success path: HTTP 2xx with the documented {code,payload,error,request_id} envelope.
		if ( $status >= 200 && $status < 300 && is_array( $parsed ) && isset( $parsed['code'] ) && (int) $parsed['code'] === 0 ) {
			$payload = array_key_exists( 'payload', $parsed ) ? $parsed['payload'] : null;
			return Api_Result::ok( $payload, $request_id, $status );
		}

		// Error path: Allscale returns code/error_code in two shapes — be permissive.
		$error_code    = null;
		$error_message = null;

		if ( is_array( $parsed ) ) {
			if ( isset( $parsed['code'] ) && (int) $parsed['code'] !== 0 ) {
				$error_code = (int) $parsed['code'];
			}
			if ( isset( $parsed['error'] ) && is_array( $parsed['error'] ) ) {
				if ( isset( $parsed['error']['code'] ) ) {
					$error_code = (int) $parsed['error']['code'];
				}
				if ( isset( $parsed['error']['message'] ) ) {
					$error_message = (string) $parsed['error']['message'];
				}
			}
			if ( isset( $parsed['error_code'] ) ) {
				$error_code = (int) $parsed['error_code'];
			}
			if ( isset( $parsed['error_message'] ) ) {
				$error_message = (string) $parsed['error_message'];
			}
		}

		if ( $error_message === null ) {
			/* translators: %d is the HTTP status code */
			$error_message = sprintf( __( 'Allscale API returned status %d.', 'allscale-checkout' ), $status );
		}

		$this->logger->warning(
			'API error response',
			array(
				'path'       => $path,
				'method'     => $method,
				'status'     => $status,
				'code'       => $error_code,
				'message'    => $error_message,
				'request_id' => $request_id,
			)
		);

		return Api_Result::err( $error_code, $error_message, $request_id, $status );
	}
}
