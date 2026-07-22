/**
 * KMoney payment method renderer.
 *
 * This method never collects card/bank data in Magento. Once the order is
 * placed, afterPlaceOrder() sends the browser to our own "redirect"
 * controller, which creates the KMoney payment request server-to-server and
 * 302s the customer to the KMoney hosted checkout page (pay_url), where they
 * log in with their own KMoney credentials and confirm the amount.
 */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function ($, Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Kmoney_Payment/payment/kmoney'
        },

        getCode: function () {
            return 'kmoney';
        },

        getTitle: function () {
            return window.checkoutConfig.payment.kmoney.title || 'KMoney';
        },

        getInstructions: function () {
            return window.checkoutConfig.payment.kmoney.instructions || '';
        },

        /**
         * Called by Magento_Checkout/js/view/payment/default after a
         * successful "Place Order" call. The order now exists (status
         * "pending"); redirect the browser to our controller, which will in
         * turn redirect to KMoney.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('kmoney/redirect/index'));
        }
    });
});
