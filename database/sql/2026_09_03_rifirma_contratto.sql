-- ============================================================================
-- RIFIRMA DEL CONTRATTO DOPO UNA REVISIONE
-- 03/09/2026 · da eseguire su ENTRAMBI i server (kmoney = MariaDB, kosmopay = MySQL)
--
-- Fino a oggi modificare il testo alzava `contract_version` ma nessuno
-- confrontava mai la versione firmata con quella in vigore: chi aveva firmato
-- passava indisturbato e continuava a vedere il proprio snapshot vecchio.
-- Da qui in avanti l'admin decide, revisione per revisione, se serve una
-- firma nuova.
--
-- E l'altra meta' della stessa decisione: una CORREZIONE (refuso, virgola,
-- un riferimento sbagliato) NON alza la versione e non chiede niente a
-- nessuno, ma le aziende che hanno firmato quella versione devono vedere il
-- testo giusto al posto dell'errore. Serve a questo la terza colonna,
-- `contract_text_corrected_at`. Gli snapshot firmati in
-- `contract_signatures` non vengono mai riscritti: restano la prova dei byte
-- firmati.
--
-- REGOLE GIA' IMPARATE, rispettate qui dentro:
--   1. NIENTE `ADD COLUMN IF NOT EXISTS` (solo MariaDB; su MySQL e' 1064 e
--      non fa niente). ALTER nude, una per riga: `#1060 - Duplicate column
--      name` = quella colonna c'e' gia', si passa oltre.
--   2. NIENTE INFORMATION_SCHEMA: su kmoney.it phpMyAdmin non lo legge.
--      Si controlla con SHOW.
--   3. Il BLOCCO 5 (riga in `migrations`) solo DOPO che il BLOCCO 4 torna.
--
-- ORDINE DI DEPLOY: PRIMA questo SQL verificato, POI il codice. Il codice
-- nuovo legge `contract_resign_from_version` a ogni richiesta di ogni utente
-- aziendale: se la colonna non c'e', il portale risponde 500 a tutti loro.
--
-- DOPO L'ESECUZIONE NON CAMBIA NIENTE PER NESSUNO: la soglia nasce a 0, che
-- significa "nessuna rifirma richiesta". Si alza solo mettendo la spunta
-- "questa revisione richiede una nuova firma" quando si salva il testo.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 1 — PRIMA DI TOCCARE NIENTE (sola lettura)
-- Atteso su un server non ancora aggiornato: tutte e due le SHOW
-- rispondono "insieme vuoto" (0 righe).
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `users` WHERE `Field` = 'contract_signed_version';
SHOW COLUMNS FROM `system_settings` WHERE `Field` IN ('contract_resign_from_version', 'contract_text_corrected_at');


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 2 — LE TRE COLONNE
--
--   users.contract_signed_version                quale versione ha firmato
--   system_settings.contract_resign_from_version da quale versione in su
--                                                serve una firma nuova (0 = nessuna)
--   system_settings.contract_text_corrected_at   quando la versione IN VIGORE
--                                                e' stata corretta (refuso):
--                                                chi l'ha firmata vede il testo
--                                                corretto, senza rifirmare
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `users` ADD COLUMN `contract_signed_version` INT UNSIGNED NULL AFTER `contract_signed_at`;
ALTER TABLE `system_settings` ADD COLUMN `contract_resign_from_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `contract_version`;
ALTER TABLE `system_settings` ADD COLUMN `contract_text_corrected_at` TIMESTAMP NULL AFTER `contract_resign_from_version`;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 3 — IL BACKFILL, CHE E' LA PARTE CHE CONTA
--
-- Senza questo, ogni firma esistente vale "versione sconosciuta" e la prima
-- volta che si mette la spunta verrebbero riportate alla firma TUTTE le
-- aziende, comprese quelle che hanno firmato ieri la versione corrente.
--
-- La versione di ogni firma c'e' in `contract_signatures`: si prende la piu'
-- alta per utente. Le firme antecedenti agli snapshot (nessuna riga in quella
-- tabella) restano a NULL e il codice le tratta come v1, che e' prudente:
-- sono le piu' vecchie di tutte.
-- ─────────────────────────────────────────────────────────────────────────

UPDATE `users` u
JOIN (
    SELECT `user_id`, MAX(`contract_version`) AS `v`
    FROM `contract_signatures`
    GROUP BY `user_id`
) s ON s.`user_id` = u.`id`
SET u.`contract_signed_version` = s.`v`;

-- Firme antecedenti agli snapshot: nessuna riga in contract_signatures.
-- Valgono 1, come fa la migrazione PHP. (Il codice tratterebbe NULL come 1
-- comunque, ma scriverlo rende leggibile il confronto fra i due server.)
UPDATE `users`
SET `contract_signed_version` = 1
WHERE `contract_signed_at` IS NOT NULL
  AND `contract_signed_version` IS NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 4 — VERIFICA (sola lettura)
--
-- Attesi:
--   a) 1 riga        b) 2 righe
--   c) soglia = 0 e contract_text_corrected_at NULL su ogni riga di
--      system_settings → nessuna rifirma in corso, nessuna correzione
--   d) la distribuzione delle versioni firmate. Le righe con
--      versione_firmata NULL sono le firme antecedenti agli snapshot: il
--      codice le conta come v1. Se sono TUTTE NULL, il BLOCCO 3 non e'
--      passato — rieseguirlo prima di andare avanti.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `users` WHERE `Field` = 'contract_signed_version';
SHOW COLUMNS FROM `system_settings` WHERE `Field` IN ('contract_resign_from_version', 'contract_text_corrected_at');

SELECT `code`, `contract_version`, `contract_resign_from_version`, `contract_text_corrected_at`
FROM `system_settings`;

SELECT `contract_signed_version` AS versione_firmata, COUNT(*) AS aziende
FROM `users`
WHERE `contract_signed_at` IS NOT NULL
  AND `company_id` IS NOT NULL
  AND `is_super_admin` = 0
GROUP BY `contract_signed_version`
ORDER BY `contract_signed_version`;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 5 — SOLO SE IL BLOCCO 4 HA DATO I RISULTATI ATTESI
-- ─────────────────────────────────────────────────────────────────────────

-- ATTENZIONE — questo INSERT non e' scritto come quelli dei deploy
-- precedenti, e la differenza e' voluta. La forma usata prima era:
--
--     SELECT '<nome>', COALESCE(MAX(`batch`),0)+1 FROM `migrations`
--     WHERE NOT EXISTS (... gia' presente ...);
--
-- Una SELECT con una funzione di aggregazione e senza GROUP BY restituisce
-- SEMPRE una riga, anche quando il WHERE non lascia passare niente: se la
-- migrazione era gia' registrata, quella forma inseriva una SECONDA riga con
-- batch 1 invece di non fare nulla. Rieseguire il blocco duplicava la riga.
-- Qui la riga da inserire arriva da una tabella derivata (una riga sola, e
-- nessun aggregato al livello esterno), quindi il NOT EXISTS la filtra
-- davvero e rieseguire non fa niente.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT `nome`, `numero` FROM (
    SELECT '2026_09_03_140000_add_contract_resign_fields' AS `nome`,
           (SELECT COALESCE(MAX(`batch`), 0) + 1
              FROM (SELECT `batch` FROM `migrations`) AS `b`) AS `numero`
) AS `riga`
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `migration` FROM `migrations`) AS m
    WHERE m.`migration` = '2026_09_03_140000_add_contract_resign_fields'
);
