<?php
/**
 * ============================================================================
 * ReportRegistry — the single source of truth for the Reporting Centre (V2).
 * ============================================================================
 *
 * Declares, in plain PHP data, everything a report may be built from:
 *
 *   DATASETS  – a base table plus the LEFT JOINs that may be attached to it.
 *   FIELDS    – every column/expression a user may filter, group, sort, list
 *               or export. Each field carries:
 *                 - label_en / label_am  → UI and export headers use the same words
 *                 - type                 → decides which operators are legal
 *                 - sql                  → the ONLY place a SQL expression lives
 *                 - view / export        → role lists (field‑level security,
 *                                          PowerSchool "FLS" model); export ⊆ view
 *                 - pii                  → counted in the audit row of every export
 *   METRICS   – aggregate measures with a human definition (the glossary).
 *   OPERATORS – per type; the query service refuses anything else.
 *
 * Clients never send SQL. They send field keys, operator keys and values;
 * ReportQueryService turns a validated definition into ONE prepared SELECT.
 *
 * Adding a department later (Education, Mezmur, Finance) = adding a dataset
 * and fields here — no new endpoint, no new page.
 *
 * Shared‑hosting safe: pure PHP, no extensions, no Composer package.
 */

declare(strict_types=1);

namespace App\Reporting;

final class ReportRegistry
{
    /** Roles that may open the centre at all (mirrors access_control.php). */
    public const READER_ROLES = ['super_admin', 'school_admin', 'info_dept', 'hr_dept'];

    /** Shorthands used in field definitions. */
    private const R_ALL   = ['super_admin', 'school_admin', 'info_dept', 'hr_dept'];
    private const R_ADMIN = ['super_admin', 'school_admin'];
    private const R_HR    = ['super_admin', 'school_admin', 'hr_dept'];

    /** Hard budgets (the query service enforces them; tests pin them). */
    public const MAX_PAGE_SIZE   = 200;
    public const MAX_LIST_ROWS   = 2000;   // deepest offset+page the list may reach
    public const MAX_EXPORT_ROWS = 5000;   // same ceiling as MemberReportService
    public const MAX_GROUPS      = 60;     // aggregate buckets before "other"
    public const MAX_FILTERS     = 20;
    public const MAX_IN_VALUES   = 50;
    public const MAX_TEXT_LEN    = 100;
    public const MAX_COLUMNS     = 40;

    /** Current / previous academic year (Education data) as scalar sub‑selects. */
    public const SQL_CURRENT_AY       = '(SELECT ay.id FROM `academic_years` ay WHERE ay.is_current = 1 ORDER BY ay.id DESC LIMIT 1)';
    public const SQL_CURRENT_AY_START = "COALESCE((SELECT ay.start_date FROM `academic_years` ay WHERE ay.is_current = 1 ORDER BY ay.id DESC LIMIT 1), '1000-01-01')";
    public const SQL_CURRENT_AY_END   = "COALESCE((SELECT ay.end_date FROM `academic_years` ay WHERE ay.is_current = 1 ORDER BY ay.id DESC LIMIT 1), '9999-12-31')";
    public const SQL_PREVIOUS_AY      = '(SELECT ay2.id FROM `academic_years` ay2 WHERE ay2.start_date < (SELECT ay.start_date FROM `academic_years` ay WHERE ay.is_current = 1 ORDER BY ay.id DESC LIMIT 1) ORDER BY ay2.start_date DESC, ay2.id DESC LIMIT 1)';

    /** Starter‑question groups (UI grouping only). */
    public const STARTER_GROUPS = [
        'membership'   => ['en' => 'Membership',     'am' => 'አባልነት'],
        'promotion'    => ['en' => 'Promotion',      'am' => 'ቋሚ አባልነት'],
        'follow_up'    => ['en' => 'Follow‑up',      'am' => 'ክትትል'],
        'data_quality' => ['en' => 'Data quality',   'am' => 'የመረጃ ጥራት'],
    ];

    /** Field categories (UI grouping only; keys are stable). */
    public const CATEGORIES = [
        'identity'   => ['en' => 'Identity',              'am' => 'ማንነት'],
        'membership' => ['en' => 'Membership',            'am' => 'አባልነት'],
        'education'  => ['en' => 'Education (enrolment)', 'am' => 'ትምህርት (ምዝገባ)'],
        'attendance' => ['en' => 'Attendance',            'am' => 'ክትትል'],
        'contact'    => ['en' => 'Contact & address',     'am' => 'አድራሻ'],
        'guardian'   => ['en' => 'Guardian & family',     'am' => 'አሳዳጊ እና ቤተሰብ'],
        'quality'    => ['en' => 'Record quality',        'am' => 'የመረጃ ጥራት'],
        'staff'      => ['en' => 'Roles & staff',         'am' => 'ኃላፊነት'],
        'system'     => ['en' => 'System',                'am' => 'ሥርዓት'],
    ];

    /**
     * Ethiopian year of a Gregorian DATE expression, in SQL.
     * New year = 11 Sept, or 12 Sept when the FOLLOWING Gregorian year is leap
     * (the Ethiopian leap day falls the year before the Gregorian one).
     */
    private const SQL_EC_YEAR = "(YEAR(%1\$s) - IF(DATE_FORMAT(%1\$s,'%%m%%d') >= IF(((YEAR(%1\$s)+1) %% 4 = 0 AND (YEAR(%1\$s)+1) %% 100 <> 0) OR (YEAR(%1\$s)+1) %% 400 = 0, '0912', '0911'), 7, 8))";

    /**
     * Datasets: base table + optional joins keyed by alias.
     * A join is attached only when a selected/filtered field requires it.
     */
    public static function datasets(): array
    {
        return [
            'members' => [
                'label_en' => 'Members',
                'label_am' => 'አባላት',
                'base'     => '`members` m',
                'pk'       => 'm.id',
                'joins'    => [
                    // ── Education (read‑only): current / previous academic year enrolment ──
                    // Each join is pinned to ONE row per member (MAX(id) sub‑select) so a
                    // member can never be duplicated by a second enrolment row.
                    'ce'  => "LEFT JOIN `class_enrollments` ce ON ce.id = (SELECT MAX(e.id) FROM `class_enrollments` e "
                           . "WHERE e.member_id = m.id AND e.status = 'active' AND e.academic_year_id = " . self::SQL_CURRENT_AY . ")",
                    'cl'  => "LEFT JOIN `classes` cl ON cl.id = ce.class_id",
                    // Class teacher = the flagged class/homeroom teacher, else the primary subject teacher of that class.
                    'ct'  => "LEFT JOIN (SELECT ta.class_id, SUBSTRING_INDEX(GROUP_CONCAT(u.full_name ORDER BY (ta.is_class_teacher = 1 OR ta.assignment_role = 'homeroom') DESC, ta.is_primary DESC, ta.id ASC SEPARATOR '\\n'), '\\n', 1) AS teacher_name "
                           . "FROM `teacher_assignments` ta JOIN `users` u ON u.id = ta.teacher_id "
                           . "WHERE ta.is_active = 1 AND ta.academic_year_id = " . self::SQL_CURRENT_AY . " GROUP BY ta.class_id) ct ON ct.class_id = ce.class_id",
                    'pe'  => "LEFT JOIN `class_enrollments` pe ON pe.id = (SELECT MAX(e.id) FROM `class_enrollments` e "
                           . "WHERE e.member_id = m.id AND e.academic_year_id = " . self::SQL_PREVIOUS_AY . ")",
                    'pcl' => "LEFT JOIN `classes` pcl ON pcl.id = pe.class_id",
                    // ── Attendance bands (Education + Mezmur, read‑only), current academic‑year date range ──
                    // Holidays are excluded; late counts as attended (same rule as InfoAnalyticsService).
                    'ea'  => "LEFT JOIN (SELECT a.member_id, COUNT(*) AS sessions, SUM(a.status IN ('present','late')) AS attended, "
                           . "SUM(a.status = 'absent') AS absent, MAX(CASE WHEN a.status IN ('present','late') THEN a.attendance_date END) AS last_present, "
                           . "SUM(a.status = 'absent' AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS absent_30d "
                           . "FROM `attendance` a WHERE a.status <> 'holiday' AND a.attendance_date BETWEEN " . self::SQL_CURRENT_AY_START . " AND " . self::SQL_CURRENT_AY_END . " "
                           . "GROUP BY a.member_id) ea ON ea.member_id = m.id",
                    // Mezmur rows are date‑based (sql/023); legacy rows may only carry a session_id.
                    'ma'  => "LEFT JOIN (SELECT x.member_id, COUNT(*) AS sessions, SUM(x.status IN ('present','late')) AS attended, "
                           . "MAX(CASE WHEN x.status IN ('present','late') THEN COALESCE(x.attendance_date, s.session_date) END) AS last_present "
                           . "FROM `mezmur_attendance` x LEFT JOIN `mezmur_sessions` s ON s.id = x.session_id "
                           . "WHERE (s.id IS NULL OR s.status = 'active') AND COALESCE(x.attendance_date, s.session_date) BETWEEN " . self::SQL_CURRENT_AY_START . " AND " . self::SQL_CURRENT_AY_END . " "
                           . "GROUP BY x.member_id) ma ON ma.member_id = m.id",
                    // ── Family Link (HR data): the primary / first guardian record ──
                    'mgp' => "LEFT JOIN `member_guardians` mgp ON mgp.member_id = m.id AND mgp.guardian_id = (SELECT mg.guardian_id FROM `member_guardians` mg "
                           . "WHERE mg.member_id = m.id ORDER BY mg.is_primary DESC, mg.contact_priority ASC, mg.guardian_id ASC LIMIT 1)",
                    'g'   => "LEFT JOIN `guardians` g ON g.id = mgp.guardian_id",
                ],
                'join_requires' => ['cl' => ['ce'], 'ct' => ['ce'], 'pcl' => ['pe'], 'g' => ['mgp']],
                'owner_en' => 'Information department (directory), HR (registration & Family Link), Education (enrolment, attendance), Mezmur (attendance)',
            ],
        ];
    }

    /**
     * Field catalogue. Keys are stable identifiers used by the UI, saved
     * reports and tests. Never rename a key — add a new one and deprecate.
     *
     * type: string | enum | int | date | bool | derived_enum
     * flags: filter, group, sort, list (default true), search (text search)
     */
    public static function fields(): array
    {
        static $fields = null;
        if ($fields !== null) {
            return $fields;
        }

        $ecRegistered = sprintf(self::SQL_EC_YEAR, 'm.registered_at');

        $f = [
            // ───────────── Identity ─────────────
            'id' => [
                'label_en' => 'Internal id', 'label_am' => 'የውስጥ መለያ', 'type' => 'int',
                'sql' => 'm.id', 'view' => self::R_ALL, 'export' => [],
                'filter' => false, 'group' => false, 'sort' => false, 'hidden' => true,
            ],
            'member_code' => [
                'label_en' => 'Member ID', 'label_am' => 'የአባል መለያ', 'type' => 'string',
                'sql' => 'm.member_code', 'view' => self::R_ALL, 'export' => self::R_ALL,
                'search' => true, 'group' => false,
            ],
            'full_name_am' => [
                'label_en' => 'Full name (Amharic)', 'label_am' => 'ሙሉ ስም', 'type' => 'string',
                'sql' => 'm.full_name_am', 'view' => self::R_ALL, 'export' => self::R_ALL,
                'search' => true, 'group' => false,
            ],
            'full_name_en' => [
                'label_en' => 'Full name (English)', 'label_am' => 'ሙሉ ስም (እንግሊዝኛ)', 'type' => 'string',
                'sql' => 'm.full_name_en', 'view' => self::R_ALL, 'export' => self::R_ALL,
                'search' => true, 'group' => false,
            ],
            'baptismal_name' => [
                'label_en' => 'Baptismal name', 'label_am' => 'የክርስትና ስም', 'type' => 'string',
                'sql' => 'm.baptismal_name', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'gender' => [
                'label_en' => 'Gender', 'label_am' => 'ጾታ', 'type' => 'enum',
                'sql' => 'm.gender', 'options' => ['male' => 'Male', 'female' => 'Female'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'age' => [
                'label_en' => 'Age', 'label_am' => 'ዕድሜ', 'type' => 'int',
                'sql' => 'm.age', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'age_band' => [
                'label_en' => 'Age band', 'label_am' => 'የዕድሜ ክልል', 'type' => 'derived_enum',
                'sql' => "CASE WHEN m.age IS NULL THEN 'unknown' WHEN m.age < 7 THEN 'under_7' WHEN m.age <= 13 THEN '7_13' WHEN m.age <= 17 THEN '14_17' WHEN m.age <= 25 THEN '18_25' WHEN m.age <= 35 THEN '26_35' ELSE '36_plus' END",
                'options' => ['under_7' => 'Under 7', '7_13' => '7–13', '14_17' => '14–17', '18_25' => '18–25', '26_35' => '26–35', '36_plus' => '36+', 'unknown' => 'Unknown'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'sort' => false,
            ],
            'age_group' => [
                'label_en' => 'Section (age group)', 'label_am' => 'ክፍል', 'type' => 'enum',
                'sql' => 'm.age_group',
                'options' => ['7_13' => 'A · Children (7–13)', '14_17' => 'B · Intermediate (14–17)', '18_plus' => 'C · Youth (18+)'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'date_of_birth' => [
                'label_en' => 'Date of birth', 'label_am' => 'የትውልድ ቀን', 'type' => 'date',
                'sql' => 'm.date_of_birth', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'dob_ec_year' => [
                'label_en' => 'Birth year (E.C.)', 'label_am' => 'የትውልድ ዓመት (ዓ.ም.)', 'type' => 'int',
                'sql' => 'm.dob_ec_year', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],

            // ───────────── Membership ─────────────
            'status' => [
                'label_en' => 'Status', 'label_am' => 'ሁኔታ', 'type' => 'enum',
                'sql' => 'm.status',
                'options' => ['active' => 'Active', 'warning' => 'Warning', 'inactive' => 'Inactive', 'archived' => 'Archived'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'member_type' => [
                'label_en' => 'Member type', 'label_am' => 'የአባልነት ዓይነት', 'type' => 'enum',
                'sql' => 'm.member_type',
                'options' => ['regular' => 'Regular', 'special_regular' => 'Special regular', 'honorary' => 'Honorary'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'membership_tier' => [
                'label_en' => 'Membership tier', 'label_am' => 'የአባልነት ደረጃ', 'type' => 'enum',
                'sql' => "COALESCE(m.membership_tier, 'permanent')",
                'options' => ['temporary' => 'Temporary', 'permanent' => 'Permanent'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'registration_type' => [
                'label_en' => 'Registration type', 'label_am' => 'የምዝገባ ዓይነት', 'type' => 'enum',
                'sql' => 'm.registration_type',
                'options' => ['waiting' => 'Waiting list', 'direct' => 'Direct', 'transfer' => 'Transfer'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'registered_at' => [
                'label_en' => 'Registered on', 'label_am' => 'የተመዘገበበት ቀን', 'type' => 'date',
                'sql' => 'm.registered_at', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'registered_month' => [
                'label_en' => 'Registration month', 'label_am' => 'የምዝገባ ወር', 'type' => 'string',
                'sql' => "DATE_FORMAT(m.registered_at, '%Y-%m')", 'view' => self::R_ALL, 'export' => self::R_ALL,
                'filter' => false, 'list' => false,
            ],
            'registered_ec_year' => [
                'label_en' => 'Registration year (E.C.)', 'label_am' => 'የምዝገባ ዓመት (ዓ.ም.)', 'type' => 'int',
                'sql' => $ecRegistered, 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'tenure_months' => [
                'label_en' => 'Months since registration', 'label_am' => 'ከተመዘገበ ወራት', 'type' => 'int',
                'sql' => 'TIMESTAMPDIFF(MONTH, m.registered_at, CURDATE())', 'view' => self::R_ALL, 'export' => self::R_ALL,
                'group' => false,
            ],
            'promotion_eligible' => [
                'label_en' => 'Eligible for permanent membership', 'label_am' => 'ለቋሚ አባልነት ብቁ', 'type' => 'bool',
                'sql' => "(m.membership_tier = 'temporary' AND m.status <> 'archived' AND m.registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH))",
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Temporary member, not archived, registered 6 or more months ago (same rule as the HR eligibility notification).',
            ],
            'promoted_at' => [
                'label_en' => 'Promoted on', 'label_am' => 'ቋሚ የሆነበት ቀን', 'type' => 'date',
                'sql' => 'm.promoted_at', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'waiting_since' => [
                'label_en' => 'Waiting since', 'label_am' => 'ከሚጠብቅበት ቀን', 'type' => 'date',
                'sql' => 'm.waiting_since', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'academic_status' => [
                'label_en' => 'Academic status', 'label_am' => 'የትምህርት ሁኔታ', 'type' => 'string',
                'sql' => 'm.academic_status', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],

            // ───────────── Education profile ─────────────
            'education_level' => [
                'label_en' => 'Education level', 'label_am' => 'የትምህርት ደረጃ', 'type' => 'string',
                'sql' => 'm.education_level', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'spiritual_education' => [
                'label_en' => 'Spiritual education', 'label_am' => 'መንፈሳዊ ትምህርት', 'type' => 'string',
                'sql' => 'm.spiritual_education', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'work_profession' => [
                'label_en' => 'Profession', 'label_am' => 'ሙያ', 'type' => 'string',
                'sql' => 'm.work_profession', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'current_class' => [
                'label_en' => 'Current class', 'label_am' => 'የአሁኑ ክፍል', 'type' => 'string',
                'sql' => 'cl.class_name', 'requires' => ['ce', 'cl'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Active enrolment in the current academic year (Education department data, read‑only).',
            ],
            'is_enrolled' => [
                'label_en' => 'Enrolled this year', 'label_am' => 'በዚህ ዓመት ተመዝግቧል', 'type' => 'bool',
                'sql' => '(ce.id IS NOT NULL)', 'requires' => ['ce'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'class_section' => [
                'label_en' => 'Class section', 'label_am' => 'የክፍል ምድብ', 'type' => 'string',
                'sql' => 'cl.section', 'requires' => ['ce', 'cl'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'enrolled_at' => [
                'label_en' => 'Enrolled on', 'label_am' => 'የተመዘገበበት ቀን', 'type' => 'date',
                'sql' => 'ce.enrolled_at', 'requires' => ['ce'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Date of the active enrolment in the current academic year.',
            ],
            'class_teacher' => [
                'label_en' => 'Class teacher', 'label_am' => 'የክፍል መምህር', 'type' => 'string',
                'sql' => 'ct.teacher_name', 'requires' => ['ce', 'ct'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Class/homeroom teacher of the member\'s current class; if none is flagged, the primary subject teacher (Education data).',
            ],
            'previous_class' => [
                'label_en' => 'Previous year class', 'label_am' => 'የባለፈው ዓመት ክፍል', 'type' => 'string',
                'sql' => 'pcl.class_name', 'requires' => ['pe', 'pcl'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Latest enrolment (any status) in the academic year before the current one.',
            ],
            'progression' => [
                'label_en' => 'Year‑on‑year progression', 'label_am' => 'የዓመት ሽግግር', 'type' => 'derived_enum',
                'sql' => "CASE WHEN ce.id IS NULL AND pe.id IS NULL THEN 'none' WHEN ce.id IS NULL THEN 'not_re_enrolled' WHEN pe.id IS NULL THEN 'new' "
                       . "WHEN cl.level_order IS NULL OR pcl.level_order IS NULL THEN 'continuing' WHEN cl.level_order > pcl.level_order THEN 'promoted' "
                       . "WHEN cl.level_order = pcl.level_order THEN 'repeating' ELSE 'moved_down' END",
                'requires' => ['ce', 'cl', 'pe', 'pcl'],
                'options' => ['new' => 'New this year', 'promoted' => 'Promoted', 'repeating' => 'Repeating', 'moved_down' => 'Moved down',
                              'continuing' => 'Continuing', 'not_re_enrolled' => 'Not re‑enrolled', 'none' => 'Not enrolled either year'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'sort' => false,
                'definition_en' => 'Compares the current active enrolment with the previous year\'s class level (Education data). "Not re‑enrolled" = had a class last year, none this year.',
            ],

            // ───────────── Attendance (Education + Mezmur, current academic year, read‑only) ─────────────
            'edu_sessions' => [
                'label_en' => 'Edu sessions marked', 'label_am' => 'የትምህርት ክትትል ቀናት', 'type' => 'int',
                'sql' => 'COALESCE(ea.sessions, 0)', 'requires' => ['ea'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
                'definition_en' => 'Education attendance records for the member in the current academic year (holidays excluded).',
            ],
            'edu_attendance_rate' => [
                'label_en' => 'Edu attendance %', 'label_am' => 'የትምህርት ክትትል %', 'type' => 'int',
                'sql' => 'ROUND(100 * ea.attended / NULLIF(ea.sessions, 0))', 'requires' => ['ea'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
                'definition_en' => 'Present + late as a percentage of marked sessions this academic year; empty when nothing was marked.',
            ],
            'edu_attendance_band' => [
                'label_en' => 'Edu attendance band', 'label_am' => 'የትምህርት ክትትል ደረጃ', 'type' => 'derived_enum',
                'sql' => "CASE WHEN COALESCE(ea.sessions, 0) = 0 THEN 'none' WHEN 100 * ea.attended / ea.sessions >= 90 THEN 'excellent' "
                       . "WHEN 100 * ea.attended / ea.sessions >= 75 THEN 'good' WHEN 100 * ea.attended / ea.sessions >= 50 THEN 'fair' ELSE 'low' END",
                'requires' => ['ea'],
                'options' => ['excellent' => 'Excellent (≥90%)', 'good' => 'Good (75–89%)', 'fair' => 'Fair (50–74%)', 'low' => 'Low (<50%)', 'none' => 'No attendance marked'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'sort' => false,
                'definition_en' => 'Band of the education attendance rate this academic year.',
            ],
            'edu_last_present' => [
                'label_en' => 'Edu last present', 'label_am' => 'በትምህርት የመጨረሻ መገኘት', 'type' => 'date',
                'sql' => 'ea.last_present', 'requires' => ['ea'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'edu_absent_30d' => [
                'label_en' => 'Edu absences (last 30 days)', 'label_am' => 'የትምህርት መቅረት (ባለፉት 30 ቀናት)', 'type' => 'int',
                'sql' => 'COALESCE(ea.absent_30d, 0)', 'requires' => ['ea'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
                'definition_en' => 'Unexcused absences recorded in the last 30 days.',
            ],
            'mezmur_sessions' => [
                'label_en' => 'Mezmur sessions marked', 'label_am' => 'የመዝሙር ክትትል ቀናት', 'type' => 'int',
                'sql' => 'COALESCE(ma.sessions, 0)', 'requires' => ['ma'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'mezmur_attendance_rate' => [
                'label_en' => 'Mezmur attendance %', 'label_am' => 'የመዝሙር ክትትል %', 'type' => 'int',
                'sql' => 'ROUND(100 * ma.attended / NULLIF(ma.sessions, 0))', 'requires' => ['ma'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'mezmur_attendance_band' => [
                'label_en' => 'Mezmur attendance band', 'label_am' => 'የመዝሙር ክትትል ደረጃ', 'type' => 'derived_enum',
                'sql' => "CASE WHEN COALESCE(ma.sessions, 0) = 0 THEN 'none' WHEN 100 * ma.attended / ma.sessions >= 90 THEN 'excellent' "
                       . "WHEN 100 * ma.attended / ma.sessions >= 75 THEN 'good' WHEN 100 * ma.attended / ma.sessions >= 50 THEN 'fair' ELSE 'low' END",
                'requires' => ['ma'],
                'options' => ['excellent' => 'Excellent (≥90%)', 'good' => 'Good (75–89%)', 'fair' => 'Fair (50–74%)', 'low' => 'Low (<50%)', 'none' => 'No attendance marked'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'sort' => false,
                'definition_en' => 'Band of the Mezmur (choir) attendance rate this academic year.',
            ],
            'mezmur_last_present' => [
                'label_en' => 'Mezmur last present', 'label_am' => 'በመዝሙር የመጨረሻ መገኘት', 'type' => 'date',
                'sql' => 'ma.last_present', 'requires' => ['ma'],
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],

            // ───────────── Contact & address (PII) ─────────────
            'phone_primary' => [
                'label_en' => 'Phone', 'label_am' => 'ስልክ', 'type' => 'string',
                'sql' => 'COALESCE(NULLIF(m.phone_primary, \'\'), m.phone_number)', 'pii' => true,
                'view' => self::R_ALL, 'export' => self::R_ALL, 'search' => true, 'group' => false,
            ],
            'alt_phone_number' => [
                'label_en' => 'Alternative phone', 'label_am' => 'ተጨማሪ ስልክ', 'type' => 'string',
                'sql' => 'm.alt_phone_number', 'pii' => true,
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'has_phone' => [
                'label_en' => 'Has a phone number', 'label_am' => 'ስልክ አለው', 'type' => 'bool',
                'sql' => "(COALESCE(NULLIF(m.phone_primary,''), NULLIF(m.phone_number,''), NULLIF(m.alt_phone_number,''), NULLIF(m.guardian_phone1,''), NULLIF(m.phone_guardian,'')) IS NOT NULL)",
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Any of: primary, alternative, guardian phone is filled.',
            ],
            'city' => [
                'label_en' => 'City', 'label_am' => 'ከተማ', 'type' => 'string',
                'sql' => 'm.city', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'sub_city' => [
                'label_en' => 'Sub‑city', 'label_am' => 'ክፍለ ከተማ', 'type' => 'string',
                'sql' => 'm.sub_city', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'woreda' => [
                'label_en' => 'Woreda', 'label_am' => 'ወረዳ', 'type' => 'string',
                'sql' => 'm.woreda', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'house_number' => [
                'label_en' => 'House number', 'label_am' => 'የቤት ቁጥር', 'type' => 'string',
                'sql' => 'm.house_number', 'pii' => true, 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],

            // ───────────── Guardian (PII) ─────────────
            'guardian_name' => [
                'label_en' => 'Guardian name', 'label_am' => 'የአሳዳጊ ስም', 'type' => 'string',
                'sql' => 'm.guardian_name', 'pii' => true, 'view' => self::R_ALL, 'export' => self::R_ALL,
                'search' => true, 'group' => false,
            ],
            'guardian_phone' => [
                'label_en' => 'Guardian phone', 'label_am' => 'የአሳዳጊ ስልክ', 'type' => 'string',
                'sql' => "COALESCE(NULLIF(m.guardian_phone1,''), m.phone_guardian)", 'pii' => true,
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'has_guardian_contact' => [
                'label_en' => 'Has guardian contact', 'label_am' => 'የአሳዳጊ መረጃ አለው', 'type' => 'bool',
                'sql' => "(NULLIF(m.guardian_name,'') IS NOT NULL AND COALESCE(NULLIF(m.guardian_phone1,''), NULLIF(m.phone_guardian,'')) IS NOT NULL)",
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'guardian_links' => [
                'label_en' => 'Linked guardians (Family Link)', 'label_am' => 'የተገናኙ አሳዳጊዎች', 'type' => 'int',
                'sql' => '(SELECT COUNT(*) FROM `member_guardians` mg WHERE mg.member_id = m.id)',
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Number of guardian records attached through Family Link (HR data).',
            ],
            'fl_guardian_name' => [
                'label_en' => 'Family Link guardian', 'label_am' => 'የቤተሰብ አገናኝ አሳዳጊ', 'type' => 'string',
                'sql' => 'g.display_name', 'requires' => ['mgp', 'g'], 'pii' => true,
                'view' => self::R_ALL, 'export' => self::R_ALL, 'search' => true, 'group' => false,
                'definition_en' => 'Primary (or first) guardian record linked through Family Link.',
            ],
            'fl_guardian_phone' => [
                'label_en' => 'Family Link guardian phone', 'label_am' => 'የቤተሰብ አገናኝ አሳዳጊ ስልክ', 'type' => 'string',
                'sql' => 'g.phone_primary', 'requires' => ['mgp', 'g'], 'pii' => true,
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'fl_guardian_relation' => [
                'label_en' => 'Guardian relation', 'label_am' => 'የአሳዳጊ ዝምድና', 'type' => 'enum',
                'sql' => 'mgp.relation', 'requires' => ['mgp'],
                'options' => ['father' => 'Father', 'mother' => 'Mother', 'other' => 'Other'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'linked_siblings' => [
                'label_en' => 'Linked siblings', 'label_am' => 'የተገናኙ እህት/ወንድሞች', 'type' => 'int',
                'sql' => '(SELECT COUNT(DISTINCT mg2.member_id) FROM `member_guardians` mg1 JOIN `member_guardians` mg2 ON mg2.guardian_id = mg1.guardian_id AND mg2.member_id <> m.id WHERE mg1.member_id = m.id)',
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'Other members who share at least one Family Link guardian with this member.',
            ],

            // ───────────── Record completeness / data quality ─────────────
            'has_photo' => [
                'label_en' => 'Has photo', 'label_am' => 'ፎቶ አለው', 'type' => 'bool',
                'sql' => "(NULLIF(m.student_photo_path,'') IS NOT NULL)", 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'has_signed_form' => [
                'label_en' => 'Has signed registration form', 'label_am' => 'የተፈረመ ቅጽ አለው', 'type' => 'bool',
                'sql' => "(COALESCE(NULLIF(m.doc_signed_form_path,''), NULLIF(m.signed_form_path,'')) IS NOT NULL)",
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'The uploaded registration document required for promotion.',
            ],
            'has_documents' => [
                'label_en' => 'Has supporting documents', 'label_am' => 'ደጋፊ ሰነዶች አሉት', 'type' => 'bool',
                'sql' => "(NULLIF(m.doc_school_records_path,'') IS NOT NULL OR NULLIF(m.doc_spiritual_path,'') IS NOT NULL)",
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'id_card_status' => [
                'label_en' => 'ID card', 'label_am' => 'መታወቂያ', 'type' => 'enum',
                'sql' => "COALESCE(m.id_card_status, 'none')",
                'options' => ['none' => 'None', 'pending' => 'Pending', 'generated' => 'Generated'],
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'completeness_score' => [
                'label_en' => 'Record completeness (0–5)', 'label_am' => 'የመረጃ ሙሉነት (0–5)', 'type' => 'int',
                'sql' => "((NULLIF(m.student_photo_path,'') IS NOT NULL) + (COALESCE(NULLIF(m.phone_primary,''), NULLIF(m.phone_number,'')) IS NOT NULL) + (COALESCE(NULLIF(m.guardian_phone1,''), NULLIF(m.phone_guardian,'')) IS NOT NULL) + (COALESCE(NULLIF(m.doc_signed_form_path,''), NULLIF(m.signed_form_path,'')) IS NOT NULL) + (m.date_of_birth IS NOT NULL))",
                'view' => self::R_ALL, 'export' => self::R_ALL,
                'definition_en' => 'One point each for: photo, member phone, guardian phone, signed form, date of birth.',
            ],

            // ───────────── Roles / staff ─────────────
            'is_teacher' => [
                'label_en' => 'Teacher flag', 'label_am' => 'መምህር', 'type' => 'bool',
                'sql' => '(m.is_teacher = 1)', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'is_staff' => [
                'label_en' => 'Staff flag', 'label_am' => 'ሠራተኛ', 'type' => 'bool',
                'sql' => '(m.is_staff = 1)', 'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'staff_positions' => [
                'label_en' => 'Staff positions', 'label_am' => 'የኃላፊነት ቦታዎች', 'type' => 'int',
                'sql' => '(SELECT COUNT(*) FROM `member_staff_positions` msp WHERE msp.member_id = m.id)',
                'view' => self::R_ALL, 'export' => self::R_ALL,
            ],
            'staff_position_titles' => [
                'label_en' => 'Position titles', 'label_am' => 'የኃላፊነት መጠሪያዎች', 'type' => 'string',
                'sql' => "(SELECT GROUP_CONCAT(COALESCE(NULLIF(sp.title_am,''), sp.title_en) ORDER BY sp.sort_order SEPARATOR ', ') FROM `member_staff_positions` msp2 JOIN `staff_positions` sp ON sp.id = msp2.position_id WHERE msp2.member_id = m.id)",
                'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false, 'sort' => false,
            ],

            // ───────────── System ─────────────
            'created_at' => [
                'label_en' => 'Created in system', 'label_am' => 'በሲስተም የተፈጠረበት', 'type' => 'date',
                'sql' => 'DATE(m.created_at)', 'view' => self::R_ALL, 'export' => self::R_ALL, 'group' => false,
            ],
            'created_by' => [
                'label_en' => 'Created by (user id)', 'label_am' => 'የፈጠረው', 'type' => 'int',
                'sql' => 'm.created_by', 'view' => self::R_ADMIN, 'export' => self::R_ADMIN, 'group' => false,
            ],
            'emergency_phone' => [
                'label_en' => 'Emergency phone', 'label_am' => 'የአደጋ ጊዜ ስልክ', 'type' => 'string',
                'sql' => 'm.emergency_phone', 'pii' => true, 'view' => self::R_HR, 'export' => self::R_HR, 'group' => false,
            ],
        ];

        // Normalise defaults so consumers never need isset() chains.
        foreach ($f as $key => &$def) {
            $def['key']     = $key;
            $def['cat']     = $def['cat'] ?? self::categoryOf($key);
            $def['dataset'] = 'members';
            $def['pii']     = (bool)($def['pii'] ?? false);
            $def['filter']  = (bool)($def['filter'] ?? true);
            $def['group']   = (bool)($def['group'] ?? in_array($def['type'], ['enum', 'derived_enum', 'bool', 'string', 'int'], true));
            $def['sort']    = (bool)($def['sort'] ?? true);
            $def['list']    = (bool)($def['list'] ?? true);
            $def['search']  = (bool)($def['search'] ?? false);
            $def['requires'] = $def['requires'] ?? [];
            $def['options'] = $def['options'] ?? null;
            $def['hidden']  = (bool)($def['hidden'] ?? false);
            // export must never be wider than view
            $def['export']  = array_values(array_intersect($def['export'], $def['view']));
        }
        unset($def);

        return $fields = $f;
    }

    /** Category of a field key (prefix/keyword based; explicit 'cat' wins). */
    private static function categoryOf(string $key): string
    {
        static $map = [
            'id' => 'identity', 'member_code' => 'identity', 'full_name_am' => 'identity', 'full_name_en' => 'identity', 'baptismal_name' => 'identity',
            'gender' => 'identity', 'age' => 'identity', 'age_band' => 'identity', 'age_group' => 'identity', 'date_of_birth' => 'identity', 'dob_ec_year' => 'identity',
            'status' => 'membership', 'member_type' => 'membership', 'membership_tier' => 'membership', 'registration_type' => 'membership',
            'registered_at' => 'membership', 'registered_month' => 'membership', 'registered_ec_year' => 'membership', 'tenure_months' => 'membership',
            'promotion_eligible' => 'membership', 'promoted_at' => 'membership', 'waiting_since' => 'membership', 'id_card_status' => 'membership',
            'academic_status' => 'education', 'education_level' => 'education', 'spiritual_education' => 'education', 'work_profession' => 'education',
            'current_class' => 'education', 'is_enrolled' => 'education',
            'phone_primary' => 'contact', 'alt_phone_number' => 'contact', 'has_phone' => 'contact', 'city' => 'contact', 'sub_city' => 'contact',
            'woreda' => 'contact', 'house_number' => 'contact', 'emergency_phone' => 'contact',
            'is_teacher' => 'staff', 'is_staff' => 'staff', 'created_at' => 'system', 'created_by' => 'system',
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        foreach (['edu_' => 'attendance', 'mezmur_' => 'attendance', 'guardian' => 'guardian', 'fl_' => 'guardian', 'linked_' => 'guardian',
                  'has_' => 'quality', 'completeness' => 'quality', 'staff_' => 'staff', 'class_' => 'education', 'previous_class' => 'education',
                  'progression' => 'education', 'enrolled_at' => 'education'] as $needle => $cat) {
            if (str_starts_with($key, $needle) || str_contains($key, $needle)) {
                return $cat;
            }
        }
        return 'system';
    }

    /**
     * Metrics (measures) with human definitions — the glossary.
     * `sql` is an aggregate over the (already filtered) population.
     */
    public static function metrics(): array
    {
        return [
            'count' => [
                'label_en' => 'Members', 'label_am' => 'አባላት',
                'sql' => 'COUNT(*)',
                'definition_en' => 'Number of member records matching the current filters (archived included unless filtered out).',
            ],
            'active' => [
                'label_en' => 'Active', 'label_am' => 'ንቁ',
                'sql' => "SUM(m.status = 'active')",
                'definition_en' => "Members whose status is exactly 'active'.",
            ],
            'not_archived' => [
                'label_en' => 'Current members', 'label_am' => 'የአሁኑ አባላት',
                'sql' => "SUM(m.status <> 'archived')",
                'definition_en' => 'All members except archived ones (active + warning + inactive).',
            ],
            'female_pct' => [
                'label_en' => 'Female %', 'label_am' => 'ሴት %',
                'sql' => "ROUND(100 * SUM(m.gender = 'female') / NULLIF(COUNT(*), 0), 1)",
                'definition_en' => 'Female members as a percentage of the population.',
            ],
            'temporary' => [
                'label_en' => 'Temporary', 'label_am' => 'ጊዜያዊ',
                'sql' => "SUM(m.membership_tier = 'temporary')",
                'definition_en' => "Members whose membership tier is 'temporary'.",
            ],
            'promotion_eligible' => [
                'label_en' => 'Eligible for permanent', 'label_am' => 'ለቋሚነት ብቁ',
                'sql' => "SUM(m.membership_tier = 'temporary' AND m.status <> 'archived' AND m.registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH))",
                'definition_en' => 'Temporary, not archived, registered ≥ 6 months ago.',
            ],
            'with_id_card' => [
                'label_en' => 'With ID card', 'label_am' => 'መታወቂያ ያላቸው',
                'sql' => "SUM(m.id_card_status = 'generated')",
                'definition_en' => "ID card status is 'generated'.",
            ],
            'missing_phone' => [
                'label_en' => 'Without any phone', 'label_am' => 'ስልክ የሌላቸው',
                'sql' => "SUM(COALESCE(NULLIF(m.phone_primary,''), NULLIF(m.phone_number,''), NULLIF(m.alt_phone_number,''), NULLIF(m.guardian_phone1,''), NULLIF(m.phone_guardian,'')) IS NULL)",
                'definition_en' => 'No member, alternative or guardian phone on record.',
            ],
            'missing_photo' => [
                'label_en' => 'Without photo', 'label_am' => 'ፎቶ የሌላቸው',
                'sql' => "SUM(NULLIF(m.student_photo_path,'') IS NULL)",
                'definition_en' => 'No student photo uploaded.',
            ],
            'avg_age' => [
                'label_en' => 'Average age', 'label_am' => 'አማካይ ዕድሜ',
                'sql' => 'ROUND(AVG(m.age), 1)',
                'definition_en' => 'Mean of the stored age column (members without age are ignored).',
            ],
            'registered_30d' => [
                'label_en' => 'Registered last 30 days', 'label_am' => 'ባለፉት 30 ቀናት የተመዘገቡ',
                'sql' => 'SUM(m.registered_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))',
                'definition_en' => 'registered_at within the last 30 days.',
            ],
            // ── Cross‑domain (V2‑2): Education enrolment + attendance, Family Link ──
            'enrolled' => [
                'label_en' => 'Enrolled this year', 'label_am' => 'በዚህ ዓመት የተመዘገቡ',
                'sql' => 'SUM(ce.id IS NOT NULL)', 'requires' => ['ce'],
                'definition_en' => 'Members with an active class enrolment in the current academic year (Education data).',
            ],
            'not_re_enrolled' => [
                'label_en' => 'Not re‑enrolled', 'label_am' => 'ዳግም ያልተመዘገቡ',
                'sql' => 'SUM(ce.id IS NULL AND pe.id IS NOT NULL)', 'requires' => ['ce', 'pe'],
                'definition_en' => 'Had an enrolment last academic year but no active enrolment this year.',
            ],
            'avg_edu_attendance' => [
                'label_en' => 'Avg edu attendance %', 'label_am' => 'አማካይ የትምህርት ክትትል %',
                'sql' => 'ROUND(AVG(100 * ea.attended / NULLIF(ea.sessions, 0)), 1)', 'requires' => ['ea'],
                'definition_en' => 'Mean education attendance rate of members with at least one marked session this academic year.',
            ],
            'low_attendance' => [
                'label_en' => 'Low attendance (<50%)', 'label_am' => 'ዝቅተኛ ክትትል (<50%)',
                'sql' => 'SUM(ea.sessions > 0 AND 100 * ea.attended / ea.sessions < 50)', 'requires' => ['ea'],
                'definition_en' => 'Members whose education attendance this academic year is below 50% (at least one marked session).',
            ],
            'with_family_link' => [
                'label_en' => 'With Family Link', 'label_am' => 'የቤተሰብ አገናኝ ያላቸው',
                'sql' => 'SUM((SELECT COUNT(*) FROM `member_guardians` mgc WHERE mgc.member_id = m.id) > 0)',
                'definition_en' => 'Members with at least one guardian linked through Family Link.',
            ],
        ];
    }

    /** Legal operators per field type. */
    public static function operators(): array
    {
        $text   = ['eq', 'neq', 'contains', 'starts_with', 'in', 'not_in', 'is_empty', 'not_empty'];
        $enum   = ['eq', 'neq', 'in', 'not_in', 'is_empty', 'not_empty'];
        $number = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'is_empty', 'not_empty'];
        $date   = ['eq', 'gt', 'gte', 'lt', 'lte', 'between', 'last_days', 'this_ec_year', 'last_ec_year', 'is_empty', 'not_empty'];
        $bool   = ['is_true', 'is_false'];
        return [
            'string'       => $text,
            'enum'         => $enum,
            'derived_enum' => $enum,
            'int'          => $number,
            'date'         => $date,
            'bool'         => $bool,
        ];
    }

    /** Operator labels for the UI. */
    public static function operatorLabels(): array
    {
        return [
            'eq' => 'is', 'neq' => 'is not', 'contains' => 'contains', 'starts_with' => 'starts with',
            'in' => 'is any of', 'not_in' => 'is none of', 'is_empty' => 'is empty', 'not_empty' => 'is not empty',
            'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'between' => 'between',
            'last_days' => 'in the last N days', 'this_ec_year' => 'this Ethiopian year', 'last_ec_year' => 'last Ethiopian year',
            'is_true' => 'yes', 'is_false' => 'no',
        ];
    }

    /**
     * Role‑specific starter reports (the "questions" HR and Info get asked).
     * Definitions only; validated like any client definition when run.
     */
    public static function starters(): array
    {
        $base = ['dataset' => 'members'];
        return [
            [
                'key' => 'eligible_for_permanent', 'roles' => self::R_ALL, 'group' => 'promotion',
                'title_en' => 'Eligible for permanent membership', 'title_am' => 'ለቋሚ አባልነት ብቁ የሆኑ',
                'definition' => $base + [
                    'filters' => [['field' => 'promotion_eligible', 'op' => 'is_true']],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'registered_at', 'tenure_months', 'has_signed_form', 'phone_primary'],
                    'sort' => [['field' => 'registered_at', 'dir' => 'asc']],
                ],
            ],
            [
                'key' => 'missing_guardian', 'roles' => self::R_ALL, 'group' => 'data_quality',
                'title_en' => 'Children without guardian contact', 'title_am' => 'የአሳዳጊ መረጃ የሌላቸው ህጻናት',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'age_group', 'op' => 'in', 'value' => ['7_13', '14_17']],
                        ['field' => 'status', 'op' => 'neq', 'value' => 'archived'],
                        ['field' => 'has_guardian_contact', 'op' => 'is_false'],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'current_class', 'guardian_name', 'guardian_phone', 'guardian_links'],
                ],
            ],
            [
                'key' => 'incomplete_records', 'roles' => self::R_ALL, 'group' => 'data_quality',
                'title_en' => 'Incomplete records (data quality)', 'title_am' => 'ያልተሟሉ መዝገቦች',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'status', 'op' => 'neq', 'value' => 'archived'],
                        ['field' => 'completeness_score', 'op' => 'lt', 'value' => 5],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'completeness_score', 'has_photo', 'has_phone', 'has_guardian_contact', 'has_signed_form', 'date_of_birth'],
                    'sort' => [['field' => 'completeness_score', 'dir' => 'asc']],
                ],
            ],
            [
                'key' => 'not_enrolled', 'roles' => self::R_ALL, 'group' => 'follow_up',
                'title_en' => 'Active members not enrolled this year', 'title_am' => 'በዚህ ዓመት ያልተመዘገቡ ንቁ አባላት',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                        ['field' => 'is_enrolled', 'op' => 'is_false'],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'age', 'phone_primary', 'registered_at'],
                ],
            ],
            [
                'key' => 'registered_this_ec_year', 'roles' => self::R_ALL, 'group' => 'membership',
                'title_en' => 'Registered this Ethiopian year', 'title_am' => 'በዚህ ዓ.ም. የተመዘገቡ',
                'definition' => $base + [
                    'filters' => [['field' => 'registered_at', 'op' => 'this_ec_year']],
                    'columns' => ['member_code', 'full_name_am', 'gender', 'age_group', 'registration_type', 'membership_tier', 'registered_at'],
                    'group_by' => 'age_group',
                ],
            ],
            // ── Cross‑domain starters (V2‑2) ──
            [
                'key' => 'low_attendance_enrolled', 'roles' => self::R_ALL, 'group' => 'follow_up',
                'title_en' => 'Enrolled but attending under 50%', 'title_am' => 'የተመዘገቡ ግን ከ50% በታች የሚከታተሉ',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'is_enrolled', 'op' => 'is_true'],
                        ['field' => 'edu_attendance_band', 'op' => 'eq', 'value' => 'low'],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'current_class', 'class_teacher', 'edu_sessions', 'edu_attendance_rate', 'edu_last_present', 'phone_primary', 'guardian_phone'],
                    'sort' => [['field' => 'edu_attendance_rate', 'dir' => 'asc']],
                ],
            ],
            [
                'key' => 'absent_recently', 'roles' => self::R_ALL, 'group' => 'follow_up',
                'title_en' => 'Absent 3+ times in the last 30 days', 'title_am' => 'ባለፉት 30 ቀናት 3+ ጊዜ የቀሩ',
                'definition' => $base + [
                    'filters' => [['field' => 'edu_absent_30d', 'op' => 'gte', 'value' => 3]],
                    'columns' => ['member_code', 'full_name_am', 'current_class', 'class_teacher', 'edu_absent_30d', 'edu_last_present', 'edu_attendance_rate', 'phone_primary', 'guardian_phone'],
                    'sort' => [['field' => 'edu_absent_30d', 'dir' => 'desc']],
                ],
            ],
            [
                'key' => 'not_re_enrolled', 'roles' => self::R_ALL, 'group' => 'follow_up',
                'title_en' => 'Enrolled last year, not this year', 'title_am' => 'ባለፈው ዓመት የተመዘገቡ፣ በዚህ ዓመት ያልተመዘገቡ',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'status', 'op' => 'neq', 'value' => 'archived'],
                        ['field' => 'progression', 'op' => 'eq', 'value' => 'not_re_enrolled'],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'previous_class', 'status', 'phone_primary', 'guardian_phone'],
                ],
            ],
            [
                'key' => 'eligible_incomplete', 'roles' => self::R_ALL, 'group' => 'promotion',
                'title_en' => 'Eligible for permanent but record incomplete', 'title_am' => 'ለቋሚነት ብቁ ግን መዝገባቸው ያልተሟላ',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'promotion_eligible', 'op' => 'is_true'],
                        ['field' => 'completeness_score', 'op' => 'lt', 'value' => 5],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'registered_at', 'completeness_score', 'has_photo', 'has_phone', 'has_guardian_contact', 'has_signed_form', 'date_of_birth'],
                    'sort' => [['field' => 'completeness_score', 'dir' => 'asc']],
                ],
            ],
            [
                'key' => 'children_no_family_link', 'roles' => self::R_ALL, 'group' => 'data_quality',
                'title_en' => 'Children not linked in Family Link', 'title_am' => 'በቤተሰብ አገናኝ ያልተገናኙ ህጻናት',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'age_group', 'op' => 'in', 'value' => ['7_13', '14_17']],
                        ['field' => 'status', 'op' => 'neq', 'value' => 'archived'],
                        ['field' => 'guardian_links', 'op' => 'eq', 'value' => 0],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'current_class', 'guardian_name', 'guardian_phone', 'linked_siblings'],
                ],
            ],
            [
                'key' => 'no_id_card', 'roles' => self::R_HR, 'group' => 'membership',
                'title_en' => 'Active members without ID card', 'title_am' => 'መታወቂያ የሌላቸው ንቁ አባላት',
                'definition' => $base + [
                    'filters' => [
                        ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                        ['field' => 'id_card_status', 'op' => 'neq', 'value' => 'generated'],
                    ],
                    'columns' => ['member_code', 'full_name_am', 'age_group', 'has_photo', 'id_card_status', 'registered_at'],
                ],
            ],
        ];
    }

    /** Default list columns per role (the UI column picker starts from these). */
    public static function defaultColumns(string $role): array
    {
        $cols = ['member_code', 'full_name_am', 'gender', 'age_group', 'status', 'membership_tier', 'phone_primary', 'sub_city', 'registered_at'];
        return array_values(array_filter($cols, static fn (string $k): bool => self::canView($k, $role)));
    }

    // ───────────────────────── helpers ─────────────────────────

    public static function field(string $key): ?array
    {
        return self::fields()[$key] ?? null;
    }

    /** Joins a metric needs (most need none). */
    public static function metricRequires(string $key): array
    {
        return self::metrics()[$key]['requires'] ?? [];
    }

    public static function canView(string $key, string $role): bool
    {
        $f = self::field($key);
        return $f !== null && in_array($role, $f['view'], true);
    }

    public static function canExport(string $key, string $role): bool
    {
        $f = self::field($key);
        return $f !== null && in_array($role, $f['export'], true);
    }

    /** Schema as the UI sees it for one role: only visible fields, no SQL. */
    public static function schemaForRole(string $role): array
    {
        $fields = [];
        foreach (self::fields() as $key => $f) {
            if (!in_array($role, $f['view'], true)) {
                continue;
            }
            $fields[] = [
                'key'        => $key,
                'label_en'   => $f['label_en'],
                'label_am'   => $f['label_am'],
                'type'       => $f['type'],
                'options'    => $f['options'],
                'pii'        => $f['pii'],
                'filter'     => $f['filter'],
                'group'      => $f['group'],
                'sort'       => $f['sort'],
                'list'       => $f['list'],
                'export'     => in_array($role, $f['export'], true),
                'hidden'     => $f['hidden'],
                'category'   => $f['cat'],
                'definition' => $f['definition_en'] ?? null,
            ];
        }
        $metrics = [];
        foreach (self::metrics() as $key => $m) {
            $metrics[] = ['key' => $key, 'label_en' => $m['label_en'], 'label_am' => $m['label_am'], 'definition' => $m['definition_en']];
        }
        $starters = [];
        foreach (self::starters() as $s) {
            if (in_array($role, $s['roles'], true)) {
                $starters[] = ['key' => $s['key'], 'group' => $s['group'] ?? 'other', 'title_en' => $s['title_en'], 'title_am' => $s['title_am'], 'definition' => $s['definition']];
            }
        }
        return [
            'datasets'        => ['members' => ['label_en' => 'Members', 'label_am' => 'አባላት']],
            'categories'      => self::CATEGORIES,
            'starter_groups'  => self::STARTER_GROUPS,
            'fields'          => $fields,
            'metrics'         => $metrics,
            'operators'       => self::operators(),
            'operator_labels' => self::operatorLabels(),
            'starters'        => $starters,
            'default_columns' => self::defaultColumns($role),
            'limits'          => [
                'page_size' => self::MAX_PAGE_SIZE, 'list_rows' => self::MAX_LIST_ROWS,
                'export_rows' => self::MAX_EXPORT_ROWS, 'groups' => self::MAX_GROUPS, 'filters' => self::MAX_FILTERS,
            ],
        ];
    }
}
