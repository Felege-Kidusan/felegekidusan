# ADR-0015: Mezmur audio and art in object storage, with presigned URLs

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-09-03 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Hymn audio files are too large for shared-hosting storage and PHP upload limits.

## Decision

Store media in S3-compatible object storage (Cloudflare R2). Clients upload directly with presigned URLs (presign, then confirm), and playback uses short-lived signed GET URLs. The app can download audio for offline use.

## Consequences

- **Positive:** offloads bandwidth and storage; no large uploads through PHP.
- **Negative:** an external dependency and credentials to manage; signed-URL expiry must be handled by the players.

## Evidence

`MezmurMediaService.php`; `api/v1/routes/mezmur.php` (`audio-presign`, `audio-confirm`); commits `b3f0e51`, `09ddbba`, `2a21a00`.
