-- 035: per-category cover gradient (Patch 32) — for categories and
-- sub-categories WITHOUT an uploaded image, admins can pin the two
-- gradient colors instead of accepting the automatic name-hashed
-- palette. Purely additive: absent columns degrade to the automatic
-- palette everywhere (server probes before use), so deployments can
-- apply this at any time.
--
-- Validation lives in the service (strict #rrggbb hex or NULL); the
-- columns are CHAR(7) NULL and both ends are optional.

ALTER TABLE mezmur_categories ADD COLUMN IF NOT EXISTS gradient_start CHAR(7) NULL DEFAULT NULL AFTER image_path;
ALTER TABLE mezmur_categories ADD COLUMN IF NOT EXISTS gradient_end CHAR(7) NULL DEFAULT NULL AFTER gradient_start;
