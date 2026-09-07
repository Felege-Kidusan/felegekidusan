<?php
namespace App\Services;

require_once dirname(__DIR__) . '/ethiopian_date.php';

/** Display-only age calculation; never modifies a member's birth data. */
final class MemberAge
{
    public static function years(array $member, ?\DateTimeImmutable $today = null): ?int
    {
        $zone = new \DateTimeZone('Africa/Addis_Ababa');
        $today = ($today ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone)->setTime(0, 0);
        $stored = (string)($member['date_of_birth'] ?? '');
        // DATE columns are Gregorian. Account for whether the birthday has
        // occurred, not just the difference between two Gregorian years.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $stored, $parts)
            && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            $born = \DateTimeImmutable::createFromFormat('!Y-m-d', $stored, $zone);
            if (!$born || $born > $today) return null;
            $age = $born->diff($today)->y;
            return $age <= 150 ? $age : null;
        }

        $year = (int)($member['dob_ec_year'] ?? 0);
        if ($year <= 0) return null;
        $now = \gregorian_to_ethiopian(\DateTime::createFromImmutable($today));
        $age = (int)$now['year'] - $year;
        $month = (int)($member['dob_ec_month'] ?? 0);
        $day = (int)($member['dob_ec_day'] ?? 0);
        // Legacy year-only records retain their year-based estimate. When
        // the full Ethiopian birthday is present, use it accurately.
        if ($month >= 1 && $month <= 13 && $day >= 1
            && $day <= ($month === 13 ? ($year % 4 === 3 ? 6 : 5) : 30)) {
            if ([(int)$now['month'], (int)$now['day']] < [$month, $day]) $age--;
        }
        return $age >= 0 && $age <= 150 ? $age : null;
    }
}
