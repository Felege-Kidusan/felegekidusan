<?php
/**
 * Ministry three-category model — the ONLY source of truth.
 *
 *   A = ህጻናት      (stored age_group '7_13')
 *   B = ማዕከላዊያን   (stored age_group '14_17')
 *   C = ወጣቶች      (stored age_group '18_plus')
 *
 * The retired fourth group 'under6' (አጸደ ህጻናት) is normalized to '7_13'
 * on read so stale rows and old filters keep working during rollout.
 * Section assignment is manual everywhere; nothing in this class assigns.
 */

namespace App\Services;

final class MemberCategory
{
    public const LETTER_A = 'A';
    public const LETTER_B = 'B';
    public const LETTER_C = 'C';

    /** @var array<string,string> letter => stored age_group */
    private const BY_LETTER = [
        self::LETTER_A => '7_13',
        self::LETTER_B => '14_17',
        self::LETTER_C => '18_plus',
    ];

    /** @var array<string,string> Amharic labels keyed by letter */
    private const LABELS_AM = [
        self::LETTER_A => 'ህጻናት',
        self::LETTER_B => 'ማዕከላዊያን',
        self::LETTER_C => 'ወጣቶች',
    ];

    /** @var array<string,string> */
    private const LABELS_EN = [
        self::LETTER_A => 'Children',
        self::LETTER_B => 'Intermediate',
        self::LETTER_C => 'Youth',
    ];

    /**
     * Normalize any historical age_group value (including the retired
     * 'under6') onto one of the three canonical groups. Unknown values
     * return null — callers decide display fallback.
     */
    public static function normalizeGroup(?string $ageGroup): ?string
    {
        $group = strtolower(trim((string)$ageGroup));
        if ($group === '') {
            return null;
        }
        if ($group === 'under6' || $group === 'under 6') {
            return self::BY_LETTER[self::LETTER_A];
        }
        return in_array($group, self::BY_LETTER, true) ? $group : null;
    }

    /** Category letter for a stored age_group ('A'|'B'|'C'|null). */
    public static function letterFor(?string $ageGroup): ?string
    {
        $normalized = self::normalizeGroup($ageGroup);
        if ($normalized === null) {
            return null;
        }
        foreach (self::BY_LETTER as $letter => $group) {
            if ($group === $normalized) {
                return $letter;
            }
        }
        return null;
    }

    public static function groupFor(string $letter): ?string
    {
        return self::BY_LETTER[strtoupper($letter)] ?? null;
    }

    /** @return array<string,string> letter => Amharic label */
    public static function labelsAm(): array
    {
        return self::LABELS_AM;
    }

    public static function labelAm(string $letter): string
    {
        return self::LABELS_AM[strtoupper($letter)] ?? '';
    }

    public static function labelEn(string $letter): string
    {
        return self::LABELS_EN[strtoupper($letter)] ?? '';
    }

    /** @return list<string> */
    public static function letters(): array
    {
        return [self::LETTER_A, self::LETTER_B, self::LETTER_C];
    }

    /** @return list<string> canonical age_group values */
    public static function groups(): array
    {
        return array_values(self::BY_LETTER);
    }
}
