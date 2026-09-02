-- 037: singer (zemarian) cover images (Patch 34) — singers get the
-- same cover-image capability as categories: upload through the
-- hardened validator, random name, re-encoded, no-execute dir.
-- Singers store in their OWN directory (uploads/mezmur_zemarians/,
-- with its own no-exec .htaccess, created on first upload) and keep
-- a distinct audit entity. Absent column = old code keeps working
-- (the service probes before using).

ALTER TABLE mezmur_zemarians ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL DEFAULT NULL AFTER name_am;
