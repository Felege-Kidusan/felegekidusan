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
    /** Phase 3: the teacher holds no assignment in this academic context. */
    public const STATE_NO_ASSIGNMENTS = 'no_assignments';
    /** Phase 4: the subject is in the catalogue but offered to no class. */
    public const STATE_NOT_OFFERED = 'not_offered';
    /** Phase 4: the offering has no teacher assigned. */
    public const STATE_NO_TEACHERS = 'no_teachers';
    /** Phase 4: nobody is enrolled in the offering's class this year. */
    public const STATE_NO_STUDENTS = 'no_students';
    /** Phase 5: the class offers no subject at all. */
    public const STATE_NO_SUBJECTS_OFFERED = 'no_subjects_offered';
    /** Phase 5: no teacher is assigned to the class in any capacity. */
    public const STATE_NO_TEACHER_ASSIGNMENTS = 'no_teacher_assignments';
    /**
     * Phase 5: records exist, but the filters the user applied match none
     * of them. Distinct from an empty collection — this one is fixed by
     * clearing a filter, and only this one may say so.
     */
    public const STATE_FILTERED_EMPTY = 'filtered_empty';

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

    // ════════════════════════════════════════════════════════════
    // PHASE 3 — TEACHER TRACKING
    //
    // Teacher tracking answers a workflow question, not an academic
    // one: "what is this teacher responsible for, and where does
    // each of those mark lists currently stand?"
    //
    // Consequences that are deliberate, not oversights:
    //
    //   * Nothing below calls ReportCardService. A teacher has no
    //     academic result. Scores, averages and grades belong to a
    //     student in a class, and showing them aggregated under a
    //     teacher's name would turn a tracking screen into a staff
    //     evaluation. There is no ranking, no effectiveness figure
    //     and no comparative statistic here, by requirement.
    //
    //   * Assignments come from `teacher_assignments` and nowhere
    //     else. It is tempting to infer "subjects this teacher
    //     teaches" from the mark lists they have submitted, and it
    //     is wrong: the fixture alone contains a packet submitted
    //     by a teacher for an offering they hold no assignment for.
    //     An inferred relationship is an invented one.
    //
    //   * Workflow status comes from SubmissionService. This layer
    //     reads and labels it; it never decides it.
    // ════════════════════════════════════════════════════════════

    /**
     * Identity + the class/subject offerings one teacher is assigned to.
     *
     * Intentionally cheap: two scoped queries and no engine call, because
     * this runs every time a teacher is selected.
     *
     * @return array<string,mixed>
     */
    public static function teacherDetail(
        \mysqli $conn,
        int $teacherId,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($teacherId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_teacher', 'message' => 'Teacher is required.'];
        }

        $teacher = self::teacherIdentity($conn, $teacherId);
        if ($teacher === null) {
            return ['status' => 'error', 'code' => 'unknown_teacher', 'message' => 'That teacher could not be found.'];
        }

        $assignments = self::teacherAssignments($conn, $teacherId, $yearId);

        // One grouped query for the whole assignment set rather than one per
        // row: the count is a real COUNT(*), never a placeholder for a value
        // that was not looked up.
        $counts = self::assessmentCounts($conn, $assignments, $yearId, $termId);
        foreach ($assignments as $i => $a) {
            $key = $a['class_id'] . ':' . (int)$a['subject_id'];
            $assignments[$i]['assessment_count'] = $a['subject_id'] === null
                ? null                      // homeroom: no subject, so the question does not apply
                : (int)($counts[$key] ?? 0);
        }

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'teacher', 'teacher_id' => $teacherId],
            'context' => ['year_id' => $yearId, 'term_id' => $termId],
            'teacher' => $teacher,
            'assignments' => $assignments,
            'data_state'  => [
                'assignments' => $assignments ? self::STATE_OK : self::STATE_NO_ASSIGNMENTS,
            ],
        ];
    }

    /**
     * The assessments of ONE offering (class + subject) the teacher is
     * assigned to, each with its current mark-list workflow state.
     *
     * The teacher/class/subject triple is re-validated here against
     * `teacher_assignments`. The caller has already authorised the class,
     * but that only proves the viewer may see the class — not that this
     * teacher is connected to it. Without this check a caller could read
     * any offering's workflow through any teacher's id.
     *
     * No score is returned. An assessment's workflow status ("Approved")
     * and its academic result (a mark) are different facts, and merging
     * them is how a reviewed-but-failing mark list comes to look fine.
     *
     * @return array<string,mixed>
     */
    public static function teacherAssessments(
        \mysqli $conn,
        int $teacherId,
        int $classId,
        int $subjectId,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($teacherId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_teacher', 'message' => 'Teacher is required.'];
        }
        if ($classId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_class', 'message' => 'A class must be selected.'];
        }
        if ($subjectId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_subject', 'message' => 'A subject must be selected.'];
        }

        $assignment = self::findAssignment($conn, $teacherId, $classId, $subjectId, $yearId);
        if ($assignment === null) {
            // Separate the two reasons only once the lookup has already
            // failed, so the normal path still costs one query. A stale
            // assignment row belonging to someone who is no longer a
            // teacher must not read as "not assigned to this subject".
            if (self::teacherIdentity($conn, $teacherId) === null) {
                return [
                    'status'  => 'error',
                    'code'    => 'unknown_teacher',
                    'message' => 'That teacher could not be found.',
                ];
            }
            return [
                'status'  => 'error',
                'code'    => 'not_assigned',
                'message' => 'That teacher is not assigned to this class and subject.',
            ];
        }

        $sql = "SELECT a.id, a.assessment_name, a.assessment_type, a.max_score,
                       a.weight_percentage, a.term_id
                  FROM assessments a
                 WHERE a.class_id = ? AND a.subject_id = ?";
        $params = [$classId, $subjectId];
        $types  = 'ii';
        if ($yearId > 0) {
            $sql .= ' AND a.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND a.term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY a.term_id IS NULL, a.term_id, a.id';

        $rows = [];
        $ids  = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $aid = (int)$r['id'];
                $ids[] = $aid;
                $rows[] = [
                    'assessment_id'   => $aid,
                    'assessment_name' => (string)$r['assessment_name'],
                    'assessment_type' => (string)($r['assessment_type'] ?? ''),
                    'max_score'       => $r['max_score'] === null ? null : (float)$r['max_score'],
                    'weight'          => $r['weight_percentage'] === null ? null : (float)$r['weight_percentage'],
                    'term_id'         => self::positiveOrNull($r['term_id']),
                    'workflow_status' => null,
                    'workflow_label'  => '',
                    'submission_id'   => null,
                ];
            }
            $stmt->close();
        }

        if ($ids) {
            // One call, one query: status and the packet it came from are
            // resolved together. Asking the service twice cost an
            // identical second round trip per offering.
            $resolved = SubmissionService::resolvedMarklistRefs($conn, $ids);
            foreach ($rows as $i => $r) {
                $aid = $r['assessment_id'];
                $ref = $resolved[$aid] ?? ['status' => null, 'submission_id' => null];
                $st  = $ref['status'];
                $rows[$i]['workflow_status'] = $st;
                // null is a real answer: no packet and no marks => never started.
                $rows[$i]['workflow_label'] = $st === null
                    ? 'Not started'
                    : SubmissionService::statusLabel($st);
                // Only a real packet can be opened in the review screen. A
                // mark list that exists only as loose marks has no packet,
                // and inventing an id would send the user to a dead modal.
                $rows[$i]['submission_id'] = $ref['submission_id'];
            }
        }

        return [
            'status'  => 'success',
            'scope'   => [
                'type'       => 'teacher',
                'teacher_id' => $teacherId,
                'class_id'   => $classId,
                'subject_id' => $subjectId,
            ],
            'context' => ['year_id' => $yearId, 'term_id' => $termId],
            'class'   => ['id' => $classId, 'class_name' => $assignment['class_name']],
            'subject' => ['id' => $subjectId, 'subject_name' => $assignment['subject_name']],
            'assessments' => $rows,
            'data_state'  => [
                'assessments' => $rows ? self::STATE_OK : self::STATE_NO_ASSESSMENTS,
            ],
        ];
    }

    /**
     * The teacher's own record. Teachers are logins (`users`) linked to a
     * person (`members`) through a NULLABLE member_id, so the join has to
     * be a LEFT JOIN and the member fields have to be allowed to be absent.
     *
     * @return array<string,mixed>|null
     */
    private static function teacherIdentity(\mysqli $conn, int $teacherId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT u.id, u.full_name, u.username, u.email, u.is_active, u.member_id,
                    m.member_code, m.phone_number
               FROM users u
          LEFT JOIN members m ON u.member_id = m.id
              WHERE u.id = ? AND u.role = 'teacher'
              LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'id'          => (int)$row['id'],
            'full_name'   => (string)($row['full_name'] ?? ''),
            'username'    => (string)($row['username'] ?? ''),
            'email'       => (string)($row['email'] ?? ''),
            'is_active'   => (int)$row['is_active'] === 1,
            'member_code' => $row['member_code'] === null ? null : (string)$row['member_code'],
            'phone'       => $row['phone_number'] === null ? null : (string)$row['phone_number'],
        ];
    }

    /**
     * Active assignments for a teacher in one academic year.
     *
     * `academic_year_id` is nullable in this table: a row with no year is a
     * standing assignment that is not tied to one year, so it is kept when
     * a year is in scope rather than dropped.
     *
     * `subject_id` is nullable too, and that NULL is meaningful — migration
     * 006 made it so because "Homeroom does not need a subject". Such a row
     * is a real assignment with no subject, and is reported as exactly that
     * instead of being hidden or given a placeholder subject.
     *
     * @return list<array<string,mixed>>
     */
    private static function teacherAssignments(\mysqli $conn, int $teacherId, int $yearId): array
    {
        $sql = "SELECT ta.class_id, ta.subject_id, ta.assignment_role,
                       ta.is_class_teacher, ta.academic_year_id,
                       c.class_name, s.subject_name
                  FROM teacher_assignments ta
                  JOIN classes c  ON c.id = ta.class_id
             LEFT JOIN subjects s ON s.id = ta.subject_id
                 WHERE ta.teacher_id = ? AND ta.is_active = 1";
        $params = [$teacherId];
        $types  = 'i';
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY c.class_name, s.subject_name IS NULL DESC, s.subject_name';

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $subjectId = self::positiveOrNull($r['subject_id']);
            $out[] = [
                'class_id'         => (int)$r['class_id'],
                'class_name'       => (string)($r['class_name'] ?? ''),
                'subject_id'       => $subjectId,
                'subject_name'     => $subjectId === null ? null : (string)($r['subject_name'] ?? ''),
                'assignment_role'  => (string)($r['assignment_role'] ?? ''),
                'is_class_teacher' => (int)($r['is_class_teacher'] ?? 0) === 1,
                'is_homeroom'      => $subjectId === null,
                'assessment_count' => null,
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Confirms the teacher really holds this class+subject, and returns the
     * display names for the scope header in the same round trip.
     *
     * The `users` join is not decoration. teacher_assignments can outlive
     * the role it was granted for, and without the role condition this
     * lookup and teacherDetail() would disagree about who is a teacher —
     * one refusing an id the other accepts. The scope must mean the same
     * thing on both endpoints.
     *
     * @return array<string,string>|null
     */
    private static function findAssignment(
        \mysqli $conn,
        int $teacherId,
        int $classId,
        int $subjectId,
        int $yearId
    ): ?array {
        $sql = "SELECT c.class_name, s.subject_name
                  FROM teacher_assignments ta
                  JOIN users u    ON u.id = ta.teacher_id AND u.role = 'teacher'
                  JOIN classes c  ON c.id = ta.class_id
                  JOIN subjects s ON s.id = ta.subject_id
                 WHERE ta.teacher_id = ? AND ta.class_id = ? AND ta.subject_id = ?
                   AND ta.is_active = 1";
        $params = [$teacherId, $classId, $subjectId];
        $types  = 'iii';
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'class_name'   => (string)($row['class_name'] ?? ''),
            'subject_name' => (string)($row['subject_name'] ?? ''),
        ];
    }

    /**
     * Assessment counts for every assigned offering at once.
     *
     * One query for the whole set. Counting per assignment would make the
     * cost of opening a teacher grow with how many classes they teach,
     * which is exactly the N+1 the earlier phases removed.
     *
     * @param list<array<string,mixed>> $assignments
     * @return array<string,int> "classId:subjectId" => count
     */
    private static function assessmentCounts(
        \mysqli $conn,
        array $assignments,
        int $yearId,
        int $termId
    ): array {
        $pairs = [];
        foreach ($assignments as $a) {
            if ($a['subject_id'] === null) {
                continue;   // homeroom has no subject to count against
            }
            $pairs[$a['class_id'] . ':' . $a['subject_id']] = [(int)$a['class_id'], (int)$a['subject_id']];
        }
        if (!$pairs) {
            return [];
        }

        $clauses = [];
        $params  = [];
        $types   = '';
        foreach ($pairs as $p) {
            $clauses[] = '(class_id = ? AND subject_id = ?)';
            $params[]  = $p[0];
            $params[]  = $p[1];
            $types    .= 'ii';
        }
        $sql = "SELECT class_id, subject_id, COUNT(*) AS n
                  FROM assessments
                 WHERE (" . implode(' OR ', $clauses) . ')';
        if ($yearId > 0) {
            $sql .= ' AND academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }
        $sql .= ' GROUP BY class_id, subject_id';

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[$r['class_id'] . ':' . $r['subject_id']] = (int)$r['n'];
        }
        $stmt->close();
        return $out;
    }

    // ════════════════════════════════════════════════════════════
    // PHASE 4 — SUBJECT TRACKING
    //
    // Subject tracking answers "where does this subject actually
    // live, who is responsible for it, who studies it, and what
    // state is its work in" — an operational question, not an
    // academic one. There is no subject score, rank or
    // effectiveness figure here, by requirement.
    //
    // THE ONE STRUCTURAL FACT THAT SHAPES THIS WHOLE LAYER
    // ----------------------------------------------------
    // An offering — `class_subjects` — has NO academic_year_id,
    // and neither does `classes`. ReportCardService::fetchSubjects()
    // joins it scoped only by class_id. A (class x subject) pair is
    // therefore a standing arrangement that persists across years.
    //
    // So the academic year does NOT filter the offering. It scopes
    // the data hanging off it: which teachers are assigned this
    // year, who is enrolled this year, which assessments belong to
    // this year. Inventing a per-year "offered" flag would be
    // inventing a column the schema does not have, and "not
    // offered" means exactly one thing: the subject is in the
    // catalogue but no class_subjects row exists for it.
    //
    // RELATIONSHIPS ARE READ, NEVER INFERRED
    // --------------------------------------
    //   teacher  <- teacher_assignments (+ users.role='teacher')
    //   student  <- class_enrollments
    //   neither is ever derived from a mark, an assessment row or a
    //   submitted packet. The fixture contains an assessment and a
    //   packet on an offering with no teacher assigned at all; that
    //   offering must report "no teachers", not the packet's author.
    // ════════════════════════════════════════════════════════════

    /**
     * Identity + every class this subject is offered to.
     *
     * The per-offering counts are year-scoped and batched: three
     * grouped queries for the whole offering set, never one per
     * offering. A subject taught in twenty classes costs the same as
     * one taught in two.
     *
     * @return array<string,mixed>
     */
    public static function subjectDetail(
        \mysqli $conn,
        int $subjectId,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($subjectId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_subject', 'message' => 'Subject is required.'];
        }

        $subject = self::subjectIdentity($conn, $subjectId);
        if ($subject === null) {
            return ['status' => 'error', 'code' => 'unknown_subject', 'message' => 'That subject could not be found.'];
        }

        $offerings = self::subjectOfferings($conn, $subjectId);

        $classIds = [];
        foreach ($offerings as $o) {
            $classIds[] = (int)$o['class_id'];
        }
        $teacherCounts    = self::offeringTeacherCounts($conn, $subjectId, $classIds, $yearId);
        $studentCounts    = self::offeringStudentCounts($conn, $classIds, $yearId);
        $assessmentCounts = self::offeringAssessmentCounts($conn, $subjectId, $classIds, $yearId, $termId);

        foreach ($offerings as $i => $o) {
            $cid = (int)$o['class_id'];
            // Real COUNT(*) results. 0 here means the query genuinely
            // found nothing, not that nobody looked.
            $offerings[$i]['teacher_count']    = (int)($teacherCounts[$cid] ?? 0);
            $offerings[$i]['student_count']    = (int)($studentCounts[$cid] ?? 0);
            $offerings[$i]['assessment_count'] = (int)($assessmentCounts[$cid] ?? 0);
        }

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'subject', 'subject_id' => $subjectId],
            'context' => ['year_id' => $yearId, 'term_id' => $termId],
            'subject' => $subject,
            'offerings' => $offerings,
            'data_state' => [
                'offerings' => $offerings ? self::STATE_OK : self::STATE_NOT_OFFERED,
            ],
        ];
    }

    /**
     * One offering: who teaches it, and what state its mark lists are in.
     *
     * Students are deliberately NOT here. They are the only collection
     * that can be large, so they have their own paginated call and are
     * fetched when the user asks for them.
     *
     * @return array<string,mixed>
     */
    public static function subjectOffering(
        \mysqli $conn,
        int $subjectId,
        int $classId,
        int $yearId = 0,
        int $termId = 0
    ): array {
        $offering = self::findOffering($conn, $subjectId, $classId);
        if (is_string($offering)) {
            return self::offeringError($offering);
        }

        $teachers = self::offeringTeachers($conn, $subjectId, $classId, $yearId);
        $assessments = self::offeringAssessments($conn, $subjectId, $classId, $yearId, $termId);

        return [
            'status'  => 'success',
            'scope'   => [
                'type'       => 'subject',
                'subject_id' => $subjectId,
                'class_id'   => $classId,
            ],
            'context'  => ['year_id' => $yearId, 'term_id' => $termId],
            'subject'  => ['id' => $subjectId, 'subject_name' => $offering['subject_name']],
            'class'    => ['id' => $classId, 'class_name' => $offering['class_name']],
            'offering' => [
                'duration_type' => $offering['duration_type'],
                'term_id'       => $offering['term_id'],
            ],
            'teachers'    => $teachers,
            'assessments' => $assessments,
            'data_state'  => [
                'teachers'    => $teachers ? self::STATE_OK : self::STATE_NO_TEACHERS,
                'assessments' => $assessments ? self::STATE_OK : self::STATE_NO_ASSESSMENTS,
            ],
        ];
    }

    /**
     * The students taking one offering, through enrolment and nothing else.
     *
     * A student belongs to a subject because they are enrolled in a class
     * that offers it. They do NOT belong to it because a mark exists with
     * their name on it — a mark can survive a withdrawal, and a mark list
     * can contain a student who was moved. Enrolment is the relationship;
     * marks are a consequence of it.
     *
     * @return array<string,mixed>
     */
    public static function subjectStudents(
        \mysqli $conn,
        int $subjectId,
        int $classId,
        int $yearId = 0,
        int $page = 1,
        int $perPage = 25
    ): array {
        $offering = self::findOffering($conn, $subjectId, $classId);
        if (is_string($offering)) {
            return self::offeringError($offering);
        }

        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'ce.class_id = ? AND ce.status = ' . "'active'";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $where .= ' AND ce.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }

        $total = 0;
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM class_enrollments ce WHERE $where");
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        $rows = [];
        $sql = "SELECT m.id, m.member_code, m.student_name, m.father_name, m.gender, m.status
                  FROM class_enrollments ce
                  JOIN members m ON m.id = ce.member_id
                 WHERE $where
                 ORDER BY m.student_name, m.id
                 LIMIT ? OFFSET ?";
        $fp = $params;
        $ft = $types . 'ii';
        $fp[] = $perPage;
        $fp[] = $offset;
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($ft, ...$fp);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows[] = [
                    'member_id'    => (int)$r['id'],
                    'member_code'  => $r['member_code'] === null ? null : (string)$r['member_code'],
                    'student_name' => (string)($r['student_name'] ?? ''),
                    'father_name'  => (string)($r['father_name'] ?? ''),
                    'gender'       => (string)($r['gender'] ?? ''),
                    'status'       => (string)($r['status'] ?? ''),
                ];
            }
            $stmt->close();
        }

        return [
            'status'  => 'success',
            'scope'   => [
                'type'       => 'subject',
                'subject_id' => $subjectId,
                'class_id'   => $classId,
            ],
            'context'  => ['year_id' => $yearId],
            'students' => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 1,
            'data_state' => [
                // A page past the end is not an empty class. Only a total of
                // zero means nobody is enrolled.
                'students' => $total > 0 ? self::STATE_OK : self::STATE_NO_STUDENTS,
            ],
        ];
    }

    // ── Phase 4 internals ────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private static function subjectIdentity(\mysqli $conn, int $subjectId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT id, subject_name, subject_name_en, subject_code, is_active
               FROM subjects WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'id'              => (int)$row['id'],
            'subject_name'    => (string)($row['subject_name'] ?? ''),
            'subject_name_en' => $row['subject_name_en'] === null ? null : (string)$row['subject_name_en'],
            'subject_code'    => $row['subject_code'] === null ? null : (string)$row['subject_code'],
            'is_active'       => (int)($row['is_active'] ?? 0) === 1,
        ];
    }

    /**
     * Every class this subject is offered to.
     *
     * No academic-year condition, because the table has no such column.
     * The year scopes the counts, not the existence of the offering.
     *
     * @return list<array<string,mixed>>
     */
    private static function subjectOfferings(\mysqli $conn, int $subjectId): array
    {
        // Migration 056 may not be applied on this database. The offering
        // columns are then selected as literal NULL, which is exactly the
        // value 056 gives existing rows: unclassified, never a guessed
        // default. Detection happens before prepare() because mysqli throws
        // on an unknown column rather than returning false.
        $durCols = SubjectDurationPolicy::supportsOfferingDuration($conn)
            ? 'cs.duration_type, cs.term_id'
            : 'NULL AS duration_type, NULL AS term_id';
        $stmt = $conn->prepare(
            'SELECT cs.class_id, ' . $durCols . ',
                    c.class_name, c.class_name_en, c.is_active
               FROM class_subjects cs
               JOIN classes c ON c.id = cs.class_id
              WHERE cs.subject_id = ?
              ORDER BY c.level_order, c.class_name'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = [
                'class_id'      => (int)$r['class_id'],
                'class_name'    => (string)($r['class_name'] ?? ''),
                'class_name_en' => $r['class_name_en'] === null ? null : (string)$r['class_name_en'],
                'class_active'  => (int)($r['is_active'] ?? 0) === 1,
                // Migration 056. NULL is "unclassified", a real third value
                // that must not be coerced into a default duration.
                'duration_type' => $r['duration_type'] === null ? null : (string)$r['duration_type'],
                'term_id'       => self::positiveOrNull($r['term_id']),
                'teacher_count'    => null,
                'student_count'    => null,
                'assessment_count' => null,
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Validates that this class really offers this subject.
     *
     * Returns the offering row, or an error code string. Every scoped
     * Phase 4 call goes through here: without it, naming any class
     * alongside any subject would read that class's data under the
     * selected subject's name.
     *
     * @return array<string,mixed>|string
     */
    private static function findOffering(\mysqli $conn, int $subjectId, int $classId)
    {
        if ($subjectId <= 0) {
            return 'invalid_subject';
        }
        if ($classId <= 0) {
            return 'invalid_class';
        }
        // Migration 056 may not be applied on this database. The offering
        // columns are then selected as literal NULL, which is exactly the
        // value 056 gives existing rows: unclassified, never a guessed
        // default. Detection happens before prepare() because mysqli throws
        // on an unknown column rather than returning false.
        $durCols = SubjectDurationPolicy::supportsOfferingDuration($conn)
            ? 'cs.duration_type, cs.term_id'
            : 'NULL AS duration_type, NULL AS term_id';
        $stmt = $conn->prepare(
            'SELECT ' . $durCols . ', c.class_name, s.subject_name
               FROM class_subjects cs
               JOIN classes c  ON c.id = cs.class_id
               JOIN subjects s ON s.id = cs.subject_id
              WHERE cs.subject_id = ? AND cs.class_id = ?
              LIMIT 1'
        );
        if (!$stmt) {
            return 'unavailable';
        }
        $stmt->bind_param('ii', $subjectId, $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            // Distinguish "no such subject" from "that class does not
            // offer it": they are different mistakes with different fixes.
            return self::subjectIdentity($conn, $subjectId) === null
                ? 'unknown_subject'
                : 'not_offered_here';
        }
        return [
            'class_name'    => (string)($row['class_name'] ?? ''),
            'subject_name'  => (string)($row['subject_name'] ?? ''),
            'duration_type' => $row['duration_type'] === null ? null : (string)$row['duration_type'],
            'term_id'       => self::positiveOrNull($row['term_id']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function offeringError(string $code): array
    {
        $messages = [
            'invalid_subject'  => 'A subject must be selected.',
            'invalid_class'    => 'A class must be selected.',
            'unknown_subject'  => 'That subject could not be found.',
            'not_offered_here' => 'That class does not offer this subject.',
            'unavailable'      => 'Could not load this offering.',
        ];
        return [
            'status'  => 'error',
            'code'    => $code,
            'message' => $messages[$code] ?? $messages['unavailable'],
        ];
    }

    /**
     * Teachers assigned to one offering.
     *
     * Returns every assignment the model allows. The schema permits more
     * than one teacher on the same class and subject, so no row is
     * collapsed and none is silently preferred.
     *
     * Two NULLs in teacher_assignments carry meaning and are handled
     * explicitly here:
     *
     *   subject_id IS NULL      homeroom. It is an assignment to the
     *                           CLASS, not to any subject, so it must
     *                           never match one. `ta.subject_id = ?`
     *                           already excludes NULL in SQL, and the
     *                           equality is kept deliberately rather than
     *                           widened to anything NULL-tolerant.
     *
     *   academic_year_id IS NULL  a standing assignment, not tied to one
     *                           year. It is kept when a year is in scope
     *                           rather than silently discarded, it is
     *                           never rewritten to look like the selected
     *                           year, and it is reported as `is_standing`
     *                           so the distinction survives to the UI.
     *
     * `users.role = 'teacher'` is required as well: an assignment row can
     * outlive the role it was granted for, which is the inconsistency
     * Phase 3 found between its own two endpoints.
     *
     * @return list<array<string,mixed>>
     */
    private static function offeringTeachers(\mysqli $conn, int $subjectId, int $classId, int $yearId): array
    {
        $sql = "SELECT u.id, u.full_name, u.username, u.is_active,
                       ta.assignment_role, ta.is_primary, ta.academic_year_id,
                       m.member_code
                  FROM teacher_assignments ta
                  JOIN users u ON u.id = ta.teacher_id AND u.role = 'teacher'
             LEFT JOIN members m ON m.id = u.member_id
                 WHERE ta.class_id = ? AND ta.subject_id = ? AND ta.is_active = 1";
        $params = [$classId, $subjectId];
        $types = 'ii';
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY ta.is_primary DESC, u.full_name';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = [
                'teacher_id'      => (int)$r['id'],
                'full_name'       => (string)($r['full_name'] ?? ''),
                'username'        => (string)($r['username'] ?? ''),
                'member_code'     => $r['member_code'] === null ? null : (string)$r['member_code'],
                'is_active'       => (int)($r['is_active'] ?? 0) === 1,
                'assignment_role' => (string)($r['assignment_role'] ?? ''),
                'is_primary'      => (int)($r['is_primary'] ?? 0) === 1,
                // Surfaced, not hidden: a standing assignment is a real
                // and different thing from one granted for this year.
                'is_standing'     => $r['academic_year_id'] === null,
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Assessments of one offering, with their mark-list workflow state.
     *
     * No score is returned. An assessment's workflow status and anybody's
     * academic result are different facts.
     *
     * @return list<array<string,mixed>>
     */
    private static function offeringAssessments(
        \mysqli $conn,
        int $subjectId,
        int $classId,
        int $yearId,
        int $termId
    ): array {
        $sql = 'SELECT a.id, a.assessment_name, a.assessment_type, a.max_score,
                       a.weight_percentage, a.term_id
                  FROM assessments a
                 WHERE a.class_id = ? AND a.subject_id = ?';
        $params = [$classId, $subjectId];
        $types = 'ii';
        if ($yearId > 0) {
            $sql .= ' AND a.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND a.term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY a.term_id IS NULL, a.term_id, a.id';

        $rows = [];
        $ids = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $aid = (int)$r['id'];
                $ids[] = $aid;
                $rows[] = [
                    'assessment_id'   => $aid,
                    'assessment_name' => (string)$r['assessment_name'],
                    'assessment_type' => (string)($r['assessment_type'] ?? ''),
                    'max_score'       => $r['max_score'] === null ? null : (float)$r['max_score'],
                    'weight'          => $r['weight_percentage'] === null ? null : (float)$r['weight_percentage'],
                    'term_id'         => self::positiveOrNull($r['term_id']),
                    'workflow_status' => null,
                    'workflow_label'  => '',
                    'submission_id'   => null,
                ];
            }
            $stmt->close();
        }

        if ($ids) {
            // The Phase 3 helper: status and the packet it came from in one
            // pass, so the C2/H8 precedence rule keeps one implementation.
            $resolved = SubmissionService::resolvedMarklistRefs($conn, $ids);
            foreach ($rows as $i => $r) {
                $ref = $resolved[$r['assessment_id']] ?? ['status' => null, 'submission_id' => null];
                $st = $ref['status'];
                $rows[$i]['workflow_status'] = $st;
                // null is a real answer: no packet and no marks => never started.
                $rows[$i]['workflow_label'] = $st === null
                    ? 'Not started'
                    : SubmissionService::statusLabel($st);
                $rows[$i]['submission_id'] = $ref['submission_id'];
            }
        }
        return $rows;
    }

    /**
     * Teacher counts for every offering at once.
     *
     * @param list<int> $classIds
     * @return array<int,int>
     */
    private static function offeringTeacherCounts(
        \mysqli $conn,
        int $subjectId,
        array $classIds,
        int $yearId
    ): array {
        if (!$classIds) {
            return [];
        }
        $place = implode(',', array_fill(0, count($classIds), '?'));
        $sql = "SELECT ta.class_id, COUNT(DISTINCT ta.teacher_id) AS n
                  FROM teacher_assignments ta
                  JOIN users u ON u.id = ta.teacher_id AND u.role = 'teacher'
                 WHERE ta.class_id IN ($place) AND ta.subject_id = ? AND ta.is_active = 1";
        $params = $classIds;
        $types = str_repeat('i', count($classIds));
        $params[] = $subjectId;
        $types .= 'i';
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' GROUP BY ta.class_id';
        return self::countMap($conn, $sql, $types, $params, 'class_id');
    }

    /**
     * @param list<int> $classIds
     * @return array<int,int>
     */
    private static function offeringStudentCounts(\mysqli $conn, array $classIds, int $yearId): array
    {
        if (!$classIds) {
            return [];
        }
        $place = implode(',', array_fill(0, count($classIds), '?'));
        $sql = "SELECT ce.class_id, COUNT(*) AS n
                  FROM class_enrollments ce
                 WHERE ce.class_id IN ($place) AND ce.status = 'active'";
        $params = $classIds;
        $types = str_repeat('i', count($classIds));
        if ($yearId > 0) {
            $sql .= ' AND ce.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' GROUP BY ce.class_id';
        return self::countMap($conn, $sql, $types, $params, 'class_id');
    }

    /**
     * @param list<int> $classIds
     * @return array<int,int>
     */
    private static function offeringAssessmentCounts(
        \mysqli $conn,
        int $subjectId,
        array $classIds,
        int $yearId,
        int $termId
    ): array {
        if (!$classIds) {
            return [];
        }
        $place = implode(',', array_fill(0, count($classIds), '?'));
        $sql = "SELECT class_id, COUNT(*) AS n
                  FROM assessments
                 WHERE class_id IN ($place) AND subject_id = ?";
        $params = $classIds;
        $types = str_repeat('i', count($classIds));
        $params[] = $subjectId;
        $types .= 'i';
        if ($yearId > 0) {
            $sql .= ' AND academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }
        $sql .= ' GROUP BY class_id';
        return self::countMap($conn, $sql, $types, $params, 'class_id');
    }

    /**
     * @param list<mixed> $params
     * @return array<int,int>
     */
    private static function countMap(
        \mysqli $conn,
        string $sql,
        string $types,
        array $params,
        string $keyCol
    ): array {
        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[(int)$r[$keyCol]] = (int)$r['n'];
        }
        $stmt->close();
        return $out;
    }

    // ════════════════════════════════════════════════════════════
    // PHASE 5 — CLASS TRACKING
    //
    // Classes -> select class -> context -> students / subjects /
    // teachers / assessments -> submission status -> the EXISTING
    // workflow action.
    //
    // THE STRUCTURAL FACT, SAME SHAPE AS PHASE 4
    // ------------------------------------------
    // `classes` has NO academic_year_id, and neither does
    // `class_subjects`. A class is a standing entity that persists
    // across years, so the academic year scopes the data hanging off
    // it -- who is enrolled, who is assigned, what was assessed --
    // and never the class itself. A class does not disappear from a
    // year merely because nothing has happened in it yet.
    //
    // FOUR FACTS KEPT APART
    // ---------------------
    //   an assessment exists      -> a row in `assessments`
    //   a submission exists       -> submission_id, or null
    //   the submission's status   -> SubmissionService
    //   a result/mark exists      -> rows in `academic_records`
    // These are four different questions. A planned assessment with
    // no packet and no marks is not "0%"; it is "not started", "no
    // packet" and "no marks" simultaneously, and each is reported.
    //
    // RELATIONSHIPS ARE READ, NEVER INFERRED
    // --------------------------------------
    //   students <- class_enrollments
    //   subjects <- class_subjects
    //   teachers <- teacher_assignments (+ users.role='teacher')
    //   assessments <- assessments.class_id
    // None of them is ever derived from a mark, a packet or an
    // assessment row belonging to something else.
    // ════════════════════════════════════════════════════════════

    /**
     * Class identity, reporting context, and a lightweight summary.
     *
     * Deliberately small: four scalar counts and the homeroom teachers.
     * Nothing that belongs to a tab is loaded here, so opening a class
     * never pulls its roll, its mark lists or anybody's report card.
     *
     * @return array<string,mixed>
     */
    public static function classDetail(
        \mysqli $conn,
        int $classId,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($classId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_class', 'message' => 'A class must be selected.'];
        }
        $class = self::classIdentity($conn, $classId);
        if ($class === null) {
            return ['status' => 'error', 'code' => 'unknown_class', 'message' => 'That class could not be found.'];
        }

        $summary = [
            'students'    => self::classStudentCount($conn, $classId, $yearId),
            'subjects'    => self::classSubjectCount($conn, $classId),
            'teachers'    => self::classTeacherCount($conn, $classId, $yearId),
            'assessments' => self::classAssessmentCount($conn, $classId, $yearId, $termId),
        ];

        // Homeroom is a class-level fact, so it belongs in the class
        // header rather than in a subject list it has no place in.
        $homeroom = self::classHomeroomTeachers($conn, $classId, $yearId);

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'class', 'class_id' => $classId],
            'context' => ['year_id' => $yearId, 'term_id' => $termId],
            'class'   => $class,
            'summary' => $summary,
            'homeroom_teachers' => $homeroom,
            'data_state' => [
                'students'    => $summary['students'] > 0 ? self::STATE_OK : self::STATE_NO_STUDENTS,
                'subjects'    => $summary['subjects'] > 0 ? self::STATE_OK : self::STATE_NO_SUBJECTS_OFFERED,
                'teachers'    => $summary['teachers'] > 0 ? self::STATE_OK : self::STATE_NO_TEACHER_ASSIGNMENTS,
                'assessments' => $summary['assessments'] > 0 ? self::STATE_OK : self::STATE_NO_ASSESSMENTS,
                'homeroom'    => $homeroom ? self::STATE_OK : self::STATE_NO_TEACHER_ASSIGNMENTS,
            ],
        ];
    }

    /**
     * The class roll, through enrolment and nothing else.
     *
     * A student belongs to a class because `class_enrollments` says so.
     * Marks, attendance rows and mark lists bearing their name are
     * consequences of membership, never evidence of it: a mark survives
     * a withdrawal, and a mark list can still name somebody who moved.
     *
     * @return array<string,mixed>
     */
    public static function classStudents(
        \mysqli $conn,
        int $classId,
        int $yearId = 0,
        string $q = '',
        int $page = 1,
        int $perPage = 25
    ): array {
        $class = self::classIdentity($conn, $classId);
        if ($classId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_class', 'message' => 'A class must be selected.'];
        }
        if ($class === null) {
            return ['status' => 'error', 'code' => 'unknown_class', 'message' => 'That class could not be found.'];
        }

        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $q = trim($q);

        $where = "ce.class_id = ? AND ce.status = 'active'";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $where .= ' AND ce.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($q !== '') {
            $where .= ' AND (m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
            $types .= 'sss';
        }

        $total = 0;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM class_enrollments ce
               JOIN members m ON m.id = ce.member_id
              WHERE $where"
        );
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        $rows = [];
        $fp = $params;
        $ft = $types . 'ii';
        $fp[] = $perPage;
        $fp[] = ($page - 1) * $perPage;
        $stmt = $conn->prepare(
            "SELECT m.id, m.member_code, m.student_name, m.father_name, m.gender, m.status
               FROM class_enrollments ce
               JOIN members m ON m.id = ce.member_id
              WHERE $where
              ORDER BY m.student_name, m.id
              LIMIT ? OFFSET ?"
        );
        if ($stmt) {
            $stmt->bind_param($ft, ...$fp);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows[] = [
                    'member_id'    => (int)$r['id'],
                    'member_code'  => $r['member_code'] === null ? null : (string)$r['member_code'],
                    'student_name' => (string)($r['student_name'] ?? ''),
                    'father_name'  => (string)($r['father_name'] ?? ''),
                    'gender'       => (string)($r['gender'] ?? ''),
                    'status'       => (string)($r['status'] ?? ''),
                ];
            }
            $stmt->close();
        }

        // "Nobody is enrolled" and "your search matched nobody" are
        // different answers and the caller must be able to tell them apart.
        $state = self::STATE_OK;
        if ($total === 0) {
            $state = $q !== '' ? self::STATE_FILTERED_EMPTY : self::STATE_NO_STUDENTS;
        }

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'class', 'class_id' => $classId],
            'context' => ['year_id' => $yearId],
            'filters' => ['q' => $q],
            'students' => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 1,
            'data_state' => ['students' => $state],
        ];
    }

    /**
     * The subjects this class offers, with the state of each.
     *
     * An offering exists because `class_subjects` says so. An assessment
     * existing for a subject does NOT make that subject an offering of
     * this class, and a subject with no assessments is still an offering.
     *
     * @return array<string,mixed>
     */
    public static function classSubjects(
        \mysqli $conn,
        int $classId,
        int $yearId = 0,
        int $termId = 0
    ): array {
        if ($classId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_class', 'message' => 'A class must be selected.'];
        }
        if (self::classIdentity($conn, $classId) === null) {
            return ['status' => 'error', 'code' => 'unknown_class', 'message' => 'That class could not be found.'];
        }

        $rows = [];
        $subjectIds = [];
        // Migration 056 may not be applied on this database. The offering
        // columns are then selected as literal NULL, which is exactly the
        // value 056 gives existing rows: unclassified, never a guessed
        // default. Detection happens before prepare() because mysqli throws
        // on an unknown column rather than returning false.
        $durCols = SubjectDurationPolicy::supportsOfferingDuration($conn)
            ? 'cs.duration_type, cs.term_id'
            : 'NULL AS duration_type, NULL AS term_id';
        $stmt = $conn->prepare(
            'SELECT cs.subject_id, ' . $durCols . ',
                    s.subject_name, s.subject_name_en, s.is_active
               FROM class_subjects cs
               JOIN subjects s ON s.id = cs.subject_id
              WHERE cs.class_id = ?
              ORDER BY s.subject_name'
        );
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $sid = (int)$r['subject_id'];
                $subjectIds[] = $sid;
                $rows[] = [
                    'subject_id'      => $sid,
                    'subject_name'    => (string)($r['subject_name'] ?? ''),
                    'subject_name_en' => $r['subject_name_en'] === null ? null : (string)$r['subject_name_en'],
                    'subject_active'  => (int)($r['is_active'] ?? 0) === 1,
                    // Migration 056's third value. NULL is "unclassified",
                    // not a missing full-year.
                    'duration_type'   => $r['duration_type'] === null ? null : (string)$r['duration_type'],
                    'term_id'         => self::positiveOrNull($r['term_id']),
                    'teachers'         => [],
                    'assessment_count' => 0,
                    'has_results'      => false,
                ];
            }
            $stmt->close();
        }

        if ($subjectIds) {
            // Three batched lookups for the whole offering set, never one
            // per offering.
            $teachers    = self::classTeachersBySubject($conn, $classId, $subjectIds, $yearId);
            $assessments = self::classAssessmentsBySubject($conn, $classId, $subjectIds, $yearId, $termId);
            $resultIds   = [];
            foreach ($assessments as $sid => $info) {
                foreach ($info['ids'] as $aid) {
                    $resultIds[] = $aid;
                }
            }
            $hasRows = $resultIds
                ? SubmissionService::marklistsHaveRows($conn, $resultIds)
                : [];

            foreach ($rows as $i => $r) {
                $sid = $r['subject_id'];
                $rows[$i]['teachers'] = $teachers[$sid] ?? [];
                $rows[$i]['assessment_count'] = isset($assessments[$sid])
                    ? count($assessments[$sid]['ids']) : 0;
                // Whether any mark has been recorded yet. Existence only —
                // no score is read and nothing is averaged.
                $any = false;
                foreach (($assessments[$sid]['ids'] ?? []) as $aid) {
                    if (!empty($hasRows[$aid])) {
                        $any = true;
                        break;
                    }
                }
                $rows[$i]['has_results'] = $any;
            }
        }

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'class', 'class_id' => $classId],
            'context' => ['year_id' => $yearId, 'term_id' => $termId],
            'subjects' => $rows,
            'data_state' => [
                'subjects' => $rows ? self::STATE_OK : self::STATE_NO_SUBJECTS_OFFERED,
            ],
        ];
    }

    /**
     * Every teacher assigned to this class, in any capacity.
     *
     * Homeroom and subject assignments are both returned and are clearly
     * separated: `is_homeroom` is true exactly when subject_id IS NULL,
     * which per migration 006 is an assignment to the CLASS. A homeroom
     * teacher is never presented as the teacher of a subject, and a
     * subject teacher is never presented as homeroom.
     *
     * @return array<string,mixed>
     */
    public static function classTeachers(
        \mysqli $conn,
        int $classId,
        int $yearId = 0
    ): array {
        if ($classId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_class', 'message' => 'A class must be selected.'];
        }
        if (self::classIdentity($conn, $classId) === null) {
            return ['status' => 'error', 'code' => 'unknown_class', 'message' => 'That class could not be found.'];
        }

        $sql = "SELECT u.id, u.full_name, u.username, u.is_active,
                       ta.subject_id, ta.assignment_role, ta.is_primary,
                       ta.is_class_teacher, ta.academic_year_id,
                       s.subject_name, m.member_code
                  FROM teacher_assignments ta
                  JOIN users u ON u.id = ta.teacher_id AND u.role = 'teacher'
             LEFT JOIN subjects s ON s.id = ta.subject_id
             LEFT JOIN members m ON m.id = u.member_id
                 WHERE ta.class_id = ? AND ta.is_active = 1";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            // A standing assignment (academic_year_id IS NULL) is kept, not
            // discarded, and is never rewritten to look like this year.
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY ta.subject_id IS NULL DESC, s.subject_name, u.full_name';

        $rows = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $subjectId = self::positiveOrNull($r['subject_id']);
                $rows[] = [
                    'teacher_id'      => (int)$r['id'],
                    'full_name'       => (string)($r['full_name'] ?? ''),
                    'username'        => (string)($r['username'] ?? ''),
                    'member_code'     => $r['member_code'] === null ? null : (string)$r['member_code'],
                    'is_active'       => (int)($r['is_active'] ?? 0) === 1,
                    'subject_id'      => $subjectId,
                    'subject_name'    => $subjectId === null ? null : (string)($r['subject_name'] ?? ''),
                    // subject_id IS NULL is the homeroom marker, per
                    // migration 006. assignment_role is reported too but is
                    // not what decides this.
                    'is_homeroom'     => $subjectId === null,
                    'assignment_role' => (string)($r['assignment_role'] ?? ''),
                    'is_primary'      => (int)($r['is_primary'] ?? 0) === 1,
                    'is_class_teacher' => (int)($r['is_class_teacher'] ?? 0) === 1,
                    'is_standing'     => $r['academic_year_id'] === null,
                ];
            }
            $stmt->close();
        }

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'class', 'class_id' => $classId],
            'context' => ['year_id' => $yearId],
            'teachers' => $rows,
            'data_state' => [
                'teachers' => $rows ? self::STATE_OK : self::STATE_NO_TEACHER_ASSIGNMENTS,
            ],
        ];
    }

    /**
     * The class's assessments, with all four facts kept apart.
     *
     * Scoped by assessments.class_id — never by a matching subject_id or
     * teacher_id, which would pull in another class's work.
     *
     * @return array<string,mixed>
     */
    public static function classAssessments(
        \mysqli $conn,
        int $classId,
        int $yearId = 0,
        int $termId = 0,
        int $subjectId = 0
    ): array {
        if ($classId <= 0) {
            return ['status' => 'error', 'code' => 'invalid_class', 'message' => 'A class must be selected.'];
        }
        if (self::classIdentity($conn, $classId) === null) {
            return ['status' => 'error', 'code' => 'unknown_class', 'message' => 'That class could not be found.'];
        }
        // A subject filter must still be an offering of THIS class.
        if ($subjectId > 0 && !self::classOffersSubject($conn, $classId, $subjectId)) {
            return [
                'status'  => 'error',
                'code'    => 'not_offered_here',
                'message' => 'That class does not offer this subject.',
            ];
        }

        $sql = 'SELECT a.id, a.assessment_name, a.assessment_type, a.max_score,
                       a.weight_percentage, a.term_id, a.subject_id,
                       s.subject_name
                  FROM assessments a
             LEFT JOIN subjects s ON s.id = a.subject_id
                 WHERE a.class_id = ?';
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= ' AND a.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND a.term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }
        if ($subjectId > 0) {
            $sql .= ' AND a.subject_id = ?';
            $params[] = $subjectId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY s.subject_name, a.term_id IS NULL, a.term_id, a.id';

        $rows = [];
        $ids = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $aid = (int)$r['id'];
                $ids[] = $aid;
                $rows[] = [
                    'assessment_id'   => $aid,
                    'assessment_name' => (string)$r['assessment_name'],
                    'assessment_type' => (string)($r['assessment_type'] ?? ''),
                    'subject_id'      => self::positiveOrNull($r['subject_id']),
                    'subject_name'    => $r['subject_name'] === null ? null : (string)$r['subject_name'],
                    'max_score'       => $r['max_score'] === null ? null : (float)$r['max_score'],
                    'weight'          => $r['weight_percentage'] === null ? null : (float)$r['weight_percentage'],
                    'term_id'         => self::positiveOrNull($r['term_id']),
                    'workflow_status' => null,
                    'workflow_label'  => '',
                    'submission_id'   => null,
                    'has_results'     => false,
                ];
            }
            $stmt->close();
        }

        if ($ids) {
            // Two batched lookups. The workflow state and the packet come
            // from the Phase 3 helper so the precedence rule keeps one
            // implementation; mark existence is its own separate fact.
            $resolved = SubmissionService::resolvedMarklistRefs($conn, $ids);
            $hasRows  = SubmissionService::marklistsHaveRows($conn, $ids);
            foreach ($rows as $i => $r) {
                $aid = $r['assessment_id'];
                $ref = $resolved[$aid] ?? ['status' => null, 'submission_id' => null];
                $st = $ref['status'];
                $rows[$i]['workflow_status'] = $st;
                // null is a real answer: no packet and no marks => never started.
                $rows[$i]['workflow_label'] = $st === null
                    ? 'Not started'
                    : SubmissionService::statusLabel($st);
                $rows[$i]['submission_id'] = $ref['submission_id'];
                $rows[$i]['has_results']   = !empty($hasRows[$aid]);
            }
        }

        return [
            'status'  => 'success',
            'scope'   => ['type' => 'class', 'class_id' => $classId]
                + ($subjectId > 0 ? ['subject_id' => $subjectId] : []),
            'context' => ['year_id' => $yearId, 'term_id' => $termId],
            'assessments' => $rows,
            'data_state' => [
                'assessments' => $rows ? self::STATE_OK : self::STATE_NO_ASSESSMENTS,
            ],
        ];
    }

    // ── Phase 5 internals ────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private static function classIdentity(\mysqli $conn, int $classId): ?array
    {
        if ($classId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            'SELECT id, class_name, class_name_en, class_code, section,
                    level_order, is_active
               FROM classes WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'id'            => (int)$row['id'],
            'class_name'    => (string)($row['class_name'] ?? ''),
            'class_name_en' => $row['class_name_en'] === null ? null : (string)$row['class_name_en'],
            'class_code'    => $row['class_code'] === null ? null : (string)$row['class_code'],
            'section'       => $row['section'] === null ? null : (string)$row['section'],
            'level_order'   => (int)($row['level_order'] ?? 0),
            'is_active'     => (int)($row['is_active'] ?? 0) === 1,
        ];
    }

    /** Is this subject actually an offering of this class? */
    private static function classOffersSubject(\mysqli $conn, int $classId, int $subjectId): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $classId, $subjectId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    private static function classStudentCount(\mysqli $conn, int $classId, int $yearId): int
    {
        $sql = "SELECT COUNT(*) AS n FROM class_enrollments ce
                 WHERE ce.class_id = ? AND ce.status = 'active'";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= ' AND ce.academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        return self::scalarCount($conn, $sql, $types, $params);
    }

    private static function classSubjectCount(\mysqli $conn, int $classId): int
    {
        return self::scalarCount(
            $conn,
            'SELECT COUNT(*) AS n FROM class_subjects WHERE class_id = ?',
            'i',
            [$classId]
        );
    }

    private static function classTeacherCount(\mysqli $conn, int $classId, int $yearId): int
    {
        $sql = "SELECT COUNT(DISTINCT ta.teacher_id) AS n
                  FROM teacher_assignments ta
                  JOIN users u ON u.id = ta.teacher_id AND u.role = 'teacher'
                 WHERE ta.class_id = ? AND ta.is_active = 1";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        return self::scalarCount($conn, $sql, $types, $params);
    }

    private static function classAssessmentCount(
        \mysqli $conn,
        int $classId,
        int $yearId,
        int $termId
    ): int {
        $sql = 'SELECT COUNT(*) AS n FROM assessments WHERE class_id = ?';
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= ' AND academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }
        return self::scalarCount($conn, $sql, $types, $params);
    }

    /**
     * Homeroom teachers only — subject_id IS NULL.
     *
     * @return list<array<string,mixed>>
     */
    private static function classHomeroomTeachers(\mysqli $conn, int $classId, int $yearId): array
    {
        $sql = "SELECT u.id, u.full_name, ta.academic_year_id
                  FROM teacher_assignments ta
                  JOIN users u ON u.id = ta.teacher_id AND u.role = 'teacher'
                 WHERE ta.class_id = ? AND ta.subject_id IS NULL AND ta.is_active = 1";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY u.full_name';

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[] = [
                'teacher_id'  => (int)$r['id'],
                'full_name'   => (string)($r['full_name'] ?? ''),
                'is_standing' => $r['academic_year_id'] === null,
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Subject teachers for a set of offerings, in one query.
     *
     * subject_id IS NULL rows are excluded by the equality: a homeroom
     * assignment covers the class, not any subject in it.
     *
     * @param list<int> $subjectIds
     * @return array<int,list<array<string,mixed>>>
     */
    private static function classTeachersBySubject(
        \mysqli $conn,
        int $classId,
        array $subjectIds,
        int $yearId
    ): array {
        if (!$subjectIds) {
            return [];
        }
        $place = implode(',', array_fill(0, count($subjectIds), '?'));
        $sql = "SELECT ta.subject_id, u.id, u.full_name, ta.is_primary,
                       ta.academic_year_id
                  FROM teacher_assignments ta
                  JOIN users u ON u.id = ta.teacher_id AND u.role = 'teacher'
                 WHERE ta.class_id = ? AND ta.subject_id IN ($place)
                   AND ta.is_active = 1";
        $params = array_merge([$classId], $subjectIds);
        $types = 'i' . str_repeat('i', count($subjectIds));
        if ($yearId > 0) {
            $sql .= ' AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL)';
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= ' ORDER BY ta.is_primary DESC, u.full_name';

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[(int)$r['subject_id']][] = [
                'teacher_id'  => (int)$r['id'],
                'full_name'   => (string)($r['full_name'] ?? ''),
                'is_primary'  => (int)($r['is_primary'] ?? 0) === 1,
                'is_standing' => $r['academic_year_id'] === null,
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Assessment ids per subject for this class, in one query.
     *
     * @param list<int> $subjectIds
     * @return array<int,array{ids:list<int>}>
     */
    private static function classAssessmentsBySubject(
        \mysqli $conn,
        int $classId,
        array $subjectIds,
        int $yearId,
        int $termId
    ): array {
        if (!$subjectIds) {
            return [];
        }
        $place = implode(',', array_fill(0, count($subjectIds), '?'));
        $sql = "SELECT id, subject_id FROM assessments
                 WHERE class_id = ? AND subject_id IN ($place)";
        $params = array_merge([$classId], $subjectIds);
        $types = 'i' . str_repeat('i', count($subjectIds));
        if ($yearId > 0) {
            $sql .= ' AND academic_year_id = ?';
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= ' AND term_id = ?';
            $params[] = $termId;
            $types .= 'i';
        }

        $out = [];
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[(int)$r['subject_id']]['ids'][] = (int)$r['id'];
        }
        $stmt->close();
        return $out;
    }

    /**
     * @param list<mixed> $params
     */
    private static function scalarCount(
        \mysqli $conn,
        string $sql,
        string $types,
        array $params
    ): int {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }
}
