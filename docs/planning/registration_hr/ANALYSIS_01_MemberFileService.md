# DEEP ANALYSIS — File 1 of N: `admin/backend/services/MemberFileService.php`

**Scope:** Upload validation, storage, resolution, serving-support, and discard for member photos/documents.
**Mode:** ANALYSIS ONLY — no code modified.
**Protocol:** Universal AI Software Change/Patch/Update Protocol v1.0 + Master Codebase Understanding Protocol.

---

## 1. FILE IDENTITY

- Path: `admin/backend/services/MemberFileService.php`
- Lines: 212
- Namespace: `App\Services`
- Class: `final class MemberFileService`
- Consumers (verified): `admin/hr_register_member.php` (storeRequestUpload for 5 fields, discard on error/rollback), `admin/info_manage_member.php` (POST update path), `admin/member_file.php` (resolvePrivatePath, mimeForPath for serving), `admin/backend/services/MemberDirectoryService.php`? (no, only via controller).

## 2. CONSTANTS & CONFIGURATION

| Line | Symbol | Value | Notes |
|---|---|---|---|
| 14 | MAX_BYTES | 5242880 (5 MB) | Applied to all fields uniformly. |
| 15 | PRIVATE_PREFIX | `private://members/` | URI scheme for private files. |
| 17-24 | IMAGE_MIMES | jpeg/png/gif/webp/bmp (+x-ms-bmp) | Allow-list. No SVG (correct — SVG can carry JS). |
| 26-32 | FIELDS | 5 fields with category/private/pdf flags | student_photo is public; rest private. |

## 3. EXECUTION PATH — storeRequestUpload()

1. Field allow-list check (line 39) — rejects unknown fields.
2. No-file check (line 42) — optional upload returns success with null path.
3. PHP upload error check (line 48).
4. `is_uploaded_file()` (line 53) — prevents direct path injection.
5. Size check 1..5MB (line 57).
6. MIME detection via finfo (line 62).
7. Image branch: extension from MIME allow-list + `getimagesize()` structural validation (lines 64-67).
8. PDF branch: MIME == application/pdf + 5-byte header `%PDF-` (lines 68-69).
9. Otherwise reject (line 71).
10. Random 32-hex filename (lines 74-79) — original filename discarded (path-traversal / extension spoofing neutralized).
11. Private: dir 0700, move_uploaded_file, chmod 0600, return `private://members/...` (lines 81-93).
12. Public (student_photo): dir 0755, move_uploaded_file, chmod 0644, return `uploads/members/photos/...` (lines 95-104).

## 4. SECURITY ANALYSIS (TROJAN / MALWARE FOCUS)

### 4.1 Strengths (VERIFIED)
- S1. Extension is never taken from user input; randomized hex + allow-listed extension. Neutralizes `.php`, `.phtml`, double-extension, null-byte tricks.
- S2. `is_uploaded_file()` guards against forged tmp_name.
- S3. MIME allow-list + structural validation (`getimagesize` for images).
- S4. SVG excluded from IMAGE_MIMES — prevents SVG-based XSS/JS.
- S5. Private files stored OUTSIDE web root (`ssms_private/members`) when ROOT_PATH resolves; served only via authenticated controller.
- S6. Private dir mode 0700, file mode 0600.
- S7. `resolvePrivatePath()` uses strict regex + realpath containment — prevents path traversal on read.
- S8. `discard()` only unlinks resolved paths; photos regex-gated.
- S9. `mimeForPath()` re-validates on serve (fail-closed if tampered on disk).
- S10. No user-controlled destination directory.

### 4.2 Weaknesses / Gaps (VERIFIED / INFERRED)
- W1 (HIGH — trojan polyglot): Images are NOT re-encoded. A polyglot file (valid JPEG header + appended PHP/HTML) passes `getimagesize()` and is stored with `.jpg`. If the web server is ever misconfigured to execute `.jpg` as PHP, or if the file is served with a sniffable content-type, the payload survives. **Recommendation:** re-encode images via GD/Imagick on upload (strip EXIF, rewrite pixels) to destroy appended data. (Not implemented — analysis only.)
- W2 (HIGH — PDF content): PDF validation is only a 5-byte magic check. No structural parse, no JS-action stripping. A malicious PDF (with `/JavaScript`, `/OpenAction`, embedded files) is stored and served. If `member_file.php` serves with `Content-Disposition: inline` and `Content-Type: application/pdf`, modern browsers/PDF viewers may execute embedded JS. **Must verify** `member_file.php` uses `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. (See next file analysis.)
- W3 (MEDIUM — no AV): No antivirus/antimalware scanning (e.g., ClamAV). Enterprise standard for document uploads. Gap.
- W4 (MEDIUM — no size re-check post-move): `filesize($tmp)` checked before move; TOCTOU window negligible (tmp is PHP-owned) but no post-move verification.
- W5 (MEDIUM — finfo dependency): If `finfo` extension is absent, `detectMime()` returns `''` → all uploads rejected (fail-closed, acceptable). But no logging/alerting.
- W6 (LOW — BMP): BMP accepted; some BMP parsers have had vulnerabilities. Minor.
- W7 (LOW — legacy photo path): `discard()` photos regex assumes 32-hex naming. Legacy photos with other names won't be cleaned. Orphaned files possible.
- W8 (LOW — public photo exposure): student_photo is world-readable URL. By design, but means any uploaded (validated) image is publicly fetchable. Acceptable for photos, but no hotlink/abuse protection.
- W9 (INFO — no upload rate limit): Storage exhaustion possible. Minor.
- W10 (INFO — no checksum/integrity): No hash stored for later integrity verification.

### 4.3 Path Traversal / Resolution (resolvePrivatePath)
- Regex anchors (`#D`) prevent newline injection.
- `basename()` check + realpath containment prevents traversal.
- Legacy branch strips `admin/` prefix then regex-gates category. Good.
- Potential issue: `str_replace('\\', '/', ...)` on a path containing `..` — but regex `[^/]+` for filename and fixed category prevents `..`. Good.

## 5. EDGE CASES

- Empty upload (no file): returns success/null — correct (optional).
- Partial upload (UPLOAD_ERR_PARTIAL): rejected with message.
- 0-byte file: rejected (size <= 0).
- >5MB: rejected.
- Wrong MIME but right extension: rejected (MIME allow-list).
- Valid image with appended PHP: passes (see W1).
- PDF with JS: passes (see W2).
- Unknown field: rejected.
- finfo missing: all rejected (fail-closed).

## 6. DEPENDENCIES

- PHP extensions: `fileinfo` (finfo), `gd`? (getimagesize is built-in, no GD needed). `random_bytes` (PHP 7+).
- Constants: `ADMIN_PATH`, `ROOT_PATH`, `MEMBER_PRIVATE_STORAGE_PATH` (optional).
- Consumers: `hr_register_member.php`, `info_manage_member.php`, `member_file.php`.

## 7. UNVERIFIED / UNKNOWN

- U1: Whether `member_file.php` sets `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` — **not yet inspected** (next file).
- U2: Whether GD/Imagick is available for re-encoding (not currently used).
- U3: Whether ClamAV is installed on the server.
- U4: Whether `MEMBER_PRIVATE_STORAGE_PATH` is defined in production config.
- U5: Whether the private storage dir is actually outside the web root in production.

## 8. VERDICT

MemberFileService is **reasonably hardened** for a PHP app: randomized names, MIME allow-list, structural image validation, private storage outside web root, strict path resolution. **Primary gaps for enterprise trojan protection:** (1) no image re-encoding (polyglot risk), (2) PDFs stored/served without content sanitization or forced download, (3) no AV scanning. These must be addressed in `member_file.php` (serving) and optionally in this service (re-encoding).

**Next file to analyze:** `admin/member_file.php` (the serving controller) — critical for confirming W2/U1.
