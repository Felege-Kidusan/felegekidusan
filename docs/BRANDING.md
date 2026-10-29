# Development display branding

## Authoritative names

- Short school name: **ውሉደ ብርሃን ሰንበት ት/ቤት**
- Full institution name: **የገላን ጉራ ቅዱስ ገብርኤል ቤተክርስቲያን ውሉደብርሃን ሰንበት ት/ቤት**
- Compact navigation root, derived from the short name: **ውሉደ ብርሃን**.
- Parish portion of the supplied full name: **የገላን ጉራ ቅዱስ ገብርኤል ቤተክርስቲያን**.

The space in the short name and joined `ውሉደብርሃን` in the full name are intentional owner-supplied spellings. No official English name, transliteration or new acronym was supplied. Legacy English-name display slots use the canonical Amharic identity with existing generic English UI descriptors; they are not an approved English translation.

This is **development-only display branding**, not authority to deploy or promote it to production. Development-main integration, development-server acceptance and separately authorized production promotion remain distinct steps. Existing production-target API/domain/package settings are intentionally retained; a display rebrand is not a test-server/Beta cutover.

## Source of truth and consumers

- [school_config.php](../school_config.php): school/parish names, compact name, ID-card defaults, website/login/chrome/report/PWA display metadata.
- [branding_defaults.php](../branding_defaults.php): generic fail-soft defaults; not a second institution configuration.
- [Flutter config](../Mobile/wbws_flutter_app/lib/utils/config.dart): compact, short and full app names. Login/accessibility, home headers, lock prompt, updater, media and diagnostics use these display constants.
- [Android manifest](../Mobile/wbws_flutter_app/android/app/src/main/AndroidManifest.xml): launcher display label; explicitly tested against the short name.
- [PHP manifest](../app/manifest.php), [legacy static manifest](../app/manifest.json) and [redirect title](../app/index.html) retain their routes, behavior, icons and colors while using the new display names. The old phone website remains closed.
- Excel headers and class-report banners now use central constants instead of an embedded previous school name. The Groups PDF school title loads the existing packaged Ethiopic font on export, restoring the prior Latin body font afterward; a font-load failure reports an error instead of generating an unreadable title. This adds a static-font fetch only when exporting; it does not fix pre-existing non-title PDF font limitations. Compact home labels are single-line/ellipsized; login institution text is centered and wraps. Browser/physical-device acceptance is still required.

The source branding scanner now searches for the previous institution's literal name/church, not the newly valid school name. Retained technical identifiers are not evidence of a branding leak. A branding scan is not a security certification or exhaustive document/artwork inspection.

## Identifiers deliberately unchanged

Repository `suraman21/fkss-sunday-school-platform`; source folder `Mobile/wbws_flutter_app`; PHP theme `fkss`; Android package/application/component/signing identity `com.arkeonethiopia.fkss`; Flutter package `fkss_app`; class names such as `FKSSApp`; API endpoints, keys and API identification; domains and network-security configuration; SQL/schema/member IDs; QR `FKSS1:` protocol; preferences, tokens, SQLite files and audio notification-channel ID. Export/download filename prefixes remain `fkss`/`FKSS`; PWA cache values remain `fkss-v5` and `fkss_`. Versions/builds, dependencies, permissions, behavior, images, icons and colors are unchanged. Do not derive filesystem/cache identifiers from a display name containing spaces or `/`.

`FKSS`, `wbws` and `ssms` in code, command examples, release filenames and historical records are compatibility/history, not new official names. Dated audits, release notes, baselines and historical institutional background are not rewritten. Current entry documents point to this policy. This branding change does not close the separately incomplete exhaustive documentation/control audit.

## Retained artwork and runtime overrides

The owner explicitly chose to keep images/icons/colors. No new logo or image alteration is included.

- **Visually confirmed old school/church text remains embedded** in [web logo](../themes/fkss/assets/logos/school_logo.png) and [ID-card logo](../admin/id_cards/assets/logos/school_logo.png). Their names cannot be changed by editing PHP text.
- The inspected [mobile logo](../Mobile/wbws_flutter_app/assets/logo_school.png) is a text-free maroon/gold emblem. It is also unchanged. Other raster/launcher assets are preserved, not certified as text-free.
- [Legacy PWA SVG icons](../app/icons/icon-192.svg), [512 icon](../app/icons/icon-512.svg) and [maskable icon](../app/icons/icon-maskable.svg) contain retained `W`/`WBWS` artwork; the maskable SVG also contains `ውሉደ ብርሃን`. They are unchanged under the keep-assets decision, not an approved new acronym.

Uploaded branding and ID-card text in `system_branding` can override packaged assets/defaults. [IdCardLayout](../admin/backend/services/IdCardLayout.php) and [branding API](../admin/api_branding.php) preserve those saved settings. Separately, saved church-name values in `system_settings` can override defaults. This change does not connect to, overwrite or migrate live school data. Administrators must review their own development runtime settings, previously generated cards/reports, browser caches/installed shortcuts, and notification/launcher labels on existing installations. Historical exports are not retroactively renamed.

## Verification boundary

[Display regression tests](../tests/security/test_development_display_branding.py) exercise PHP configuration, report metadata and manifest output without a school database; compare protected configuration values to the pre-rebrand baseline; and protect exact Flutter/launcher spellings. Flutter widget tests cover login at narrow/wide widths and larger text. Full CI build/test checks remain mandatory. Passing source/build checks does not prove a live server, owner-signed APK upgrade, physical phone layout, printed card or artwork has been accepted.
