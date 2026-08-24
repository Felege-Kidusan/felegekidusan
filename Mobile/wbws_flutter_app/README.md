# FKSS Mobile App

Flutter client for the Sunday School Management System. The app supports
role-based online workflows plus offline attendance, grades, and roster caches.

## Local-data security

Version 1.1.15 migrates the existing `wbws_offline_v4.db` plaintext SQLite file
to SQLCipher 4.10 on first startup. A random 256-bit key is stored in platform
secure storage and is never hardcoded or written beside the database.

The migration is designed to preserve unsynced work:

1. Open the existing database without a key.
2. Export to a new encrypted sibling with `sqlcipher_export()`.
3. Verify its application marker and SQLite integrity.
4. Rename the original to a temporary recovery copy.
5. Activate and reopen the encrypted copy with its key.
6. Remove the plaintext recovery copy only after verification.

Interrupted migrations resume from fixed sibling files. Key loss, corruption,
or storage failures stop at a generic recovery screen; the app does not silently
delete attendance or grade drafts.

## Required release checks

Use Flutter 3.27 or newer and build 1.1.15+17 or later.

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release
```

Before rollout, test both a fresh install and an upgrade containing representative
cached rosters plus unsynced attendance/grade packets on Android and iOS. Confirm
the upgraded database does not begin with the plaintext `SQLite format 3` header,
o plaintext migration sibling remains, logout removes all prior-user rows, and a
second cold start opens the encrypted database successfully.

Android release shrinking must retain SQLCipher classes; the required rules are
in `android/app/proguard-rules.pro`. Android app-data backup is disabled so an
encrypted database cannot be restored without its device-bound secure key.
