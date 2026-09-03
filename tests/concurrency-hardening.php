<?php
/**
 * Focused regression tests for atomic claims and intent reuse.
 *
 * Run with: php tests/concurrency-hardening.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WC_Payment_Gateway {
		public function get_return_url( $order ) {
			return 'https://store.test/order/' . $order->get_id();
		}

		public function get_option( $key ) {
			return '';
		}
	}

	class WC_Order {
		private $id;
		private $meta;
		private $paid;
		private $status;
		private $total;
		private $notes = array();

		public function __construct( $id, array $meta = array(), $paid = false, $status = '', $total = 25 ) {
			$this->id   = $id;
			$this->meta = $meta;
			$this->paid = (bool) $paid;
			$this->status = $status !== '' ? $status : ( $this->paid ? 'processing' : 'pending' );
			$this->total  = $total;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_meta( $key, $single = true ) {
			if ( ! $single && isset( $this->meta[ $key ] ) ) {
				return is_array( $this->meta[ $key ] ) ? $this->meta[ $key ] : array( $this->meta[ $key ] );
			}
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function add_meta_data( $key, $value, $unique = false ) {
			if ( $unique || ! isset( $this->meta[ $key ] ) ) {
				$this->meta[ $key ] = $unique ? $value : array( $value );
				return;
			}
			$values = is_array( $this->meta[ $key ] ) ? $this->meta[ $key ] : array( $this->meta[ $key ] );
			$values[] = $value;
			$this->meta[ $key ] = $values;
		}

		public function add_order_note( $note ) {
			$this->notes[] = $note;
		}

		public function get_notes() {
			return $this->notes;
		}

		public function is_paid() {
			return $this->paid;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_total() {
			return $this->total;
		}

		public function save() {
		}
	}

	class Allscale_Test_WPDB {
		public $options = 'wp_options';
		public $rows = array();
		public $last_error = '';
		public $fail_next_insert = false;

		public function prepare( $query, ...$args ) {
			return array( 'query' => $query, 'args' => $args );
		}

		public function esc_like( $value ) {
			return $value;
		}

		public function get_var( $statement ) {
			$key = $statement['args'][0];
			return array_key_exists( $key, $this->rows ) ? $this->rows[ $key ] : null;
		}

		public function query( $statement ) {
			$query = ltrim( $statement['query'] );
			$args  = $statement['args'];

			if ( strpos( $query, 'INSERT IGNORE' ) === 0 ) {
				if ( $this->fail_next_insert ) {
					$this->fail_next_insert = false;
					$this->last_error = 'simulated database failure';
					return false;
				}
				if ( array_key_exists( $args[0], $this->rows ) ) {
					return 0;
				}
				$this->rows[ $args[0] ] = $args[1];
				return 1;
			}

			if ( strpos( $query, 'INSERT INTO' ) === 0 ) {
				$this->rows[ $args[0] ] = $args[1];
				return 1;
			}

			if ( strpos( $query, 'UPDATE' ) === 0 ) {
				list( $replacement, $key, $expected ) = $args;
				if ( ! array_key_exists( $key, $this->rows ) || $this->rows[ $key ] !== $expected ) {
					return 0;
				}
				$this->rows[ $key ] = $replacement;
				return 1;
			}

			if ( strpos( $query, 'DELETE FROM' ) === 0 && count( $args ) === 2 && strpos( $args[0], '%' ) === false ) {
				list( $key, $expected ) = $args;
				if ( ! array_key_exists( $key, $this->rows ) || $this->rows[ $key ] !== $expected ) {
					return 0;
				}
				unset( $this->rows[ $key ] );
				return 1;
			}

			// Expired-row cleanup; tests start with an empty table.
			return 0;
		}
	}

	$GLOBALS['wpdb'] = new Allscale_Test_WPDB();
	$GLOBALS['allscale_test_uuid'] = 0;
	$GLOBALS['allscale_test_notices'] = array();

	function wp_generate_uuid4() {
		$GLOBALS['allscale_test_uuid']++;
		return 'owner-' . $GLOBALS['allscale_test_uuid'];
	}

	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}

	function delete_option( $key ) {
		unset( $GLOBALS['wpdb']->rows[ $key ] );
		return true;
	}

	function wc_add_notice( $message, $type ) {
		$GLOBALS['allscale_test_notices'][] = array( $type, $message );
	}

	function __( $text, $domain = '' ) {
		return $text;
	}

	function do_action( $hook, ...$args ) {
	}
}

namespace Allscale\Checkout\Tests {
	use Allscale\Checkout\Api_Client;
	use Allscale\Checkout\Api_Result;
	use Allscale\Checkout\Atomic_Lock;
	use Allscale\Checkout\Gateway;
	use Allscale\Checkout\Logger;
	use Allscale\Checkout\Status_Codes;
	use Allscale\Checkout\Status_Mapper;

	require_once dirname( __DIR__ ) . '/includes/class-api-result.php';
	require_once dirname( __DIR__ ) . '/includes/class-logger.php';
	require_once dirname( __DIR__ ) . '/includes/class-api-client.php';
	require_once dirname( __DIR__ ) . '/includes/class-status-codes.php';
	require_once dirname( __DIR__ ) . '/includes/class-status-mapper.php';
	require_once dirname( __DIR__ ) . '/includes/class-atomic-lock.php';
	require_once dirname( __DIR__ ) . '/includes/class-gateway.php';

	function expect_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	class Test_Api_Client extends Api_Client {
		private $result;

		public function __construct( Api_Result $result ) {
			$this->result = $result;
		}

		public function get_intent_details( $intent_id ) {
			return $this->result;
		}
	}

	$first = Atomic_Lock::acquire( 'same-resource', 60 );
	expect_same( true, is_array( $first ), 'The first worker must acquire the resource.' );
	expect_same( false, Atomic_Lock::acquire( 'same-resource', 60 ), 'A concurrent worker must not acquire the resource.' );
	expect_same( true, Atomic_Lock::release( $first ), 'The owner must be able to release its resource.' );
	$second = Atomic_Lock::acquire( 'same-resource', 60 );
	expect_same( true, is_array( $second ), 'The resource must be available after release.' );

	// Simulate an expired owner and verify compare-and-swap takeover. The old
	// handle must not be able to delete the replacement owner's row.
	$GLOBALS['wpdb']->rows[ $second['option_name'] ] = '0:expired-owner';
	$replacement = Atomic_Lock::acquire( 'same-resource', 60 );
	expect_same( true, is_array( $replacement ), 'An expired resource must be recoverable.' );
	expect_same( false, Atomic_Lock::release( $second ), 'A stale owner must not release a replacement lock.' );
	expect_same( true, Atomic_Lock::release( $replacement ), 'The replacement owner must release its own lock.' );
	$GLOBALS['wpdb']->fail_next_insert = true;
	expect_same( null, Atomic_Lock::acquire( 'storage-failure', 60 ), 'A storage error must not look like lock contention.' );
	$GLOBALS['wpdb']->last_error = '';

	$gateway = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
	$logger  = new Logger( false );
	$invoke_existing = \Closure::bind(
		function ( $order, $api, $amount ) use ( $logger ) {
			return $this->existing_intent_result( $order, $api, $amount, $logger );
		},
		$gateway,
		Gateway::class
	);

	$active_order = new \WC_Order(
		101,
		array(
			Status_Mapper::META_INTENT_ID           => 'intent-active',
			Status_Mapper::META_CHECKOUT_URL        => 'https://checkout.test/existing',
			Status_Mapper::META_INTENT_AMOUNT_CENTS => 2500,
		)
	);
	$active_result = $invoke_existing(
		$active_order,
		new Test_Api_Client(
			Api_Result::ok(
				array(
					'status'       => Status_Codes::PAYING,
					'amount_cents' => 2500,
					'checkout_url' => 'https://checkout.test/existing',
				)
			)
		),
		2500
	);
	expect_same( 'success', $active_result['result'], 'An active intent must be reused.' );
	expect_same( 'https://checkout.test/existing', $active_result['redirect'], 'Retries must use the existing checkout URL.' );

	$failed_result = $invoke_existing(
		$active_order,
		new Test_Api_Client( Api_Result::ok( array( 'status' => Status_Codes::FAILED, 'amount_cents' => 2500 ) ) ),
		2500
	);
	expect_same( false, $failed_result, 'A terminal failure may be replaced with a new intent.' );

	$mismatch_result = $invoke_existing(
		$active_order,
		new Test_Api_Client( Api_Result::ok( array( 'status' => Status_Codes::PAYING, 'amount_cents' => 2500 ) ) ),
		3000
	);
	expect_same( 'failure', $mismatch_result['result'], 'An active intent for another amount must block a replacement.' );

	$paid_order = new \WC_Order(
		202,
		array(
			Status_Mapper::META_SETTLED_INTENT_ID => 'intent-original',
			Status_Mapper::META_TX_HASH           => 'tx-original',
		),
		true
	);
	Status_Mapper::apply(
		$paid_order,
		Status_Codes::CONFIRMED,
		array(
			'intent_id' => 'intent-second',
			'tx_hash'   => 'tx-second',
		),
		$logger
	);
	expect_same(
		array( 'intent-second' ),
		$paid_order->get_meta( Status_Mapper::META_DUPLICATE_PAYMENT, false ),
		'A second settled intent must be marked for reconciliation.'
	);
	expect_same( 1, count( $paid_order->get_notes() ), 'A duplicate settlement must add one merchant-facing order note.' );

	Status_Mapper::apply(
		$paid_order,
		Status_Codes::CONFIRMED,
		array(
			'intent_id' => 'intent-second',
			'tx_hash'   => 'tx-second',
		),
		$logger
	);
	expect_same( 1, count( $paid_order->get_notes() ), 'The same duplicate settlement must not add another order note.' );

	$cancelled_order = new \WC_Order( 303, array(), false, 'cancelled', 25 );
	$late_context = array(
		'intent_id' => 'intent-late',
		'tx_hash'   => 'tx-late',
		'paid_cents' => 2500,
	);
	Status_Mapper::apply( $cancelled_order, Status_Codes::CONFIRMED, $late_context, $logger );
	expect_same(
		array( 'intent-late' ),
		$cancelled_order->get_meta( Status_Mapper::META_LATE_PAYMENT, false ),
		'A payment on a cancelled order must be persisted for reconciliation.'
	);
	expect_same( 'cancelled', $cancelled_order->get_status(), 'Late payment handling must not restore or fulfil a cancelled order.' );
	expect_same( 1, count( $cancelled_order->get_notes() ), 'A late payment must add one merchant-facing order note.' );
	Status_Mapper::apply( $cancelled_order, Status_Codes::CONFIRMED, $late_context, $logger );
	expect_same( 1, count( $cancelled_order->get_notes() ), 'The same late payment must not add another order note.' );

	echo "Concurrency hardening tests passed.\n";
}
