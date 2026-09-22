# Release & Rollback Runbook

**Status:** Active process · **Established:** 2026-09-22 (with the account
self-service release) · **CI:** `.github/workflows/ci.yml`

This document defines how SSMS is versioned, released, and rolled back. It
formalizes the tag habit the repository already used informally
(`revert-safe-point`, `pre-karaoke-v2`) into a repeatable, professional
process.

---

## 1. Versioning — Semantic Versioning (semver)

Releases are tagged `vMAJOR.MINOR.PATCH` on `main`:

| Bump | When | Example |
|---|---|---|
| **MAJOR** | Breaking change: schema migration that cannot run alongside old code, removed role/endpoint, config key rename | `v2.0.0` |
| **MINOR** | New feature that is backward-compatible (new module, new optional columns, new API actions) | `v1.1.0` |
| **PATCH** | Bug fix / security fix with no schema or contract change | `v1.1.1` |

Rule of thumb: **if deployment requires a SQL migration, at least mention it
in the PR "Deployment notes"**; if old code cannot run against the new schema,
it is a MAJOR bump and the migration must ship a downgrade note.

### Current release line

| Tag | Commit | Meaning |
|---|---|---|
| `v1.0.0` | `71095fc` | Baseline before account self-service (P1-B notification local-first state) |
| `v1.1.0` | *(see Releases page)* | Account self-service: shared My Account component, settings CSRF fix, API hardening |

Earlier informal tags (`revert-safe-point`, `pre-karaoke-v2`) remain as
historical markers and are still valid rollback anchors.

---

## 2. Release process (the "proper way")

1. **Branch** from `main`: `feature/<name>` or `fix/<name` — never commit to
   `main` directly.
2. **Open a PR** → CI runs automatically (changed-file `php -l` /
   `node --check` + DB-free smoke tests). CI must be **green** before merge.
3. **Merge** the PR (merge commit preserves branch history).
4. **Tag the release** on `main`:
   ```
   git tag -a v1.x.y -m "Release vX.Y.Z: <summary>" && git push origin v1.x.y
   ```
5. **Create a GitHub Release** for the tag with user-facing notes
   (Features / Fixes / Deployment notes).
6. **Deploy to production** from the tag (never from a branch tip).

---

## 3. Rollback runbook

### 3.1 Decide what kind of rollback you need

| Symptom | Action |
|---|---|
| New release misbehaves, need the old version back **now** | **Code rollback (§3.2)** — fast, no history rewrite |
| One bad commit among otherwise-good work | **Revert PR (§3.3)** — keeps everything else |
| Bad release reached `main` but is not deployed | Nothing to deploy; fix forward or revert (§3.3) |

### 3.2 Code rollback to a known-good tag (fast path)

Point production at the last good release tag:

```bash
cd <app-root>              # e.g. public_html
git fetch --tags
git checkout v1.0.0        # the last known-good release
# verify the app, then stay on the tag (detached) or:
# git switch -c hotfix/rollback-v1.0.0 && git push origin hotfix/rollback-v1.0.0
```

Or entirely from the GitHub UI: **Releases → v1.0.0 → "Browse code"**, or
download the tag archive and deploy it.

> **Why DB safety matters here:** rollbacks only need to be code rollbacks
> when migrations are *additive*. This is a standing rule — for example,
> `sql/047_account_self_service.sql` only **adds** `users.phone`; the app
> feature-detects the column, so **v1.0.0 code runs cleanly against a
> v1.1.0 schema** and vice-versa. Never ship a migration that breaks the
> previous release without a MAJOR version bump and a downgrade note here.

### 3.3 Revert a merged PR (keeps history, ships as a normal PR)

```bash
git checkout main && git pull
git revert -m 1 <merge-commit-sha>     # e.g. the account-service merge 474ddb5
git push origin main                    # (or open a PR with the revert)
```

GitHub UI alternative: on the merged PR page → **Revert** button → it opens
a ready-made revert PR → CI runs → merge.

### 3.4 What does NOT need rolling back

- `activity_logs` / audit rows: append-only history, harmless.
- `api_refresh_sessions` revocations: users just sign in again.
- `users.phone` values: ignored by v1.0.0 code (column unknown to it).

---

## 4. CI policy

The workflow is prepared at `docs/templates/ci-workflow.yml`. **Activation is
one step** (a PAT with the `repo` scope alone cannot create workflow files —
GitHub requires the `workflow` scope): copy the template into place and push
with a workflow-scoped token, or paste it via the GitHub web UI:

```
mkdir -p .github/workflows && cp docs/templates/ci-workflow.yml .github/workflows/ci.yml
```

Once in place, CI runs on every PR to `main` and every push to `main`:

1. `php -l` on **changed** PHP files only (vendored trees excluded).
2. `node --check` on **changed** JS files only.
3. `tests/smoke/account_settings_component_test.php` — DB-free contract test
   of the shared account component (runs on every CI pass so the component
   contract can never silently regress).

Status: **template ready, activation pending a `workflow`-scoped token or a
web-UI paste.**

**Branch protection (recommended next step):** Settings → Branches → protect
`main` with *Require a pull request before merging* + *Require status checks:
CI / quality*. Until then, the discipline is: never push to `main` directly.

---

## 5. Hotfix path (urgent production fix)

```bash
git checkout -b fix/<issue> v1.x.y     # branch FROM the deployed tag
# ... minimal fix, commit ...
git push origin fix/<issue>            # PR → CI green → merge to main
# release as v1.x.(y+1)
```
