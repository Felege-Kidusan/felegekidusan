# SSMS production audit — verified patch pass and remaining work

**Date:** 7 September 2026 · **Repository:** `suraman21/SSMS`
**Baseline:** `0743d922cd7b81676ae2ed4d3f3d42f03a02849a`
**Working copy:** `/home/user/SSMS`
**Status:** Verified bug-fix release candidate; GitHub `main` publication requested by the owner. **Production deployment has not been performed from this workspace.**

## 1. Executive result

The reported **Mezmur logout 404 is fixed locally**. The legacy logout shim now destroys the session and goes to the real, same-origin `/admin/index.php`, rather than `/backend/auth/index.php`. The actual Mezmur logout button and the alternate login form were exercised in Chromium.

This pass also patched grouped defects in authentication, sessions, financial dates and atomic writes, inventory quantities and concurrency, identity editing, enrollment transfers, impersonation, notification/task authorization, public enquiries, calendar initialization, user administration and several smaller UI/report issues. The detailed register below separates these changes from what still needs work.

**Highest-priority deployment finding:** test seeding/tooling was not safely excluded from HTTP. It can load application configuration and create/reset fixture accounts and academic data. Root path denials, directory-specific rules and PHP CLI guards have been added. Apply equivalent production protection promptly through your reviewed deployment process; this audit did not probe the unsafe endpoint on the live site.

### Important limit

This is **not a certification that every line, button or workflow is bug-free**. Every baseline repository file was inventoried; all PHP files were syntax-checked, including bundled dependencies. Selected high-risk code and workflows received deeper review and runtime tests. The file ledger explicitly identifies limited/automated coverage. Native-device behavior, external integrations, remaining legacy tests, some financial/account lifecycle policies and production-size MySQL behavior are not fully verified.

No request to the production application host, production deployment, production database access or production-record download was performed. GitHub was accessed for source retrieval and owner-requested publication. All test mutations used isolated synthetic databases. Packages and static CDN assets were downloaded for local testing; no customer data was sent to an external service.

## 2. Verification results

| Check | Result | What it establishes / limitation |
|---|---|---|
| Baseline PHP syntax | 931 / 931 passed | Demonstrates why syntax alone did not reveal the workflow bugs. |
| Final PHP 8.2.33 syntax | 936 / 936 passed | Real production major/minor compatibility check, including new helpers and fixture. |
| Final PHP 8.4.24 syntax | 936 / 936 passed | Secondary runtime compatibility check. |
| Standalone JavaScript syntax | 27 files passed | Non-minified tracked/new `.js` files; not proof of DOM behavior. |
| New HTTP regression suite | **33 / 33 passed on PHP 8.2 and 8.4** | Auth/role boundaries, direct/shim routes, CSRF, exact DB values, forced failures, concurrent writes, private files and backup paths. |
| API bootstrap CGI probe | Passed on PHP 8.2 and 8.4 | Final log review caught a duplicate constant warning; startup now returns clean JSON even with initial error display enabled. |
| Focused no-DB / CGI tests | **4 / 4 passed** | URL construction, validation, age boundaries and HTTP denial before config, independent of Apache. |
| Existing E2E workflow suite | **154 passed, 0 failed** on PHP 8.2 | Initially 152 passed / 2 failed. DOM stand-in and post-login CSRF assumptions were corrected, not application security relaxed. |
| Domain SQL/runtime smoke suites | **6 / 6 passed** | HR, Mezmur, Information analytics, PDF reports, QR rosters and scale-oriented guards, each in a reset synthetic `ssms_smoke` database. |
| Chromium role sweep | **13 roles; 78 desktop and 56 mobile navigation clicks** | No page JS errors, local HTTP errors, duplicate HTML IDs or document overflow observed in the final 390px/desktop sweep. Not every data-changing control was clicked. |
| Focused browser contracts | **5 / 5 passed** | Head-loaded/duplicate calendar, property value sync, Gregorian mode, double-click Finance save, attribute escaping and actual restore buttons. |
| Flutter tests | **332 passed** | A test file initially failed compilation; its literals/const usage were fixed. No app feature or dependency update was made. |
| Flutter analyzer (`lib test`) | **0 errors; 10 warnings; 469 info diagnostics** | Ran with nonfatal warnings/infos. It is not a warning-clean result and does not replace device testing. |
| Composer locked dependency audit | No advisories or abandoned packages reported | Point-in-time Composer result only; does not cover every bundled/non-Composer component. |
| Existing Python security/source contracts | **616 tests; 31 failures; 6 setup errors** | Baseline: 33 failures, 6 errors, 2 skips. After installing PDO SQLite, the two formerly skipped checks pass. This suite remains **red**. |

The newly enabled SQLite tests exercise a synthetic **100,000+ row directory** and real HTML/CSV rendering adapters. They do not establish production MySQL throughput or memory behavior under concurrent real traffic.

Evidence is under [`evidence/`](evidence/) and the adjacent JSON/CSV artifacts. The new tests are in [`tests/audit`](../../../tests/audit/README.md).

### Runtime environment

- Apache 2.4.68, including the repository's actual `.htaccess` rules.
- PHP 8.2.33 via FastCGI and PHP 8.4.24; MariaDB 11.8.6 / InnoDB.
- Node 20.20.2; Playwright Chromium.
- Flutter 3.47.2 / Dart 3.13.2. No Android/iOS release package was built or installed.
- The cPanel `ea-php82` production handler stanza was preserved. Local preview/server configuration is outside the repository and is not part of the deployment patch.

## 3. Patched finding register

These are grouped findings, not a count of independently certified workflows. “Verified” below always means the specified **local** evidence, not production validation. Detailed paths and evidence are also in [`findings.json`](findings.json).

| ID | Priority | Area | Defect and local correction |
|---|---|---|---|
| F01 | High | Shared authentication | Logout through /backend/auth/logout.php redirected to a nonexistent relative index.php. **Fix:** Database-independent logout, cookie destruction and same-origin canonical login URL. |
| F02 | High | Shared authentication | Login shim relative redirects were wrong; alternate login parsed redirect/HTML as JSON. **Fix:** Preserve normal form redirects; explicit JSON contract for fetch; alternate form uses that contract; validate scalar credentials and rotate session/CSRF identity. |
| F03 | High | Sessions | Idle-timeout directory traversal could remain at / forever on frontend/backend paths. **Fix:** Replace traversal with deterministic URL helper; destroy expired sessions and create fresh anonymous sessions on public/login pages. |
| F04 | High | Sessions | Privileged-session revalidation did not cover migrated frontend/backend routes. **Fix:** Include these areas in existing periodic account/password/role checks and recognize JSON shim requests. |
| F05 | Medium | Sessions | Login and several legacy account controllers started sessions before security bootstrap. **Fix:** One database-independent secure session bootstrap, reused before identity reads; HttpOnly, Lax and strict-mode behavior retained on PHP 8.2/8.4. |
| F06 | High | Rendering | PHP addslashes was not a safe script bridge; several HTML escapers left attribute quotes unescaped. **Fix:** JSON_HEX encoding for APP bootstrap; attribute-safe escaping; JSON-encoded attendance-card handler argument. |
| F07 | Critical | Deployment safety | HTTP-accessible test seeding/tooling could load live configuration and mutate data without a department login. **Fix:** Block tests/tools/repository metadata/dependency paths; CLI checks before config in PHP test scripts; explicit opt-in for destructive E2E seeding. |
| F08 | High | Deployment safety | Root rewrite denials were not automatically inherited where admin/.htaccess declared its own rewrite rules. **Fix:** Repeat targeted deployment/debug/library denials inside admin; preserve active backup, health and year-rollover tools. |
| F09 | High | Uploads | Executable-file denials missed cPanel .php8 and multi-extension names. **Fix:** Final case-insensitive denial covers PHP variants, PHAR and executable suffixes, including file.php.jpg. |
| F10 | High | Finance | Transaction date was bound as integer. **Fix:** Correct binding metadata so a Gregorian DATE survives insertion. |
| F11 | High | Finance | Paid fee and matching income were separate writes, used today instead of paid date, and assumed category ID 1. **Fix:** One transaction for both records; exact paid date and correct bindings; category lookup by intended income category rather than hardcoded ID. |
| F12 | High | Finance / Materials | Weak input validation accepted invalid values/references or surfaced driver failures. **Fix:** Shared typed public validation errors; finite two-decimal money, whole quantities, valid dates/enums/lengths; reference checks and useful 4xx responses. |
| F13 | Medium | Finance UI | Rapid save clicks could send duplicate writes; UTC-derived defaults could select the previous local day; paid-fee totals stayed stale. **Fix:** In-flight guards and disabled save buttons; local date defaults; refresh income and overview after paid fees. |
| F14 | High | Materials | Item unit string was bound as integer; quantity binding metadata was also wrong. **Fix:** Correct insert/update bind types. |
| F15 | High | Materials | Outgoing/disposal could overdraw and still log the requested amount; log/balance writes were not atomic; adjustment never changed quantity. **Fix:** Lock the item, enforce available stock and commit movement/balance/status together. Adjustment means counted absolute balance, including zero. |
| F16 | Medium | Materials / Finance | Missing records could be reported as updated/deleted; item deletion orphaned movement/request history. **Fix:** Return not-found outcomes and refuse deletion of inventory with history; validate request quantities/statuses and links. |
| F17 | High | Identity & Codes | Position edit bound seven values using six type characters; legacy_flag was bound numerically on create/edit. **Fix:** Correct arity and preserve the textual legacy flag. |
| F18 | High | Notifications / tasks | Notification/task IDs could be modified outside recipient scope; member-change old/new values were exposed as generic alerts. **Fix:** Match recipient predicates for writes; restrict detailed member-change history to member-management staff. |
| F19 | High | Cross-department actions | CSRF was checked only on POST, while several mutations could be reached using GET (including year-context clear and mark-all-read). **Fix:** Explicit POST requirement for enumerated Finance, Materials, notification, year-context, Education, subject, teacher and attendance-status writes. |
| F20 | High | Enrollment | mysqli::in_transaction() does not exist; transfer path could fatally fail. First-enrollment concurrency was unprotected. **Fix:** Explicit transaction ownership/savepoints, member row locks, source-year validation, generic failure messages and reliable reused target IDs. |
| F21 | High | Impersonation | Assumed department role could not pass restore endpoint gate; legacy restore button omitted CSRF. **Fix:** Only impersonation endpoint may use validated original privileged role; include CSRF in restore button. |
| F22 | Medium | Shared calendar | Head-loaded observer used document.body before it existed; duplicate inclusion redeclared calendar state; property-set values stayed visually stale; dynamic Gregorian inputs were converted. **Fix:** DOM-ready boot, single inclusion/initialization, value synchronization and mode-respecting refresh. |
| F23 | Medium | Teacher | No-assignment Teacher page tried to populate a submission element that was not rendered. **Fix:** Skip list request/render when the empty-state page has no list element. |
| F24 | Medium | Content Manager | Content-editor landing had no logout control and its header overflowed at phone width. **Fix:** Provide logout instead of a self-referential dashboard link; compact small-screen header. |
| F25 | High | User management | Unsafe referer redirect, self-lockout via Save, silent update of missing user, missing destructive CSRF and incomplete target-role toggle list. **Fix:** Same-origin allowlisted redirects; fresh bootstrap; field bounds; self-role/status protection; missing-user check; delete CSRF; new existing role types supported by toggle. |
| F26 | High | User deletion / privacy | Clearing the only notification recipient could turn a private message into a global broadcast. **Fix:** Remove single-recipient messages on account deletion; preserve already role-addressed messages. |
| F27 | Medium | Public enquiries | Array inputs could fail validation; Amharic name length used bytes; fields were weakly bounded; file-based rate counter raced. **Fix:** Schema-aligned scalar/Unicode/phone/date-age bounds and shared atomic limiter (five accepted submissions/hour/IP). |
| F28 | Medium | Public member verification | Age used constant Gregorian-minus-eight and ignored actual birthday. **Fix:** Gregorian birthday-aware age with real Ethiopian year boundary/fallback for legacy Ethiopian fields. |
| F29 | Low | REST routing | Array-shaped routes reached trim() before the error handler; duplicate API marker definitions emitted warnings before config. **Fix:** Reject malformed routes with JSON 400 and define the API request marker only once. |
| F30 | Low | Reports | Leading UTF-8 BOM could precede headers. **Fix:** Remove BOM without changing report content. |
| F31 | Medium | Mezmur timing editor | Native confirm calls bypassed established in-app dialog; absent dialog could auto-accept a destructive action. **Fix:** Use existing system confirm and fail closed when dialog is unavailable; capture hymn identity for delayed confirmation. |
| F32 | Quality | Regression infrastructure | New Flutter test file did not compile; old DOM harness and CSRF assumptions caused false failures. **Fix:** Dart-3.3-compatible literals/non-const runtime strings; complete DOM stand-in; authenticated CSRF extraction; focused scope-aware assertions. |

### Selected integrity evidence

- **Finance dates:** inserting `123.45` on `2026-08-22` preserves both exact values in the database. Dates are strings, not integers.
- **Paid fee atomicity:** a forced failure on the income INSERT leaves no fee row behind. A historical paid date is used for its matching income. Category ID `1` is no longer assumed to be the correct income category.
- **Stock:** two independent authenticated sessions attempt to withdraw four units from a balance of five. Exactly one succeeds; the other receives `409`; balance is one with one movement row. A forced movement failure leaves stock unchanged.
- **Enrollment:** initial concurrent enrollments leave one active class; transfer there/back reuses the correct existing target ID. Nested operations use a savepoint and do not commit unrelated outer work. Forced failure rolls back the operation without falsely reporting success.
- **Identity:** position create/edit preserves textual `legacy_flag`; seven update arguments have seven type specifiers.
- **Authorization:** a Teacher cannot dismiss a Finance-only notification or complete its task. Detailed member-change values are not a generic all-role feed. Private documents are rejected for unrelated roles and traversal paths.
- **Deletion privacy:** deleting a user's sole notification target no longer publishes that private notification as a target-less broadcast.
- **Backup:** Super Admin can create, download and decrypt an encrypted backup. School Admin is denied. Actual SQL restore/disaster recovery was **not** rehearsed against a production-like copy.

## 4. Coverage ledger — what “file by file” means here

The baseline contains **1,996 tracked files**, including PHP dependencies, two QR library trees, TCPDF, Flutter/platform files, tests and documentation. Treating all those as manually reviewed application code would be misleading.

The final input inventory currently contains **2,009 files** (baseline plus added source/test files; the inventory excludes its own generated audit artifacts). Categories are heuristic and visible in the CSV:

| Classification | Files |
|---|---:|
| asset/configuration | 25 |
| application-source | 453 |
| documentation | 38 |
| mobile-platform/assets | 193 |
| third-party/bundled | 1,224 |
| test/tooling | 76 |

Artifacts:

- [`file-inventory.csv`](file-inventory.csv): path, category, size, line count, SHA-256, review level and notes.
- [`review-notes.json`](review-notes.json): changed-section/targeted-runtime coverage. An entry is **not** a whole-file manual sign-off.
- [`function-index.csv`](function-index.csv): regex-assisted named PHP/JavaScript function declarations. It is a navigation aid, not a complete AST index of Dart methods, closures or all dynamic calls.
- [`control-index.csv`](control-index.csv): source HTML controls. Conditional and dynamically generated controls need additional runtime review.
- [`literal-routes.csv`](literal-routes.csv): literal route references and target existence. Dynamic relative paths require context.
- `php82-lint-final.json`, `php84-lint-final.json`, `javascript-lint-final.json`: per-file syntax evidence.
- `phpstan-baseline.json` / `phpstan-level5.json`: diagnostic discovery, **not** a clean static-analysis gate. Includes false positives from runtime includes, global helpers, overload-like separate entry points and excluded dependency definitions. The nonexistent mysqli transaction method was a real finding from this pass.

### Department/workflow coverage

| Area | Exercised | Still needs deeper/staging coverage |
|---|---|---|
| Super/School Admin | Landing/navigation, role restrictions, identity edit, impersonation return, self-lockout rejection, user deletion privacy, backup creation/download/decryption | All account lifecycle combinations, two-admin concurrent revocation, every branding/export variant and full restore |
| HR | Browser navigation, private file access boundaries, independent attendance/submission service smoke | Every member-edit/import field, real document replacement and all archived/duplicate-member combinations |
| Information | Browser navigation, analytics/read-model smoke, PDF/QR/report checks | Every legacy member-management control, large report payloads and ownership-policy consistency |
| Education | Navigation, existing assignment/grade/attendance E2E checks, enrollment/transfer concurrency | Complete promotion/year-rollover histories, all edge-year data and multi-user lifecycle conflicts |
| Finance | Navigation, categories/transactions/fees, date/money validation, rollback, double-click guard | Partial-payment semantics, durable fee↔ledger link, reversal/void policy and server retry idempotency |
| Materials | Navigation, item create/edit, units, movement/stock/status consistency, insufficient stock, concurrent withdrawal | Concurrent master-record editing, full adjustment audit policy and request-fulfillment accounting |
| Mezmur | Alternate login/logout, navigation, existing library/search/catalog/media contract E2E, attendance/submission smoke, confirmation repair | R2 live upload/range/streaming, real audio timing, native playback, queued-edit ownership policy |
| Teacher / takers | Login/routes/navigation, no-assignment empty state, class-restricted API tests; department taker read-only landing | Every assigned-class browser path and physical-device attendance capture/offline sync |
| Content Manager / public site | Content tabs/logout/mobile width, public enquiry validation and limiter, public member age | Every CMS content edit/upload and public visual layout on real devices |
| Flutter | Analyzer, all existing tests, version/artifact tests | Physical Android/iOS, biometrics, camera, notifications, background audio, app installation/update and destructive cache/reconnect scenarios |

### Registration was deliberately retired

Git history shows HR registration removal in `dffe768` (31 August 2026); Information registration had previously moved to HR. Current HR register buttons and the School Admin quick-add entry are gone. Information retains a hidden old form and old JavaScript; HR retains dead handlers.

The three unresolved application literals point to those retired endpoints. The other seven unresolved literals are back-links printed by CLI-only legacy migration files. These are **not all active broken buttons**. No registration endpoint or mobile registration screen was silently restored. Restoring that capability is a separate owner-approved change, not a way to make obsolete tests green.

## 5. Remaining risks and decisions — do not skip these

### High-priority follow-up

1. **Fee/ledger lifecycle:** `finance_member_fees` has no durable foreign-key link to its income transaction. Atomic creation is fixed, but independent edits/deletes/voids can still produce business inconsistency. “Partial” also needs a clear definition: total due versus cash actually received. A reviewed schema/workflow change and receipt reconciliation are needed; no guesses or bulk financial repair were made.
2. **Retry idempotency:** Finance now prevents immediate duplicate button submissions, but that is not exactly-once processing after a lost network response. Add a durable, validated request-ID policy before promising safe automatic retries for monetary or stock writes.
3. **Account lifecycle:** legacy User Save still combines a PDO account update with later mysqli teacher-assignment lifecycle work. Cross-connection atomicity and last-active-Super-Admin protection under concurrent administrators deserve a separate fault-injected review. Self-deactivation/demotion through the form is blocked, but a global last-admin guarantee is not claimed.
4. **Existing data:** deployment may already contain coerced dates, `unit='0'`, invalid position flags, orphan stock movements or multiple active enrollments. The patch prevents tested new failures; it does not reconstruct historical intent. Review [`preflight-readonly.sql`](preflight-readonly.sql), then reconcile with department owners.
5. **The legacy security suite is not green.** See [`legacy-test-triage.csv`](legacy-test-triage.csv) for all 37 unresolved entries. Some clearly assert old SQLite version 20 versus current 24, old app build 17 versus current 19, or deleted registration files. Other UI/search contracts need semantic review. Do not classify them all as harmless merely because newer tests pass.

### Additional boundaries

- Notification read state is still stored on the shared notification row, not as per-recipient receipts. Recipient-scoped authorization is fixed; independent read/unread state for every staff member is not implemented.
- Direct inventory master edits and request fulfillment need an agreed audit/movement policy; the verified transactional guarantees apply to the movement endpoint.
- Browser lyric drafts/queues and the mobile shared hymn-library policy were deliberately **not migrated or cleared** in this patch. Account scoping would require a safe ownership/recovery design so existing offline work is not hidden or lost.
- The central basename role map still has a permissive authenticated fallback for unmapped application files. A full explicit allowlist/action-policy migration needs route-by-route review; blanket blocking already-live tools would break workflows.
- Production schema, storage permissions, OPcache, TLS/proxy behavior, session settings, scheduled jobs and volume were not inspected. MariaDB test success is not a production MySQL capacity certification.
- Real R2/media, AI/provider integrations, backups on the host, APK delivery and native-device permissions remain staging tasks. No real external-service credentials were requested or used.
- Composer audit does not certify all bundled code. TCPDF's checked-in header is `6.11.4`; QR code libraries include older/duplicated source. They were syntax/smoke checked, not manually reviewed line by line or upgraded blindly.

## 6. Compatibility changes to communicate

- Fetch login clients should send `Accept: application/json`. Normal HTML forms retain redirects. Login rotates the CSRF token; use the authenticated page's token afterward.
- Wrong-method mutations now return `405`; invalid ledger input uses `422`; conflicts use `409`; missing records use `404`. Clients must display the server's validation message instead of assuming every response is `200`.
- Monetary inputs allow finite positive values with at most two decimals, capped at `99,999,999.99`; zero is allowed only where explicitly appropriate, such as an optional purchase price.
- Inventory adjustment quantity means **absolute counted balance**, not signed delta. Outgoing/disposal above available stock fails without changing balance/history. Items with movement/request history cannot be deleted.
- `EnrollmentService` now owns its transaction by default. A caller already owning a **mysqli** transaction must explicitly pass `withinTransaction=true` so a savepoint is used. Current call sites were checked; do not assume a separate PDO transaction covers mysqli work.
- Detailed member-change feed access is restricted to Super Admin, School Admin, Information and HR. Other roles retain their scoped notifications/tasks.
- Public enquiry age choices follow the existing form (4–18, optional). Names are measured as Unicode characters; phone/email/field sizes are validated.
- No database migration, dependency upgrade, mobile feature change or registration restoration is included in the application patch.

## 7. Safe deployment and rollback

**Do not copy this checkout over production blindly, run its test seeder on production, or use `git reset --hard` as an audit deployment plan.**

### Before deployment

1. Review the diff and package manifest against the stated baseline. Preserve local hosting configuration and the cPanel PHP handler. If production has changed since the confirmed baseline, merge and retest rather than overwrite.
2. Protect `/tests/`, top-level `/tools/`, repository metadata and non-public library/debug paths promptly. Remember that `/admin/` has its own rewrite configuration. Keep legitimate `/admin/tools/backup.php`, `download_backup.php`, `health_check.php` and year-rollover flows accessible through their authentication gates.
3. Back up current application files **and** the database using your established secure process. Preserve the private environment and encryption keys separately. Prove decryption and rehearse restore into an isolated database before relying on the backup.
4. In staging, check required tables/columns and **InnoDB** engines using the read-only preflight. Do not run the legacy synthetic provisioner as a migration runner.
5. Test with approved synthetic/anonymized data, production PHP 8.2 and production-like routing. Disable real mail, AI, payments and object-storage side effects in the test environment.

### Deploy as one reviewed release

6. Prefer an atomic release-directory switch or maintenance window. New helpers must exist before updated controllers/config reference them: `backend/core/browser.php`, `LedgerInputException.php`, `LedgerValidation.php`, `MemberAge.php`.
7. Deploy the reviewed source and rule changes together. Do not deploy the local `.fkss_env.php`, database dumps, test accounts, runtime servers, package caches or SDKs. Tests/docs can remain outside the served release.
8. Reload/reset OPcache using the hosting-supported method. PHP and JS must be from the same release; shared core/calendar and department scripts use file-version cache busting where changed.
9. Run the staging acceptance checks below, inspect server logs, and only then reopen any paused writes. Monitor login/403/500 rates and department reports after release.

### Rollback

- Keep the prior release and pre-change file manifest. Switch back atomically or restore the affected files from the matching pre-image; do not remove new helper files while new controllers still reference them.
- Preserve the security denials even if an unrelated application change is rolled back, or install equivalent host-level protection first. Do not re-expose test tooling.
- This patch adds no schema migration. **Do not restore an old database snapshot over legitimate new transactions merely to roll back code.** Reconcile any post-release writes separately with the appropriate department.
- Stop writes if integrity is uncertain. Verify old/new code and assets are not mixed; clear OPcache through the host and rerun login/logout plus affected workflow checks.

## 8. Staging acceptance checklist

- [ ] Direct and shim login/logout resolve to the same host; Enter/button submission works; bad credentials and expired CSRF give useful messages.
- [ ] Logout clears access; back/refresh and another tab do not regain authenticated API access.
- [ ] Idle and absolute expiry, deactivated account, password change and role revocation behave on legacy and migrated pages.
- [ ] Switch to each supported department and restore the original admin using the actual button.
- [ ] Exercise every enabled department with its real permission boundaries, including no-class/no-data states and a non-admin denied role.
- [ ] Finance: valid/historical dates, category mismatch, paid fee rollback, network timeout/retry, partial payments and reversal policy reviewed by Finance.
- [ ] Materials: create/edit, incoming/outgoing/disposal/adjustment, insufficient stock, concurrent users, history-preserving deletion and fulfillment policy.
- [ ] Education: enroll/transfer/promote/unenroll, assignments, grades, attendance, locked/past year and rollover, including rollback on failure.
- [ ] HR/Information: every member edit/import/archive/restore/duplicate override and file replacement path; private documents remain inaccessible directly.
- [ ] Public enquiries, CMS edits/uploads, long Unicode text and small-screen layout.
- [ ] PDF/QR printing, fonts/logos, scans from actual phones and ID expiry/age boundaries.
- [ ] Encrypted backup, authorized download, decryption and **full isolated restore**.
- [ ] Physical Android/iOS: offline attendance/grades, interrupted sync, re-login on shared device, app lock, camera, audio/background controls and APK updates.
- [ ] R2 range/stream/upload/delete and external AI/provider behavior tested with non-production resources.
- [ ] Legacy test failures individually resolved/rebaselined with evidence; no blanket skips presented as a green release gate.

## 9. Deliverables and disposition

- Modified working copy with local patches and new regression tests.
- `SSMS-production-audit-patch.zip`: reviewed-file overlay, rollback pre-images, checksums and diff (generated after final checks).
- This report, grouped finding register, per-file coverage ledger, per-file lint records, browser evidence, compact test logs and read-only preflight SQL.

**Recommendation:** review and stage this patch; prioritize the exposed-tool protections. Complete the remaining accounting/account-lifecycle and staging/device checks before treating the broad production audit as finished or proceeding to feature changes.
