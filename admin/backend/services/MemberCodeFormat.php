<?php
/**
 * Presentation rules for member identity codes — FORMAT v2.
 *
 * Codes are `{PREFIX}-{5-digit tail}`:
 *   students  A-76392            (pass through escaped unchanged)
 *   staff     DEDHT-98798 …      (ordinary marker N typeset smaller)
 *
 * Leadership asked that the N ordinary-member marker be rendered
 * SMALLER than the other letters on every place a code is displayed
 * (ID cards, verification page, printouts) so head vs. ordinary status
 * is readable at a glance.
 *
 * All output is HTML-escaped here — callers must print the returned
 * string raw (it is already safe), never escape it a second time.
 */
namespace App\Services;

require_once __DIR__ . '/IdentityCodeService.php';

final class MemberCodeFormat
{
    /** Ordinary-member marker that renders smaller. */
    public const MINOR_MARKER = 'N';

    /**
     * HTML for a member code with the N marker typeset smaller on staff
     * codes. Student codes and unknown/legacy shapes pass through
     * escaped unchanged.
     */
    public static function html(?string $code): string
    {
        $code = trim((string)$code);
        if ($code === '') {
            return '—';
        }
        $escaped = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        $parsed = IdentityCodeService::parse($code);
        if ($parsed === null || $parsed['kind'] === 'student') {
            return $escaped; // A-76392 has no head-marker semantics
        }

        [$head, $tail] = explode('-', $escaped, 2);
        $renderedHead = str_replace(
            self::MINOR_MARKER,
            '<span class="mc-min" style="font-size:0.72em">' . self::MINOR_MARKER . '</span>',
            $head
        );
        return $renderedHead . '-' . $tail;
    }

    /** Plain-text form (for exports / filenames / JSON payloads). */
    public static function text(?string $code): string
    {
        return trim((string)$code);
    }
}
