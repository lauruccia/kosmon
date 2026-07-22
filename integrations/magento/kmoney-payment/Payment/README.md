# Kmoney_Payment — KMoney hosted checkout for Magento 2

See the full integration guide (KMONEY_MAGENTO_INTEGRATION.md) delivered alongside this
module for setup steps, sequence diagrams and API reference. Quick summary:

1. Copy this folder to `app/code/Kmoney/Payment` in your Magento installation.
2. `bin/magento module:enable Kmoney_Payment`
3. `bin/magento setup:upgrade`
4. `bin/magento setup:di:compile` (skip on developer mode if you prefer)
5. `bin/magento setup:static-content:deploy -f` (production mode only)
6. `bin/magento cache:flush`
7. Configure: Stores > Configuration > Sales > Payment Methods > KMoney
   - Enter your **KMoney account number** (starts with KYB or KYP) and save. That's it —
     no API token, no webhook secret to create or paste.
8. The circuit administrator sees the connection request on the KMoney side and approves
   it. Reopen this configuration page afterwards: the module retrieves the API token and
   webhook signing secret by itself and stores them, encrypted.
9. Enable the payment method once you see the "account connected" notice.

Manual/classic setup is still supported if you prefer it or your KMoney account was set up
before this version: leave "KMoney account number" empty and paste the API token and
webhook signing secret yourself (token from the KMoney portal under /api-tokens with the
"write" ability; webhook created under Settings > Webhooks, URL =
`https://your-store.com/kmoney/webhook/index`, event `payment_request.paid`).

No card, bank or KMoney-account data is ever entered on the Magento site: the customer is
redirected to KMoney's own hosted page to authenticate and confirm the payment.
