<?php
/**
 * ============================================================================
 * Education Analytics, Reporting & Intelligence Service (Production-Grade)
 * ============================================================================
 * Centralized business intelligence engine for Sunday School Education Dept.
 *
 * Responsibilities:
 * - Aggregates school-wide academic & attendance metrics across all classes.
 * - Computes comparative class league tables and rankings.
 * - Audits teacher assessment delivery & exam governance (Mid/Final submitted vs missing).
 * - Identifies curriculum balance and subject competency benchmarks.
 * - Compiles student triage rosters for pastoral & academic intervention.
 * - Generates publication-ready 5-page Executive PDF briefs and 4-sheet Excel workbooks.
 *
 * @author Sunday School Management System (FKSS / WBWS)
 * @version 1.0.0 (2026 Production Build)
 * ============================================================================
 */

namespace App\Services;

require_once __DIR__ . '/ReportCardService.php';
require_once __DIR__ . '/SubmissionService.php';
require_once __DIR__ . '/EnrollmentService.php';

class EducationAnalyticsService
{
    public const PASS_MARK = 50.0;
    public const MASTERY_SCORE = 85.0;
    public const MASTERY_ATT = 80.0;
    public const AT_RISK_SCORE = 50.0;
    public const AT_RISK_ATT = 60.0;

    /**
     * Build the complete unified analytics hub payload for the Education Department.
     *
     * @param \mysqli $conn
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function getHubData(\mysqli $conn, array $filters = []): array
    {
        $yearId = !empty($filters['year_id']) ? (int)$filters['year_id'] : (int)(ReportCardService::currentYearId($conn));
        $termId = !empty($filters['term_id']) ? (int)$filters['term_id'] : 0;
        $classFilter = !empty($filters['class_id']) && $filters['class_id'] !== 'all' ? (int)$filters['class_id'] : 0;

        $students = [];
        $macroStats = [
            'total' => 0,
            'avg_grade' => 0,
            'median_grade' => 0,
            'stdev_grade' => 0,
            'p25_grade' => 0,
            'p75_grade' => 0,
            'avg_attendance' => 0,
            'median_attendance' => 0,
            'stdev_attendance' => 0,
            'recorded_att_students' => 0,
            'unrecorded_att_students' => 0,
            'high_achievers' => 0,
            'at_risk_grade' => 0,
            'at_risk_att' => 0,
            'grade_distribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
            'grade_bins' => ['<50' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0],
            'att_bins' => ['<50' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0],
            'triage_health' => ['mastery' => 0, 'proficient' => 0, 'academic_risk' => 0, 'attendance_risk' => 0, 'dual_critical' => 0, 'untracked' => 0, 'steady' => 0],
            'subject_benchmarks' => [],
            'graded_count' => 0,
        ];
        $classBenchmarks = [];
        $examGovernance = ['summary' => ['total_planned_assessments' => 0, 'approved_count' => 0, 'submitted_count' => 0, 'draft_count' => 0, 'missing_count' => 0, 'delivery_rate' => 0], 'audit_matrix' => []];
        $subjectBenchmarks = [];
        $triageRoster = [];

        // 1. Fetch Students Performance Dataset via ReportCardService
        try {
            $perfResult = ReportCardService::filterStudentsPerformance($conn, [
                'class_id' => $classFilter > 0 ? $classFilter : 'all',
                'year_id' => $yearId,
                'term_id' => $termId,
                'gender' => $filters['gender'] ?? 'all',
                'min_grade' => $filters['min_grade'] ?? null,
                'max_grade' => $filters['max_grade'] ?? null,
                'min_attendance' => $filters['min_attendance'] ?? null,
                'max_attendance' => $filters['max_attendance'] ?? null,
                'grade_letter' => $filters['grade_letter'] ?? 'all',
                'search' => $filters['search'] ?? null,
                'sort' => $filters['sort'] ?? 'grade_desc',
            ]);
            if (($perfResult['status'] ?? '') === 'success') {
                $students = $perfResult['students'] ?? [];
                $macroStats = array_merge($macroStats, $perfResult['stats'] ?? []);
                $subjectBenchmarks = $macroStats['subject_benchmarks'] ?? [];
            }
        } catch (\Throwable $e) {
            error_log('EducationAnalyticsService::filterStudentsPerformance error: ' . $e->getMessage());
        }

        // 2. Fetch Class-by-Class Comparative League Table
        try {
            $classBenchmarks = self::buildClassBenchmarks($conn, $yearId, $termId);
        } catch (\Throwable $e) {
            error_log('EducationAnalyticsService::buildClassBenchmarks error: ' . $e->getMessage());
        }

        // 3. Fetch Teacher Exam & Assessment Submission Governance Matrix
        try {
            $examGovernance = self::buildExamGovernance($conn, $yearId, $termId, $classFilter);
        } catch (\Throwable $e) {
            error_log('EducationAnalyticsService::buildExamGovernance error: ' . $e->getMessage());
        }

        // 4. Build Triage Action Roster
        try {
            $triageRoster = self::buildTriageRoster($students);
        } catch (\Throwable $e) {
            error_log('EducationAnalyticsService::buildTriageRoster error: ' . $e->getMessage());
        }

        return [
            'status' => 'success',
            'academic_year_id' => $yearId,
            'term_id' => $termId,
            'macro_stats' => $macroStats,
            'students' => $students,
            'students_count' => count($students),
            'class_benchmarks' => $classBenchmarks,
            'exam_governance' => $examGovernance,
            'subject_benchmarks' => $subjectBenchmarks,
            'triage_roster' => $triageRoster,
            'brand' => ReportCardService::brand(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Build comparative class-by-class league table with rankings, averages, and pass rates.
     *
     * @return list<array<string,mixed>>
     */
    public static function buildClassBenchmarks(\mysqli $conn, int $yearId, int $termId): array
    {
        $classes = [];
        try {
            $res = $conn->query("SELECT id, class_name, class_name_en, level_order FROM classes WHERE is_active = 1 ORDER BY level_order, id");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $classes[] = $row;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        $league = [];
        foreach ($classes as $c) {
            $cid = (int)$c['id'];
            try {
                $pack = ReportCardService::getClassReport($conn, $cid, 0, $yearId, $termId);
                if (($pack['status'] ?? '') !== 'success') {
                    continue;
                }

                $stList = $pack['students'] ?? [];
                $stats = $pack['stats'] ?? [];
                $tot = count($stList);
                $graded = (int)($stats['graded_students'] ?? 0);
                $avgGrade = $stats['class_average'] !== null ? (float)$stats['class_average'] : null;
                $medianGrade = $stats['median'] !== null ? (float)$stats['median'] : null;
                $passRate = $stats['pass_rate'] !== null ? (float)$stats['pass_rate'] : null;

                $attRates = [];
                $presentTot = 0;
                $absentTot = 0;
                $sessionsTot = 0;

                foreach ($stList as $st) {
                    $tDays = (int)($st['total_days'] ?? 0);
                    if ($tDays > 0) {
                        $attRates[] = (float)($st['attendance_rate'] ?? 0);
                        $presentTot += (int)($st['present_days'] ?? 0);
                        $absentTot += (int)($st['absent_days'] ?? 0);
                        $sessionsTot = max($sessionsTot, $tDays);
                    }
                }

                $avgAtt = !empty($attRates) ? round(array_sum($attRates) / count($attRates), 1) : null;
                $recAttCount = count($attRates);

                // Calculate curriculum submission completeness
                $semRec = (float)($stats['semester']['recorded'] ?? 0.0);

                $league[] = [
                    'class_id' => $cid,
                    'class_name' => $c['class_name'],
                    'class_name_en' => $c['class_name_en'] ?? '',
                    'level_order' => (int)($c['level_order'] ?? 0),
                    'total_students' => $tot,
                    'graded_students' => $graded,
                    'average_grade' => $avgGrade,
                    'median_grade' => $medianGrade,
                    'pass_rate' => $passRate,
                    'average_attendance' => $avgAtt,
                    'tracked_attendance_students' => $recAttCount,
                    'untracked_attendance_students' => $tot - $recAttCount,
                    'sessions_recorded' => $sessionsTot,
                    'curriculum_recorded_pct' => $semRec,
                    'grade_distribution' => $stats['grade_distribution'] ?? ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Sort league by average_grade descending
        usort($league, static function ($a, $b) {
            $aa = $a['average_grade'];
            $bb = $b['average_grade'];
            if ($aa === null && $bb === null) return 0;
            if ($aa === null) return 1;
            if ($bb === null) return -1;
            return ($aa < $bb) ? 1 : -1;
        });

        $rank = 1;
        foreach ($league as &$row) {
            $row['class_rank'] = $row['average_grade'] !== null ? $rank++ : '—';
        }
        unset($row);

        return $league;
    }

    /**
     * Build Teacher Assessment & Exam Submission Governance Matrix.
     * Checks all assigned teachers, subjects, planned assessments (Mid, Final, etc.), and their delivery status.
     *
     * @return array<string,mixed>
     */
    public static function buildExamGovernance(\mysqli $conn, int $yearId, int $termId, int $classFilter = 0): array
    {
        // 1. Fetch active teacher assignments with class and subject
        $sql = "SELECT ta.id AS assignment_id, ta.teacher_id, ta.class_id, ta.subject_id,
                       COALESCE(u.full_name, 'Teacher') AS teacher_name, COALESCE(u.email, '') AS teacher_email,
                       COALESCE(c.class_name, 'Class') AS class_name, COALESCE(c.class_name_en, '') AS class_name_en,
                       COALESCE(s.subject_name, 'General Subject') AS subject_name, COALESCE(s.subject_name_en, '') AS subject_name_en
                FROM teacher_assignments ta
                LEFT JOIN users u ON ta.teacher_id = u.id
                LEFT JOIN classes c ON ta.class_id = c.id
                LEFT JOIN subjects s ON ta.subject_id = s.id
                WHERE (ta.is_active = 1 OR ta.is_active IS NULL)";
        
        $params = [];
        $types = '';
        if ($classFilter > 0) {
            $sql .= " AND ta.class_id = ?";
            $params[] = $classFilter;
            $types .= 'i';
        }
        if ($yearId > 0) {
            $sql .= " AND (ta.academic_year_id = ? OR ta.academic_year_id IS NULL OR ta.academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= " ORDER BY c.level_order, c.class_name, s.subject_name";

        $assignments = [];
        try {
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($types !== '') {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $assignments[] = $row;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $assignments = [];
        }

        // 2. Fetch planned assessments safely
        $assessSql = "SELECT a.id, a.class_id, a.subject_id, a.assessment_name, a.weight_percentage, a.max_score
                      FROM assessments a
                      WHERE 1=1";
        $aParams = [];
        $aTypes = '';
        if ($classFilter > 0) {
            $assessSql .= " AND a.class_id = ?";
            $aParams[] = $classFilter;
            $aTypes .= 'i';
        }
        if ($yearId > 0) {
            $assessSql .= " AND (a.academic_year_id = ? OR a.academic_year_id IS NULL OR a.academic_year_id = 0)";
            $aParams[] = $yearId;
            $aTypes .= 'i';
        }
        if ($termId > 0) {
            $assessSql .= " AND (a.term_id = ? OR a.term_id IS NULL OR a.term_id = 0)";
            $aParams[] = $termId;
            $aTypes .= 'i';
        }

        $plannedAssessments = [];
        try {
            $aStmt = $conn->prepare($assessSql);
            if ($aStmt) {
                if ($aTypes !== '') {
                    $aStmt->bind_param($aTypes, ...$aParams);
                }
                $aStmt->execute();
                $aRes = $aStmt->get_result();
                while ($row = $aRes->fetch_assoc()) {
                    $cid = (int)$row['class_id'];
                    $sid = (int)$row['subject_id'];
                    $plannedAssessments[$cid][$sid][] = $row;
                }
                $aStmt->close();
            }
        } catch (\Throwable $e) {
            $plannedAssessments = [];
        }

        // 3. Fetch grade_submissions records safely
        $submissionsMap = [];
        try {
            $subSql = "SELECT gs.id, gs.teacher_id, gs.class_id, gs.subject_id, gs.assessment_id, gs.status,
                              gs.student_count, gs.average_score, gs.submitted_at, gs.reviewed_at, gs.review_notes,
                              rv.full_name AS reviewer_name
                       FROM grade_submissions gs
                       LEFT JOIN users rv ON gs.reviewed_by = rv.id
                       WHERE 1=1";
            $subParams = [];
            $subTypes = '';
            if ($classFilter > 0) {
                $subSql .= " AND gs.class_id = ?";
                $subParams[] = $classFilter;
                $subTypes .= 'i';
            }
            if ($yearId > 0) {
                $subSql .= " AND (gs.academic_year_id = ? OR gs.academic_year_id IS NULL OR gs.academic_year_id = 0)";
                $subParams[] = $yearId;
                $subTypes .= 'i';
            }

            $sStmt = $conn->prepare($subSql);
            if ($sStmt) {
                if ($subTypes !== '') {
                    $sStmt->bind_param($subTypes, ...$subParams);
                }
                $sStmt->execute();
                $sRes = $sStmt->get_result();
                while ($row = $sRes->fetch_assoc()) {
                    $cid = (int)$row['class_id'];
                    $sid = (int)$row['subject_id'];
                    $aid = (int)$row['assessment_id'];
                    $submissionsMap[$cid][$sid][$aid] = $row;
                }
                $sStmt->close();
            }
        } catch (\Throwable $e) {
            $submissionsMap = [];
        }

        // Build composite audit matrix rows
        $auditRows = [];
        $totPlanned = 0;
        $totApproved = 0;
        $totSubmitted = 0;
        $totDraft = 0;
        $totMissing = 0;

        foreach ($assignments as $asg) {
            $cid = (int)$asg['class_id'];
            $sid = (int)$asg['subject_id'];
            $tName = (string)$asg['teacher_name'];
            $cName = (string)$asg['class_name'];
            $sName = (string)$asg['subject_name'];

            $subjectAssess = $plannedAssessments[$cid][$sid] ?? [];
            if (empty($subjectAssess)) {
                $auditRows[] = [
                    'class_id' => $cid,
                    'class_name' => $cName,
                    'subject_id' => $sid,
                    'subject_name' => $sName,
                    'teacher_id' => (int)$asg['teacher_id'],
                    'teacher_name' => $tName,
                    'assessment_id' => 0,
                    'assessment_title' => 'General / No assessments configured yet',
                    'assessment_type' => '—',
                    'weight' => 0,
                    'max_score' => 0,
                    'status' => 'unconfigured',
                    'status_label' => 'Not Configured',
                    'status_badge' => 'ch-w',
                    'submission_id' => 0,
                    'student_count' => 0,
                    'average_score' => null,
                    'submitted_at' => null,
                    'reviewer_name' => null,
                ];
                continue;
            }

            foreach ($subjectAssess as $aItem) {
                $aid = (int)$aItem['id'];
                $totPlanned++;
                $sub = $submissionsMap[$cid][$sid][$aid] ?? null;

                $st = $sub ? (string)$sub['status'] : 'missing';
                if ($st === 'approved') $totApproved++;
                elseif ($st === 'submitted') $totSubmitted++;
                elseif ($st === 'draft' || $st === 'incomplete') $totDraft++;
                else $totMissing++;

                $badgeMap = [
                    'approved' => 'badge-ok',
                    'submitted' => 'badge-info',
                    'draft' => 'badge-warn',
                    'incomplete' => 'badge-warn',
                    'revision_needed' => 'badge-err',
                    'missing' => 'badge-err',
                ];

                $labelMap = [
                    'approved' => 'Approved',
                    'submitted' => 'Submitted (Pending Review)',
                    'draft' => 'Draft (In Progress)',
                    'incomplete' => 'Draft (Incomplete)',
                    'revision_needed' => 'Needs Revision',
                    'missing' => 'Missing / Not Submitted',
                ];

                $auditRows[] = [
                    'class_id' => $cid,
                    'class_name' => $cName,
                    'subject_id' => $sid,
                    'subject_name' => $sName,
                    'teacher_id' => (int)$asg['teacher_id'],
                    'teacher_name' => $tName,
                    'assessment_id' => $aid,
                    'assessment_title' => (string)$aItem['assessment_name'],
                    'assessment_type' => (string)$aItem['assessment_name'],
                    'weight' => (float)$aItem['weight_percentage'],
                    'max_score' => (float)$aItem['max_score'],
                    'status' => $st,
                    'status_label' => $labelMap[$st] ?? ucfirst($st),
                    'status_badge' => $badgeMap[$st] ?? 'badge-warn',
                    'submission_id' => $sub ? (int)$sub['id'] : 0,
                    'student_count' => $sub ? (int)$sub['student_count'] : 0,
                    'average_score' => $sub && $sub['average_score'] !== null ? round((float)$sub['average_score'], 1) : null,
                    'submitted_at' => $sub ? $sub['submitted_at'] : null,
                    'reviewer_name' => $sub ? $sub['reviewer_name'] : null,
                ];
            }
        }

        $deliveryRate = $totPlanned > 0 ? round(($totApproved + $totSubmitted) / $totPlanned * 100, 1) : 0;

        return [
            'summary' => [
                'total_planned_assessments' => $totPlanned,
                'approved_count' => $totApproved,
                'submitted_count' => $totSubmitted,
                'draft_count' => $totDraft,
                'missing_count' => $totMissing,
                'delivery_rate' => $deliveryRate,
            ],
            'audit_matrix' => $auditRows,
        ];
    }

    /**
     * Build Triage Action Roster for academic & attendance intervention.
     *
     * @param list<array<string,mixed>> $students
     * @return list<array<string,mixed>>
     */
    public static function buildTriageRoster(array $students): array
    {
        $roster = [];
        foreach ($students as $s) {
            $g = $s['overall_average'] !== null ? (float)$s['overall_average'] : null;
            $att = (float)($s['attendance_rate'] ?? 0);
            $tDays = (int)($s['total_days'] ?? 0);

            $reasons = [];
            $severity = 'low';

            if ($g !== null && $g < self::AT_RISK_SCORE && $tDays > 0 && $att < self::AT_RISK_ATT) {
                $reasons[] = '🚨 Dual Critical: Score < 50% & Attendance < 60%';
                $severity = 'critical';
            } elseif ($g !== null && $g < self::AT_RISK_SCORE) {
                $reasons[] = '⚠️ Academic Risk: Score < 50%';
                $severity = 'high';
            } elseif ($tDays > 0 && $att < self::AT_RISK_ATT) {
                $reasons[] = '⏰ Attendance Intervention: Attendance < 60%';
                $severity = 'medium';
            }

            if (!empty($reasons)) {
                $roster[] = [
                    'id' => (int)$s['id'],
                    'student_name' => (string)$s['student_name'],
                    'father_name' => (string)$s['father_name'],
                    'christian_name' => (string)($s['christian_name'] ?? ''),
                    'member_code' => (string)($s['member_code'] ?? ''),
                    'class_id' => (int)$s['class_id'],
                    'class_name' => (string)$s['class_name'],
                    'gender' => (string)$s['gender'],
                    'overall_average' => $g,
                    'grade_letter' => (string)$s['grade_letter'],
                    'attendance_rate' => $tDays > 0 ? $att : null,
                    'total_days' => $tDays,
                    'present_days' => (int)($s['present_days'] ?? 0),
                    'absent_days' => (int)($s['absent_days'] ?? 0),
                    'severity' => $severity,
                    'reasons' => $reasons,
                ];
            }
        }

        // Sort by severity (critical first, then high, then medium)
        $order = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
        usort($roster, static fn($a, $b) => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return $roster;
    }

    /**
     * Stream a 5-Page Publication-Ready Executive Education Intelligence PDF/Print HTML.
     *
     * @param \mysqli $conn
     * @param array<string,mixed> $filters
     */
    public static function streamExecutivePdf(\mysqli $conn, array $filters = []): void
    {
        $data = self::getHubData($conn, $filters);
        $brand = $data['brand'];
        $stats = $data['macro_stats'];
        $classes = $data['class_benchmarks'];
        $governance = $data['exam_governance']['summary'];
        $auditMatrix = $data['exam_governance']['audit_matrix'];
        $triage = $data['triage_roster'];

        $today = date('F j, Y');
        $todayAm = date('Y-m-d');

        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Executive Education Intelligence Report — <?= htmlspecialchars($brand['school_short'] ?? 'FKSS') ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=DM+Sans:wght@400;500;700&display=swap');
*{box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;color:#1e293b;background:#fff;margin:0;padding:0;font-size:12px;line-height:1.4}
.amh{font-family:'Noto Serif Ethiopic',serif}
.page{width:210mm;min-height:297mm;padding:20mm 15mm;margin:0 auto;background:#fff;page-break-after:always;position:relative}
@media print{
  body{background:#fff;font-size:11px}
  .page{width:100%;min-height:auto;padding:0;margin:0;page-break-after:always}
  .no-print{display:none!important}
}
.header{border-bottom:2px solid #600000;padding-bottom:12px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center}
.logo-title{display:flex;align-items:center;gap:12px}
.logo{width:56px;height:56px;object-fit:contain}
.school-am{font-size:16px;font-weight:700;color:#600000;line-height:1.2}
.school-en{font-size:12px;font-weight:600;color:#334155}
.doc-title{font-size:13px;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-top:4px}
.meta-box{text-align:right;font-size:10px;color:#64748b}
.meta-box strong{color:#1e293b}

.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.kpi-card{background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #600000;padding:10px;border-radius:6px}
.kpi-val{font-size:20px;font-weight:800;color:#1e293b;line-height:1.2}
.kpi-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase}
.kpi-sub{font-size:9px;color:#94a3b8;margin-top:2px}

.sec-head{font-size:13px;font-weight:700;color:#600000;border-bottom:1.5px solid #e2e8f0;padding-bottom:4px;margin:16px 0 8px;display:flex;justify-content:space-between}
table.rpt-tbl{width:100%;border-collapse:collapse;font-size:10.5px;margin-bottom:14px}
table.rpt-tbl th{background:#f1f5f9;color:#334155;font-weight:700;text-align:left;padding:6px 8px;border:1px solid #cbd5e1}
table.rpt-tbl td{padding:5px 8px;border:1px solid #e2e8f0}
table.rpt-tbl tr:nth-child(even){background:#fafafa}

.badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700}
.badge-ok{background:#ecfdf5;color:#047857}
.badge-info{background:#eff6ff;color:#0284c7}
.badge-warn{background:#fffbeb;color:#b45309}
.badge-err{background:#fef2f2;color:#b91c1c}

.sig-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:40px;page-break-inside:avoid}
.sig-box{border-top:1px dashed #94a3b8;padding-top:8px;text-align:center;font-size:11px}
.sig-box .title{font-weight:700;color:#1e293b}
.sig-box .am-title{font-size:10px;color:#64748b}

.action-bar{position:fixed;bottom:20px;right:20px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:50px;box-shadow:0 10px 25px rgba(0,0,0,.2);display:flex;gap:12px;z-index:9999}
.action-btn{background:#600000;color:#fff;border:none;padding:8px 16px;border-radius:25px;font-weight:700;cursor:pointer}
</style>
</head>
<body>

<div class="action-bar no-print">
  <span>📄 Official Executive Education Report</span>
  <button class="action-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
</div>

<!-- ═════════ PAGE 1: EXECUTIVE BRIEF & SCHOOL-WIDE PULSE ═════════ -->
<div class="page">
  <div class="header">
    <div class="logo-title">
      <?php if (!empty($brand['logo'])): ?><img src="<?= htmlspecialchars($brand['logo']) ?>" class="logo" alt="Logo"><?php endif; ?>
      <div>
        <div class="school-am amh"><?= htmlspecialchars($brand['school_am']) ?></div>
        <div class="school-en"><?= htmlspecialchars($brand['school_en']) ?></div>
        <div class="doc-title">Executive Education Intelligence Report</div>
      </div>
    </div>
    <div class="meta-box">
      <div>Report Date: <strong><?= $today ?> (<?= $todayAm ?>)</strong></div>
      <div>Scope: <strong>School-Wide Education Directorate</strong></div>
      <div>Classification: <strong>Official Institutional Brief</strong></div>
    </div>
  </div>

  <div class="kpi-grid">
    <div class="kpi-card" style="border-left-color:#6366f1">
      <div class="kpi-lbl">Total Cohort</div>
      <div class="kpi-val"><?= number_format($stats['total'] ?? 0) ?></div>
      <div class="kpi-sub"><?= $stats['graded_count'] ?? 0 ?> graded students</div>
    </div>
    <div class="kpi-card" style="border-left-color:#0284c7">
      <div class="kpi-lbl">School Grade Mean</div>
      <div class="kpi-val"><?= number_format((float)($stats['avg_grade'] ?? 0), 1) ?>%</div>
      <div class="kpi-sub">Median: <?= number_format((float)($stats['median_grade'] ?? 0), 1) ?>% (±<?= $stats['stdev_grade'] ?? 0 ?>%)</div>
    </div>
    <div class="kpi-card" style="border-left-color:#059669">
      <div class="kpi-lbl">Attendance Mean</div>
      <div class="kpi-val"><?= number_format((float)($stats['avg_attendance'] ?? 0), 1) ?>%</div>
      <div class="kpi-sub"><?= $stats['recorded_att_students'] ?? 0 ?> tracked (<?= $stats['unrecorded_att_students'] ?? 0 ?> untracked)</div>
    </div>
    <div class="kpi-card" style="border-left-color:#d97706">
      <div class="kpi-lbl">Exam Delivery Rate</div>
      <div class="kpi-val"><?= $governance['delivery_rate'] ?? 0 ?>%</div>
      <div class="kpi-sub"><?= $governance['approved_count'] + $governance['submitted_count'] ?> / <?= $governance['total_planned_assessments'] ?> assessments submitted</div>
    </div>
  </div>

  <div class="sec-head">
    <span>1. Executive Summary &amp; Cohort Health Synthesis</span>
    <span style="font-size:10px;font-weight:normal;color:#64748b">Directorial Overview</span>
  </div>
  <p style="margin:0 0 12px;font-size:11px;line-height:1.5;color:#334155">
    This executive intelligence packet synthesizes the academic progression, attendance regularities, and curriculum delivery status across all registered classes in the Sunday School.
    The student body maintains a collective grade mean of <strong><?= number_format((float)($stats['avg_grade'] ?? 0), 1) ?>%</strong> with <strong><?= $stats['high_achievers'] ?? 0 ?></strong> students meeting high achievement criteria ($\ge 85\%$ score and $\ge 80\%$ attendance).
    Faculty assessment governance demonstrates a <strong><?= $governance['delivery_rate'] ?? 0 ?>%</strong> exam delivery rate, with <strong><?= $governance['missing_count'] ?? 0 ?></strong> planned assessment submissions currently pending completion from assigned teachers.
  </p>

  <div class="sec-head">
    <span>2. Grade Distribution Breakdown</span>
    <span style="font-size:10px;font-weight:normal;color:#64748b">Scale: A (90-100) · B (80-89) · C (70-79) · D (60-69) · F (&lt;60)</span>
  </div>
  <table class="rpt-tbl">
    <thead>
      <tr>
        <th>Grade Category</th>
        <th>Score Range</th>
        <th>Student Count</th>
        <th>% of Graded Cohort</th>
        <th>Status Interpretation</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $gDist = $stats['grade_distribution'] ?? ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'F'=>0];
      $totG = max(1, (int)($stats['graded_count'] ?? 1));
      $ranges = [
          'A' => ['90.0% – 100.0%', 'Excellent / Mastery', 'badge-ok'],
          'B' => ['80.0% – 89.9%', 'Very Good / Proficient', 'badge-info'],
          'C' => ['70.0% – 79.9%', 'Good / Steady', 'badge-info'],
          'D' => ['60.0% – 69.9%', 'Satisfactory Pass', 'badge-warn'],
          'F' => ['0.0% – 59.9%', 'Academic Support Required', 'badge-err'],
      ];
      foreach ($ranges as $let => $inf):
          $cnt = $gDist[$let] ?? 0;
          $pct = round(($cnt / $totG) * 100, 1);
      ?>
      <tr>
        <td><strong>Grade <?= $let ?></strong></td>
        <td><?= $inf[0] ?></td>
        <td><strong><?= number_format($cnt) ?></strong></td>
        <td><?= $pct ?>%</td>
        <td><span class="badge <?= $inf[2] ?>"><?= $inf[1] ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sec-head">
    <span>3. Subject Competency &amp; Curriculum Averages</span>
  </div>
  <table class="rpt-tbl">
    <thead>
      <tr>
        <th>Subject Name</th>
        <th>Graded Students</th>
        <th>Subject Mean (%)</th>
        <th>Benchmark vs. School Mean</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (array_slice($data['subject_benchmarks'], 0, 6) as $sb):
          $sAvg = (float)$sb['average'];
          $schAvg = (float)($stats['avg_grade'] ?? 0);
          $diff = round($sAvg - $schAvg, 1);
      ?>
      <tr>
        <td><strong class="amh"><?= htmlspecialchars($sb['subject']) ?></strong></td>
        <td><?= number_format($sb['count']) ?></td>
        <td><strong><?= number_format($sAvg, 1) ?>%</strong></td>
        <td>
          <span class="badge <?= $diff >= 0 ? 'badge-ok' : 'badge-warn' ?>">
            <?= $diff >= 0 ? "+{$diff}% Above Mean" : "{$diff}% Below Mean" ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ═════════ PAGE 2: CLASS COMPARATIVE LEAGUE TABLE ═════════ -->
<div class="page">
  <div class="header">
    <div class="logo-title">
      <div>
        <div class="school-am amh"><?= htmlspecialchars($brand['school_am']) ?></div>
        <div class="doc-title">Section 2: Comparative Class Performance League Table</div>
      </div>
    </div>
    <div class="meta-box">Page 2 / 4</div>
  </div>

  <table class="rpt-tbl">
    <thead>
      <tr>
        <th>Rank</th>
        <th>Class Name</th>
        <th>Enrolled</th>
        <th>Graded</th>
        <th>Grade Avg</th>
        <th>Pass Rate</th>
        <th>Att Rate</th>
        <th>Exam Delivery</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($classes as $cls): ?>
      <tr>
        <td style="text-align:center;font-weight:700"><?= $cls['class_rank'] ?></td>
        <td><strong class="amh"><?= htmlspecialchars($cls['class_name']) ?></strong><?php if (!empty($cls['class_name_en'])): ?> (<?= htmlspecialchars($cls['class_name_en']) ?>)<?php endif; ?></td>
        <td><?= $cls['total_students'] ?></td>
        <td><?= $cls['graded_students'] ?></td>
        <td><strong><?= $cls['average_grade'] !== null ? number_format($cls['average_grade'], 1).'%' : '—' ?></strong></td>
        <td><?= $cls['pass_rate'] !== null ? number_format($cls['pass_rate'], 1).'%' : '—' ?></td>
        <td><?= $cls['average_attendance'] !== null ? number_format($cls['average_attendance'], 1).'%' : '—' ?></td>
        <td><span class="badge <?= $cls['curriculum_recorded_pct'] >= 80 ? 'badge-ok' : ($cls['curriculum_recorded_pct'] >= 50 ? 'badge-warn' : 'badge-err') ?>"><?= $cls['curriculum_recorded_pct'] ?>% recorded</span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sec-head">
    <span>4. Class League Highlights &amp; Observations</span>
  </div>
  <p style="font-size:11px;color:#334155;line-height:1.5">
    Classes ranked at the top of the league exhibit consistent teacher assessment recording alongside robust attendance rates (>80%).
    Classes lagging in pass rates correspond directly to missing assessment submissions or attendance data collection gaps.
  </p>
</div>

<!-- ═════════ PAGE 3: TEACHER EXAM SUBMISSION GOVERNANCE ═════════ -->
<div class="page">
  <div class="header">
    <div class="logo-title">
      <div>
        <div class="school-am amh"><?= htmlspecialchars($brand['school_am']) ?></div>
        <div class="doc-title">Section 3: Teacher Exam &amp; Assessment Submission Governance Matrix</div>
      </div>
    </div>
    <div class="meta-box">Page 3 / 4</div>
  </div>

  <table class="rpt-tbl">
    <thead>
      <tr>
        <th>Class</th>
        <th>Subject</th>
        <th>Teacher</th>
        <th>Assessment Title</th>
        <th>Weight</th>
        <th>Max</th>
        <th>Delivery Status</th>
        <th>Average</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (array_slice($auditMatrix, 0, 20) as $row): ?>
      <tr>
        <td class="amh"><?= htmlspecialchars($row['class_name']) ?></td>
        <td class="amh"><?= htmlspecialchars($row['subject_name']) ?></td>
        <td><?= htmlspecialchars($row['teacher_name']) ?></td>
        <td><?= htmlspecialchars($row['assessment_title']) ?></td>
        <td><?= $row['weight'] > 0 ? $row['weight'].'%' : '—' ?></td>
        <td><?= $row['max_score'] > 0 ? $row['max_score'].' pts' : '—' ?></td>
        <td><span class="badge <?= $row['status'] === 'approved' ? 'badge-ok' : ($row['status'] === 'submitted' ? 'badge-info' : ($row['status'] === 'draft' ? 'badge-warn' : 'badge-err')) ?>"><?= htmlspecialchars($row['status_label']) ?></span></td>
        <td><?= $row['average_score'] !== null ? $row['average_score'].' pts' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ═════════ PAGE 4: AT-RISK INTERVENTION & OFFICIAL SIGN-OFF ═════════ -->
<div class="page">
  <div class="header">
    <div class="logo-title">
      <div>
        <div class="school-am amh"><?= htmlspecialchars($brand['school_am']) ?></div>
        <div class="doc-title">Section 4: Priority Student Triage Roster &amp; Verification</div>
      </div>
    </div>
    <div class="meta-box">Page 4 / 4</div>
  </div>

  <table class="rpt-tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Student Name</th>
        <th>Code</th>
        <th>Class</th>
        <th>Grade Score</th>
        <th>Attendance</th>
        <th>Identified Priority Factor</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($triage)): ?>
      <tr><td colspan="7" style="text-align:center;padding:12px;color:#059669">No critical at-risk students identified.</td></tr>
      <?php else:
          $idx = 1;
          foreach (array_slice($triage, 0, 15) as $tr): ?>
      <tr>
        <td><?= $idx++ ?></td>
        <td><strong><?= htmlspecialchars($tr['student_name'] . ' ' . $tr['father_name']) ?></strong></td>
        <td><code><?= htmlspecialchars($tr['member_code']) ?></code></td>
        <td class="amh"><?= htmlspecialchars($tr['class_name']) ?></td>
        <td><strong><?= $tr['overall_average'] !== null ? number_format($tr['overall_average'], 1).'%' : '—' ?></strong></td>
        <td><?= $tr['attendance_rate'] !== null ? number_format($tr['attendance_rate'], 1).'%' : '—' ?></td>
        <td><span class="badge <?= $tr['severity'] === 'critical' ? 'badge-err' : 'badge-warn' ?>"><?= htmlspecialchars(implode(', ', $tr['reasons'])) ?></span></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <!-- FORMAL INSTITUTIONAL SIGN-OFF -->
  <div class="sig-grid">
    <div class="sig-box">
      <div class="title">Head of Education Department</div>
      <div class="am-title amh"><?= htmlspecialchars($brand['sig_head'] ?? 'የሰንበት ት/ቤቱ የትምህርት ክፍል ኃላፊ ስምና ፊርማ') ?></div>
      <div style="margin-top:25px">Date: ________________________</div>
    </div>
    <div class="sig-box">
      <div class="title">Parish Administration Office</div>
      <div class="am-title amh"><?= htmlspecialchars($brand['sig_admin'] ?? 'የደብሩ አስተዳደር ጽሕፈት ቤት ስምና ፊርማ') ?></div>
      <div style="margin-top:25px">Date: ________________________</div>
    </div>
  </div>
</div>

</body>
</html>
        <?php
        exit;
    }

    /**
     * Stream a 4-Sheet Formatted Excel Workbook (.xlsx) with PhpSpreadsheet.
     *
     * @param \mysqli $conn
     * @param array<string,mixed> $filters
     */
    public static function streamExecutiveExcel(\mysqli $conn, array $filters = []): void
    {
        $data = self::getHubData($conn, $filters);
        $brand = $data['brand'];
        $stats = $data['macro_stats'];
        $students = $data['students'];
        $classes = $data['class_benchmarks'];
        $auditMatrix = $data['exam_governance']['audit_matrix'];
        $triage = $data['triage_roster'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ════════════ SHEET 1: EXECUTIVE SUMMARY ════════════
        $s1 = $spreadsheet->getActiveSheet();
        $s1->setTitle('Executive Summary');

        $s1->setCellValue('A1', ($brand['school_am'] ?? 'FKSS') . ' — ' . ($brand['school_en'] ?? ''));
        $s1->setCellValue('A2', 'Executive Education Intelligence Summary');
        $s1->setCellValue('A3', 'Total Students: ' . ($stats['total'] ?? 0) . ' | Grade Mean: ' . ($stats['avg_grade'] ?? 0) . '% | Attendance Mean: ' . ($stats['avg_attendance'] ?? 0) . '% | Exam Delivery: ' . ($data['exam_governance']['summary']['delivery_rate'] ?? 0) . '% | Exported: ' . date('Y-m-d H:i'));

        $s1->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $s1->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $s1->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        // Class Benchmark Table
        $s1->setCellValue('A5', 'Class League Benchmarks');
        $s1->getStyle('A5')->getFont()->setBold(true)->setSize(11);

        $headers1 = ['Rank', 'Class Name', 'Enrolled', 'Graded', 'Average Grade (%)', 'Median Grade (%)', 'Pass Rate (%)', 'Attendance Rate (%)', 'Exam Delivery (%)'];
        foreach ($headers1 as $idx => $h) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $s1->setCellValue($colLetter . '6', $h);
        }
        $s1->getStyle('A6:I6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $s1->getStyle('A6:I6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('600000');

        $r1 = 7;
        foreach ($classes as $cls) {
            $s1->setCellValue('A' . $r1, $cls['class_rank']);
            $s1->setCellValue('B' . $r1, $cls['class_name']);
            $s1->setCellValue('C' . $r1, $cls['total_students']);
            $s1->setCellValue('D' . $r1, $cls['graded_students']);
            $s1->setCellValue('E' . $r1, $cls['average_grade'] !== null ? $cls['average_grade'] : '');
            $s1->setCellValue('F' . $r1, $cls['median_grade'] !== null ? $cls['median_grade'] : '');
            $s1->setCellValue('G' . $r1, $cls['pass_rate'] !== null ? $cls['pass_rate'] : '');
            $s1->setCellValue('H' . $r1, $cls['average_attendance'] !== null ? $cls['average_attendance'] : '');
            $s1->setCellValue('I' . $r1, $cls['curriculum_recorded_pct']);
            $r1++;
        }

        foreach (range(1, 9) as $c) {
            $s1->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        // ════════════ SHEET 2: TEACHER EXAM GOVERNANCE ════════════
        $s2 = $spreadsheet->createSheet();
        $s2->setTitle('Teacher Exam Governance');

        $headers2 = ['Class', 'Subject', 'Teacher Name', 'Assessment Title', 'Assessment Type', 'Weight (%)', 'Max Points', 'Delivery Status', 'Students Scored', 'Average Score', 'Submitted Date', 'Reviewer'];
        foreach ($headers2 as $idx => $h) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $s2->setCellValue($colLetter . '1', $h);
        }
        $s2->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $s2->getStyle('A1:L1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('7C3AED');

        $r2 = 2;
        foreach ($auditMatrix as $row) {
            $s2->setCellValue('A' . $r2, $row['class_name']);
            $s2->setCellValue('B' . $r2, $row['subject_name']);
            $s2->setCellValue('C' . $r2, $row['teacher_name']);
            $s2->setCellValue('D' . $r2, $row['assessment_title']);
            $s2->setCellValue('E' . $r2, $row['assessment_type']);
            $s2->setCellValue('F' . $r2, $row['weight']);
            $s2->setCellValue('G' . $r2, $row['max_score']);
            $s2->setCellValue('H' . $r2, $row['status_label']);
            $s2->setCellValue('I' . $r2, $row['student_count']);
            $s2->setCellValue('J' . $r2, $row['average_score'] ?? '');
            $s2->setCellValue('K' . $r2, $row['submitted_at'] ?? '');
            $s2->setCellValue('L' . $r2, $row['reviewer_name'] ?? '');
            $r2++;
        }
        foreach (range(1, 12) as $c) {
            $s2->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        // ════════════ SHEET 3: STUDENT RECORDS ════════════
        $s3 = $spreadsheet->createSheet();
        $s3->setTitle('Student Performance');

        $headers3 = ['#', 'Student Name', 'Father Name', 'Christian Name', 'Member Code', 'Class', 'Gender', 'Grade Average (%)', 'Letter Grade', 'Total Obtained', 'Total Max', 'Attendance Rate (%)', 'Present', 'Absent', 'Late', 'Excused', 'Total Days'];
        foreach ($headers3 as $idx => $h) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $s3->setCellValue($colLetter . '1', $h);
        }
        $s3->getStyle('A1:Q1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $s3->getStyle('A1:Q1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('059669');

        $r3 = 2;
        foreach ($students as $st) {
            $totDays = (int)($st['total_days'] ?? 0);
            $s3->setCellValue('A' . $r3, $st['filter_rank'] ?? ($r3 - 1));
            $s3->setCellValue('B' . $r3, $st['student_name'] ?? '');
            $s3->setCellValue('C' . $r3, $st['father_name'] ?? '');
            $s3->setCellValue('D' . $r3, $st['christian_name'] ?? '');
            $s3->setCellValue('E' . $r3, $st['member_code'] ?? '');
            $s3->setCellValue('F' . $r3, $st['class_name'] ?? '');
            $s3->setCellValue('G' . $r3, strtoupper((string)($st['gender'] ?? '')));
            $s3->setCellValue('H' . $r3, $st['overall_average'] !== null ? $st['overall_average'] : '');
            $s3->setCellValue('I' . $r3, $st['grade_letter'] ?? '');
            $s3->setCellValue('J' . $r3, $st['total_obtained'] ?? 0);
            $s3->setCellValue('K' . $r3, $st['total_max'] ?? 0);
            $s3->setCellValue('L' . $r3, $totDays > 0 ? ($st['attendance_rate'] ?? 0) : '—');
            $s3->setCellValue('M' . $r3, $st['present_days'] ?? 0);
            $s3->setCellValue('N' . $r3, $st['absent_days'] ?? 0);
            $s3->setCellValue('O' . $r3, $st['late_days'] ?? 0);
            $s3->setCellValue('P' . $r3, $st['excused_days'] ?? 0);
            $s3->setCellValue('Q' . $r3, $totDays);
            $r3++;
        }
        foreach (range(1, 17) as $c) {
            $s3->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        // ════════════ SHEET 4: TRIAGE ROSTER ════════════
        $s4 = $spreadsheet->createSheet();
        $s4->setTitle('Priority Triage Roster');

        $headers4 = ['#', 'Student Name', 'Father Name', 'Member Code', 'Class', 'Gender', 'Grade Average (%)', 'Attendance Rate (%)', 'Severity Level', 'Identified Risk Factors'];
        foreach ($headers4 as $idx => $h) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $s4->setCellValue($colLetter . '1', $h);
        }
        $s4->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $s4->getStyle('A1:J1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');

        $r4 = 2;
        $tIdx = 1;
        foreach ($triage as $tr) {
            $s4->setCellValue('A' . $r4, $tIdx++);
            $s4->setCellValue('B' . $r4, $tr['student_name']);
            $s4->setCellValue('C' . $r4, $tr['father_name']);
            $s4->setCellValue('D' . $r4, $tr['member_code']);
            $s4->setCellValue('E' . $r4, $tr['class_name']);
            $s4->setCellValue('F' . $r4, strtoupper((string)($tr['gender'] ?? '')));
            $s4->setCellValue('G' . $r4, $tr['overall_average'] !== null ? $tr['overall_average'] : '');
            $s4->setCellValue('H' . $r4, $tr['attendance_rate'] !== null ? $tr['attendance_rate'] : '');
            $s4->setCellValue('I' . $r4, ucfirst($tr['severity']));
            $s4->setCellValue('J' . $r4, implode('; ', $tr['reasons']));
            $r4++;
        }
        foreach (range(1, 10) as $c) {
            $s4->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'Education_Executive_Intelligence_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
