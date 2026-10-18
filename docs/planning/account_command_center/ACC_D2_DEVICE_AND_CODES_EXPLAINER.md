# D2-1 and D2-3 explained — Trusted devices, activation codes and recovery (plain language)

**Status:** explanation + recommendation, **nothing frozen** · **Date:** 2026-10-18 · Supplements `ACC_D2_ARCHITECTURE.md` §3, §7 and D2-A/D2-B flows.
Labels: **FACT** (repo-verified / cited) · **INFERENCE** · **RECOMMENDATION**.

---

# Part 1 — D2-1: How the "trusted device" works

## 1.1 The idea in one picture

Think of every enrolled phone as holding a **physical key that was cut inside the phone and can never be taken out**. When the parent or member unlocks the app with their fingerprint/face (or phone PIN), the phone uses that key to **sign a small note** for our server: "it is me, phone #2 of this account, at 10:31, for this request". The server keeps only the **matching lock** (the public key), so it can check the signature but can never reproduce it. Nothing secret ever travels over the network, nothing secret is stored on our server, and copying the app's data to another phone copies nothing useful because the key stays in the original phone's security chip.

## 1.2 How it works on each platform (FACT)

| | Android | iPhone |
|---|---|---|
| Where the key lives | **Android Keystore**, in the phone's Trusted Execution Environment or StrongBox security chip; the private key "never leaves secure hardware — even the OS kernel cannot access it" [9](https://github.com/doridori/Android-Security-Reference/blob/master/hardware/keystore.md) | **Secure Enclave**; keys are "non-exportable by design"; "even Apple and Google cannot read or copy these keys out of the secure hardware" [1](https://docs.nevis.net/mobilesdk/appendixes/device-migration) |
| Who may use the key | Only after the phone's biometric prompt (or device PIN fallback) succeeds; the key can be bound so that a *new* fingerprint enrolment invalidates it [2](https://pub-dev.translate.goog/packages/biometric_signature?_x_tr_sl=en&_x_tr_tl=id&_x_tr_hl=id&_x_tr_pto=tc) | Same, via Face ID / Touch ID / passcode |
| Backups / phone transfer | Keystore keys are **not** included in Google backup or cable transfer; app metadata may be copied but it then "references key material that does not exist" on the new phone [1](https://docs.nevis.net/mobilesdk/appendixes/device-migration) [6](https://stackoverflow.com/questions/38157868/can-the-encryption-keys-stored-in-the-androids-keystore-be-backed-up) | "Secure Enclave keys are never included in any backup"; restored Keychain data points to a key that is absent [1](https://docs.nevis.net/mobilesdk/appendixes/device-migration) |
| Uninstalling the app | Keys are deleted with the app [2](https://stackoverflow.com/questions/48322042/are-keystore-entries-lost-when-the-application-is-uninstalled) | Keychain *items* may survive, but the Secure Enclave key becomes unusable; our server-side device record is the truth anyway |
| Minimum OS | Android 6 / 7+ for hardware-backed keys (plugin needs SDK 23–24+) [2](https://pub-dev.translate.goog/packages/biometric_signature?_x_tr_sl=en&_x_tr_tl=id&_x_tr_hl=id&_x_tr_pto=tc) | iOS 13+ [2](https://pub-dev.translate.goog/packages/biometric_signature?_x_tr_sl=en&_x_tr_tl=id&_x_tr_hl=id&_x_tr_pto=tc) |

**Consequence that matters for us:** the trusted device is *really* the physical phone. A new phone is always a new device that must be enrolled — which is exactly the behaviour we want for "trusted devices".

## 1.3 What the user experiences day to day
1. Open the app → fingerprint/face prompt → home screen. No ID, no PIN, no code.
2. Behind the scenes the phone signed a note; the server answered with a 15‑minute pass that only *that phone* can use (the pass carries the phone's key fingerprint — a stolen pass is useless elsewhere).
3. Occasionally (first use after install, after ~6 months, after a reset, before sensitive screens) the app asks for the **6‑digit PIN** as a second check.

## 1.4 What happens when the phone is lost, broken, replaced, or wiped

| Situation | What the thief/finder can do | What the user does | What the server does |
|---|---|---|---|
| **Lost / stolen, locked phone** | Nothing without the owner's fingerprint/face or phone PIN; even then the app PIN protects sensitive screens | From another own phone: Settings → Devices → "Remove this phone". Or visit the office; staff remove it (no approval needed, instant) | Device marked REVOKED; every pass for that phone stops working on the very next request (`authorization_version`) |
| **Broken / dead phone** | — | Office visit (or another own phone) → new phone enrolled with a code | Old device left ACTIVE until revoked; it is listed, so the user/staff revoke it |
| **New phone, old one still works** | — | On the new phone: "Add this device" → scan the QR shown by the *old* phone → enter PIN. Done, no office visit | New device ACTIVE; both listed; max 3 |
| **Phone reset / app reinstalled** | — | Behaves like a new phone (key gone). Add-device via old phone or office | Old device record becomes dead weight → auto-revoked after 180 days unused, or user removes it |
| **All phones lost + PIN forgotten** | — | Office: identity check → reset code → new PIN on the new phone | All sessions revoked; proofing recorded |
| **Fingerprint re-enrolled on the phone** | — | App asks for PIN once and re-enrols the key quietly (or asks for a code — product choice) | Device key replaced; logged |

Nothing in this table needs e‑mail, SMS or a phone number.

## 1.5 Compatibility check (FACT from the repo)
- The existing Flutter staff app already uses `local_auth` (biometric prompt), `flutter_secure_storage` and `crypto`; Flutter ≥ 3.27, Dart ≥ 3.3; iOS deployment target 12.0 (`Mobile/wbws_flutter_app/pubspec.yaml`, `ios/Runner.xcodeproj`). The Parent and Members apps are **new** APKs built from the same toolchain.
- A mature Flutter plugin exists for exactly this: **`biometric_signature`** (verified publisher, v13, Android SDK 23+/iOS 13+): generates a hardware-backed P‑256 key in Keystore/StrongBox or Secure Enclave, signs a payload after the biometric prompt, supports device-credential fallback, key-overwrite protection, biometric-invalidation binding, and optional **Android key attestation** so the server can verify the key was created in hardware [1](https://pub.dev/packages/biometric_signature) [5](https://github.com/chamodanethra/biometric_signature). It needs compileSdk 35 in the new apps' Gradle config [2](https://pub-dev.translate.goog/packages/biometric_signature?_x_tr_sl=en&_x_tr_tl=id&_x_tr_hl=id&_x_tr_pto=tc) — a normal setting for a new app.
- **Server side:** verifying a P‑256 signature is `openssl_verify()` in PHP — present on every PHP build with OpenSSL; verifying the signed note as a JWT (ES256) is `lcobucci/jwt`, pure PHP. No daemon, no extension beyond `openssl`. **Fully compatible with shared PHP/MariaDB hosting.**
- Devices without a security chip (very old/cheap Androids): the Keystore falls back to software-backed keys — still non-exportable by the app layer but weaker. **RECOMMENDATION:** accept them (attestation recorded as `software`), optionally require PIN more often on such devices. Blocking them would exclude exactly the families we most want to reach.

## 1.6 Device keys vs passkeys — honest comparison

| | **Device-bound key in Keystore / Secure Enclave (proposed)** | **Passkeys (WebAuthn, platform-synced)** |
|---|---|---|
| Where the key lives | Only in that phone's chip | In the phone **and** in Google Password Manager / iCloud Keychain, synced to every device signed into that Google/Apple account [1](https://mojoauth.com/blog/cross-device-passkey-sync-icloud-google-1password) |
| "Trusted device" semantics | Exact: one key = one phone | Broken: the key silently appears on the user's other devices; a family sharing one Google account shares the key |
| Lost phone | Recovery through *our* desk/second device; we control it | Recovery through Google/Apple account recovery (phone number, recovery code, trusted device) — outside our control; if those were never set up "the vault can be unrecoverable" [1](https://mojoauth.com/blog/cross-device-passkey-sync-icloud-google-1password) [3](https://securityboulevard.com/2026/05/cross-device-passkey-sync-explained-icloud-keychain-google-password-manager-and-1password/) |
| Dependence on Google/Apple account | None (works on phones with no Google account, common locally) | Required and must be the *same* account on all devices |
| Assurance level | Device-bound authenticators qualify for NIST AAL2 **and** AAL3; synced passkeys are AAL2 only [9](https://blog.samueleng.se/posts/2025-11-12-synced-vs-devicebound-passkeys) | AAL2 |
| Server requirements | `openssl` + pure-PHP JWT library | Full WebAuthn verifier library + `/.well-known/assetlinks.json` and Apple site association + RP-ID management |
| Flutter maturity | `biometric_signature` (mature, hardware-attested) | `passkeys` / Credential Manager plugins exist but add platform account complexity |
| Phishing resistance | Yes — the signed note is bound to our API host and request | Yes |
| Best use | Native apps where *the device* is the trust anchor (banking-app pattern) | Web logins where convenience across devices matters |

**RECOMMENDATION for D2‑1:** device-bound keys in Keystore / Secure Enclave with signed per-request proofs (the banking-app pattern), biometric‑gated, plus the 6‑digit PIN as the second check. Passkeys are not safer *for this use*; they move recovery to Google/Apple and dissolve the "trusted device" boundary. Keep WebAuthn as a *future* option for the staff web console only.

---

# Part 2 — D2-3: Activation and recovery codes — the complete user experience

## 2.1 Why codes exist at all
No e‑mail, no SMS, no phone number (baseline §16; many families have none). So the *only* trustworthy moment to establish "this phone belongs to this parent/member" is a **human check at the office**. A code is simply the bridge between that human check and the phone: it says "the office verified a person five minutes ago and allowed *one* phone to be enrolled for *this* account". Once a phone is enrolled, the phone's own key replaces the code forever.

## 2.2 Four journeys, step by step

### A. First activation (parent example; member identical with own ID)
1. **At the office window.** Parent comes with their child(ren)'s ID card or number and their own ID. Staff open the Activation desk, find the child, see the guardians HR has linked. If the parent is not linked yet → "HR must link you first" (HR queue; usually done during registration, so rare).
2. Staff tick: identity evidence seen, children confirmed, no legal restriction, consent explained (Amharic printed sheet). The proofing record is saved.
3. Staff press **Issue code**. A QR + 8‑character code appears on the staff screen (and can be printed on a small slip).
4. **Parent's phone, right there.** Install the app (APK/Play), tap **Activate**, scan the QR (or type child ID + code), **register fingerprint/face**, **choose a 6‑digit PIN** twice. The home screen shows all linked children. Staff see "Activated ✔" on their screen. Total ≈ 3–5 minutes.
5. If the parent has **no phone with them / no internet right now** → see §2.4.

### B. Adding a second phone (e.g., father's phone after mother activated, or a new phone)
- **Without visiting the office:** on the already-enrolled phone: Settings → Devices → **Add a device** → a QR appears (valid 24 h, one use). New phone: install → "Add this device" → scan → fingerprint → **enter the existing PIN**. Done. (Note: *each guardian has their own account*; the father's own account is activated once at the office — this journey is for the same person's extra phone.)
- **At the office:** identity check (lighter: ID + account found) → staff issue an **add‑device code** (24 h) → same phone steps.

### C. Forgotten PIN ("password reset")
- Office only (no e‑mail to send a link to). Identity check → staff press **Reset PIN** → reset code (24 h) → on the phone: enter code → set new PIN → all other sessions are logged out for safety; the other phones show a notice "PIN reset at the office on …".
- Staff never see or choose the PIN.

### D. Lost / stolen / broken phone
- **Protect first (instant, no code):** from another own phone → remove the lost device; or call/visit the office → staff remove it immediately. No approval, no waiting.
- **Then re‑enrol:** new phone via journey B (self-service if another phone exists; otherwise office add‑device code). If the PIN is also forgotten → journey C on the same visit.

## 2.3 Why the *first* activation must happen at the office (and not at home with a slip)
1. **It is the identity proofing event.** Everything afterwards trusts the phone's key; the only chance to make sure the key belongs to the right human is when the human is standing there. This is the NIST "in‑person proofing" model your whole design relies on.
2. **A take‑home activation slip is a bearer secret.** Whoever holds the paper for 72 hours can enrol *their* phone as the parent. Children carrying slips home, slips left on a desk, photos of slips — all become account‑takeover paths, and we have no SMS/e‑mail to warn the real parent. Consuming the code at the window reduces that window from 3 days to 3 minutes and lets staff *watch* it being used.
3. **Fingerprint + PIN are set under guidance.** Many parents will do this once in their lives; staff can help, and the consent is explained face to face.
4. **It costs almost nothing extra.** The parent is already at the office for the identity check; the phone steps are 2–3 minutes. The desk can run several parents in parallel (codes are per account).

## 2.4 Parents with no smartphone with them, no data, or no smartphone at all (practical design)
- **No phone with them:** the proofing record is saved and the account becomes **PROVISIONED**; no code is issued. They come back any time; staff then issue the code and watch it being used (identity re‑checked quickly against the saved record). *Alternative:* issue a code **valid only until the end of that day** for use outside — **RECOMMENDATION: no**, keep first activation at the window; a same‑day return or a registration‑day "phone table" solves it.
- **No mobile data at the office:** the office offers **Wi‑Fi** for activation (a tiny amount of data). If the school has no Wi‑Fi, a staff hotspot for the activation minute works. Daily use afterwards is offline‑tolerant (baseline §15).
- **No smartphone at all:** one guardian's phone can serve the family; the other guardian can be activated later. Families with no smartphone keep receiving information through existing channels; the app is additive.
- **Shared family phone:** allowed — each guardian is a separate account and can both be enrolled on the same phone? **RECOMMENDATION: no** (one account per app install; two accounts on one phone defeats the device model and confuses notices). One phone = one guardian account; the second guardian uses their own phone or none.

## 2.5 Why add‑device / reset codes expire after 24 hours (and first‑activation codes should not leave the office)
- These codes are **not** bearer secrets for a *new* identity — they only work together with something the attacker does not have: the **existing PIN** (add‑device) or the office identity check that was just performed (reset). That is why they may safely be used at home.
- A long life would still help an attacker who gets the slip *and* shoulder‑surfed the PIN. 24 h matches how people actually behave (same day / next morning) and is the standard order of magnitude used for e‑mailed verification links (PowerSchool uses 24 h for its registration links). 72 h was in D1 §9 for first activation only **because** it assumed home use; if D2‑3 is decided "at the window", the first‑activation TTL can drop to **30 minutes** — it is consumed on the spot anyway, and a shorter life makes a photographed screen worthless.
- Every code is single‑use, hashed at rest, revocable from the desk, and shows "used ✔ / expired" live.

## 2.6 Recommended approach for D2‑3 (summary)

| Journey | Where | Code TTL | What else protects it | Approval |
|---|---|---|---|---|
| First activation | **At the office window, consumed in front of staff** | 30 min (was 72 h) | Identity proofing record, staff watching, consent | none (HR link must pre‑exist) |
| Add a device (self‑service) | Anywhere | 24 h, one use | Needs an enrolled phone **and** the PIN | none |
| Add a device (office) | Office issues; phone steps at home allowed | 24 h, one use | Identity check + PIN known to user | none |
| PIN reset | Office issues; phone steps at home allowed | 24 h, one use | Identity proofing‑lite; all sessions revoked; notice to other phones | none |
| Lost phone | Office or another own phone | — | Instant revoke | none (protective) |
| No phone at hand | Account PROVISIONED, return later | — | — | none |

Residual risk accepted: a reset or add‑device slip taken home for up to 24 h; mitigated by PIN requirement, notices on other devices, and desk revocation.

---

## 3. Open points for your decision (not frozen)
1. **D2‑1:** device‑bound keys + signed proofs (recommended) — yes / no?
2. **D2‑3a:** first activation at the window, code TTL 30 min — yes / or allow same‑day take‑home?
3. **D2‑3b:** add‑device and reset codes 24 h, usable at home — yes / shorten / office‑only?
4. **D2‑3c:** one guardian account per phone install (no two accounts on one phone) — yes / no?
5. **D2‑3d:** accept phones without a hardware security chip (software‑backed keys, more frequent PIN) — yes / no?
