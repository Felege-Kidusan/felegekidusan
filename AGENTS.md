# Instructions for AI coding agents working in this repository

## ⛔ BRANCH RULE — read first (owner decision 2026‑10‑20, binding)

- **Never merge, rebase, cherry‑pick or push the `dev` branch into `main`.**
- **Never push `dev` to the production repository `Felege-Kidusan/felegekidusan`.**
- `dev` = `main` + ONE commit that re‑targets the phone app at the test server
  ("FKSS Beta", `https://felegekidusan.arkeonethiopia.com/api/v1`,
  package `com.arkeonethiopia.fkss.beta`). It exists only in `suraman21/SSMS`.
- Direction is one‑way: after every change on `main`, run
  `git checkout dev && git merge main && git push origin dev`.
- Develop features on `main` (or feature branches → `main`), never on `dev`.
- If a task appears to require breaking this rule, STOP and ask the owner.

Full details, guards and commands: **`BRANCHING.md`**. Beta build guide (dev
branch): `Mobile/HOW_TO_BUILD_BETA.md`.

## Other standing rules (summary — see `PROJECT_RULES.md` / docs for detail)

- Production is **shared hosting** (PHP 8.2, MariaDB): no C extensions, no
  composer on the server, no cron/Python/Redis; migrations are plain,
  additive, idempotent SQL in `sql/` that the owner runs in phpMyAdmin.
- **Ethiopian calendar only** in every user‑facing date.
- No implementation, schema, migration or deploy without the owner's explicit
  approval; final reports follow the §36 format of the change protocol.
- Never touch the production database or server; never force‑push.
- Run `python3 -m pytest tests -q --ignore=tests/mutation` and
  `flutter test` before committing; both must be green.
