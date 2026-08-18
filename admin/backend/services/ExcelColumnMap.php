<?php
/**
 * Excel column dictionary.
 *
 * Internal keys stay stable. Display headers are what staff see.
 * Import accepts display headers, old snake_case keys, and aliases.
 */

namespace App\Services;

class ExcelColumnMap
{
    /** Virtual columns that are not members.* fields. */
    public const VIRTUAL = ['class', 'class_code', 'class_name'];

    /**
     * key => [display header, aliases...]
     */
    private const META = [
        'member_code'        => ['Member Code'],
        'full_name_am'       => ['Full Name', 'full_name', 'name'],
        'baptismal_name'     => ['Christian Name', 'christian_name', 'Christian Name (የክርስትና ስም)', 'baptismal', 'baptismal_name'],
        'class'              => ['Class', 'class_code', 'class_name', 'grade', 'grade_code'],
        'current_section'    => ['Age Section', 'section'],
        'education_level'    => ['Education Level'],
        'gender'             => ['Gender'],
        'date_of_birth'      => ['Date of Birth', 'dob'],
        'age'                => ['Age'],
        'spiritual_education'=> ['Spiritual Education'],
        'member_type'        => ['Member Type'],
        'membership_tier'    => ['Membership Tier'],
        'status'             => ['Status'],
        'phone_primary'      => ['Primary Phone'],
        'phone_number'       => ['Phone Number', 'phone'],
        'alt_phone_number'   => ['Alt Phone'],
        'phone_guardian'     => ['Guardian Phone (legacy)'],
        'guardian_name'      => ['Guardian Name'],
        'guardian_phone1'    => ['Guardian Phone 1'],
        'guardian_phone2'    => ['Guardian Phone 2'],
        'address'            => ['Address'],
        'city'               => ['City'],
        'sub_city'           => ['Sub City'],
        'woreda'             => ['Woreda'],
        'house_number'       => ['House Number'],
        'work_profession'    => ['Profession'],
        'registered_at'      => ['Registered At'],
        'waiting_since'      => ['Waiting Since'],
    ];

    public static function columns(string $tier): array
    {
        if ($tier === 'temporary') {
            return [
                'full_name_am', 'baptismal_name',
                'current_section', 'education_level',
                'phone_primary', 'phone_number',
                'guardian_name', 'guardian_phone1',
                'waiting_since',
            ];
        }
        return [
            'member_code',
            'full_name_am',
            'baptismal_name',
            'class',
            'current_section',
            'education_level',
            'gender', 'date_of_birth', 'age',
            'spiritual_education', 'member_type', 'membership_tier',
            'status',
            'phone_primary', 'phone_number', 'alt_phone_number', 'phone_guardian',
            'guardian_name', 'guardian_phone1', 'guardian_phone2',
            'address', 'city', 'sub_city', 'woreda', 'house_number',
            'work_profession', 'registered_at',
        ];
    }

    public static function locked(string $tier): array
    {
        return $tier === 'temporary' ? [] : ['member_code'];
    }

    public static function header(string $key): string
    {
        return self::META[$key][0] ?? $key;
    }

    public static function headersFor(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::header($key);
        }
        return $out;
    }

    public static function resolveHeader(string $raw): ?string
    {
        $h = self::norm($raw);
        if ($h === '') {
            return null;
        }
        foreach (self::META as $key => $labels) {
            if (self::norm($key) === $h) {
                return $key;
            }
            foreach ($labels as $label) {
                if (self::norm($label) === $h) {
                    return $key;
                }
            }
        }
        return null;
    }

    public static function isVirtual(string $key): bool
    {
        return in_array($key, self::VIRTUAL, true);
    }

    public static function isClassColumn(string $key): bool
    {
        return in_array($key, ['class', 'class_code', 'class_name'], true);
    }

    public static function isDateColumn(string $key): bool
    {
        return in_array($key, ['date_of_birth', 'registered_at', 'waiting_since', 'joined_date'], true);
    }

    private static function norm(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = preg_replace('/[_\s]+/u', ' ', $s);
        $s = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
