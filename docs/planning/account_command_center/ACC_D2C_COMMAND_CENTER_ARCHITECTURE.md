# D2-C — Command Center (staff console): Architecture

**Status:** DRAFT v0.1 · **Date:** 2026-10-18 · Part of D2 (see `ACC_D2_ARCHITECTURE.md`).
**Governing:** D1 v1.1 (§4 permission matrix, §5 SoD, §8 approvals, §10 tracking/reporting, §11 support), `FOUR_EYES_GOVERNANCE_RESEARCH.md` decisions. **Language:** English (D0-3).
**Placement:** inside SSMS admin — `admin/acc/` pages + `admin/api_acc.php` JSON endpoints — using the existing staff session, `AdminSessionGuard`, CSRF (`CSRF_TOKEN`) and role gates (FACT patterns). New role value **`accounts_office`** added to the staff role list; `super_admin` inherits everything.

---

## 1. Who sees what (from D1 §4)

| Role | Entry point | Modules visible |
|---|---|---|
| `accounts_office` | own dashboard "Accounts" | Directory, Account page, Activation desk, Lost-phone desk, Reset desk, Support cases, View-as, Audit explorer (own product scope), Reports, Approvals (as maker), Pending reviews (view-as of others) |
| `super_admin` | super-admin dashboard → "Accounts" section (full) | all of the above + Policy, Roles, Approvals (as checker), Break-glass, Integrity, Consent versions, Exports |
| `school_admin` | school-admin dashboard → "Accounts oversight" | Approvals inbox (custody removals, break-glass reviews), Monthly review, Quarterly admin-rights review, Integrity button, read-only summary |
| `hr_dept` | HR dashboard → within Family Link: "Account status" column/badge per guardian/member | read-only status + link to request desk action (no actions) |

---

## 2. Modules

### 2.1 Directory & Account page
- Search by member ID (child or member), guardian/member name (Amharic/Latin), phone, device name, status, product.
- Account page = header (kind, state, subject, since), **tabs:** Overview · Children/Links (parent) or Member (member) · Devices & sessions · Proofing records · Consents · Support cases · Timeline (audit) · Actions.
- Actions are rendered from the permission matrix: T0 execute immediately with reason field where required; T1/T2 open a request card.

### 2.2 Desks (guided checklists — proofing complete by construction)
| Desk | Steps enforced by UI (and re-validated server-side) |
|---|---|
| **Activation desk** | 1 find person → 2 identity evidence type → 3 (parent) tick each child link; links missing → "Send to HR" (T2 card, desk blocked for that child) → 4 legal-restriction checkbox + note → 5 consent version shown/accepted → 6 issue code (QR + text, printable slip, auto-hides after N minutes) → 7 live status "waiting / used ✔ / expired" |
| **Lost-phone desk** | 1 find account → 2 identity check (lighter) → 3 pick device(s) → revoke (T0, immediate) → 4 optional add-device code → 5 confirmation + notice sent |
| **Reset desk** | 1 find → 2 identity evidence → 3 reset code (24 h) → sessions revoked → notice |
| **Closure desk** | user-requested (T0) vs not requested (T1 card) vs legal (T2 card to school admin) |

### 2.3 Approvals inbox (shared component, role-filtered)
- Card = action, product, subject (names + IDs), requester, when, plain-language question, facts panel (pre-filled from SSMS: member/guardian/children/proofing/link status), **Approve / Reject** (reason mandatory on reject), delegation banner if acting on behalf.
- Server enforces maker≠checker, binding to payload hash, single use; timers (48 h reminder) computed lazily; break-glass items pinned on top for `school_admin` with "review by" date (3 days).

### 2.4 View-as (support)
- Button on Account page → reason (mandatory) → opens a **read-only rendering of the user's app views** inside the console (server renders the same data endpoints with the view-as token; no app binary involved) → red banner "Viewing as … — read-only — expires in 14:59" → user notice queued → session listed under "Pending reviews" for a *different* staff member to mark reviewed ≤ 24 h.

### 2.5 Audit explorer
- Filters: date range, stream, event, actor, subject, product, outcome, device, IP, request id; saved views; per-row before/after diff; **export CSV = T1**.
- Integrity page: last digest, chain status, "Verify now" (range), anchor heads; readable by `school_admin`.

### 2.6 Reviews
- **Monthly review page** (`school_admin`): counts by action and product; exceptions (break-glass, unreviewed view-as, hard locks, after-hours admin actions, failed-login bursts); trend vs previous month; "Reviewed" button + comment → `review.monthly.signed` event; PDF/CSV export for the record.
- **Quarterly admin-rights page:** list holders of `accounts_office` / `super_admin`, last ACC activity, per-person Yes/No → `review.access.signed`; removals executed by super_admin as T1.

### 2.7 Policy & roles (super_admin, T1)
- Policy registry editor (D1 §9 parameters) → each change is a T1 request; effective version stamped on subsequent events.
- Role grant/revoke for `accounts_office` → T1; shown in quarterly review.

### 2.8 Reports (T0)
Active accounts by product/grade/branch, pending activations ageing, devices per account, support-case ageing, activation throughput per desk day; export via PhpSpreadsheet (already vendored).

---

## 3. Technical shape

| Layer | Choice (FACT-aligned with repo patterns) |
|---|---|
| Pages | `admin/acc/*.php` rendered within the existing dashboard shell; vanilla JS modules like `family_link.js` (named functions, `json_encode` bridge, no inline handlers — lesson G10) |
| JSON API | `admin/api_acc.php?action=…` (same style as `admin/api_guardians.php`): CSRF on writes, role gate per action, `accSafeError()` (no `$e->getMessage()` leakage) |
| Services | `admin/backend/acc/*` (ACC Core) called by both the console API and the two app APIs |
| Authorization | per-action gate table in code mirroring D1 §4; SoD checks in the service layer, not in UI |
| Idempotency | `Idempotency-Key` for desk actions (reuse `ApiIdempotencyService` pattern) |
| Notifications to staff | existing dashboard notification mechanism (targets hr_dept/super_admin/school_admin as already done for eligibility) |
| Tests | PHPUnit/service tests for SoD and chain; Playwright console E2E for the three desks and approvals (maker≠checker negative test), following `family_link_browser.py` |

---

## 4. UX rules (from the governance research)
One inbox, one card, one question · plain language, no technical vocabulary for school_admin/hr · pre-filled facts · protective actions never wait · counts and exceptions, not raw logs, on the monthly page · English labels, Amharic data as stored.

---

## 5. Console decisions needed
| ID | Question | Recommendation |
|---|---|---|
| C-1 | `accounts_office` gets its own dashboard file (like other roles) or a section inside super-admin? | Own dashboard (`admin/dashboards/accounts-office.php`), consistent with existing per-role pattern |
| C-2 | View-as rendering: server-side "app view" pages in the console vs raw JSON viewer | Server-side app-view pages (support staff see what the parent sees) |
| C-3 | Slip printing: browser print of a minimal page with QR | Yes |
