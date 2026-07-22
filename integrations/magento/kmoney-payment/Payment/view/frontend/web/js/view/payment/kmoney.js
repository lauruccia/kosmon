/**
 * Registers the KMoney method renderer with Magento's checkout payment
 * renderer list. This is the file referenced by
 * view/frontend/layout/checkout_index_index.xml ("component": "Kmoney_Payment/js/view/payment/kmoney").
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'kmoney',
        component: 'Kmoney_Payment/js/view/payment/method-renderer/kmoney-method'
    });

    return Component.extend({});
});
