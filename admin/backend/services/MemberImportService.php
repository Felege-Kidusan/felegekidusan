<?php
/**
 * Pure helpers for the HR Excel member import (admin/api_import_members.php).
 *
 * Everything here is side-effect free so the rules can be unit-tested from
 * the CLI: cell cleaning, strict Ethiopian-calendar date parsing, gender
 * values, age and name splitting. The importer owns all database work.
 */

namespace App\Services;

require_once dirname(__DIR__) . '/ethiopian_date.php';
require_once __DIR__ . '/MemberAge.php';
require_once __DIR__ . '/MemberCategory.php';

final class MemberImportService
{
    /** Accepted gender spellings → stored enum value. */
    private const GENDERS = [
        'male' => 'male', 'm' => 'male', 'ወንድ' => 'male', 'ወ' => 'male',
        'female' => 'female', 'f' => 'female', 'ሴት' => 'female', 'ሴ' => 'female',
    ];

    /**
     * Trim a cell, map dash placeholders to empty, and undo the export's formula guard: MemberExportService
     * prefixes "'" to values starting with = + - @ (e.g. "+2519…"), so a
     * re-uploaded export would otherwise store "'+2519…".
     */
    public static function cleanCell($value): string
    {
        $value = trim((string)$value);
        if (strlen($value) >= 2 && $value[0] === "'" && preg_match('/^[=+\-@\t\r]/u', substr($value, 1))) {
            $value = trim(substr($value, 1));
        }
        // A cell holding only dashes ("-", "--", "—") is a "no data"
        // placeholder, never a real phone/name: treat it as empty.
        if (preg_match('/^[\-\x{2013}\x{2014}]+$/u', $value)) {
            return '';
        }
        return $value;
    }

    /** Collapse runs of whitespace inside a name to single spaces. */
    public static function cleanName(string $value): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    /** 'male' | 'female' | null (unknown spelling). */
    public static function normalizeGender(string $value): ?string
    {
        $key = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        return self::GENDERS[$key] ?? null;
    }

    /** Last valid day of an Ethiopian month (Pagume: 6 in EC leap years, else 5). */
    public static function ecMonthDays(int $year, int $month): int
    {
        if ($month === 13) {
            return ($year % 4 === 3) ? 6 : 5;
        }
        return 30;
    }

    /**
     * Parse an Ethiopian-calendar date written yyyy-mm-dd / yyyy/mm/dd or
     * dd/mm/yyyy / dd-mm-yyyy (also "." separators).
     *
     * @return array{ok:bool, y?:int, m?:int, d?:int, gregorian?:string, error?:string}
     */
    public static function parseEcDate(string $value, ?\DateTimeImmutable $today = null): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $p)) {
            [$y, $m, $d] = [(int)$p[1], (int)$p[2], (int)$p[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $p)) {
            [$d, $m, $y] = [(int)$p[1], (int)$p[2], (int)$p[3]];
        } else {
            return ['ok' => false, 'error' => 'format'];
        }
        if ($y < 1900 || $y > 2100) {
            return ['ok' => false, 'error' => 'year'];
        }
        if ($m < 1 || $m > 13) {
            return ['ok' => false, 'error' => 'month'];
        }
        if ($d < 1 || $d > self::ecMonthDays($y, $m)) {
            return ['ok' => false, 'error' => 'day'];
        }
        $greg = \ethiopian_to_gregorian($y, $m, $d);
        $back = \gregorian_to_ethiopian($greg);
        if ((int)$back['year'] !== $y || (int)$back['month'] !== $m || (int)$back['day'] !== $d) {
            return ['ok' => false, 'error' => 'day'];
        }
        $zone = new \DateTimeZone('Africa/Addis_Ababa');
        $today = ($today ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
        if ($greg->format('Y-m-d') > $today->format('Y-m-d')) {
            return ['ok' => false, 'error' => 'future'];
        }
        return ['ok' => true, 'y' => $y, 'm' => $m, 'd' => $d, 'gregorian' => $greg->format('Y-m-d')];
    }

    /** Human explanation for a parseEcDate() error code. */
    public static function dateErrorText(string $code): string
    {
        switch ($code) {
            case 'format': return 'use the Ethiopian date as yyyy-mm-dd or dd/mm/yyyy';
            case 'year':   return 'the year is out of range';
            case 'month':  return 'the month must be 1–13';
            case 'day':    return 'the day does not exist in that Ethiopian month';
            case 'future': return 'the date is in the future';
            default:       return 'invalid date';
        }
    }

    /** Whole-year age for a Gregorian birth date, same rule as member profiles. */
    public static function ageFromGregorian(string $gregorian, ?\DateTimeImmutable $today = null): ?int
    {
        return MemberAge::years(['date_of_birth' => $gregorian], $today);
    }

    /**
     * Section/age-group decision: age from the birth date when present,
     * otherwise a numeric Age cell. null = unknown.
     */
    public static function ageGroupFor(?int $age): ?string
    {
        return MemberCategory::groupForAge($age);
    }

    /** @return array{0:string,1:string,2:?string} student, father, grandfather */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName), 3) ?: [];
        return [(string)($parts[0] ?? ''), (string)($parts[1] ?? ''), isset($parts[2]) ? (string)$parts[2] : null];
    }
}
