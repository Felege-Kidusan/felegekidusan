# D2-B — Members App ↔ Account Command Center: Architecture

**Status:** DRAFT v0.1 · **Date:** 2026-10-18 · Part of D2 (see `ACC_D2_ARCHITECTURE.md` for the shared foundation).
**Governing specs:** `MEMBER_APP_SIGNIN_PROPOSAL.md` (accepted design: separate "FKSS Members" APK, in-person agent-vault activation, device-bound keys, biometric daily login, DPoP, optional verified recovery channels §8), `MEMBERS_APP_PROGRESS.md`, D1 v1.1. **Standing rule:** members are never registered through the app — registration is web/HR only.
**Product identity:** OAuth client `fkss-members-app` · API namespace `/api/member/v1` · account kind `member` · consent set `member-v1`.

---

## 1. What is specific to the Members app

| Aspect | Member-specific rule |
|---|---|
| Who the account is | The member (`members.id`), 1:1 |
| What the member types | Their **own member ID** at activation / new-device only |
| Eligibility to activate | Member row must be `active` (HR-registered); **PROPOSED:** minimum age / category rule for self-held accounts (e.g., minors' accounts not issued — guardians use the Parent app) — **M-1 decision** |
| What the member sees | Their own profile, attendance, classes/mezmur, notices, documents as the Members programme defines; no other member's data |
| Same human also a parent | Separate parent account (D1 DECIDED-3); the console shows the cross-link via `guardians.member_id` for support context only |
| Recovery channels | Optional verified e-mail/Telegram registered *inside a trusted session*, for notices and step-up only — never the sole recovery (members proposal §8) |
| Language | Amharic-first with English fallback (as Members programme decides) |

---

## 2. Flows

### 2.1 First activation (agent-vault ceremony, at the office)
```
Desk (Command Center)                              Member phone                         ACC Core
1 accounts_office verifies member identity (ID card/registry photo)
2 records proofing (evidence, consent)
3 "Issue activation code" (shown once / QR)
                                                   4 App → Activate → scan/type member ID + code
                                                   5 keystore P-256 key, biometric-gated
                                                   6 send {member_id, code, pubkey, device}  ──▶  7 resolve member → account (create if PROVISIONED)
                                                                                                 8 code single-use; device ENROLLING→ACTIVE
                                                   9 set 6-digit PIN                      ──▶  10 PIN peppered+hashed; account ACTIVE; tokens issued
                                                                                                 11 events: account.activated, device.registered, consent.granted
12 desk shows "Activated ✔"
```
Identical mechanics to the Parent app (shared ActivationService); differences are the subject table, the eligibility check and the consent text.

### 2.2 Daily login / PIN step-up / second device / lost phone / forgotten PIN / closure
Same as Parent app §2.2–§2.7 (shared services), with product-specific screens. Member-specific step-up: **PROPOSED** PIN before viewing/downloading personal documents and before changing contact details.

### 2.3 Optional recovery channel registration (phase 2 of Members programme)
From an ACTIVE device + PIN: add e-mail or Telegram → verification code via that channel → stored as `acc_contact_channels(verified_at)`. Used for: notices (reset, new device, view-as), and **optional** step-up; never to activate or to replace the desk for lost-all-devices (D1 §7).

---

## 3. API surface (namespace `/api/member/v1`) — detail in D3

| Group | Endpoints (shape) | Auth |
|---|---|---|
| Activation | `POST /activation/resolve` (member ID + code) · `POST /activation/complete` | code + rate limit |
| Token | `POST /token` (`device-assertion` / `refresh_token`) · `POST /revoke` | device proof |
| Self | `GET /me` · `POST /me/pin` · `GET /me/devices` · `POST /me/devices/{id}/revoke` · `POST /me/devices/pairing-code` · `POST /me/channels` (+ verify) · `POST /me/closure-request` · `GET /me/notices` | access token (device-bound) |
| Member data | profile, attendance, classes, mezmur schedule, documents, announcements — defined by the Members programme | access token + `MemberScope::assertSelf(sub, member_id)` |
| Consent | `GET /consent/current` · `POST /consent/accept` | access token |

---

## 4. Scopes
`member:profile member:attendance:read member:documents:read member:devices member:channels member:consent member:notices` (+ later write scopes as the programme defines). Never overlaps with `parent:*`.

---

## 5. Relationship to the existing staff app (FACT-based)
- The staff app keeps `/api/v1/*` with HMAC tokens; the Members app **does not** reuse those endpoints even where data overlaps (e.g., member profile) — it gets `/api/member/v1` handlers that apply member-self scoping. Rationale: different principal type, different token format, different threat model; avoids any change to staff code.
- Shared *services* under `admin/backend/services/*` (e.g., attendance summaries) may be called read-only by member handlers, with explicit self-scoping wrappers.

---

## 6. Events (subset)
Same authentication/device/consent set as D2-A plus `channel.added/verified/removed`, `access.sensitive.read{kind=document}`.

---

## 7. Member-specific decisions needed
| ID | Question | Recommendation |
|---|---|---|
| M-1 | Eligibility: which member categories/ages may hold a Members-app account? | Adults and youth ≥ an age threshold defined by the school; children are served through the Parent app |
| M-2 | PIN step-up before documents / contact changes? | Yes |
| M-3 | Recovery channels in phase 1 or 2? | Phase 2 |
