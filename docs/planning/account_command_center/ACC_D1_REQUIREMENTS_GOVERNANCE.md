# D1 — Account Command Center: Requirements & Governance

**Status:** FROZEN v1.2 (v1.0 accepted; v1.1 §7a identifiers; v1.2 code TTL/conditions per D2‑3 decisions, 2026-10-18) · **Date:** 2026-10-18 · **Programme:** Account Command Center (ACC), step D1 of D0–D7
**Scope:** member-app and parent-app user accounts (staff accounts out of scope, see D0)
**Platform:** Level B — inside SSMS on shared hosting, mature vendored PHP libraries (D0)
**Upstream documents:** `PARENT_APP_FEATURE_BASELINE.md` (locked), `MEMBER_APP_SIGNIN_PROPOSAL.md` (held, design reused), `IMPLEMENTATION_PLAN_GUARDIAN_LINKAGE.md` (delivered Family Link), `PARENT_ACCOUNTS_MANAGEMENT_RESEARCH.md`, `ACCOUNT_COMMAND_CENTER_PLATFORM_RESEARCH.md` (D0 memo), `FOUR_EYES_GOVERNANCE_RESEARCH.md` (decisions)
Labels: **FACT** (repo/decided) · **PROPOSED** (default, change freely) · **UNKNOWN** (needs your answer)
This document defines *what* and *who*. Architecture (*how*) is D2; data/API is D3.

---

## 1. Decision log carried into D1 (FACT)

| ID | Decision |
|---|---|
| D0-1 | Shared hosting only → Level B (PHP libraries + own console). OIDC-shaped design so the engine can be swapped later. |
| D0-2 | Members + parents from day one; staff untouched. |
| D0-3 | Console language English; apps Amharic-first. |
| G-1 | IT operates `accounts_office`; a different IT person / super_admin is the technical checker (maker ≠ checker enforced server-side). |
| G-2 | HR: existing Family Link queue + read-only account status; no account actions. |
| G-3 | School admin: legal/custody removals, monthly review, quarterly admin-rights review; fallback = super_admin break-glass. |
| G-4 | "View as": starts immediately with mandatory reason; second-person review within 24 h; read-only; user notified. |
| G-5 | Break-glass actions reviewed within 3 days. |
| Baseline | Parent §1/§13/§16: in-person activation code, trusted devices, biometric unlock, no e-mail/SMS/phone for core login, settings incl. device management and account closure. Members: separate "FKSS Members" APK, agent-vault in-person activation, device-bound keys, never registered through the app. |

---

## 2. Goals and non-goals

**Goals**
1. One console where every member and parent account is created, activated, supported, locked, recovered, reviewed and closed.
2. Every state change and every authentication event is recorded in an append-only, tamper-evident trail with a professional viewer.
3. Protective actions are instant; granting actions are controlled; nobody non-technical ever faces a technical decision.
4. Zero new infrastructure; compatible with live shared hosting.

**Non-goals (this programme)**
- Staff identity migration; web SSO to third-party systems; e-mail/SMS as mandatory factors; mobile self-registration of any kind (standing rule); replacing Family Link (ACC reads it, HR writes it).

---

## 3. Actors

| Actor | Kind | Description | Source of truth for identity |
|---|---|---|---|
| **Member user** | end user | A registered church-school member using the FKSS Members app | `members` row (HR-registered) |
| **Parent user** | end user | A guardian using the Parent app | `guardians` row + `member_guardians` links (Family Link, HR-stewarded) |
| **`accounts_office`** (NEW) | staff role, IT team | Day-to-day operator of ACC: activation desk, lost-phone desk, resets, support, reports | `users` |
| **`super_admin`** | staff role, IT lead | Everything `accounts_office` has + policy, role grants, audit integrity, break-glass; technical checker | `users` |
| **`school_admin`** | staff role, leadership | Business oversight: custody/legal removals, monthly review, quarterly admin-rights review | `users` |
| **`hr_dept`** | staff role | Family Link / member registry steward; read-only ACC status | `users` |
| **Other staff** (teacher, info_dept, edu_dept, …) | staff roles | No ACC access (teachers see parent names via rosters only) | — |
| **System** | automated | Lockouts, expiries, digests, chain verification | — |

**DECIDED-1:** `info_dept` gets no ACC access (keep the circle small; revisit after launch).

---

## 4. Permission matrix

Tiers: **T0** single operator, immediate, logged · **T1** technical dual control (maker ≠ checker, IT) · **T2** business confirmation (HR fact / school-admin decision) · **R** read.

| # | Capability | accounts_office | super_admin | school_admin | hr_dept | Tier |
|---|---|---|---|---|---|---|
| 1 | Search/view accounts, devices, sessions, timeline | ✔ | ✔ | R (summary) | R (status only) | T0/R |
| 2 | Issue activation code (after proofing record) when the member/guardian link already exists | ✔ | ✔ | – | – | T0 |
| 3 | Issue activation code when the parent↔child link does **not** yet exist | blocked until HR confirms link in Family Link | same | – | decides in Family Link | T2 (HR) |
| 4 | Record proofing (who verified, evidence, children confirmed, consent) | ✔ | ✔ | – | – | T0 |
| 5 | Reset credentials in person (new one-time code, forced new secret, all sessions revoked) | ✔ | ✔ | – | – | T0 |
| 6 | Revoke one device / all devices; force logout | ✔ | ✔ | – | – | T0 |
| 7 | Temporary lock / unlock (security hold) | ✔ | ✔ | – | – | T0 |
| 8 | Suspend account (policy breach, request by user) with reason | ✔ | ✔ | – | – | T0 |
| 9 | Remove/refuse a guardian's access to a child for legal/custody/complaint reasons; reinstate | request | request / break-glass | **decides** | – | T2 (SA) |
| 10 | Close account (user-requested) | ✔ | ✔ | – | – | T0 |
| 11 | Close account **not** requested by user | maker | checker | – | – | T1 |
| 12 | Start "view as" support session (read-only, 15 min, reason) | ✔ | ✔ | – | – | T0 + 24 h second-person review |
| 13 | Review others' "view as" sessions | ✔ (not own) | ✔ (not own) | – | – | review |
| 14 | Grant/revoke `accounts_office`; grant super_admin | maker | checker (≠ maker) | – | – | T1 |
| 15 | Change security policy parameters (§9) | maker | checker | – | – | T1 |
| 16 | Bulk actions (> N accounts/devices) | maker | checker | – | – | T1 |
| 17 | Export audit / personal data | maker | checker | – | – | T1 |
| 18 | Verify audit-chain integrity; view digests | ✔ | ✔ | R | – | T0/R |
| 19 | Monthly review sign-off | – | prepares | **signs** | – | T2 (SA) |
| 20 | Quarterly admin-rights review | – | prepares | **signs** | – | T2 (SA) |
| 21 | Break-glass (proceed without available checker, reason mandatory, flagged) | – | ✔ | reviews ≤ 3 days | – | exception |
| 22 | Consent version management, re-consent prompt | – | ✔ | – | – | T1 |
| 23 | Support cases: open, note, close | ✔ | ✔ | – | – | T0 |

**DECIDED-2:** "bulk" = ≥ 10 accounts or ≥ 20 devices in one action.

---

## 5. Separation-of-duties rules (hard, server-enforced)

| Rule | Statement |
|---|---|
| SoD-1 | The user who creates a T1 request cannot approve it (identity compared on the server, not in UI). |
| SoD-2 | A staff member cannot perform any ACC action on an account linked to themselves or to a member/guardian record they are personally related to (where detectable via `member_id` on `users` / Family Link). Attempt is refused and logged. |
| SoD-3 | Nobody can review their own "view as" session or their own break-glass action. |
| SoD-4 | `hr_dept` cannot issue codes, reset, lock, revoke or close; `accounts_office` cannot create or change guardian↔child links (Family Link remains HR-only). |
| SoD-5 | No staff role can read a user's secret, token or activation code after issue (codes are shown once at issue time, stored hashed). |
| SoD-6 | Audit records cannot be updated or deleted by any application path; integrity is verifiable by `school_admin` without IT help (one button). |
| SoD-7 | Approvals are bound to one request id and payload; a changed request invalidates the approval. |
| SoD-8 | Shared accounts are forbidden in ACC; every staff action is attributable to a named `users` row. |

---

## 6. Account lifecycle (state machines)

### 6.1 Parent and member accounts (same machine; differences noted)

```
                 ┌──────────────┐  code issued   ┌────────────────────┐  code used + secret set  ┌────────┐
 (no account) ──▶│  PROVISIONED │───────────────▶│ PENDING_ACTIVATION │────────────────────────▶│ ACTIVE │
                 └──────────────┘                └────────────────────┘                          └───┬────┘
                        ▲                            │ code expired/revoked → back to PROVISIONED      │
                        │                                                                           │
   lock cleared / timer │        ┌────────┐  auto: failed-attempt threshold / staff: security hold   │
                        └────────│ LOCKED │◀───────────────────────────────────────────────────────┤
                                 └────────┘                                                         │
                                 ┌───────────┐  staff: suspend (reason) / SA: access removed        │
                                 │ SUSPENDED │◀───────────────────────────────────────────────────┤
                                 └─────┬─────┘  staff: reinstate (T0) or SA (if legal)             │
                                       │                                                            │
                                 ┌─────▼─────┐  user request (T0) / not requested (T1) / legal (T2)
                                 │  CLOSED   │◀───────────────────────────────────────────────────┘
                                 └───────────┘  terminal: data retained per §10, no login, record never deleted
```

| State | Meaning | Can log in | Entered by |
|---|---|---|---|
| PROVISIONED | Account record exists, bound to a `members`/`guardians` row; no credential | No | `accounts_office` creates from registry (never from the app) |
| PENDING_ACTIVATION | One-time activation code issued after proofing; waits for the person to complete setup in the app within code TTL | No (activation only) | Issue code (T0; T2 first if parent link missing) |
| ACTIVE | Credential + ≥ 1 enrolled device | Yes | Activation completed |
| LOCKED | Temporary security state (auto after failed attempts, or staff hold); auto-clears or staff clears | No | System / `accounts_office` |
| SUSPENDED | Deliberate hold with reason (user request, policy breach, legal removal) | No | Staff (T0) / school admin (T2) |
| CLOSED | Terminal; identity retained for audit, personal data minimised per retention | No | T0 / T1 / T2 as above |

**Member vs parent differences:** a parent account additionally carries *link status* per child (from Family Link): `linked / pending_hr / removed_by_sa`. A parent with zero linked children may be ACTIVE but sees no child data (app shows "no children linked — visit the office"). A member account is bound 1:1 to a `members` row; a parent account is bound 1:1 to a `guardians` row. One person may hold both (same human, two apps) — **DECIDED-3:** two separate accounts in phase 1, linked by `guardians.member_id` when it exists; merge is a possible later phase.

### 6.2 Device lifecycle

```
 ENROLLING (activation or add-device code) ──▶ ACTIVE ──▶ REVOKED (user, staff, or policy)
                                                  │
                                                  └──▶ STALE (no use for N days → requires re-unlock with full secret; auto-revoke after M days)
```
- Max active devices per account: 3 (DECIDED-4).
- Adding a device: from an existing ACTIVE device (self-service, biometric + secret) **or** at the desk with an add-device code (T0). Never by remote code sent over e-mail/SMS.
- Lost/stolen/broken phone: `accounts_office` revokes that device (T0) → all its sessions die immediately (`authorization_version` bump + session family revoke); user re-enrols a new phone with a desk-issued code; event + in-app notice on remaining devices.
- A user may revoke their own devices from the app settings (baseline §13); staff see it in the timeline.

### 6.3 Session lifecycle
Access token short-lived; refresh token rotated per use in a *family*; reuse of an old refresh token revokes the whole family (pattern already in `api_refresh_sessions`, FACT). Every session belongs to exactly one device.

---

## 7. Credentials, activation and recovery policy

| Topic | Requirement |
|---|---|
| Core login | Device-bound key (WebAuthn/passkey library) unlocked by biometric or device PIN + a memorised secret (PIN/password) created at activation. No e-mail/SMS/phone number required (baseline §16). |
| Activation | In person only. Steps: (1) identity verified by `accounts_office` against registry + ID evidence; (2) for parents, children confirmed against Family Link; (3) consent recorded; (4) **proofing record** saved; (5) one-time code issued (shown once / printed slip), TTL §9; (6) user completes setup in app: code → device key enrol → secret → done. |
| Reset (forgotten secret) | In person only, same proofing; new one‑time code (24 h); **all existing sessions revoked**; user notified in‑app on other devices when possible and on next login. Staff never see or set the secret. |
| Lost all devices | Treated as reset + new device enrolment. |
| Optional recovery channels | Verified e-mail/Telegram may be registered *inside a trusted session* for *notifications and step-up*, never as the sole recovery path (members proposal §8; FACT accepted). |
| Code security | Codes are CSPRNG‑random, ≥ 8 chars of unambiguous alphabet (or QR + short code), single‑use, hashed at rest, bound to the account and purpose, invalidated on first use, on expiry **or when a newer code of the same purpose is issued**, rate‑limited on entry. First‑activation codes: 30 min, consumed at the office. Add‑device/reset codes: 24 h. |
| Secret policy | 6-digit PIN + device key/biometric (DECIDED-5); the PIN alone never logs in from a new device. |

### 7a. Identifiers — no separate parent ID (owner decision, v1.1)

| Rule | Statement |
|---|---|
| ID-1 | There is **no separate parent ID number or card**. The *member ID of any linked child* is what a parent types in the Parent app. Members type their own member ID in the Members app. One ID family for the whole school. |
| ID-2 | The member ID is a **lookup key, never a credential**. It is printed on cards and known to teachers and classmates, so it must carry zero secrecy value. Security comes only from the in-person one-time code, the device-bound key, and the PIN. |
| ID-3 | A parent account is **owned by the guardian record** (`guardians.id`), not by the child. The ID the parent typed is not stored as the account's identity; it only locates the family at activation / new-device enrolment. Internally every account has a stable opaque id (UUID, the OIDC `sub`). |
| ID-4 | The one-time code is bound to **one specific guardian**. Mother and father may both type the same child ID; the code decides which guardian account is being activated. The app never asks "which parent are you?". |
| ID-5 | Access to siblings comes **only** from Family Link (`member_guardians`), never from the typed ID, name similarity or phone. Logging in via one child shows every child HR has linked to that guardian — and nothing else. |
| ID-6 | If the child whose ID was used leaves, graduates, or has the guardian's access removed by the school admin, the guardian account is unaffected; at the next device enrolment the parent types any *still-linked* child's ID. A guardian with zero linked children stays ACTIVE but sees "no children linked — visit the office". |
| ID-7 | On an already-enrolled device no ID is typed at all (device key + biometric/PIN). The ID is needed only at activation and new-device enrolment, always together with a desk-issued code. |
| ID-8 | Anti-enumeration: activation/enrolment responses never reveal whether an ID exists, how many guardians it has, or their names. Rate-limited per device and per ID. |
| ID-9 | Desk slip / support language: "Your access: child ID FK-M-… + code …" — staff look parents up by child ID, guardian name, or phone; the console shows the guardian's children and the per-child link status. |
| ID-10 | Presentation mockups used a placeholder parent code (FK-P-…); that is cosmetic and will be replaced by the child-ID wording when the deck is next regenerated. Baseline §1 is unchanged (it never required a separate parent ID). |

Industry note: this is the PowerSchool pattern (a per-student *Access ID* links a child to a parent account; the parent account itself is a separate identity). PowerSchool adds an *Access Password* because activation is self-service; FKSS replaces that secret with the in-person code, which is stronger.

---

## 8. Approvals workflow requirements (T1 / T2)

1. **Approvals inbox** on the dashboard of each approving role (super_admin, school_admin, hr_dept via Family Link). Badge count; no e-mail one-click approvals.
2. **Card format:** one request, requester, plain-language question, pre-filled facts from SSMS (member/child/guardian/proofing), Yes / No, reason (mandatory on No), timestamp and channel recorded.
3. **Routing table (versioned)** maps action → approving role(s) → fallback → escalation time. Rule version stored on each request.
4. **Fallback & escalation:** T1 → any other super_admin; T2 (SA) → super_admin break-glass; escalation reminder after 48 h; **no auto-approval on timeout**.
5. **Delegation:** school_admin may delegate for a dated period to a named user; recorded "on behalf of".
6. **Break-glass:** super_admin proceeds with mandatory reason; action flagged; appears at top of school_admin inbox; must be reviewed ≤ 3 days (G-5); unreviewed items are highlighted in the monthly review.
7. **Binding:** approval references request id + payload hash; single use; executing the action re-checks that conditions still hold.
8. **Evidence:** every request produces audit events `approval.requested / approved / rejected / escalated / delegated / breakglass`.

---

## 9. Policy parameters (PROPOSED defaults — confirm or change; all T1-changeable later)

| Parameter | Default | Note |
|---|---|---|
| Failed secret attempts before soft lock | 5 → 15 min | per account + per device |
| Failed attempts before hard lock (staff needed) | 10 | event + alert |
| First‑activation code TTL | **30 min**, single use, consumed at the office during in‑person verification (D2‑3a) | revocable |
| Reset / add‑device code TTL | 24 h, single use; CSPRNG; stored hashed; rate‑limited; invalidated on use or when replaced; PIN reset revokes all sessions + in‑app notice (D2‑3b) | |
| Access token lifetime | 15 min | |
| Refresh token: sliding / absolute | 30 days / 180 days | re-unlock with secret after absolute |
| Biometric re-prompt | every app open after 5 min background | baseline §1 |
| Device STALE after / auto-revoke after | 60 days / 180 days | |
| Max active devices | 3 | DECIDED-4 |
| "View as" session TTL | 15 min, non-extendable | review ≤ 24 h |
| Approval escalation reminder | 48 h | |
| Break-glass review | ≤ 3 days | G-5 |
| Monthly review due | by the 7th of next month | |
| Quarterly admin-rights review | first week of each quarter | |
| Audit retention online / archive | 24 months / then archive table + export | never purge while account exists |
| Proofing records retention | life of account + 5 years | DECIDED-6 |
| Closed-account personal data minimisation | 12 months after closure | keep ids + audit |

---

## 10. Tracking, monitoring and reporting requirements

1. **Two streams, one table:** `user` events (authentication, device, consent, self-service) and `admin` events (every staff action, with before/after). Fixed vocabulary (see `PARENT_ACCOUNTS_MANAGEMENT_RESEARCH.md` §4.3); correlation id per request; UTC timestamps; never log secrets/codes/tokens.
2. **Tamper evidence:** append-only table (triggers reject UPDATE/DELETE), `prev_hash`/`row_hash` chain, daily digest row, digest head exported outside the table (`activity_logs` + downloadable); **one-button "Verify integrity"** usable by school_admin.
3. **Viewer:** per-account timeline; global explorer with filters (actor, event, outcome, date, device, IP); saved views ("today's failures", "all T1 approvals", "break-glass pending"); CSV export (T1).
4. **Alerts (in-dashboard, no external services):** failed-login bursts; hard locks; break-glass used; "view as" unreviewed > 24 h; approvals > 48 h; activation codes expired unused > X; admin actions outside office hours; chain verification failure.
5. **Monthly review page (school_admin):** counts (activations, resets, device revocations, locks, suspensions, closures, view-as sessions, T1 approvals, break-glass), exceptions list, trend vs. previous month, **"Reviewed"** button with optional comment → audit event.
6. **Quarterly admin-rights page:** list of users holding `accounts_office` / `super_admin`, last activity, Yes/No per person → audit event; removals executed by super_admin (T1).
7. **Operational reports (T0):** active accounts by kind/grade, pending activations, devices per account distribution, support-case ageing.
8. **Retention jobs without cron:** triggered on dashboard load by super_admin / on demand (shared-hosting constraint), idempotent.

---

## 11. Support requirements

- **Support case** object: account, opener, category (activation / lost phone / forgotten secret / app issue / data correction / closure / other), notes, status, linked audit events.
- **"View as"** (G-4): read-only token scoped to the account, 15 min, visible banner in console, write endpoints refuse it centrally, user receives an in-app notice ("Staff member viewed your account on … for support"), session listed for second-person review within 24 h.
- **Desk scripts** built into the UI (checklists) for activation, lost phone, reset — so that the proofing record is complete by construction.
- Staff never see secrets; staff never type the user's secret; codes shown once.

---

## 12. Non-functional requirements

| Area | Requirement |
|---|---|
| Hosting | PHP 8.x + MariaDB on shared hosting; no daemons, no cron dependency, no C extensions beyond those present (verify openssl/sodium/mbstring availability — D2 task) |
| Libraries | Vendored, pinned, licence-listed (`league/oauth2-server`, OIDC layer, WebAuthn lib, `symfony/rate-limiter`, `otphp`, `monolog`) |
| Language | Console English; stored names/addresses may be Amharic; apps Amharic-first |
| Security | CSRF on all console writes; all ACC endpoints behind role gates; secrets hashed (argon2id where available); codes/tokens hashed; `test_error_disclosure` rule respected |
| Performance | Console pages < 1 s for 10k accounts; audit explorer paginated; indexes on actor/subject/time |
| Availability | Degraded-mode: apps keep cached data offline (baseline §15); ACC itself needs only SSMS up |
| Testability | E2E suites for activation, lost phone, reset, approvals (maker ≠ checker), chain verification; contract tests for app APIs |
| Compatibility | Zero change to staff login, staff app JWT flow, Family Link behaviour |

---

## 13. Out of scope / deferred
Staff accounts; third-party SSO; push-provider choice (baseline Appendix B-5, decided in parent-app planning); automated custody-document handling; parent/member self-service registration (never).

---

## 14. Open items — RESOLVED (all proposed defaults accepted 2026-10-18)

| # | Question | Decision |
|---|---|---|
| 1 | `info_dept` read-only status lookup? | No |
| 2 | Bulk threshold N | ≥ 10 accounts / ≥ 20 devices |
| 3 | One human in both apps: one identity or two accounts? | Two accounts in phase 1, linked by `guardians.member_id` |
| 4 | Max active devices per account | 3 |
| 5 | App secret type | 6-digit PIN + device key (biometric) |
| 6 | Proofing record retention | Life of account + 5 years |
| 7 | Who are the named initial holders of `accounts_office` (count only, not names here) | 2–3 IT staff |

---

## 15. D1 acceptance → D2 entry criteria
D1 is FROZEN: §14 answered, §4/§5/§6/§9 accepted. Changes from here go through the change log below.

**Change log:** v1.0 2026-10-18 — frozen. · v1.1 2026-10-18 — added §7a identifier rule (child member ID as parent lookup key; account owned by guardian; no separate parent ID), per owner decision. · v1.2 2026-10-18 — D2‑3 decisions: first‑activation code 30 min at office; add‑device/reset 24 h with single‑use/hash/rate‑limit/replace‑invalidation; PIN reset revokes sessions + notifies (see `ACC_D2_DECISION_RECORD_AND_RISKS.md`). D2 (Architecture) will then cover: component diagram, library versions + licence list + host-extension check, token/key design (OIDC-shaped), trust boundaries, degraded modes, and migration-safety analysis against the current staff auth and Family Link.
