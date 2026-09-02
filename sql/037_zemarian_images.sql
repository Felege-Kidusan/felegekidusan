-- 037: singer (zemarian) cover images (Patch 34) — singers get the
-- same cover-image capability as categories: upload through the
-- hardened validator, random name, re-encoded, no-execute dir (they
-- share the uploads/mezmur_categories storage dir, which is already
-- locked down; only the DB column is new). Absent column = old code
-- keeps working (service probes before using).

ALTER TABLE mezmur_zemarians ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL DEFAULT NULL AFTER name_am;
