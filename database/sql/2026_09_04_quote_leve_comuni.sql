-- ============================================================================
-- LE DUE LEVE DIVENTANO DI TUTTE E TRE LE QUOTE
-- 04/09/2026 · da eseguire su ENTRAMBI i server (kmoney = MariaDB, kosmopay = MySQL)
--
-- Richiesta di Laura: una pagina sola per le tre quote, e per ciascuna
-- l'interruttore, l'importo, i metodi di pagamento, il fido a chi paga in KY e
-- la restituzione in KY a chi paga in euro, con l'importo deciso da lei.
--
-- Le due leve esistevano gia' per la sola quota di apertura conto delle
-- aziende (2026_09_03_company_account_fee.sql). Questo file porta le OTTO
-- colonne mancanti delle altre due quote — quattro su `system_settings`,
-- quattro su `users` — e aggancia la restituzione dei privati all'importo che
-- la quota ha davvero oggi.
--
-- DIPENDENZA: nessuna. Si puo' eseguire prima o dopo il file del 03/09; le
-- colonne di quello non vengono toccate.
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
-- ============================================================================
-- LA RIGA CHE CONTA E' L'UPDATE DEL BLOCCO 3, e va capita prima di eseguire.
--
-- Fino a oggi il privato che pagava la quota in EURO riceveva SEMPRE tanti KY
-- quanti ne aveva pagati: in euro la quota di iscrizione non e' un costo, e'
-- un acquisto di KY, e il numero stava cablato nel codice. Da adesso e' una
-- impostazione. Se restasse a zero, il primo privato che paga dopo il
-- rilascio verserebbe 30 euro senza ricevere niente.
--
-- L'UPDATE la mette pari all'importo che la quota ha DAVVERO su questo
-- server, non ai 30,00 del default. Da li' in poi i due numeri sono
-- indipendenti: alzando la quota a 50,00 la restituzione resta a 30,00
-- finche' non la si cambia anche lei, dalla pagina /admin/quote.
--
-- Gli AGENTI partono da zero, ed e' il comportamento di oggi: i 480 sono il
-- prezzo della nomina, KNM incassa e il conto dell'agente non viene toccato.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 1 — PRIMA DI TOCCARE NIENTE (sola lettura)
--
-- Attesi su un server non ancora aggiornato: nessuna colonna che finisca per
-- `_ky_credit_cents` o `_ky_allowance` fra quelle dei privati e degli agenti
-- (quelle dell'apertura conto ci sono se il file del 03/09 e' gia' passato).
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE '%\_fee\_ky\_%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE '%\_fee\_ky\_%override%';

-- L'importo che la quota privati ha davvero oggi: e' il numero che l'UPDATE
-- del BLOCCO 3 copiera' nella restituzione. Va guardato PRIMA.
SELECT `code`, `registration_fee_enabled`, `registration_fee_amount_cents`,
       `agent_code_fee_enabled`, `agent_code_fee_amount_cents`
FROM `system_settings` WHERE `code` = 'user_limit_defaults';


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 2 — COLONNE, UNA ALLA VOLTA
--
-- I quattro ripieghi su `users` nascono NULL per tutti, e NULL non e' zero:
-- vuol dire «segui il pannello». Scrivere 0 o «no» e' una decisione presa per
-- quella persona, e resta ferma anche se domani il default cambia. NON fare
-- UPDATE su queste quattro colonne.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `system_settings` ADD COLUMN `registration_fee_ky_credit_cents` INT UNSIGNED NOT NULL DEFAULT 3000;
ALTER TABLE `system_settings` ADD COLUMN `registration_fee_ky_allowance` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_ky_credit_cents` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `system_settings` ADD COLUMN `agent_code_fee_ky_allowance` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `users` ADD COLUMN `registration_fee_ky_credit_override_cents` INT UNSIGNED NULL;
ALTER TABLE `users` ADD COLUMN `registration_fee_ky_allowance_override` TINYINT(1) NULL;
ALTER TABLE `users` ADD COLUMN `agent_code_fee_ky_credit_override_cents` INT UNSIGNED NULL;
ALTER TABLE `users` ADD COLUMN `agent_code_fee_ky_allowance_override` TINYINT(1) NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 3 — LA RESTITUZIONE DEI PRIVATI AGGANCIATA ALL'IMPORTO VERO
--
-- Da eseguire UNA VOLTA SOLA, subito dopo il BLOCCO 2. La condizione
-- `= 3000` fa si' che rieseguire il file non sovrascriva una cifra che
-- l'admin ha nel frattempo deciso a mano — a meno che quella cifra non sia
-- proprio 30,00, e in quel caso riscriverla non cambia niente.
--
-- Se il BLOCCO 1 ha mostrato registration_fee_amount_cents = 3000, questo
-- UPDATE non sposta nulla ed e' giusto cosi'.
-- ─────────────────────────────────────────────────────────────────────────

UPDATE `system_settings`
SET `registration_fee_ky_credit_cents` = `registration_fee_amount_cents`
WHERE `code` = 'user_limit_defaults'
  AND `registration_fee_ky_credit_cents` = 3000;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 4 — VERIFICA (sola lettura)
--
-- Attesi: 6 righe dalla prima (2 privati + 2 agenti + 2 apertura conto, se il
-- file del 03/09 e' passato; 4 se non ancora), 6 righe dalla seconda (4 nuove
-- + 2 dell'apertura conto), e nella terza
-- registration_fee_ky_credit_cents UGUALE a registration_fee_amount_cents,
-- agent_code_fee_ky_credit_cents = 0, i tre `_ky_allowance` = 1.
--
-- L'ultima deve dare ZERO: nessun utente ha un trattamento suo, e non deve
-- averlo — i ripieghi si scrivono uno alla volta dalla scheda dell'utente.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE '%\_fee\_ky\_%';
SHOW COLUMNS FROM `users` WHERE `Field` LIKE '%\_fee\_ky\_%override%';

SELECT `code`,
       `registration_fee_amount_cents`, `registration_fee_ky_credit_cents`, `registration_fee_ky_allowance`,
       `agent_code_fee_amount_cents`,   `agent_code_fee_ky_credit_cents`,   `agent_code_fee_ky_allowance`
FROM `system_settings` WHERE `code` = 'user_limit_defaults';

SELECT COUNT(*) AS utenti_con_trattamento_proprio
FROM `users`
WHERE `registration_fee_ky_credit_override_cents` IS NOT NULL
   OR `registration_fee_ky_allowance_override` IS NOT NULL
   OR `agent_code_fee_ky_credit_override_cents` IS NOT NULL
   OR `agent_code_fee_ky_allowance_override` IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 5 — SOLO SE IL BLOCCO 4 E' TORNATO COME DEVE
-- ─────────────────────────────────────────────────────────────────────────

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_04_120000_add_fee_levers_to_all_fees', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `migration` FROM `migrations`) AS m
    WHERE m.`migration` = '2026_09_04_120000_add_fee_levers_to_all_fees'
);


-- ─────────────────────────────────────────────────────────────────────────
-- UTILE DOPO — chi ha un trattamento diverso dal pannello, e quale
-- ─────────────────────────────────────────────────────────────────────────
-- SELECT id, name, email,
--        registration_fee_ky_credit_override_cents  AS priv_ky,
--        registration_fee_ky_allowance_override     AS priv_fido,
--        agent_code_fee_ky_credit_override_cents    AS agente_ky,
--        agent_code_fee_ky_allowance_override       AS agente_fido,
--        company_account_fee_ky_credit_override_cents AS azienda_ky,
--        company_account_fee_ky_allowance_override    AS azienda_fido
-- FROM `users`
-- WHERE registration_fee_ky_credit_override_cents IS NOT NULL
--    OR registration_fee_ky_allowance_override IS NOT NULL
--    OR agent_code_fee_ky_credit_override_cents IS NOT NULL
--    OR agent_code_fee_ky_allowance_override IS NOT NULL
--    OR company_account_fee_ky_credit_override_cents IS NOT NULL
--    OR company_account_fee_ky_allowance_override IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- ANNULLAMENTO, se mai servisse
--
-- Attenzione: togliendo `registration_fee_ky_credit_cents` si perde la cifra
-- della restituzione dei privati. Il codice vecchio la ricalcolava da solo
-- come pari all'importo, quindi tornare indietro e' sicuro solo se nel
-- frattempo quella cifra non e' stata cambiata a mano.
-- ─────────────────────────────────────────────────────────────────────────
-- ALTER TABLE `users` DROP COLUMN `registration_fee_ky_credit_override_cents`;
-- ALTER TABLE `users` DROP COLUMN `registration_fee_ky_allowance_override`;
-- ALTER TABLE `users` DROP COLUMN `agent_code_fee_ky_credit_override_cents`;
-- ALTER TABLE `users` DROP COLUMN `agent_code_fee_ky_allowance_override`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_ky_credit_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `registration_fee_ky_allowance`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_ky_credit_cents`;
-- ALTER TABLE `system_settings` DROP COLUMN `agent_code_fee_ky_allowance`;
-- DELETE FROM `migrations` WHERE `migration` = '2026_09_04_120000_add_fee_levers_to_all_fees';
