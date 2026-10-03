<?php
/**
 * Report cards — one place for totals, averages, rank, and attendance.
 *
 * Used by Education and the teacher portal so both screens show the
 * same numbers. Safe for large schools: work is per class, not the
 * whole membership table. Student PII is limited to name, Christian
 * name, father, code, class, and gender.
 */

namespace App\Services;

require_once __DIR__ . '/EnrollmentService.php';
require_once __DIR__ . '/SubjectDurationPolicy.php';

class ReportCardService
{
    public const PASS_MARK = 50.0;
    public const MAX_CLASS_SIZE = 200;

    /** @var list<array{letter:string,min:float,max:float,label:string}> */
    public const GRADE_SCALE = [
        ['letter' => 'A', 'min' => 90.0, 'max' => 100.0, 'label' => 'Excellent'],
        ['letter' => 'B', 'min' => 80.0, 'max' => 89.9, 'label' => 'Very good'],
        ['letter' => 'C', 'min' => 70.0, 'max' => 79.9, 'label' => 'Good'],
        ['letter' => 'D', 'min' => 60.0, 'max' => 69.9, 'label' => 'Pass'],
        ['letter' => 'F', 'min' => 0.0, 'max' => 59.9, 'label' => 'Needs work'],
    ];

    public static function letter(float $pct): string
    {
        if ($pct >= 90) {
            return 'A';
        }
        if ($pct >= 80) {
            return 'B';
        }
        if ($pct >= 70) {
            return 'C';
        }
        if ($pct >= 60) {
            return 'D';
        }
        return 'F';
    }

    public static function canViewClass(\mysqli $conn, int $userId, string $role, int $classId): bool
    {
        if (in_array($role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
            return true;
        }
        if ($role !== 'teacher' || $userId <= 0 || $classId <= 0) {
            return false;
        }
        $sqls = [
            "SELECT 1 FROM teacher_assignments
             WHERE teacher_id = ? AND class_id = ?
               AND (is_active = 1 OR status = 'active' OR is_active IS NULL)
             LIMIT 1",
            "SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? LIMIT 1",
        ];
        foreach ($sqls as $sql) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('ii', $userId, $classId);
            $stmt->execute();
            $ok = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();
            return $ok;
        }
        return false;
    }

    /**
     * @return array{status:string,message?:string}|array<string,mixed>
     */
    public static function getCard(\mysqli $conn, int $memberId, int $classId, int $yearId = 0, int $termId = 0): array
    {
        if ($memberId <= 0) {
            return ['status' => 'error', 'message' => 'Student is required.'];
        }
        if ($classId <= 0) {
            $stmt = $conn->prepare(
                "SELECT class_id FROM class_enrollments
                 WHERE member_id = ? AND status = 'active'
                 ORDER BY id DESC LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $memberId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $classId = (int)($row['class_id'] ?? 0);
            }
        }
        if ($classId <= 0) {
            return ['status' => 'error', 'message' => 'This student is not in a class.'];
        }
        $pack = self::buildRankedClass($conn, $classId, $yearId, $termId);
        if (($pack['status'] ?? '') !== 'success') {
            return $pack;
        }
        return self::cardFromPack($conn, $pack, $memberId);
    }

    /**
     * @return array{status:string,message?:string}|array<string,mixed>
     */
    public static function getClassReport(\mysqli $conn, int $classId, int $subjectId = 0, int $yearId = 0, int $termId = 0): array
    {
        if ($classId <= 0) {
            return ['status' => 'error', 'message' => 'Class is required.'];
        }
        if ($subjectId > 0) {
            $pack = self::buildRankedClass($conn, $classId, $yearId, $termId, $subjectId);
            if (($pack['status'] ?? '') === 'success') {
                unset($pack['_bundle'], $pack['_computed']);
            }
            return $pack;
        }
        $pack = self::buildRankedClass($conn, $classId, $yearId, $termId);
        if (($pack['status'] ?? '') !== 'success') {
            return $pack;
        }
        unset($pack['_bundle'], $pack['_computed']);
        return $pack;
    }

    /**
     * Shared class pack used by the list and by each card so rank and
     * averages stay identical.
     *
     * @return array<string,mixed>
     */
    private static function buildRankedClass(\mysqli $conn, int $classId, int $yearId, int $termId, int $subjectId = 0): array
    {
        $bundle = self::buildClassBundle($conn, $classId, $yearId, $termId);
        if (!$bundle) {
            return ['status' => 'error', 'message' => 'Class not found.'];
        }

        $students = [];
        $computedByMember = [];
        foreach ($bundle['roster'] as $memberId => $member) {
            $computed = self::computeStudent(
                $memberId,
                $bundle['subjects'],
                $bundle['scores'][$memberId] ?? [],
                $bundle['attendance'][$memberId] ?? self::emptyAttendance(),
                $subjectId,
                $bundle['assessments'] ?? [],
                [
                    'is_annual' => (bool)($bundle['is_annual'] ?? false),
                    'report_term_number' => (int)($bundle['report_term_number'] ?? 0),
                    'weights' => $bundle['weights'] ?? ['s1' => 50.0, 's2' => 50.0],
                    'term_numbers' => $bundle['term_numbers'] ?? [],
                ]
            );
            $computedByMember[$memberId] = $computed;
            $avg = $computed['totals']['average'];
            $students[] = [
                'id' => $memberId,
                'student_name' => (string)($member['student_name'] ?? ''),
                'father_name' => (string)($member['father_name'] ?? ''),
                'christian_name' => (string)($member['baptismal_name'] ?? ''),
                'member_code' => (string)($member['member_code'] ?? ''),
                'gender' => (string)($member['gender'] ?? ''),
                'overall_average' => $avg,
                'avg_percentage' => $avg,
                'overall_grade' => $computed['totals']['grade_letter'],
                'grade_letter' => $computed['totals']['grade_letter'],
                'total_obtained' => $computed['totals']['obtained'],
                'total_max' => $computed['totals']['max'],
                'subjects_count' => $computed['totals']['subjects_count'],
                'assessments_count' => $computed['totals']['assessments_count'],
                'attendance_rate' => $computed['attendance']['rate'],
                'present_days' => $computed['attendance']['present'],
                'absent_days' => $computed['attendance']['absent'],
                'late_days' => $computed['attendance']['late'],
                'excused_days' => $computed['attendance']['excused'] ?? 0,
                'total_days' => $computed['attendance']['total'],
                'has_attendance' => ((int)($computed['attendance']['total'] ?? 0) > 0),
                'strongest_subject' => self::highlightName($computed['highlights'], 'strongest'),
                'weakest_subject' => self::highlightName($computed['highlights'], 'weakest'),
                'subjects' => self::slimSubjects($computed['subjects']),
                'rank' => null,
                'tied' => false,
            ];
        }

        usort($students, static function ($a, $b) {
            $aa = $a['overall_average'];
            $bb = $b['overall_average'];
            if ($aa === null && $bb === null) {
                return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
            }
            if ($aa === null) {
                return 1;
            }
            if ($bb === null) {
                return -1;
            }
            if ($aa === $bb) {
                return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
            }
            return ($aa < $bb) ? 1 : -1;
        });

        $pos = 0;
        $prevAvg = null;
        $prevRank = 0;
        foreach ($students as &$row) {
            $pos++;
            if ($row['overall_average'] === null) {
                $row['rank'] = null;
                continue;
            }
            if ($prevAvg !== null && abs($row['overall_average'] - $prevAvg) < 0.05) {
                $row['rank'] = $prevRank;
                $row['tied'] = true;
            } else {
                $row['rank'] = $pos;
                $prevRank = $pos;
                $prevAvg = $row['overall_average'];
            }
        }
        unset($row);

        // Mark the earlier twin of a tie as well.
        $rankCounts = [];
        foreach ($students as $row) {
            if ($row['rank'] === null) {
                continue;
            }
            $rankCounts[$row['rank']] = ($rankCounts[$row['rank']] ?? 0) + 1;
        }
        foreach ($students as &$row) {
            if ($row['rank'] !== null && ($rankCounts[$row['rank']] ?? 0) > 1) {
                $row['tied'] = true;
            }
        }
        unset($row);

        $pcts = [];
        foreach ($students as $row) {
            if ($row['overall_average'] !== null) {
                $pcts[] = (float)$row['overall_average'];
            }
        }
        sort($pcts);
        $graded = count($pcts);
        $median = null;
        if ($graded > 0) {
            $mid = (int)floor(($graded - 1) / 2);
            $median = ($graded % 2 === 1)
                ? round($pcts[$mid], 1)
                : round(($pcts[$mid] + $pcts[$mid + 1]) / 2, 1);
        }

        $subjectStats = [];
        foreach ($bundle['subjects'] as $subj) {
            if ($subjectId > 0 && (int)$subj['id'] !== $subjectId) {
                continue;
            }
            $sid = (int)$subj['id'];
            $vals = self::subjectAveragesForClass($bundle['scores'], $sid);
            $scoredIds = self::scoredAssessmentIds($bundle['scores'], $sid);
            $subjectStats[] = [
                'id' => $sid,
                'subject_name' => $subj['subject_name'],
                'subject_name_en' => $subj['subject_name_en'] ?? '',
                'average' => $vals['average'],
                'graded' => $vals['graded'],
                'completion' => self::subjectCompletion($bundle['assessments'][$sid] ?? [], $scoredIds),
            ];
        }

        $recSum = 0.0;
        $recN = 0;
        $leftSubjects = 0;
        foreach ($subjectStats as $ss) {
            $c = $ss['completion'] ?? null;
            if (!is_array($c)) {
                continue;
            }
            $recSum += (float)($c['recorded'] ?? 0);
            $recN++;
            if ((float)($c['remaining'] ?? 0) > 0) {
                $leftSubjects++;
            }
        }
        $semRec = $recN > 0 ? round($recSum / $recN, 1) : 0.0;

        $stats = [
            'total_students' => count($students),
            'graded_students' => $graded,
            'class_average' => $graded > 0 ? round(array_sum($pcts) / $graded, 1) : null,
            'highest' => $graded > 0 ? round(max($pcts), 1) : null,
            'lowest' => $graded > 0 ? round(min($pcts), 1) : null,
            'median' => $median,
            'semester' => [
                'recorded' => $semRec,
                'remaining' => max(0.0, round(100.0 - $semRec, 1)),
                'subjects_left' => $leftSubjects,
                'subjects_count' => $recN,
            ],
            'pass_rate' => $graded > 0
                ? round(count(array_filter($pcts, static function ($v) {
                    return $v >= self::PASS_MARK;
                })) / $graded * 100, 1)
                : null,
            'grade_distribution' => [
                'A' => count(array_filter($pcts, static fn($v) => $v >= 90)),
                'B' => count(array_filter($pcts, static fn($v) => $v >= 80 && $v < 90)),
                'C' => count(array_filter($pcts, static fn($v) => $v >= 70 && $v < 80)),
                'D' => count(array_filter($pcts, static fn($v) => $v >= 60 && $v < 70)),
                'F' => count(array_filter($pcts, static fn($v) => $v < 60)),
            ],
            'subjects' => $subjectStats,
        ];

        return [
            'status' => 'success',
            'class' => $bundle['class'],
            'year' => $bundle['year'],
            'term' => $bundle['term'],
            'students' => $students,
            'stats' => $stats,
            'grade_scale' => self::GRADE_SCALE,
            'pass_mark' => self::PASS_MARK,
            'brand' => self::brand(),
            '_bundle' => $bundle,
            '_computed' => $computedByMember,
        ];
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<string,mixed>
     */
    private static function cardFromPack(\mysqli $conn, array $pack, int $memberId): array
    {
        $studentRow = null;
        foreach ($pack['students'] as $row) {
            if ((int)$row['id'] === $memberId) {
                $studentRow = $row;
                break;
            }
        }
        if (!$studentRow) {
            return ['status' => 'error', 'message' => 'This student is not in the selected class.'];
        }
        $bundle = $pack['_bundle'] ?? null;
        $computed = $pack['_computed'][$memberId] ?? null;
        if (!$bundle || !$computed) {
            return ['status' => 'error', 'message' => 'Could not build this report card.'];
        }
        $student = self::safeStudent($bundle['roster'][$memberId] ?? [
            'id' => $memberId,
            'student_name' => $studentRow['student_name'] ?? '',
            'father_name' => $studentRow['father_name'] ?? '',
            'baptismal_name' => $studentRow['christian_name'] ?? '',
            'member_code' => $studentRow['member_code'] ?? '',
        ]);
        return [
            'status' => 'success',
            'student' => $student,
            'class' => $bundle['class'],
            'year' => $bundle['year'],
            'term' => $bundle['term'],
            'subjects' => $computed['subjects'],
            'totals' => $computed['totals'],
            'attendance' => $computed['attendance'],
            'highlights' => $computed['highlights'],
            'overall_average' => $computed['totals']['average'],
            'overall_grade' => $computed['totals']['grade_letter'],
            'completion' => $pack['stats']['subjects'] ?? [],
            'rank' => $studentRow['rank'],
            'total_in_class' => (int)$pack['stats']['total_students'],
            'rank_tied' => !empty($studentRow['tied']),
            'grade_scale' => self::GRADE_SCALE,
            'pass_mark' => self::PASS_MARK,
            'issued_on' => self::issuedOn($conn),
            'brand' => self::brand(),
        ];
    }

    /**
     * All cards for a class — used for “Print class”. Hard cap keeps
     * memory honest on a huge school.
     *
     * @return array{status:string,message?:string,cards?:list<array<string,mixed>>}
     */
    public static function getClassCards(\mysqli $conn, int $classId, int $yearId = 0, int $termId = 0): array
    {
        $pack = self::buildRankedClass($conn, $classId, $yearId, $termId);
        if (($pack['status'] ?? '') !== 'success') {
            return $pack;
        }
        $cards = [];
        $n = 0;
        foreach ($pack['students'] as $row) {
            if ($n >= self::MAX_CLASS_SIZE) {
                break;
            }
            $card = self::cardFromPack($conn, $pack, (int)$row['id']);
            if (($card['status'] ?? '') === 'success') {
                $cards[] = $card;
                $n++;
            }
        }
        return [
            'status' => 'success',
            'cards' => $cards,
            'truncated' => count($pack['students']) > self::MAX_CLASS_SIZE,
            'class' => $pack['class'],
            'year' => $pack['year'],
            'term' => $pack['term'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function buildClassBundle(\mysqli $conn, int $classId, int $yearId, int $termId): ?array
    {
        $class = self::fetchClass($conn, $classId);
        if (!$class) {
            return null;
        }

        $preferredYear = $yearId > 0 ? $yearId : self::currentYearId($conn);
        $rosterYear = EnrollmentService::resolveRosterYear($conn, $classId, $preferredYear);
        $rosterYearId = $rosterYear['year_id'] ?? $preferredYear;

        $rosterRows = EnrollmentService::fetchRoster($conn, $classId, $rosterYearId, [
            'include_null_year' => true,
        ]);
        $roster = [];
        foreach ($rosterRows as $row) {
            $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
            if ($mid > 0) {
                $roster[$mid] = $row;
            }
        }

        $yearInfo = self::fetchYear($conn, (int)($rosterYearId ?: $preferredYear));
        $termInfo = $termId > 0 ? self::fetchTerm($conn, $termId) : null;
        $effectiveTermId = $termId > 0 ? $termId : 0;

        // No term requested means the ANNUAL view. Before migration 056 that
        // silently pooled every semester's marks into one average; it now
        // computes each semester separately and combines them per subject
        // using the year's configured weights.
        $isAnnual = ($effectiveTermId === 0);
        $reportTermNumber = (int)($termInfo['term_number'] ?? 0);

        $subjects = self::fetchSubjects($conn, $classId);
        $scoreYearId = self::resolveScoreYear($conn, $classId, (int)($yearInfo['id'] ?? 0));
        $weightYearId = $scoreYearId > 0 ? $scoreYearId : (int)($yearInfo['id'] ?? 0);
        $weights = SubjectDurationPolicy::weightsForYear($conn, $weightYearId);
        $termNumbers = self::termNumbers($conn, $weightYearId);
        $scores = self::fetchScores($conn, $classId, $scoreYearId, $effectiveTermId);
        $attendance = self::fetchAttendance($conn, $classId, array_keys($roster), (int)($yearInfo['id'] ?? 0));
        $assessments = self::fetchAssessments($conn, $classId, $scoreYearId, $effectiveTermId);

        // Subjects that only exist as scores (subject_id 0 or a new subject).
        foreach ($scores as $memberScores) {
            foreach ($memberScores as $sid => $rows) {
                if (!isset($subjects[$sid])) {
                    $name = $rows[0]['subject_name'] ?? 'Subject';
                    $subjects[$sid] = [
                        'id' => (int)$sid,
                        'subject_name' => $name,
                        'subject_name_en' => $rows[0]['subject_name_en'] ?? '',
                    ];
                }
            }
        }

        return [
            'class' => [
                'id' => (int)$class['id'],
                'class_name' => (string)($class['class_name'] ?? ''),
                'class_name_en' => (string)($class['class_name_en'] ?? ''),
            ],
            'year' => $yearInfo,
            'term' => $termInfo,
            'roster' => $roster,
            'subjects' => $subjects,
            'scores' => $scores,
            'attendance' => $attendance,
            'assessments' => $assessments,
            'is_annual' => $isAnnual,
            'report_term_number' => $reportTermNumber,
            'weights' => $weights,
            'term_numbers' => $termNumbers,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $subjects
     * @param array<int,list<array<string,mixed>>> $memberScores keyed by subject id
     * @param array<string,int|float> $attendance
     * @return array{subjects:list<array<string,mixed>>,totals:array<string,mixed>,attendance:array<string,mixed>,highlights:array<string,mixed>}
     */
    private static function computeStudent(
        int $memberId,
        array $subjects,
        array $memberScores,
        array $attendance,
        int $onlySubjectId = 0,
        array $plannedBySubject = [],
        array $ctx = []
    ): array {
        unset($memberId);
        $isAnnual = (bool)($ctx['is_annual'] ?? false);
        $reportTermNumber = (int)($ctx['report_term_number'] ?? 0);
        $weights = $ctx['weights'] ?? ['s1' => 50.0, 's2' => 50.0];
        $termNumbers = $ctx['term_numbers'] ?? [];

        $outSubjects = [];
        $subjectPcts = [];
        $totalObtained = 0.0;
        $totalMax = 0.0;
        $assessmentsCount = 0;

        foreach ($subjects as $subj) {
            $sid = (int)$subj['id'];
            if ($onlySubjectId > 0 && $sid !== $onlySubjectId) {
                continue;
            }

            $duration = SubjectDurationPolicy::normalize($subj['duration_type'] ?? null);
            $offeringTerm = (int)($subj['offering_term_number'] ?? 0);

            // A semester-only offering that declares its semester does not
            // appear on another semester's report at all. This is what lets a
            // Semester 1 subject be replaced by a different Semester 2 subject
            // without either showing up as an empty row on the other report.
            if (!$isAnnual
                && !SubjectDurationPolicy::appearsInTerm($duration, $offeringTerm, $reportTermNumber)) {
                continue;
            }

            $rows = $memberScores[$sid] ?? [];
            $agg = self::aggregateSubject($rows);
            $assessmentsCount += count($agg['assessments']);
            $totalObtained += $agg['obtained'];
            $totalMax += $agg['max'];

            $scoredIds = [];
            foreach ($agg['assessments'] as $a) {
                if (($a['score'] ?? null) !== null && (int)($a['id'] ?? 0) > 0) {
                    $scoredIds[] = (int)$a['id'];
                }
            }

            $semesterScores = ['s1' => null, 's2' => null];
            $untagged = 0;
            $finalScore = $agg['average'];
            $status = SubjectDurationPolicy::STATUS_PENDING;
            $reason = '';

            if ($isAnnual && $duration !== SubjectDurationPolicy::UNCLASSIFIED) {
                // Split this subject's marks by semester and combine them
                // through the duration policy. Raw S1 and S2 marks are never
                // averaged together.
                $byTerm = [1 => [], 2 => []];
                foreach ($rows as $row) {
                    $tn = $termNumbers[(int)($row['term_id'] ?? 0)] ?? 0;
                    if ($tn === 1 || $tn === 2) {
                        $byTerm[$tn][] = $row;
                    } else {
                        // No resolvable semester. Not guessed at, and not
                        // folded into either side; surfaced instead.
                        $untagged++;
                    }
                }
                $s1 = $byTerm[1] ? self::aggregateSubject($byTerm[1])['average'] : null;
                $s2 = $byTerm[2] ? self::aggregateSubject($byTerm[2])['average'] : null;
                $semesterScores = ['s1' => $s1, 's2' => $s2];

                $decision = SubjectDurationPolicy::finalScore(
                    $duration,
                    $s1 === null ? null : (float)$s1,
                    $s2 === null ? null : (float)$s2,
                    $weights,
                    $offeringTerm
                );
                $finalScore = $decision['final'];
                $status = $decision['status'];
                $reason = $decision['reason'];
            } elseif (!$isAnnual) {
                $status = SubjectDurationPolicy::semesterStatus(
                    $duration,
                    $reportTermNumber,
                    $agg['average'] !== null
                );
                $reason = $status === SubjectDurationPolicy::STATUS_CONTINUING
                    ? 'full-year subject still running; this is the semester score, not the annual result'
                    : '';
            } else {
                // Annual view of an unclassified offering: unchanged pre-056
                // behaviour, explicitly reported as not a classified final.
                $reason = 'subject duration is not classified; classify it to get a final score';
            }

            // Only a real final score contributes to the student's average. A
            // CONTINUING full-year subject is excluded, never counted as zero.
            if ($finalScore !== null) {
                $subjectPcts[] = [
                    'id' => $sid,
                    'subject_name' => $subj['subject_name'],
                    'average' => (float)$finalScore,
                ];
            }

            $outSubjects[] = [
                'id' => $sid,
                'subject_name' => $subj['subject_name'],
                'subject_name_en' => $subj['subject_name_en'] ?? '',
                'assessments' => $agg['assessments'],
                'obtained' => $agg['average'] !== null ? round($agg['obtained'], 2) : null,
                'max' => $agg['max'] > 0 ? round($agg['max'], 2) : null,
                'average' => $agg['average'],
                'final_percentage' => $finalScore,
                'grade_letter' => $finalScore !== null ? self::letter((float)$finalScore) : null,
                'completion' => self::subjectCompletion($plannedBySubject[$sid] ?? [], $scoredIds),
                'duration_type' => $duration,
                'offering_term_number' => $offeringTerm,
                'subject_status' => $status,
                'status_reason' => $reason,
                'semester_1_score' => $semesterScores['s1'],
                'semester_2_score' => $semesterScores['s2'],
                'semester_weights' => $isAnnual ? $weights : null,
                'untagged_mark_rows' => $untagged,
            ];
        }

        $overall = null;
        if ($subjectPcts) {
            $sum = 0.0;
            foreach ($subjectPcts as $p) {
                $sum += $p['average'];
            }
            $overall = round($sum / count($subjectPcts), 1);
        } elseif ($totalMax > 0 && !$isAnnual) {
            // Raw obtained/max across every mark. Correct for a single
            // semester, but in the ANNUAL view it would add Semester 1 and
            // Semester 2 raw marks into one percentage -- exactly what the
            // duration policy exists to prevent -- so it is not used there.
            $overall = round($totalObtained / $totalMax * 100, 1);
        }

        $pendingSubjects = 0;
        foreach ($outSubjects as $s) {
            if (($s['final_percentage'] ?? null) === null) {
                $pendingSubjects++;
            }
        }

        $strongest = null;
        $weakest = null;
        if (count($subjectPcts) >= 2) {
            $strongest = $subjectPcts[0];
            $weakest = $subjectPcts[0];
            foreach ($subjectPcts as $p) {
                if ($p['average'] > $strongest['average']) {
                    $strongest = $p;
                }
                if ($p['average'] < $weakest['average']) {
                    $weakest = $p;
                }
            }
            if ((int)$strongest['id'] === (int)$weakest['id']) {
                $weakest = null;
            }
        }

        usort($outSubjects, static function ($a, $b) {
            return strcasecmp((string)$a['subject_name'], (string)$b['subject_name']);
        });

        return [
            'subjects' => $outSubjects,
            'totals' => [
                'obtained' => $totalMax > 0 || $totalObtained > 0 ? round($totalObtained, 2) : null,
                'max' => $totalMax > 0 ? round($totalMax, 2) : null,
                'average' => $overall,
                'grade_letter' => $overall !== null ? self::letter($overall) : null,
                'subjects_count' => count($subjectPcts),
                'assessments_count' => $assessmentsCount,
                'pending_subjects' => $pendingSubjects,
                'is_annual' => $isAnnual,
                'semester_weights' => $isAnnual ? $weights : null,
            ],
            'attendance' => $attendance,
            'highlights' => [
                'strongest' => $strongest,
                'weakest' => $weakest,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{assessments:list<array<string,mixed>>,obtained:float,max:float,average:?float}
     */
    private static function aggregateSubject(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $rec) {
            $aid = (int)($rec['assessment_id'] ?? 0);
            $key = $aid > 0 ? 'a' . $aid : 'r' . (int)($rec['id'] ?? 0);
            $prev = $byKey[$key] ?? null;
            if ($prev && (int)$prev['id'] >= (int)$rec['id']) {
                continue;
            }
            $byKey[$key] = $rec;
        }

        $assessments = [];
        $obtained = 0.0;
        $max = 0.0;
        $pcts = [];
        $weighted = 0.0;
        $weightSum = 0.0;
        $allWeighted = true;

        foreach ($byKey as $rec) {
            $score = $rec['score'] === null || $rec['score'] === '' ? null : (float)$rec['score'];
            $maxScore = (float)($rec['max_score'] ?? $rec['assess_max'] ?? 0);
            if ($maxScore <= 0) {
                $maxScore = 0.0;
            }
            $weight = isset($rec['weight_percentage']) && $rec['weight_percentage'] !== null
                ? (float)$rec['weight_percentage']
                : 0.0;
            $pct = ($score !== null && $maxScore > 0) ? ($score / $maxScore) * 100 : null;
            if ($score !== null && $maxScore > 0) {
                $obtained += $score;
                $max += $maxScore;
                $pcts[] = $pct;
                if ($weight > 0) {
                    $weighted += $pct * $weight;
                    $weightSum += $weight;
                } else {
                    $allWeighted = false;
                }
            }
            $assessments[] = [
                'id' => (int)($rec['assessment_id'] ?? $rec['id'] ?? 0),
                'assessment_name' => (string)($rec['assessment_name'] ?? 'Assessment'),
                'score' => $score,
                'max_score' => $maxScore > 0 ? $maxScore : null,
                'weight_percentage' => $weight > 0 ? $weight : null,
                'percentage' => $pct !== null ? round($pct, 1) : null,
                'remarks' => self::safeText($rec['remarks'] ?? ''),
            ];
        }

        usort($assessments, static function ($a, $b) {
            return strcasecmp((string)$a['assessment_name'], (string)$b['assessment_name']);
        });

        $average = null;
        if ($allWeighted && $weightSum > 0) {
            $average = round($weighted / $weightSum, 1);
        } elseif ($pcts) {
            $average = round(array_sum($pcts) / count($pcts), 1);
        } elseif ($max > 0) {
            $average = round($obtained / $max * 100, 1);
        }

        return [
            'assessments' => $assessments,
            'obtained' => $obtained,
            'max' => $max,
            'average' => $average,
        ];
    }

    /**
     * @param array<int,array<int,list<array<string,mixed>>>> $allScores
     * @return array{average:?float,graded:int}
     */
    private static function subjectAveragesForClass(array $allScores, int $subjectId): array
    {
        $vals = [];
        foreach ($allScores as $memberScores) {
            $rows = $memberScores[$subjectId] ?? [];
            if (!$rows) {
                continue;
            }
            $agg = self::aggregateSubject($rows);
            if ($agg['average'] !== null) {
                $vals[] = $agg['average'];
            }
        }
        return [
            'average' => $vals ? round(array_sum($vals) / count($vals), 1) : null,
            'graded' => count($vals),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchSubjects(\mysqli $conn, int $classId): array
    {
        // Migration 056 added class_subjects.duration_type / .term_id. The
        // richer query is tried first and silently falls back on a database
        // where 056 has not been applied yet, so report cards keep working
        // between a code deploy and the migration.
        $sql056 =
            "SELECT s.id, s.subject_name, s.subject_name_en,
                    cs.duration_type, cs.term_id AS offering_term_id,
                    t.term_number AS offering_term_number
             FROM subjects s
             INNER JOIN class_subjects cs ON cs.subject_id = s.id
             LEFT JOIN academic_terms t ON t.id = cs.term_id
             WHERE cs.class_id = ? AND (s.is_active = 1 OR s.is_active IS NULL)
             ORDER BY s.subject_name";
        $sqlLegacy =
            "SELECT s.id, s.subject_name, s.subject_name_en
             FROM subjects s
             INNER JOIN class_subjects cs ON cs.subject_id = s.id
             WHERE cs.class_id = ? AND (s.is_active = 1 OR s.is_active IS NULL)
             ORDER BY s.subject_name";

        $subjects = [];
        // The database is asked what it supports BEFORE preparing. The old
        // `@prepare(...) ?: prepare($legacy)` form could never work: `@` does
        // not suppress exceptions and mysqli throws by default on PHP 8.1+,
        // so on a pre-056 database this method raised instead of degrading.
        $stmt = SubjectDurationPolicy::supportsOfferingDuration($conn)
            ? $conn->prepare($sql056)
            : $conn->prepare($sqlLegacy);
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $subjects[(int)$row['id']] = [
                    'id' => (int)$row['id'],
                    'subject_name' => (string)$row['subject_name'],
                    'subject_name_en' => (string)($row['subject_name_en'] ?? ''),
                    'duration_type' => SubjectDurationPolicy::normalize($row['duration_type'] ?? null),
                    'offering_term_id' => (int)($row['offering_term_id'] ?? 0),
                    'offering_term_number' => (int)($row['offering_term_number'] ?? 0),
                ];
            }
            $stmt->close();
        }
        return $subjects;
    }

    /**
     * term_id => term_number for one academic year, so a score row can be
     * attributed to Semester 1 or Semester 2.
     *
     * @return array<int,int>
     */
    private static function termNumbers(\mysqli $conn, int $yearId): array
    {
        $map = [];
        $sql = 'SELECT id, term_number FROM academic_terms';
        if ($yearId > 0) {
            $sql .= ' WHERE academic_year_id = ' . (int)$yearId;
        }
        try {
            $r = $conn->query($sql);
            while ($r && ($row = $r->fetch_assoc())) {
                $map[(int)$row['id']] = (int)$row['term_number'];
            }
        } catch (\Throwable $e) {
            return $map;
        }
        return $map;
    }

    /**
     * Scores for every student in the class. Year / term filters keep
     * NULL rows so a first Save still appears on the card.
     *
     * @return array<int,array<int,list<array<string,mixed>>>> member_id => subject_id => rows
     */
    private static function fetchScores(\mysqli $conn, int $classId, int $yearId, int $termId): array
    {
        $sql = "SELECT ar.id, ar.member_id, ar.class_id, ar.subject_id, ar.assessment_id,
                       ar.score, ar.max_score, ar.remarks, ar.academic_year_id, ar.term_id,
                       a.assessment_name, a.weight_percentage, a.max_score AS assess_max,
                       a.class_id AS a_class_id, a.subject_id AS a_subject_id,
                       s.subject_name AS rec_subject_name, s.subject_name_en AS rec_subject_en,
                       s2.subject_name AS a_subject_name, s2.subject_name_en AS a_subject_en
                FROM academic_records ar
                LEFT JOIN assessments a ON a.id = ar.assessment_id
                LEFT JOIN subjects s ON s.id = ar.subject_id
                LEFT JOIN subjects s2 ON s2.id = a.subject_id
                WHERE (ar.class_id = ? OR a.class_id = ?)";
        $params = [$classId, $classId];
        $types = 'ii';
        if ($yearId > 0) {
            $sql .= " AND (ar.academic_year_id = ? OR ar.academic_year_id IS NULL OR ar.academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        if ($termId > 0) {
            $sql .= " AND (ar.term_id = ? OR ar.term_id IS NULL OR ar.term_id = 0)";
            $params[] = $termId;
            $types .= 'i';
        }
        $sql .= " ORDER BY ar.id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        $out = [];
        while ($row = $r->fetch_assoc()) {
            $mid = (int)$row['member_id'];
            $sid = (int)($row['subject_id'] ?: 0);
            if ($sid <= 0) {
                $sid = (int)($row['a_subject_id'] ?: 0);
            }
            $row['subject_name'] = $row['rec_subject_name'] ?: ($row['a_subject_name'] ?: 'Subject');
            $row['subject_name_en'] = $row['rec_subject_en'] ?: ($row['a_subject_en'] ?: '');
            if ((float)($row['max_score'] ?? 0) <= 0 && (float)($row['assess_max'] ?? 0) > 0) {
                $row['max_score'] = $row['assess_max'];
            }
            $out[$mid][$sid][] = $row;
        }
        $stmt->close();
        return $out;
    }

    /**
     * @param list<int> $memberIds
     * @return array<int,array<string,int|float>>
     */
    private static function fetchAttendance(\mysqli $conn, int $classId, array $memberIds, int $yearId): array
    {
        $out = [];
        foreach ($memberIds as $id) {
            $out[(int)$id] = self::emptyAttendance();
        }
        if ($classId <= 0) {
            return $out;
        }

        $sql = "SELECT member_id,
                       COUNT(*) AS total,
                       SUM(status = 'present') AS present_count,
                       SUM(status = 'absent') AS absent_count,
                       SUM(status = 'late') AS late_count,
                       SUM(status = 'excused') AS excused_count
                FROM attendance
                WHERE class_id = ?";
        $params = [$classId];
        $types = 'i';
        if ($yearId > 0) {
            $sql .= " AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= " GROUP BY member_id";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $mid = (int)$row['member_id'];
            $total = (int)$row['total'];
            $present = (int)$row['present_count'];
            $absent = (int)$row['absent_count'];
            $late = (int)$row['late_count'];
            $excused = (int)$row['excused_count'];
            $attended = $present + $late;
            $out[$mid] = [
                'total' => $total,
                'present' => $present,
                'absent' => $absent,
                'late' => $late,
                'excused' => $excused,
                'rate' => $total > 0 ? round($attended / $total * 100, 1) : 0,
            ];
        }
        $stmt->close();
        return $out;
    }

    private static function emptyAttendance(): array
    {
        return [
            'total' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'excused' => 0,
            'rate' => 0,
        ];
    }

    private static function resolveScoreYear(\mysqli $conn, int $classId, int $preferredYearId): int
    {
        if ($preferredYearId > 0) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM academic_records
                 WHERE class_id = ? AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)"
            );
            if ($stmt) {
                $stmt->bind_param('ii', $classId, $preferredYearId);
                $stmt->execute();
                $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();
                if ($n > 0) {
                    return $preferredYearId;
                }
            }
        }
        $stmt = $conn->prepare(
            "SELECT academic_year_id, COUNT(*) AS c
             FROM academic_records
             WHERE class_id = ?
             GROUP BY academic_year_id
             ORDER BY c DESC, academic_year_id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return $preferredYearId;
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $alt = (int)($row['academic_year_id'] ?? 0);
        return $alt > 0 ? $alt : $preferredYearId;
    }

    private static function fetchClass(\mysqli $conn, int $classId): ?array
    {
        $stmt = $conn->prepare("SELECT id, class_name, class_name_en FROM classes WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function fetchYear(\mysqli $conn, int $yearId): ?array
    {
        if ($yearId <= 0) {
            $yearId = self::currentYearId($conn);
        }
        if ($yearId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, year_name, year_gc, is_current FROM academic_years WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'year_name' => (string)($row['year_name'] ?? ''),
            'year_gc' => (string)($row['year_gc'] ?? ''),
            'is_current' => (int)($row['is_current'] ?? 0),
        ];
    }

    private static function fetchTerm(\mysqli $conn, int $termId): ?array
    {
        if ($termId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, academic_year_id, term_name, term_number, is_current FROM academic_terms WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $termId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? [
            'id' => (int)$row['id'],
            'academic_year_id' => (int)($row['academic_year_id'] ?? 0),
            'term_name' => (string)($row['term_name'] ?? ''),
            'term_number' => (int)($row['term_number'] ?? 0),
            'is_current' => (int)($row['is_current'] ?? 0),
        ] : null;
    }

    private static function currentTerm(\mysqli $conn, int $yearId): ?array
    {
        if ($yearId > 0) {
            $stmt = $conn->prepare(
                "SELECT id, academic_year_id, term_name, term_number, is_current
                 FROM academic_terms
                 WHERE academic_year_id = ?
                 ORDER BY is_current DESC, term_number ASC
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $yearId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return [
                        'id' => (int)$row['id'],
                        'academic_year_id' => (int)$row['academic_year_id'],
                        'term_name' => (string)$row['term_name'],
                        'term_number' => (int)$row['term_number'],
                        'is_current' => (int)$row['is_current'],
                    ];
                }
            }
        }
        try {
            $r = $conn->query("SELECT id, academic_year_id, term_name, term_number, is_current FROM academic_terms WHERE is_current = 1 LIMIT 1");
            $row = $r ? $r->fetch_assoc() : null;
            return $row ? [
                'id' => (int)$row['id'],
                'academic_year_id' => (int)$row['academic_year_id'],
                'term_name' => (string)$row['term_name'],
                'term_number' => (int)$row['term_number'],
                'is_current' => (int)$row['is_current'],
            ] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The active academic year id, or 0 when none is set.
     *
     * Public because EducationAnalyticsService::getHubData() already calls it
     * from outside the class to resolve a missing year_id. While this was
     * private that call was a fatal "Call to private method" the moment the
     * hub was opened with no current academic year configured, which is the
     * state of a fresh deployment and of any year between rollovers.
     */
    public static function currentYearId(\mysqli $conn): int
    {
        $year = EnrollmentService::activeYear($conn);
        return $year ? (int)$year['id'] : 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function safeStudent(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? $row['member_id'] ?? 0),
            'student_name' => (string)($row['student_name'] ?? ''),
            'father_name' => (string)($row['father_name'] ?? ''),
            'grandfather_name' => (string)($row['grandfather_name'] ?? ''),
            'christian_name' => (string)($row['baptismal_name'] ?? ''),
            'member_code' => (string)($row['member_code'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
        ];
    }

    private static function safeText($value): string
    {
        $text = trim(strip_tags((string)$value));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 240);
        }
        return substr($text, 0, 240);
    }

    private static function issuedOn(\mysqli $conn): string
    {
        try {
            $now = new \DateTime('now', new \DateTimeZone('Africa/Addis_Ababa'));
            if (function_exists('wbws_format_date')) {
                return (string)wbws_format_date($now, 'long', $conn);
            }
            return $now->format('j M Y');
        } catch (\Throwable $e) {
            return date('j M Y');
        }
    }

    /**
     * @return array<string,string>
     */

    /**
     * Planned assessments for this class / year / term, grouped by subject.
     *
     * @return array<int,list<array{id:int,name:string,weight:float}>>
     */
    private static function fetchAssessments(\mysqli $conn, int $classId, int $yearId, int $termId): array
    {
        $sql = "SELECT id, subject_id, assessment_name, weight_percentage, term_id
                FROM assessments WHERE class_id = ?";
        $params = [$classId];
        $types = 'i';
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
        $sql .= " ORDER BY id";
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $r = $stmt->get_result();
            $out = [];
            while ($row = $r->fetch_assoc()) {
                $sid = (int)($row['subject_id'] ?? 0);
                $out[$sid][] = [
                    'id' => (int)$row['id'],
                    'name' => (string)($row['assessment_name'] ?? 'Assessment'),
                    'weight' => (float)($row['weight_percentage'] ?? 0),
                ];
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Assessment ids that have at least one score in this class for a subject.
     *
     * @param array<int,array<int,list<array<string,mixed>>>> $allScores
     * @return list<int>
     */
    private static function scoredAssessmentIds(array $allScores, int $subjectId): array
    {
        $ids = [];
        foreach ($allScores as $memberScores) {
            foreach ($memberScores[$subjectId] ?? [] as $rec) {
                $aid = (int)($rec['assessment_id'] ?? 0);
                if ($aid > 0 && $rec['score'] !== null && $rec['score'] !== '') {
                    $ids[$aid] = $aid;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * How much of this subject's 100% semester weight is already recorded.
     *
     * @param list<array{id:int,name:string,weight:float}> $planned
     * @param list<int> $scoredIds
     * @return array{planned:float,recorded:float,remaining:float,missing:list<string>}
     */
    private static function subjectCompletion(array $planned, array $scoredIds): array
    {
        $scored = [];
        foreach ($scoredIds as $id) {
            $scored[(int)$id] = true;
        }
        $plannedWeight = 0.0;
        $recorded = 0.0;
        $missing = [];
        $items = [];
        foreach ($planned as $a) {
            $w = (float)($a['weight'] ?? 0);
            if ($w <= 0) {
                continue;
            }
            $plannedWeight += $w;
            $isRecorded = !empty($scored[(int)$a['id']]);
            $label = trim(($a['name'] ?? 'Assessment') . ' (' . rtrim(rtrim(number_format($w, 1), '0'), '.') . '%)');
            if ($isRecorded) {
                $recorded += $w;
            } else {
                $missing[] = $label;
            }
            $items[] = [
                'id' => (int)($a['id'] ?? 0),
                'name' => (string)($a['name'] ?? 'Assessment'),
                'weight' => round($w, 1),
                'recorded' => $isRecorded,
            ];
        }
        if ($plannedWeight <= 0 && $scored) {
            $recorded = 100.0;
            $plannedWeight = 100.0;
        } elseif ($plannedWeight > 0 && $plannedWeight < 100) {
            $gap = round(100.0 - $plannedWeight, 1);
            if ($gap > 0) {
                $missing[] = 'Not yet set (' . rtrim(rtrim(number_format($gap, 1), '0'), '.') . '%)';
                $items[] = [
                    'id' => 0,
                    'name' => 'Not yet set',
                    'weight' => $gap,
                    'recorded' => false,
                ];
            }
        }
        $recorded = min(100.0, round($recorded, 1));
        $remaining = max(0.0, round(100.0 - $recorded, 1));
        return [
            'planned' => round($plannedWeight, 1),
            'recorded' => $recorded,
            'remaining' => $remaining,
            'missing' => $missing,
            'items' => $items,
        ];
    }

    /**
     * @param list<array<string,mixed>> $subjects
     * @return list<array<string,mixed>>
     */
    private static function slimSubjects(array $subjects): array
    {
        $out = [];
        foreach ($subjects as $sub) {
            $out[] = [
                'id' => (int)($sub['id'] ?? 0),
                'subject_name' => (string)($sub['subject_name'] ?? ''),
                'obtained' => $sub['obtained'] ?? null,
                'max' => $sub['max'] ?? null,
                'average' => $sub['average'] ?? null,
                'grade_letter' => $sub['grade_letter'] ?? null,
                'assessments' => $sub['assessments'] ?? [],
                'duration_type' => $sub['duration_type'] ?? null,
                'subject_status' => $sub['subject_status'] ?? null,
                'final_percentage' => $sub['final_percentage'] ?? null,
                'semester_1_score' => $sub['semester_1_score'] ?? null,
                'semester_2_score' => $sub['semester_2_score'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $highlights
     */
    private static function highlightName(array $highlights, string $key): ?string
    {
        $row = $highlights[$key] ?? null;
        if (!is_array($row) || empty($row['subject_name'])) {
            return null;
        }
        return (string)$row['subject_name'];
    }

    /**
     * Branded class workbook: school header, per-subject totals, overall, attendance.
     */
    public static function streamExcel(\mysqli $conn, int $classId, int $subjectId = 0, int $yearId = 0, int $termId = 0): void
    {
        $pack = self::buildRankedClass($conn, $classId, $yearId, $termId, $subjectId);
        if (($pack['status'] ?? '') !== 'success') {
            throw new \RuntimeException($pack['message'] ?? 'Could not build the class report.');
        }

        $brand = self::brand();
        $className = (string)($pack['class']['class_name'] ?? 'Class');
        $yearName = (string)($pack['year']['year_name'] ?? '');
        $termName = (string)($pack['term']['term_name'] ?? '');
        $period = trim($yearName . ($termName !== '' ? ' · ' . $termName : ''));
        $students = $pack['students'] ?? [];
        $subjectCols = $pack['stats']['subjects'] ?? [];
        $stats = $pack['stats'] ?? [];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Class');

        $headers = ['Rank', 'Name', 'Christian name', 'Code'];
        foreach ($subjectCols as $sub) {
            $nm = (string)$sub['subject_name'];
            $headers[] = $nm . ' obtained';
            $headers[] = $nm . ' max';
            $headers[] = $nm . ' %';
            $headers[] = $nm . ' grade';
        }
        $headers = array_merge($headers, [
            'Overall obtained', 'Overall max', 'Overall %', 'Grade',
            'Attendance %', 'Present', 'Absent', 'Late',
        ]);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

        $title = $brand['school_am'] ?: $brand['school_en'];
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['name' => 'Calibri', 'bold' => true, 'size' => 16, 'color' => ['argb' => 'FFF0C000']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF600000']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', trim('Student report · ' . $className . ($period !== '' ? ' · ' . $period : '')));
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11, 'color' => ['argb' => 'FFF0C000']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF400000']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '3', $h);
            $col++;
        }
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['name' => 'Calibri', 'bold' => true, 'size' => 10, 'color' => ['argb' => 'FFF0C000']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF600000']],
        ]);

        $rowNum = 4;
        foreach ($students as $st) {
            $byId = [];
            foreach ($st['subjects'] ?? [] as $ss) {
                $byId[(int)$ss['id']] = $ss;
            }
            $vals = [
                $st['rank'] ?? '',
                trim(($st['student_name'] ?? '') . ' ' . ($st['father_name'] ?? '')),
                $st['christian_name'] ?? '',
                $st['member_code'] ?? '',
            ];
            foreach ($subjectCols as $sub) {
                $ss = $byId[(int)$sub['id']] ?? [];
                $vals[] = $ss['obtained'] ?? '';
                $vals[] = $ss['max'] ?? '';
                $vals[] = $ss['average'] ?? '';
                $vals[] = $ss['grade_letter'] ?? '';
            }
            $vals[] = $st['total_obtained'] ?? '';
            $vals[] = $st['total_max'] ?? '';
            $vals[] = $st['overall_average'] ?? '';
            $vals[] = $st['grade_letter'] ?? '';
            $vals[] = $st['attendance_rate'] ?? 0;
            $vals[] = $st['present_days'] ?? 0;
            $vals[] = $st['absent_days'] ?? 0;
            $vals[] = $st['late_days'] ?? 0;
            $c = 1;
            foreach ($vals as $v) {
                $sheet->setCellValueExplicit(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $rowNum,
                    (string)$v,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                $c++;
            }
            $rowNum++;
        }

        $sheet->freezePane('A4');
        for ($i = 1; $i <= count($headers); $i++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $w = 14;
            $h = strtolower($headers[$i - 1]);
            if (strpos($h, 'name') !== false) {
                $w = 28;
            } elseif ($h === 'code' || $h === 'rank' || $h === 'grade' || strpos($h, 'grade') !== false) {
                $w = 12;
            }
            $sheet->getColumnDimension($letter)->setWidth($w);
        }

        $sum = $spreadsheet->createSheet();
        $sum->setTitle('Summary');
        $sum->setCellValue('A1', $title);
        $sum->setCellValue('A2', $className . ($period !== '' ? ' · ' . $period : ''));
        $sum->setCellValue('A4', 'Students');
        $sum->setCellValue('B4', (string)($stats['total_students'] ?? 0));
        $sum->setCellValue('A5', 'With scores');
        $sum->setCellValue('B5', (string)($stats['graded_students'] ?? 0));
        $sum->setCellValue('A6', 'Class average');
        $sum->setCellValue('B6', $stats['class_average'] !== null ? $stats['class_average'] . '%' : '—');
        $sum->setCellValue('A7', 'Median');
        $sum->setCellValue('B7', $stats['median'] !== null ? $stats['median'] . '%' : '—');
        $sum->setCellValue('A8', 'Pass rate');
        $sum->setCellValue('B8', $stats['pass_rate'] !== null ? $stats['pass_rate'] . '%' : '—');
        $sum->setCellValue('A10', 'Subject');
        $sum->setCellValue('B10', 'Class average %');
        $sum->setCellValue('C10', 'Students graded');
        $sum->setCellValue('D10', 'Semester recorded %');
        $sum->setCellValue('E10', 'Still left %');
        $sum->setCellValue('F10', 'Still to enter');
        $r = 11;
        foreach ($subjectCols as $sub) {
            $c = $sub['completion'] ?? [];
            $sum->setCellValue('A' . $r, $sub['subject_name']);
            $sum->setCellValue('B' . $r, $sub['average'] !== null ? $sub['average'] . '%' : '—');
            $sum->setCellValue('C' . $r, (string)($sub['graded'] ?? 0));
            $sum->setCellValue('D' . $r, isset($c['recorded']) ? $c['recorded'] . '%' : '—');
            $sum->setCellValue('E' . $r, isset($c['remaining']) ? $c['remaining'] . '%' : '—');
            $sum->setCellValue('F' . $r, !empty($c['missing']) ? implode(', ', $c['missing']) : '');
            $r++;
        }
        $sum->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF600000']],
        ]);
        $sum->getStyle('A10:F10')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFF0C000']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF600000']],
        ]);
        $sum->getColumnDimension('A')->setWidth(28);
        $sum->getColumnDimension('B')->setWidth(18);
        $sum->getColumnDimension('C')->setWidth(18);

        $marks = $spreadsheet->createSheet();
        $marks->setTitle('Assessments');
        $markHeads = ['Name', 'Christian name', 'Code', 'Subject', 'Assessment', 'Score', 'Max', '%'];
        $c = 1;
        foreach ($markHeads as $h) {
            $marks->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '1', $h);
            $c++;
        }
        $marks->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFF0C000']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF600000']],
        ]);
        $mr = 2;
        foreach ($students as $st) {
            $full = trim(($st['student_name'] ?? '') . ' ' . ($st['father_name'] ?? ''));
            foreach ($st['subjects'] ?? [] as $ss) {
                $rows = $ss['assessments'] ?? [];
                if (!$rows) {
                    $marks->fromArray([
                        $full, $st['christian_name'] ?? '', $st['member_code'] ?? '',
                        $ss['subject_name'] ?? '', '', '', '', '',
                    ], null, 'A' . $mr);
                    $mr++;
                    continue;
                }
                foreach ($rows as $a) {
                    $marks->fromArray([
                        $full,
                        $st['christian_name'] ?? '',
                        $st['member_code'] ?? '',
                        $ss['subject_name'] ?? '',
                        $a['assessment_name'] ?? '',
                        $a['score'] ?? '',
                        $a['max_score'] ?? '',
                        $a['percentage'] ?? '',
                    ], null, 'A' . $mr);
                    $mr++;
                }
            }
        }
        foreach (['A' => 28, 'B' => 18, 'C' => 12, 'D' => 22, 'E' => 22, 'F' => 10, 'G' => 10, 'H' => 10] as $colLetter => $w) {
            $marks->getColumnDimension($colLetter)->setWidth($w);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $safeClass = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $className) ?: 'class';
        $prefix = defined('EXPORT_PREFIX') ? EXPORT_PREFIX : 'fkss';
        $filename = $prefix . '_' . $safeClass . '_report.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Advanced student performance filtering by Grade % and Attendance % ranges across classes.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function filterStudentsPerformance(\mysqli $conn, array $filters = []): array
    {
        $classFilter = $filters['class_id'] ?? 'all';
        $preferredYear = !empty($filters['year_id']) ? (int)$filters['year_id'] : (int)(self::currentYearId($conn));
        $termId = !empty($filters['term_id']) ? (int)$filters['term_id'] : 0;
        
        $classesToQuery = [];
        if ($classFilter !== 'all' && $classFilter !== '' && (int)$classFilter > 0) {
            $stmt = $conn->prepare("SELECT id, class_name, class_name_en FROM classes WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($stmt) {
                $cid = (int)$classFilter;
                $stmt->bind_param('i', $cid);
                $stmt->execute();
                $c = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($c) $classesToQuery[] = $c;
            }
        } else {
            $res = $conn->query("SELECT id, class_name, class_name_en FROM classes WHERE is_active = 1 ORDER BY level_order, id");
            if ($res) {
                while ($c = $res->fetch_assoc()) {
                    $classesToQuery[] = $c;
                }
            }
        }

        if (empty($classesToQuery)) {
            return [
                'status' => 'success',
                'students' => [],
                'count' => 0,
                'stats' => [
                    'total' => 0,
                    'avg_grade' => 0,
                    'avg_attendance' => 0,
                    'high_achievers' => 0,
                    'at_risk_grade' => 0,
                    'at_risk_att' => 0,
                    'grade_distribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
                    'graded_count' => 0,
                ],
                'filters_applied' => $filters,
            ];
        }

        $minGrade = (isset($filters['min_grade']) && $filters['min_grade'] !== '') ? (float)$filters['min_grade'] : null;
        $maxGrade = (isset($filters['max_grade']) && $filters['max_grade'] !== '') ? (float)$filters['max_grade'] : null;
        $minAtt = (isset($filters['min_attendance']) && $filters['min_attendance'] !== '') ? (float)$filters['min_attendance'] : null;
        $maxAtt = (isset($filters['max_attendance']) && $filters['max_attendance'] !== '') ? (float)$filters['max_attendance'] : null;
        $gender = (!empty($filters['gender']) && $filters['gender'] !== 'all') ? strtolower(trim((string)$filters['gender'])) : null;
        $gradeLetter = (!empty($filters['grade_letter']) && $filters['grade_letter'] !== 'all') ? strtoupper(trim((string)$filters['grade_letter'])) : null;
        $search = !empty($filters['search']) ? strtolower(trim((string)$filters['search'])) : null;
        $sort = (string)($filters['sort'] ?? 'grade_desc');

        $allMatching = [];
        $gradePcts = [];
        $attRates = [];
        $gradeDist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        $highAchievers = 0;
        $atRiskGrade = 0;
        $atRiskAtt = 0;

        foreach ($classesToQuery as $cls) {
            $cid = (int)$cls['id'];
            $pack = self::buildRankedClass($conn, $cid, $preferredYear, $termId);
            if (($pack['status'] ?? '') !== 'success' || empty($pack['students'])) {
                continue;
            }

            foreach ($pack['students'] as $st) {
                $stAvg = $st['overall_average'];
                $stAtt = (float)($st['attendance_rate'] ?? 0);
                $stGender = strtolower((string)($st['gender'] ?? ''));
                $stLetter = (string)($st['grade_letter'] ?? 'F');
                $stName = (string)($st['student_name'] ?? '');
                $stFather = (string)($st['father_name'] ?? '');
                $stCode = (string)($st['member_code'] ?? '');
                $stChristian = (string)($st['christian_name'] ?? '');

                $stTotDays = (int)($st['total_days'] ?? 0);

                if ($gender !== null && $stGender !== $gender) {
                    continue;
                }
                if ($gradeLetter !== null && $stLetter !== $gradeLetter) {
                    continue;
                }
                if ($minGrade !== null) {
                    if ($stAvg === null || (float)$stAvg < $minGrade) {
                        continue;
                    }
                }
                if ($maxGrade !== null) {
                    if ($stAvg === null || (float)$stAvg > $maxGrade) {
                        continue;
                    }
                }
                if ($minAtt !== null) {
                    if ($stTotDays === 0 || $stAtt < $minAtt) {
                        continue;
                    }
                }
                if ($maxAtt !== null && $maxAtt < 100.0) {
                    if ($stTotDays === 0 || $stAtt > $maxAtt) {
                        continue;
                    }
                }
                if ($search !== null) {
                    $haystack = strtolower($stName . ' ' . $stFather . ' ' . $stCode . ' ' . $stChristian);
                    if (strpos($haystack, $search) === false) {
                        continue;
                    }
                }

                $st['class_id'] = $cid;
                $st['class_name'] = $cls['class_name'];
                $st['class_name_en'] = $cls['class_name_en'] ?? '';

                $allMatching[] = $st;

                if ($stAvg !== null) {
                    $gradePcts[] = (float)$stAvg;
                    if (isset($gradeDist[$stLetter])) {
                        $gradeDist[$stLetter]++;
                    }
                    if ((float)$stAvg < 50.0) {
                        $atRiskGrade++;
                    }
                    if ((float)$stAvg >= 85.0 && $stTotDays > 0 && $stAtt >= 80.0) {
                        $highAchievers++;
                    }
                }
                if ($stTotDays > 0) {
                    $attRates[] = $stAtt;
                    if ($stAtt < 60.0) {
                        $atRiskAtt++;
                    }
                }
            }
        }

        usort($allMatching, static function ($a, $b) use ($sort) {
            $aAvg = $a['overall_average'];
            $bAvg = $b['overall_average'];
            $aAtt = (float)($a['attendance_rate'] ?? 0);
            $bAtt = (float)($b['attendance_rate'] ?? 0);
            $aName = (string)($a['student_name'] ?? '');
            $bName = (string)($b['student_name'] ?? '');
            $aClass = (string)($a['class_name'] ?? '');
            $bClass = (string)($b['class_name'] ?? '');

            switch ($sort) {
                case 'grade_asc':
                    if ($aAvg === null && $bAvg === null) return strcasecmp($aName, $bName);
                    if ($aAvg === null) return 1;
                    if ($bAvg === null) return -1;
                    return ($aAvg < $bAvg) ? -1 : 1;

                case 'att_desc':
                    if (abs($aAtt - $bAtt) > 0.01) return ($aAtt < $bAtt) ? 1 : -1;
                    if ($aAvg !== null && $bAvg !== null && abs($aAvg - $bAvg) > 0.01) return ($aAvg < $bAvg) ? 1 : -1;
                    return strcasecmp($aName, $bName);

                case 'att_asc':
                    if (abs($aAtt - $bAtt) > 0.01) return ($aAtt < $bAtt) ? -1 : 1;
                    return strcasecmp($aName, $bName);

                case 'name_asc':
                    return strcasecmp($aName, $bName);

                case 'class_asc':
                    $cc = strcasecmp($aClass, $bClass);
                    if ($cc !== 0) return $cc;
                    if ($aAvg !== null && $bAvg !== null && abs($aAvg - $bAvg) > 0.01) return ($aAvg < $bAvg) ? 1 : -1;
                    return strcasecmp($aName, $bName);

                case 'grade_desc':
                default:
                    if ($aAvg === null && $bAvg === null) return strcasecmp($aName, $bName);
                    if ($aAvg === null) return 1;
                    if ($bAvg === null) return -1;
                    if (abs($aAvg - $bAvg) > 0.01) return ($aAvg < $bAvg) ? 1 : -1;
                    return ($aAtt < $bAtt) ? 1 : -1;
            }
        });

        $idx = 1;
        foreach ($allMatching as &$row) {
            $row['filter_rank'] = $idx++;
        }
        unset($row);

        $totCount = count($allMatching);
        $avgG = !empty($gradePcts) ? round(array_sum($gradePcts) / count($gradePcts), 1) : 0;
        $avgA = !empty($attRates) ? round(array_sum($attRates) / count($attRates), 1) : 0;

        // Advanced Statistical Calculations for Visual Analytics
        sort($gradePcts);
        $gradedN = count($gradePcts);
        $medianG = 0.0;
        $p25G = 0.0;
        $p75G = 0.0;
        $stdevG = 0.0;

        if ($gradedN > 0) {
            $mid = (int)floor(($gradedN - 1) / 2);
            $medianG = ($gradedN % 2 === 1)
                ? round($gradePcts[$mid], 1)
                : round(($gradePcts[$mid] + $gradePcts[$mid + 1]) / 2, 1);

            $p25Idx = (int)floor($gradedN * 0.25);
            $p75Idx = min($gradedN - 1, (int)floor($gradedN * 0.75));
            $p25G = round($gradePcts[$p25Idx], 1);
            $p75G = round($gradePcts[$p75Idx], 1);

            $sumSqDiff = 0.0;
            foreach ($gradePcts as $gVal) {
                $sumSqDiff += ($gVal - $avgG) ** 2;
            }
            $stdevG = round(sqrt($sumSqDiff / $gradedN), 2);
        }

        sort($attRates);
        $attN = count($attRates);
        $medianA = 0.0;
        $stdevA = 0.0;
        if ($attN > 0) {
            $midA = (int)floor(($attN - 1) / 2);
            $medianA = ($attN % 2 === 1)
                ? round($attRates[$midA], 1)
                : round(($attRates[$midA] + $attRates[$midA + 1]) / 2, 1);

            $sumSqDiffA = 0.0;
            foreach ($attRates as $aVal) {
                $sumSqDiffA += ($aVal - $avgA) ** 2;
            }
            $stdevA = round(sqrt($sumSqDiffA / $attN), 2);
        }

        // Subject-by-Subject Benchmarks across filtered cohort
        $subjectAgg = [];
        foreach ($allMatching as $st) {
            $stSubs = $st['subjects'] ?? [];
            if (!is_array($stSubs)) continue;
            foreach ($stSubs as $sb) {
                $sName = (string)($sb['name'] ?? $sb['subject_name'] ?? '');
                $sAvg = $sb['average'] ?? null;
                if ($sName === '' || $sAvg === null) continue;
                if (!isset($subjectAgg[$sName])) {
                    $subjectAgg[$sName] = ['total' => 0.0, 'count' => 0];
                }
                $subjectAgg[$sName]['total'] += (float)$sAvg;
                $subjectAgg[$sName]['count']++;
            }
        }

        $subjectBenchmarks = [];
        foreach ($subjectAgg as $sName => $sData) {
            if ($sData['count'] > 0) {
                $subjectBenchmarks[] = [
                    'subject' => $sName,
                    'average' => round($sData['total'] / $sData['count'], 1),
                    'count' => $sData['count'],
                ];
            }
        }
        usort($subjectBenchmarks, static fn($a, $b) => ($a['average'] < $b['average']) ? 1 : -1);

        // Triage & Health Matrix (5 Actionable Tiers + Untracked)
        $triage = [
            'mastery' => 0,          // Score >= 85 & Att >= 80
            'proficient' => 0,       // Score 70-84.9 & Att >= 70
            'academic_risk' => 0,    // Score < 50 & Att >= 60
            'attendance_risk' => 0,  // Att < 60 & Score >= 50
            'dual_critical' => 0,    // Score < 50 & Att < 60
            'untracked' => 0,        // total_days === 0
            'steady' => 0,           // other passing students
        ];

        foreach ($allMatching as $st) {
            $stAvg = $st['overall_average'];
            $stAtt = (float)($st['attendance_rate'] ?? 0);
            $stDays = (int)($st['total_days'] ?? 0);

            if ($stDays === 0) {
                $triage['untracked']++;
                continue;
            }

            if ($stAvg === null) {
                $triage['steady']++;
                continue;
            }

            $g = (float)$stAvg;
            if ($g >= 85.0 && $stAtt >= 80.0) {
                $triage['mastery']++;
            } elseif ($g < 50.0 && $stAtt < 60.0) {
                $triage['dual_critical']++;
            } elseif ($g < 50.0 && $stAtt >= 60.0) {
                $triage['academic_risk']++;
            } elseif ($stAtt < 60.0 && $g >= 50.0) {
                $triage['attendance_risk']++;
            } elseif ($g >= 70.0 && $stAtt >= 70.0) {
                $triage['proficient']++;
            } else {
                $triage['steady']++;
            }
        }

        // Binned Distributions for Histograms
        $gradeBins = ['<50' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
        foreach ($gradePcts as $gVal) {
            if ($gVal < 50.0) $gradeBins['<50']++;
            elseif ($gVal < 60.0) $gradeBins['50-59']++;
            elseif ($gVal < 70.0) $gradeBins['60-69']++;
            elseif ($gVal < 80.0) $gradeBins['70-79']++;
            elseif ($gVal < 90.0) $gradeBins['80-89']++;
            else $gradeBins['90-100']++;
        }

        $attBins = ['<50' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
        foreach ($attRates as $aVal) {
            if ($aVal < 50.0) $attBins['<50']++;
            elseif ($aVal < 60.0) $attBins['50-59']++;
            elseif ($aVal < 70.0) $attBins['60-69']++;
            elseif ($aVal < 80.0) $attBins['70-79']++;
            elseif ($aVal < 90.0) $attBins['80-89']++;
            else $attBins['90-100']++;
        }

        return [
            'status' => 'success',
            'students' => $allMatching,
            'count' => $totCount,
            'stats' => [
                'total' => $totCount,
                'avg_grade' => $avgG,
                'median_grade' => $medianG,
                'stdev_grade' => $stdevG,
                'p25_grade' => $p25G,
                'p75_grade' => $p75G,
                'avg_attendance' => $avgA,
                'median_attendance' => $medianA,
                'stdev_attendance' => $stdevA,
                'recorded_att_students' => count($attRates),
                'unrecorded_att_students' => $totCount - count($attRates),
                'high_achievers' => $highAchievers,
                'at_risk_grade' => $atRiskGrade,
                'at_risk_att' => $atRiskAtt,
                'grade_distribution' => $gradeDist,
                'grade_bins' => $gradeBins,
                'att_bins' => $attBins,
                'triage_health' => $triage,
                'subject_benchmarks' => $subjectBenchmarks,
                'graded_count' => count($gradePcts),
            ],
            'filters_applied' => [
                'class_id' => $classFilter,
                'min_grade' => $minGrade,
                'max_grade' => $maxGrade,
                'min_attendance' => $minAtt,
                'max_attendance' => $maxAtt,
                'gender' => $gender,
                'grade_letter' => $gradeLetter,
                'search' => $search,
                'sort' => $sort,
            ]
        ];
    }

    /**
     * Export filtered students to Excel file download.
     *
     * @param array<string,mixed> $filters
     */
    public static function streamFilteredExcel(\mysqli $conn, array $filters = []): void
    {
        $res = self::filterStudentsPerformance($conn, $filters);
        if (($res['status'] ?? '') !== 'success') {
            throw new \RuntimeException('Failed to generate report.');
        }

        $students = $res['students'] ?? [];
        $stats = $res['stats'] ?? [];
        $brand = self::brand();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Filtered Performance');

        $headers = [
            '#', 'Student Name', 'Father Name', 'Baptismal Name', 'Member Code',
            'Class', 'Gender', 'Grade Average (%)', 'Letter Grade',
            'Total Obtained', 'Total Max', 'Assessments Count',
            'Attendance Rate (%)', 'Attendance Breakdown', 'Present Days', 'Absent Days', 'Late Days', 'Excused Days', 'Total Sessions'
        ];

        // Format Title & Banner
        $sheet->setCellValue('A1', ($brand['school_am'] ?? 'FKSS') . ' — ' . ($brand['school_en'] ?? ''));
        $sheet->setCellValue('A2', 'Filtered Student Performance & Attendance Report');
        $sheet->setCellValue('A3', 'Total Students: ' . ($stats['total'] ?? 0) . ' | Avg Grade: ' . ($stats['avg_grade'] ?? 0) . '% | Avg Attendance (recorded): ' . ($stats['avg_attendance'] ?? 0) . '% | Exported: ' . date('Y-m-d H:i'));

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        // Header row
        $headerRow = 5;
        foreach ($headers as $colIdx => $h) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue($colLetter . $headerRow, $h);
        }

        $headerStyle = $sheet->getStyle("A{$headerRow}:S{$headerRow}");
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('5B21B6');

        $rIdx = $headerRow + 1;
        foreach ($students as $st) {
            $totDays = (int)($st['total_days'] ?? 0);
            $pDays = (int)($st['present_days'] ?? 0);
            $aDays = (int)($st['absent_days'] ?? 0);
            $lDays = (int)($st['late_days'] ?? 0);
            $eDays = (int)($st['excused_days'] ?? 0);
            $attRateStr = ($totDays > 0 && $st['attendance_rate'] !== null) ? ($st['attendance_rate'] . '%') : '—';
            $breakdownStr = ($totDays > 0) ? "{$pDays}P · {$aDays}A" . ($lDays > 0 ? " · {$lDays}L" : "") . ($eDays > 0 ? " · {$eDays}E" : "") . " ({$totDays} days)" : 'No records';

            $sheet->setCellValue('A' . $rIdx, $st['filter_rank'] ?? ($rIdx - $headerRow));
            $sheet->setCellValue('B' . $rIdx, $st['student_name'] ?? '');
            $sheet->setCellValue('C' . $rIdx, $st['father_name'] ?? '');
            $sheet->setCellValue('D' . $rIdx, $st['christian_name'] ?? '');
            $sheet->setCellValue('E' . $rIdx, $st['member_code'] ?? '');
            $sheet->setCellValue('F' . $rIdx, $st['class_name'] ?? '');
            $sheet->setCellValue('G' . $rIdx, ucfirst((string)($st['gender'] ?? '')));
            $sheet->setCellValue('H' . $rIdx, $st['overall_average'] !== null ? $st['overall_average'] . '%' : '—');
            $sheet->setCellValue('I' . $rIdx, $st['grade_letter'] ?? '—');
            $sheet->setCellValue('J' . $rIdx, $st['total_obtained'] ?? 0);
            $sheet->setCellValue('K' . $rIdx, $st['total_max'] ?? 0);
            $sheet->setCellValue('L' . $rIdx, $st['assessments_count'] ?? 0);
            $sheet->setCellValue('M' . $rIdx, $attRateStr);
            $sheet->setCellValue('N' . $rIdx, $breakdownStr);
            $sheet->setCellValue('O' . $rIdx, $pDays);
            $sheet->setCellValue('P' . $rIdx, $aDays);
            $sheet->setCellValue('Q' . $rIdx, $lDays);
            $sheet->setCellValue('R' . $rIdx, $eDays);
            $sheet->setCellValue('S' . $rIdx, $totDays);
            $rIdx++;
        }

        foreach (range(1, 19) as $col) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $minG = $filters['min_grade'] ?? '';
        $minA = $filters['min_attendance'] ?? '';
        $filterTag = ($minG !== '' ? 'G' . $minG : '') . ($minA !== '' ? '_A' . $minA : '');
        $filename = 'Students_Performance_' . ($filterTag ? $filterTag . '_' : '') . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * @return array<string,string>
     */
    public static function brand(): array
    {
        return [
            'school_am' => defined('SCHOOL_NAME_AMHARIC') ? SCHOOL_NAME_AMHARIC : 'ፈለገ ቅዱሳን ሰንበት ትምህርት ቤት',
            'school_en' => trim((defined('SCHOOL_TRANSLATION_EN') ? SCHOOL_TRANSLATION_EN : 'Spring of Saints') . ' ' . (defined('SCHOOL_TYPE') ? SCHOOL_TYPE : 'Sunday School')),
            'school_short_am' => defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : 'ፈለገ ቅዱሳን',
            'school_short' => defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'FKSS',
            'parish_am' => defined('PARISH_NAME_AM') ? PARISH_NAME_AM : '',
            'parish_en' => defined('PARISH_NAME_EN') ? PARISH_NAME_EN : '',
            'invocation' => defined('RELIGIOUS_INVOCATION') ? RELIGIOUS_INVOCATION : '',
            'logo' => defined('SCHOOL_LOGO_PATH') ? SCHOOL_LOGO_PATH : '/themes/fkss/assets/logos/school_logo.png',
            'primary' => defined('THEME_PRIMARY') ? THEME_PRIMARY : '#600000',
            'accent' => defined('THEME_ACCENT') ? THEME_ACCENT : '#F0C000',
            'sig_head' => defined('ID_CARD_SIG_HEAD_AM') ? ID_CARD_SIG_HEAD_AM : 'የሰንበት ት/ቤቱ ሃላፊ ስምና ፊርማ',
            'sig_admin' => defined('ID_CARD_SIG_ADMIN_AM') ? ID_CARD_SIG_ADMIN_AM : 'የደብሩ አስተዳደር ስምና ፊርማ',
        ];
    }
}
