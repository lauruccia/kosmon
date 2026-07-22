# KMoney payment integration for Magento 2

Implementation guide for the Magento developer. Everything described here is already live on the
KMoney backend (API v1) — you are not waiting on anything to start building. A complete,
ready-to-install Magento 2 module implementing this integration is included: see
**Appendix B** for the full source, or use the accompanying `kmoney-magento2-module.zip`.

**Connecting the store to a KMoney account needs only the account number.** The merchant
enters their KMoney account number (KYB.../KYP...) in **Stores > Configuration > Sales >
Payment Methods > KMoney** and saves — no API token, no webhook secret to create or paste.
KMoney notifies its circuit administrator, who approves the connection from the merchant's
company page; the module then retrieves the API token and webhook signing secret by itself
the next time the configuration page is opened, and stores them encrypted. This is the exact
same account-number pairing already used by the KMoney WooCommerce plugin — see Section 7 for
the full flow. Manual/classic setup (pasting a token and webhook secret yourself) still works
if you prefer it.

If anything here is ambiguous, ask the store owner (KMoney merchant account holder) before
guessing — in particular the currency assumption in Section 4 and the refund gap in Section 8.

---

## 1. What KMoney is, in one paragraph

KMoney (KY) is a closed-loop local business currency (similar to Sardex/mutual credit
circuits). Every merchant and customer holds a KY account on `kmoney-app`, a Laravel
application. Amounts are always **integer cents of KY** (`5000` = `50,00 KY`) — never floats.
Your Magento store is one merchant on this circuit; customers pay you in KY through their own
KMoney account.

## 2. Non-negotiable security rule

**Magento must never collect, see, or transmit a customer's KMoney email/password.** The only
place a customer enters their KMoney credentials is on KMoney's own domain, on a page that has
2FA and passkey/WebAuthn support. Magento's job is limited to:

1. Asking KMoney (server-to-server, using the merchant's own API token) to create a "payment
   request" for the order total.
2. Redirecting the customer's browser to the URL KMoney returns (`pay_url`).
3. Finding out afterwards — via webhook and/or a server-side status check — whether the customer
   completed the payment.

This is the same pattern as PayPal Checkout, Stripe Checkout, or Klarna's hosted page: your
store never touches the payment credentials.

## 3. Sequence overview

```
Customer            Magento (this module)              KMoney API / KMoney hosted page
   |                        |                                      |
   | checks out, picks      |                                      |
   | KMoney, places order   |                                      |
   |----------------------->|                                      |
   |                        | order created, status "pending"      |
   |                        | POST /api/v1/payment-requests ------>|
   |                        |<----- { uuid, token, pay_url } -------|
   |  302 redirect to pay_url                                      |
   |<-----------------------|                                      |
   |  logs into KMoney (2FA / passkey), confirms amount             |
   |---------------------------------------------------------------->|
   |                        |   webhook: payment_request.paid  <----|  (authoritative, async)
   |                        |   POST /kmoney/webhook/index           |
   |  redirected back to return_url with kmoney_pr_uuid             |
   |<----------------------------------------------------------------|
   |  GET /kmoney/return/index                                      |
   |                        | GET /api/v1/payment-requests/{uuid}   |
   |                        |   (never trust the redirect alone) -->|
   |                        |<---------------- { status: paid } ----|
   |                        | order invoiced, "checkout/success"    |
   |<-----------------------|                                      |
```

Two independent confirmation paths exist on purpose (webhook + return-controller check) because
the customer may close the browser tab before the redirect completes. Whichever happens first
invoices the order; the other one is a no-op (see `OrderFinalizer::markPaid()`, which checks
`$order->hasInvoices()` before doing anything).

## 4. Amounts: KY vs. store currency

KMoney amounts are integer **cents of KY**. Local-currency circuits like this one are normally
pegged 1:1 to the store's national currency (1 KY ≈ 1 EUR), but **confirm this with the store
owner before going live** — if it is not 1:1, you need a conversion rate somewhere in
`Controller/Redirect/Index.php` (`$amountCents = (int) round($order->getGrandTotal() * 100)`
currently assumes 1:1).

## 5. API reference (KMoney API v1)

Base URL: `https://<kmoney-domain>/api/v1` (ask the store owner for the exact domain).
Auth: HTTP header `Authorization: Bearer km_xxxxxxxxxxxx`.

The Bearer token is generated by the merchant on the KMoney portal, under `/api-tokens`. It must
have the **`write`** ability enabled, or `POST /payment-requests` will be rejected with `403`.

Rate limits: 60 requests/minute overall, 10 requests/minute on `POST /payment-requests`
specifically. Handle `429` with backoff — do not hammer retries.

### 5.1 `POST /payment-requests` — create a hosted payment request

Request:

```http
POST /api/v1/payment-requests HTTP/1.1
Host: kmoney.example.com
Authorization: Bearer km_xxxxxxxxxxxx
Content-Type: application/json

{
  "amount": 4999,
  "description": "Order #000000123",
  "external_reference": "000000123",
  "return_url": "https://your-store.com/kmoney/return/index",
  "cancel_url": "https://your-store.com/checkout/cart",
  "expires_in_minutes": 30
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `amount` | integer | yes | Cents of KY, minimum 1 |
| `description` | string | no | Max 255 chars |
| `external_reference` | string | no | Max 191 chars. **Use the Magento order `increment_id`.** If a `pending` request with the same `external_reference` + `amount` already exists for your account, the API returns that one instead of creating a duplicate — safe to call again on checkout retries/timeouts. |
| `return_url` | string (URL) | no | Where KMoney sends the customer back after a **successful** payment. KMoney appends `kmoney_status`, `kmoney_pr_uuid`, `kmoney_transfer_uuid` query params — **do not trust these values on their own**, always re-verify with a `GET` (Section 5.2). |
| `cancel_url` | string (URL) | no | Shown to the customer as an escape hatch on the payment page; KMoney does not call this automatically. |
| `expires_in_minutes` | integer | no | 1–1440, default 30 |

Response `201 Created` (or `200 OK` if an existing pending request was returned for idempotency):

```json
{
  "data": {
    "uuid": "b7e1e6b0-....",
    "status": "pending",
    "direction": "incoming",
    "kind": "ecommerce",
    "amount": 4999,
    "currency": "KY",
    "description": "Order #000000123",
    "external_reference": "000000123",
    "pay_url": "https://kmoney.example.com/pay/AbCdEf1234567890...",
    "expires_at": "2026-07-02T15:30:00+00:00",
    "paid_at": null,
    "creditor": { "account_number": "KYB1234567890AB", "company": "Your Store" },
    "payer": null,
    "transfer_uuid": null,
    "created_at": "2026-07-02T15:00:00+00:00"
  }
}
```

**Redirect the customer's browser to `data.pay_url`.** That page is fully hosted and
authenticated by KMoney.

Error responses: `403` (token missing `write` ability), `422` (validation error / no active
merchant account), `401` (bad/missing/expired token).

### 5.2 `GET /payment-requests/{uuid}` — check status server-side

```http
GET /api/v1/payment-requests/b7e1e6b0-.... HTTP/1.1
Authorization: Bearer km_xxxxxxxxxxxx
```

Same response shape as above; `status` is one of `pending`, `paid`, `expired`, `cancelled`. Use
this to confirm payment when the customer returns to `return_url` — **never invoice an order
based only on the query-string values KMoney appended to the redirect.**

### 5.3 Other endpoints you likely won't need for checkout

`GET /me`, `GET /balance`, `GET /transfers`, `GET /transfers/{uuid}`, `POST /transfers`,
`GET /payment-plans`, `GET /payment-plans/{uuid}` also exist, documented at
`https://<kmoney-domain>/api/openapi.json`. Not needed for a standard checkout integration.

## 6. Webhook: `payment_request.paid`

This is the **authoritative** confirmation — treat the browser redirect as a nice-to-have UX
shortcut, and the webhook as the source of truth.

### 6.1 Register the webhook

On the KMoney portal (merchant login) go to **Settings → Webhooks → New webhook**:

- URL: `https://your-store.com/kmoney/webhook/index`
- Event: `payment_request.paid`
- Save. **Copy the "secret" shown once at creation time** — you cannot retrieve it again later,
  only regenerate it (which would require updating Magento's config too).

Paste that secret into Magento admin: **Stores → Configuration → Sales → Payment Methods →
KMoney → Webhook signing secret**.

### 6.2 Payload

```http
POST /kmoney/webhook/index HTTP/1.1
Content-Type: application/json
X-KMoney-Event: payment_request.paid
X-KMoney-Signature: sha256=3f7a9c...

{
  "event": "payment_request.paid",
  "timestamp": "2026-07-02T15:03:12+00:00",
  "payload": {
    "uuid": "b7e1e6b0-....",
    "token": "AbCdEf1234567890...",
    "kind": "ecommerce",
    "external_reference": "000000123",
    "amount": 4999,
    "currency": "KY",
    "description": "Order #000000123",
    "status": "paid",
    "paid_at": "2026-07-02T15:03:12+00:00",
    "transfer_uuid": "9f1c2a44-....",
    "payer_account_number": "KYP9876543210CD"
  }
}
```

### 6.3 Signature verification (already implemented in `Controller/Webhook/Index.php`)

```php
$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawRequestBody, $webhookSecret);
$isValid = hash_equals($expectedSignature, $requestHeaders['X-KMoney-Signature']);
```

Reject (HTTP 401) anything with a missing or non-matching signature. Respond `200` quickly —
KMoney's `SendWebhookJob` retries up to 3 times with backoff on non-2xx responses, so a `500`
here is safe (it will retry) but should not be your normal path.

## 7. Module installation

The module `Kmoney_Payment` is complete and included (Appendix B / zip). Steps:

```bash
# 1. Copy the module
cp -r Kmoney app/code/Kmoney   # result: app/code/Kmoney/Payment/...

# 2. Enable and install
bin/magento module:enable Kmoney_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile          # skip in developer mode if you prefer
bin/magento setup:static-content:deploy -f     # production mode only
bin/magento cache:flush
```

Then configure: **Stores → Configuration → Sales → Payment Methods → KMoney**

| Field | Value |
|---|---|
| Enabled | Yes |
| Title | Whatever the customer should see (default "KMoney") |
| Instructions | Free text shown under the method at checkout |
| KMoney account number | The merchant's KMoney account number (KYB.../KYP...). This is the only field the merchant has to fill in — see 7.1 below. |
| KMoney API base URL | Pre-filled `https://kmoney.it/api/v1`; change only for a different KMoney instance |
| KMoney API token | Filled in automatically once the account number is linked (7.1). Can also be pasted manually. |
| Webhook signing secret | Filled in automatically together with the token. Can also be pasted manually (Section 6.1). |

Both the token and the webhook secret fields use Magento's built-in encryption
(`Magento\Config\Model\Config\Backend\Encrypted`) — they are not stored in plaintext in the
database, whether they were typed in by hand or filled in automatically by the pairing flow
below.

### 7.1 Account-number pairing (default, no token/secret to copy)

This is the same pairing flow already used by the KMoney WooCommerce plugin, ported as-is to
Magento — see `Model/Pairing.php`, `Observer/StartPairingOnConfigSave.php` and
`Observer/PollPairingOnConfigEdit.php` in Appendix B.

1. The merchant enters **only the KMoney account number** above and saves the configuration
   page (`Stores → Configuration → Sales → Payment Methods → KMoney`).
2. On save, `admin_system_config_changed` fires `StartPairingOnConfigSave`, which calls
   `Pairing::maybeStart()`: it generates a random `claim_secret` and calls
   `POST /api/v1/ecommerce/pairings` with `account_number`, `site_url`, `webhook_url`
   (`<store base url>/kmoney/webhook/index`), `claim_secret` and `platform: "magento"` — the
   same public, rate-limited endpoint the WooCommerce plugin uses, with a different
   `platform` value. The request state (`uuid`, `claim_secret`, `status: pending`) is stored
   encrypted in `core_config_data` (`payment/kmoney/pairing_state`), not shown in the admin UI.
3. The circuit administrator sees the pending request on the KMoney portal
   (`/admin/companies/{id}`, "Richieste di collegamento in attesa") and approves or rejects it.
   Approval creates an API token (`read`+`write`) named `Plugin Magento — <host>` and a
   `payment_request.paid` webhook pointing at the store's webhook URL, and leaves the raw
   credentials encrypted on the pairing record until the module retrieves them.
4. The next time `Stores → Configuration → Sales → Payment Methods` is opened,
   `controller_action_predispatch_adminhtml_system_config_edit` fires `PollPairingOnConfigEdit`,
   which calls `Pairing::maybePoll()`: `GET /api/v1/ecommerce/pairings/{uuid}?claim_secret=...`.
   If approved, the API token and webhook secret are retrieved **exactly once** (the server
   zeroes them out after this call) and saved into the same encrypted config fields a manual
   setup would use — `Helper\Data::getApiToken()`/`getWebhookSecret()` decrypt them exactly the
   same way regardless of how they got there. An admin notice ("connection pending" / "account
   connected!" / "connection failed: ...") is shown at the top of the configuration page.
5. Changing the account number to a different one restarts the pairing from step 2.

Nothing about the actual payment flow (Sections 3–6, Controller/Redirect, Controller/Return,
Controller/Webhook) changes because of this — pairing only affects how the API token and
webhook secret get into the two encrypted config fields.

## 8. What is intentionally NOT included (be upfront with the store owner)

- **Refunds are not automated.** The KMoney API v1 has no public refund endpoint today. If a
  Magento order needs a refund, the merchant currently has to do it manually from the KMoney
  portal. `_canRefund` is set to `false` on the payment method on purpose — do not silently
  enable it without a real API to call.
- **No partial payments / split payment (KY + another method).** This module assumes the whole
  order total is paid in KY. If the store previously used a "pay X% in KMoney, rest in card"
  model (as the old WooCommerce plugin did), that's a separate, larger feature — confirm with
  the store owner whether it's actually needed before building it.
- **Multi-currency stores**: the amount conversion in `Controller/Redirect/Index.php` assumes
  the order's grand total is already in the right unit to become KY 1:1 (see Section 4).

## 9. End-to-end test checklist

0. **Pairing**: enter only the KMoney account number and save → confirm a "connection pending"
   notice appears. Approve the request from the KMoney admin (`/admin/companies/{id}`). Reopen
   the configuration page → confirm the "account connected!" notice and that the API token /
   webhook secret fields are now populated (still shown as dots, like any obscure field).
1. Config saved, module enabled, `bin/magento cache:flush` run.
2. Place a test order selecting "KMoney" as the payment method → confirm you land on
   `https://kmoney.../pay/...` (a KMoney-branded page, not a Magento page).
3. Log in with a **test KMoney account that has enough balance**, confirm payment.
4. Confirm you're redirected back to `checkout/onepage/success` in Magento.
5. Confirm the order now has an invoice and status "Processing" (check Sales → Orders).
6. Check the order's comment history — you should see "KMoney: payment request ... created" and
   "KMoney: payment confirmed, order invoiced".
7. Repeat steps 2–3 but close the browser tab right after confirming payment on KMoney (before
   the redirect completes) — the order should still get invoiced within a few seconds via the
   webhook alone. Check `Kmoney webhook` delivery log on the KMoney portal (Settings → Webhooks
   → your webhook → deliveries) to confirm a `200` response from Magento.
8. Try paying with insufficient KMoney balance — KMoney's own page should block it (nothing to
   test on the Magento side, but confirm the order stays "pending" and is not incorrectly
   invoiced).
9. Let a payment request expire (create one, wait past `expires_in_minutes`, then try to pay) —
   confirm KMoney shows "expired" and Magento's order stays pending / uninvoiced.
10. Tamper with the webhook signature (e.g. curl the endpoint with a wrong `X-KMoney-Signature`)
    — confirm Magento responds `401` and does **not** invoice the order.
11. Change the account number to a different one and save → confirm a new pairing request is
    sent (visible as a new pending request on the KMoney admin side) rather than reusing the old
    token.

## 10. File manifest

```
Kmoney/Payment/
├── registration.php
├── composer.json
├── README.md
├── etc/
│   ├── module.xml
│   ├── config.xml
│   ├── adminhtml/
│   │   ├── system.xml
│   │   └── events.xml             (wires the two pairing observers below)
│   └── frontend/
│       ├── routes.xml
│       └── di.xml
├── Model/
│   ├── Kmoney.php                 (payment method)
│   ├── ConfigProvider.php         (exposes title/instructions to checkout JS)
│   ├── OrderFinalizer.php         (shared invoicing logic, idempotent)
│   ├── Pairing.php                (account-number pairing: start + poll + admin notice)
│   └── Api/Client.php             (KMoney API HTTP client, used for the actual payment flow)
├── Observer/
│   ├── StartPairingOnConfigSave.php   (admin_system_config_changed)
│   └── PollPairingOnConfigEdit.php    (controller_action_predispatch_adminhtml_system_config_edit)
├── Helper/Data.php                (reads + decrypts admin config)
├── Controller/
│   ├── Redirect/Index.php         (GET /kmoney/redirect/index — creates the payment request)
│   ├── Return/Index.php           (GET /kmoney/return/index — verifies + invoices)
│   └── Webhook/Index.php          (POST /kmoney/webhook/index — verifies + invoices)
├── view/frontend/
│   ├── layout/checkout_index_index.xml
│   └── web/
│       ├── js/view/payment/kmoney.js
│       ├── js/view/payment/method-renderer/kmoney-method.js
│       └── template/payment/kmoney.html
└── i18n/en_US.csv
```

## 11. Questions / contact

For anything about the KMoney API itself (rate limits, account setup, webhook secret, going
live), contact the store owner / KMoney merchant account holder — they manage the KMoney side of
this integration (API token, webhook registration) directly on the KMoney portal.

---

---

## Appendix B — Full module source code

Every file below is also included, ready to use, in `kmoney-magento2-module.zip`. Copy the `Kmoney/` folder into `app/code/` of your Magento installation (result: `app/code/Kmoney/Payment/...`).

### `app/code/Kmoney/Payment/registration.php`

```php
<?php
/**
 * Kmoney_Payment module registration.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Kmoney_Payment',
    __DIR__
);
```

### `app/code/Kmoney/Payment/composer.json`

```json
{
    "name": "kmoney/module-payment",
    "version": "1.1.0",
    "description": "KMoney hosted-checkout payment method for Magento 2 (redirect + webhook, no card/PSD2 data touches the store). Store connection is by account number only: enter it and save, an admin approval on the KMoney side fills in the API token and webhook secret automatically.",
    "type": "magento2-module",
    "license": "proprietary",
    "require": {
        "php": "~8.1.0||~8.2.0||~8.3.0",
        "magento/framework": "*",
        "magento/module-payment": "*",
        "magento/module-sales": "*",
        "magento/module-checkout": "*"
    },
    "autoload": {
        "files": [
            "registration.php"
        ],
        "psr-4": {
            "Kmoney\\Payment\\": ""
        }
    }
}
```

### `app/code/Kmoney/Payment/etc/module.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Kmoney_Payment" setup_version="1.1.0">
        <sequence>
            <module name="Magento_Sales"/>
            <module name="Magento_Payment"/>
            <module name="Magento_Checkout"/>
        </sequence>
    </module>
</config>
```

### `app/code/Kmoney/Payment/etc/config.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Payment:etc/payment.xsd">
    <default>
        <payment>
            <kmoney>
                <active>0</active>
                <model>Kmoney\Payment\Model\Kmoney</model>
                <title>KMoney</title>
                <instructions>Pay with your KMoney account. You will be redirected to KMoney to confirm the payment securely with your own credentials (2FA/passkey supported). You never enter your KMoney password on this site.</instructions>
                <order_status>pending</order_status>
                <allowspecific>0</allowspecific>
                <group>offline</group>
                <sort_order>50</sort_order>
                <can_use_checkout>1</can_use_checkout>
                <can_use_internal>0</can_use_internal>
                <api_base_url>https://kmoney.it/api/v1</api_base_url>
            </kmoney>
        </payment>
    </default>
</config>
```

### `app/code/Kmoney/Payment/etc/frontend/routes.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:App/etc/routes.xsd">
    <router id="standard">
        <route id="kmoney" frontName="kmoney">
            <module name="Kmoney_Payment"/>
        </route>
    </router>
</config>
```

### `app/code/Kmoney/Payment/etc/frontend/di.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Checkout\Model\CompositeConfigProvider">
        <arguments>
            <argument name="configProviders" xsi:type="array">
                <item name="kmoney" xsi:type="object">Kmoney\Payment\Model\ConfigProvider</item>
            </argument>
        </arguments>
    </type>
</config>
```

### `app/code/Kmoney/Payment/etc/adminhtml/system.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Config:etc/system_file.xsd">
    <system>
        <section id="payment">
            <group id="kmoney" translate="label" type="text" sortOrder="100" showInDefault="1" showInWebsite="1" showInStore="0">
                <label>KMoney</label>
                <field id="active" translate="label" type="select" sortOrder="10" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>Enabled</label>
                    <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
                </field>
                <field id="title" translate="label" type="text" sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Title (shown to customer at checkout)</label>
                </field>
                <field id="instructions" translate="label" type="textarea" sortOrder="25" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Instructions (shown at checkout, under the method)</label>
                </field>
                <field id="account_number" translate="label" type="text" sortOrder="28" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>KMoney account number</label>
                    <comment><![CDATA[Enter your KMoney account number (starts with KYB or KYP) and save. That is the only thing you need to do: KMoney notifies its circuit administrator, who approves the connection from the merchant's company page. As soon as it is approved, this module retrieves the API token and webhook signing secret below by itself — nothing to copy or paste.]]></comment>
                </field>
                <field id="api_base_url" translate="label" type="text" sortOrder="30" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>KMoney API base URL</label>
                    <comment><![CDATA[Pre-filled for kmoney.it. Change it only if this store connects to a different KMoney instance.]]></comment>
                </field>
                <field id="api_token" translate="label" type="obscure" sortOrder="40" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>KMoney API token</label>
                    <comment><![CDATA[Filled in automatically once the account number above is linked and approved. You can also paste a token here yourself if you prefer the classic manual setup (token generated on the KMoney portal under /api-tokens, "write" ability).]]></comment>
                    <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
                </field>
                <field id="webhook_secret" translate="label" type="obscure" sortOrder="50" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>Webhook signing secret</label>
                    <comment><![CDATA[Filled in automatically together with the API token above. Manual alternative: the "secret" shown once when creating a webhook on the KMoney portal (Settings > Webhooks > New webhook, URL = https://your-store.com/kmoney/webhook/index, event = payment_request.paid).]]></comment>
                    <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
                </field>
                <field id="sort_order" translate="label" type="text" sortOrder="60" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>Sort Order</label>
                    <frontend_class>validate-number</frontend_class>
                </field>
            </group>
        </section>
    </system>
</config>
```

### `app/code/Kmoney/Payment/etc/adminhtml/events.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <!--
        Account-number pairing (same flow as the KMoney WooCommerce plugin):
        the merchant only enters the KMoney account number below. These two
        observers drive the pairing request and its follow-up, so the API
        token and webhook secret above get filled in automatically once the
        circuit administrator approves the connection.
    -->
    <event name="admin_system_config_changed">
        <observer name="kmoney_start_pairing_on_config_save" instance="Kmoney\Payment\Observer\StartPairingOnConfigSave"/>
    </event>
    <event name="controller_action_predispatch_adminhtml_system_config_edit">
        <observer name="kmoney_poll_pairing_on_config_edit" instance="Kmoney\Payment\Observer\PollPairingOnConfigEdit"/>
    </event>
</config>
```

### `app/code/Kmoney/Payment/Model/Kmoney.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * KMoney payment method.
 *
 * This is intentionally a thin, offline-style method: it never authorizes or
 * captures anything itself. The real payment happens on the KMoney hosted
 * checkout page (see Controller/Redirect/Index), and the order is invoiced
 * only after KMoney confirms the payment via webhook or via the return
 * controller's server-side verification (see Model/OrderFinalizer).
 */
class Kmoney extends AbstractMethod
{
    public const CODE = 'kmoney';

    protected $_code = self::CODE;

    protected $_isOffline = false;
    protected $_isInitializeNeeded = false;

    protected $_canOrder = true;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canCapturePartial = false;
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canFetchTransactionInfo = false;
}
```

### `app/code/Kmoney/Payment/Model/ConfigProvider.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Escaper;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Exposes the KMoney title/instructions to the checkout JS layer
 * (window.checkoutConfig.payment.kmoney).
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly PaymentHelper $paymentHelper,
        private readonly Escaper $escaper
    ) {
    }

    public function getConfig(): array
    {
        $method = $this->paymentHelper->getMethodInstance(Kmoney::CODE);

        if (!$method->isAvailable()) {
            return [];
        }

        return [
            'payment' => [
                Kmoney::CODE => [
                    'title'        => $this->escaper->escapeHtml((string) $method->getTitle()),
                    'instructions' => $this->escaper->escapeHtml((string) $method->getConfigData('instructions')),
                ],
            ],
        ];
    }
}
```

### `app/code/Kmoney/Payment/Model/OrderFinalizer.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

/**
 * Turns a confirmed KMoney payment_request.paid event into a Magento invoice.
 *
 * Shared by Controller/Return/Index (customer comes back from KMoney) and
 * Controller/Webhook/Index (server-to-server notification). Both paths can
 * race each other, so this is written to be idempotent: it does nothing if
 * the order is already invoiced.
 */
class OrderFinalizer
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $dbTransaction,
        private readonly InvoiceSender $invoiceSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Order $order
     * @param array $paymentRequest Decoded "PaymentRequest" payload from the KMoney API
     *                              (either the GET /payment-requests/{uuid} response, or
     *                              the "payload" object of a payment_request.paid webhook).
     */
    public function markPaid(Order $order, array $paymentRequest): void
    {
        if ($order->hasInvoices() || !$order->canInvoice()) {
            // Already handled by the other path (return controller vs. webhook), or
            // the order is in a state that no longer accepts an invoice. Not an error.
            return;
        }

        $transferUuid = $paymentRequest['transfer_uuid'] ?? ($paymentRequest['uuid'] ?? null);

        $payment = $order->getPayment();
        if ($payment && $transferUuid) {
            $payment->setTransactionId($transferUuid);
            $payment->setAdditionalInformation('kmoney_transfer_uuid', $transferUuid);
            $payment->setIsTransactionClosed(true);
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->getOrder()->setIsInProcess(true);

        $this->dbTransaction
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        try {
            $this->invoiceSender->send($invoice);
        } catch (\Throwable $e) {
            // Never fail order finalization because of a broken email transport.
            $this->logger->warning('Kmoney: invoice email not sent', ['error' => $e->getMessage()]);
        }

        $order->addCommentToStatusHistory(
            __('KMoney: payment confirmed, order invoiced (transfer %1).', $transferUuid ?? 'n/a')
        )->setIsCustomerNotified(true)->save();
    }
}
```

### `app/code/Kmoney/Payment/Model/Pairing.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model;

use Kmoney\Payment\Helper\Data as ConfigHelper;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Links the store's KMoney account using only the account number (pairing).
 *
 * Same flow as the KMoney WooCommerce plugin: the merchant enters only the
 * KMoney account number (KYB... or KYP...) in the payment method settings
 * and saves. This module then sends a connection request to KMoney
 * (POST /api/v1/ecommerce/pairings) with a claim secret generated here. The
 * circuit administrator approves the request from /admin/companies/{id} on
 * the KMoney side; the next time the configuration page is opened (or
 * saved), this module checks the request status, retrieves the API token
 * and webhook signing secret exactly once (authenticating with the claim
 * secret) and stores them itself, encrypted like any other "obscure"
 * Magento config field. The merchant never copies or pastes a token or a
 * webhook secret.
 */
class Pairing
{
    private const CONFIG_PATH_STATE          = 'payment/kmoney/pairing_state';
    private const CONFIG_PATH_ACCOUNT_NUMBER = 'payment/kmoney/account_number';
    private const CONFIG_PATH_API_TOKEN      = 'payment/kmoney/api_token';
    private const CONFIG_PATH_WEBHOOK_SECRET = 'payment/kmoney/webhook_secret';
    private const PLATFORM                   = 'magento';

    public function __construct(
        private readonly ConfigHelper $configHelper,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Called right after the payment settings are saved. Starts (or
     * restarts) the pairing if an account number was entered but there is
     * no token yet, or if the account number changed since the previous
     * request.
     */
    public function maybeStart(): void
    {
        $accountNumber = $this->normalizeAccountNumber(
            (string) $this->scopeConfig->getValue(self::CONFIG_PATH_ACCOUNT_NUMBER)
        );

        if ($accountNumber === '') {
            $this->clearState();
            return;
        }

        $state = $this->readState();
        $accountChanged = isset($state['account_number']) && $state['account_number'] !== $accountNumber;
        $hasToken = $this->configHelper->getApiToken() !== '';

        // Already linked with this exact account, or a request is already
        // in flight: nothing to do.
        if (!$accountChanged && ($hasToken || (($state['status'] ?? null) === 'pending'))) {
            return;
        }

        $baseUrl = $this->configHelper->getApiBaseUrl();
        if ($baseUrl === '') {
            $this->writeState([
                'status'  => 'error',
                'message' => 'KMoney API base URL is missing.',
            ]);
            return;
        }

        $claimSecret = bin2hex(random_bytes(20));
        $siteUrl = rtrim($this->getStoreBaseUrl(), '/') . '/';
        $webhookUrl = rtrim($siteUrl, '/') . '/kmoney/webhook/index';

        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            $curl->setTimeout(20);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
            $curl->post($baseUrl . '/ecommerce/pairings', $this->json->serialize([
                'account_number' => $accountNumber,
                'site_url'       => $siteUrl,
                'webhook_url'    => $webhookUrl,
                'claim_secret'   => $claimSecret,
                'platform'       => self::PLATFORM,
            ]));

            $status = (int) $curl->getStatus();
            $decoded = $this->decodeBody((string) $curl->getBody());
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney pairing: unable to reach KMoney', ['error' => $e->getMessage()]);
            $this->writeState([
                'account_number' => $accountNumber,
                'status'         => 'error',
                'message'        => 'Could not reach KMoney: ' . $e->getMessage(),
            ]);
            return;
        }

        if ($status === 201 && is_array($decoded) && !empty($decoded['uuid'])) {
            $this->writeState([
                'uuid'           => (string) $decoded['uuid'],
                'claim_secret'   => $claimSecret,
                'account_number' => $accountNumber,
                'status'         => 'pending',
            ]);
            return;
        }

        $message = is_array($decoded) && !empty($decoded['error'])
            ? (string) $decoded['error']
            : ('Unexpected response from KMoney (HTTP ' . $status . ').');

        $this->writeState([
            'account_number' => $accountNumber,
            'status'         => 'error',
            'message'        => $message,
        ]);
    }

    /**
     * Called when the payment settings page is opened. If a request is
     * pending, checks its status with KMoney; if approved, retrieves the
     * credentials and stores them.
     */
    public function maybePoll(): void
    {
        $state = $this->readState();

        if (($state['status'] ?? null) !== 'pending' || empty($state['uuid']) || empty($state['claim_secret'])) {
            return;
        }

        $baseUrl = $this->configHelper->getApiBaseUrl();
        if ($baseUrl === '') {
            return;
        }

        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->addHeader('Accept', 'application/json');
            $curl->setTimeout(20);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
            $curl->get(
                $baseUrl . '/ecommerce/pairings/' . rawurlencode((string) $state['uuid'])
                . '?claim_secret=' . rawurlencode((string) $state['claim_secret'])
            );

            $http = (int) $curl->getStatus();
            $decoded = $this->decodeBody((string) $curl->getBody());
        } catch (\Throwable $e) {
            // Temporary network error: this will be retried the next time
            // the page is opened.
            $this->logger->warning('Kmoney pairing: poll failed', ['error' => $e->getMessage()]);
            return;
        }

        if (!is_array($decoded)) {
            return;
        }

        if ($http === 404) {
            // The request no longer exists on the KMoney side (e.g. it was
            // replaced by a newer one): starts over on the next save.
            $state['status'] = 'error';
            $state['message'] = 'This connection request is no longer valid: save the settings again to send a new one.';
            $this->writeState($state);
            return;
        }

        $remoteStatus = $decoded['status'] ?? '';

        if ($remoteStatus === 'approved' && !empty($decoded['api_token'])) {
            // One-time delivery: store the credentials right away, encrypted
            // the same way as any other "obscure" field of the payment method.
            $this->writeEncryptedConfig(self::CONFIG_PATH_API_TOKEN, (string) $decoded['api_token']);
            if (!empty($decoded['webhook_secret'])) {
                $this->writeEncryptedConfig(self::CONFIG_PATH_WEBHOOK_SECRET, (string) $decoded['webhook_secret']);
            }

            $this->writeState([
                'account_number' => $state['account_number'] ?? '',
                'status'         => 'linked',
                'just_linked'    => true,
            ]);
            return;
        }

        if ($remoteStatus === 'approved' && !empty($decoded['claimed'])) {
            // Credentials were already retrieved elsewhere (e.g. another
            // installation) but are not present here: a new pairing is needed.
            $state['status'] = 'error';
            $state['message'] = 'The credentials for this connection have already been retrieved elsewhere. Save the settings again to send a new connection request.';
            $this->writeState($state);
            return;
        }

        if ($remoteStatus === 'rejected') {
            $state['status'] = 'rejected';
            $state['message'] = 'The circuit administrator rejected the connection request. Check the account number or contact KMoney support.';
            $this->writeState($state);
        }
        // "pending": nothing changed, this will be retried on the next page load.
    }

    /**
     * Notice to show at the top of the configuration page (the success
     * notice is shown only once). Returns null if there is nothing to show.
     *
     * @return array{type: string, message: string}|null
     */
    public function consumeNotice(): ?array
    {
        $state = $this->readState();

        if (empty($state['status'])) {
            return null;
        }

        if ($state['status'] === 'pending') {
            return [
                'type'    => 'warning',
                'message' => (string) __(
                    'KMoney: the connection request for account %1 is awaiting approval from the circuit administrator. Once approved, this module configures itself automatically — reopen this page to check.',
                    $state['account_number'] ?? ''
                ),
            ];
        }

        if ($state['status'] === 'linked' && !empty($state['just_linked'])) {
            unset($state['just_linked']);
            $this->writeState($state);

            return [
                'type'    => 'success',
                'message' => (string) __('KMoney: account connected! The API token and webhook were configured automatically.'),
            ];
        }

        if (in_array($state['status'], ['error', 'rejected'], true)) {
            return [
                'type'    => 'error',
                'message' => (string) __('KMoney: the connection could not be completed. %1', $state['message'] ?? ''),
            ];
        }

        return null;
    }

    private function normalizeAccountNumber(string $accountNumber): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $accountNumber));
    }

    private function getStoreBaseUrl(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function clearState(): void
    {
        $this->configWriter->delete(self::CONFIG_PATH_STATE);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::CONFIG_PATH_STATE);
        if ($raw === '') {
            return [];
        }

        try {
            $decrypted = $this->encryptor->decrypt($raw);
            $decoded = $this->json->unserialize($decrypted);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $encrypted = $this->encryptor->encrypt($this->json->serialize($state));
        $this->configWriter->save(self::CONFIG_PATH_STATE, $encrypted, 'default', 0);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');
    }

    private function writeEncryptedConfig(string $path, string $value): void
    {
        $this->configWriter->save($path, $this->encryptor->encrypt($value), 'default', 0);
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');
    }

    /** @return array<string, mixed>|null */
    private function decodeBody(string $rawBody): ?array
    {
        if ($rawBody === '') {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($rawBody);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
```

### `app/code/Kmoney/Payment/Model/Api/Client.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Model\Api;

use Kmoney\Payment\Helper\Data as ConfigHelper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Thin client for KMoney API v1 (https://<host>/api/v1).
 *
 * Auth: "Authorization: Bearer km_..." token, generated on the KMoney portal
 * under /api-tokens with the "write" ability. Amounts are always integer
 * cents of KY (e.g. 5000 = 50,00 KY) — never send floats.
 */
class Client
{
    public function __construct(
        private readonly ConfigHelper $configHelper,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    /**
     * POST /payment-requests
     *
     * Creates a hosted payment request and returns the decoded "data" object,
     * which includes "uuid", "token" and "pay_url" — redirect the customer's
     * browser to pay_url to complete the payment on KMoney's own domain.
     *
     * external_reference should be the Magento order increment_id: if a
     * pending request with the same external_reference + amount already
     * exists, the API returns that one instead of creating a duplicate
     * (safe to call again on checkout retries).
     */
    public function createPaymentRequest(
        int $amountCents,
        string $description,
        string $externalReference,
        string $returnUrl,
        string $cancelUrl,
        int $expiresInMinutes = 30
    ): array {
        return $this->request('POST', '/payment-requests', [
            'amount'             => $amountCents,
            'description'        => $description,
            'external_reference' => $externalReference,
            'return_url'         => $returnUrl,
            'cancel_url'         => $cancelUrl,
            'expires_in_minutes' => $expiresInMinutes,
        ]);
    }

    /**
     * GET /payment-requests/{uuid}
     *
     * Used by the return controller to verify server-side that a payment
     * request is really "paid" before invoicing — never trust the browser
     * redirect query string alone.
     */
    public function getPaymentRequest(string $uuid): array
    {
        return $this->request('GET', '/payment-requests/' . rawurlencode($uuid));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $baseUrl = $this->configHelper->getApiBaseUrl();
        $token   = $this->configHelper->getApiToken();

        if (!$baseUrl || !$token) {
            throw new \RuntimeException('KMoney API is not configured (base URL / token missing).');
        }

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('Accept', 'application/json');
        $curl->setTimeout(20);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);

        $url = $baseUrl . $path;

        if ($method === 'POST') {
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post($url, $this->json->serialize($body ?? []));
        } else {
            $curl->get($url);
        }

        $status = (int) $curl->getStatus();
        $rawBody = (string) $curl->getBody();

        $decoded = [];
        if ($rawBody !== '') {
            try {
                $decoded = $this->json->unserialize($rawBody);
            } catch (\Throwable $e) {
                throw new \RuntimeException('KMoney API returned an invalid response (HTTP ' . $status . ').');
            }
        }

        if ($status >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $status);
            throw new \RuntimeException('KMoney API error (' . $status . '): ' . $message);
        }

        return $decoded['data'] ?? $decoded;
    }
}
```

### `app/code/Kmoney/Payment/Observer/StartPairingOnConfigSave.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Observer;

use Kmoney\Payment\Model\Pairing;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Fires after the admin saves Stores > Configuration > Sales > Payment
 * Methods. If an account number was entered but there is no API token yet
 * (or the account number changed), starts the KMoney account pairing —
 * same behavior as the KMoney WooCommerce plugin: no token or webhook
 * secret to copy and paste.
 */
class StartPairingOnConfigSave implements ObserverInterface
{
    public function __construct(
        private readonly Pairing $pairing,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($this->request->getParam('section') !== 'payment') {
            return;
        }

        $this->pairing->maybeStart();
    }
}
```

### `app/code/Kmoney/Payment/Observer/PollPairingOnConfigEdit.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Observer;

use Kmoney\Payment\Model\Pairing;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;

/**
 * Fires when Stores > Configuration > Sales > Payment Methods is opened.
 * If a KMoney account pairing is pending, checks its status; once approved,
 * the API token and webhook secret were already retrieved and stored by
 * Pairing::maybePoll() — this observer only surfaces the resulting notice
 * (pending / connected / failed) to the admin.
 */
class PollPairingOnConfigEdit implements ObserverInterface
{
    public function __construct(
        private readonly Pairing $pairing,
        private readonly RequestInterface $request,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($this->request->getParam('section') !== 'payment') {
            return;
        }

        $this->pairing->maybePoll();

        $notice = $this->pairing->consumeNotice();
        if ($notice === null) {
            return;
        }

        switch ($notice['type']) {
            case 'success':
                $this->messageManager->addSuccessMessage($notice['message']);
                break;
            case 'warning':
                $this->messageManager->addWarningMessage($notice['message']);
                break;
            default:
                $this->messageManager->addErrorMessage($notice['message']);
                break;
        }
    }
}
```

### `app/code/Kmoney/Payment/Helper/Data.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    private const XML_PATH_API_BASE_URL   = 'payment/kmoney/api_base_url';
    private const XML_PATH_API_TOKEN      = 'payment/kmoney/api_token';
    private const XML_PATH_WEBHOOK_SECRET = 'payment/kmoney/webhook_secret';
    private const XML_PATH_ACCOUNT_NUMBER = 'payment/kmoney/account_number';

    public function __construct(
        Context $context,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        $url = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return rtrim($url, '/');
    }

    /**
     * Both the account-number pairing (Model\Pairing) and a merchant pasting
     * a token manually store it through Magento's "obscure" field encryption
     * (backend_model Encrypted on save). Decrypt here so callers always get
     * the plain Bearer token.
     */
    public function getApiToken(?int $storeId = null): string
    {
        return $this->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_PATH_API_TOKEN, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    public function getWebhookSecret(?int $storeId = null): string
    {
        return $this->decrypt(
            (string) $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_SECRET, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    /**
     * KMoney account number entered by the merchant (KYB.../KYP...). It is
     * the only thing the merchant has to provide: the actual connection
     * (API token + webhook secret) is retrieved automatically — see
     * Model\Pairing.
     */
    public function getAccountNumber(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ACCOUNT_NUMBER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $decrypted = $this->encryptor->decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }

        // A value that fails to decrypt into something meaningful is
        // treated as already-plain (e.g. a fresh install before the first
        // encrypted save) rather than silently discarded.
        return $decrypted !== '' ? $decrypted : $value;
    }
}
```

### `app/code/Kmoney/Payment/Controller/Redirect/Index.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Controller\Redirect;

use Kmoney\Payment\Model\Api\Client;
use Kmoney\Payment\Model\Kmoney as KmoneyMethod;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /kmoney/redirect/index
 *
 * Called by the checkout JS right after the order is placed
 * (see view/frontend/web/js/view/payment/method-renderer/kmoney-method.js,
 * afterPlaceOrder()). Creates the KMoney payment request for the last order
 * in the checkout session and 302-redirects the browser to the hosted
 * payment page (pay_url) on the KMoney domain, where the customer logs in
 * with their own KMoney credentials (2FA/passkey) and confirms the amount.
 *
 * This store never sees or handles the customer's KMoney credentials.
 */
class Index extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RedirectFactory $redirectFactory,
        private readonly Client $apiClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId() || $order->getPayment()->getMethod() !== KmoneyMethod::CODE) {
            $this->messageManager->addErrorMessage(__('Unable to start the KMoney payment for this order.'));
            return $resultRedirect->setPath('checkout/cart');
        }

        // KMoney amounts are integer cents of KY; Magento's grand total is a
        // decimal in the order currency. This assumes a 1:1 KY <> store
        // currency mapping — adjust the conversion here if that is not the case.
        $amountCents = (int) round(((float) $order->getGrandTotal()) * 100);

        try {
            $paymentRequest = $this->apiClient->createPaymentRequest(
                amountCents: $amountCents,
                description: (string) __('Order #%1', $order->getIncrementId()),
                externalReference: (string) $order->getIncrementId(),
                returnUrl: $this->_url->getUrl('kmoney/return/index', ['_secure' => true]),
                cancelUrl: $this->_url->getUrl('checkout/cart', ['_secure' => true])
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney: unable to create payment request', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);

            $order->addCommentToStatusHistory(
                __('KMoney: unable to create the payment request (%1). Customer was sent back to the cart.', $e->getMessage())
            );
            $this->orderRepository->save($order);

            $this->messageManager->addErrorMessage(
                __('KMoney payment is currently unavailable. Please choose another payment method.')
            );
            return $resultRedirect->setPath('checkout/cart');
        }

        if (empty($paymentRequest['pay_url'])) {
            $this->logger->error('Kmoney: createPaymentRequest response has no pay_url', ['order' => $order->getIncrementId()]);
            $this->messageManager->addErrorMessage(__('KMoney payment is currently unavailable. Please choose another payment method.'));
            return $resultRedirect->setPath('checkout/cart');
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('kmoney_pr_uuid', $paymentRequest['uuid'] ?? null);
        $payment->setAdditionalInformation('kmoney_pr_token', $paymentRequest['token'] ?? null);

        $order->addCommentToStatusHistory(
            __('KMoney: payment request %1 created, customer redirected to KMoney to pay.', $paymentRequest['uuid'] ?? '?')
        );
        $this->orderRepository->save($order);

        return $resultRedirect->setUrl($paymentRequest['pay_url']);
    }
}
```

### `app/code/Kmoney/Payment/Controller/Return/Index.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Controller\Return;

use Kmoney\Payment\Model\Api\Client;
use Kmoney\Payment\Model\OrderFinalizer;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

/**
 * GET /kmoney/return/index
 *
 * KMoney redirects the customer's browser back here after a successful
 * payment (return_url passed when the payment request was created), with
 * kmoney_status / kmoney_pr_uuid / kmoney_transfer_uuid query parameters.
 *
 * IMPORTANT: those query parameters are NOT trusted on their own — anyone
 * could craft a matching URL. This controller always calls back to the
 * KMoney API (GET /payment-requests/{uuid}) to verify the real status
 * server-side before invoicing the order. The webhook controller
 * (Controller/Webhook/Index) is the fully authoritative confirmation and
 * will do the same thing independently if this controller is never hit
 * (e.g. the customer closes the tab before the redirect completes).
 */
class Index extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly Client $apiClient,
        private readonly OrderFinalizer $orderFinalizer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->redirectFactory->create();
        $prUuid = (string) $this->getRequest()->getParam('kmoney_pr_uuid', '');
        $order = $this->checkoutSession->getLastRealOrder();

        if ($prUuid === '' || !$order || !$order->getId()) {
            return $resultRedirect->setPath('checkout/cart');
        }

        try {
            $paymentRequest = $this->apiClient->getPaymentRequest($prUuid);
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney: unable to verify payment request on return', [
                'pr_uuid' => $prUuid,
                'order'   => $order->getIncrementId(),
                'error'   => $e->getMessage(),
            ]);
            $this->messageManager->addNoticeMessage(
                __('We could not confirm your KMoney payment yet. If the payment succeeded, your order will be updated automatically within a few minutes.')
            );
            return $resultRedirect->setPath('checkout/cart');
        }

        $isPaid = ($paymentRequest['status'] ?? null) === 'paid';
        $matchesOrder = ($paymentRequest['external_reference'] ?? null) === $order->getIncrementId();

        if ($isPaid && $matchesOrder) {
            $this->orderFinalizer->markPaid($order, $paymentRequest);
            return $resultRedirect->setPath('checkout/onepage/success');
        }

        $this->messageManager->addNoticeMessage(
            __('Your KMoney payment was not completed. You can try again or choose another payment method.')
        );
        return $resultRedirect->setPath('checkout/cart');
    }
}
```

### `app/code/Kmoney/Payment/Controller/Webhook/Index.php`

```php
<?php

declare(strict_types=1);

namespace Kmoney\Payment\Controller\Webhook;

use Kmoney\Payment\Helper\Data as ConfigHelper;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Kmoney\Payment\Model\OrderFinalizer;
use Psr\Log\LoggerInterface;

/**
 * POST /kmoney/webhook/index
 *
 * Receives "payment_request.paid" webhooks pushed by kmoney-app
 * (see Webhook::EVENTS / SendWebhookJob in the KMoney codebase). This is the
 * authoritative, asynchronous confirmation that a payment succeeded — do not
 * rely on the customer's browser redirect alone (Controller/Return/Index)
 * because the customer may never come back to this domain.
 *
 * Register the webhook on the KMoney portal under Settings > Webhooks:
 *   URL:    https://your-store.com/kmoney/webhook/index
 *   Events: payment_request.paid
 * Copy the "secret" shown once at creation time into
 * Stores > Configuration > Sales > Payment Methods > KMoney > Webhook signing secret.
 *
 * This endpoint is called server-to-server by kmoney-app with no Magento
 * session / form key, so CSRF validation is explicitly disabled below;
 * the HMAC signature check is what protects it instead.
 */
class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ConfigHelper $configHelper,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderFinalizer $orderFinalizer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $rawBody = (string) $this->getRequest()->getContent();
        $signatureHeader = (string) $this->getRequest()->getHeader('X-KMoney-Signature');
        $secret = $this->configHelper->getWebhookSecret();

        if ($secret === '') {
            $this->logger->error('Kmoney webhook: no webhook secret configured in Magento admin');
            return $result->setHttpResponseCode(500)->setData(['error' => 'webhook not configured']);
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        if ($signatureHeader === '' || !hash_equals($expectedSignature, $signatureHeader)) {
            $this->logger->warning('Kmoney webhook: invalid or missing signature');
            return $result->setHttpResponseCode(401)->setData(['error' => 'invalid signature']);
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload) || ($payload['event'] ?? null) !== 'payment_request.paid') {
            // Acknowledge (200) so KMoney does not retry events this endpoint
            // does not care about — only report a real error for our own event.
            return $result->setHttpResponseCode(200)->setData(['ignored' => true]);
        }

        $paymentRequest = $payload['payload'] ?? [];
        $externalReference = $paymentRequest['external_reference'] ?? null;

        if (!$externalReference) {
            return $result->setHttpResponseCode(200)->setData(['ignored' => true, 'reason' => 'no external_reference']);
        }

        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $externalReference)
            ->setPageSize(1)
            ->getFirstItem();

        if (!$order || !$order->getId()) {
            $this->logger->warning('Kmoney webhook: no matching order', ['external_reference' => $externalReference]);
            return $result->setHttpResponseCode(200)->setData(['ignored' => true, 'reason' => 'order not found']);
        }

        try {
            $this->orderFinalizer->markPaid($order, $paymentRequest);
        } catch (\Throwable $e) {
            $this->logger->error('Kmoney webhook: order finalization failed', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            // Non-2xx so kmoney-app's SendWebhookJob retries (it retries up to 3 times).
            return $result->setHttpResponseCode(500)->setData(['error' => 'processing failed']);
        }

        return $result->setHttpResponseCode(200)->setData(['ok' => true]);
    }
}
```

### `app/code/Kmoney/Payment/view/frontend/layout/checkout_index_index.xml`

```xml
<?xml version="1.0"?>
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceBlock name="checkout.root">
            <arguments>
                <argument name="jsLayout" xsi:type="array">
                    <item name="components" xsi:type="array">
                        <item name="checkout" xsi:type="array">
                            <item name="children" xsi:type="array">
                                <item name="steps" xsi:type="array">
                                    <item name="children" xsi:type="array">
                                        <item name="billing-step" xsi:type="array">
                                            <item name="children" xsi:type="array">
                                                <item name="payment" xsi:type="array">
                                                    <item name="children" xsi:type="array">
                                                        <item name="renders" xsi:type="array">
                                                            <item name="children" xsi:type="array">
                                                                <item name="kmoney" xsi:type="array">
                                                                    <item name="component" xsi:type="string">Kmoney_Payment/js/view/payment/kmoney</item>
                                                                    <item name="methods" xsi:type="array">
                                                                        <item name="kmoney" xsi:type="array">
                                                                            <item name="isBillingAddressRequired" xsi:type="boolean">true</item>
                                                                        </item>
                                                                    </item>
                                                                </item>
                                                            </item>
                                                        </item>
                                                    </item>
                                                </item>
                                            </item>
                                        </item>
                                    </item>
                                </item>
                            </item>
                        </item>
                    </item>
                </argument>
            </arguments>
        </referenceBlock>
    </body>
</page>
```

### `app/code/Kmoney/Payment/view/frontend/web/js/view/payment/kmoney.js`

```javascript
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
```

### `app/code/Kmoney/Payment/view/frontend/web/js/view/payment/method-renderer/kmoney-method.js`

```javascript
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
```

### `app/code/Kmoney/Payment/view/frontend/web/template/payment/kmoney.html`

```html
<div class="payment-method" data-bind="css: {'_active': (getCode() == isChecked())}">
    <div class="payment-method-title field choice">
        <input type="radio"
               name="payment[method]"
               class="radio"
               data-bind="attr: {'id': getCode()}, value: getCode(), checked: isChecked, click: selectPaymentMethod, visible: isRadioButtonVisible()" />
        <label class="label" data-bind="attr: {'for': getCode()}">
            <span data-bind="text: getTitle()"></span>
        </label>
    </div>
    <div class="payment-method-content">
        <!-- ko foreach: getRegion('messages') -->
        <!-- ko template: getTemplate() --><!-- /ko -->
        <!-- /ko -->
        <div class="payment-method-billing-address">
            <!-- ko foreach: $parent.getRegion(getBillingAddressFormName()) -->
            <!-- ko template: getTemplate() --><!-- /ko -->
            <!-- /ko -->
        </div>
        <div class="kmoney-instructions" data-bind="text: getInstructions()" style="margin: 10px 0 16px; font-size: 13px; color: #555;"></div>
        <div class="actions-toolbar">
            <div class="primary">
                <button class="action primary checkout"
                        type="submit"
                        data-bind="
                            click: placeOrder,
                            attr: {title: $t('Continue to KMoney')},
                            css: {disabled: !isPlaceOrderActionAllowed()}
                        "
                        disabled>
                    <span data-bind="i18n: 'Continue to KMoney'"></span>
                </button>
            </div>
        </div>
    </div>
</div>
```

### `app/code/Kmoney/Payment/i18n/en_US.csv`

```csv
"KMoney","KMoney"
"Continue to KMoney","Continue to KMoney"
"Enabled","Enabled"
"Title (shown to customer at checkout)","Title (shown to customer at checkout)"
"Instructions (shown at checkout, under the method)","Instructions (shown at checkout, under the method)"
"KMoney account number","KMoney account number"
"KMoney API base URL","KMoney API base URL"
"KMoney API token","KMoney API token"
"Webhook signing secret","Webhook signing secret"
"Sort Order","Sort Order"
"KMoney: the connection request for account %1 is awaiting approval from the circuit administrator. Once approved, this module configures itself automatically — reopen this page to check.","KMoney: the connection request for account %1 is awaiting approval from the circuit administrator. Once approved, this module configures itself automatically — reopen this page to check."
"KMoney: account connected! The API token and webhook were configured automatically.","KMoney: account connected! The API token and webhook were configured automatically."
"KMoney: the connection could not be completed. %1","KMoney: the connection could not be completed. %1"
```

### `app/code/Kmoney/Payment/README.md`

```markdown
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
```
