<?php
/**
 * Copy this file ABOVE the website folder:
 *
 *   cp api/v1/app_release.example.php /home/arkeonet/.fkss_app_release.php
 *   chmod 600 /home/arkeonet/.fkss_app_release.php
 *
 * Then put the APKs here (never in git):
 *
 *   mkdir -p /home/arkeonet/fkss_releases
 *   cp FKSS-universal.apk /home/arkeonet/fkss_releases/fkss.apk
 *
 * P65 (optional, recommended at fleet scale): also upload the per-ABI
 * builds produced by `flutter build apk --split-per-abi` and point the
 * two *_path keys at them. Phones then download a ~2x smaller file that
 * matches their CPU. The universal 'apk_path' MUST stay published — it
 * is what old app versions and every fallback receive.
 *
 *   cp FKSS-armeabi-v7a.apk /home/arkeonet/fkss_releases/fkss-arm32.apk
 *   cp FKSS-arm64-v8a.apk    /home/arkeonet/fkss_releases/fkss-arm64.apk
 *
 * After each new build, raise latest_version / latest_build to match
 * pubspec.yaml (currently 1.6.5+31). Raise min_build only after the staged
 * compatibility window and adoption checks in the release runbook.
 */
return [
    'latest_version' => '1.6.5',
    'latest_build'   => 31,
    'min_version'    => '1.0.0',
    'min_build'      => 1,
    'force_update'   => false,
    // Emergency containment: set false to pause outbound app outbox drains.
    // Local SQLite saves and queued rows continue and must not be deleted.
    'background_drains_enabled' => true,
    'release_notes'  => 'This update fixes hymns from the old system still appearing after the move to felegekidusan.com: the app now rebuilds its hymn list from the current system automatically. Your account, downloads and pending changes are kept.',
    'banner_text'    => '',
    'banner_kind'    => 'info',
    // Universal APK (both 32-bit and 64-bit phones) — ALWAYS publish.
    'apk_path'       => '/home/arkeonet/fkss_releases/fkss.apk',
    // Optional per-ABI builds (~2x smaller per device). Leave '' to skip.
    'apk_arm64_path' => '/home/arkeonet/fkss_releases/fkss-arm64.apk',
    'apk_arm32_path' => '/home/arkeonet/fkss_releases/fkss-arm32.apk',
    'tiles'          => [
        'education' => ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance'],
    ],
];
