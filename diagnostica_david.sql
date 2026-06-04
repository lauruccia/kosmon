-- ==================================================================
-- DIAGNOSTICA David (david@ksm.it) — solo SELECT, nessuna modifica
-- Scopo: capire perché gli UPDATE non agganciano il conto a -8004000.
-- Esegui in phpMyAdmin (tab SQL) e incolla a Claude i risultati.
-- ==================================================================

-- 1) L'utente: id, ruolo, owner_type, eventuale conto gestito
SELECT 'USER' AS fonte, id, name, email, role, account_holder_type,
       company_id, managed_account_id
FROM users
WHERE email = 'david@ksm.it';

-- 2) Conti agganciati a David come OWNER (owner_user_id)
SELECT 'ACCOUNT by owner_user_id' AS fonte, a.id, a.type, a.owner_type,
       a.is_system_account, a.company_id, a.owner_user_id,
       a.parent_account_id, a.status, a.available_balance
FROM accounts a
JOIN users u ON u.id = a.owner_user_id
WHERE u.email = 'david@ksm.it';

-- 3) QUALSIASI conto con saldo -8004000 (il valore gonfiato ×100 mostrato a video)
SELECT 'ACCOUNT saldo -8004000' AS fonte, a.id, a.type, a.owner_type,
       a.is_system_account, a.company_id, a.owner_user_id,
       a.parent_account_id, a.status, a.available_balance,
       u.email AS owner_email
FROM accounts a
LEFT JOIN users u ON u.id = a.owner_user_id
WHERE a.available_balance = -8004000;

-- 4) Conto eventualmente collegato via company di David (vecchia chiave dello script rotto)
SELECT 'ACCOUNT by company email' AS fonte, a.id, a.type, a.owner_type,
       a.is_system_account, a.company_id, a.owner_user_id,
       a.status, a.available_balance
FROM accounts a
WHERE a.company_id = (SELECT id FROM companies WHERE email = 'david@ksm.it' LIMIT 1);

-- 5) Conto gestito da David come operatore (managed_account_id)
SELECT 'ACCOUNT gestito (managed)' AS fonte, a.id, a.type, a.owner_type,
       a.is_system_account, a.company_id, a.owner_user_id,
       a.status, a.available_balance
FROM accounts a
WHERE a.id = (SELECT managed_account_id FROM users WHERE email = 'david@ksm.it' LIMIT 1);
