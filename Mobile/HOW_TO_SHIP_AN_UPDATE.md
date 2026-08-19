# How to give teachers a new FKSS app without a Play Store listing

The phone app asks the website: “is there a newer file?”  
If yes, the teacher taps **Download and install**. You do **not** rebuild for every Education change — only when the app itself must change.

Website Education, attendance, and grades do **not** need a new app. Those already come from `/api/v1`.

No new SQL.

---

## After every GitHub push (website)

On the live server:

```bash
cd /home/arkeonet/felegekidusan.arkeonethiopia.com && /usr/bin/git fetch origin main && /usr/bin/git reset --hard origin/main >> /home/arkeonet/deploy.log 2>&1
```

That updates the API, including `/api/v1/app/config`. It does **not** change the app already installed on phones.

Check:

```bash
curl -sS https://felegekidusan.arkeonethiopia.com/api/v1/app/config
```

You should see JSON with `latest_version` and `download_available`.

---

## First time only — folder for the APK

The APK must live **above** the website (same idea as `.fkss_env.php`). Never put it in git.

```bash
mkdir -p /home/arkeonet/fkss_releases
chmod 755 /home/arkeonet/fkss_releases
cp /home/user/SSMS/api/v1/app_release.example.php /home/arkeonet/.fkss_app_release.php
# edit the file: set version numbers to match pubspec.yaml
nano /home/arkeonet/.fkss_app_release.php
chmod 600 /home/arkeonet/.fkss_app_release.php
```

---

## When you actually have a new APK (rare)

On a computer with Flutter:

```bash
cd Mobile/wbws_flutter_app
# bump version in pubspec.yaml  e.g. 1.1.0+2 → 1.2.0+3
# also set AppConfig.appVersion and AppConfig.appBuild to the same numbers
flutter build apk --release
```

The file is:

`build/app/outputs/flutter-apk/app-release.apk`

Copy it to the server:

```bash
scp app-release.apk USER@SERVER:/home/arkeonet/fkss_releases/fkss.apk
```

On the server, edit `/home/arkeonet/.fkss_app_release.php`:

- `latest_version` / `latest_build` = the new numbers (`1.2.0` and `3`)
- `apk_path` = `/home/arkeonet/fkss_releases/fkss.apk`
- Raise `min_build` **only** if old phones must not keep working (security fix)
- `force_update` = true only for that emergency

Then:

```bash
curl -sS https://felegekidusan.arkeonethiopia.com/api/v1/app/config
```

`download_available` should be `true`. Teachers open FKSS → banner → Download and install. Android will ask to allow installs from FKSS.

iPhone cannot install an APK. Send them the new file another way if you ever ship iOS.

---

## What you can change without a new APK

Edit `.fkss_app_release.php` only:

- `banner_text` — message at the top of the app
- `tiles.education` — hide a tile (example: remove `enrollment`)
- `release_notes` — text on the update screen

Education rules, who sees phones, enroll, classes — those are already on the website API.

---

## If something goes wrong

- Config fails: the app stays on the current version. Teachers are not locked out.
- Download missing: the update screen says the school has not published the file yet.
- Wrong file: the app checks a SHA-256 hash and refuses a damaged download.
