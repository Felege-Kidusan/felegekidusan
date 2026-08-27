<?php
/**
 * Presentation rules for member identity codes.
 *
 * Staff codes follow {DEPT}{H|N}{POSITIONS}-{tail} where H marks a
 * department head and N an ordinary department member. Leadership asked
 * that the N marker be rendered SMALLER than the other letters on every
 * place a code is displayed (ID cards, verification page, printouts) so
 * head vs. ordinary status is readable at a glance.
 *
 * All output is HTML-escaped here — callers must print the returned
 * string raw (it is already safe), never escape it a second time.
 */
namespace App\Services;

final class MemberCodeFormat
{
    /** Ordinary-member marker that renders smaller. */
    public const MINOR_MARKER = 'N';

    /**
     * HTML for a member code with the N marker typeset smaller.
     * Student codes (A1, B12…) pass through escaped unchanged.
     */
    public static function html(?string $code): string
    {
        $code = trim((string)$code);
        if ($code === '') {
            return '—';
        }
        $escaped = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        // Staff codes contain the '-' tail separator; student codes don't.
        if (strpos($escaped, '-') === false) {
            return $escaped;
        }
        [$head, $tail] = explode('-', $escaped, 2);
        $renderedHead = str_replace(
            self::MINOR_MARKER,
            '<span class="mc-min" style="font-size:0.72em">' . self::MINOR_MARKER . '</span>',
            $head
        );
        return $renderedHead . '-' . $tail;
    }

    /**
     * Plain-text form (for exports / filenames / JSON payloads).
     */
    public static function text(?string $code): string
    {
        return trim((string)$code);
    }
}
