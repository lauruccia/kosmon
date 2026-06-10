# PROJECT_MAP.md — Mappa del codebase KMoney

Mappa di riferimento per orientarsi senza riesplorare il progetto. Aggiornata al 2026-06-10.

## Root

| File | Scopo |
|---|---|
| `CLAUDE.md` | Istruzioni tecniche complete (stack, comandi, convenzioni) |
| `AI_CONTEXT.md` | Contesto sintetico per AI — leggere per primo |
| `AGENTS.md` | Regole operative per agenti AI |
| `CHANGELOG_AI.md` | Storico modifiche fatte dalle AI |
| `DEPLOY.md` / `MYSQL_SETUP.md` / `REVERB_SETUP.md` | Guide deploy/infra |
| `bootstrap/app.php` | Middleware alias, routing, Sentry |
| `vite.config.js` | Entry: `app.css`, `app.js`, `ky-payment-request.js` |

## app/Http/Controllers/ (portale, ~58 file)

| Area | Controller |
|---|---|
| Auth & sicurezza | AuthController, TwoFactorController, WebAuthnController, StepUpController, EmailChangeController, LoginLogController |
| Onboarding | OnboardingController, KycController, ContractController |
| Portale base | PortalController (dashboard), AccountController, WalletController, StatementController, ReceiptController, HelpController, DocsController, LegalController, HomeController |
| Pagamenti | SendPaymentController, PaymentHandlerController, PaymentRequestController, TextPaymentRequestController, CodePaymentController, IncassoQrController, SonicPaymentController, PaymentLinkController, ScheduledPaymentController, PaymentPlanController, NettingController |
| NFC | NfcCardController, NfcCardPaymentController, NfcPaymentController, StaticNfcController, CardController |
| KY Card (ricariche) | KyCardController |
| Marketplace | ListingController (shop), AnnouncementController |
| Kit merchant | MerchantKitController (`/kit-merchant`, PDF QR stampabile) |
| Referral | ReferralController (`/invita`, link invito, stats) |
| Report merchant | MerchantReportController (`/report-merchant`, KPI, trend, CSV) |
| Sottoconti | SubAccountInvitationController, SubAccountLimitRequestController |
| Preferenze/notifiche | NotificationPreferencesController, PushSubscriptionController, BalanceAlertController, BeneficiaryController |
| Integrazioni | ApiTokenController, WebhookController |
| Admin (prefisso Admin*) | AdminController, AdminFeeController, AdminKyCardController, AdminSectorController, AdminBroadcastController, CashbackRuleController + `Admin/`: AdminMenuVisibilityController, AdminNfcCardController |
| Broker | BrokerController |
| API v1 (`Api/V1/`) | AccountController, TransferController, PaymentRequestController, PaymentPlanController |

## app/Models/ (44 modelli)

Core finanziario: `Account`, `Transfer`, `LedgerEntry`, `AuditLog`, `CreditLimit`, `CreditLimitRequest`, `TransactionFee`, `CashbackRule`.
Pagamenti: `PaymentRequest`, `TextPaymentRequest`, `PaymentPlan`, `PaymentPlanInstallment`, `ScheduledPayment`, `NettingProposal`, `SavedBeneficiary`.
Ricariche/carte: `KyCard`, `KyCardPurchase`, `NfcCard`, `NfcCardAuthSession`, `NfcCardLog`.
Identità: `User`, `Company`, `Sector`, `Role`, `Permission`, `AccountManager`, `SubAccountInvitation`, `SubAccountLimitRequest`, `KycDocument`, `ContractSignature`, `WebAuthnCredential`, `LoginLog`.
Marketplace/social: `Listing`, `Announcement`, `AnnouncementReply`, `SupportMessage`.
Sistema: `ApiToken`, `Webhook`, `WebhookDelivery`, `PushSubscription`, `BalanceAlert`, `MenuVisibility`, `SystemSetting`.

## app/Services/ (9)

`TransferBookingService` (motore finanziario — leggere prima di toccare i saldi), `CashbackService`, `NettingService`, `PaymentPlanService`, `ScheduledPaymentService`, `SubAccountService`, `WebhookService`, `WebPushService`, `MenuVisibilityService`.

## app/Jobs/ (7)

`ProcessScheduledPayments` (ogni minuto), `ProcessDueInstallments` (ogni ora), `CheckBalanceAlerts` (ogni ora), `ExpirePaymentRequests` (ogni 5 min), `SendMonthlyStatements` (1° del mese), `SendWebhookJob`, `SendBroadcastMessageJob`. Schedulazione in `routes/console.php`.

## app/Http/Middleware/ (7)

`ApiTokenAuth`, `ContentSecurityPolicy`, `EnsureCompanyNotSuspended`, `EnsureContractSigned`, `EnsureOnboardingComplete`, `RequireStepUp`, `TwoFactorChallenge`.

## app/Console/Commands/ (12)

Import dati legacy (`ImportOldData`, `ImportAllTransactions`, `ReimportCurrentDump`, `FixImportData`, `FixImportedUsersDelegate`, `RepairImportedProfiles`, `AnalyzeDump`, `DebugUserFields`), manutenzione saldi (`RecalcAccountBalances`, `ReconcileBalances`), `MigrateNfcCardSerials`, `RunScheduledPayments`.

## app/Notifications/ (~29)

Una per evento: pagamenti, rate, netting, cashback, KY Card, fido, contratto OTP, login nuovo IP, estratto conto mensile, broadcast. Concern `RespectsNotificationPreferences` in `Concerns/`.

## resources/views/

- `layouts/portal.blade.php` — layout principale (nav, sidebar accordion a 6 gruppi: Panoramica/Paga/Incassa/Carte&Conto/Circuito/Strumenti); `layouts/legal.blade.php`
- `portal/` — ~73 viste: dashboard, wallet, movements, pay/invia/receive, incasso-qr, scanner, sonic/, code/, nfc-*, payment-links, payment-plans/, scheduled-payments/, netting/, text-requests/, ky-card*, shop*, announcements*, api-tokens/, webhooks/, security, kyc, contract-*, statement, beneficiaries/, balance-alerts, notification-preferences, sottoconti, **merchant-kit**, **referral**, **merchant-report**
- `admin/`, `broker/`, `auth/`, `emails/`, `home.blade.php`

## Altro

- `database/migrations/` — 88 migration. **In prod le modifiche schema vanno fatte via SQL su phpMyAdmin** (vedi AGENTS.md)
- `app/helpers.php` — `ky_format()`, `ky_to_cents()`, `ky_input()`
- `app/Support/Totp.php` — 2FA
- `app/Events/PaymentRequestUpdated` + Reverb per aggiornamenti real-time
- `GET /health` — health check JSON
