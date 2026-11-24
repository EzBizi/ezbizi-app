-- Desk 11-24: Monday sync writes logged as Jose's clicks, relabelled "Monday sync".
-- Rule: a request mints its own correlation_id (the client never sends one), so
-- Jose's clicks are short groups (span <= 30 s) holding only his rows. Any of his
-- task rows whose correlation_id spans longer, or also carries another actor's
-- rows, came from a long-running process (sync, queue worker, script).
-- Measured 11-24: 357 groups / 296,701 rows relabel; 280 groups / 2,653 rows stay his.
-- Dump first:  mysqldump ezbizi audit_events --where="id IN (SELECT id FROM tmp_monday_sync_rows)"

CREATE TABLE tmp_monday_sync_rows AS
SELECT e.id
FROM audit_events e
JOIN (
    SELECT correlation_id
    FROM audit_events
    WHERE correlation_id IN (
        SELECT DISTINCT correlation_id FROM audit_events
        WHERE actor_id = '019fd712-75d3-711e-86f1-596d39292c51'
          AND action IN ('task.values_changed', 'task.created', 'task.updated')
          AND occurred_at >= '2026-11-10'
    )
    GROUP BY correlation_id
    HAVING TIMESTAMPDIFF(SECOND, MIN(occurred_at), MAX(occurred_at)) > 30
        OR SUM(actor_type <> 'user' OR actor_id <> '019fd712-75d3-711e-86f1-596d39292c51') > 0
) m ON m.correlation_id = e.correlation_id
WHERE e.actor_id = '019fd712-75d3-711e-86f1-596d39292c51'
  AND e.action IN ('task.values_changed', 'task.created', 'task.updated')
  AND e.occurred_at >= '2026-11-10';

SELECT COUNT(*) FROM tmp_monday_sync_rows; -- expect ~296,701

UPDATE audit_events e JOIN tmp_monday_sync_rows t ON t.id = e.id
SET e.actor_type = 'system', e.actor_id = NULL, e.actor_label = 'Monday sync';

DROP TABLE tmp_monday_sync_rows;
