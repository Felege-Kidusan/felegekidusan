# Parent-App Users: Who Manages Them, How Everything Is Tracked, and Whether a Dedicated Console Is Needed

**Type:** research + recommendation (no implementation). **Date:** 2026-10-18.
**Inputs:** SSMS repository (read-only), industry practice (sources at the end), the locked Parent App baseline (`PARENT_APP_FEATURE_BASELINE.md` §1, §13, §16).
Labels: **FACT** = verified in repo or cited source · **INFERENCE** = my reading · **UNKNOWN** = owner decision needed.

---

## 0. Short answer

1. **Yes — you need a dedicated user-management capability, and it should be its own console with its own role.** Large organisations treat external users (customers, citizens, parents) as a separate discipline — *Customer IAM* — from staff accounts, with its own admin console, lifecycle rules, consent handling and audit trail. Gartner defines CIAM tools as those that "manage identity, authentication and authorization for external identity use cases", including self-service, identity repositories, reporting and analytics, and APIs/SDKs for mobile apps [15](https://translate.google.com/translate?u=https%3A%2F%2Fwww.gartner.com%2Fen%2Finformation-technology%2Fglossary%2Fcustomer-identity-access-management-ciam&hl=es&sl=en&tl=es&client=srp).
2. **No — it should not be a separate application.** It should be a new **"Family Accounts" console inside the existing SSMS admin** (own sidebar/dashboard, own role, reusing the admin shell, CSRF, sessions, `activity_logs` conventions and the Family Link tables). Buying/hosting a product such as Keycloak is ruled out by the shared-hosting constraint (Java server process), and a separate app would create a second source of truth — which the baseline's own rule 19.6 forbids.
3. **Tracking is a design property, not a feature.** Every lifecycle action (activation, reset, suspension, device revocation, guardian-link change, staff "view-as") and every authentication event must land in an **append-only, hash-chained audit table** with a viewer, filters and export. This is standard (NIST AU-9, OWASP logging) and is feasible in pure PHP + MariaDB.

The rest of this document explains why, what the console must contain, who holds which responsibility, and what still needs your decision.

---

## 1. What SSMS has today (FACT, from the repository)

| Area | Current state | Gap for parents |
|---|---|---|
| Staff identities | `users` table: `username, email, full_name, role (varchar), password_hash, is_active, authorization_version, last_login, member_id, profile_image_path` | Parents are a different population; mixing them into `users` would give them a staff-shaped record and staff login code paths |
| Staff user admin | `admin/users.php` — super_admin-only CRUD page (create/edit/delete, role, active flag) | No lifecycle states, no activation codes, no device view, no per-account audit timeline |
| Audit | `activity_logs` (`user_id, username, action, details, entity_type, entity_id, ip_address, user_agent, created_at`) + `activity_logs_archive`; module-specific audit tables (`hr_attendance_audit`, `mezmur_attendance_audit`, `wbws_audit_log`); Guardian actions logged via `GuardianService::audit` | Mutable table, no integrity protection, no central viewer beyond "Recent Activity" (20 rows) and a basic logs section in super-admin; actions are free-text |
| Mobile sessions (staff app) | `api_refresh_sessions`: rotation families, `token_hash`, `revoked_at`, `created_ip`, `user_agent_hash`, `last_used_at`; `authorization_version` on `users` for live revalidation | Good pattern to reuse; nothing parent-facing yet; no "device" concept with a human name |
| Login protection | Web login throttles (429 after repeated failures) | No account lockout policy object, no admin-visible failed-attempt history |
| Parent ↔ child truth | Family Link (`guardians`, `member_guardians` with relation/primary, aliases, review queue, HR UI) — delivered | A guardian is a **record**, not an **account**: no credentials, no devices, no consent, no state |
| Roles in code | super_admin, school_admin, hr_dept, info_dept, edu_dept, teacher, finance_dept, material_dept, mezmur_dept (+ sub-flags) | No role whose job is "accounts office"; today only super_admin can touch identities |

**Conclusion (INFERENCE):** the building blocks exist (sessions-with-revocation, authorization versioning, audit helper, Family Link), but there is no identity *module* and no console for it.

---

## 2. How large organisations handle this

### 2.1 Two populations, two systems
Every major vendor and analyst splits identity into **workforce IAM** (employees; admin-provisioned, least privilege, SoD, audit evidence) and **CIAM** (external people; lifecycle starts outside HR, consent and privacy obligations apply by law, self-service matters) [2](https://startwithidentity.com/articles/ciam-vs-iam/) [8](https://www.openiam.com/customer-identity-concepts/ciam-vs-workforce-identity) [11](https://www.ibm.com/think/topics/ciam). They are "separate but coordinated": same standards and governance, different consoles and rules [8](https://www.openiam.com/customer-identity-concepts/ciam-vs-workforce-identity).
**Mapping to FKSS:** staff = workforce (existing `users`), parents = CIAM. One important twist: your parents are *not* self-registered strangers — they are **enrolled in person** (baseline §1). That makes the parent population closer to a "citizen" CIAM model with strong identity proofing, which is the most favourable case for security.

### 2.2 What a dedicated identity console contains (reference: Keycloak, Entra, Okta, Google)
Keycloak's admin console — the most widely used open-source identity server — centralises: users, roles and mappings; view/terminate **sessions** per user; **required actions** (force password change); **impersonation for support**; and two audit streams: **user events** (login success/failure, logout, password change, consent granted/revoked, account disabled) and **admin events** (every create/update/delete done by an administrator, including impersonation), each with its own retention [1](https://www.keycloak.org/docs/latest/server_admin/index.html) [2](https://documentation.cloud-iam.com/resources/events.html) [3](https://skycloak.io/blog/what-is-keycloak-complete-guide/). That two-stream model (what *users* did vs what *admins* did to users) is the pattern to copy.
Microsoft Entra likewise keeps **sign-in logs** separate from **audit logs** (directory changes) [1](https://learn.microsoft.com/en-us/entra/identity/monitoring-health/reference-reports-data-retention); Okta exposes one System Log that administrators browse, filter and export, and recommends giving log viewers the *lowest* role that can read logs [8](https://help.okta.com/oie/en-us/content/topics/privileged-access/pam-audit-events-with-okta-syslog.htm).

### 2.3 Lifecycle, least privilege, separation of duties
Identity lifecycle is managed as **joiner → mover → leaver**: create with minimum access, adjust when the relationship changes, disable immediately on exit while *retaining the audit trail* [2](https://newcore.com/identity-101/identity-and-access-management/identity-lifecycle-management-joiner-mover-leaver) [17](https://www.idmanagement.gov/playbooks/ilm/). NIST SP 800-53 requires **AC-5 separation of duties** (e.g., people who administer access control should not also administer audit functions) and **AC-6 least privilege**, with AC-6(9) requiring that use of privileged functions be logged [10](https://csrc.nist.gov/files/pubs/sp/800/53/r5/ipd/docs/sp800-53r5-draft-controls-markup.pdf) [19](https://www.miniorange.com/blog/nist-privileged-access-management/).
**Mapping to FKSS:** joiner = in-person activation; mover = child changes class / a second guardian is approved / relation corrected; leaver = guardian relationship ends, child graduates, account closed. Each must be an explicit, logged state transition — not a row delete.

### 2.4 In-person enrolment is a recognised assurance model, not a workaround
NIST SP 800-63A defines identity assurance levels; IAL2 allows in-person proofing and defines the roles of **proofing agent** (vetted staff who attend proofing sessions and make limited risk decisions) and **trusted referee** (trained staff who handle exceptions, e.g., applicants lacking standard evidence). NIST requires the CSP to **record every proofing session that involves a referee**: why, who the referee was, what evidence was presented, which steps were completed and the decision [3](https://pages.nist.gov/800-63-4/sp800-63a/ial-general/) [4](https://pages.nist.gov/800-63-4/sp800-63a/proofing/). Help-desk guidance converges on the same point: recovery must be as strong as the login it replaces; verify with evidence the caller cannot research; treat resets as privileged actions with a second approver where risk is high; log every reset to an immutable trail [1](https://nhimg.org/account-recovery-help-desk-security-guide) [8](https://credentialgovernance.avatier.com/en/blog/password-reset-questions-best-practices-2026). NIST 800-63B adds that account recovery **shall** notify the subscriber and that a subscriber who loses all authenticators repeats identity proofing [2](https://pages.nist.gov/800-63-4/sp800-63b.html) [17](https://samiii-xyz.github.io/800-63-4/sp800-63b.html).
**Mapping to FKSS:** the registration-office staff member *is* the proofing agent. The console must therefore capture a **proofing record** for every activation and reset (who verified, what ID/evidence, which child links were confirmed, outcome) — this is the single most important tracking requirement.

### 2.5 How schools specifically do it
- **PowerSchool**: the school issues per-student *Access ID + Access Password* that link a child to a parent account; the site "must verify that the person has no legal restrictions to access student data before issuing the access information", may hand it over in person, and administrators can create the parent account and link students from the student's "Guardian and Student Account Access" page [3](https://cdnsm5-ss18.sharpschool.com/UserFiles/Servers/Server_27969467/File/IT%20Resources/PowerSchool/PowerSchool%20Handbooks%20and%20Job%20Aids/Parent%20and%20Student%20Portal/Parent_Student_Portal_Administration_HB_0.pdf) [5](https://ps.powerschool-docs.com/pssis-admin/latest/ids-and-passwords). Parent credentials and link credentials are deliberately **separate** [11](https://help.powerschool.com/t5/District-Code-and-Login/How-do-I-log-into-PowerSchool-How-can-I-find-my-account/ta-p/6091).
- **Google Classroom**: the domain administrator decides whether guardian access is on at all and whether *only administrators* or also *verified teachers* may invite/remove guardians; guardians see summaries, not the class itself [1](https://support.google.com/edu/classroom/answer/7017326?hl=en) [7](https://workspaceupdates.googleblog.com/2024/07/enable-guardians-to-preview-assigned-classwork-google-calendar.html).
**Mapping to FKSS:** both confirm the model in your baseline — the school issues the link credential, verifies legal standing first, and a central admin setting decides who may grant guardian access. Family Link already is your "Guardian and Student Account Access" page; the accounts console sits on top of it.

### 2.6 Support access ("view as parent")
Okta provides **read-only impersonation** for support and retains every impersonation event in the System Log [9](https://support.okta.com/help/s/article/read-only-impersonation-access-system-logs-audit-events?language=en_US). Good designs make it a separately grantable permission, short-lived, read-only enforced at one choke point, visibly bannered, audited, and **the user is notified** [2](https://github.com/Neurowealth/Backend/issues/548) [1](https://rishandigital.com/power-pages/user-impersonation-for-testing/).

### 2.7 What must be logged, and how it must be protected
OWASP: always log authentication successes **and** failures, authorization failures, session events, all actions by administrators (especially on other users), use of break-glass accounts, access to sensitive data, data export, and uploads; each entry needs who, what, when, where, outcome, and a correlation id [1](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) [4](https://checksy.app/library/owasp-logging-and-monitoring) [12](https://axonbuild.com/blog/how-can-you-prevent-insufficient-logging-and-monitoring). OWASP's logging vocabulary gives ready event names (`authn_login_success`, `authn_login_fail_max`, `authn_token_revoked`, …) [2](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Vocabulary_Cheat_Sheet.html).
NIST AU-9 requires protecting audit information from modification/deletion and alerting on tampering; AU-9(3) adds cryptographic integrity [1](https://csf.tools/reference/nist-sp-800-53/r5/au/au-9/) [8](https://nist-sp-800-53-r5.bsafes.com/docs/3-3-audit-and-accountability/au-9-protection-of-audit-information/). The practical pattern: an **append-only table** (INSERT/SELECT only; triggers reject UPDATE/DELETE), each row storing `prev_hash` and `row_hash`, with periodic digests anchored outside the database [9](https://www.techinterview.org/post/3233468938/lld-audit-logging/) [12](https://spike-r.com/threshold/specs/append-only-audit-log). Hash chaining is tamper-*evident*, not tamper-proof, which is why the chain head is exported/anchored elsewhere [12](https://spike-r.com/threshold/specs/append-only-audit-log).

### 2.8 Retention benchmarks
Entra keeps sign-in/audit logs 7 days (free) or 30 days (P1/P2) and expects you to export for longer [1](https://learn.microsoft.com/en-us/entra/identity/monitoring-health/reference-reports-data-retention); Okta keeps the System Log 90 days [3](https://support.okta.com/help/s/article/Exporting-Okta-Log-Data?language=en_US); Google Workspace keeps admin/user log events 6 months [10](https://knowledge.workspace.google.com/admin/reports/data-retention-and-lag-times); compliance regimes commonly demand 1 year (PCI) or more [12](https://www.manageengine.com/products/active-directory-audit/kb/microsoft-entra-id/entra-id-monitoring.html). Since SSMS owns its database, it can keep more than SaaS defaults; the real limit is shared-hosting disk.

---

## 3. Build vs. buy under the shared-hosting constraint

| Option | Verdict | Why |
|---|---|---|
| Keycloak / Authentik / Ory / Zitadel (self-hosted identity servers) | **Not feasible** | Long-running Java/Go/Node services; shared hosting runs PHP only (standing constraint) |
| Hosted CIAM (Auth0, Firebase Auth, Entra External ID, Cognito) | **Not recommended** | Monthly cost in foreign currency, external dependency for *every* parent login, e-mail/phone-centric flows (your parents may have neither), data residency of family data outside the school, and a second identity store contradicting baseline 19.6 |
| **Embedded identity module in SSMS (PHP + MariaDB)** | **Recommended** | Reuses proven in-repo patterns (`api_refresh_sessions` rotation families, `authorization_version`, CSRF, role gates, audit helper), keeps one source of truth, zero new infrastructure; design copied from Keycloak's console, not its runtime |

**INFERENCE:** the engineering risk of "build" is contained because the authentication *cryptography* was already designed in `MEMBER_APP_SIGNIN_PROPOSAL.md` (device-bound keys, DPoP, agent-vault activation); what is new is the *management* layer, which is CRUD + state machine + audit — well within PHP.

---

## 4. Recommended design — the **Family Accounts** console

### 4.1 Where it lives and who can open it
A new admin section/dashboard `Family Accounts` (Amharic: *የቤተሰብ መለያዎች*) inside SSMS, visible to:

| Role | Access | Rationale |
|---|---|---|
| **`accounts_office`** (NEW role — the registration-office staff who do in-person activation) | Full operational: activate, reset, suspend, revoke devices, record proofing, view audit for accounts they handle | This is the proofing agent / help desk (NIST 800-63A roles). Should *not* be the same people who hold `super_admin` |
| **`super_admin`** | Everything + policy settings + role grants + full audit + integrity verification | Owner of the system |
| **`school_admin`** | Read all; approve high-risk changes (second guardian, relation change, account closure, re-linking a child to a different parent) | Four-eyes on actions that change *who may see a child* |
| **`hr_dept`** | No account operations; continues to own Family Link data (guardian records, aliases, merges) | Keeps data stewardship separate from access administration (AC-5) |
| `info_dept`, `teacher`, others | None (teachers see parent names via class rosters only) | Least privilege |

**Why a dedicated role instead of "super_admin does it":** activation and resets will happen every Sunday at a desk; the person doing it must not carry super_admin power, and super_admin must be able to audit them. That is exactly AC-5.

### 4.2 Modules the console must have
1. **Directory & search** — parents by name/phone/ID, by child, by status; list shows status, children count, last login, devices, open issues.
2. **Account lifecycle (state machine)** — `invited → pending_activation → active → suspended → closed` (+ `locked` as a transient security state). Every transition: reason code, actor, timestamp, free-text note; closure keeps the record and the audit trail (leaver rule), never deletes.
3. **Activation ceremony** — generate one-time activation code (short expiry, e.g. 72 h, single use, revocable), print/hand-over slip, **proofing record** (staff who verified, evidence type seen, children confirmed, consent signed), and status "code issued / used / expired".
4. **Children & guardian links** — view from Family Link; approve/deny *link requests*; revoke link when a relationship changes; show "who can see this child" from the child's side. Relation data itself stays in Family Link (single source of truth).
5. **Credentials** — in-person reset = new one-time code + forced password creation + **all sessions revoked + notification**; never shows or sets the parent's password (least privilege for the agent).
6. **Devices & sessions** — human-named devices (model, first seen, last used, city/IP), revoke one or all, bump `authorization_version`; show the parent's own revocations too.
7. **Consent & privacy** — consent version accepted, when, by which channel; re-consent prompt on policy change; data-export/closure requests queue (baseline §13).
8. **Support tools** — read-only **"view as parent"**: separate permission, 15-minute TTL, visible banner, writes blocked centrally, parent notified in-app, full audit.
9. **Audit log viewer** — unified timeline per account and global; filters by actor/event/outcome/date; CSV export; integrity-check button that re-walks the hash chain; alerts (failed-login bursts, impersonation used, bulk revocations, activation codes issued but unused).
10. **Policy & KPIs** — lockout thresholds, session/refresh lifetimes, code expiry, quiet-hours default; KPI strip (active parents, pending activations, suspended, devices revoked this month, failed logins today).

### 4.3 Tracking: the event model ("tracking everything" made concrete)
Two streams, as in Keycloak: **parent events** (what the parent/app did) and **admin events** (what staff did to a parent). One table, one shape:

| Field | Note |
|---|---|
| `seq` (auto) · `occurred_at` (UTC) | monotonic; gaps are suspicious |
| `stream` | `parent` / `admin` / `system` |
| `event` | from a fixed vocabulary (below) |
| `actor_type` · `actor_id` · `actor_label` | parent / staff / system |
| `subject_type` · `subject_id` | the parent account, device, link, child |
| `outcome` · `reason` | success / failure / denied + machine code |
| `ip` · `user_agent_hash` · `device_id` · `request_id` | correlation |
| `before` · `after` (JSON, PII-minimised) | for admin changes |
| `prev_hash` · `row_hash` | SHA-256 chain |

Minimum vocabulary (OWASP-style): `authn.login.success`, `authn.login.fail`, `authn.login.fail_max → account.locked`, `authn.biometric.unlock`, `authn.token.refreshed/revoked`, `account.invited/activated/suspended/reactivated/closed`, `account.code.issued/used/expired/revoked`, `account.password.reset_by_staff/changed_by_user`, `account.proofing.recorded`, `device.registered/renamed/revoked`, `link.requested/approved/denied/revoked`, `consent.granted/withdrawn`, `support.view_as.start/end`, `admin.role.granted/revoked`, `data.export.requested/delivered`, `access.sensitive.read` (report-card photo, feedback), `audit.verify.ok/fail`.

Protection on shared hosting (all pure MariaDB/PHP): BEFORE UPDATE/DELETE triggers that `SIGNAL` an error on the audit table; application code has no update/delete path; nightly PHP job (or on-demand button) recomputes the chain and writes a daily digest row; digest head also written to `activity_logs` and e-mailed/exported so a full-chain rewrite is detectable (anchoring). Retention proposal: **24 months online, then archive table + export**, never purge admin events for an account that still exists. (These numbers are a proposal — see §6.)

### 4.4 Data model sketch (names indicative; no schema change proposed yet)
`parent_accounts` (id, guardian_id FK → guardians, parent_code, status, password_hash, authorization_version, locale, created_by, activated_at, suspended_at, closed_at) · `parent_activation_codes` (code_hash, purpose activate/reset, issued_by, expires_at, used_at, proofing_record_id) · `parent_proofing_records` (staff_id, evidence_type, children_confirmed JSON, consent_version, note) · `parent_devices` (name, platform, public_key, first_seen, last_seen, revoked_at) · `parent_sessions` (clone of `api_refresh_sessions` with device_id) · `parent_consents` · `parent_support_sessions` · `parent_account_events` (audit, §4.3). Parent ↔ child authority remains **`member_guardians`** — the console reads it, Family Link writes it.

---

## 5. So: a new dedicated dashboard — yes or no?

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| A. Add a tab to super-admin only | Fastest | Only super_admin can operate it; violates SoD; Sunday desk staff would need super_admin | No |
| B. Extend HR's Family Link section | Data already there | Mixes data stewardship (HR) with access administration; HR would hold reset/suspend power over every parent | No |
| **C. Dedicated "Family Accounts" console + `accounts_office` role inside SSMS** | Clean SoD, least privilege, one source of truth, reuses shell/auth/CSRF/audit, visible from super_admin too | One more dashboard to maintain | **Yes — recommended** |
| D. Separate admin application | Isolation | Second login, second session system, second audit trail, duplicated roles, more hosting surface | No |

---

## 6. Decisions needed from you (UNKNOWN — not assumed)
1. **Who staffs `accounts_office`?** Registration-office members only, or also the HR department head? (Recommendation: a named small group, never the super_admin account.)
2. **Four-eyes scope:** which actions require a second approver — closing an account, approving a *second* guardian, re-linking a child to a different parent, "view as parent"? (Recommendation: all four.)
3. **Retention:** 24 months online for events acceptable? Proofing records kept for the life of the account + N years?
4. **Notifications to parents** on staff actions (reset, device revoked, view-as) — in-app only (no e-mail/SMS available) is acceptable?
5. **Legal check before issuing access** (PowerSchool rule): who confirms there is no custody/legal restriction, and is that captured in the proofing record as a checkbox + note?
6. **Parent identifier format** (e.g., `FK-P-2019-0148` as shown in the mockups) — confirm or change.

Once these are answered, the next step under the project protocol is an implementation plan (schema, API, console UI, tests) for approval — not code.

---

## Sources
- Gartner CIAM definition (glossary mirror) — https://www.gartner.com/en/information-technology/glossary/customer-identity-access-management-ciam
- CIAM vs IAM comparisons — https://startwithidentity.com/articles/ciam-vs-iam/ · https://www.openiam.com/customer-identity-concepts/ciam-vs-workforce-identity · https://www.ibm.com/think/topics/ciam
- Keycloak Server Administration Guide (sessions, user events, admin events) — https://www.keycloak.org/docs/latest/server_admin/index.html · event catalogue — https://documentation.cloud-iam.com/resources/events.html · console features — https://skycloak.io/blog/what-is-keycloak-complete-guide/
- Microsoft Entra log retention — https://learn.microsoft.com/en-us/entra/identity/monitoring-health/reference-reports-data-retention
- Okta System Log retention & read-only impersonation audit — https://support.okta.com/help/s/article/Exporting-Okta-Log-Data?language=en_US · https://support.okta.com/help/s/article/read-only-impersonation-access-system-logs-audit-events?language=en_US · https://help.okta.com/oie/en-us/content/topics/privileged-access/pam-audit-events-with-okta-syslog.htm
- Google Workspace log retention — https://knowledge.workspace.google.com/admin/reports/data-retention-and-lag-times
- NIST SP 800-53 Rev.5 AC-5 / AC-6 — https://csrc.nist.gov/files/pubs/sp/800/53/r5/ipd/docs/sp800-53r5-draft-controls-markup.pdf ; AC-6(9) summary — https://www.miniorange.com/blog/nist-privileged-access-management/ ; AU-9 — https://csf.tools/reference/nist-sp-800-53/r5/au/au-9/ · https://nist-sp-800-53-r5.bsafes.com/docs/3-3-audit-and-accountability/au-9-protection-of-audit-information/
- NIST SP 800-63A-4 (proofing agents, trusted referees, session records) — https://pages.nist.gov/800-63-4/sp800-63a/ial-general/ · https://pages.nist.gov/800-63-4/sp800-63a/proofing/ ; SP 800-63B-4 account recovery — https://pages.nist.gov/800-63-4/sp800-63b.html
- Identity lifecycle (JML) — https://newcore.com/identity-101/identity-and-access-management/identity-lifecycle-management-joiner-mover-leaver · https://www.idmanagement.gov/playbooks/ilm/
- Help-desk recovery security — https://nhimg.org/account-recovery-help-desk-security-guide · https://credentialgovernance.avatier.com/en/blog/password-reset-questions-best-practices-2026
- OWASP Logging Cheat Sheet & Vocabulary — https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html · https://cheatsheetseries.owasp.org/cheatsheets/Logging_Vocabulary_Cheat_Sheet.html · checklist — https://checksy.app/library/owasp-logging-and-monitoring
- Tamper-evident audit log design — https://www.techinterview.org/post/3233468938/lld-audit-logging/ · https://spike-r.com/threshold/specs/append-only-audit-log
- Support impersonation design — https://github.com/Neurowealth/Backend/issues/548 · https://rishandigital.com/power-pages/user-impersonation-for-testing/
- PowerSchool parent/guardian access administration — https://ps.powerschool-docs.com/pssis-admin/latest/ids-and-passwords · district handbook — https://cdnsm5-ss18.sharpschool.com/UserFiles/Servers/Server_27969467/File/IT%20Resources/PowerSchool/PowerSchool%20Handbooks%20and%20Job%20Aids/Parent%20and%20Student%20Portal/Parent_Student_Portal_Administration_HB_0.pdf
- Google Classroom guardian administration — https://support.google.com/edu/classroom/answer/7017326?hl=en
