-- =====================================================================
-- DIAGNOSTICA SQUILIBRIO CIRCUITO KMoney  (SOLO LETTURA — nessuna modifica)
-- Eseguire su phpMyAdmin (DB di produzione MySQL).
-- Obiettivo: localizzare i 51.100.588 KY di delta anomalo.
-- Importi in DB = centesimi; le query mostrano già i KY (/100).
-- =====================================================================

-- 1) Conferma del delta totale del circuito (deve essere 0; ora ~51.100.588)
SELECT ROUND(SUM(available_balance)/100, 2) AS delta_circuito_ky
FROM accounts;

-- 2) Dove sta il saldo: ripartizione per tipo conto e sistema/non-sistema
SELECT type,
       is_system_account,
       COUNT(*)                              AS conti,
       ROUND(SUM(available_balance)/100, 2)  AS totale_ky
FROM accounts
GROUP BY type, is_system_account
ORDER BY SUM(available_balance) DESC;

-- 3) Cassa Circuito (sistema) + conti riserva MAIN
SELECT id, account_number, account_name, type, is_system_account,
       ROUND(available_balance/100, 2) AS saldo_ky
FROM accounts
WHERE is_system_account = 1 OR type = 'main'
ORDER BY available_balance;

-- 4) Top 30 conti per saldo (dove si concentra il circolante)
SELECT id, account_number, account_name, type,
       ROUND(available_balance/100, 2) AS saldo_ky
FROM accounts
WHERE is_system_account = 0
ORDER BY available_balance DESC
LIMIT 30;

-- 5) CUORE DELLA DIAGNOSI:
--    quanto dei saldi membri è spiegato dai trasferimenti tracciati
--    e quanto è "saldo iniziale impostato direttamente dall'import".
SELECT
  ROUND(SUM(a.available_balance)/100, 2)            AS saldi_membri_ky,
  ROUND(SUM(t.calc)/100, 2)                         AS spiegato_da_trasferimenti_ky,
  ROUND(SUM(a.available_balance - t.calc)/100, 2)   AS iniettato_dall_import_ky
FROM accounts a
JOIN (
  SELECT acc.id AS id,
    COALESCE((SELECT SUM(amount) FROM transfers
              WHERE to_account_id = acc.id   AND status IN ('booked','completed')), 0)
  - COALESCE((SELECT SUM(amount) FROM transfers
              WHERE from_account_id = acc.id AND status IN ('booked','completed')), 0) AS calc
  FROM accounts acc
) t ON t.id = a.id
WHERE a.is_system_account = 0;

-- 6) Sanity check unità: massimo saldo singolo (per scoprire eventuali
--    importi gonfiati x100 o duplicati durante l'import)
SELECT ROUND(MAX(available_balance)/100, 2) AS max_saldo_ky,
       ROUND(AVG(available_balance)/100, 2) AS media_saldo_ky
FROM accounts WHERE is_system_account = 0 AND available_balance > 0;
