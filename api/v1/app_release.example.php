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
 * pubspec.yaml (for example 1.1.0+2 → version 1.1.0, build 2).
 * Raise min_build only when old phones MUST update (security fix).
 */
return [
    'latest_version' => '1.1.0',
    'latest_build'   => 2,
    'min_version'    => '1.0.0',
    'min_build'      => 1,
    'force_update'   => false,
    'release_notes'  => 'Education on the phone, safer student data, in-app update.',
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
