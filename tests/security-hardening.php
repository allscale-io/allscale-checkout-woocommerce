<?php
/**
 * Focused regression tests for credential redaction and preservation.
 *
 * Run with: php tests/security-hardening.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WC_Payment_Gateway {
	}

	class WP_REST_Response {
		private $data;

		public function __construct( $data ) {
			$this->data = $data;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}
	}

	$GLOBALS['allscale_test_options'] = array();

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['allscale_test_options'] )
			? $GLOBALS['allscale_test_options'][ $key ]
			: $default;
	}
}

namespace Allscale\Checkout\Tests {
	use Allscale\Checkout\Gateway;
	use Allscale\Checkout\Plugin;

	require_once dirname( __DIR__ ) . '/includes/class-gateway.php';
	require_once dirname( __DIR__ ) . '/includes/class-plugin.php';

	function expect_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
			);
		}
	}

	$plugin  = Plugin::instance();
	$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
	$response = new \WP_REST_Response(
		array(
			'id'       => Gateway::ID,
			'settings' => array(
				'api_key'    => array( 'value' => 'key-visible' ),
				'api_secret' => array( 'value' => 'secret-must-not-leak' ),
			),
		)
	);

	$plugin->redact_rest_api_secret( $response, $gateway, null );
	$data = $response->get_data();
	expect_same( '', $data['settings']['api_secret']['value'], 'REST responses must redact api_secret.' );
	expect_same( 'key-visible', $data['settings']['api_key']['value'], 'REST responses must not mutate unrelated settings.' );

	$other_gateway = new \WC_Payment_Gateway();
	$other_response = new \WP_REST_Response(
		array(
			'settings' => array(
				'api_secret' => array( 'value' => 'belongs-to-another-gateway' ),
			),
		)
	);
	$plugin->redact_rest_api_secret( $other_response, $other_gateway, null );
	expect_same(
		'belongs-to-another-gateway',
		$other_response->get_data()['settings']['api_secret']['value'],
		'Other payment gateways must remain untouched.'
	);
	$other_settings = $plugin->preserve_empty_rest_api_secret(
		array( 'api_secret' => '' ),
		$other_gateway
	);
	expect_same( '', $other_settings['api_secret'], 'Other payment gateway settings must remain untouched.' );

	$GLOBALS['allscale_test_options'][ 'woocommerce_' . Gateway::ID . '_settings' ] = array(
		'api_key'    => 'stored-key',
		'api_secret' => 'stored-secret',
	);

	$preserved = $plugin->preserve_empty_rest_api_secret(
		array(
			'api_key'    => 'stored-key',
			'api_secret' => '',
		),
		$gateway
	);
	expect_same( 'stored-secret', $preserved['api_secret'], 'An empty REST value must keep the stored secret.' );

	$replaced = $plugin->preserve_empty_rest_api_secret(
		array(
			'api_key'    => 'new-key',
			'api_secret' => 'new-secret',
		),
		$gateway
	);
	expect_same( 'new-secret', $replaced['api_secret'], 'A non-empty REST value must replace the stored secret.' );

	echo "Security hardening tests passed.\n";
}
