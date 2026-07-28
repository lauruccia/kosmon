/**
 * KMoney payment method renderer.
 *
 * This method never collects card/bank data in Magento. Once the order is
 * placed, afterPlaceOrder() points Magento's own "redirect on success"
 * action at our "redirect" controller instead of navigating directly - see
 * the note on afterPlaceOrder() below for why. That controller creates the
 * KMoney payment request server-to-server and 302s the customer to the
 * KMoney hosted checkout page (pay_url), where they log in with their own
 * KMoney credentials and confirm the amount.
 */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/redirect-on-success',
    'mage/url'
], function ($, Component, redirectOnSuccessAction, url) {
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
         * "pending").
         *
         * IMPORTANT: do not window.location.replace() here directly. Right
         * after afterPlaceOrder() runs, Magento's core checkout flow always
         * also calls redirect-on-success's execute(), which itself does its
         * own window.location.replace() to the default order-success page.
         * Two competing replace() calls race, and the default one (fired
         * second) wins - the customer ends up on the plain Magento order
         * confirmation page, order created but never paid. Instead, tell
         * redirect-on-success to use our URL: Magento's own flow then
         * performs the single real navigation, with no race.
         */
        afterPlaceOrder: function () {
            redirectOnSuccessAction.setRedirectUrl(url.build('kmoney/redirect/index'));
        }
    });
});
