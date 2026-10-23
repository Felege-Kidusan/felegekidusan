# scripts/

Command-line tools for the operator. Nothing here is part of the website: the root `.htaccess` blocks this folder, and the PHP scripts also refuse web requests (`PHP_SAPI` guard).

Run every script from the repository root. Read the header of a script before running it; each one documents its modes and safety rules.

| Script | What it does | Changes data? |
|---|---|---|
| `check_live_urls.py` (+ `live_urls.json`) | Checks that key URLs on dev or production still answer as expected (`--server dev` or `--server production`). See [OPERATIONS.md §2.2](../docs/OPERATIONS.md#22-folder-structure-safety-checks) | No. GET requests only, no login |
| `promote_to_production.sh` | Promotes an approved commit to the production repository through a pull request ([PRODUCTION_WORKFLOW.md](../docs/PRODUCTION_WORKFLOW.md)). Reads the token from `FKSS_GH_PAT`; `--dry-run` changes nothing | Production repository (GitHub) |
| `restore_production_dump.sh` | Restores a phpMyAdmin export into a database, applying foreign keys one at a time | Yes: the target database |
| `attendance_rollout_2026.php` | One-off attendance-alerting back-fill (2026-10). `--verify` and the default dry run are read-only | Yes, with `--apply` |
| `find_stored_mojibake.php` | Finds double-encoded text stored in the database and prints the repair SQL | Yes, with `--apply` |
| `fix_mojibake.py` | Repairs double-encoded text in three PHP source files (dry run by default) | Source files, with `--apply` |

**Safety**
- Scripts that change data run on the server or a test database only after the owner approves. Try them on a test database first.
- Never put tokens, passwords or database credentials in this folder or in a command saved to a file.
- New scripts: snake_case names, a header that says what the script does and how to run it, and a `PHP_SAPI` guard on PHP scripts ([CONTRIBUTING.md §9](../CONTRIBUTING.md#9-naming-and-where-files-go)).
