# Documentation index

Last reviewed: 2026-10-23 against `main` = `46fbaa7`.

**Status labels**

| Label | Meaning |
|---|---|
| **Canonical** | Maintained. Describes the current system. If it disagrees with code, the code wins; fix the document |
| **Reference** | Believed accurate for its narrow topic. Not re-verified in the 2026-10-22 review unless the entry says "reconciled" |
| **Historical** | A point-in-time record (audit, plan, implementation note). Useful for *why*, not for *what is true now* |
| **Superseded** | Replaced by the linked canonical document. A banner at the top says so; the content is preserved |
| **Planning** | A proposal or decision record. Not authority to implement anything |

No historical document has been deleted. Git history is the archive.

## 1. Getting started

| Document | Status |
|---|---|
| [README.md](../README.md): project overview, stack, setup summary | Canonical |
| [ONBOARDING.md](ONBOARDING.md): first-week path for a new engineer | Canonical |
| [ENGINEERING_HANDOVER.md](ENGINEERING_HANDOVER.md): handover summary, source-of-truth boundaries, risks, unknowns, next stages | Canonical |
| [CONTRIBUTING.md](../CONTRIBUTING.md): workflow, coding standards, database rules, tests, definition of done | Canonical |
| [CHANGELOG.md](../CHANGELOG.md): curated change history, mobile build history, deployment record | Canonical (reconstructed 2026-10-22; maintained from now on) |
| [BRANCHING.md](../BRANCHING.md): `main` vs `dev` rules | Canonical |
| [PRODUCTION_WORKFLOW.md](PRODUCTION_WORKFLOW.md): repository roles, intended release policy and current state | Canonical (with a current-state section) |
| [AGENTS.md](../AGENTS.md): rules for AI coding agents | Canonical |
| [planning/system/PROJECT_RULES.md](planning/system/PROJECT_RULES.md): standing change protocol for AI-assisted work | Reference |
| [ci_cd/GIT_CHEATSHEET.md](ci_cd/GIT_CHEATSHEET.md) | Reference |

## 2. Product and architecture

| Document | Status |
|---|---|
| [product/PRD.md](product/PRD.md): product requirements, with requirement IDs | **Canonical** (reconstructed baseline, draft for team review) |
| [product/GLOSSARY.md](product/GLOSSARY.md): domain, calendar and technical terms | **Canonical** |
| [architecture/TECHNICAL_DESIGN.md](architecture/TECHNICAL_DESIGN.md): technical design (arc42, C4 diagrams, runtime and deployment views) | **Canonical** (reconstructed baseline, draft for team review) |
| [adr/README.md](adr/README.md): architecture decision records ADR-0001 to ADR-0020, plus a template | **Canonical** (reconstructed 2026-10-22) |
| [rfc/README.md](rfc/README.md): proposals for significant changes; [RFC 0001](rfc/0001-repository-structure.md) repository structure and root clean-up | **Planning** (proposed 2026-10-23; nothing implemented) |
| [ARCHITECTURE.md](ARCHITECTURE.md) | **Canonical** |
| [FAILURE_INTELLIGENCE.md](FAILURE_INTELLIGENCE.md), [Mobile client contract](../Mobile/wbws_flutter_app/docs/FAILURE_INTELLIGENCE_CLIENT.md) | Reference |
| [WEB_ADMIN_SYNC_MONITORING.md](WEB_ADMIN_SYNC_MONITORING.md) | Reference |
| [SECTION_AGE_GROUP_SOURCE_OF_TRUTH.md](SECTION_AGE_GROUP_SOURCE_OF_TRUTH.md) | Reference (domain decision, P71) |
| [planning/system/SSMS_SYSTEM_KNOWLEDGE_REPORT.md](planning/system/SSMS_SYSTEM_KNOWLEDGE_REPORT.md): module inventory and change-impact map | Reference (architecture baseline from `6d5a5f8`, reconciled 2026-10-22 against `46fbaa7`; not the accepted handover) |
| [Mobile/wbws_flutter_app/README.md](../Mobile/wbws_flutter_app/README.md) | Reference |

## 3. API and authorization

| Document | Status |
|---|---|
| [API.md](API.md) | **Canonical** |
| [api/openapi.yaml](api/openapi.yaml): OpenAPI 3.1 reference for `/api/v1` (117 operations) | **Canonical** (reconstructed 2026-10-23; bodies summarised) |
| [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md) | **Canonical** (page-level; per-action rules unresolved) |

## 4. Database

| Document | Status |
|---|---|
| [DATABASE.md](DATABASE.md): engine, migration rules, inventory `001`–`078`, unverified applied state | **Canonical** |
| [DATA_DICTIONARY.md](DATA_DICTIONARY.md): every table by domain, where it is defined, core ERD, declared foreign keys | **Canonical** (table level; from source, not production) |
| [PRODUCTION_MIGRATION_NOTES.md](PRODUCTION_MIGRATION_NOTES.md) | Historical (2026-10-06 inventory of discoveries) |
| [INCIDENT_2026-10-03_TRACKING_SCHEMA.md](INCIDENT_2026-10-03_TRACKING_SCHEMA.md) | Historical (incident record) |

## 5. Security

| Document | Status |
|---|---|
| [SECURITY.md](SECURITY.md): verified controls, secrets (key names only), reporting | **Canonical** |
| [audits/ENTERPRISE_SECURITY_AUDIT_REPORT_2026-09-27.md](audits/ENTERPRISE_SECURITY_AUDIT_REPORT_2026-09-27.md) | **Superseded** (claims contradicted; see banner). Moved from the repository root on 2026-10-23 |
| [audits/SSMS_REMEDIATION_AUDIT_2026-10-02.md](audits/SSMS_REMEDIATION_AUDIT_2026-10-02.md) | Historical |
| [audits/SECURITY_AUDIT.md](audits/SECURITY_AUDIT.md) | Historical (2026-09-05) |
| [audits/production-2026-09-07/REPORT.md](audits/production-2026-09-07/REPORT.md) | Historical |

Open findings are tracked in a restricted register held by the owner, not in this repository (see [SECURITY.md §5](SECURITY.md#5-open-findings)).

## 6. Development, testing and CI

| Document | Status |
|---|---|
| [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md): setup, suites, current CI failures and skips, acceptance policy | **Canonical** |
| [TEST_STRATEGY.md](TEST_STRATEGY.md): test levels, coverage by risk, quality gates, path to a green pipeline | **Canonical** (proposed baseline 2026-10-23) |
| [tests/audit/README.md](../tests/audit/README.md) | Reference |
| [VISUAL_QA_GUIDE_2026-10-20.md](VISUAL_QA_GUIDE_2026-10-20.md) | Historical (manual QA guide for one release) |
| [ci_cd/ARCHITECTURE.md](ci_cd/ARCHITECTURE.md) | **Superseded** (design proposal, 2026-09-05) |
| [ci_cd/SEARCH_LOAD_AND_ERRORS.md](ci_cd/SEARCH_LOAD_AND_ERRORS.md) | Historical (P43) |

## 7. Operations and releases

| Document | Status |
|---|---|
| [OPERATIONS.md](OPERATIONS.md): SFTP deployment, rollback limits, backups, mobile release, monitoring | **Canonical** |
| [Mobile/wbws_flutter_app/docs/RELEASE.md](../Mobile/wbws_flutter_app/docs/RELEASE.md): Android build and signing guide | Canonical for mobile builds |
| [Mobile/wbws_flutter_app/RELEASE_NOTES.md](../Mobile/wbws_flutter_app/RELEASE_NOTES.md) | Canonical (release log) |
| [audits/AUDIO_STORAGE_SETUP.md](audits/AUDIO_STORAGE_SETUP.md) | Reference (media storage setup; verify before use) |
| [audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md](audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md) | Historical (build 24 rollout) |
| [audits/DEPLOYMENT_RUNBOOK.md](audits/DEPLOYMENT_RUNBOOK.md) | **Superseded** (go-live runbook, 2026-10-06) |
| [audits/DEPLOYMENT_GUIDE.md](audits/DEPLOYMENT_GUIDE.md) | **Superseded** (CMS rollout, 2026-09-05) |
| [Mobile/HOW_TO_SHIP_AN_UPDATE.md](../Mobile/HOW_TO_SHIP_AN_UPDATE.md) | **Superseded** |

## 8. Feature design and implementation records (historical)

These records explain why features look the way they do. Check the code before relying on any statement in them.

| Area | Documents |
|---|---|
| Academic tracking | [Phase 0](ACADEMIC_TRACKING_PHASE0.md), [1](ACADEMIC_TRACKING_PHASE1.md), [2](ACADEMIC_TRACKING_PHASE2.md), [3](ACADEMIC_TRACKING_PHASE3.md), [4](ACADEMIC_TRACKING_PHASE4.md), [5](ACADEMIC_TRACKING_PHASE5.md) |
| Attendance | [ATTENDANCE_REWORK_2026-10.md](ATTENDANCE_REWORK_2026-10.md); attendance-alerting and class-day documents under [planning/](planning/README.md) |
| Communication | [NOTIFICATION_SYSTEM_OVERHAUL.md](NOTIFICATION_SYSTEM_OVERHAUL.md), [COMMUNICATION_UX_OVERHAUL.md](COMMUNICATION_UX_OVERHAUL.md), [MOBILE_COMMUNICATION_PARITY.md](MOBILE_COMMUNICATION_PARITY.md) |
| Mobile offline sync (S0–S3) | [S0 audit](MOBILE_OFFLINE_SYNC_S0_AUDIT.md), [S0.5/S1a](MOBILE_OFFLINE_SYNC_S0_5_S1A_VERIFICATION.md), [S1](MOBILE_OFFLINE_SYNC_S1_OBSERVABILITY.md), [S2 F-20](MOBILE_OFFLINE_SYNC_S2_BACKGROUND_F20.md), [S2 A.1](MOBILE_OFFLINE_SYNC_S2_A1_BACKGROUND_CORE.md), [A.2](MOBILE_OFFLINE_SYNC_S2_A2_TRIGGER_WIRING.md), [A.3](MOBILE_OFFLINE_SYNC_S2_A3_READINESS_AUDIT.md), [A.4](MOBILE_OFFLINE_SYNC_S2_A4_OPPORTUNITY_WIRING.md), [A.5](MOBILE_OFFLINE_SYNC_S2_A5_TEST_SEAM.md), [A.6](MOBILE_OFFLINE_SYNC_S2_A6_MIGRATION_RUNTIME.md), [A.7](MOBILE_OFFLINE_SYNC_S2_A7_HYMN_ATTEMPT_LEDGER.md), [S3 A.8](MOBILE_OFFLINE_SYNC_S3_A8_ANDROID_BACKGROUND_PRODUCER.md), [A.8 ADR](MOBILE_OFFLINE_SYNC_S3_A8_PHASE1_ANDROID_BACKGROUND_PRODUCER_ADR.md), [A.9](MOBILE_OFFLINE_SYNC_S3_A9_ANDROID_BUILD_VERIFICATION.md), [A.10 / F-19](MOBILE_OFFLINE_SYNC_F19_DEPENDENCY_LOCKFILE_REMEDIATION.md), [A.11](MOBILE_OFFLINE_SYNC_A11_DART_SQLITE_RUNTIME_VERIFICATION.md), [A.12](MOBILE_OFFLINE_SYNC_S3_A12_ANDROID_COLD_START.md), [A.13](MOBILE_OFFLINE_SYNC_S3_A13_PRODUCTION_RUNTIME_VERIFICATION.md) |
| Responsive UI | [RESPONSIVE_AUDIT_FILE_BY_FILE.md](RESPONSIVE_AUDIT_FILE_BY_FILE.md), [RESPONSIVE_BOTTOM_NAV_FIX.md](RESPONSIVE_BOTTOM_NAV_FIX.md), [education/MOBILE_RESPONSIVE_P70.md](education/MOBILE_RESPONSIVE_P70.md), [mezmur_player/DASHBOARD_RESPONSIVE_P68.md](mezmur_player/DASHBOARD_RESPONSIVE_P68.md) |
| Mezmur (web) | [LYRICS_SYNC.md](mezmur_player/LYRICS_SYNC.md), [KARAOKE_SYNC_AUDIT_P50.md](mezmur_player/KARAOKE_SYNC_AUDIT_P50.md), [KARAOKE_SYNC_FIX_P50.md](mezmur_player/KARAOKE_SYNC_FIX_P50.md), [LYRICS_ANIMATION_P51.md](mezmur_player/LYRICS_ANIMATION_P51.md), [LYRICS_ACCESSIBILITY_P52.md](mezmur_player/LYRICS_ACCESSIBILITY_P52.md), [LYRICS_SPOTIFY_STYLE_P53.md](mezmur_player/LYRICS_SPOTIFY_STYLE_P53.md), [HYMN_ART_P66.md](mezmur_player/HYMN_ART_P66.md), [PLAYER_UI_P67.md](mezmur_player/PLAYER_UI_P67.md) |
| Mezmur (mobile) | [Mobile/wbws_flutter_app/docs/mezmur_player/](../Mobile/wbws_flutter_app/docs/mezmur_player/), [mobile audits](../Mobile/wbws_flutter_app/docs/audits/) |
| Mobile (other) | [Mobile/TEACHER_APP_WEAKNESS_REPORT.md](../Mobile/TEACHER_APP_WEAKNESS_REPORT.md) (historical working note) |

## 9. Planning and decision records

[planning/README.md](planning/README.md) has its own index. It covers:
- the Account Command Center, Parent App and Members App plans (not implemented; approval gates apply);
- Family Link, registration/HR analyses;
- attendance alerting, timetable, class-day calendar, reporting, danger confirmation, and score limit / paging.

Planning documents record owner decisions and research. They are **not** authority to implement.

## 10. Historical audits (`docs/audits/`)

Point-in-time audits, kept as evidence. The newest are listed first.

| Date | Document |
|---|---|
| 2026-10-21 | [PRE_PRODUCTION_AUDIT_2026-10-21.md](audits/PRE_PRODUCTION_AUDIT_2026-10-21.md) |
| 2026-10-20 | [END_TO_END_AUDIT_2026-10-20.md](audits/END_TO_END_AUDIT_2026-10-20.md), [PRODUCTION_READINESS_AUDIT_2026-10-20.md](audits/PRODUCTION_READINESS_AUDIT_2026-10-20.md), [CLASS_DAY_ENFORCEMENT_REPORT_2026-10-20.md](audits/CLASS_DAY_ENFORCEMENT_REPORT_2026-10-20.md) |
| 2026-10-19 | [ATTENDANCE_PHASE3_FOLLOWUP_AUDIT_2026-10-19.md](audits/ATTENDANCE_PHASE3_FOLLOWUP_AUDIT_2026-10-19.md) |
| 2026-10-17 | [planning/system/PRODUCTION_READINESS_AUDIT.md](planning/system/PRODUCTION_READINESS_AUDIT.md) (pre-sync gate; banner added) |
| 2026-10-05/06 | [MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md](audits/MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md), [STAGE1_STAGING_EVIDENCE](audits/MOBILE_FLEET_TELEMETRY_STAGE1_STAGING_EVIDENCE.md), [INTEGRITY_HARDENING](audits/MOBILE_FLEET_TELEMETRY_INTEGRITY_HARDENING.md), [RETENTION](audits/MOBILE_FLEET_TELEMETRY_RETENTION.md), [FIXES_APPLIED.md](audits/FIXES_APPLIED.md) |
| 2026-10-02 | [SSMS_REMEDIATION_AUDIT_2026-10-02.md](audits/SSMS_REMEDIATION_AUDIT_2026-10-02.md), [evidence/r2/R2_category_decision_hymns_68_73.md](audits/evidence/r2/R2_category_decision_hymns_68_73.md) |
| 2026-09-26 | [EDUCATION_DEPARTMENT_ARCHAEOLOGY_AUDIT.md](audits/EDUCATION_DEPARTMENT_ARCHAEOLOGY_AUDIT.md) |
| 2026-09-07 | [production-2026-09-07/REPORT.md](audits/production-2026-09-07/REPORT.md) |
| 2026-09-05 | [AUDIT_PHASE1_STRUCTURAL.md](audits/AUDIT_PHASE1_STRUCTURAL.md), [FOUNDATION_VERIFICATION.md](audits/FOUNDATION_VERIFICATION.md), [FUNCTIONAL_BUG_AUDIT.md](audits/FUNCTIONAL_BUG_AUDIT.md), [SECURITY_AUDIT.md](audits/SECURITY_AUDIT.md), [PRODUCTION_HARDENING_PLAN.md](audits/PRODUCTION_HARDENING_PLAN.md), [MEZMUR_DEEP_AUDIT.md](audits/MEZMUR_DEEP_AUDIT.md), [MEZMUR_WEB_AUDIT_P42.md](audits/MEZMUR_WEB_AUDIT_P42.md), [SEARCH_ROOT_CAUSE.md](audits/SEARCH_ROOT_CAUSE.md) |

Several historical documents were produced with AI assistance. They are evidence of what was done and decided at the time, **not** authority on the current system.

## 11. Other packages

| Document | Status |
|---|---|
