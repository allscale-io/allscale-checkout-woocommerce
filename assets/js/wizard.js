/**
 * Setup-wizard glue for the credentials step.
 *
 * The shared test-connection code in admin.js reads inputs by their WooCommerce
 * settings names. In the wizard the inputs use plain `api_key` / `api_secret`,
 * so we mirror their values into hidden WC-named fields the shared JS expects.
 *
 * No-ops on wizard steps that don't render the credential inputs.
 */
( function () {
	var keyInput = document.getElementById( 'aw-api-key' );
	var secretInput = document.getElementById( 'aw-api-secret' );
	if ( ! keyInput || ! secretInput ) {
		return;
	}

	keyInput.setAttribute( 'name', 'api_key' );
	secretInput.setAttribute( 'name', 'api_secret' );

	function inject() {
		if ( ! document.querySelector( 'input[name="woocommerce_allscale_checkout_api_key"]' ) ) {
			var k = document.createElement( 'input' );
			k.type = 'hidden';
			k.name = 'woocommerce_allscale_checkout_api_key';
			document.body.appendChild( k );
		}
		if ( ! document.querySelector( 'input[name="woocommerce_allscale_checkout_api_secret"]' ) ) {
			var s = document.createElement( 'input' );
			s.type = 'hidden';
			s.name = 'woocommerce_allscale_checkout_api_secret';
			document.body.appendChild( s );
		}
	}

	function sync() {
		inject();
		document.querySelector( 'input[name="woocommerce_allscale_checkout_api_key"]' ).value = keyInput.value;
		document.querySelector( 'input[name="woocommerce_allscale_checkout_api_secret"]' ).value = secretInput.value;
	}

	keyInput.addEventListener( 'input', sync );
	secretInput.addEventListener( 'input', sync );
	sync();
} )();
