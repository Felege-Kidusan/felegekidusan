<?php
/**
 * ID card layout — one place for sizes, positions, and colours.
 * Super Admin Branding saves here. Printed cards read the same values.
 */
namespace App\Services;

class IdCardLayout
{
    public const CANVAS_W = 1011;
    public const CANVAS_H = 638;

    /** @return array<string,int|string> */
    public static function defaults(): array
    {
        return [
            'logo_x' => 22,
            'logo_y' => 14,
            'logo_size' => 118,
            'logo_opacity' => 100,
            'seal_x' => 830,
            'seal_y' => 390,
            'seal_size' => 150,
            'seal_opacity' => 85,
            'sig_head_size' => 140,
            'sig_head_opacity' => 90,
            'sig_admin_size' => 140,
            'sig_admin_opacity' => 90,
            'title_size' => 24,
            'parish_size' => 26,
            'label_size' => 20,
            'value_size' => 24,
            'code_size' => 32,
            'bar_height' => 38,
            'photo_w' => 210,
            'photo_h' => 260,
            'qr_size' => 220,
            'label_color' => '#600000',
            'title_color' => '#600000',
            'gold_color' => '#B8860B',
            'bar_color' => '#600000',
            'value_color' => '#1A0A0A',
        ];
    }

    /** @return list<string> */
    public static function intKeys(): array
    {
        return [
            'logo_x', 'logo_y', 'logo_size', 'logo_opacity',
            'seal_x', 'seal_y', 'seal_size', 'seal_opacity',
            'sig_head_size', 'sig_head_opacity', 'sig_admin_size', 'sig_admin_opacity',
            'title_size', 'parish_size', 'label_size', 'value_size', 'code_size',
            'bar_height', 'photo_w', 'photo_h', 'qr_size',
        ];
    }

    /** @return list<string> */
    public static function colorKeys(): array
    {
        return ['label_color', 'title_color', 'gold_color', 'bar_color', 'value_color'];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,int|string>
     */
    public static function sanitize(array $in): array
    {
        $out = self::defaults();
        $ranges = [
            'logo_x' => [0, 900], 'logo_y' => [0, 500], 'logo_size' => [48, 260], 'logo_opacity' => [10, 100],
            'seal_x' => [0, 940], 'seal_y' => [0, 560], 'seal_size' => [40, 280], 'seal_opacity' => [10, 100],
            'sig_head_size' => [40, 280], 'sig_head_opacity' => [10, 100],
            'sig_admin_size' => [40, 280], 'sig_admin_opacity' => [10, 100],
            'title_size' => [14, 40], 'parish_size' => [14, 40],
            'label_size' => [12, 32], 'value_size' => [14, 40], 'code_size' => [18, 48],
            'bar_height' => [16, 64], 'photo_w' => [120, 320], 'photo_h' => [140, 360], 'qr_size' => [120, 300],
        ];
        foreach (self::intKeys() as $k) {
            if (!isset($in[$k]) || !is_numeric($in[$k])) {
                continue;
            }
            $v = (int)$in[$k];
            $r = $ranges[$k] ?? [0, 1000];
            $out[$k] = max($r[0], min($r[1], $v));
        }
        foreach (self::colorKeys() as $k) {
            if (!isset($in[$k]) || !is_string($in[$k])) {
                continue;
            }
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $in[$k])) {
                $out[$k] = strtoupper($in[$k]);
            }
        }
        return $out;
    }

    /** @return array<string,int|string> */
    public static function load(\mysqli $conn): array
    {
        $out = self::defaults();
        try {
            $chk = $conn->query("SHOW TABLES LIKE 'system_branding'");
            if (!$chk || $chk->num_rows === 0) {
                return $out;
            }
            $stmt = $conn->prepare("SELECT original_name FROM system_branding WHERE asset_key = '_id_card_settings' LIMIT 1");
            if (!$stmt) {
                return $out;
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row || empty($row['original_name'])) {
                return $out;
            }
            $decoded = json_decode((string)$row['original_name'], true);
            if (is_array($decoded)) {
                $out = self::sanitize($decoded);
            }
        } catch (\Throwable $e) {
            return self::defaults();
        }
        return $out;
    }

    public static function background(\mysqli $conn): string
    {
        $fallback = defined('ID_CARD_BACKGROUND')
            ? ID_CARD_BACKGROUND
            : '/admin/id_cards/assets/backgrounds/id_card_bg.jpg';
        try {
            $stmt = $conn->prepare("SELECT file_path FROM system_branding WHERE asset_key = 'card_bg' LIMIT 1");
            if (!$stmt) {
                return $fallback;
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['file_path'])) {
                $path = (string)$row['file_path'];
                $disk = dirname(__DIR__, 2) . $path;
                if (is_file($disk) || is_file(($_SERVER['DOCUMENT_ROOT'] ?? '') . $path)) {
                    return $path;
                }
            }
        } catch (\Throwable $e) {
            return $fallback;
        }
        return $fallback;
    }

    /**
     * @param array<string,int|string> $s
     */
    public static function cssVars(array $s, string $background = ''): string
    {
        $s = self::sanitize($s);
        if ($background === '') {
            $background = defined('ID_CARD_BACKGROUND')
                ? ID_CARD_BACKGROUND
                : '/admin/id_cards/assets/backgrounds/id_card_bg.jpg';
        }
        $bgUrl = 'url(\'' . str_replace(["'", '\\'], '', $background) . '\')';
        $parts = [
            '--id-bg:' . $bgUrl,
            '--id-logo-x:' . (int)$s['logo_x'] . 'px',
            '--id-logo-y:' . (int)$s['logo_y'] . 'px',
            '--id-logo-size:' . (int)$s['logo_size'] . 'px',
            '--id-logo-opacity:' . (max(10, (int)$s['logo_opacity']) / 100),
            '--id-seal-x:' . (int)$s['seal_x'] . 'px',
            '--id-seal-y:' . (int)$s['seal_y'] . 'px',
            '--id-seal-size:' . (int)$s['seal_size'] . 'px',
            '--id-seal-opacity:' . (max(10, (int)$s['seal_opacity']) / 100),
            '--id-sig-head:' . (int)$s['sig_head_size'] . 'px',
            '--id-sig-head-op:' . (max(10, (int)$s['sig_head_opacity']) / 100),
            '--id-sig-admin:' . (int)$s['sig_admin_size'] . 'px',
            '--id-sig-admin-op:' . (max(10, (int)$s['sig_admin_opacity']) / 100),
            '--id-title-size:' . (int)$s['title_size'] . 'px',
            '--id-parish-size:' . (int)$s['parish_size'] . 'px',
            '--id-label-size:' . (int)$s['label_size'] . 'px',
            '--id-value-size:' . (int)$s['value_size'] . 'px',
            '--id-code-size:' . (int)$s['code_size'] . 'px',
            '--id-bar-height:' . (int)$s['bar_height'] . 'px',
            '--id-photo-w:' . (int)$s['photo_w'] . 'px',
            '--id-photo-h:' . (int)$s['photo_h'] . 'px',
            '--id-qr-size:' . (int)$s['qr_size'] . 'px',
            '--id-label-color:' . $s['label_color'],
            '--id-title-color:' . $s['title_color'],
            '--id-gold-color:' . $s['gold_color'],
            '--id-bar-color:' . $s['bar_color'],
            '--id-value-color:' . $s['value_color'],
        ];
        return implode(';', $parts);
    }
}
