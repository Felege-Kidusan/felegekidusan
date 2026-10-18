# ACC D5 — Console & App Wireframes
**Status:** PLANNING v1.0 (2026‑10‑18). Low‑fidelity. Visual file: `acc_wireframes/acc_wireframes.html` (inline CSS, opens in the viewer). English console (CONFIRMED D0); app screens Amharic in product.

## 1. Screen inventory
| ID | Screen | Primary role | Writes | Flag class (D4 §2) |
|---|---|---|---|---|
| W1 | Overview (KPIs, live alert strip, last events) | accounts_office | none | — |
| W2 | Find account (member ID / child's ID / name / phone) | accounts_office; hr_dept read‑only subset | `lookup` event | S0 |
| W3 | Account detail: summary · devices · children (read‑only Family Link) · codes · sessions · consent · notices · support · timeline | accounts_office | all T0 actions | S1/S2 per button |
| W4 | Office activation wizard (identify → proofing → consent → code → confirm) | accounts_office | `activation.*`, `proofing.*`, `consent.*` | S1 |
| W5 | Devices & lost‑phone revoke (reported device only — A14) | accounts_office | `device.revoke`, optional `code.issue(add_device)` | S1 |
| W6 | PIN reset (proofing choice → code → sessions revoked on use) | accounts_office | `code.issue(pin_reset)` | S1 |
| W7 | Review queue (reviewer ≠ actor) | accounts_office lead / school_admin / super_admin‑technical | `review.ack` | — |
| W8 | Alerts & incidents (runbook checklist) | accounts_office + super_admin | `incident.*`, `alert.ack` | — |
| W9 | Monthly pack + sign‑off | school_admin | `review.monthly_signoff` | — |
| W10 | super_admin break‑glass gate | super_admin | `break_glass.start/stop` | S3 |
| W11 (text only) | Policies — read‑only view of `acc_policies` with history; editing is S2 | accounts_office (read), super_admin‑technical (edit) | `policy.change` | S2 |
| W12 (text only) | Support cases list/detail (case ↔ account, notes without secrets) | accounts_office | `support.*` | S0 |
| M1 | Phone: first activation (ID lookup + code/QR + device security class notice) | parent/member | API `activate` | — |
| M2 | Phone: create PIN (+ optional biometric unlock of device key) | parent/member | API `pin.set` | — |
| M3 | Phone: shared‑phone account switcher (Phase 1, CONFIRMED) | parent/member | local + API `session` | — |
| M4 | Phone: consent — **DRAFT watermark until school administration approves (A11)** | parent/member | API `consent.accept` | — |

## 2. UX rules (PROPOSED)
1. **One action, one reason**: every S1+ button opens a confirmation with reason (enum + optional note) and shows the exact effect list before executing (see W5 modal).
2. **No implicit widening**: "Revoke this device" never touches other devices; "Revoke all" and "Lock account" are separate buttons with their own reasons (A14).
3. **Codes are shown once**, large, with countdown; copy/QR only — never e‑mailed/chatted from the console.
4. **Device security class is always visible** (Hardware‑backed / Basic) with a tooltip "not equal assurance" for basic (D2 decision).
5. **Family facts are read‑only**; the only family‑related write in ACC is the visibility override (S2), everything else deep‑links to the HR Family Link page.
6. **Flags, not blockers**: S1/S2 actions execute immediately and show "flagged for review" toast (A9). The proposed residual second‑person set (D7 §3) shows a "needs a second operator" state instead.
7. **Timeline per account** is the support tool: reason codes and ids only, no secrets.
8. Console reuses the existing admin shell (`.panel`, light theme) so operators see a familiar UI; ACC pages live under `admin/acc/` and are gated by `access_control.php` ROLE_MAP entries.
9. Phone screens: Amharic strings from a locale file; numbers/IDs Latin; code entry accepts dashes; QR path preferred in office.

## 3. Role → screen visibility (PROPOSED; matrix finalised in D7 §3)
| Role | W1 | W2 | W3 actions | W4 | W5 | W6 | W7 | W8 | W9 | W10 | W11 edit |
|---|---|---|---|---|---|---|---|---|---|---|---|
| accounts_office | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ (not own) | ✔ | view | — | — |
| school_admin | reports | ✔ (read) | custody removal only | — | — | — | ✔ | view | ✔ sign | — | — |
| super_admin (technical) | — | — | — | — | — | — | ✔ | ✔ | view | ✔ gate | ✔ (S2) |
| hr_dept | — | status chip inside Family Link page | — | — | — | — | — | — | — | — | — |
| others | no ACC pages |

## 4. Accessibility / operational notes
Keyboard‑first forms, 12‑px minimum body text replaced by 14 px in the real build, colour never the only signal (flags carry text), Africa/Addis_Ababa times with UTC tooltip, Ethiopian date shown next to Gregorian using the existing `ethiopian_date.php` helper.
