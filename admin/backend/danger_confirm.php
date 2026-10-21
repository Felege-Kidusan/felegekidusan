<?php
/**
 * Server-side typed-phrase check for HIGH-risk actions (owner decision D3,
 * docs/planning/DANGER_CONFIRMATION_RESEARCH_PLAN_2026-10-21.md §8).
 *
 * The web dialog (admin/js/danger_confirm.js) sends the phrase the user typed
 * as POST `confirm_phrase`. HIGH-risk endpoints call dc_require_phrase() so an
 * old cached page, a script or a stray request cannot run the action without
 * the explicit confirmation. Comparison: trimmed, spaces collapsed,
 * case-insensitive (same as the dialog).
 *
 * Only admin web endpoints use this. The mobile app talks to /api/v1 and never
 * reaches these actions.
 */

if (!function_exists('dc_phrases')) {
    /** Canonical phrases. Keep in sync with the dialogs (tests check both). */
    function dc_phrases(): array
    {
        return [
            'timetable.remove_day'      => 'REMOVE DAY',
            'timetable.copy_bell'       => 'REPLACE SCHEDULE',
            'timetable.delete_period'   => 'DELETE PERIOD',
            'education.delete_class'    => 'DELETE CLASS',
            'education.delete_term'     => 'DELETE SEMESTER',
            'education.sync_types'      => 'SYNC TYPES',
            'subjects.delete_subject'   => 'DELETE SUBJECT',
            'subjects.apply_template'   => 'APPLY SCHEME',
            'users.delete'              => 'DELETE USER',
            'test_seed.clear'           => 'REMOVE PRACTICE MEMBERS',
            'app_release.delete_apk'    => 'DELETE APK',
            'app_release.force_update'  => 'FORCE UPDATE',
            'members.import'            => 'IMPORT MEMBERS',
            'guardians.link_all'        => 'LINK ALL',
            'finance.delete_transaction'=> 'DELETE TRANSACTION',
            'groups.delete_group'       => 'DELETE GROUP',
        ];
    }

    function dc_normalize(?string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', (string)$s);
        return strtoupper(trim((string)$s));
    }

    /** True when the request carries the phrase for $key. */
    function dc_phrase_ok(string $key, ?array $src = null): bool
    {
        $map = dc_phrases();
        if (!isset($map[$key])) {
            return false;
        }
        $src = $src ?? $_POST;
        return hash_equals($map[$key], dc_normalize($src['confirm_phrase'] ?? ''));
    }

    /** JSON body returned when the phrase is missing/wrong. */
    function dc_refusal(string $key): array
    {
        $phrase = dc_phrases()[$key] ?? 'CONFIRM';
        $msg = 'This action needs typed confirmation. Reload the page, try again and type ' . $phrase . ' when asked. Nothing was changed.';
        return [
            'status'             => 'error',
            'success'            => false,
            'ok'                 => false,
            'code'               => 'confirm_phrase_required',
            'needs_confirmation' => true,
            'confirm_phrase'     => $phrase,
            'message'            => $msg,
            'error'              => $msg,
        ];
    }

    /**
     * Stop the request with a JSON refusal unless the phrase for $key was sent.
     * HTTP 409 (Conflict) so it is never mistaken for success.
     */
    function dc_require_phrase(string $key, ?array $src = null): void
    {
        if (dc_phrase_ok($key, $src)) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(dc_refusal($key), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
