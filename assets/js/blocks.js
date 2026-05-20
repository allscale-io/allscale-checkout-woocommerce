/**
 * Block-based checkout — Allscale payment method registration.
 *
 * Renders the label (with icon + chain badges) and description.
 * Server-side process_payment handles the redirect, so the client side
 * stays minimal.
 */
(function () {
	'use strict';

	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var createElement = window.wp.element.createElement;
	var decodeEntities = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };

	var settings = (window.wc.wcSettings && window.wc.wcSettings.getSetting)
		? window.wc.wcSettings.getSetting('allscale_checkout_data', {})
		: {};
	var title = decodeEntities(settings.title || 'Pay with Crypto (Allscale)');
	var description = decodeEntities(settings.description || '');
	var icon = settings.icon || '';

	function Label() {
		var children = [];
		if (icon) {
			children.push(createElement('img', {
				key: 'icon',
				src: icon,
				alt: '',
				style: {
					display: 'inline-block',
					marginRight: '8px',
					height: '22px',
					width: 'auto',
					verticalAlign: 'middle'
				}
			}));
		}
		children.push(createElement('span', {
			key: 'text',
			style: { fontWeight: 600 }
		}, title));
		return createElement('span', { style: { display: 'inline-flex', alignItems: 'center' } }, children);
	}

	function Content() {
		return createElement('div', {
			style: { fontSize: '13.5px', color: '#5f6368', lineHeight: 1.4 }
		}, description);
	}

	registerPaymentMethod({
		name: 'allscale_checkout',
		label: createElement(Label, null),
		content: createElement(Content, null),
		edit: createElement(Content, null),
		ariaLabel: title,
		canMakePayment: function () { return true; },
		supports: {
			features: settings.supports || ['products']
		}
	});
})();
