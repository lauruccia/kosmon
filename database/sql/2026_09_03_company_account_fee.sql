-- ============================================================================
-- QUOTA DI APERTURA CONTO PER LE AZIENDE
-- 03/09/2026 · da eseguire su ENTRAMBI i server (kmoney = MariaDB, kosmopay = MySQL)
--
-- La TERZA quota del circuito, indipendente dalle altre due
-- (2026_08_31_registration_fee.sql e 2026_08_31_agent_code_fee.sql): questo
-- file si puo' eseguire in qualsiasi ordine rispetto a quelli, o da solo.
--
-- Stesse due regole di sempre (nota del 27/08 sui due database):
--   1. NIENTE `ADD COLUMN IF NOT EXISTS` — e' solo MariaDB, su MySQL da 1064
--      e non fa niente. Le ALTER sono nude: `#1060 - Duplicate column name`
--      vuol dire "c'e' gia'", si salta quella e si prosegue.
--   2. NIENTE INFORMATION_SCHEMA — su kmoney.it l'utente non lo puo' leggere
--      e ogni controllo tornerebbe "vuoto" anche a colonna esistente.
--
-- E la terza: il BLOCCO 5 (riga in `migrations`) SOLO dopo che il BLOCCO 4 ha
-- dato i risultati attesi.
--
-- ORDINE DI DEPLOY: prima questo SQL verificato, poi il codice.
--
-- COSA RICEVE L'AZIENDA IN CAMBIO (04/09/2026): due default globali qui
-- dentro — `company_account_fee_ky_credit_cents` (quanti KY a chi paga in
-- euro, 0 di partenza) e `company_account_fee_ky_allowance` (fido aggiuntivo a
-- chi paga in KY, acceso) — piu' due ripieghi per singola azienda su `users`.
--
-- DOPO L'ESECUZIONE LA QUOTA E' SPENTA (company_account_fee_enabled = 0) e il
-- pagamento in KY e' SPENTO anche lui (company_account_fee_ky_enabled = 0,
-- unica differenza dalle altre due quote: e' una concessione, non un default).
-- Si accende dal backoffice, /admin/quote-apertura-conto.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 1 — PRIMA DI TOCCARE NIENTE (sola lettura)
-- Atteso su un server non ancora aggiornato: tutti e tre "insieme vuoto".
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'company_account_fee%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE 'company_account_fee%';
SHOW TABLES LIKE 'company_account_fee_payments';


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 2 — COLONNE, UNA ALLA VOLTA
--
-- Su `users` la colonna company_account_fee_due_cents nasce NULL per TUTTI,
-- ed e' quello che tiene fuori dalla quota le ~1.200 aziende gia' presenti.
-- NON fare UPDATE su questa colonna: l'admin la mette in carico una alla
-- volta dalla scheda utente, con un audit log dietro.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_enabled` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_amount_cents` INT UNSIGNED NOT NULL DEFAULT 60000;
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_stripe_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_paypal_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_bank_transfer_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_ky_enabled` TINYINT(1) NOT NULL DEFAULT 0;
-- Cosa riceve l'azienda in cambio (04/09/2026), i due default globali:
-- quanti KY a chi paga in EURO (0 = niente), e se dare il fido aggiuntivo a
-- chi paga in KY (1 = si', come le altre due quote).
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_ky_credit_cents` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `system_settings` ADD COLUMN `company_account_fee_ky_allowance` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `users` ADD COLUMN `company_account_fee_due_cents` INT UNSIGNED NULL;
ALTER TABLE `users` ADD COLUMN `company_account_fee_paid_at` TIMESTAMP NULL;
ALTER TABLE `users` ADD COLUMN `company_account_fee_ky_allowance_cents` INT UNSIGNED NOT NULL DEFAULT 0;
-- I due ripieghi per singola azienda: NULL = segui il default del pannello.
-- NON confonderli con la colonna qui sopra, che e' il fido REALMENTE concesso
-- a chi ha gia' pagato in KY.
ALTER TABLE `users` ADD COLUMN `company_account_fee_ky_credit_override_cents` INT UNSIGNED NULL;
ALTER TABLE `users` ADD COLUMN `company_account_fee_ky_allowance_override` TINYINT(1) NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 3 — LA TABELLA DEI PAGAMENTI
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `company_account_fee_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `account_id` BIGINT UNSIGNED NULL,
    `amount_eur_cents` INT UNSIGNED NOT NULL,
    `ky_amount` INT UNSIGNED NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `payment_method` VARCHAR(32) NOT NULL,
    `stripe_checkout_session_id` VARCHAR(255) NULL,
    `stripe_payment_intent_id` VARCHAR(255) NULL,
    `paypal_order_id` VARCHAR(255) NULL,
    `transfer_id` BIGINT UNSIGNED NULL,
    `admin_notes` TEXT NULL,
    `confirmed_by` BIGINT UNSIGNED NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_account_fee_payments_uuid_unique` (`uuid`),
    KEY `company_account_fee_payments_user_id_status_index` (`user_id`, `status`),
    KEY `company_account_fee_payments_status_index` (`status`),
    CONSTRAINT `company_account_fee_payments_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 4 — VERIFICA (sola lettura)
-- Attesi: 8 righe, 5 righe, 1 riga, enabled = 0, ky_enabled = 0, e ZERO
-- utenti con la quota dovuta. Le ultime due o rispondono un numero, o dicono
-- `#1054 - Colonna sconosciuta`, e allora il BLOCCO 2 non e' passato.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'company_account_fee%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE 'company_account_fee%';
SHOW TABLES LIKE 'company_account_fee_payments';

SELECT `code`, `company_account_fee_enabled`, `company_account_fee_amount_cents`,
       `company_account_fee_stripe_enabled`, `company_account_fee_paypal_enabled`,
       `company_account_fee_bank_transfer_enabled`, `company_account_fee_ky_enabled`,
       `company_account_fee_ky_credit_cents`, `company_account_fee_ky_allowance`
FROM `system_settings` WHERE `code` = 'user_limit_defaults';

SELECT COUNT(*) AS aziende_con_quota_dovuta FROM `users` WHERE `company_account_fee_due_cents` IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 5 — SOLO SE IL BLOCCO 4 E' TORNATO COME DEVE
-- ─────────────────────────────────────────────────────────────────────────

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_03_120000_create_company_account_fee', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `migration` FROM `migrations`) AS m
    WHERE m.`migration` = '2026_09_03_120000_create_company_account_fee'
);


-- ─────────────────────────────────────────────────────────────────────────
-- UTILE DOPO L'ACCENSIONE — quali aziende devono la quota e non l'hanno pagata
-- ─────────────────────────────────────────────────────────────────────────
-- SELECT u.id, u.name, u.email, c.name AS azienda,
--        u.company_account_fee_due_cents / 100 AS dovuti_eur, u.created_at
-- FROM `users` u
-- LEFT JOIN `companies` c ON c.id = u.company_id
-- WHERE u.company_account_fee_due_cents IS NOT NULL
--   AND u.company_account_fee_due_cents > 0
--   AND u.company_account_fee_paid_at IS NULL
-- ORDER BY u.created_at;


-- ─────────────────────────────────────────────────────────────────────────
-- ANNULLAMENTO, se mai servisse (finche' la quota non e' stata accesa la
-- tabella e' vuota e le colonne sono ai default: non si perde niente)
-- ─────────────────────────────────────────────────────────────────────────
-- DROP TABLE IF EXISTS `company_account_fee_payments`;
-- ALTER TABLE `users` DROP COLUMN `company_account_fee_due_cents`;
-- ALTER TABLE `users` DROP COLUMN `company_account_fee_paid_at`;
-- ALTER TABLE `users` DROP COLUMN `company_account_fee_ky_allowance_cents`;
-- ALTER TABLE `users` DROP COLUMN `company_account_fee_ky_credit_override_cents`;
-- ALTER TABLE `users` DROP COLUMN `company_account_fee_ky_allowance_override`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_amount_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_stripe_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_paypal_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_bank_transfer_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_ky_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_ky_credit_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `company_account_fee_ky_allowance`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_09_03_120000_create_company_account_fee';
