-- ============================================================================
-- A5 — una ricarica = una riga di punti e una di base commissionabile
-- 31/08/2026 · da eseguire su ENTRAMBI i server (kmoney = MariaDB, kosmopay = MySQL)
--
-- Perche': dal 28/08 il webhook Stripe funziona (prima rispondeva 419 per il
-- CSRF), quindi l'accredito di una ricarica puo' partire da DUE strade
-- simultanee — webhook e pagina di successo. I KY erano gia' protetti dalla
-- idempotency_key del transfer; i punti MLM e la base commissionabile no.
-- La riga doppia in mlm_commission_base_ledger viene pagata in EURO VERI dal
-- run di MlmCommissionEngine il 1° del mese.
--
-- ORDINE OBBLIGATORIO: prima il BLOCCO 1 (sola lettura). Se restituisce
-- ZERO righe si passa al BLOCCO 3. Se restituisce righe, NON eseguire il
-- BLOCCO 3 (fallirebbe con errore 1062): fermarsi e guardare il BLOCCO 2.
--
-- Nessun IF NOT EXISTS: e' sintassi solo MariaDB, su MySQL da errore 1064 e
-- non fa niente (nota del 27/08 sui due database diversi).
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 1 — CONTROLLO DUPLICATI (sola lettura, non modifica niente)
-- Atteso: "Empty set" su tutte e due le query.
-- ─────────────────────────────────────────────────────────────────────────

SELECT source_type, source_transfer_id, COUNT(*) AS righe, MIN(id) AS id_da_tenere, MAX(id) AS id_di_troppo
FROM mlm_point_ledger
WHERE source_transfer_id IS NOT NULL
GROUP BY source_type, source_transfer_id
HAVING COUNT(*) > 1;

SELECT source_transfer_id, COUNT(*) AS righe, MIN(id) AS id_da_tenere, MAX(id) AS id_di_troppo,
       SUM(monthly_amount_eur_cents) AS totale_cent_in_gioco
FROM mlm_commission_base_ledger
WHERE source_transfer_id IS NOT NULL
GROUP BY source_transfer_id
HAVING COUNT(*) > 1;


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 2 — SOLO SE IL BLOCCO 1 HA RESTITUITO RIGHE
-- Non cancellare niente d'istinto: una riga di base commissionabile puo'
-- essere GIA' STATA PAGATA da un run mensile, e in quel caso il problema non
-- e' piu' il ledger ma la commissione gia' liquidata.
-- Prima guardare COSA sono, con questa (sola lettura):
-- ─────────────────────────────────────────────────────────────────────────

-- SELECT b.*, u.name AS cliente, a.name AS agente
-- FROM mlm_commission_base_ledger b
-- JOIN users u ON u.id = b.client_user_id
-- JOIN users a ON a.id = b.direct_agent_id
-- WHERE b.source_transfer_id IN (
--     SELECT source_transfer_id FROM (
--         SELECT source_transfer_id FROM mlm_commission_base_ledger
--         WHERE source_transfer_id IS NOT NULL
--         GROUP BY source_transfer_id HAVING COUNT(*) > 1
--     ) AS d
-- )
-- ORDER BY b.source_transfer_id, b.id;

-- Ripulitura (tiene la riga piu' vecchia di ogni gruppo) — DA ESEGUIRE SOLO
-- dopo aver deciso caso per caso. Il SELECT annidato in FROM serve perche'
-- MySQL non accetta di leggere la stessa tabella che sta cancellando.
--
-- DELETE FROM mlm_point_ledger
-- WHERE source_transfer_id IS NOT NULL
--   AND id NOT IN (
--     SELECT id_da_tenere FROM (
--         SELECT MIN(id) AS id_da_tenere FROM mlm_point_ledger
--         WHERE source_transfer_id IS NOT NULL
--         GROUP BY source_type, source_transfer_id
--     ) AS k
--   );
--
-- DELETE FROM mlm_commission_base_ledger
-- WHERE source_transfer_id IS NOT NULL
--   AND id NOT IN (
--     SELECT id_da_tenere FROM (
--         SELECT MIN(id) AS id_da_tenere FROM mlm_commission_base_ledger
--         WHERE source_transfer_id IS NOT NULL
--         GROUP BY source_transfer_id
--     ) AS k
--   );


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 3 — GLI INDICI (una riga per volta)
-- ADD UNIQUE INDEX e' un'operazione ONLINE su InnoDB (ALGORITHM=INPLACE):
-- il portale continua a rispondere e le due tabelle sono piccole. Se una
-- delle due fallisce con 1062 significa che il BLOCCO 1 non e' stato fatto:
-- la tabella resta com'era, nessun danno.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE mlm_point_ledger
    ADD UNIQUE INDEX mlm_point_ledger_source_unique (source_type, source_transfer_id);

ALTER TABLE mlm_commission_base_ledger
    ADD UNIQUE INDEX mlm_commission_base_ledger_source_unique (source_transfer_id);


-- ─────────────────────────────────────────────────────────────────────────
-- BLOCCO 4 — VERIFICA (sola lettura)
-- Attesi: Non_unique = 0 per i due nomi qui sotto.
-- ─────────────────────────────────────────────────────────────────────────

SHOW INDEX FROM mlm_point_ledger;
SHOW INDEX FROM mlm_commission_base_ledger;


-- ─────────────────────────────────────────────────────────────────────────
-- ANNULLAMENTO, se mai servisse
-- ─────────────────────────────────────────────────────────────────────────
-- ALTER TABLE mlm_point_ledger DROP INDEX mlm_point_ledger_source_unique;
-- ALTER TABLE mlm_commission_base_ledger DROP INDEX mlm_commission_base_ledger_source_unique;

-- NOTA: le righe con source_transfer_id NULL (registrazione, simulatore)
-- restano libere di ripetersi: in MySQL come in MariaDB un indice UNIQUE
-- ammette piu' NULL. E' voluto — senza transfer non c'e' una sorgente da
-- identificare.
