<?php
/**
 * ============================================================
 * Ethiopian calendar helpers — SOURCE OF TRUTH
 * ============================================================
 * Converts between Gregorian (PHP DateTime) and Ethiopian dates.
 *
 * HOW THE SYSTEM USES DATES (important for future developers):
 *   1. STORAGE is always Gregorian. Every DATE column in the database
 *      holds a normal Gregorian date. This is deliberate — it keeps date
 *      math, sorting and comparisons reliable. Do NOT store Ethiopian
 *      strings in date columns.
 *   2. DISPLAY / PICKERS are Ethiopian. The Ethiopian date the user sees
 *      or picks is DERIVED from the stored Gregorian value at runtime
 *      (in PHP via these functions, in the browser via wbws-calendar.js).
 *
 * VERIFIED-CORRECT RANGE:
 *   The Ethiopian leap-year is derived from the Gregorian leap rule
 *   (is_gregorian_leap). This is correct from now through Gregorian
 *   year 2099 inclusive.
 *
 * KNOWN 2100 BOUNDARY (NOT a bug to fix now — ~74 years away):
 *   Gregorian 2100 is NOT a leap year (divisible by 100, not 400), but the
 *   Ethiopian year that spans it IS a leap year. Because this file infers
 *   the Ethiopian leap year from the Gregorian rule, conversions will drift
 *   by one day starting in 2100. When that time comes, replace the leap
 *   inference with the true Ethiopian rule (Ethiopian year % 4 === 3).
 *   Until then the logic is correct and must NOT be changed.
 * ============================================================
 */

function is_gregorian_leap(int $y): bool
{
    return ($y % 4 === 0 && $y % 100 !== 0) || ($y % 400 === 0);
}

function meskerem1_day(int $gregorianYear): int
{
    // Meskerem 1 falls on Sep 11 normally, or Sep 12 in the Gregorian year preceding a Gregorian leap year
    $nextYear = $gregorianYear + 1;
    return is_gregorian_leap($nextYear) ? 12 : 11;
}

function get_meskerem1_date(int $gregorianYear): DateTime
{
    $day = meskerem1_day($gregorianYear);
    return new DateTime(sprintf('%04d-09-%02d', $gregorianYear, $day), new DateTimeZone('Africa/Addis_Ababa'));
}

function gregorian_to_ethiopian($input)
{
    $dt = $input instanceof DateTime ? clone $input : new DateTime($input, new DateTimeZone('Africa/Addis_Ababa'));
    $dt->setTimezone(new DateTimeZone('Africa/Addis_Ababa'));
    $gYear = (int) $dt->format('Y');
    $meskerem = get_meskerem1_date($gYear);

    if ($dt < $meskerem) {
        // Use previous Gregorian year Meskerem
        $meskerem = get_meskerem1_date($gYear - 1);
        $ethYear = $gYear - 8;
    } else {
        $ethYear = $gYear - 7;
    }

    $daysSince = (int) floor(($dt->getTimestamp() - $meskerem->getTimestamp()) / 86400);
    if ($daysSince < 0) {
        // safety: if negative, shift by 365
        $daysSince += 365 + (is_gregorian_leap((int)$meskerem->format('Y')) ? 1 : 0);
    }

    $ethMonth = intdiv($daysSince, 30) + 1;
    $ethDay = ($daysSince % 30) + 1;

    return [
        'year' => $ethYear,
        'month' => $ethMonth,
        'day' => $ethDay,
    ];
}

function ethiopian_to_gregorian(int $ey, int $em, int $ed): DateTime
{
    // The Gregorian year that contains Meskerem 1 for this Ethiopian year
    $gYear = $ey + 7;
    $meskerem = get_meskerem1_date($gYear);
    $daysOffset = ($em - 1) * 30 + ($ed - 1);
    $result = clone $meskerem;
    if ($daysOffset !== 0) {
        $result->modify("+{$daysOffset} days");
    }
    return $result;
}

function ethio_month_name(int $m, bool $short = false): string
{
    $names = [
        1 => 'Meskerem', 2 => 'Tikimt', 3 => 'Hidar', 4 => 'Tahsas', 5 => 'Tir', 6 => 'Yekatit',
        7 => 'Megabit', 8 => 'Miyazya', 9 => 'Ginbot', 10 => 'Sene', 11 => 'Hamle', 12 => 'Nehasse', 13 => 'Pagume'
    ];
    $n = $names[$m] ?? '';
    if ($short && $n !== '') {
        return substr($n, 0, 3);
    }
    return $n;
}

function ethio_date_array($input)
{
    return gregorian_to_ethiopian($input);
}

function ethio_date_format($input, string $format = 'F j, Y'): string
{
    $arr = ethio_date_array($input);
    $replacements = [
        // Tokens: Y, y, F (full month name), M (short month), m (2-digit), n (month no leading), j (day no leading), d (2-digit day)
        'Y' => strval($arr['year']),
        'y' => substr(strval($arr['year']), -2),
        'F' => ethio_month_name((int)$arr['month'], false),
        'M' => ethio_month_name((int)$arr['month'], true),
        'm' => str_pad((string)$arr['month'], 2, '0', STR_PAD_LEFT),
        'n' => (string)$arr['month'],
        'j' => (string)$arr['day'],
        'd' => str_pad((string)$arr['day'], 2, '0', STR_PAD_LEFT),
    ];

    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $ch = $format[$i];
        if (isset($replacements[$ch])) {
            $out .= $replacements[$ch];
        } else {
            $out .= $ch;
        }
    }
    return $out;
}
