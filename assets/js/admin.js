/**
 * Allscale Checkout — settings page admin behaviors.
 *
 * - Test connection AJAX with proper state machine
 * - Copy webhook URL to clipboard
 * - Show/hide API secret
 * - Collapsible "Advanced" section
 */
(function () {
	'use strict';

	function $(selector, root) {
		return (root || document).querySelector(selector);
	}
	function $all(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	// ----- Test connection ------------------------------------------------
	function setTestState(container, state, message) {
		var btn = $('[data-as-test-btn]', container);
		var pill = $('[data-as-test-pill]', container);
		var pillDot = pill && pill.querySelector('.as-pill-dot');
		var pillText = pill && pill.querySelector('.as-pill-text');
		var error = $('[data-as-test-error]', container);
		var i18n = (window.AllscaleAdmin && window.AllscaleAdmin.i18n) || {};

		if (btn) {
			btn.disabled = (state === 'loading');
		}

		// Reset classes.
		if (pillDot) {
			pillDot.classList.remove('dot-green', 'dot-yellow', 'dot-red', 'dot-gray', 'dot-blue');
		}

		var labelEl = btn && btn.querySelector('.as-test-btn-label');
		// The default button label — captured ONCE at first render so we can
		// restore it across state changes without hardcoding English.
		var defaultLabel = (labelEl && labelEl.dataset.defaultLabel)
			|| (i18n.testConnection || 'Test connection');
		if (labelEl && !labelEl.dataset.defaultLabel) {
			labelEl.dataset.defaultLabel = labelEl.textContent || defaultLabel;
			defaultLabel = labelEl.dataset.defaultLabel;
		}

		switch (state) {
			case 'loading':
				if (labelEl) { labelEl.textContent = i18n.testing || 'Testing…'; }
				if (pillDot) { pillDot.classList.add('dot-blue'); }
				if (pillText) { pillText.textContent = i18n.testing || 'Testing connection…'; }
				if (error) { error.hidden = true; }
				break;
			case 'success':
				if (labelEl) { labelEl.textContent = defaultLabel; }
				if (pillDot) { pillDot.classList.add('dot-green'); }
				if (pillText) { pillText.textContent = i18n.connected || 'Connected'; }
				if (error) { error.hidden = true; }
				break;
			case 'failure':
				if (labelEl) { labelEl.textContent = defaultLabel; }
				if (pillDot) { pillDot.classList.add('dot-red'); }
				if (pillText) { pillText.textContent = message || i18n.testFailed || 'Test failed'; }
				if (error) {
					error.hidden = false;
					error.textContent = message || (i18n.testFailed || 'Test failed');
				}
				break;
			case 'idle':
			default:
				if (labelEl) { labelEl.textContent = defaultLabel; }
				if (pillDot) { pillDot.classList.add('dot-gray'); }
				if (pillText) { pillText.textContent = i18n.notTested || 'Not tested'; }
				if (error) { error.hidden = true; }
				break;
		}
	}

	function runTest(container) {
		var cfg = window.AllscaleAdmin || {};
		if (!cfg.ajaxUrl || !cfg.nonce || !cfg.action) {
			return;
		}
		var keyInput = document.querySelector('input[name="woocommerce_allscale_checkout_api_key"]');
		var secretInput = document.querySelector('input[name="woocommerce_allscale_checkout_api_secret"]');
		var apiKey = keyInput ? keyInput.value : '';
		var apiSecret = secretInput ? secretInput.value : '';

		setTestState(container, 'loading');

		var body = new URLSearchParams();
		body.set('action', cfg.action);
		body.set('nonce', cfg.nonce);
		body.set('api_key', apiKey);
		body.set('api_secret', apiSecret);

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json().catch(function () { return null; }); })
			.then(function (json) {
				if (json && json.success) {
					setTestState(container, 'success');
				} else {
					var msg = (json && json.data && json.data.message) || (cfg.i18n && cfg.i18n.testFailed) || 'Test failed';
					setTestState(container, 'failure', msg);
				}
			})
			.catch(function () {
				var msg = (cfg.i18n && cfg.i18n.networkErr) || "Couldn't reach Allscale";
				setTestState(container, 'failure', msg);
			});
	}

	// ----- Copy to clipboard ---------------------------------------------
	function attachCopyButtons() {
		$all('[data-as-copy]').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var text = btn.getAttribute('data-as-copy') || '';
				var done = function () {
					var original = btn.textContent;
					var copied = (window.AllscaleAdmin && window.AllscaleAdmin.i18n && window.AllscaleAdmin.i18n.copied) || 'Copied';
					btn.textContent = '✓ ' + copied;
					btn.disabled = true;
					setTimeout(function () {
						btn.textContent = original;
						btn.disabled = false;
					}, 1500);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done).catch(function () {
						fallbackCopy(text);
						done();
					});
				} else {
					fallbackCopy(text);
					done();
				}
			});
		});
	}

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (_) {}
		document.body.removeChild(ta);
	}

	// ----- Show/hide secret ----------------------------------------------
	function attachSecretToggles() {
		$all('[data-as-toggle-secret]').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var input = btn.parentElement.querySelector('[data-as-secret-input]');
				if (!input) { return; }
				var i18n = (window.AllscaleAdmin && window.AllscaleAdmin.i18n) || {};
				if (input.type === 'password') {
					input.type = 'text';
					btn.textContent = i18n.hide || 'Hide';
				} else {
					input.type = 'password';
					btn.textContent = i18n.show || 'Show';
				}
			});
		});
	}

	// ----- Collapsible sections ------------------------------------------
	function attachCollapsibles() {
		$all('[data-as-collapsible]').forEach(function (card) {
			// Start collapsed by default.
			card.setAttribute('data-collapsed', '');
			var toggle = card.querySelector('[data-as-collapsible-toggle]');
			var body = card.querySelector('[data-as-collapsible-body]');
			if (!toggle || !body) { return; }
			toggle.addEventListener('click', function () {
				if (card.hasAttribute('data-collapsed')) {
					card.removeAttribute('data-collapsed');
					body.hidden = false;
				} else {
					card.setAttribute('data-collapsed', '');
					body.hidden = true;
				}
			});
		});
	}

	// ----- Wire everything up --------------------------------------------
	ready(function () {
		$all('[data-as-test-conn]').forEach(function (container) {
			var btn = $('[data-as-test-btn]', container);
			if (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					runTest(container);
				});
			}
		});

		attachCopyButtons();
		attachSecretToggles();
		attachCollapsibles();
	});
})();
