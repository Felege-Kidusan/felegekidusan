<?php
/**
 * Academic Intelligence — four perspectives on ONE calculation.
 *
 * The Education department asks the same academic question from four
 * directions:
 *
 *   STUDENT  "የተማሪ እገሌ ውጤት ምን ይመስላል?"        one learner across subjects
 *   TEACHER  "የTeacher በቀለ እንቅስቃሴ ... ምን ይመስላል?"  one teacher across assignments
 *   SUBJECT  "የግዕዝ ትምህርት ... ምን ይመስላል?"          one subject across classes
 *   CLASS    "የ4ኛ ክፍል ... ምን ላይ ደርሷል?"            one class across subjects
 *
 * THIS CLASS CONTAINS NO ACADEMIC FORMULA.
 * ----------------------------------------
 * Every score, percentage, grade letter, semester weighting, subject final,
 * rank and attendance rate is produced by ReportCardService, which remains
 * the single authority. This class only:
 *
 *   1. decides WHICH classes are relevant to the chosen perspective
 *      (from class_subjects / teacher_assignments / class_enrollments),
 *   2. asks ReportCardService for each relevant class exactly ONCE,
 *   3. re-shapes the answer into a common envelope, and
 *   4. counts and groups values the engine already computed.
 *
 * The one thing it does arithmetically is cohort counting: given the
 * per-student results the engine returned, how many passed, how the letters
 * are spread, what the mean is. Those are the same rules buildRankedClass()
 * applies to a whole class, reused here because a filtered or per-subject
 * cohort is a subset the engine is never asked about directly. Student
 * averages and subject finals are NEVER recomputed here.
 *
 * That this re-shaping cannot drift from the engine is not asserted, it is
 * tested: tests/e2e/academic_intelligence.php compares the per-subject and
 * per-class numbers produced here against
 * ReportCardService::getClassReport($conn, $classId, $subjectId, ...) for
 * every subject of every class, in annual and in both semesters.
 *
 * COST: one ReportCardService class pack per relevant class per request,
 * memoised. A subject taught in ten classes costs ten packs, not ten times
 * the number of subjects; a teacher with four assignments in two classes
 * costs two packs, not four.
 */

namespace App\Services;

require_once __DIR__ . '/ReportCardService.php';

class AcademicIntelligenceService
{
    public const PERSPECTIVES = ['student', 'teacher', 'subject', 'class'];

    /** Hard ceiling on classes examined in one cross-class request. */
    public const MAX_CLASSES_PER_REQUEST = 60;

    /** Default page size for the student drill-down tables. */
    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 200;

    /**
     * Per-request memo of class packs, so a class examined from two angles
     * in one request is computed once.
     *
     * @var array<string,array<string,mixed>>
     */
    private static array $packs = [];

    /** Test and long-running-CLI hook; a web request is short-lived. */
    public static function resetCache(): void
    {
        self::$packs = [];
    }

    // ════════════════════════════════════════════════════════════════════
    // DISPATCH
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function perspective(\mysqli $conn, string $perspective, array $params = []): array
    {
        $perspective = strtolower(trim($perspective));
        if (!in_array($perspective, self::PERSPECTIVES, true)) {
            return ['status' => 'error', 'message' => 'Unknown perspective.'];
        }

        $yearId = (int)($params['year_id'] ?? 0);
        if ($yearId <= 0) {
            $yearId = ReportCardService::currentYearId($conn);
        }
        $termId = (int)($params['term_id'] ?? 0);
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];

        switch ($perspective) {
            case 'student':
                return self::student(
                    $conn,
                    (int)($params['member_id'] ?? 0),
                    (int)($params['class_id'] ?? 0),
                    $yearId,
                    $termId,
                    $filters
                );
            case 'teacher':
                return self::teacher($conn, (int)($params['teacher_id'] ?? 0), $yearId, $termId, $filters);
            case 'subject':
                return self::subject($conn, (int)($params['subject_id'] ?? 0), $yearId, $termId, $filters);
            default:
                return self::classView($conn, (int)($params['class_id'] ?? 0), $yearId, $termId, $filters);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // STUDENT
    // ════════════════════════════════════════════════════════════════════

    /**
     * One learner: the authoritative report card, re-shaped.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function student(
        \mysqli $conn,
        int $memberId,
        int $classId = 0,
        int $yearId = 0,
        int $termId = 0,
        array $filters = []
    ): array {
        if ($memberId <= 0) {
            return ['status' => 'error', 'message' => 'Student is required.'];
        }

        // getCard resolves the class from the active enrolment when the
        // caller does not know it, and does every calculation.
        $card = ReportCardService::getCard($conn, $memberId, $classId, $yearId, $termId);
        if (($card['status'] ?? '') !== 'success') {
            return [
                'status' => 'error',
                'message' => (string)($card['message'] ?? 'Could not build this student view.'),
            ];
        }

        $subjects = is_array($card['subjects'] ?? null) ? $card['subjects'] : [];
        $totals = is_array($card['totals'] ?? null) ? $card['totals'] : [];
        $attendance = is_array($card['attendance'] ?? null) ? $card['attendance'] : [];
        $student = is_array($card['student'] ?? null) ? $card['student'] : [];

        // Completion counts come from the engine's per-subject completion.
        $graded = 0;
        $pending = 0;
        foreach ($subjects as $s) {
            if (($s['final_percentage'] ?? null) !== null) {
                $graded++;
            } else {
                $pending++;
            }
        }

        $rows = [];
        foreach ($subjects as $s) {
            $rows[] = [
                'id' => (int)($s['id'] ?? 0),
                'subject_name' => (string)($s['subject_name'] ?? ''),
                'subject_name_en' => (string)($s['subject_name_en'] ?? ''),
                'average' => $s['average'] ?? null,
                'final_percentage' => $s['final_percentage'] ?? null,
                'grade_letter' => $s['grade_letter'] ?? null,
                'semester_1_score' => $s['semester_1_score'] ?? null,
                'semester_2_score' => $s['semester_2_score'] ?? null,
                'semester_weights' => $s['semester_weights'] ?? null,
                'duration_type' => $s['duration_type'] ?? null,
                'subject_status' => $s['subject_status'] ?? null,
                'status_reason' => (string)($s['status_reason'] ?? ''),
                'completion' => $s['completion'] ?? null,
                'assessments' => is_array($s['assessments'] ?? null) ? $s['assessments'] : [],
                'untagged_mark_rows' => (int)($s['untagged_mark_rows'] ?? 0),
            ];
        }

        // Assessment progression: the marks this student actually has, in the
        // order the engine returned them. Nothing is interpolated, and a
        // subject with no marks contributes no points.
        $progression = [];
        foreach ($subjects as $s) {
            foreach ((is_array($s['assessments'] ?? null) ? $s['assessments'] : []) as $a) {
                if (($a['percentage'] ?? null) === null) {
                    continue;
                }
                $progression[] = [
                    'subject_id' => (int)($s['id'] ?? 0),
                    'subject_name' => (string)($s['subject_name'] ?? ''),
                    'assessment_name' => (string)($a['assessment_name'] ?? ''),
                    'percentage' => $a['percentage'],
                    'score' => $a['score'] ?? null,
                    'max_score' => $a['max_score'] ?? null,
                ];
            }
        }

        $semesterCompare = [];
        foreach ($subjects as $s) {
            if (($s['semester_1_score'] ?? null) === null && ($s['semester_2_score'] ?? null) === null) {
                continue;
            }
            $semesterCompare[] = [
                'subject_name' => (string)($s['subject_name'] ?? ''),
                'semester_1' => $s['semester_1_score'] ?? null,
                'semester_2' => $s['semester_2_score'] ?? null,
            ];
        }

        return [
            'status' => 'success',
            'perspective' => 'student',
            'academic_year' => self::yearEnvelope($card['year'] ?? null),
            'term' => self::termEnvelope($card['term'] ?? null),
            'filters' => self::echoFilters($filters),
            'summary' => [
                'member_id' => $memberId,
                'student_name' => (string)($student['student_name'] ?? ''),
                'father_name' => (string)($student['father_name'] ?? ''),
                'christian_name' => (string)($student['baptismal_name'] ?? $student['christian_name'] ?? ''),
                'member_code' => (string)($student['member_code'] ?? ''),
                'gender' => (string)($student['gender'] ?? ''),
                'class_id' => (int)($card['class']['id'] ?? 0),
                'class_name' => (string)($card['class']['class_name'] ?? ''),
                'class_name_en' => (string)($card['class']['class_name_en'] ?? ''),
                'overall_average' => $card['overall_average'] ?? null,
                'grade_letter' => $card['overall_grade'] ?? null,
                'rank' => $card['rank'] ?? null,
                'rank_tied' => !empty($card['rank_tied']),
                'total_in_class' => (int)($card['total_in_class'] ?? 0),
                'attendance_rate' => $attendance['rate'] ?? null,
                'present_days' => (int)($attendance['present'] ?? 0),
                'absent_days' => (int)($attendance['absent'] ?? 0),
                'late_days' => (int)($attendance['late'] ?? 0),
                'excused_days' => (int)($attendance['excused'] ?? 0),
                'total_days' => (int)($attendance['total'] ?? 0),
                'has_attendance' => (int)($attendance['total'] ?? 0) > 0,
                'subjects_count' => count($subjects),
                'graded_subjects' => $graded,
                'pending_subjects' => $pending,
                'assessments_count' => (int)($totals['assessments_count'] ?? 0),
                'is_annual' => !empty($totals['is_annual']),
                'semester_weights' => $totals['semester_weights'] ?? null,
                'strongest_subject' => $card['highlights']['strongest']['subject_name'] ?? null,
                'weakest_subject' => $card['highlights']['weakest']['subject_name'] ?? null,
            ],
            'rows' => $rows,
            'charts' => [
                'subject_performance' => array_values(array_map(static function ($s) {
                    return [
                        'label' => (string)($s['subject_name'] ?? ''),
                        'value' => $s['final_percentage'] ?? null,
                    ];
                }, $subjects)),
                'assessment_progression' => $progression,
                'semester_comparison' => $semesterCompare,
                'attendance' => [
                    'present' => (int)($attendance['present'] ?? 0),
                    'absent' => (int)($attendance['absent'] ?? 0),
                    'late' => (int)($attendance['late'] ?? 0),
                    'excused' => (int)($attendance['excused'] ?? 0),
                ],
            ],
            'drilldown' => [
                'class_id' => (int)($card['class']['id'] ?? 0),
                'subject_ids' => array_values(array_map(static fn($s) => (int)($s['id'] ?? 0), $subjects)),
            ],
            'pass_mark' => ReportCardService::PASS_MARK,
            'grade_scale' => ReportCardService::GRADE_SCALE,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // TEACHER
    // ════════════════════════════════════════════════════════════════════

    /**
     * One teacher's documented activity across the classes and subjects they
     * are actually assigned to.
     *
     * Deliberately NOT produced here: any teacher score, ranking or
     * comparison between teachers. The rows report what was planned, what
     * was delivered, what was submitted and how the students did. Attributing
     * those outcomes to the teacher is a human judgement, not a computed one.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function teacher(
        \mysqli $conn,
        int $teacherId,
        int $yearId = 0,
        int $termId = 0,
        array $filters = []
    ): array {
        if ($teacherId <= 0) {
            return ['status' => 'error', 'message' => 'Teacher is required.'];
        }
        $teacher = self::fetchTeacher($conn, $teacherId);
        if (!$teacher) {
            return ['status' => 'error', 'message' => 'Teacher not found.'];
        }

        $assignments = self::fetchAssignments($conn, $teacherId, $yearId, (int)($filters['class_id'] ?? 0), (int)($filters['subject_id'] ?? 0));
        $governance = self::governanceIndex($conn, $yearId, $termId);

        $rows = [];
        $classIds = [];
        $studentTotal = 0;
        $countedClasses = [];
        $plannedTotal = 0;
        $submittedTotal = 0;
        $approvedTotal = 0;
        $missingTotal = 0;
        $cohort = [];

        foreach ($assignments as $asg) {
            $classId = (int)$asg['class_id'];
            $subjectId = (int)$asg['subject_id'];
            $classIds[$classId] = true;

            $pack = self::pack($conn, $classId, $yearId, $termId);
            if (($pack['status'] ?? '') !== 'success') {
                continue;
            }

            // A homeroom assignment carries no subject: report the class as a
            // whole rather than inventing a subject slice.
            $slice = $subjectId > 0
                ? self::subjectSlice($pack, $subjectId)
                : self::classSlice($pack);

            // Students are counted once per class, not once per subject.
            if (!isset($countedClasses[$classId])) {
                $countedClasses[$classId] = true;
                $studentTotal += (int)($pack['stats']['total_students'] ?? 0);
            }

            $gov = $governance[$classId][$subjectId] ?? self::emptyGovernance();
            $plannedTotal += $gov['planned'];
            $submittedTotal += $gov['submitted'];
            $approvedTotal += $gov['approved'];
            $missingTotal += $gov['missing'];

            foreach ($slice['values'] as $v) {
                $cohort[] = $v;
            }

            $rows[] = [
                'assignment_id' => (int)$asg['id'],
                'class_id' => $classId,
                'class_name' => (string)$asg['class_name'],
                'class_name_en' => (string)($asg['class_name_en'] ?? ''),
                'subject_id' => $subjectId,
                'subject_name' => $subjectId > 0 ? (string)$asg['subject_name'] : 'All subjects (homeroom)',
                'subject_name_en' => (string)($asg['subject_name_en'] ?? ''),
                'assignment_role' => (string)($asg['assignment_role'] ?? 'primary'),
                'is_class_teacher' => (int)($asg['is_class_teacher'] ?? 0) === 1,
                'student_count' => (int)($pack['stats']['total_students'] ?? 0),
                'graded_students' => $slice['graded'],
                'pending_students' => $slice['pending'],
                'average' => $slice['average'],
                'pass_rate' => $slice['pass_rate'],
                'highest' => $slice['highest'],
                'lowest' => $slice['lowest'],
                'grade_distribution' => $slice['grade_distribution'],
                'duration_type' => $slice['duration_type'],
                'subject_status_counts' => $slice['status_counts'],
                'completion' => $slice['completion'],
                'assessments_planned' => $gov['planned'],
                'assessments_recorded' => $slice['completion']['recorded'] ?? null,
                'submissions' => [
                    'approved' => $gov['approved'],
                    'submitted' => $gov['submitted'],
                    'draft' => $gov['draft'],
                    'revision_needed' => $gov['revision_needed'],
                    'missing' => $gov['missing'],
                ],
                'missing_assessments' => $gov['missing_titles'],
                'attendance_rate' => self::attendanceMean($pack),
            ];
        }

        $summaryStats = self::cohortStats($cohort);
        $deliveryRate = $plannedTotal > 0
            ? round((($approvedTotal + $submittedTotal) / $plannedTotal) * 100, 1)
            : null;

        return [
            'status' => 'success',
            'perspective' => 'teacher',
            'academic_year' => self::yearEnvelope(self::fetchYearRow($conn, $yearId)),
            'term' => self::termEnvelope(self::fetchTermRow($conn, $termId)),
            'filters' => self::echoFilters($filters),
            'summary' => [
                'teacher_id' => $teacherId,
                'teacher_name' => (string)$teacher['full_name'],
                'teacher_email' => (string)($teacher['email'] ?? ''),
                'assignment_count' => count($rows),
                'class_count' => count($classIds),
                'subject_count' => count(array_unique(array_filter(array_map(
                    static fn($r) => (int)$r['subject_id'],
                    $rows
                )))),
                'total_students' => $studentTotal,
                'average' => $summaryStats['average'],
                'pass_rate' => $summaryStats['pass_rate'],
                'graded_results' => $summaryStats['graded'],
                'assessments_planned' => $plannedTotal,
                'assessments_approved' => $approvedTotal,
                'assessments_submitted' => $submittedTotal,
                'assessments_missing' => $missingTotal,
                'delivery_rate' => $deliveryRate,
                // Stated in the payload so no consumer mistakes this view for
                // a teacher evaluation.
                'disclaimer' => 'Documented academic activity and student outcomes. Not a measure of teacher quality.',
            ],
            'rows' => $rows,
            'charts' => [
                'class_averages' => array_values(array_map(static function ($r) {
                    return [
                        'label' => $r['class_name'] . ' · ' . $r['subject_name'],
                        'value' => $r['average'],
                    ];
                }, $rows)),
                'assessment_delivery' => [
                    'approved' => $approvedTotal,
                    'submitted' => $submittedTotal,
                    'missing' => $missingTotal,
                ],
                'grade_distribution' => $summaryStats['grade_distribution'],
            ],
            'drilldown' => [
                'class_ids' => array_values(array_map('intval', array_keys($classIds))),
            ],
            'pass_mark' => ReportCardService::PASS_MARK,
            'grade_scale' => ReportCardService::GRADE_SCALE,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // SUBJECT
    // ════════════════════════════════════════════════════════════════════

    /**
     * One subject across every class that actually offers it.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function subject(
        \mysqli $conn,
        int $subjectId,
        int $yearId = 0,
        int $termId = 0,
        array $filters = []
    ): array {
        if ($subjectId <= 0) {
            return ['status' => 'error', 'message' => 'Subject is required.'];
        }
        $subject = self::fetchSubject($conn, $subjectId);
        if (!$subject) {
            return ['status' => 'error', 'message' => 'Subject not found.'];
        }

        $classes = self::classesOfferingSubject($conn, $subjectId, (int)($filters['class_id'] ?? 0));
        $teachersByClass = self::teachersForSubject($conn, $subjectId, $yearId);

        $rows = [];
        $cohort = [];
        $totalStudents = 0;

        foreach ($classes as $cls) {
            $classId = (int)$cls['id'];
            $pack = self::pack($conn, $classId, $yearId, $termId);
            if (($pack['status'] ?? '') !== 'success') {
                continue;
            }
            $slice = self::subjectSlice($pack, $subjectId);

            // A semester-only subject simply does not appear on the other
            // semester's report. Say so instead of showing a zero row.
            $offered = $slice['present_in_report'];
            $totalStudents += (int)($pack['stats']['total_students'] ?? 0);
            foreach ($slice['values'] as $v) {
                $cohort[] = $v;
            }

            $rows[] = [
                'class_id' => $classId,
                'class_name' => (string)$cls['class_name'],
                'class_name_en' => (string)($cls['class_name_en'] ?? ''),
                'level_order' => (int)($cls['level_order'] ?? 0),
                'teachers' => $teachersByClass[$classId] ?? [],
                'student_count' => (int)($pack['stats']['total_students'] ?? 0),
                'graded_students' => $slice['graded'],
                'pending_students' => $slice['pending'],
                'offered_in_this_term' => $offered,
                'average' => $slice['average'],
                'pass_rate' => $slice['pass_rate'],
                'highest' => $slice['highest'],
                'lowest' => $slice['lowest'],
                'grade_distribution' => $slice['grade_distribution'],
                'semester_1_average' => $slice['semester_1_average'],
                'semester_2_average' => $slice['semester_2_average'],
                'duration_type' => $slice['duration_type'],
                'subject_status_counts' => $slice['status_counts'],
                'completion' => $slice['completion'],
                'attendance_rate' => self::attendanceMean($pack),
            ];
        }

        usort($rows, static function ($a, $b) {
            if ($a['level_order'] !== $b['level_order']) {
                return $a['level_order'] <=> $b['level_order'];
            }
            return strcasecmp((string)$a['class_name'], (string)$b['class_name']);
        });

        $summaryStats = self::cohortStats($cohort);

        return [
            'status' => 'success',
            'perspective' => 'subject',
            'academic_year' => self::yearEnvelope(self::fetchYearRow($conn, $yearId)),
            'term' => self::termEnvelope(self::fetchTermRow($conn, $termId)),
            'filters' => self::echoFilters($filters),
            'summary' => [
                'subject_id' => $subjectId,
                'subject_name' => (string)$subject['subject_name'],
                'subject_name_en' => (string)($subject['subject_name_en'] ?? ''),
                'class_count' => count($rows),
                'total_students' => $totalStudents,
                'graded_results' => $summaryStats['graded'],
                'average' => $summaryStats['average'],
                'pass_rate' => $summaryStats['pass_rate'],
                'highest' => $summaryStats['highest'],
                'lowest' => $summaryStats['lowest'],
                'teacher_count' => count(array_unique(array_merge(...array_map(
                    static fn($r) => array_map(static fn($t) => (int)$t['teacher_id'], $r['teachers']),
                    $rows
                ) ?: [[]]))),
            ],
            'rows' => $rows,
            'charts' => [
                'performance_by_class' => array_values(array_map(static function ($r) {
                    return ['label' => $r['class_name'], 'value' => $r['average']];
                }, $rows)),
                'grade_distribution' => $summaryStats['grade_distribution'],
                'completion_by_class' => array_values(array_map(static function ($r) {
                    return [
                        'label' => $r['class_name'],
                        'value' => $r['completion']['recorded'] ?? null,
                    ];
                }, $rows)),
                'semester_comparison' => array_values(array_map(static function ($r) {
                    return [
                        'label' => $r['class_name'],
                        'semester_1' => $r['semester_1_average'],
                        'semester_2' => $r['semester_2_average'],
                    ];
                }, $rows)),
            ],
            'drilldown' => [
                'class_ids' => array_values(array_map(static fn($r) => (int)$r['class_id'], $rows)),
            ],
            'pass_mark' => ReportCardService::PASS_MARK,
            'grade_scale' => ReportCardService::GRADE_SCALE,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // CLASS
    // ════════════════════════════════════════════════════════════════════

    /**
     * One class: its subjects, its students, its completion.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function classView(
        \mysqli $conn,
        int $classId,
        int $yearId = 0,
        int $termId = 0,
        array $filters = []
    ): array {
        if ($classId <= 0) {
            return ['status' => 'error', 'message' => 'Class is required.'];
        }
        $pack = self::pack($conn, $classId, $yearId, $termId);
        if (($pack['status'] ?? '') !== 'success') {
            return [
                'status' => 'error',
                'message' => (string)($pack['message'] ?? 'Could not load this class.'),
            ];
        }

        $allStudents = is_array($pack['students'] ?? null) ? $pack['students'] : [];
        $students = self::applyStudentFilters($allStudents, $filters);
        $isFiltered = count($students) !== count($allStudents);

        // Unfiltered, the engine's own class statistics are reported verbatim.
        // Filtered, the same statistics are recomputed over the surviving
        // cohort so the headline numbers and the table always describe the
        // same set of students.
        if ($isFiltered) {
            $cohortStats = self::cohortStats(array_values(array_filter(array_map(
                static fn($s) => $s['overall_average'],
                $students
            ), static fn($v) => $v !== null)));
            $summaryNumbers = [
                'class_average' => $cohortStats['average'],
                'pass_rate' => $cohortStats['pass_rate'],
                'highest' => $cohortStats['highest'],
                'lowest' => $cohortStats['lowest'],
                'median' => $cohortStats['median'],
                'graded_students' => $cohortStats['graded'],
            ];
            $gradeDistribution = $cohortStats['grade_distribution'];
        } else {
            $stats = is_array($pack['stats'] ?? null) ? $pack['stats'] : [];
            $summaryNumbers = [
                'class_average' => $stats['class_average'] ?? null,
                'pass_rate' => $stats['pass_rate'] ?? null,
                'highest' => $stats['highest'] ?? null,
                'lowest' => $stats['lowest'] ?? null,
                'median' => $stats['median'] ?? null,
                'graded_students' => (int)($stats['graded_students'] ?? 0),
            ];
            $gradeDistribution = $stats['grade_distribution'] ?? self::emptyDistribution();
        }

        // Per-subject breakdown, over the same (possibly filtered) cohort.
        $rows = [];
        foreach (self::subjectCatalogue($pack) as $sid => $meta) {
            $slice = self::subjectSlice($pack, $sid, $students);
            $rows[] = [
                'id' => $sid,
                'subject_name' => $meta['subject_name'],
                'subject_name_en' => $meta['subject_name_en'],
                'average' => $slice['average'],
                'pass_rate' => $slice['pass_rate'],
                'highest' => $slice['highest'],
                'lowest' => $slice['lowest'],
                'graded_students' => $slice['graded'],
                'pending_students' => $slice['pending'],
                'grade_distribution' => $slice['grade_distribution'],
                'semester_1_average' => $slice['semester_1_average'],
                'semester_2_average' => $slice['semester_2_average'],
                'duration_type' => $slice['duration_type'],
                'subject_status_counts' => $slice['status_counts'],
                'completion' => $slice['completion'],
            ];
        }
        usort($rows, static fn($a, $b) => strcasecmp((string)$a['subject_name'], (string)$b['subject_name']));

        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = (int)($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $slicedStudents = array_slice($students, ($page - 1) * $perPage, $perPage);

        $stats = is_array($pack['stats'] ?? null) ? $pack['stats'] : [];

        return [
            'status' => 'success',
            'perspective' => 'class',
            'academic_year' => self::yearEnvelope($pack['year'] ?? null),
            'term' => self::termEnvelope($pack['term'] ?? null),
            'filters' => self::echoFilters($filters),
            'summary' => array_merge([
                'class_id' => (int)($pack['class']['id'] ?? $classId),
                'class_name' => (string)($pack['class']['class_name'] ?? ''),
                'class_name_en' => (string)($pack['class']['class_name_en'] ?? ''),
                'total_students' => count($students),
                'total_students_unfiltered' => count($allStudents),
                'is_filtered' => $isFiltered,
                'subjects_count' => count($rows),
                'attendance_rate' => self::attendanceMean($pack, $students),
                'curriculum_recorded_pct' => $stats['semester']['recorded'] ?? null,
                'curriculum_remaining_pct' => $stats['semester']['remaining'] ?? null,
                'subjects_incomplete' => (int)($stats['semester']['subjects_left'] ?? 0),
            ], $summaryNumbers),
            'rows' => $rows,
            'charts' => [
                'subject_performance' => array_values(array_map(static function ($r) {
                    return ['label' => $r['subject_name'], 'value' => $r['average']];
                }, $rows)),
                'grade_distribution' => $gradeDistribution,
                'completion_by_subject' => array_values(array_map(static function ($r) {
                    return ['label' => $r['subject_name'], 'value' => $r['completion']['recorded'] ?? null];
                }, $rows)),
                'attendance_vs_result' => array_values(array_map(static function ($s) {
                    return [
                        'member_id' => (int)$s['id'],
                        'label' => (string)($s['student_name'] ?? ''),
                        'attendance' => (int)($s['total_days'] ?? 0) > 0 ? $s['attendance_rate'] : null,
                        'result' => $s['overall_average'],
                    ];
                }, $students)),
                'semester_comparison' => array_values(array_map(static function ($r) {
                    return [
                        'label' => $r['subject_name'],
                        'semester_1' => $r['semester_1_average'],
                        'semester_2' => $r['semester_2_average'],
                    ];
                }, $rows)),
            ],
            'drilldown' => [
                'students' => array_values(array_map(static function ($s) {
                    return [
                        'member_id' => (int)$s['id'],
                        'student_name' => (string)($s['student_name'] ?? ''),
                        'father_name' => (string)($s['father_name'] ?? ''),
                        'member_code' => (string)($s['member_code'] ?? ''),
                        'gender' => (string)($s['gender'] ?? ''),
                        'overall_average' => $s['overall_average'],
                        'grade_letter' => $s['grade_letter'] ?? null,
                        'rank' => $s['rank'] ?? null,
                        'rank_tied' => !empty($s['tied']),
                        'attendance_rate' => (int)($s['total_days'] ?? 0) > 0 ? $s['attendance_rate'] : null,
                        'has_attendance' => (int)($s['total_days'] ?? 0) > 0,
                        'subjects_count' => (int)($s['subjects_count'] ?? 0),
                    ];
                }, $slicedStudents)),
                'subject_ids' => array_values(array_map(static fn($r) => (int)$r['id'], $rows)),
                'page' => $page,
                'per_page' => $perPage,
                'total' => count($students),
                'has_more' => (($page - 1) * $perPage + count($slicedStudents)) < count($students),
            ],
            'pass_mark' => ReportCardService::PASS_MARK,
            'grade_scale' => ReportCardService::GRADE_SCALE,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // FILTER CATALOGUE
    // ════════════════════════════════════════════════════════════════════

    /**
     * The choices the workspace filter bar can offer. Only values with a
     * real backend meaning are returned.
     *
     * @return array<string,mixed>
     */
    public static function options(\mysqli $conn, int $yearId = 0): array
    {
        if ($yearId <= 0) {
            $yearId = ReportCardService::currentYearId($conn);
        }

        $years = [];
        $res = $conn->query("SELECT id, year_name, ec_year, is_current FROM academic_years ORDER BY id DESC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $years[] = [
                    'id' => (int)$row['id'],
                    'year_name' => (string)$row['year_name'],
                    'ec_year' => $row['ec_year'] !== null ? (int)$row['ec_year'] : null,
                    'is_current' => (int)$row['is_current'] === 1,
                ];
            }
        }

        $terms = [];
        $stmt = $conn->prepare(
            "SELECT id, term_name, term_number, is_current
             FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number"
        );
        if ($stmt) {
            $stmt->bind_param('i', $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $terms[] = [
                    'id' => (int)$row['id'],
                    'term_name' => (string)$row['term_name'],
                    'term_number' => (int)$row['term_number'],
                    'is_current' => (int)$row['is_current'] === 1,
                ];
            }
            $stmt->close();
        }

        $classes = [];
        $res = $conn->query(
            "SELECT id, class_name, class_name_en, level_order
             FROM classes WHERE is_active = 1 ORDER BY level_order, id"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $classes[] = [
                    'id' => (int)$row['id'],
                    'class_name' => (string)$row['class_name'],
                    'class_name_en' => (string)($row['class_name_en'] ?? ''),
                    'level_order' => (int)($row['level_order'] ?? 0),
                ];
            }
        }

        $subjects = [];
        $res = $conn->query(
            "SELECT DISTINCT s.id, s.subject_name, s.subject_name_en
             FROM subjects s
             INNER JOIN class_subjects cs ON cs.subject_id = s.id
             WHERE (s.is_active = 1 OR s.is_active IS NULL)
             ORDER BY s.subject_name"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $subjects[] = [
                    'id' => (int)$row['id'],
                    'subject_name' => (string)$row['subject_name'],
                    'subject_name_en' => (string)($row['subject_name_en'] ?? ''),
                ];
            }
        }

        // Only teachers who actually hold an assignment can be analysed.
        $teachers = [];
        $sql = "SELECT DISTINCT u.id, u.full_name, u.email
                FROM users u
                INNER JOIN teacher_assignments ta ON ta.teacher_id = u.id
                WHERE u.role = 'teacher'
                  AND (ta.is_active = 1 OR ta.is_active IS NULL)";
        if ($yearId > 0) {
            $sql .= " AND (ta.academic_year_id = " . (int)$yearId
                . " OR ta.academic_year_id IS NULL OR ta.academic_year_id = 0)";
        }
        $sql .= " ORDER BY u.full_name";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $teachers[] = [
                    'id' => (int)$row['id'],
                    'full_name' => (string)($row['full_name'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                ];
            }
        }

        return [
            'status' => 'success',
            'academic_year_id' => $yearId,
            'years' => $years,
            'terms' => $terms,
            'classes' => $classes,
            'subjects' => $subjects,
            'teachers' => $teachers,
            'perspectives' => self::PERSPECTIVES,
            'pass_mark' => ReportCardService::PASS_MARK,
            'grade_scale' => ReportCardService::GRADE_SCALE,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // INTERNALS — pack access and projection
    // ════════════════════════════════════════════════════════════════════

    /**
     * The authoritative class pack, computed at most once per class per
     * request.
     *
     * @return array<string,mixed>
     */
    private static function pack(\mysqli $conn, int $classId, int $yearId, int $termId): array
    {
        $key = $classId . ':' . $yearId . ':' . $termId;
        if (!array_key_exists($key, self::$packs)) {
            try {
                self::$packs[$key] = ReportCardService::getClassReport($conn, $classId, 0, $yearId, $termId);
            } catch (\Throwable $e) {
                error_log('AcademicIntelligenceService::pack ' . $e->getMessage());
                self::$packs[$key] = ['status' => 'error', 'message' => 'Could not load this class.'];
            }
        }
        return self::$packs[$key];
    }

    /**
     * Every subject that appears on this class's report, with its names.
     *
     * @param array<string,mixed> $pack
     * @return array<int,array{subject_name:string,subject_name_en:string}>
     */
    private static function subjectCatalogue(array $pack): array
    {
        // Driven by what actually reached the students' reports, NOT by
        // stats.subjects. stats.subjects lists every offering attached to
        // the class, including a SEMESTER_ONLY subject that does not run in
        // the requested semester; computeStudent() has already dropped those
        // from each student. Reading the student rows is therefore what
        // keeps a Semester 1 subject off the Semester 2 report instead of
        // showing it as an empty row — the behaviour migration 056 exists to
        // produce.
        $out = [];
        foreach (($pack['students'] ?? []) as $st) {
            foreach (($st['subjects'] ?? []) as $sub) {
                $sid = (int)($sub['id'] ?? 0);
                if ($sid > 0 && !isset($out[$sid])) {
                    $out[$sid] = [
                        'subject_name' => (string)($sub['subject_name'] ?? ''),
                        'subject_name_en' => '',
                    ];
                }
            }
        }
        // Enrich with the English names the stats rows carry.
        foreach (($pack['stats']['subjects'] ?? []) as $s) {
            $sid = (int)$s['id'];
            if (isset($out[$sid])) {
                $out[$sid]['subject_name'] = (string)($s['subject_name'] ?? $out[$sid]['subject_name']);
                $out[$sid]['subject_name_en'] = (string)($s['subject_name_en'] ?? '');
            }
        }
        return $out;
    }

    /**
     * One subject's cohort result inside one class.
     *
     * Reads the per-student `final_percentage` the engine already decided
     * (duration-aware, semester-weighted) and counts it. Rounding to one
     * decimal before aggregating mirrors what a single-subject report card
     * does, which is why this agrees with
     * ReportCardService::getClassReport($conn, $classId, $subjectId, ...).
     *
     * @param array<string,mixed> $pack
     * @param list<array<string,mixed>>|null $students restrict to this cohort
     * @return array<string,mixed>
     */
    private static function subjectSlice(array $pack, int $subjectId, ?array $students = null): array
    {
        $students = $students ?? (is_array($pack['students'] ?? null) ? $pack['students'] : []);

        $finals = [];
        $s1 = [];
        $s2 = [];
        $enrolled = 0;
        $statusCounts = [];
        $duration = null;
        $present = false;

        foreach ($students as $st) {
            foreach (($st['subjects'] ?? []) as $sub) {
                if ((int)($sub['id'] ?? 0) !== $subjectId) {
                    continue;
                }
                $present = true;
                $enrolled++;
                if ($duration === null) {
                    $duration = $sub['duration_type'] ?? null;
                }
                $status = (string)($sub['subject_status'] ?? '');
                if ($status !== '') {
                    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                }
                if (($sub['final_percentage'] ?? null) !== null) {
                    $finals[] = round((float)$sub['final_percentage'], 1);
                }
                if (($sub['semester_1_score'] ?? null) !== null) {
                    $s1[] = (float)$sub['semester_1_score'];
                }
                if (($sub['semester_2_score'] ?? null) !== null) {
                    $s2[] = (float)$sub['semester_2_score'];
                }
            }
        }

        $stats = self::cohortStats($finals);
        $completion = null;
        foreach (($pack['stats']['subjects'] ?? []) as $ss) {
            if ((int)$ss['id'] === $subjectId) {
                $completion = $ss['completion'] ?? null;
                break;
            }
        }

        return [
            'values' => $finals,
            'present_in_report' => $present,
            'enrolled' => $enrolled,
            'graded' => $stats['graded'],
            'pending' => max(0, $enrolled - $stats['graded']),
            'average' => $stats['average'],
            'pass_rate' => $stats['pass_rate'],
            'highest' => $stats['highest'],
            'lowest' => $stats['lowest'],
            'grade_distribution' => $stats['grade_distribution'],
            'semester_1_average' => $s1 ? round(array_sum($s1) / count($s1), 1) : null,
            'semester_2_average' => $s2 ? round(array_sum($s2) / count($s2), 1) : null,
            'duration_type' => $duration,
            'status_counts' => $statusCounts,
            'completion' => $completion,
        ];
    }

    /**
     * A whole class as one slice, for a homeroom assignment that names no
     * subject.
     *
     * @param array<string,mixed> $pack
     * @return array<string,mixed>
     */
    private static function classSlice(array $pack): array
    {
        $values = [];
        foreach (($pack['students'] ?? []) as $st) {
            if (($st['overall_average'] ?? null) !== null) {
                $values[] = (float)$st['overall_average'];
            }
        }
        $stats = self::cohortStats($values);
        $total = (int)($pack['stats']['total_students'] ?? count($pack['students'] ?? []));

        return [
            'values' => $values,
            'present_in_report' => true,
            'enrolled' => $total,
            'graded' => $stats['graded'],
            'pending' => max(0, $total - $stats['graded']),
            'average' => $stats['average'],
            'pass_rate' => $stats['pass_rate'],
            'highest' => $stats['highest'],
            'lowest' => $stats['lowest'],
            'grade_distribution' => $stats['grade_distribution'],
            'semester_1_average' => null,
            'semester_2_average' => null,
            'duration_type' => null,
            'status_counts' => [],
            'completion' => $pack['stats']['semester'] ?? null,
        ];
    }

    /**
     * Cohort arithmetic over values the engine produced.
     *
     * These are the same rules buildRankedClass() applies to a class: the
     * mean to one decimal, PASS_MARK for the pass rate, and the GRADE_SCALE
     * thresholds for the spread. They are applied here because a per-subject
     * or filtered cohort is a subset of a class, which the engine is never
     * asked about directly. No value in $values is computed here.
     *
     * @param list<float> $values
     * @return array<string,mixed>
     */
    private static function cohortStats(array $values): array
    {
        $values = array_values(array_filter($values, static fn($v) => $v !== null));
        $n = count($values);
        if ($n === 0) {
            return [
                'graded' => 0,
                'average' => null,
                'median' => null,
                'highest' => null,
                'lowest' => null,
                'pass_rate' => null,
                'grade_distribution' => self::emptyDistribution(),
            ];
        }
        $sorted = $values;
        sort($sorted);
        $mid = (int)floor(($n - 1) / 2);
        $median = ($n % 2 === 1)
            ? round($sorted[$mid], 1)
            : round(($sorted[$mid] + $sorted[$mid + 1]) / 2, 1);

        return [
            'graded' => $n,
            'average' => round(array_sum($values) / $n, 1),
            'median' => $median,
            'highest' => round(max($values), 1),
            'lowest' => round(min($values), 1),
            'pass_rate' => round(count(array_filter(
                $values,
                static fn($v) => $v >= ReportCardService::PASS_MARK
            )) / $n * 100, 1),
            'grade_distribution' => [
                'A' => count(array_filter($values, static fn($v) => $v >= 90)),
                'B' => count(array_filter($values, static fn($v) => $v >= 80 && $v < 90)),
                'C' => count(array_filter($values, static fn($v) => $v >= 70 && $v < 80)),
                'D' => count(array_filter($values, static fn($v) => $v >= 60 && $v < 70)),
                'F' => count(array_filter($values, static fn($v) => $v < 60)),
            ],
        ];
    }

    /** @return array<string,int> */
    private static function emptyDistribution(): array
    {
        return ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
    }

    /**
     * Mean attendance over students who actually have attendance recorded.
     * A student with no sessions is excluded rather than counted as 0%.
     *
     * @param array<string,mixed> $pack
     * @param list<array<string,mixed>>|null $students
     */
    private static function attendanceMean(array $pack, ?array $students = null): ?float
    {
        $students = $students ?? (is_array($pack['students'] ?? null) ? $pack['students'] : []);
        $rates = [];
        foreach ($students as $st) {
            if ((int)($st['total_days'] ?? 0) > 0) {
                $rates[] = (float)($st['attendance_rate'] ?? 0);
            }
        }
        return $rates ? round(array_sum($rates) / count($rates), 1) : null;
    }

    /**
     * Student-level filters, applied to engine-produced rows.
     *
     * Mirrors the predicates ReportCardService::filterStudentsPerformance()
     * already supports, so the Education department sees the same filter
     * behaviour in both places. Selection only: no value is recomputed.
     *
     * @param list<array<string,mixed>> $students
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private static function applyStudentFilters(array $students, array $filters): array
    {
        $gender = (!empty($filters['gender']) && $filters['gender'] !== 'all')
            ? strtolower(trim((string)$filters['gender'])) : null;
        $letter = (!empty($filters['grade_letter']) && $filters['grade_letter'] !== 'all')
            ? strtoupper(trim((string)$filters['grade_letter'])) : null;
        $minGrade = (isset($filters['min_grade']) && $filters['min_grade'] !== '' && $filters['min_grade'] !== null)
            ? (float)$filters['min_grade'] : null;
        $maxGrade = (isset($filters['max_grade']) && $filters['max_grade'] !== '' && $filters['max_grade'] !== null)
            ? (float)$filters['max_grade'] : null;
        $minAtt = (isset($filters['min_attendance']) && $filters['min_attendance'] !== '' && $filters['min_attendance'] !== null)
            ? (float)$filters['min_attendance'] : null;
        $maxAtt = (isset($filters['max_attendance']) && $filters['max_attendance'] !== '' && $filters['max_attendance'] !== null)
            ? (float)$filters['max_attendance'] : null;
        $search = !empty($filters['search']) ? strtolower(trim((string)$filters['search'])) : null;

        if ($gender === null && $letter === null && $minGrade === null && $maxGrade === null
            && $minAtt === null && $maxAtt === null && $search === null) {
            return array_values($students);
        }

        $out = [];
        foreach ($students as $st) {
            $avg = $st['overall_average'] ?? null;
            $att = (float)($st['attendance_rate'] ?? 0);
            $days = (int)($st['total_days'] ?? 0);

            if ($gender !== null && strtolower((string)($st['gender'] ?? '')) !== $gender) {
                continue;
            }
            if ($letter !== null && strtoupper((string)($st['grade_letter'] ?? '')) !== $letter) {
                continue;
            }
            if ($minGrade !== null && ($avg === null || (float)$avg < $minGrade)) {
                continue;
            }
            if ($maxGrade !== null && ($avg === null || (float)$avg > $maxGrade)) {
                continue;
            }
            if ($minAtt !== null && ($days === 0 || $att < $minAtt)) {
                continue;
            }
            if ($maxAtt !== null && $maxAtt < 100.0 && ($days === 0 || $att > $maxAtt)) {
                continue;
            }
            if ($search !== null) {
                $hay = strtolower(
                    (string)($st['student_name'] ?? '') . ' '
                    . (string)($st['father_name'] ?? '') . ' '
                    . (string)($st['member_code'] ?? '') . ' '
                    . (string)($st['christian_name'] ?? '')
                );
                if (strpos($hay, $search) === false) {
                    continue;
                }
            }
            $out[] = $st;
        }
        return $out;
    }

    // ════════════════════════════════════════════════════════════════════
    // INTERNALS — relationships
    // ════════════════════════════════════════════════════════════════════

    /** @return array<string,mixed>|null */
    private static function fetchTeacher(\mysqli $conn, int $teacherId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, role FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private static function fetchSubject(\mysqli $conn, int $subjectId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT id, subject_name, subject_name_en FROM subjects WHERE id = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * A teacher's active assignments for the year.
     *
     * @return list<array<string,mixed>>
     */
    private static function fetchAssignments(
        \mysqli $conn,
        int $teacherId,
        int $yearId,
        int $classFilter = 0,
        int $subjectFilter = 0
    ): array {
        $sql = "SELECT ta.id, ta.class_id, ta.subject_id, ta.assignment_role, ta.is_class_teacher,
                       COALESCE(c.class_name, 'Class') AS class_name,
                       COALESCE(c.class_name_en, '') AS class_name_en,
                       COALESCE(c.level_order, 0) AS level_order,
                       COALESCE(s.subject_name, '') AS subject_name,
                       COALESCE(s.subject_name_en, '') AS subject_name_en
                FROM teacher_assignments ta
                LEFT JOIN classes c ON c.id = ta.class_id
                LEFT JOIN subjects s ON s.id = ta.subject_id
                WHERE ta.teacher_id = ?
                  AND (ta.is_active = 1 OR ta.is_active IS NULL)";
        $params = [$teacherId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= " AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL OR ta.academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($classFilter > 0) {
            $sql .= " AND ta.class_id = ?";
            $params[] = $classFilter;
            $types .= 'i';
        }
        if ($subjectFilter > 0) {
            $sql .= " AND ta.subject_id = ?";
            $params[] = $subjectFilter;
            $types .= 'i';
        }
        $sql .= " ORDER BY c.level_order, c.class_name, s.subject_name LIMIT " . self::MAX_CLASSES_PER_REQUEST;

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $out[] = $row;
        }
        $stmt->close();
        return $out;
    }

    /**
     * Classes that actually offer a subject. Never "all classes".
     *
     * @return list<array<string,mixed>>
     */
    private static function classesOfferingSubject(\mysqli $conn, int $subjectId, int $classFilter = 0): array
    {
        $sql = "SELECT c.id, c.class_name, c.class_name_en, c.level_order
                FROM classes c
                INNER JOIN class_subjects cs ON cs.class_id = c.id
                WHERE cs.subject_id = ? AND c.is_active = 1";
        $params = [$subjectId];
        $types = 'i';
        if ($classFilter > 0) {
            $sql .= " AND c.id = ?";
            $params[] = $classFilter;
            $types .= 'i';
        }
        $sql .= " ORDER BY c.level_order, c.id LIMIT " . self::MAX_CLASSES_PER_REQUEST;

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $out[] = $row;
        }
        $stmt->close();
        return $out;
    }

    /**
     * Who teaches a subject, per class. A class with nobody assigned gets an
     * empty list rather than a guess.
     *
     * @return array<int,list<array{teacher_id:int,teacher_name:string}>>
     */
    private static function teachersForSubject(\mysqli $conn, int $subjectId, int $yearId): array
    {
        $sql = "SELECT ta.class_id, ta.teacher_id, COALESCE(u.full_name, 'Teacher') AS full_name
                FROM teacher_assignments ta
                LEFT JOIN users u ON u.id = ta.teacher_id
                WHERE ta.subject_id = ? AND (ta.is_active = 1 OR ta.is_active IS NULL)";
        $params = [$subjectId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= " AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL OR ta.academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= " ORDER BY u.full_name";

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        $seen = [];
        while ($row = $r->fetch_assoc()) {
            $cid = (int)$row['class_id'];
            $tid = (int)$row['teacher_id'];
            if (isset($seen[$cid][$tid])) {
                continue;
            }
            $seen[$cid][$tid] = true;
            $out[$cid][] = [
                'teacher_id' => $tid,
                'teacher_name' => (string)$row['full_name'],
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Planned assessments and their submission state, indexed by
     * class then subject. Reuses the governance rules already used by the
     * Analytics Hub: an assessment with no grade_submissions row is missing.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private static function governanceIndex(\mysqli $conn, int $yearId, int $termId): array
    {
        $planned = [];
        $sql = "SELECT id, class_id, subject_id, assessment_name FROM assessments WHERE 1=1";
        $params = [];
        $types = '';
        if ($yearId > 0) {
            $sql .= " AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= " AND (term_id = ? OR term_id IS NULL OR term_id = 0)";
            $params[] = $termId;
            $types .= 'i';
        }
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $planned[(int)$row['class_id']][(int)$row['subject_id']][(int)$row['id']] =
                    (string)$row['assessment_name'];
            }
            $stmt->close();
        }

        $submissions = [];
        $sql = "SELECT class_id, subject_id, assessment_id, status FROM grade_submissions WHERE 1=1";
        $params = [];
        $types = '';
        if ($yearId > 0) {
            $sql .= " AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $submissions[(int)$row['class_id']][(int)$row['subject_id']][(int)$row['assessment_id']] =
                    (string)$row['status'];
            }
            $stmt->close();
        }

        $index = [];
        foreach ($planned as $cid => $bySubject) {
            foreach ($bySubject as $sid => $items) {
                $row = self::emptyGovernance();
                foreach ($items as $aid => $title) {
                    $row['planned']++;
                    $status = $submissions[$cid][$sid][$aid] ?? 'missing';
                    switch ($status) {
                        case 'approved':
                            $row['approved']++;
                            break;
                        case 'submitted':
                            $row['submitted']++;
                            break;
                        case 'draft':
                        case 'incomplete':
                            $row['draft']++;
                            break;
                        case 'revision_needed':
                        case 'rejected':
                            $row['revision_needed']++;
                            break;
                        default:
                            $row['missing']++;
                            $row['missing_titles'][] = $title;
                    }
                }
                $index[$cid][$sid] = $row;
            }
        }
        return $index;
    }

    /** @return array<string,mixed> */
    private static function emptyGovernance(): array
    {
        return [
            'planned' => 0,
            'approved' => 0,
            'submitted' => 0,
            'draft' => 0,
            'revision_needed' => 0,
            'missing' => 0,
            'missing_titles' => [],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // INTERNALS — envelope helpers
    // ════════════════════════════════════════════════════════════════════

    /** @return array<string,mixed>|null */
    private static function fetchYearRow(\mysqli $conn, int $yearId): ?array
    {
        if ($yearId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, year_name, ec_year, is_current FROM academic_years WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private static function fetchTermRow(\mysqli $conn, int $termId): ?array
    {
        if ($termId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id, term_name, term_number, academic_year_id FROM academic_terms WHERE id = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $termId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * @param array<string,mixed>|null $year
     * @return array<string,mixed>|null
     */
    private static function yearEnvelope(?array $year): ?array
    {
        if (!$year) {
            return null;
        }
        return [
            'id' => (int)($year['id'] ?? 0),
            'year_name' => (string)($year['year_name'] ?? ''),
            'ec_year' => isset($year['ec_year']) && $year['ec_year'] !== null ? (int)$year['ec_year'] : null,
            'is_current' => (int)($year['is_current'] ?? 0) === 1,
        ];
    }

    /**
     * Null means the ANNUAL view, which is a real and distinct selection,
     * not a missing value.
     *
     * @param array<string,mixed>|null $term
     * @return array<string,mixed>|null
     */
    private static function termEnvelope(?array $term): ?array
    {
        if (!$term || (int)($term['id'] ?? 0) <= 0) {
            return null;
        }
        return [
            'id' => (int)$term['id'],
            'term_name' => (string)($term['term_name'] ?? ''),
            'term_number' => (int)($term['term_number'] ?? 0),
        ];
    }

    /**
     * Echo back only the filters that were actually understood, so the
     * client can show what is in force.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private static function echoFilters(array $filters): array
    {
        $known = [
            'class_id', 'subject_id', 'teacher_id', 'gender', 'grade_letter',
            'min_grade', 'max_grade', 'min_attendance', 'max_attendance',
            'search', 'page', 'per_page',
        ];
        $out = [];
        foreach ($known as $k) {
            if (isset($filters[$k]) && $filters[$k] !== '' && $filters[$k] !== null) {
                $out[$k] = $filters[$k];
            }
        }
        return $out;
    }
}
