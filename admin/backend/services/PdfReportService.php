<?php
/**
 * ════════════════════════════════════════════════════════════
 * Information Department — PDF Report Service (Phase D)
 * ════════════════════════════════════════════════════════════
 * Generates governed attendance reports over the THREE independent
 * sources (Education classes, Mezmur sections, HR sections) using
 * the bundled TCPDF engine with an Ethiopic-capable font.
 *
 * Product rules (2026-08-28):
 *   • READ-ONLY — reports render from InfoAnalyticsService read
 *     model + scoped read queries; nothing is ever written.
 *   • Sources stay separate — comparison tables show one row per
 *     source; member reports list each source's history under its
 *     own heading. Identities are never merged.
 *   • Budgeted — the same read budgets as the analytics hub apply
 *     (date window ≤ 366 days, paged group reads).
 *
 * Report templates:
 *   general   — KPI band + department comparison (all sources)
 *   sections  — section-based detail (mezmur or hr)
 *   classes   — class-based detail (education)
 *   member    — one member's attendance history per source
 *   full      — combined deep-dive (all of the above)
 */

namespace App\Services;

require_once __DIR__ . '/../pdf/tcpdf/tcpdf.php';

class PdfReportService
{
    public const TYPES = ['general', 'sections', 'classes', 'member', 'full'];

    public const TYPE_LABELS = [
        'general' => 'General Attendance Report',
        'sections' => 'Section-Based Attendance Report',
        'classes' => 'Class-Based Attendance Report',
        'member' => 'Member Attendance Report',
        'full' => 'Full Attendance Analysis',
    ];

    private const FONT = 'notosansethiopic';
    private const FONT_BOLD = 'notosansethiopicb';
    private const MAX_MEMBER_ROWS = 200; // per-source history cap

    /**
     * Build a report. Returns ['ok', 'data', 'filename'] — data is the
     * raw PDF binary (caller streams/downloads it). Never writes to any
     * attendance table.
     */
    public static function build(\mysqli $conn, string $type, array $opts = []): array
    {
        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'message' => 'Unknown report type.'];
        }
        if ($type === 'member') {
            $memberId = (int)($opts['member_id'] ?? 0);
            if ($memberId <= 0) {
                return ['ok' => false, 'message' => 'Member ID is required for member reports.'];
            }
        }

        $from = self::clampDate($opts['from'] ?? null);
        $to = self::clampDate($opts['to'] ?? null);

        $pdf = self::newPdf(self::TYPE_LABELS[$type]);
        self::pageHeader($pdf, self::TYPE_LABELS[$type], $from, $to);

        switch ($type) {
            case 'general':
                self::renderGeneral($pdf, $conn, $from, $to);
                break;
            case 'sections':
                $source = ($opts['source'] ?? 'mezmur') === 'hr' ? 'hr' : 'mezmur';
                self::renderGroupDetail($pdf, $conn, $source, $from, $to);
                break;
            case 'classes':
                self::renderGroupDetail($pdf, $conn, 'edu', $from, $to);
                break;
            case 'member':
                self::renderMember($pdf, $conn, (int)$opts['member_id'], $from, $to);
                break;
            case 'full':
                self::renderGeneral($pdf, $conn, $from, $to);
                foreach (['mezmur', 'hr', 'edu'] as $src) {
                    $pdf->AddPage();
                    self::renderGroupDetail($pdf, $conn, $src, $from, $to);
                }
                break;
        }

        self::pageFooterNote($pdf);
        $data = $pdf->Output('report.pdf', 'S');
        $stamp = date('Ymd');
        return [
            'ok' => true,
            'data' => $data,
            'filename' => 'FKSS_' . self::TYPE_LABELS[$type] . '_' . $stamp . '.pdf',
        ];
    }

    // ────────────────────────────────────────────────────────────
    // TEMPLATE PARTS
    // ────────────────────────────────────────────────────────────

    /** KPI band + side-by-side comparison (never merged). */
    private static function renderGeneral(\TCPDF $pdf, \mysqli $conn, ?string $from, ?string $to): void
    {
        $kpi = InfoAnalyticsService::kpiBand($conn, $from, $to);
        $pdf->SetFont(self::FONT_BOLD, '', 11);
        $pdf->Cell(0, 8, 'Window: ' . $kpi['from'] . ' to ' . $kpi['to'], 0, 1);

        self::sectionTitle($pdf, 'Department Comparison (sources stay separate)');
        $head = ['Department', 'Days', 'Groups', 'Marks', 'Attended', 'Rate', 'Absent', 'Late', 'Excused', 'Sheets', 'Approved'];
        self::tableHeader($pdf, $head, [38, 13, 14, 14, 17, 14, 14, 13, 15, 13, 16]);
        foreach ($kpi['items'] as $row) {
            self::tableRow($pdf, [
                $row['label'],
                (string)$row['days'],
                (string)$row['groups_active'],
                (string)$row['marked'],
                (string)$row['attended'],
                $row['rate'] === null ? '—' : $row['rate'] . '%',
                (string)$row['absent'],
                (string)$row['late'],
                (string)$row['excused'],
                (string)$row['packets'],
                (string)$row['approved'],
            ], [38, 13, 14, 14, 17, 14, 14, 13, 15, 13, 16]);
        }

        $pdf->Ln(4);
        $pdf->SetFont(self::FONT, '', 8.5);
        $pdf->MultiCell(0, 5,
            "Note: each department records attendance with its OWN takers on its OWN tables. " .
            "Education is class-based (teachers); Mezmur and HR are section-based. " .
            "Figures are compared side-by-side and are never combined into one total.",
            0, 'L');
    }

    /** Per-group (section or class) detail for one source. */
    private static function renderGroupDetail(\TCPDF $pdf, \mysqli $conn, string $source, ?string $from, ?string $to): void
    {
        $label = InfoAnalyticsService::SOURCE_LABELS[$source] ?? $source;
        $groupWord = $source === 'edu' ? 'Class' : 'Section';
        self::sectionTitle($pdf, $label . ' — ' . $groupWord . '-Based Detail');

        $group = InfoAnalyticsService::groupTable($conn, $source, $from, $to, 1, 200, 'marked', 'desc');
        if (empty($group['items'])) {
            $pdf->SetFont(self::FONT, '', 10);
            $pdf->Cell(0, 8, 'No ' . strtolower($groupWord) . ' attendance recorded in this window.', 0, 1);
            return;
        }

        $head = [$groupWord, 'Days', 'Marks', 'Attended', 'Rate', 'Absent', 'Late', 'Excused', 'Sheets'];
        $widths = [52, 14, 16, 18, 16, 16, 14, 16, 16];
        self::tableHeader($pdf, $head, $widths);
        foreach ($group['items'] as $g) {
            self::tableRow($pdf, [
                $g['group_key'],
                (string)$g['days'],
                (string)$g['marked'],
                (string)$g['attended'],
                $g['rate'] === null ? '—' : $g['rate'] . '%',
                (string)$g['absent'],
                (string)$g['late'],
                (string)$g['excused'],
                (string)$g['packets'],
            ], $widths);
        }

        // Daily trend for this source (bounded).
        $pdf->Ln(3);
        self::sectionTitle($pdf, 'Daily Trend (' . $label . ')');
        $trend = InfoAnalyticsService::trends($conn, $source, $from, $to);
        $items = array_slice($trend['items'], 0, 45);
        if (empty($items)) {
            $pdf->SetFont(self::FONT, '', 10);
            $pdf->Cell(0, 8, 'No recorded days in this window.', 0, 1);
            return;
        }
        $head = ['Date', 'Marked', 'Attended', 'Absent', 'Rate'];
        $widths = [40, 22, 22, 22, 22];
        self::tableHeader($pdf, $head, $widths);
        foreach ($items as $r) {
            self::tableRow($pdf, [
                $r['date'],
                (string)$r['marked'],
                (string)$r['attended'],
                (string)$r['absent'],
                $r['rate'] === null ? '—' : $r['rate'] . '%',
            ], $widths);
        }
    }

    /** One member's history under each source's own heading (never merged). */
    private static function renderMember(\TCPDF $pdf, \mysqli $conn, int $memberId, ?string $from, ?string $to): void
    {
        $stmt = $conn->prepare(
            "SELECT id, member_code, student_name, father_name, grandfather_name,
                    status, current_section
             FROM members WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) {
            $pdf->SetFont(self::FONT, '', 10);
            $pdf->Cell(0, 8, 'Member not found.', 0, 1);
            return;
        }

        $name = trim($member['student_name'] . ' ' . $member['father_name'] . ' ' . $member['grandfather_name']);
        self::sectionTitle($pdf, 'Member: ' . $name);
        $pdf->SetFont(self::FONT, '', 9.5);
        $pdf->Cell(0, 6, 'Code: ' . ($member['member_code'] ?: '—')
            . '    Status: ' . ($member['status'] ?: '—')
            . '    Section: ' . ($member['current_section'] ?: '—'), 0, 1);
        $pdf->Ln(2);

        [$fromD, $toD] = self::window($from, $to);
        $pdf->SetFont(self::FONT_BOLD, '', 9.5);
        $pdf->Cell(0, 6, 'Window: ' . $fromD . ' to ' . $toD, 0, 1);

        // Education (class-based) history
        self::memberSourceTable(
            $pdf,
            'Education (class-based, recorded by teachers)',
            self::fetchHistory(
                $conn,
                "SELECT a.attendance_date, a.status, COALESCE(c.class_name, '—') AS grp, a.notes
                 FROM attendance a LEFT JOIN classes c ON c.id = a.class_id
                 WHERE a.member_id = ? AND a.attendance_date BETWEEN ? AND ?
                 ORDER BY a.attendance_date DESC LIMIT " . self::MAX_MEMBER_ROWS,
                [$memberId, $fromD, $toD]
            )
        );

        // Mezmur (section-based) history
        self::memberSourceTable(
            $pdf,
            'Mezmur (section-based, recorded by mezmur takers)',
            self::fetchHistory(
                $conn,
                "SELECT m.attendance_date, m.status,
                        COALESCE(NULLIF(TRIM(mb.current_section), ''), '—') AS grp, m.notes
                 FROM mezmur_attendance m
                 LEFT JOIN members mb ON mb.id = m.member_id
                 WHERE m.member_id = ? AND m.attendance_date BETWEEN ? AND ?
                 ORDER BY m.attendance_date DESC LIMIT " . self::MAX_MEMBER_ROWS,
                [$memberId, $fromD, $toD]
            )
        );

        // HR (section-based) history
        self::memberSourceTable(
            $pdf,
            'HR (section-based, recorded by HR takers)',
            self::fetchHistory(
                $conn,
                "SELECT attendance_date, status, section AS grp, notes
                 FROM hr_attendance
                 WHERE member_id = ? AND attendance_date BETWEEN ? AND ?
                 ORDER BY attendance_date DESC LIMIT " . self::MAX_MEMBER_ROWS,
                [$memberId, $fromD, $toD]
            )
        );
    }

    // ────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────

    private static function fetchHistory(\mysqli $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private static function memberSourceTable(\TCPDF $pdf, string $title, array $rows): void
    {
        $pdf->Ln(3);
        self::sectionTitle($pdf, $title);
        if (empty($rows)) {
            $pdf->SetFont(self::FONT, '', 9.5);
            $pdf->Cell(0, 6, 'No records in this window.', 0, 1);
            return;
        }
        $head = ['Date', 'Status', 'Group/Section', 'Notes'];
        $widths = [30, 22, 44, 82];
        self::tableHeader($pdf, $head, $widths);
        foreach ($rows as $r) {
            self::tableRow($pdf, [
                (string)$r['attendance_date'],
                (string)$r['status'],
                (string)($r['grp'] ?? '—'),
                (string)($r['notes'] ?? ''),
            ], $widths);
        }
    }

    private static function newPdf(string $title): \TCPDF
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SSMS Information Department');
        $pdf->SetAuthor('SSMS');
        $pdf->SetTitle($title);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetHeaderMargin(6);
        $pdf->SetFooterMargin(8);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setFontSubsetting(true);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetFont(self::FONT, '', 9.5);
        return $pdf;
    }

    private static function pageHeader(\TCPDF $pdf, string $title, ?string $from, ?string $to): void
    {
        $pdf->AddPage();
        $pdf->SetFont(self::FONT_BOLD, '', 15);
        $pdf->Cell(0, 9, 'የመረጃ ክፍል — Information Department', 0, 1, 'C');
        $pdf->SetFont(self::FONT, '', 10);
        $pdf->Cell(0, 6, 'Attendance Analytics Hub (read-only)', 0, 1, 'C');
        $pdf->Ln(2);
        $pdf->SetFont(self::FONT_BOLD, '', 12);
        $pdf->Cell(0, 8, $title, 0, 1);
        $pdf->SetFont(self::FONT, '', 9);
        $pdf->Cell(0, 5, 'Generated: ' . date('Y-m-d H:i'), 0, 1);
        $pdf->Ln(2);
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
        $pdf->Ln(3);
    }

    private static function sectionTitle(\TCPDF $pdf, string $title): void
    {
        $pdf->Ln(2);
        $pdf->SetFont(self::FONT_BOLD, '', 11);
        $pdf->SetFillColor(236, 244, 248);
        $pdf->Cell(0, 7, ' ' . $title, 0, 1, 'L', true);
        $pdf->Ln(1);
    }

    private static function tableHeader(\TCPDF $pdf, array $cols, array $widths): void
    {
        $pdf->SetFont(self::FONT_BOLD, '', 8.5);
        $pdf->SetFillColor(224, 229, 234);
        foreach ($cols as $i => $c) {
            $pdf->Cell($widths[$i], 6.5, ' ' . $c, 1, 0, 'L', true);
        }
        $pdf->Ln();
    }

    private static function tableRow(\TCPDF $pdf, array $cells, array $widths): void
    {
        $pdf->SetFont(self::FONT, '', 8.5);
        foreach ($cells as $i => $c) {
            $pdf->Cell($widths[$i], 6, ' ' . $c, 1, 0, 'L', false);
        }
        $pdf->Ln();
    }

    private static function pageFooterNote(\TCPDF $pdf): void
    {
        // rendered on last page bottom
    }

    private static function clampDate(?string $value): ?string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private static function window(?string $from, ?string $to): array
    {
        $to = self::clampDate($to) ?? date('Y-m-d');
        $from = self::clampDate($from) ?? date('Y-m-d', strtotime('-29 days', strtotime($to)));
        if (strtotime($to) - strtotime($from) > 366 * 86400) {
            $from = date('Y-m-d', strtotime($to . ' -366 days'));
        }
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }
}
