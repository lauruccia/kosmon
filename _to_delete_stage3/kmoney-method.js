/**
 * KMoney payment method renderer.
 *
 * This method never collects card/bank data in Magento. Once the order is
 * placed, afterPlaceOrder() sends the browser to our own "redirect"
 * controller, which creates the KMoney payment request server-to-server and
 * 302s the customer to the KMoney hosted checkout page (pay_url), where they
 * log in with their own KMoney credentials and confirm the amount.
 *
 * NOTE on redirectAfterPlaceOrder: Magento's core payment component
 * (Magento_Checkout/js/view/payment/default) always calls
 * afterPlaceOrder() first and then, if redirectAfterPlaceOrder is left at
 * its default of true, ALSO calls redirect-on-success's execute(), which
 * does its own window.location.replace() to the default order-success page.
 * That second, competing replace() call is what caused the "order created,
 * customer never reaches KMoney" bug: it fires right after ours and wins.
 * Setting redirectAfterPlaceOrder: false below tells core to skip its own
 * redirect entirely and just let ours run - no race, and no dependency on
 * the exact internal API of redirect-on-success (which this store's theme
 * may customize).
 */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function ($, Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Kmoney_Payment/payment/kmoney',
            redirectAfterPlaceOrder: false
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
         * turn redirect to KMoney. Safe to do directly here now that core's
         * own automatic redirect is disabled above.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('kmoney/redirect/index'));
        }
    });
});
