# sql/

Database schema changes. Nothing here is part of the website: the root `.htaccess` blocks this folder.

| Path | What it is |
|---|---|
| `NNN_name.sql` (`001`–`078`) | Numbered migrations. Hand-written, mostly additive and idempotent. **The owner applies them to production by hand**; nothing applies them automatically |
| `preflight/` | Read-only checks run before or after some migrations. They create temporary tables only and never change application data |
| `legacy/` | Archived old schema sources: a stale schema snapshot and the early PHP migrations. Not to be applied. See [legacy/README.md](legacy/README.md) |

**Rules for a new migration** (full rules: [DATABASE.md §3](../docs/DATABASE.md#3-migration-rules-in-force), [CONTRIBUTING.md §5](../CONTRIBUTING.md#5-database-changes))
- Name: the next free three-digit number, then a short snake_case description, for example `079_member_notes.sql`. Never reuse a number; CI fails on duplicates.
- Never edit a migration that may already have been applied. Write a new one instead.
- Plain SQL that is safe for MariaDB on shared hosting. Make it idempotent (`IF NOT EXISTS`, `information_schema` guards) so it can be run twice.
- No bare `DELETE FROM` or `TRUNCATE` on user data; CI flags them. Data clean-ups are separate, owner-approved steps.
- Code must keep working before the migration is applied, because code deploys and migrations happen separately.

**Caution:** the numbered files do not build an empty database in order (some depend on legacy tables). For a test database use `tests/e2e/provision_env.sh` ([DEVELOPMENT_AND_TESTING.md §2](../docs/DEVELOPMENT_AND_TESTING.md#2-local-setup-test-environment-only)).
