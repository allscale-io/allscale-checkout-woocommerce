(function () {
    'use strict';

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement = wp.element.createElement;
    var decodeEntities = wp.htmlEntities.decodeEntities;

    var settings = wc.wcSettings.getSetting('allscale_checkout_data', {});
    var title = decodeEntities(settings.title || 'Pay with Crypto (Allscale)');
    var description = decodeEntities(settings.description || '');
    var icon = settings.icon || '';

    var Label = function () {
        var children = [];
        if (icon) {
            children.push(
                createElement('img', {
                    key: 'icon',
                    src: icon,
                    alt: title,
                    style: { display: 'inline', marginRight: '8px', maxHeight: '24px', verticalAlign: 'middle' }
                })
            );
        }
        children.push(createElement('span', { key: 'text' }, title));
        return createElement('span', null, children);
    };

    var Content = function () {
        return createElement('div', null, description);
    };

    registerPaymentMethod({
        name: 'allscale_checkout',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        ariaLabel: title,
        canMakePayment: function () { return true; },
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
