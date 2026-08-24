<?php
/**
 * Constant-memory presentation adapters for member reports.
 */
namespace App\Services;

use PDOStatement;
use RuntimeException;

final class MemberReportRenderer
{
    private const AGE_LABELS = [
        'under6' => 'Under 6',
        '7_13' => '7-13',
        '14_17' => '14-17',
        '18_plus' => '18+',
    ];

    /**
     * @param array{total:int,male:int,female:int,active:int,warning:int} $summary
     */
    public static function streamCsv(
        PDOStatement $rows,
        array $summary,
        string $filename,
        bool $truncated
    ): void {
        self::beginResponse(
            'text/csv; charset=utf-8',
            'attachment; filename="' . self::safeFilename($filename, 'members.csv') . '"',
            false,
            $truncated
        );
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not open the report output stream.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        self::csv($output, [
            '#', 'Code', 'Student Name', 'Father Name', 'Grandfather',
            'Christian Name (የክርስትና ስም)', 'Gender', 'Age Group', 'Phone',
            'Alt Phone', 'Guardian', 'Guardian Ph1', 'Guardian Ph2', 'City',
            'Sub City', 'Woreda', 'Profession', 'Education', 'Reg Type',
            'Member Type', 'Status',
        ]);

        $number = 0;
        while (($member = MemberReportService::nextRow($rows)) !== null) {
            $number++;
            self::csv($output, [
                $number,
                self::spreadsheetSafe($member['member_code']),
                self::spreadsheetSafe($member['student_name']),
                self::spreadsheetSafe($member['father_name']),
                self::spreadsheetSafe($member['grandfather_name']),
                self::spreadsheetSafe($member['baptismal_name']),
                self::spreadsheetSafe($member['gender']),
                self::spreadsheetSafe($member['age_group']),
                self::spreadsheetSafe($member['phone_number']),
                self::spreadsheetSafe($member['alt_phone_number']),
                self::spreadsheetSafe($member['guardian_name']),
                self::spreadsheetSafe($member['guardian_phone1']),
                self::spreadsheetSafe($member['guardian_phone2']),
                self::spreadsheetSafe($member['city']),
                self::spreadsheetSafe($member['sub_city']),
                self::spreadsheetSafe($member['woreda']),
                self::spreadsheetSafe($member['work_profession']),
                self::spreadsheetSafe($member['education_level']),
                self::spreadsheetSafe($member['registration_type']),
                self::spreadsheetSafe($member['member_type']),
                self::spreadsheetSafe($member['status']),
            ]);
            if (($number % 250) === 0) {
                fflush($output);
                if (connection_aborted()) {
                    break;
                }
            }
        }
        $rows->closeCursor();
        fclose($output);
    }

    /**
     * Preserve the existing Word-compatible HTML contract without collecting
     * rows in memory.
     *
     * @param array{total:int,male:int,female:int,active:int,warning:int} $summary
     */
    public static function streamWord(
        PDOStatement $rows,
        array $summary,
        string $title,
        string $generatedAt,
        string $filename,
        bool $truncated
    ): void {
        self::beginResponse(
            'application/msword; charset=utf-8',
            'attachment; filename="' . self::safeFilename($filename, 'members.doc') . '"',
            true,
            $truncated
        );
        echo '<!DOCTYPE html><html xmlns:o="urn:schemas-microsoft-com:office:office" '
            . 'xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"><style>';
        echo '@page{size:A4 landscape;margin:1cm}body{font-family:Calibri,"Noto Sans Ethiopic",sans-serif;font-size:10pt;margin:0}'
            . 'h1{color:#166534;font-size:16pt;border-bottom:3px solid #16a34a;padding-bottom:6pt;margin-bottom:4pt}'
            . '.meta{color:#475569;font-size:8pt;margin-bottom:10pt}.notice{padding:6pt;background:#fff7ed;border:1px solid #fdba74;margin:6pt 0}'
            . '.summary span{display:inline-block;padding:3pt 10pt;background:#f0fdf4;border:1px solid #d1fae5;margin:2pt;font-size:9pt}'
            . '.summary .n{font-weight:bold;color:#166534;font-size:11pt}table{border-collapse:collapse;width:100%;margin-top:8pt}'
            . 'th{background:#166534;color:white;font-size:8pt;padding:5pt 6pt;text-align:left}'
            . 'td{border-bottom:1px solid #e2e8f0;padding:4pt 6pt;font-size:8pt}tr:nth-child(even) td{background:#f0fdf4}'
            . '</style></head><body>';
        echo '<h1>' . self::h($title) . '</h1><p class="meta">Generated: '
            . self::h($generatedAt) . ' | Matching members: ' . $summary['total'] . '</p>';
        self::truncationNotice($truncated, $summary['total']);
        self::summaryMarkup($summary);
        echo '<table><thead><tr><th>#</th><th>Name</th><th>Code</th><th>Gender</th><th>Age Grp</th>'
            . '<th>Phone</th><th>City</th><th>Sub-City</th><th>Status</th><th>Reg Type</th>'
            . '<th>Education</th><th>Profession</th></tr></thead><tbody>';

        $number = 0;
        while (($member = MemberReportService::nextRow($rows)) !== null) {
            $number++;
            echo self::tableRow($member, $number, false);
            self::flushEvery($number);
            if (connection_aborted()) {
                break;
            }
        }
        $rows->closeCursor();
        echo '</tbody></table></body></html>';
    }

    /**
     * Stream the existing print-ready HTML/PDF adapter.
     *
     * @param array{total:int,male:int,female:int,active:int,warning:int} $summary
     */
    public static function streamPrintPage(
        PDOStatement $rows,
        array $summary,
        string $title,
        string $generatedAt,
        string $csvUrl,
        string $schoolLine,
        string $footer,
        bool $truncated,
        bool $autoPrint
    ): void {
        self::beginResponse('text/html; charset=utf-8', '', true, $truncated);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . self::h($title) . '</title><style>';
        echo '@page{size:A4 landscape;margin:8mm 10mm}@media print{body{margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.no-print{display:none!important}table{page-break-inside:auto}tr{page-break-inside:avoid}thead{display:table-header-group}}'
            . '*{box-sizing:border-box}body{font-family:"Noto Serif Ethiopic","Segoe UI",Calibri,sans-serif;font-size:9pt;color:#1e293b;margin:0}'
            . '.header{background:#166534;color:white;padding:14px 20px;margin-bottom:8px}.header h1{font-size:16pt;margin:0 0 2px}.meta{font-size:8pt;opacity:.9}'
            . '.summary{display:flex;flex-wrap:wrap;gap:6px;padding:6px 20px 12px}.summary span{padding:5px 14px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0}'
            . '.summary .n{color:#166534;font-size:12pt;font-weight:700}.notice{margin:8px 20px;padding:9px 12px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;color:#9a3412}'
            . 'table{width:100%;border-collapse:collapse;margin:0 auto}th{background:#166534;color:white;font-size:7pt;padding:6px 5px;text-align:left;text-transform:uppercase;white-space:nowrap}'
            . 'td{padding:5px;font-size:8pt;border-bottom:1px solid #e7e7e7}tr:nth-child(even) td{background:#f8fdf9}'
            . '.status{padding:2px 8px;border-radius:10px;font-size:7pt;font-weight:600}.s-active{background:#d1fae5;color:#065f46}'
            . '.s-warning{background:#fef3c7;color:#92400e}.s-inactive{background:#fee2e2;color:#991b1b}.s-other{background:#e2e8f0;color:#334155}'
            . '.toolbar{padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.btn{display:inline-block;padding:10px 20px;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;border:0;text-decoration:none}'
            . '.green{background:#166534;color:white}.blue{background:#1d4ed8;color:white}.gray{background:#f1f5f9;color:#334155}'
            . '.footer{text-align:center;padding:10px;font-size:7pt;color:#64748b;border-top:1px solid #e2e8f0;margin-top:10px}'
            . '</style></head><body>';
        echo '<div class="no-print toolbar"><button type="button" onclick="window.print()" class="btn green">Print / Save as PDF</button>'
            . '<a href="' . self::h($csvUrl) . '" class="btn blue">Download CSV Instead</a>'
            . '<a href="/admin/reports.php" class="btn gray">Back to Reports</a>'
            . '<span style="color:#475569;font-size:11px">In the print dialog, choose “Save as PDF”.</span></div>';
        echo '<div class="header"><h1>' . self::h($title) . '</h1><div class="meta">'
            . self::h($schoolLine) . ' | Generated: ' . self::h($generatedAt)
            . ' | Matching members: ' . $summary['total'] . '</div></div>';
        self::truncationNotice($truncated, $summary['total']);
        self::summaryMarkup($summary);
        echo '<table><thead><tr><th>#</th><th>Name</th><th>Code</th><th>Gender</th><th>Age Grp</th>'
            . '<th>Phone</th><th>City</th><th>Sub-City</th><th>Status</th><th>Reg Type</th>'
            . '<th>Education</th><th>Profession</th></tr></thead><tbody>';

        $number = 0;
        while (($member = MemberReportService::nextRow($rows)) !== null) {
            $number++;
            echo self::tableRow($member, $number, true);
            self::flushEvery($number);
            if (connection_aborted()) {
                break;
            }
        }
        $rows->closeCursor();
        echo '</tbody></table><div class="footer">' . self::h($footer)
            . ' — Report generated on ' . self::h($generatedAt) . '</div>';
        if ($autoPrint) {
            echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},500);});</script>';
        }
        echo '</body></html>';
    }

    /** @param array<string,string> $member */
    private static function tableRow(array $member, int $number, bool $statusStyle): string
    {
        $name = trim($member['student_name'] . ' ' . $member['father_name'] . ' ' . $member['grandfather_name']);
        $age = self::AGE_LABELS[$member['age_group']] ?? $member['age_group'];
        $gender = $member['gender'] === 'male' ? 'M' : ($member['gender'] === 'female' ? 'F' : '');
        $status = self::h($member['status']);
        if ($statusStyle) {
            $class = in_array($member['status'], ['active', 'warning', 'inactive'], true)
                ? 's-' . $member['status']
                : 's-other';
            $status = '<span class="status ' . $class . '">' . $status . '</span>';
        }
        return '<tr><td>' . $number . '</td><td><strong>' . self::h($name) . '</strong></td>'
            . '<td>' . self::h($member['member_code']) . '</td><td>' . $gender . '</td><td>' . self::h($age) . '</td>'
            . '<td>' . self::h($member['phone_number']) . '</td><td>' . self::h($member['city']) . '</td>'
            . '<td>' . self::h($member['sub_city']) . '</td><td>' . $status . '</td>'
            . '<td>' . self::h($member['registration_type']) . '</td><td>' . self::h($member['education_level']) . '</td>'
            . '<td>' . self::h($member['work_profession']) . '</td></tr>';
    }

    /** @param array{total:int,male:int,female:int,active:int,warning:int} $summary */
    private static function summaryMarkup(array $summary): void
    {
        echo '<div class="summary"><span><span class="n">' . $summary['total'] . '</span> Total</span>'
            . '<span><span class="n">' . $summary['male'] . '</span> Male</span>'
            . '<span><span class="n">' . $summary['female'] . '</span> Female</span>'
            . '<span><span class="n">' . $summary['active'] . '</span> Active</span>'
            . '<span><span class="n">' . $summary['warning'] . '</span> Warning</span></div>';
    }

    private static function truncationNotice(bool $truncated, int $total): void
    {
        if (!$truncated) {
            return;
        }
        echo '<div class="notice">This printable report shows the first '
            . number_format(MemberReportService::MAX_ROWS) . ' of ' . number_format($total)
            . ' matching members. Use the Complete CSV export for the entire roster.</div>';
    }

    private static function beginResponse(
        string $contentType,
        string $contentDisposition,
        bool $html,
        bool $truncated
    ): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @set_time_limit(0);
        header('Content-Type: ' . $contentType);
        if ($contentDisposition !== '') {
            header('Content-Disposition: ' . $contentDisposition);
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Report-Row-Limit: ' . MemberReportService::MAX_ROWS);
        header('X-Report-Truncated: ' . ($truncated ? 'true' : 'false'));
        if ($html) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        } else {
            header("Content-Security-Policy: default-src 'none'; sandbox");
        }
        ob_implicit_flush(true);
    }

    /** @param resource $output @param array<int,mixed> $values */
    private static function csv($output, array $values): void
    {
        if (fputcsv($output, $values, ',', '"', '') === false) {
            throw new RuntimeException('Could not write a report row.');
        }
    }

    private static function spreadsheetSafe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safeFilename(string $filename, string $fallback): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return is_string($safe) && $safe !== '' ? $safe : $fallback;
    }

    private static function flushEvery(int $number): void
    {
        if (($number % 250) === 0) {
            flush();
        }
    }
}
