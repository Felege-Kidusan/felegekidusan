# ADR-0017: Members are never registered from the Android app

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-17 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Registration needs document uploads, duplicate checks and HR review, which were hard to guarantee on an offline mobile client.

## Decision

Member registration is web-only and owned by HR. The mobile registration path was removed. Mobile edits of guardian data sync back to the server.

## Consequences

- **Positive:** one controlled entry point for the register.
- **Negative:** field registration requires a browser.

## Evidence

Commit `f5098cb`; [ARCHITECTURE.md §4](../ARCHITECTURE.md#4-mobile-application-mobilewbws_flutter_app).
