# ADR-0006: One role per account, organised by department

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-08-15 (HR department created); 2026-08-28 (department-owned takers) | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Each church department works on its own part of the register, and personal data must be limited to those who need it.

## Decision

- Every staff account has exactly one of 13 roles.
- Pages and endpoints are gated by file name in `admin/access_control.php` (`ROLE_MAP`), with finer checks inside endpoints.
- Registration moved from the Information Department to a new HR Department.
- Departments create and manage their own attendance-taker accounts.
- School Admin and Super Admin can impersonate a department.

## Consequences

- **Positive:** simple mental model; least privilege per department.
- **Negative:** one person needing two roles needs two accounts; a file not listed in `ROLE_MAP` is open to every logged-in role unless it checks for itself.

## Evidence

`admin/access_control.php`; `admin/backend/user-save.php`; commits `fd8b416`, `ec473e3`; [ROLES_AND_PERMISSIONS.md](../ROLES_AND_PERMISSIONS.md).
