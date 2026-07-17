-- ============================================================================
-- DEPLOY PRODUZIONE kosmopay.it — SQL consolidato (17/07/2026)
-- DB: phpMyAdmin, database di produzione (/home2/kosmopay/kosmon)
--
-- Contiene, IN ORDINE, tutte le migration accumulate dal 13/07 al 16/07:
--   10 migration = area MLM (requisiti configurabili, scadenza punti,
--   bonus diretti/extra, punti omaggio, radice unica, Prov K).
--
-- ⚠️ ESEGUIRE DOPO il deploy del codice da cPanel (prima codice, poi SQL).
--
-- ── PASSO 0 — VERIFICA COSA È GIÀ APPLICATO ────────────────────────────────
-- Esegui prima SOLO questa query:
--
--   SELECT migration FROM migrations WHERE migration LIKE '2026_07_1%' ORDER BY migration;
--
-- Ogni sezione qui sotto inizia con "SEZIONE n" e indica il nome della sua
-- migration. Se il nome compare già nel risultato della query, CANCELLA
-- l'intera sezione prima di eseguire il file (gli ALTER/CREATE non sono
-- rieseguibili: darebbero errore "duplicate column/table").
-- Se la query restituisce zero righe, esegui il file così com'è, tutto intero.
--
-- Gli INSERT nella tabella `migrations` sono invece protetti (NOT EXISTS):
-- non creano mai duplicati.
-- ============================================================================


-- ============================================================================
-- SEZIONE 1 — 2026_07_13_100000_extend_mlm_bonus_payouts_for_awards
-- Estende mlm_bonus_payouts per Bonus Diretti + Extra Bonus.
-- ============================================================================

ALTER TABLE mlm_bonus_payouts
  MODIFY mlm_bonus_event_id BIGINT UNSIGNED NULL,
  MODIFY rank_at_time VARCHAR(20) NULL,
  ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'struttura' AFTER rank_at_time,
  ADD INDEX mlm_bonus_payouts_kind_beneficiary_user_id_index (kind, beneficiary_user_id);

INSERT INTO migrations (migration, batch)
SELECT '2026_07_13_100000_extend_mlm_bonus_payouts_for_awards', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_13_100000_extend_mlm_bonus_payouts_for_awards');


-- ============================================================================
-- SEZIONE 2 — 2026_07_13_210000_create_mlm_rank_requirements_table
-- Requisiti di qualifica agente (Basic..Manager) configurabili da admin.
-- Valori seedati = quelli storici confermati dalla slide "Qualifiche" KNM.
-- ============================================================================

CREATE TABLE `mlm_rank_requirements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rank` VARCHAR(20) NOT NULL,
  `min_points` INT UNSIGNED NOT NULL DEFAULT 0,
  `min_level1_basic` INT UNSIGNED NOT NULL DEFAULT 0,
  `min_branches_with_key` INT UNSIGNED NOT NULL DEFAULT 0,
  `min_branches_with_senior` INT UNSIGNED NOT NULL DEFAULT 0,
  `min_branches_with_top` INT UNSIGNED NOT NULL DEFAULT 0,
  `min_branches_with_supervisor` INT UNSIGNED NOT NULL DEFAULT 0,
  `min_branches_300pt` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mlm_rank_requirements_rank_unique` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mlm_rank_requirements`
  (`rank`, `min_points`, `min_level1_basic`, `min_branches_with_key`, `min_branches_with_senior`, `min_branches_with_top`, `min_branches_with_supervisor`, `min_branches_300pt`, `created_at`, `updated_at`)
VALUES
  ('basic',      12, 0, 0, 0, 0, 0, 0, NOW(), NOW()),
  ('key',        24, 2, 0, 0, 0, 0, 0, NOW(), NOW()),
  ('senior',     48, 3, 2, 0, 0, 0, 0, NOW(), NOW()),
  ('top',        48, 4, 0, 0, 0, 0, 3, NOW(), NOW()),
  ('supervisor', 48, 5, 0, 4, 2, 0, 0, NOW(), NOW()),
  ('manager',    48, 6, 0, 0, 0, 3, 0, NOW(), NOW());

INSERT INTO migrations (migration, batch)
SELECT '2026_07_13_210000_create_mlm_rank_requirements_table', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_13_210000_create_mlm_rank_requirements_table');


-- ============================================================================
-- SEZIONE 3 — 2026_07_13_210100_add_mlm_points_validity_override_to_system_settings
-- Override "da test" (in minuti) della validita' dei punti cliente.
-- NULL = comportamento normale di produzione (nessun effetto).
-- ============================================================================

ALTER TABLE `system_settings`
  ADD COLUMN `mlm_points_validity_override_minutes` INT UNSIGNED NULL AFTER `welcome_bonus_amount`;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_13_210100_add_mlm_points_validity_override_to_system_settings', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_13_210100_add_mlm_points_validity_override_to_system_settings');


-- ============================================================================
-- SEZIONE 4 — 2026_07_13_210200_convert_mlm_point_ledger_to_datetime
-- valid_from/valid_until da DATE a DATETIME; le righe esistenti vengono
-- spostate a fine giornata (23:59:59) per non perdere validita'.
-- Va eseguita DOPO la Sezione 3.
-- ============================================================================

ALTER TABLE `mlm_point_ledger`
  MODIFY `valid_from` DATETIME NOT NULL,
  MODIFY `valid_until` DATETIME NOT NULL;

UPDATE `mlm_point_ledger`
SET `valid_until` = CONCAT(DATE(`valid_until`), ' 23:59:59')
WHERE `valid_until` IS NOT NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_13_210200_convert_mlm_point_ledger_to_datetime', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_13_210200_convert_mlm_point_ledger_to_datetime');


-- ============================================================================
-- SEZIONE 5 — 2026_07_14_090000_create_mlm_metric_grants_table
-- Punti/agenti omaggio assegnati dall'admin.
-- ============================================================================

CREATE TABLE `mlm_metric_grants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(36) NOT NULL,
  `agent_user_id` BIGINT UNSIGNED NOT NULL,
  `metric` ENUM('points','level1_basic_count') NOT NULL,
  `amount` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NULL,
  `granted_by_admin_id` BIGINT UNSIGNED NULL,
  `revoked_at` TIMESTAMP NULL DEFAULT NULL,
  `revoked_by_admin_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mlm_metric_grants_uuid_unique` (`uuid`),
  KEY `mlm_metric_grants_agent_user_id_metric_revoked_at_index` (`agent_user_id`, `metric`, `revoked_at`),
  CONSTRAINT `mlm_metric_grants_agent_user_id_foreign` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mlm_metric_grants_granted_by_admin_id_foreign` FOREIGN KEY (`granted_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mlm_metric_grants_revoked_by_admin_id_foreign` FOREIGN KEY (`revoked_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_14_090000_create_mlm_metric_grants_table', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_14_090000_create_mlm_metric_grants_table');


-- ============================================================================
-- SEZIONE 6 — 2026_07_15_120000_extend_mlm_metric_grants_metric_enum
-- Estende l'omaggio a tutte le 7 metriche di qualifica.
-- ============================================================================

ALTER TABLE `mlm_metric_grants` MODIFY `metric` ENUM(
    'points',
    'level1_basic_count',
    'branches_with_key',
    'branches_with_senior',
    'branches_with_top',
    'branches_with_supervisor',
    'branches_300pt'
) NOT NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_15_120000_extend_mlm_metric_grants_metric_enum', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_15_120000_extend_mlm_metric_grants_metric_enum');


-- ============================================================================
-- SEZIONE 7 — 2026_07_15_130000_make_mlm_metric_grants_amount_signed
-- amount con segno: l'admin puo' anche togliere quantita' omaggio.
-- ============================================================================

ALTER TABLE `mlm_metric_grants` MODIFY `amount` INT NOT NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_15_130000_make_mlm_metric_grants_amount_signed', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_15_130000_make_mlm_metric_grants_amount_signed');


-- ============================================================================
-- SEZIONE 8 — 2026_07_15_140000_create_mlm_pending_rank_awards_table
-- Coda delle promozioni di grado in attesa dell'Extra Bonus (job settimanale).
-- (CREATE ... IF NOT EXISTS: rieseguibile senza errore.)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `mlm_pending_rank_awards` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `rank` varchar(255) NOT NULL,
  `detected_at` timestamp NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mlm_pending_rank_awards_user_id_rank_unique` (`user_id`,`rank`),
  KEY `mlm_pending_rank_awards_user_id_foreign` (`user_id`),
  CONSTRAINT `mlm_pending_rank_awards_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_15_140000_create_mlm_pending_rank_awards_table', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_15_140000_create_mlm_pending_rank_awards_table');


-- ============================================================================
-- SEZIONE 9 — 2026_07_15_140000_add_mlm_root_agent_id_to_system_settings
-- Radice unica del sistema MLM (scelta dall'admin).
-- Va eseguita DOPO la Sezione 3 (posiziona la colonna dopo l'override minuti).
-- ============================================================================

ALTER TABLE system_settings
  ADD COLUMN mlm_root_agent_id BIGINT UNSIGNED NULL AFTER mlm_points_validity_override_minutes,
  ADD CONSTRAINT system_settings_mlm_root_agent_id_foreign FOREIGN KEY (mlm_root_agent_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_15_140000_add_mlm_root_agent_id_to_system_settings', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_15_140000_add_mlm_root_agent_id_to_system_settings');

-- NB: dopo il deploy, scegliere la radice unica da
-- /admin/mlm-impostazioni/radice. Finche' non lo fai il comportamento resta
-- identico a prima; alla scelta, gli alberi indipendenti esistenti vengono
-- consolidati automaticamente sotto la radice.


-- ============================================================================
-- SEZIONE 10 — 2026_07_16_100000_add_knm_margin_percent_for_prov_k
-- "Prov K": le % del residuale si applicano a importo mensile x margine KNM
-- (default 30%, configurabile da /admin/mlm-impostazioni, snapshot per deposito).
-- Va eseguita DOPO la Sezione 3.
-- ============================================================================

ALTER TABLE `system_settings`
  ADD COLUMN `mlm_knm_margin_percent` TINYINT UNSIGNED NULL AFTER `mlm_points_validity_override_minutes`;

ALTER TABLE `mlm_commission_base_ledger`
  ADD COLUMN `knm_margin_percent` TINYINT UNSIGNED NULL AFTER `monthly_amount_eur_cents`;

UPDATE `system_settings` SET `mlm_knm_margin_percent` = 30 WHERE `code` = 'mlm';

INSERT INTO migrations (migration, batch)
SELECT '2026_07_16_100000_add_knm_margin_percent_for_prov_k', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_16_100000_add_knm_margin_percent_for_prov_k');

-- NB: le commissioni gia' calcolate sul sito con i valori vecchi (importo
-- pieno, pre-Prov K) restano storiche per scelta. Se vuoi riallinearle:
-- cancella i run del mese e rilancia "Calcola commissioni" dall'admin.


-- ============================================================================
-- VERIFICA FINALE (facoltativa ma consigliata) — deve restituire 10 righe:
--
--   SELECT migration FROM migrations WHERE migration LIKE '2026_07_1%' ORDER BY migration;
--
-- ============================================================================
