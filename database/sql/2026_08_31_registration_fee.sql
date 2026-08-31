-- ============================================================================
-- QUOTA DI ISCRIZIONE DEI PRIVATI
-- 31/08/2026 · da eseguire su ENTRAMBI i server (kmoney = MariaDB, kosmopay = MySQL)
--
-- DUE REGOLE IMPARATE A CARO PREZZO, rispettate qui dentro (vedi la nota del
-- 27/08 "I due server NON hanno lo stesso database"):
--
--   1. NIENTE `ADD COLUMN IF NOT EXISTS`: e' sintassi solo MariaDB, su MySQL
--      da errore 1064 e NON FA NIENTE. Le ALTER qui sotto sono nude, una per
--      riga: se una risponde `#1060 - Duplicate column name`, quella colonna
--      c'e' gia' — si salta quella e si prosegue con le altre.
--
--   2. NIENTE controlli via INFORMATION_SCHEMA: su kmoney.it l'utente
--      phpMyAdmin non puo' leggerlo e ogni SELECT torna "insieme vuoto"
--      anche quando la colonna esiste. Si controlla con SHOW.
--
-- E LA TERZA, la piu' importante: il BLOCCO 5 (la riga in `migrations`) si
-- esegue SOLO dopo aver verificato con il BLOCCO 4 che schema e tabella ci
-- sono davvero. Il 27/08 e' successo il contrario e il database ha dichiarato
-- fatta una migrazione che non c'era: `php artisan migrate` non l'avrebbe
-- piu' applicata.
--
-- ORDINE DI DEPLOY: PRIMA questo SQL verificato, POI il codice. Il codice
-- nuovo legge una tabella e tre colonne che qui non ci sono ancora.
--
-- DOPO L'ESECUZIONE LA QUOTA E' SPENTA (registration_fee_enabled = 0).
-- Si accende dal backoffice, /admin/quote-iscrizione. Finche' e' spenta non
-- cambia niente per nessuno.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 1 — PRIMA DI TOCCARE NIENTE (sola lettura)
-- Atteso su un server non ancora aggiornato: tutte e tre "insieme vuoto".
-- Se invece mostrano gia' le colonne/la tabella, questo file e' gia' stato
-- eseguito qui: passare direttamente al BLOCCO 4.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'registration_fee%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE 'registration_fee%';
SHOW TABLES LIKE 'registration_fee_payments';


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 2 — COLONNE, UNA ALLA VOLTA
-- Lanciarle una per una. `#1060 - Duplicate column name` = quella c'e' gia',
-- si passa alla successiva senza toccare altro.
--
-- Su `users` la colonna registration_fee_due_cents nasce NULL per TUTTI ed e'
-- esattamente quello che tiene fuori dalla quota chi e' gia' iscritto
-- (decisione di Laura: solo i nuovi privati). NON fare UPDATE su questa
-- colonna: un UPDATE qui vorrebbe dire mettere in debito 1300 persone.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `system_settings` ADD COLUMN `registration_fee_enabled` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `system_settings` ADD COLUMN `registration_fee_amount_cents` INT UNSIGNED NOT NULL DEFAULT 3000;
ALTER TABLE `system_settings` ADD COLUMN `registration_fee_stripe_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `registration_fee_paypal_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `registration_fee_bank_transfer_enabled` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `registration_fee_ky_enabled` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `users` ADD COLUMN `registration_fee_due_cents` INT UNSIGNED NULL;
ALTER TABLE `users` ADD COLUMN `registration_fee_paid_at` TIMESTAMP NULL;
ALTER TABLE `users` ADD COLUMN `registration_fee_ky_allowance_cents` INT UNSIGNED NOT NULL DEFAULT 0;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 3 — LA TABELLA DEI PAGAMENTI
-- `CREATE TABLE IF NOT EXISTS` e' standard e vale su entrambi i motori
-- (a differenza di ADD COLUMN IF NOT EXISTS): questo si puo' rilanciare.
-- ─────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `registration_fee_payments` (
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
    UNIQUE KEY `registration_fee_payments_uuid_unique` (`uuid`),
    KEY `registration_fee_payments_user_id_status_index` (`user_id`, `status`),
    KEY `registration_fee_payments_status_index` (`status`),
    CONSTRAINT `registration_fee_payments_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 4 — VERIFICA, PRIMA DI REGISTRARE LA MIGRAZIONE (sola lettura)
--
-- Attesi, tutti e cinque:
--   a) 6 righe                       b) 3 righe
--   c) 1 riga (la tabella esiste)    d) registration_fee_enabled = 0
--   e) 0 utenti con la quota dovuta
--
-- Le due SELECT finali sono le piu' oneste: o rispondono un numero, o dicono
-- `#1054 - Colonna sconosciuta`, e in quel caso il BLOCCO 2 non e' passato.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'registration_fee%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE 'registration_fee%';
SHOW TABLES LIKE 'registration_fee_payments';

SELECT `code`, `registration_fee_enabled`, `registration_fee_amount_cents`,
       `registration_fee_stripe_enabled`, `registration_fee_paypal_enabled`,
       `registration_fee_bank_transfer_enabled`, `registration_fee_ky_enabled`
FROM `system_settings` WHERE `code` = 'user_limit_defaults';

SELECT COUNT(*) AS utenti_con_quota_dovuta FROM `users` WHERE `registration_fee_due_cents` IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 5 — SOLO SE IL BLOCCO 4 HA DATO TUTTI I RISULTATI ATTESI
-- Registra la migrazione come gia' eseguita, cosi' un `php artisan migrate`
-- futuro non la ritenta. Se il BLOCCO 4 non torna, NON eseguire questo:
-- lasciare il database "in ritardo" e' recuperabile, dichiararlo aggiornato
-- a vuoto non lo e'.
-- ─────────────────────────────────────────────────────────────────────────

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_31_140000_create_registration_fee', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `migration` FROM `migrations`) AS m
    WHERE m.`migration` = '2026_08_31_140000_create_registration_fee'
);


-- ─────────────────────────────────────────────────────────────────────────
-- ANNULLAMENTO, se mai servisse (nessun dato utile va perso finche' la quota
-- non e' stata accesa: la tabella e' vuota e le colonne sono ai default)
-- ─────────────────────────────────────────────────────────────────────────
-- DROP TABLE IF EXISTS `registration_fee_payments`;
-- ALTER TABLE `users` DROP COLUMN `registration_fee_due_cents`;
-- ALTER TABLE `users` DROP COLUMN `registration_fee_paid_at`;
-- ALTER TABLE `users` DROP COLUMN `registration_fee_ky_allowance_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_amount_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_stripe_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_paypal_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_bank_transfer_enabled`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_ky_enabled`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_08_31_140000_create_registration_fee';
