-- ============================================================================
-- QUOTA PER IL CODICE AGENTE
-- 31/08/2026 · da eseguire su ENTRAMBI i server (kmoney = MariaDB, kosmopay = MySQL)
--
-- SEPARATO dall'SQL della quota di iscrizione dei privati
-- (2026_08_31_registration_fee.sql): sono due file indipendenti e si possono
-- eseguire in qualsiasi ordine, o anche uno solo.
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
-- DOPO L'ESECUZIONE LA QUOTA E' SPENTA (agent_code_fee_enabled = 0).
-- Si accende dal backoffice, /admin/quote-codice-agente.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 1 — PRIMA DI TOCCARE NIENTE (sola lettura)
-- Atteso su un server non ancora aggiornato: tutti e tre "insieme vuoto".
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'agent_code_fee%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE 'agent_code_fee%';
SHOW TABLES LIKE 'agent_code_fee_payments';


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 2 — COLONNE, UNA ALLA VOLTA
--
-- Su `users` la colonna agent_code_fee_due_cents nasce NULL per TUTTI, ed e'
-- quello che tiene fuori dalla quota gli agenti che ci sono gia'.
-- NON fare UPDATE su questa colonna.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_enabled` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_amount_cents` INT UNSIGNED NOT NULL DEFAULT 48000;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_stripe_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_paypal_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_bank_transfer_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_ky_enabled` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `users` ADD COLUMN `agent_code_fee_due_cents` INT UNSIGNED NULL;
ALTER TABLE `users` ADD COLUMN `agent_code_fee_paid_at` TIMESTAMP NULL;
ALTER TABLE `users` ADD COLUMN `agent_code_fee_ky_allowance_cents` INT UNSIGNED NOT NULL DEFAULT 0;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 3 — LA TABELLA DEI PAGAMENTI
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `agent_code_fee_payments` (
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
    UNIQUE KEY `agent_code_fee_payments_uuid_unique` (`uuid`),
    KEY `agent_code_fee_payments_user_id_status_index` (`user_id`, `status`),
    KEY `agent_code_fee_payments_status_index` (`status`),
    CONSTRAINT `agent_code_fee_payments_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 4 — VERIFICA (sola lettura)
-- Attesi: 6 righe, 3 righe, 1 riga, enabled = 0, e ZERO utenti con la quota
-- dovuta. Le ultime due o rispondono un numero, o dicono `#1054 - Colonna
-- sconosciuta`, e allora il BLOCCO 2 non e' passato.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'agent_code_fee%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE 'agent_code_fee%';
SHOW TABLES LIKE 'agent_code_fee_payments';

SELECT `code`, `agent_code_fee_enabled`, `agent_code_fee_amount_cents`,
       `agent_code_fee_stripe_enabled`, `agent_code_fee_paypal_enabled`,
       `agent_code_fee_bank_transfer_enabled`, `agent_code_fee_ky_enabled`
FROM `system_settings` WHERE `code` = 'user_limit_defaults';

SELECT COUNT(*) AS agenti_con_quota_dovuta FROM `users` WHERE `agent_code_fee_due_cents` IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 5 — SOLO SE IL BLOCCO 4 E' TORNATO COME DEVE
-- ─────────────────────────────────────────────────────────────────────────

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_31_150000_create_agent_code_fee', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `migration` FROM `migrations`) AS m
    WHERE m.`migration` = '2026_08_31_150000_create_agent_code_fee'
);


-- ─────────────────────────────────────────────────────────────────────────
-- UTILE DOPO L'ACCENSIONE — chi deve la quota e non l'ha ancora pagata
-- ─────────────────────────────────────────────────────────────────────────
-- SELECT u.id, u.name, u.email, u.agent_code_fee_due_cents / 100 AS dovuti_eur,
--        u.mlm_agent_request_status, u.mlm_agent_reviewed_at
-- FROM `users` u
-- WHERE u.agent_code_fee_due_cents IS NOT NULL
--   AND u.agent_code_fee_paid_at IS NULL
-- ORDER BY u.mlm_agent_reviewed_at;


-- ─────────────────────────────────────────────────────────────────────────
-- ANNULLAMENTO, se mai servisse (finche' la quota non e' stata accesa la
-- tabella e' vuota e le colonne sono ai default: non si perde niente)
-- ─────────────────────────────────────────────────────────────────────────
-- DROP TABLE IF EXISTS `agent_code_fee_payments`;
-- ALTER TABLE `users` DROP COLUMN `agent_code_fee_due_cents`;
-- ALTER TABLE `users` DROP COLUMN `agent_code_fee_paid_at`;
-- ALTER TABLE `users` DROP COLUMN `agent_code_fee_ky_allowance_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_amount_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_stripe_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_paypal_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_bank_transfer_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_ky_enabled`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_08_31_150000_create_agent_code_fee';
