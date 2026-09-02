-- 036: gradient alpha channel (Patch 33) — the cover-color picker gains
-- opacity control, so colors may be #rrggbb (fully opaque) or #rrggbbaa
-- (partial transparency). Widens the P32 columns; existing 6-digit
-- values are untouched. Validation lives in the service (strict hex,
-- 6 or 8 digits).

ALTER TABLE mezmur_categories MODIFY COLUMN gradient_start CHAR(9) NULL DEFAULT NULL;
ALTER TABLE mezmur_categories MODIFY COLUMN gradient_end CHAR(9) NULL DEFAULT NULL;
