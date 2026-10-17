-- ============================================================
-- Family Link — READ-ONLY profiling pack (run in phpMyAdmin)
-- Purpose: real production numbers to tune GuardianMatchService
-- thresholds (AUTO 0.80 / REVIEW 0.55 / W_NAME 0.50). No writes.
-- Run each block separately; paste results back for tuning advice.
-- ============================================================

-- 1. Legacy guardian fill-rate on members
SELECT
  COUNT(*)                                                   AS members_total,
  SUM(COALESCE(guardian_name,'')  <> '')                     AS has_guardian_name,
  SUM(COALESCE(guardian_phone1,'') <> '')                    AS has_guardian_phone1,
  SUM(COALESCE(guardian_phone2,'') <> '')                    AS has_guardian_phone2,
  SUM(COALESCE(guardian_name,'')='' AND COALESCE(guardian_phone1,'')='') AS no_guardian_data
FROM members WHERE status <> 'archived';

-- 2. Phone format spread (digits-only length) — tells us how safe last-9 keys are
SELECT CHAR_LENGTH(REGEXP_REPLACE(guardian_phone1,'[^0-9]','')) AS digit_len, COUNT(*) AS n
FROM members WHERE COALESCE(guardian_phone1,'') <> ''
GROUP BY digit_len ORDER BY n DESC;

-- 3. Script mix of guardian names (Ge'ez vs Latin vs mixed)
SELECT
  CASE
    WHEN guardian_name REGEXP '^[[:ascii:]]+$' THEN 'latin_only'
    WHEN guardian_name REGEXP '[A-Za-z]' THEN 'mixed'
    ELSE 'geez_or_other'
  END AS script_class, COUNT(*) AS n
FROM members WHERE COALESCE(guardian_name,'') <> ''
GROUP BY script_class;

-- 4. Conservative cluster-size histogram by last-9 phone (what the backfill will group)
SELECT cluster_size, COUNT(*) AS clusters
FROM (
  SELECT RIGHT(REGEXP_REPLACE(guardian_phone1,'[^0-9]',''),9) AS n9, COUNT(*) AS cluster_size
  FROM members
  WHERE status <> 'archived' AND CHAR_LENGTH(REGEXP_REPLACE(guardian_phone1,'[^0-9]','')) >= 9
  GROUP BY n9
) t GROUP BY cluster_size ORDER BY cluster_size;

-- 5. Same phone but DIFFERENT typed names (spelling drift inside one family)
SELECT n9, COUNT(DISTINCT LOWER(TRIM(guardian_name))) AS name_variants, GROUP_CONCAT(DISTINCT guardian_name SEPARATOR ' | ') AS variants
FROM (
  SELECT RIGHT(REGEXP_REPLACE(guardian_phone1,'[^0-9]',''),9) AS n9, guardian_name
  FROM members WHERE status <> 'archived' AND CHAR_LENGTH(REGEXP_REPLACE(guardian_phone1,'[^0-9]','')) >= 9
) t GROUP BY n9 HAVING name_variants > 1 ORDER BY name_variants DESC LIMIT 50;

-- 6. Same name but DIFFERENT phones (possible split families OR genuine namesakes)
SELECT LOWER(TRIM(guardian_name)) AS gname, COUNT(DISTINCT RIGHT(REGEXP_REPLACE(guardian_phone1,'[^0-9]',''),9)) AS phones, COUNT(*) AS members
FROM members WHERE status <> 'archived' AND COALESCE(guardian_name,'') <> ''
GROUP BY gname HAVING phones > 1 ORDER BY phones DESC, members DESC LIMIT 50;

-- 7. After backfill/sweep: review-queue band & score distribution
SELECT band, status, ROUND(score,1) AS score_bucket, COUNT(*) AS n
FROM guardian_link_suggestions GROUP BY band, status, score_bucket ORDER BY band, status, score_bucket;

-- 8. After backfill: guardians by source + children per guardian
SELECT g.source, COUNT(*) AS guardians,
       ROUND(AVG(k.kids),2) AS avg_children, MAX(k.kids) AS max_children
FROM guardians g
LEFT JOIN (SELECT guardian_id, COUNT(*) AS kids FROM member_guardians GROUP BY guardian_id) k ON k.guardian_id = g.id
WHERE g.status = 'active' GROUP BY g.source;
