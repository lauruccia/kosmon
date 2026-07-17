-- ============================================================================
-- 2026_07_17_120000_add_accepted_ky_percentage_to_companies
--
-- % Kmoney accettata, dichiarata dall'azienda nel profilo (0/25/50/75/100).
-- Mostrata come badge sulla card della directory /aziende; sulla card viene
-- scelta in automatico la % migliore tra quella dichiarata e la migliore %
-- (25-100) dei prodotti attivi. Conto sottozero = sempre 100%, non modificabile.
--
-- ⚠️ ESEGUIRE DOPO il deploy del codice da cPanel (prima codice, poi SQL).
--
-- Verifica preliminare: se la query
--   SELECT migration FROM migrations
--   WHERE migration = '2026_07_17_120000_add_accepted_ky_percentage_to_companies';
-- restituisce gia' una riga, NON eseguire questo file (colonna gia' presente).
-- ============================================================================

ALTER TABLE `companies`
  ADD COLUMN `accepted_ky_percentage` TINYINT UNSIGNED NULL AFTER `subscription_plan`;

INSERT INTO migrations (migration, batch)
SELECT '2026_07_17_120000_add_accepted_ky_percentage_to_companies', COALESCE(MAX(batch),0)+1
FROM migrations AS m
HAVING NOT EXISTS (SELECT 1 FROM migrations AS m2 WHERE m2.migration = '2026_07_17_120000_add_accepted_ky_percentage_to_companies');
