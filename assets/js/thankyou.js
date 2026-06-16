/**
 * Thank-you page polling for pending Allscale payments.
 *
 * Reloads the order-received page every 10 seconds for up to 5 minutes total
 * (persisted across reloads via sessionStorage) so an on-chain confirmation
 * surfaces without the customer refreshing manually.
 *
 * Config is provided by wp_localize_script as window.allscaleThankyou.
 */
( function () {
	var cfg = window.allscaleThankyou || {};
	var key = cfg.key;
	if ( ! key ) {
		return;
	}

	var maxMs = 5 * 60 * 1000; // 5-minute total polling window.
	var intervalMs = 10000;    // Poll every 10 seconds.

	var started;
	try {
		started = parseInt( window.sessionStorage.getItem( key ), 10 );
		if ( ! started || isNaN( started ) ) {
			started = Date.now();
			window.sessionStorage.setItem( key, String( started ) );
		}
	} catch ( e ) {
		started = Date.now();
	}

	if ( Date.now() - started >= maxMs ) {
		return; // Hit the cap — stop reloading.
	}

	setTimeout( function () {
		if ( Date.now() - started < maxMs ) {
			window.location.reload();
		}
	}, intervalMs );
} )();
