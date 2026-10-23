# ADR-0005: Distribute the Android app directly, with an in-app updater

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-08-19 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

The school did not publish the app to an app store. Staff phones still needed reliable updates.

## Decision

The server publishes the latest version, the minimum build and a force flag (`GET /api/v1/app/config`) and serves the APK (`/api/v1/app/download`). The Super Admin uploads APKs through a release manager with a resumable, chunked upload. The app offers optional updates and enforces forced ones.

## Consequences

- **Positive:** no store dependency; immediate rollout.
- **Negative:** users must allow installs from unknown sources; app-signing key custody becomes critical; release discipline is manual.

## Evidence

Commits `09f46ec`, `d706d26`, `fc0e376`, `3ec85fb`; `AppReleaseManager.php`; `api/v1/routes/app.php`; `Mobile/wbws_flutter_app/docs/RELEASE.md`.
