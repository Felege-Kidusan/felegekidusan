# FKSS Members Mobile App — Secure Sign-In Design Proposal

Status: PROPOSAL (not implemented). Date: 2026-10-17.
Scope: how students/members sign in to a members Flutter app (attendance, grades, hymns/lyrics, future features) against the existing PHP/MariaDB backend on cPanel shared hosting.

---

## 1. How the big companies actually do it (research summary)

| Company / standard | What they do | Source |
|---|---|---|
| **All (OAuth 2.0 / OIDC)** | Native apps are *public clients*: no secret in the binary; Authorization Code + **PKCE**; system browser for federated login (RFC 8252) | RFC 8252 §6, §8.1 |
| **Google** | Short-lived access tokens (minutes, memory only) + rotating refresh tokens in Keystore/Keychain; Play Integrity attestation; reCAPTCHA risk checks at login; passkeys auto-created | Google OAuth BCP; 2026 mobile-auth roundups |
| **Microsoft (Entra/MSAL)** | Refresh-token rotation + **reuse detection → family revocation**; device-bound tokens (Conditional Access Token Protection); brokered silent SSO; FIDO2/passkeys; risk-based step-up MFA | MS docs; FOCI/CA analyses |
| **Apple** | Secure Enclave keys; Keychain access flags (`WhenUnlockedThisDeviceOnly`); App Attest; Sign-in-with-Apple = plain OIDC | Apple dev docs |
| **WhatsApp / Telegram** | Weak-identity populations: **phone number + OTP sent out-of-band**, account bound to the device, optional second factor (password/2FA) | MTProto docs |
| **Industry direction 2026** | Passkeys (WebAuthn/FIDO2) as phishing-resistant primary; biometrics = local gate over a crypto operation, never the authentication itself; refresh rotation + reuse detection = OAuth 2.1 baseline | OAuth Security BCP; MASVS |

The universal token model (Google/Microsoft/Keycloak/Curity all converge here):
1. **Access token**: 5–15 min, held in memory only.
2. **Refresh token**: 30–90 days, stored in iOS Keychain / Android Keystore (hardware-backed, biometric-gated), **rotated on every use**.
3. **Reuse detection**: a replayed (already-rotated) refresh token = theft signal → revoke the whole token *family* (all devices of that session line).
4. **Server-side revocation**: session table, per-device listing, remote sign-out.

## 2. What FKSS already has (repo facts)

- `api/v1/core/auth.php`: JWT-style access tokens + `API_REFRESH_EXPIRY = 90 days`, **rotating refresh sessions with family revocation and replay detection** (`sql/010_refresh_token_rotation.sql`: `api_refresh_sessions` with `family_id`, `replaced_by`, `consumed_at`, `revoked_at`, `token_hash` unique). This IS the big-company pattern, already in PHP/MariaDB — no Redis needed.
- `api/v1/routes/`: attendance, grades, mezmur (hymns), members, notifications endpoints exist (staff-scoped today).
- Flutter app: `session_service.dart`, `app_lock_service.dart` (lock screen), mezmur player + synced lyrics, attendance/grade sync — reusable for a members flavor/app.
- Gaps: **no member identity** (ACL has staff roles only; `members` table has no login credentials), no enrollment/OTP flow, no device policy for members.

## 3. Proposed design

### 3.1 Identity model
- New table `member_accounts`: `member_id` (FK members), `pin_hash` (argon2id), `recovery_phone`, `status`, `created_at`, `last_login_at`.
- New table `member_enroll_codes`: one-time activation codes (`code_hash`, `expires_at`, `used_at`) issued by HR/admin — printable on the member ID card and/or shown as QR in the admin dashboard.
- Extend refresh sessions with `actor_type ENUM('staff','member')` (or a parallel table) so member sessions rotate/revoke through the **same** proven code path.
- New ACL scope `member`: server derives `member_id` from the token only; allow-list = own attendance, own published grades, hymn/lyrics catalog, own profile. Client never sends member_id.

### 3.2 First sign-in (activation) — in-person attested, zero e-mail/phone/OTP
No e-mail, no Telegram, no SMS exists for many families, so out-of-band OTP is
removed entirely. Trust is established ONCE, face-to-face (banking-agent /
Aadhaar-KYC / MDM zero-touch pattern), and afterwards the device hardware key
is the credential (passkey pattern).
1. HR opens the member record -> "Issue activation": single-use QR ticket
   (TTL 48 h) shown on the staff screen / printed slip.
2. Member opens the Members app IN FRONT OF STAFF, scans the QR; app displays
   member name/grade/class for visual confirmation.
3. App generates an ES256 key pair inside Android Keystore / Secure Enclave
   (non-exportable, `setUserAuthenticationRequired(true)` -> biometric/PIN
   gated) and sends the PUBLIC key + ticket to the server -> activation row
   created in PENDING state; ticket consumed (single use).
4. STAFF APPROVAL TAP from the authenticated admin session (same room, staff
   visually confirms the student / ID card) -> activation ACTIVE; first
   DPoP-bound refresh session issued to that device key.
   A leaked or photographed QR can reach PENDING but never ACTIVE: staff only
   approve the child standing in front of them. Pending rows expire and are
   visible in admin.
5. Member sets a 6-digit PIN as fallback authenticator (broken sensor, shared
   family phone with several PIN-gated profiles).

### 3.3 Daily sign-in — passkey-style, no password, no OTP
- Open app -> local profile list (discoverable-credential style; the device
  enumerates its stored member profiles) -> member taps their profile.
- Biometric/PIN unlocks the Keystore key -> app signs a server challenge
  (WebAuthn-style assertion) -> server verifies against the stored public key
  -> issues 15-min access token + rotates the refresh token, BOTH
  sender-constrained to the device key (DPoP, RFC 9449).
- member_code is a public identifier only; the secret (private key) never
  leaves hardware, so there is nothing to phish, shoulder-surf, replay or
  intercept. No OTP channel is needed at all; Telegram (if ever adopted) is
  notifications-only.
- Lost/stolen phone: HR revokes the device row (admin) -> tokens inert anyway
  without the key; member re-activates in person (the recovery path).
- Shared family phone: multiple profiles, each with its own key pair + PIN.

### 3.4 Risk controls ("conditional access, light")
- Server derives member_id ONLY from the verified token/key; member-scoped
  ACL allow-list (own attendance, own published grades, hymns catalog).
- Existing rotation + replay detection revokes the whole token family on
  replay; DPoP makes exfiltrated tokens inert even before replay detection.
- Rate limits per ticket/IP/device on activation; per-device challenge rate
  limits; exponential backoff on PIN/biometric failures (platform gate).
- All auth events -> activity_logs; admin lists member devices + pending
  activations with remote revoke.
- Phase 2: Google Play Integrity / Apple App Attest on refresh (blocks
  repacked clients), Android key attestation against Google root at
  activation, optional synced-passkey export if families change phones often.

## 4. Build phases (each independently shippable)
1. **Server**: migrations (`member_accounts`, `member_enroll_codes`, actor_type), `/api/v1/member/auth` routes (activate, login, refresh reuse of core, logout, devices), member ACL + own-data endpoints (attendance, published grades, hymns).
2. **Flutter members app/flavor**: onboarding (QR/code + PIN set), lock screen biometrics, secure storage, silent-refresh interceptor, home = attendance + grades + hymns/lyrics (reuse mezmur services).
3. **Admin side**: enrollment-code issue/revoke UI (ID-card print + QR), member sessions list + remote sign-out.
4. **Hardening phase 2**: Play Integrity, passkeys, OTP recovery channel, "Sign in with Telegram/Google" optional.

## 5. Decisions (resolved)
- D1: First sign-in = **in-person attested activation** (single-use QR + staff approval tap) creating a **device-bound hardware key** (passkey pattern) + PIN fallback. NO e-mail/phone/Telegram/SMS anywhere in the auth path.
- D2: OTP channel = **none needed**; recovery = in-person re-activation + HR remote revoke. Telegram remains an optional notifications channel only.
- D3: **Separate "FKSS Members" APK**.

## 6. Why outsiders are blocked at every layer
| Attack | Blocked by |
|---|---|
| Leaked/photographed activation QR | single-use + 48 h TTL + PENDING until staff approval tap in person |
| Phishing / fake login page | no password or OTP exists; private key never leaves hardware; challenge signatures are origin-bound |
| Stolen access/refresh token (logs, MITM, DB leak) | DPoP sender-constraint: token inert without device key; plus rotation + replay family revocation |
| Stolen phone | Keystore/Secure Enclave non-exportable key + biometric/PIN gate + HR remote revoke |
| Repacked/modded APK | signature check now, Play Integrity/App Attest phase 2 |
| Shared-device sibling access | per-member profiles, per-profile PIN/key |
| Brute force | platform biometric/PIN backoff + server rate limits per device |
| Insider misuse of staff approval | every approval audited (staff id, member id, device fp, time); pending-activation visibility |
Residual risk: a thief with the unlocked phone AND the member PIN, unreported -> bounded by 15-min access tokens, device-bound refresh, and revocation once reported.

## 7. Agent-guided vault activation variant (user proposal, 2026-10-17) + recommended hybrid
User-proposed ceremony: agent (HR) opens a secure activation vault via member ID no or QR;
vault issues a ONE-TIME OTP handed to the member IN PERSON; member uses it in the app to
set login credentials = ID no + password. Banking-agent model; operationally simple.
Weakness if used alone: ID no is public (printed on cards) and passwords are shareable/
guessable/phishable -> ongoing login from any device becomes the outsider door.
RECOMMENDED HYBRID (leading candidate):
1. Vault ceremony exactly as user proposed (agent session + ID/QR -> in-person OTP -> member sets ID no + password). Vault access fully audited; OTP single-use, short TTL.
2. Same session silently registers the device hardware key (Keystore/Secure Enclave) and binds the account to that device ("trusted device" step).
3. Daily login on trusted device = profile tap + biometric/PIN (password not re-typed).
4. Login on new/unrecognized device = ID no + password + FRESH in-person agent OTP from vault.
5. Password reset = same vault ceremony. No e-mail/phone/SMS/Telegram in the auth path.
Passwords stored argon2id with policy + lockout; sessions use existing rotation/replay
engine + DPoP device binding; admin sees devices, vault accesses, approvals; remote revoke.
Status: ON HOLD — user will signal when detailed build planning starts.

## 8. Optional self-service recovery channels (user proposal, 2026-10-17) — ACCEPTED INTO DESIGN
Members may OPTIONALLY register an e-mail and/or Telegram username in Settings after first
successful sign-in, to avoid routine HR visits for forgotten passwords / new phones.
Recovery ladder: (1) trusted-device biometric; (2) registered VERIFIED channel: new-device
sign-in = ID no + password + channel OTP; forgotten password = channel OTP + 24h cooling-off
then reset with full session/device-trust revocation; (3) in-person agent vault ceremony
(always available, universal fallback for families with no e-mail/Telegram).
Security controls: registration only inside authenticated device-bound session; verified
round-trip before any use; two-compartment rule (password + channel OTP, attacker needs both);
cooling-off + notifications to old channel/device with cancel for resets and channel changes;
channel replacement requires old trusted device biometric or agent ceremony; full audit trail;
contacts encrypted at rest, masked in admin, optional and deletable.
Residual risk: simultaneous compromise of password AND channel, bounded by cooling-off cancel
window and HR remote revoke (same profile Google/Apple accept).
Status: ON HOLD with §7 hybrid — user will signal when detailed build planning starts.
