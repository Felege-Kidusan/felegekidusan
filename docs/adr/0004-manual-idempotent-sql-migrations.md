# ADR-0004: Hand-applied, additive and idempotent SQL migrations

| Status | Date | Deciders |
|---|---|---|
| Accepted (known debt) | 2026-08-15 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

There is no shell or deploy tooling on the server. The owner applies database changes through phpMyAdmin.

## Decision

Database changes are numbered files `sql/NNN_*.sql`. They are additive and idempotent where possible (`IF NOT EXISTS`, `information_schema` guards), the owner runs them by hand, and code tolerates a missing migration where feasible (a feature stays inert until its table exists). CI checks numbering and flags destructive statements.

## Consequences

- **Positive:** safe to re-run; deploys can come before the migration.
- **Negative:** **no migration ledger**, so the applied state in production is unknown; `sql/` cannot be applied linearly to an empty database; runtime DDL exists in places.
- **Follow-up:** introduce a ledger and a verified baseline ([DATABASE.md §6](../DATABASE.md#6-recommended-next-steps-documented-not-implemented)).

## Evidence

`sql/`; `.github/workflows/backend-checks.yml`; [DATABASE.md](../DATABASE.md).
