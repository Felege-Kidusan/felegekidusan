# BRANCHING — read this before you merge, rebase or push anything

> **Owner rule (2026‑10‑20, binding for humans and AI agents):**
> **`dev` is NEVER merged, rebased, cherry‑picked or pushed into `main`, and
> NEVER pushed to the production repository.** Direction is one‑way only:
> `main` → `dev`.

## The two branches

| Branch | Lives in | What it is | Who deploys from it |
|---|---|---|---|
| **`main`** | `suraman21/SSMS` **and** `Felege-Kidusan/felegekidusan` (identical commits) | The real system. Production web server + **production phone app "FKSS"** (`https://felegekidusan.com/api/v1`, package `com.arkeonethiopia.fkss`). | Production server (`git pull`), dev server (`git pull`), production APK builds. |
| **`dev`** | `suraman21/SSMS` **only** | `main` **+ exactly one commit** that re‑targets the *phone app* at the test server: **"FKSS Beta"**, `https://felegekidusan.arkeonethiopia.com/api/v1`, package `com.arkeonethiopia.fkss.beta`, BETA chip on the login screen. Server code is byte‑identical to `main`. | Beta APK builds only (`Mobile/HOW_TO_BUILD_BETA.md`). It may also be pulled on the dev server — the server files are the same as `main`. |

`dev` always contains the full latest system: after every push to `main`, `main` is merged **into** `dev` (`git checkout dev && git merge main`). Pulling `dev` therefore gives *everything that is on main* plus the beta app settings.

## Rules

1. **Never** `git merge dev` / `git rebase dev` / `git cherry-pick <dev commit>` while on `main` (or any branch that will reach `main`).
2. **Never** open or accept a pull request whose base is `main` and head is `dev`.
3. **Never** push `dev` to `Felege-Kidusan/felegekidusan` (`git push <prod> dev` is forbidden in any form).
4. Features are developed on `main` (or a feature branch merged into `main`), **never on `dev`**. The only commit that may exist on `dev` but not on `main` is the beta‑target commit (and merge commits from `main`).
5. Keeping `dev` current is the agent's job: `git checkout dev && git merge main && git push origin dev`. Never force‑push either branch.

## What stops an accident

| Guard | Where | Effect |
|---|---|---|
| `tests/security/test_mobile_target_environment.py` | `main` pins production values; `dev` carries a different version pinning beta values | A merge `dev → main` conflicts on this file, and if resolved wrongly the test fails on `main`. |
| `.github/workflows/branch-guard.yml` | runs on every push / PR to `main` | Fails CI if beta values (`arkeonethiopia.com/api`, `fkss.beta`, `FKSS Beta`, `isBeta`) appear on `main`, or if a PR into `main` comes from `dev`. |
| `AGENTS.md` (repo root) | read automatically by AI coding tools | Repeats this rule in the first lines. |
| `.github/PULL_REQUEST_TEMPLATE.md` | every PR | Checklist item "not from `dev`". |
| Build step | `flutter build` runs the full test suite (see `RELEASE_NOTES.md` header) | A production APK cannot be built from a tree that carries beta values. |

## Day‑to‑day

```bash
# Beta app for testing (dev server):
git checkout dev && git pull origin dev
cd Mobile/wbws_flutter_app && flutter build apk --release        # → FKSS Beta

# Production app (after testing):
git checkout main && git pull origin main
cd Mobile/wbws_flutter_app && flutter build apk --release        # → FKSS

# Servers:
#   production server  → git pull (main) from Felege-Kidusan/felegekidusan
#   dev server         → git pull (main or dev — server files are identical)
```

If you are an AI agent and the task seems to require breaking rule 1–3: **stop and ask the owner**; do not proceed.
