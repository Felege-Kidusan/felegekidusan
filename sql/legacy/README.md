# Legacy schema snapshot

`database_schema.sql` is a **partial, stale** snapshot of the original schema (11 tables). It is **not** the current schema and must not be applied to production.

- **Why it is kept:** `tests/e2e/provision_env.sh` loads it as the first step when building a local test database, and two tests read it (`tests/security/test_authorization_scope_version.py`, `tests/security/test_identity_management.py`).
- **Where the real schema comes from:** the versioned migrations `sql/NNN_*.sql`, applied manually by the owner. See [docs/DATABASE.md](../../docs/DATABASE.md).
- **History:** moved here from the repository root on 2026-10-23 ([RFC 0001](../../docs/rfc/0001-repository-structure.md), Phase 1). Content unchanged; `git log --follow sql/legacy/database_schema.sql` shows its full history.

## `php-migrations/`

The early PHP migration scripts, moved unchanged on 2026-10-23 ([RFC 0001](../../docs/rfc/0001-repository-structure.md) step 2b) from `admin/migrations/` (10 files, now `php-migrations/admin/`) and `backend/migrations/` (9 near-identical copies, now `php-migrations/backend/`). `git log --follow` shows their history.

- **They do not run from here, by design.** Each file loads its settings file by a path relative to its old folder, which no longer resolves, so it stops before any database work. Do not "fix" those paths.
- **Still read by tests:** `tests/e2e/provision_env.sh` copies the `CREATE TABLE` text out of `php-migrations/admin/`, and `tests/security/test_schema_conformance.py` reads `003_add_assessments.php`. For the one manual step that runs a file, see `tests/audit/README.md`.

This folder is outside the numbered migration sequence, so the CI migration-numbering checks (which scan `sql/[0-9][0-9][0-9]_*.sql`) do not see it. The web server denies `sql/` entirely.
