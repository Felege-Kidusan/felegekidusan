<?php
/**
 * ============================================================================
 * ReportExportService — server‑side file generation for the Reporting Centre.
 * ============================================================================
 *
 * Formats
 *   csv  – streamed, UTF‑8 BOM, spreadsheet‑formula neutralised (CWE‑1236)
 *   xlsx – streamed through openspout (constant memory) when the vendored
 *          package and its extensions (zip, xmlwriter) are available;
 *          otherwise the caller falls back to CSV with an explanatory header.
 *
 * All exports go through ReportQueryService::openExport(), so field‑level
 * export permissions and the 5 000‑row ceiling are applied before any byte
 * is written. Nothing here is reachable without the audit row written by the
 * endpoint first.
 */

declare(strict_types=1);

namespace App\Reporting;

use PDO;
use PDOStatement;
use RuntimeException;

require_once __DIR__ . '/../ethiopian_date.php';

final class ReportExportService
{
    public const FORMATS = ['csv', 'xlsx'];

    /** Whether streamed XLSX can be produced on this host. */
    public static function xlsxAvailable(): bool
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return false;
        }
        if (!extension_loaded('zip') || !extension_loaded('xmlwriter') || !extension_loaded('dom')) {
            return false;
        }
        require_once $autoload;
        return class_exists(\OpenSpout\Writer\XLSX\Writer::class);
    }

    public static function safeFilename(string $base, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $base) ?? 'report';
        $base = trim($base, '_') ?: 'report';
        return substr($base, 0, 60) . '_' . date('Ymd_His') . '.' . $ext;
    }

    private static function commonHeaders(string $contentType, string $filename, int $rowLimit): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Report-Row-Limit: ' . $rowLimit);
        header("Content-Security-Policy: default-src 'none'; sandbox");
    }

    /** Neutralise spreadsheet formulas in text cells. */
    public static function spreadsheetSafe(mixed $value): string
    {
        $s = $value === null ? '' : (string)$value;
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/u', $s)) {
            return "'" . $s;
        }
        return $s;
    }

    /** Present a raw DB value using the registry (labels for enums/bools). */
    public static function present(array $col, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($col['type'] === 'bool') {
            return ((int)$value === 1) ? 'Yes' : 'No';
        }
        if ($col['type'] === 'date') {
            // Ethiopian calendar only in every user-facing output (exports included).
            $s = (string)$value;
            return strlen($s) > 10 ? \ethio_export_datetime($s) : \ethio_export_date($s);
        }
        if (is_array($col['options']) && isset($col['options'][(string)$value])) {
            return (string)$col['options'][(string)$value];
        }
        return (string)$value;
    }

    /**
     * @param array{statement:PDOStatement,columns:array} $export  from openExport()
     * @return int rows written
     */
    public static function streamCsv(array $export, string $filename, string $lang = 'en'): int
    {
        self::commonHeaders('text/csv; charset=utf-8', $filename, ReportRegistry::MAX_EXPORT_ROWS);
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not open output.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        $cols = $export['columns'];
        fputcsv($out, array_map(static fn (array $c): string => $c[$lang === 'am' ? 'label_am' : 'label_en'], $cols), ',', '"', '');
        $n = 0;
        /** @var PDOStatement $st */
        $st = $export['statement'];
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $line = [];
            foreach ($cols as $c) {
                $line[] = self::spreadsheetSafe(self::present($c, $row[$c['key']] ?? null));
            }
            fputcsv($out, $line, ',', '"', '');
            $n++;
            if (($n % 500) === 0) {
                flush();
            }
        }
        fclose($out);
        return $n;
    }

    /**
     * Streamed XLSX via openspout. Caller must have checked xlsxAvailable().
     * @return int rows written
     */
    public static function streamXlsx(array $export, string $filename, string $lang = 'en', string $title = 'Members'): int
    {
        self::commonHeaders('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename, ReportRegistry::MAX_EXPORT_ROWS);

        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToBrowser($filename);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', $title) ?? 'Report', 0, 31));

        $bold = (new \OpenSpout\Common\Entity\Style\Style())->setFontBold();
        $cols = $export['columns'];
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(
            array_map(static fn (array $c): string => $c[$lang === 'am' ? 'label_am' : 'label_en'], $cols),
            $bold
        ));

        $n = 0;
        /** @var PDOStatement $st */
        $st = $export['statement'];
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $cells = [];
            foreach ($cols as $c) {
                $raw = $row[$c['key']] ?? null;
                if ($c['type'] === 'int' && $raw !== null && is_numeric($raw)) {
                    $cells[] = (int)$raw;
                } else {
                    $cells[] = self::spreadsheetSafe(self::present($c, $raw));
                }
            }
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($cells));
            $n++;
        }
        $writer->close();
        return $n;
    }
}
