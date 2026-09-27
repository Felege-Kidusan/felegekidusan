<?php
/**
 * Ministry three-category model — the ONLY source of truth.
 *
 *   A = ህጻናት      (stored age_group '7_13')
 *   B = ማዕከላዊያን   (stored age_group '14_17')
 *   C = ወጣቶች      (stored age_group '18_plus')
 *
 * The former fourth nursery group (አጸደ ህጻናት) has been REMOVED from the
 * system entirely (sql/017 merged legacy rows, sql/019 dropped the ENUM
 * value). Unknown or missing groups return null and callers keep the
 * member's code PENDING — categories are never guessed.
 * Section assignment is manual everywhere; nothing in this class assigns.
 *
 * P71: this class is also the single definition of the SECTION concept
 * (the age group's name). Every dashboard, filter, modal, report and the
 * data normalization (sql/041) derive section names and age ranges from
 * HERE — never from a hardcoded list. classes.section /
 * members.current_section store the canonical Amharic section name;
 * members.age_group / classes.age_group store the code below.
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
     * Section metadata keyed by the stored age_group code.
     * 'am'   = canonical section name (what classes.section /
     *          members.current_section store after sql/041)
     * 'en'   = English section name
     * 'ages' = human age-range label (change the range HERE — one place)
     */
    private const SECTION_META = [
        '7_13'    => ['am' => 'ህጻናት',    'en' => 'Children',     'ages' => '7–13'],
        '14_17'   => ['am' => 'ማዕከላዊያን', 'en' => 'Intermediate', 'ages' => '14–17'],
        '18_plus' => ['am' => 'ወጣቶች',    'en' => 'Youth',        'ages' => '18+'],
    ];

    /**
     * Legacy/variant section spellings seen in UIs and imports → canonical
     * Amharic section name. The education class modal historically wrote
     * ልጆች / ማእከላዊ / ሰበከላ (ሰበከላ was a mistranslation of ወጣቶች); sql/041
     * and the import gate normalize them through this map. Unknown values
     * return null from normalizeSectionAm() — never guessed.
     */
    private const SECTION_ALIASES = [
        'ልጆች'           => 'ህጻናት',
        'children'       => 'ህጻናት',
        'ህፃናት'          => 'ህጻናት',
        'ህጻናት (a)'       => 'ህጻናት',
        'ህፃናት (a)'       => 'ህጻናት',
        'a'              => 'ህጻናት',
        '7_13'           => 'ህጻናት',
        '7-13'           => 'ህጻናት',
        'ማእከላዊ'          => 'ማዕከላዊያን',
        'ማዕከላዊ'          => 'ማዕከላዊያን',
        'ማእከላዊያን'       => 'ማዕከላዊያን',
        'ማዕከላዊያን (b)'     => 'ማዕከላዊያን',
        'ማእከላዊያን (b)'     => 'ማዕከላዊያን',
        'middle'         => 'ማዕከላዊያን',
        'intermediate'   => 'ማዕከላዊያን',
        'b'              => 'ማዕከላዊያን',
        '14_17'          => 'ማዕከላዊያን',
        '14-17'          => 'ማዕከላዊያን',
        'ሰበከላ'          => 'ወጣቶች',
        'parish'         => 'ወጣቶች',
        'youth'          => 'ወጣቶች',
        'ወጣቶ'           => 'ወጣቶች',
        'ወጣት'           => 'ወጣቶች',
        'ወጣቶች (c)'       => 'ወጣቶች',
        'c'              => 'ወጣቶች',
        '18_plus'        => 'ወጣቶች',
        '18+'            => 'ወጣቶች',
    ];

    /**
     * Normalize any historical age_group value onto one of the three
     * canonical groups. Unknown values (including the removed 'under6')
     * return null — callers decide the fallback (usually: pending code).
     */
    public static function normalizeGroup(?string $ageGroup): ?string
    {
        $group = strtolower(trim((string)$ageGroup));
        if ($group === '') {
            return null;
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

    // ── P71: section dimension (name ⇄ age range ⇄ code) ────────

    /**
     * Full section list for UIs, keyed by stored age_group code.
     * @return array<string,array{am:string,en:string,ages:string,letter:string}>
     */
    public static function sections(): array
    {
        $out = [];
        foreach (self::SECTION_META as $code => $meta) {
            $out[$code] = [
                'am'     => $meta['am'],
                'en'     => $meta['en'],
                'ages'   => $meta['ages'],
                'letter' => (string)self::letterFor($code),
            ];
        }
        return $out;
    }

    /** Canonical Amharic section name for a stored age_group code (or null). */
    public static function sectionAm(?string $ageGroup): ?string
    {
        $normalized = self::normalizeGroup($ageGroup);
        return $normalized === null ? null : self::SECTION_META[$normalized]['am'];
    }

    /** Canonical English section name for a stored age_group code (or null). */
    public static function sectionEn(?string $ageGroup): ?string
    {
        $normalized = self::normalizeGroup($ageGroup);
        return $normalized === null ? null : self::SECTION_META[$normalized]['en'];
    }

    /** Human age-range label ('7–13' / '14–17' / '18+') for a code (or null). */
    public static function ageRangeLabel(?string $ageGroup): ?string
    {
        $normalized = self::normalizeGroup($ageGroup);
        return $normalized === null ? null : self::SECTION_META[$normalized]['ages'];
    }

    /** Stored age_group code for a canonical section name (or null). */
    public static function ageGroupForSectionAm(?string $name): ?string
    {
        $canonical = self::normalizeSectionAm($name);
        if ($canonical === null) {
            return null;
        }
        foreach (self::SECTION_META as $code => $meta) {
            if ($meta['am'] === $canonical) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Normalize any section spelling (canonical name, legacy variant or
     * alias) onto the canonical Amharic name. Unknown/empty → null —
     * callers decide the fallback (same doctrine as member codes).
     */
    public static function normalizeSectionAm(?string $value): ?string
    {
        $name = trim((string)$value);
        if ($name === '') {
            return null;
        }
        foreach (self::SECTION_META as $meta) {
            if ($meta['am'] === $name) {
                return $meta['am'];
            }
        }
        $key = mb_strtolower($name, 'UTF-8');
        foreach (self::SECTION_ALIASES as $alias => $canonical) {
            if (mb_strtolower($alias, 'UTF-8') === $key) {
                return $canonical;
            }
        }
        return null;
    }

    /**
     * Return canonical section name from section string and/or age_group.
     * Guaranteed never to return empty/null if either is resolvable.
     */
    public static function canonicalizeSection(?string $section, ?string $ageGroup = null): string
    {
        $s = self::normalizeSectionAm($section);
        if ($s !== null) {
            return $s;
        }
        if ($ageGroup !== null) {
            $fromAg = self::sectionAm($ageGroup);
            if ($fromAg !== null) {
                return $fromAg;
            }
        }
        $trimmed = trim((string)$section);
        return ($trimmed !== '' && $trimmed !== '—') ? $trimmed : '—';
    }

    /**
     * Generates a SQL CASE expression to evaluate the canonical section
     * name from table columns (evaluating section aliases first, then age_group fallback).
     */
    public static function sqlSectionExpr(string $tableAlias = 'm', string $column = 'current_section', string $ageGroupCol = 'age_group'): string
    {
        $col = $tableAlias !== '' ? "`$tableAlias`.`$column`" : "`$column`";
        $ag  = $tableAlias !== '' ? "`$tableAlias`.`$ageGroupCol`" : "`$ageGroupCol`";
        return "CASE
            WHEN NULLIF(TRIM($col), '') IN ('ህጻናት', 'ህፃናት', 'ልጆች', 'children', 'Children', 'ህጻናት (A)', 'ህጻናት (a)', 'A', 'a', '7_13', '7-13') THEN 'ህጻናት'
            WHEN NULLIF(TRIM($col), '') IN ('ማዕከላዊያን', 'ማዕከላዊ', 'ማእከላዊ', 'ማእከላዊያን', 'middle', 'Middle', 'intermediate', 'Intermediate', 'ማዕከላዊያን (B)', 'ማዕከላዊያን (b)', 'B', 'b', '14_17', '14-17') THEN 'ማዕከላዊያን'
            WHEN NULLIF(TRIM($col), '') IN ('ወጣቶች', 'ወጣት', 'ወጣቶ', 'ሰበከላ', 'parish', 'Parish', 'youth', 'Youth', 'ወጣቶች (C)', 'ወጣቶች (c)', 'C', 'c', '18_plus', '18+') THEN 'ወጣቶች'
            WHEN $ag = '7_13' THEN 'ህጻናት'
            WHEN $ag = '14_17' THEN 'ማዕከላዊያን'
            WHEN $ag IN ('18_plus', '18+') THEN 'ወጣቶች'
            WHEN NULLIF(TRIM($col), '') IS NOT NULL AND NULLIF(TRIM($col), '') != '—' THEN TRIM($col)
            ELSE '—'
        END";
    }
}
