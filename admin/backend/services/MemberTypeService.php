<?php
/**
 * Membership tier registry (regular / special_regular / honorary).
 *
 * The ENUM keys are stable system identifiers (reports, mobile sync and
 * filters reference them); the human-facing Amharic/English labels live
 * in `member_type_settings` (sql/019) and are editable from the Super
 * Admin panel. Every reader goes through this service and degrades to
 * hard-coded defaults when the table is missing, so deployments before
 * migration 019 keep working unchanged.
 */
namespace App\Services;

final class MemberTypeService
{
    public const KEYS = ['regular', 'special_regular', 'honorary'];

    private const FALLBACK = [
        'regular'         => ['am' => 'መደበኛ',   'en' => 'Regular'],
        'special_regular' => ['am' => 'ልዩ መደበኛ', 'en' => 'Special Regular'],
        'honorary'        => ['am' => 'የክብር አባል', 'en' => 'Honorary'],
    ];

    /**
     * @return array<string,array{am:string,en:string}> keyed by type_key
     */
    public static function labels(\mysqli $conn): array
    {
        $out = self::FALLBACK;
        try {
            $result = $conn->query(
                'SELECT type_key, label_am, label_en FROM member_type_settings ORDER BY sort_order ASC'
            );
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $key = (string)$row['type_key'];
                    if (in_array($key, self::KEYS, true)) {
                        $out[$key] = [
                            'am' => (string)$row['label_am'],
                            'en' => (string)$row['label_en'],
                        ];
                    }
                }
                $result->free();
            }
        } catch (\Throwable $error) {
            // Table may not exist pre-migration; fallbacks apply.
        }
        return $out;
    }

    public static function labelAm(\mysqli $conn, string $key): string
    {
        $labels = self::labels($conn);
        return $labels[$key]['am'] ?? (self::FALLBACK[$key]['am'] ?? $key);
    }

    public static function labelEn(\mysqli $conn, string $key): string
    {
        $labels = self::labels($conn);
        return $labels[$key]['en'] ?? (self::FALLBACK[$key]['en'] ?? $key);
    }

    /**
     * Validate and persist a label edit from the Super Admin panel.
     * @return array{status:string,message:string}
     */
    public static function saveLabel(\mysqli $conn, string $key, string $labelAm, string $labelEn): array
    {
        if (!in_array($key, self::KEYS, true)) {
            return ['status' => 'error', 'message' => 'Unknown membership type.'];
        }
        $labelAm = trim($labelAm);
        $labelEn = trim($labelEn);
        if ($labelAm === '' || $labelEn === '') {
            return ['status' => 'error', 'message' => 'Both labels are required.'];
        }
        if (mb_strlen($labelAm) > 150 || mb_strlen($labelEn) > 150) {
            return ['status' => 'error', 'message' => 'Labels are too long.'];
        }
        try {
            $stmt = $conn->prepare(
                'UPDATE member_type_settings SET label_am = ?, label_en = ? WHERE type_key = ?'
            );
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Membership settings are not installed yet (run sql/019).'];
            }
            $stmt->bind_param('sss', $labelAm, $labelEn, $key);
            $ok = $stmt->execute();
            $stmt->close();
        } catch (\Throwable $error) {
            // PHP >= 8.1 mysqli strict reporting throws when the table is
            // missing (pre-migration deployment) or the write fails.
            return ['status' => 'error', 'message' => 'Membership settings are not installed yet (run sql/019).'];
        }
        return $ok
            ? ['status' => 'success', 'message' => 'Membership type updated.']
            : ['status' => 'error', 'message' => 'Unable to save membership type.'];
    }
}
