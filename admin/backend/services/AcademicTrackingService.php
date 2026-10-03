<?php
/**
 * ════════════════════════════════════════════════════════════
 * AcademicTrackingService — the scoped read layer behind the
 * Academic Tracking workflow (Phase 2: Student).
 *
 * WHAT THIS CLASS IS
 * ------------------
 * It resolves a scope (which student, in which class, for which
 * academic context), asks the authoritative engines for that
 * scope's results, and shapes them into the sections the
 * tracking UI renders. That is all.
 *
 * WHAT THIS CLASS IS NOT
 * ----------------------
 * It is NOT a calculation engine. There is no formula in this
 * file and there must never be one:
 *
 *   grades, subject averages, final percentages, grade letters,
 *   pass/fail, ranking, semester weights and duration policy
 *     → ReportCardService (via getCard), which in turn uses
 *       SubjectDurationPolicy for the semester/full-year rules
 *
 *   assessment workflow status
 *     → SubmissionService, normalised by ReviewTransitionPolicy
 *
 *   class visibility
 *     → ReportCardService::canViewClass
 *
 * Every number this file emits was produced by one of those.
 * tests/security/test_student_tracking.py pins that: it diffs
 * this output against ReportCardService::getCard() field by
 * field, and it greps this source for formula tokens.
 *
 * THE ONE RULE THAT SHAPES EVERYTHING ELSE
 * ----------------------------------------
 * An absent record is not a zero.
 *
 * ReportCardService::emptyAttendance() legitimately returns
 * rate => 0 for a student with no attendance rows, because its
 * own consumers read `total` to know whether the rate means
 * anything. A tracking screen that printed that 0 would be
 * stating "this student attended 0% of classes", which is a
 * different and false claim. So every section here carries an
 * explicit data_state and nulls the metric it cannot justify,
 * rather than letting a default leak into the UI.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

require_once __DIR__ . '/ReportCardService.php';
require_once __DIR__ . '/SubmissionService.php';
require_once __DIR__ . '/SubjectDurationPolicy.php';

final class AcademicTrackingService
{
    /** Section carries real, authoritative data. */
    public const STATE_OK = 'ok';
    /** The student is not enrolled in any class for this context. */
    public const STATE_NO_ENROLMENT = 'no_enrolment';
    /** The class offers no subjects in this context. */
    public const STATE_NO_SUBJECTS = 'no_subjects';
    /** Subjects are offered but not one of them has a final result yet. */
    public const STATE_NO_RESULTS = 'no_results';
    /** No assessment has been planned for any of this student's subjects. */
    public const STATE_NO_ASSESSMENTS = 'no_assessments';
    /** Not one attendance row exists — which is NOT an attendance rate of 0. */
    public const STATE_NO_ATTENDANCE = 'no_attendance';

    /**
     * Header + overview + subjects + attendance for one student.
     *
     * These four sections are returned together on purpose. They are all
     * views of a single ReportCardService::getCard() result, so splitting
     * them across four endpoints would multiply the cost of the only
     * expensive call by four and buy the user nothing: switching between
     * them would still show the same already-fetched numbers. The sections
     * that genuinely cost more — assessments (extra workflow lookup) and
     * the report card — are separate, lazy calls.
     *
     * @return array<string,mixed>
     */
    public static function studentDetail(
        \mysqli $conn,
        int $memberId,
        int $classId = 0,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($memberId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_student', 'message' => 'Student is required.'];
        }

        $card = ReportCardService::getCard($conn, $memberId, $classId, $yearId, $termId);
        if (($card['status'] ?? '') !== 'success') {
            // getCard already distinguishes "not in a class" from "not in
            // THIS class" from a build failure. Keep its sentence rather
            // than flattening all three into one.
            return [
                'status' => 'error',
                'code' => self::errorCode((string)($card['message'] ?? '')),
                'message' => (string)($card['message'] ?? 'Could not load this student.'),
            ];
        }

        $subjects   = is_array($card['subjects'] ?? null) ? $card['subjects'] : [];
        $totals     = is_array($card['totals'] ?? null) ? $card['totals'] : [];
        $attendance = is_array($card['attendance'] ?? null) ? $card['attendance'] : [];
        $student    = is_array($card['student'] ?? null) ? $card['student'] : [];

        $rows = [];
        $withResult = 0;
        foreach ($subjects as $s) {
            $hasResult = ($s['final_percentage'] ?? null) !== null;
            if ($hasResult) {
                $withResult++;
            }
            $completion = is_array($s['completion'] ?? null) ? $s['completion'] : [];
            $rows[] = [
                'subject_id'        => (int)($s['id'] ?? 0),
                'subject_name'      => (string)($s['subject_name'] ?? ''),
                'subject_name_en'   => (string)($s['subject_name_en'] ?? ''),
                // Duration policy is read, never re-derived. A SEMESTER_ONLY
                // offering must never be rendered as if it were FULL_YEAR.
                'duration_type'     => $s['duration_type'] ?? null,
                'offering_term'     => $s['offering_term_number'] ?? null,
                'semester_1_score'  => $s['semester_1_score'] ?? null,
                'semester_2_score'  => $s['semester_2_score'] ?? null,
                'semester_weights'  => $s['semester_weights'] ?? null,
                'final_percentage'  => $s['final_percentage'] ?? null,
                'grade_letter'      => $s['grade_letter'] ?? null,
                'subject_status'    => $s['subject_status'] ?? null,
                'status_reason'     => (string)($s['status_reason'] ?? ''),
                'has_result'        => $hasResult,
                'recorded_percent'  => $completion['recorded'] ?? null,
                'planned_percent'   => $completion['planned'] ?? null,
                'assessments_count' => count(is_array($s['assessments'] ?? null) ? $s['assessments'] : []),
            ];
        }

        $hasAttendance = (int)($attendance['total'] ?? 0) > 0;

        return [
            'status' => 'success',
            'scope' => [
                'type'      => 'student',
                'member_id' => $memberId,
                'class_id'  => (int)($card['class']['id'] ?? 0),
            ],
            'context' => [
                'year_id'   => (int)($card['year']['id'] ?? 0),
                'year_name' => (string)($card['year']['year_name'] ?? $card['year']['name'] ?? ''),
                'term_id'   => (int)($card['term']['id'] ?? 0),
                'term_name' => (string)($card['term']['term_name'] ?? $card['term']['name'] ?? ''),
                // termId 0 means the annual view; the engine reports which
                // mode it actually used rather than the UI guessing.
                'is_annual' => !empty($totals['is_annual']),
                'semester_weights' => $totals['semester_weights'] ?? null,
            ],
            // Identity only. No phone number, no credential field, no
            // administrative column — see safeStudent() in the engine.
            'student' => [
                'id'             => (int)($student['id'] ?? $memberId),
                'student_name'   => (string)($student['student_name'] ?? ''),
                'father_name'    => (string)($student['father_name'] ?? ''),
                'christian_name' => (string)($student['christian_name'] ?? ''),
                'member_code'    => (string)($student['member_code'] ?? ''),
                'gender'         => (string)($student['gender'] ?? ''),
                'status'         => self::memberStatus($conn, $memberId),
            ],
            'class' => [
                'id'            => (int)($card['class']['id'] ?? 0),
                'class_name'    => (string)($card['class']['class_name'] ?? ''),
                'class_name_en' => (string)($card['class']['class_name_en'] ?? ''),
            ],
            'overview' => [
                'subjects_total'     => count($rows),
                'subjects_with_result' => $withResult,
                'subjects_pending'   => count($rows) - $withResult,
                // Null when the engine could not produce one. Never 0.
                'overall_average'    => $totals['average'] ?? null,
                'overall_grade'      => $totals['grade_letter'] ?? null,
                'rank'               => self::positiveOrNull($card['rank'] ?? null),
                'rank_tied'          => !empty($card['rank_tied']),
                'total_in_class'     => (int)($card['total_in_class'] ?? 0),
                'assessments_count'  => (int)($totals['assessments_count'] ?? 0),
                'strongest_subject'  => $card['highlights']['strongest']['subject_name'] ?? null,
                'weakest_subject'    => $card['highlights']['weakest']['subject_name'] ?? null,
            ],
            'subjects' => $rows,
            'attendance' => [
                'has_attendance' => $hasAttendance,
                'total'    => $hasAttendance ? (int)$attendance['total'] : null,
                'present'  => $hasAttendance ? (int)($attendance['present'] ?? 0) : null,
                'absent'   => $hasAttendance ? (int)($attendance['absent'] ?? 0) : null,
                'late'     => $hasAttendance ? (int)($attendance['late'] ?? 0) : null,
                'excused'  => $hasAttendance ? (int)($attendance['excused'] ?? 0) : null,
                // The engine's 0 is a placeholder when total is 0. Refuse it.
                'rate'     => $hasAttendance ? ($attendance['rate'] ?? null) : null,
            ],
            'data_state' => [
                'subjects'   => $rows ? self::STATE_OK : self::STATE_NO_SUBJECTS,
                'results'    => $withResult > 0
                    ? self::STATE_OK
                    : ($rows ? self::STATE_NO_RESULTS : self::STATE_NO_SUBJECTS),
                'attendance' => $hasAttendance ? self::STATE_OK : self::STATE_NO_ATTENDANCE,
            ],
            'pass_mark'   => ReportCardService::PASS_MARK,
            'grade_scale' => ReportCardService::GRADE_SCALE,
        ];
    }

    /**
     * Every assessment that exists for this student's subjects, with its
     * workflow status and — separately — this student's result on it.
     *
     * Status and result are two different facts and are never merged. A
     * mark list can be Approved while this particular student has no score
     * recorded, and a mark list can be Draft while a score already exists
     * in the table. Both are reported as they are.
     *
     * @return array<string,mixed>
     */
    public static function studentAssessments(
        \mysqli $conn,
        int $memberId,
        int $classId = 0,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($memberId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_student', 'message' => 'Student is required.'];
        }

        $card = ReportCardService::getCard($conn, $memberId, $classId, $yearId, $termId);
        if (($card['status'] ?? '') !== 'success') {
            return [
                'status' => 'error',
                'code' => self::errorCode((string)($card['message'] ?? '')),
                'message' => (string)($card['message'] ?? 'Could not load these assessments.'),
            ];
        }

        $subjects = is_array($card['subjects'] ?? null) ? $card['subjects'] : [];

        // Pass 1 — build the row set from the engine's own two lists:
        //   completion.items = every assessment PLANNED for the offering
        //   assessments      = the ones this student has a mark row for
        // Planned-but-unmarked assessments matter most here: they are the
        // work still outstanding, and a view built only from marks would
        // silently hide them.
        $rows = [];
        $ids = [];
        $unplanned = [];
        foreach ($subjects as $s) {
            $sid  = (int)($s['id'] ?? 0);
            $name = (string)($s['subject_name'] ?? '');
            $scored = [];
            foreach ((is_array($s['assessments'] ?? null) ? $s['assessments'] : []) as $a) {
                $aid = (int)($a['id'] ?? 0);
                if ($aid > 0) {
                    $scored[$aid] = $a;
                }
            }

            $completion = is_array($s['completion'] ?? null) ? $s['completion'] : [];
            $items = is_array($completion['items'] ?? null) ? $completion['items'] : [];
            $seen = [];
            foreach ($items as $it) {
                $aid = (int)($it['id'] ?? 0);
                if ($aid <= 0) {
                    // The engine appends a synthetic "Not yet set" item to
                    // represent weight that no assessment claims. It is not
                    // an assessment, so it is reported as a gap, not a row.
                    $unplanned[] = [
                        'subject_id'   => $sid,
                        'subject_name' => $name,
                        'weight'       => $it['weight'] ?? null,
                    ];
                    continue;
                }
                $seen[$aid] = true;
                $rows[] = self::assessmentRow($sid, $name, $aid, (string)($it['name'] ?? 'Assessment'), $it['weight'] ?? null, $scored[$aid] ?? null);
                $ids[$aid] = true;
            }
            // A scored assessment with no planned weight still happened.
            foreach ($scored as $aid => $a) {
                if (!empty($seen[$aid])) {
                    continue;
                }
                $rows[] = self::assessmentRow($sid, $name, $aid, (string)($a['assessment_name'] ?? 'Assessment'), $a['weight_percentage'] ?? null, $a);
                $ids[$aid] = true;
            }
        }

        // Pass 2 — one batched workflow lookup for every assessment on the
        // page. Calling the single-id resolver per row would be N+1.
        $statuses = SubmissionService::resolvedMarklistStatuses($conn, array_keys($ids));
        foreach ($rows as $i => $r) {
            $st = $statuses[$r['assessment_id']] ?? null;
            $rows[$i]['workflow_status'] = $st;
            // null is a real answer: no packet and no marks => never started.
            $rows[$i]['workflow_label'] = $st === null
                ? 'Not started'
                : SubmissionService::statusLabel($st);
        }

        usort($rows, static function ($a, $b) {
            $c = strcasecmp($a['subject_name'], $b['subject_name']);
            return $c !== 0 ? $c : strcasecmp($a['assessment_name'], $b['assessment_name']);
        });

        return [
            'status' => 'success',
            'scope' => [
                'type'      => 'student',
                'member_id' => $memberId,
                'class_id'  => (int)($card['class']['id'] ?? 0),
            ],
            'context' => [
                'year_id'   => (int)($card['year']['id'] ?? 0),
                'year_name' => (string)($card['year']['year_name'] ?? $card['year']['name'] ?? ''),
                'term_id'   => (int)($card['term']['id'] ?? 0),
                'term_name' => (string)($card['term']['term_name'] ?? $card['term']['name'] ?? ''),
            ],
            'rows' => array_values($rows),
            'unplanned_weight' => $unplanned,
            'data_state' => $rows ? self::STATE_OK : self::STATE_NO_ASSESSMENTS,
        ];
    }

    /**
     * One assessment row. `has_result` is the honest discriminator: a score
     * of null means no mark was recorded for THIS student, which is not the
     * same as a mark of zero and must never be rendered as one.
     *
     * @param array<string,mixed>|null $scored
     * @return array<string,mixed>
     */
    private static function assessmentRow(
        int $subjectId,
        string $subjectName,
        int $assessmentId,
        string $assessmentName,
        $weight,
        ?array $scored
    ): array {
        $score = $scored['score'] ?? null;
        return [
            'subject_id'      => $subjectId,
            'subject_name'    => $subjectName,
            'assessment_id'   => $assessmentId,
            'assessment_name' => $assessmentName,
            'weight'          => $weight === null ? null : (float)$weight,
            'has_result'      => $score !== null,
            'score'           => $score,
            'max_score'       => $scored['max_score'] ?? null,
            'percentage'      => $scored['percentage'] ?? null,
            'remarks'         => (string)($scored['remarks'] ?? ''),
            'workflow_status' => null,
            'workflow_label'  => '',
        ];
    }

    /**
     * Enrolment/membership status for the header. A single scoped row — the
     * one fact the report card does not carry.
     */
    private static function memberStatus(\mysqli $conn, int $memberId): string
    {
        $stmt = $conn->prepare("SELECT status FROM members WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (string)($row['status'] ?? '');
    }

    /** @param mixed $v */
    private static function positiveOrNull($v): ?int
    {
        $n = (int)$v;
        return $n > 0 ? $n : null;
    }

    /**
     * Map the engine's user-facing sentence onto a stable code so the UI can
     * pick the right empty state without string-matching English.
     */
    private static function errorCode(string $message): string
    {
        $m = strtolower($message);
        if (strpos($m, 'not in a class') !== false) {
            return self::STATE_NO_ENROLMENT;
        }
        if (strpos($m, 'not in the selected class') !== false) {
            return 'not_in_class';
        }
        return 'unavailable';
    }
}
