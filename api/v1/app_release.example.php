<?php
/**
 * Copy this file ABOVE the website folder:
 *
 *   cp api/v1/app_release.example.php /home/arkeonet/.fkss_app_release.php
 *   chmod 600 /home/arkeonet/.fkss_app_release.php
 *
 * Then put the APK here (never in git):
 *
 *   mkdir -p /home/arkeonet/fkss_releases
 *   cp FKSS.apk /home/arkeonet/fkss_releases/fkss.apk
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
    'apk_path'       => '/home/arkeonet/fkss_releases/fkss.apk',
    'tiles'          => [
        'education' => ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance'],
    ],
];
