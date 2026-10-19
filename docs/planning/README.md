# Planning & Design Documents (index)

Repository artefacts only — **not served over HTTP** (`.htaccess` in this folder denies access). Nothing here authorises implementation; see the status line at the top of each document.

## account_command_center/ — Account Command Center (members + parents)
| Stage | File | Status |
|---|---|---|
| Research | `ACCOUNT_COMMAND_CENTER_PLATFORM_RESEARCH.md`, `FOUR_EYES_GOVERNANCE_RESEARCH.md` | delivered |
| D1 Requirements & governance | `ACC_D1_REQUIREMENTS_GOVERNANCE.md` | FROZEN v1.2 |
| D2 Architecture | `ACC_D2_ARCHITECTURE.md` (+ `ACC_D2A_PARENT_APP…`, `ACC_D2B_MEMBERS_APP…`, `ACC_D2C_COMMAND_CENTER…`), `ACC_D2_DEVICE_AND_CODES_EXPLAINER.md`, `ACC_D2_DECISION_RECORD_AND_RISKS.md` | decided (owner decisions §7/§8) |
| D3 Data & API | `ACC_D3_DATA_AND_API.md` (host facts §8b) | v1.0 |
| D4 Monitoring / audit / incident | `ACC_D4_MONITORING_AUDIT_INCIDENT.md` | planning v1.0 |
| D5 Wireframes | `ACC_D5_CONSOLE_WIREFRAMES.md`, `wireframes/acc_wireframes.html` | planning v1.0 |
| D6 Threat model & tests | `ACC_D6_THREAT_MODEL_AND_TEST_PLAN.md` | planning v1.0 |
| D7 Implementation plan | `ACC_D7_IMPLEMENTATION_PLAN.md` | **AWAITING OWNER APPROVAL** |
| Decisions | `ACC_DECISION_LOG_2026-10-18.md` | record |
| Tooling | `host_check/acc_host_check.php.txt` (read-only host check; rename to .php to use, delete after) | — |

## parent_app/
`PARENT_APP_FEATURE_BASELINE.md` (LOCKED baseline §1–§19), `PARENT_ACCOUNTS_MANAGEMENT_RESEARCH.md`, `PARENT_APP_PHASE1_INPUTS.md` (FCM, assigned-teacher rule, lesson-delivery options), `presentation/` (Amharic deck, PPTX + PDF).

## members_app/
`MEMBER_APP_SIGNIN_PROPOSAL.md`, `MEMBERS_APP_PROGRESS.md`, `MEMBERS_APP_FEATURE_PROPOSAL.md` (for review, not approved).

## family_link/
`IMPLEMENTATION_PLAN_GUARDIAN_LINKAGE.md` — implemented (`95e40be`, `7659d7f`, `00523ee`, `9c6f84d`).

## registration_hr/
Registration/HR promotion analyses (`ANALYSIS_01…06`, `REGISTRATION_SYSTEM_ANALYSIS_INDEX.md`), fix plan, HR promotion status/proposal, new-members promotion plan and report — implemented in earlier releases.

## system/
`SSMS_SYSTEM_KNOWLEDGE_REPORT.md`, `PRODUCTION_READINESS_AUDIT.md`, `PROJECT_RULES.md` (Universal AI Software Change, Patch & Update Protocol — governs all work).

- `REPORTING_CENTRE_SCOPE_AND_ROLE_BOUNDARY_2026-10-19.md` — owner decision: future cross‑department / sensitive datasets are Info‑only; HR scope frozen at V2‑3; V2‑4 paused pending user feedback.
