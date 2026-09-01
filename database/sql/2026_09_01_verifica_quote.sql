-- ============================================================================
-- VERIFICA DELLE DUE QUOTE — 01/09/2026
-- SOLA LETTURA. Non modifica NIENTE: si puo' lanciare su produzione a occhi
-- chiusi, anche a circuito acceso, anche due volte.
--
-- A cosa serve: dire se i due SQL del 31/08 sono stati eseguiti davvero su
-- QUESTO server, e in che stato sono le quote adesso.
--   · 2026_08_31_registration_fee.sql   (quota privati, 30)
--   · 2026_08_31_agent_code_fee.sql     (quota codice agente, 480)
--
-- DA LANCIARE SU ENTRAMBI I SERVER, uno alla volta: kmoney (MariaDB) e
-- kosmopay (MySQL) hanno database diversi e uno puo' essere aggiornato e
-- l'altro no. E' gia' successo.
--
-- NIENTE INFORMATION_SCHEMA: su kmoney.it l'utente non lo puo' leggere e
-- ogni controllo tornerebbe "vuoto" anche a colonna esistente. Qui si usano
-- solo SHOW e SELECT.
--
-- COME SI LEGGE: ogni query ha sopra il risultato ATTESO. Se una risposta non
-- corrisponde, il blocco corrispondente del file del 31/08 non e' passato su
-- questo server — si rilancia SOLO quel pezzo, le ALTER sono nude e un
-- `#1060 - Duplicate column name` vuol dire "c'e' gia'", si salta e si va
-- avanti.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1 · QUOTA PRIVATI — le colonne e la tabella
-- Attesi: 6 righe · 3 righe · 1 riga.
-- Zero righe da qualsiasi delle tre = 2026_08_31_registration_fee.sql NON
-- eseguito qui.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'registration_fee%';
SHOW COLUMNS FROM `users`           WHERE `Field` LIKE 'registration_fee%';
SHOW TABLES LIKE 'registration_fee_payments';


-- ─────────────────────────────────────────────────────────────────────────
-- 2 · QUOTA CODICE AGENTE — le colonne e la tabella
-- Attesi: 6 righe · 3 righe · 1 riga.
-- ─────────────────────────────────────────────────────────────────────────

SHOW COLUMNS FROM `system_settings` WHERE `Field` LIKE 'agent_code_fee%';
SHOW COLUMNS FROM `users`           WHERE `Field` LIKE 'agent_code_fee%';
SHOW TABLES LIKE 'agent_code_fee_payments';


-- ─────────────────────────────────────────────────────────────────────────
-- 3 · LA TABELLA DEI PAGAMENTI AGENTE, DENTRO
-- Attese: colonna `uuid` unica, indice su (user_id, status), indice su
-- `status`, chiave esterna su users. Se la tabella e' stata creata a mano
-- senza il CREATE TABLE del file, qui si vede.
-- ─────────────────────────────────────────────────────────────────────────

SHOW INDEX FROM `agent_code_fee_payments`;


-- ─────────────────────────────────────────────────────────────────────────
-- 4 · LE RIGHE IN `migrations`
-- Attese: 2 righe (una per quota). Se mancano, lo schema puo' essere giusto
-- lo stesso, ma il prossimo `php artisan migrate` riproverebbe a crearlo e si
-- fermerebbe su "table already exists". E' il BLOCCO 5 dei due file.
-- ─────────────────────────────────────────────────────────────────────────

SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` IN (
    '2026_08_31_140000_create_registration_fee',
    '2026_08_31_150000_create_agent_code_fee'
)
ORDER BY `migration`;


-- ─────────────────────────────────────────────────────────────────────────
-- 5 · LE QUOTE SONO ACCESE O SPENTE, E A QUANTO
-- Nascono SPENTE (enabled = 0) e si accendono dal backoffice:
--   /admin/quote-iscrizione        (privati)
--   /admin/quote-codice-agente     (codice agente)
-- Gli importi sono in CENTESIMI: 3000 = 30 euro, 48000 = 480 euro.
-- ─────────────────────────────────────────────────────────────────────────

SELECT
    `code`,
    `registration_fee_enabled`        AS privati_accesa,
    `registration_fee_amount_cents`   AS privati_importo,
    `registration_fee_ky_enabled`     AS privati_ky,
    `agent_code_fee_enabled`          AS agente_accesa,
    `agent_code_fee_amount_cents`     AS agente_importo,
    `agent_code_fee_ky_enabled`       AS agente_ky
FROM `system_settings`
WHERE `code` = 'user_limit_defaults';


-- ─────────────────────────────────────────────────────────────────────────
-- 6 · CHI DEVE E CHI HA PAGATO — quota codice agente
-- A quota mai accesa: tutti zero. E' il risultato giusto prima del lancio.
--
-- NB sui tre significati della colonna `agent_code_fee_due_cents`:
--   NULL      = non deve niente e non dovra' mai (tutti gli agenti di prima)
--   0         = ESONERATO dall'admin (01/09/2026)
--   > 0       = la deve, di quella cifra
-- ─────────────────────────────────────────────────────────────────────────

SELECT
    COUNT(*)                                                                   AS utenti_toccati_dalla_quota,
    SUM(CASE WHEN `agent_code_fee_due_cents` > 0
              AND `agent_code_fee_paid_at` IS NULL THEN 1 ELSE 0 END)          AS da_pagare,
    SUM(CASE WHEN `agent_code_fee_paid_at` IS NOT NULL THEN 1 ELSE 0 END)      AS pagate,
    SUM(CASE WHEN `agent_code_fee_due_cents` = 0
              AND `agent_code_fee_paid_at` IS NULL THEN 1 ELSE 0 END)          AS esonerati,
    COALESCE(SUM(CASE WHEN `agent_code_fee_due_cents` > 0
                       AND `agent_code_fee_paid_at` IS NULL
                      THEN `agent_code_fee_due_cents` ELSE 0 END), 0) / 100    AS totale_da_incassare_eur,
    COALESCE(SUM(`agent_code_fee_ky_allowance_cents`), 0) / 100                AS fidi_aggiuntivi_in_uso_ky
FROM `users`
WHERE `agent_code_fee_due_cents` IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- 7 · LO STESSO PER LA QUOTA PRIVATI
-- Qui lo 0 vuol dire un'altra cosa: SOSPESA (e' entrato dal portale di un
-- agente e paga i 480, non i 30 — finche' resta sul percorso agente).
-- ATTENZIONE, LO ZERO NON SIGNIFICA LA STESSA COSA NELLE DUE QUOTE.
-- ─────────────────────────────────────────────────────────────────────────

SELECT
    COUNT(*)                                                                   AS utenti_toccati_dalla_quota,
    SUM(CASE WHEN `registration_fee_due_cents` > 0
              AND `registration_fee_paid_at` IS NULL THEN 1 ELSE 0 END)        AS da_pagare,
    SUM(CASE WHEN `registration_fee_paid_at` IS NOT NULL THEN 1 ELSE 0 END)    AS pagate,
    SUM(CASE WHEN `registration_fee_due_cents` = 0
              AND `registration_fee_paid_at` IS NULL THEN 1 ELSE 0 END)        AS sospese_percorso_agente
FROM `users`
WHERE `registration_fee_due_cents` IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- 8 · I TENTATIVI DI PAGAMENTO, PER STATO
-- A tabella vuota non torna nessuna riga: giusto prima del lancio.
-- `failed` in euro = soldi forse incassati e quota non saldata: quelle righe
-- hanno il bottone «Verifica e accredita» nel backoffice.
-- ─────────────────────────────────────────────────────────────────────────

SELECT `status`, `payment_method`, COUNT(*) AS righe,
       MIN(`created_at`) AS dal, MAX(`created_at`) AS al
FROM `agent_code_fee_payments`
GROUP BY `status`, `payment_method`
ORDER BY `status`, `payment_method`;

SELECT `status`, `payment_method`, COUNT(*) AS righe,
       MIN(`created_at`) AS dal, MAX(`created_at`) AS al
FROM `registration_fee_payments`
GROUP BY `status`, `payment_method`
ORDER BY `status`, `payment_method`;


-- ─────────────────────────────────────────────────────────────────────────
-- 9 · LE INCOERENZE CHE VOGLIAMO NON VEDERE
-- Tutte e tre devono tornare ZERO righe.
-- ─────────────────────────────────────────────────────────────────────────

-- 9a · Quota agente segnata pagata, ma nessuna riga di pagamento completata
--      dietro. Se ne esce qualcuna: o e' stata saldata a mano sul database,
--      o un annullamento ha lasciato le cose a meta'.
SELECT u.`id`, u.`name`, u.`email`, u.`agent_code_fee_paid_at`
FROM `users` u
WHERE u.`agent_code_fee_paid_at` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `agent_code_fee_payments` p
      WHERE p.`user_id` = u.`id` AND p.`status` = 'completed'
  );

-- 9b · Fido aggiuntivo acceso senza nessuna quota pagata dietro: sono KY di
--      scoperto che nessuna quota giustifica piu' (e' il bug che aveva
--      lasciato la cancellazione del movimento dal backoffice).
SELECT u.`id`, u.`name`, u.`email`,
       u.`agent_code_fee_ky_allowance_cents` / 100    AS fido_agente_ky,
       u.`registration_fee_ky_allowance_cents` / 100  AS fido_privati_ky
FROM `users` u
WHERE (u.`agent_code_fee_ky_allowance_cents`   > 0 AND u.`agent_code_fee_paid_at`   IS NULL)
   OR (u.`registration_fee_ky_allowance_cents` > 0 AND u.`registration_fee_paid_at` IS NULL);

-- 9c · Gia' agente (ha firmato) ma con la quota del codice ancora da pagare:
--      vuol dire che qualcuno e' passato dalla firma senza pagare.
SELECT u.`id`, u.`name`, u.`email`, u.`agent_code_fee_due_cents` / 100 AS dovuti_eur
FROM `users` u
WHERE u.`mlm_role` = 'agente'
  AND u.`agent_code_fee_due_cents` > 0
  AND u.`agent_code_fee_paid_at` IS NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- 10 · L'ELENCO CON NOME E COGNOME, quando serve sapere CHI
-- ─────────────────────────────────────────────────────────────────────────

SELECT u.`id`, u.`name`, u.`email`,
       u.`agent_code_fee_due_cents` / 100 AS dovuti_eur,
       u.`agent_code_fee_paid_at`,
       u.`mlm_agent_request_status`, u.`mlm_agent_reviewed_at`
FROM `users` u
WHERE u.`agent_code_fee_due_cents` IS NOT NULL
ORDER BY u.`agent_code_fee_paid_at` IS NULL DESC, u.`mlm_agent_reviewed_at`;
