<?php
/**
 * Unified return value from Api_Client methods.
 *
 * Saves callers from digging into array shapes.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Api_Result {

	/** @var bool */
	public $success;

	/**
	 * Decoded response payload on success.
	 *
	 * Shape varies per endpoint — sometimes an array, sometimes a bare integer
	 * (the status endpoint), sometimes null.
	 *
	 * @var array|int|string|null
	 */
	public $data;

	/** @var int|null Allscale error code. */
	public $error_code;

	/** @var string|null Allscale error message verbatim. */
	public $error_message;

	/** @var string|null Allscale request id (for log correlation). */
	public $request_id;

	/** @var int|null HTTP status code. */
	public $http_status;

	private function __construct() {
	}

	/**
	 * @param array|int|string|null $data        Decoded payload.
	 * @param string|null           $request_id  Request id from the API.
	 * @param int|null              $http_status HTTP status code.
	 */
	public static function ok( $data, $request_id = null, $http_status = null ) {
		$r              = new self();
		$r->success     = true;
		$r->data        = $data;
		$r->request_id  = $request_id;
		$r->http_status = $http_status;
		return $r;
	}

	/**
	 * @param int|null    $error_code    Allscale error code.
	 * @param string|null $error_message Allscale error message.
	 * @param string|null $request_id    Request id from the API.
	 * @param int|null    $http_status   HTTP status code.
	 */
	public static function err( $error_code, $error_message, $request_id = null, $http_status = null ) {
		$r                = new self();
		$r->success       = false;
		$r->error_code    = ( $error_code === null ) ? null : (int) $error_code;
		$r->error_message = $error_message;
		$r->request_id    = $request_id;
		$r->http_status   = $http_status;
		return $r;
	}
}
