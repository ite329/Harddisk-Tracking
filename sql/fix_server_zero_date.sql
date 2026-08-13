-- Optional one-time cleanup for legacy zero dates in Data.server.
-- Run only after confirming the expiry-date column is `s_day`.
-- This converts legacy 0000-00-00 values to real SQL NULL.

UPDATE `server`
SET `s_day` = NULL
WHERE CAST(`s_day` AS CHAR) = '0000-00-00';
