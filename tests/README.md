# tests/

Automated tests. Nothing here is part of the website: the root `.htaccess` blocks this folder.

The full guide (setup, how each suite runs, current CI status and known failures) is [DEVELOPMENT_AND_TESTING.md](../docs/DEVELOPMENT_AND_TESTING.md). The test plan is [TEST_STRATEGY.md](../docs/TEST_STRATEGY.md).

| Folder | What it holds | In CI? |
|---|---|---|
| `security/` | Security, regression and structure tests (Python) | Yes: `python3 -m pytest tests/security -q -rs` |
| `e2e/` | End-to-end journeys and PHP harnesses run against a local server, plus `provision_env.sh` (builds a test database) | Only `idempotency_lifecycle.php` |
| `smoke/` | PHP smoke tests run against a test database | No |
| `audit/` | Production-audit regression suite. See [audit/README.md](audit/README.md) | No |
| `mutation/` | Mutation harness: breaks one rule on purpose to prove a test notices | No |
| `js/` | JavaScript runtime tests, driven from Python tests | Through `security/` |
| `fixtures/` | Test data and helper code shared by the suites | Used by the suites |

The Flutter tests live with the app, in `Mobile/wbws_flutter_app/test/`.

**Safety**
- Many e2e, smoke and audit scripts **reset or change data**. Run them only against a disposable test database, never production. Some refuse to start unless `SSMS_AUDIT_TESTING=1` is set.
- Never commit a real secrets file (`.fkss_env.php`); CI rejects it.
- New Python tests: `security/test_<topic>.py` ([CONTRIBUTING.md §9](../CONTRIBUTING.md#9-naming-and-where-files-go)).
