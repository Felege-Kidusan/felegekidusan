# Legacy schema snapshot

`database_schema.sql` is a **partial, stale** snapshot of the original schema (11 tables). It is **not** the current schema and must not be applied to production.

- **Why it is kept:** `tests/e2e/provision_env.sh` loads it as the first step when building a local test database, and two tests read it (`tests/security/test_authorization_scope_version.py`, `tests/security/test_identity_management.py`).
- **Where the real schema comes from:** the versioned migrations `sql/NNN_*.sql`, applied manually by the owner. See [docs/DATABASE.md](../../docs/DATABASE.md).
- **History:** moved here from the repository root on 2026-10-23 ([RFC 0001](../../docs/rfc/0001-repository-structure.md), Phase 1). Content unchanged; `git log --follow sql/legacy/database_schema.sql` shows its full history.

This folder is outside the numbered migration sequence, so the CI migration-numbering checks (which scan `sql/[0-9][0-9][0-9]_*.sql`) do not see it. The web server denies `sql/` entirely.
