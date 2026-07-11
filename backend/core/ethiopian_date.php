<?php
// DELEGATING SHIM — not a second implementation.
// The real Ethiopian calendar engine (the source of truth) lives in
// admin/backend/ethiopian_date.php. This file only re-requires it so older
// include paths keep working. Do NOT add logic here or edit it thinking it
// is the engine — make changes in admin/backend/ethiopian_date.php instead.
require_once __DIR__ . '/../../admin/backend/ethiopian_date.php';
