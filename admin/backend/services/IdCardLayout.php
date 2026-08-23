<?php
/**
 * ID card layout — one place for sizes, colours, and underlines.
 * Super Admin Branding saves here. Printed cards read the same values.
 */
namespace App\Services;

class IdCardLayout
{
    public const CANVAS_W = 1011;
    public const CANVAS_H = 638;

    /** @return list<array<string,mixed>> */
    public static function elements(): array
    {
        $text = static function (string $id, string $label, string $side, int $size, string $colorKey = 'title_color'): array {
            return [
                'id' => $id,
                'label' => $label,
                'side' => $side,
                'controls' => [
                    ['k' => $id . '_size', 'label' => 'Size', 'type' => 'int', 'min' => 10, 'max' => 56, 'def' => $size],
                    ['k' => $id . '_color', 'label' => 'Colour', 'type' => 'color', 'def_from' => $colorKey],
                ],
            ];
        };
        $row = static function (string $id, string $label, string $side, bool $isCode = false): array {
            return [
                'id' => $id,
                'label' => $label,
                'side' => $side,
                'controls' => [
                    ['k' => $id . '_lbl_size', 'label' => 'Label size', 'type' => 'int', 'min' => 10, 'max' => 40, 'def_from' => 'label_size'],
                    ['k' => $id . '_lbl_color', 'label' => 'Label colour', 'type' => 'color', 'def_from' => 'label_color'],
                    ['k' => $id . '_val_size', 'label' => $isCode ? 'Number size' : 'Value size', 'type' => 'int', 'min' => 12, 'max' => 56, 'def_from' => $isCode ? 'code_size' : 'value_size'],
                    ['k' => $id . '_val_color', 'label' => $isCode ? 'Number colour' : 'Value colour', 'type' => 'color', 'def_from' => $isCode ? 'title_color' : 'value_color'],
                    ['k' => $id . '_line', 'label' => 'Underline thickness', 'type' => 'int', 'min' => 0, 'max' => 10, 'def' => 2],
                    ['k' => $id . '_line_color', 'label' => 'Underline colour', 'type' => 'color', 'def_from' => 'title_color'],
                ],
            ];
        };

        return [
            [
                'id' => 'logo', 'label' => 'Logo', 'side' => 'front',
                'controls' => [
                    ['k' => 'logo_x', 'label' => 'Left', 'type' => 'int', 'min' => 0, 'max' => 900, 'def' => 22],
                    ['k' => 'logo_y', 'label' => 'Top', 'type' => 'int', 'min' => 0, 'max' => 500, 'def' => 14],
                    ['k' => 'logo_size', 'label' => 'Size', 'type' => 'int', 'min' => 48, 'max' => 260, 'def' => 118],
                    ['k' => 'logo_opacity', 'label' => 'Opacity', 'type' => 'int', 'min' => 10, 'max' => 100, 'def' => 100],
                ],
            ],
            $text('invoc', 'Invocation', 'front', 15),
            $text('parish', 'Parish name', 'front', 26),
            $text('title', 'Amharic title', 'both', 24),
            [
                'id' => 'title_en', 'label' => 'English title', 'side' => 'both',
                'controls' => [
                    ['k' => 'title_en_size', 'label' => 'Size', 'type' => 'int', 'min' => 10, 'max' => 40, 'def' => 16],
                    ['k' => 'title_en_color', 'label' => 'Colour', 'type' => 'color', 'def_from' => 'gold_color'],
                ],
            ],
            [
                'id' => 'header', 'label' => 'Header space', 'side' => 'both',
                'controls' => [
                    ['k' => 'header_top', 'label' => 'Top space', 'type' => 'int', 'min' => 0, 'max' => 80, 'def' => 8],
                    ['k' => 'header_bottom', 'label' => 'Bottom space', 'type' => 'int', 'min' => 0, 'max' => 80, 'def' => 6],
                    ['k' => 'header_left', 'label' => 'Left space', 'type' => 'int', 'min' => 0, 'max' => 280, 'def' => 0],
                ],
            ],
            [
                'id' => 'bar', 'label' => 'Maroon bar', 'side' => 'both',
                'controls' => [
                    ['k' => 'bar_height', 'label' => 'Height', 'type' => 'int', 'min' => 8, 'max' => 80, 'def' => 38],
                    ['k' => 'bar_color', 'label' => 'Bar colour', 'type' => 'color', 'def' => '#600000'],
                    ['k' => 'bar_gold', 'label' => 'Gold line', 'type' => 'int', 'min' => 0, 'max' => 12, 'def' => 4],
                    ['k' => 'gold_color', 'label' => 'Gold colour', 'type' => 'color', 'def' => '#B8860B'],
                    ['k' => 'bar_label_size', 'label' => 'Back label size', 'type' => 'int', 'min' => 10, 'max' => 40, 'def' => 22],
                    ['k' => 'bar_label_color', 'label' => 'Back label colour', 'type' => 'color', 'def' => '#F0C000'],
                ],
            ],
            [
                'id' => 'photo', 'label' => 'Photo', 'side' => 'front',
                'controls' => [
                    ['k' => 'photo_w', 'label' => 'Width', 'type' => 'int', 'min' => 100, 'max' => 340, 'def' => 210],
                    ['k' => 'photo_h', 'label' => 'Height', 'type' => 'int', 'min' => 120, 'max' => 380, 'def' => 260],
                    ['k' => 'photo_border', 'label' => 'Border', 'type' => 'int', 'min' => 0, 'max' => 10, 'def' => 3],
                    ['k' => 'photo_border_color', 'label' => 'Border colour', 'type' => 'color', 'def_from' => 'title_color'],
                ],
            ],
            $row('name', 'Full name', 'front'),
            $row('christian', 'Christian name', 'front'),
            $row('gender', 'Gender', 'front'),
            $row('age', 'Age', 'front'),
            $row('code', 'ID number', 'front', true),
            [
                'id' => 'sig_head', 'label' => 'Head signature', 'side' => 'front',
                'controls' => [
                    ['k' => 'sig_head_size', 'label' => 'Width', 'type' => 'int', 'min' => 40, 'max' => 280, 'def' => 140],
                    ['k' => 'sig_head_opacity', 'label' => 'Opacity', 'type' => 'int', 'min' => 10, 'max' => 100, 'def' => 90],
                    ['k' => 'sig_head_lbl_size', 'label' => 'Caption size', 'type' => 'int', 'min' => 8, 'max' => 24, 'def' => 13],
                    ['k' => 'sig_head_lbl_color', 'label' => 'Caption colour', 'type' => 'color', 'def_from' => 'label_color'],
                ],
            ],
            [
                'id' => 'sig_admin', 'label' => 'Director signature', 'side' => 'front',
                'controls' => [
                    ['k' => 'sig_admin_size', 'label' => 'Width', 'type' => 'int', 'min' => 40, 'max' => 280, 'def' => 140],
                    ['k' => 'sig_admin_opacity', 'label' => 'Opacity', 'type' => 'int', 'min' => 10, 'max' => 100, 'def' => 90],
                    ['k' => 'sig_admin_lbl_size', 'label' => 'Caption size', 'type' => 'int', 'min' => 8, 'max' => 24, 'def' => 13],
                    ['k' => 'sig_admin_lbl_color', 'label' => 'Caption colour', 'type' => 'color', 'def_from' => 'label_color'],
                ],
            ],
            [
                'id' => 'seal', 'label' => 'Seal', 'side' => 'front',
                'controls' => [
                    ['k' => 'seal_x', 'label' => 'Left', 'type' => 'int', 'min' => 0, 'max' => 940, 'def' => 830],
                    ['k' => 'seal_y', 'label' => 'Top', 'type' => 'int', 'min' => 0, 'max' => 560, 'def' => 390],
                    ['k' => 'seal_size', 'label' => 'Size', 'type' => 'int', 'min' => 40, 'max' => 280, 'def' => 150],
                    ['k' => 'seal_opacity', 'label' => 'Opacity', 'type' => 'int', 'min' => 10, 'max' => 100, 'def' => 85],
                ],
            ],
            [
                'id' => 'foot_front', 'label' => 'Front footer', 'side' => 'front',
                'controls' => [
                    ['k' => 'foot_front_size', 'label' => 'Size', 'type' => 'int', 'min' => 10, 'max' => 36, 'def' => 22],
                    ['k' => 'foot_front_color', 'label' => 'Colour', 'type' => 'color', 'def_from' => 'title_color'],
                ],
            ],
            $row('phone', 'Phone', 'back'),
            $row('address', 'Address', 'back'),
            [
                'id' => 'em_head', 'label' => 'Emergency heading', 'side' => 'back',
                'controls' => [
                    ['k' => 'em_head_size', 'label' => 'Size', 'type' => 'int', 'min' => 12, 'max' => 36, 'def' => 22],
                    ['k' => 'em_head_color', 'label' => 'Colour', 'type' => 'color', 'def_from' => 'title_color'],
                    ['k' => 'em_head_line', 'label' => 'Underline', 'type' => 'int', 'min' => 0, 'max' => 10, 'def' => 3],
                    ['k' => 'em_head_line_color', 'label' => 'Underline colour', 'type' => 'color', 'def_from' => 'gold_color'],
                ],
            ],
            $row('em_name', 'Emergency name', 'back'),
            $row('em_phone', 'Emergency phone', 'back'),
            $row('issue', 'Issue date', 'back'),
            $row('expiry', 'Expiry date', 'back'),
            [
                'id' => 'qr', 'label' => 'QR code', 'side' => 'back',
                'controls' => [
                    ['k' => 'qr_size', 'label' => 'Size', 'type' => 'int', 'min' => 100, 'max' => 320, 'def' => 220],
                    ['k' => 'qr_border', 'label' => 'Border', 'type' => 'int', 'min' => 0, 'max' => 10, 'def' => 3],
                    ['k' => 'qr_border_color', 'label' => 'Border colour', 'type' => 'color', 'def_from' => 'title_color'],
                    ['k' => 'qr_hint_size', 'label' => 'Hint size', 'type' => 'int', 'min' => 8, 'max' => 24, 'def' => 14],
                    ['k' => 'qr_hint_color', 'label' => 'Hint colour', 'type' => 'color', 'def_from' => 'label_color'],
                ],
            ],
            [
                'id' => 'foot_back', 'label' => 'Back disclaimer', 'side' => 'back',
                'controls' => [
                    ['k' => 'foot_back_size', 'label' => 'Size', 'type' => 'int', 'min' => 8, 'max' => 28, 'def' => 14],
                    ['k' => 'foot_back_color', 'label' => 'Colour', 'type' => 'color', 'def' => '#3B0000'],
                ],
            ],
        ];
    }

    /** Shared fallbacks so old cards keep looking the same. */
    private static function baseDefaults(): array
    {
        return [
            'label_size' => 20,
            'value_size' => 24,
            'code_size' => 32,
            'title_size' => 24,
            'parish_size' => 26,
            'label_color' => '#600000',
            'title_color' => '#600000',
            'gold_color' => '#B8860B',
            'bar_color' => '#600000',
            'value_color' => '#1A0A0A',
        ];
    }

    /** @return array<string,int|string> */
    public static function defaults(): array
    {
        $out = self::baseDefaults();
        foreach (self::elements() as $el) {
            foreach ($el['controls'] as $c) {
                if (array_key_exists('def', $c)) {
                    $out[$c['k']] = $c['def'];
                    continue;
                }
                if (!empty($c['def_from']) && array_key_exists($c['def_from'], $out)) {
                    $out[$c['k']] = $out[$c['def_from']];
                    continue;
                }
                $out[$c['k']] = ($c['type'] ?? '') === 'color' ? '#600000' : 0;
            }
        }
        // Keep legacy keys used by older CSS / preview helpers
        $out['title_size'] = (int)$out['title_size'];
        $out['parish_size'] = (int)($out['parish_size'] ?? $out['parish_size']);
        return $out;
    }

    /** @return list<string> */
    public static function intKeys(): array
    {
        $keys = ['label_size', 'value_size', 'code_size', 'title_size', 'parish_size'];
        foreach (self::elements() as $el) {
            foreach ($el['controls'] as $c) {
                if (($c['type'] ?? '') === 'int') {
                    $keys[] = $c['k'];
                }
            }
        }
        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    public static function colorKeys(): array
    {
        $keys = ['label_color', 'title_color', 'gold_color', 'bar_color', 'value_color'];
        foreach (self::elements() as $el) {
            foreach ($el['controls'] as $c) {
                if (($c['type'] ?? '') === 'color') {
                    $keys[] = $c['k'];
                }
            }
        }
        return array_values(array_unique($keys));
    }

    /** @return array<string,array{0:int,1:int}> */
    private static function ranges(): array
    {
        $out = [
            'label_size' => [10, 40],
            'value_size' => [12, 56],
            'code_size' => [14, 56],
            'title_size' => [10, 56],
            'parish_size' => [10, 56],
        ];
        foreach (self::elements() as $el) {
            foreach ($el['controls'] as $c) {
                if (($c['type'] ?? '') === 'int') {
                    $out[$c['k']] = [(int)($c['min'] ?? 0), (int)($c['max'] ?? 1000)];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,int|string>
     */
    public static function sanitize(array $in): array
    {
        $out = self::defaults();
        $ranges = self::ranges();
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

    public static function ensureStorage(\mysqli $conn): void
    {
        try {
            $conn->query("ALTER TABLE `system_branding` MODIFY `original_name` MEDIUMTEXT NULL");
        } catch (\Throwable $e) {
            /* already wide, or no table yet */
        }
    }

    /** @return array<string,int|string> */
    public static function load(\mysqli $conn): array
    {
        $out = self::defaults();
        try {
            self::ensureStorage($conn);
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
        $parts = ['--id-bg:' . $bgUrl];

        $opacityKeys = [
            'logo_opacity', 'seal_opacity', 'sig_head_opacity', 'sig_admin_opacity',
        ];
        foreach (self::intKeys() as $k) {
            $css = '--id-' . str_replace('_', '-', $k);
            if (in_array($k, $opacityKeys, true)) {
                $parts[] = $css . ':' . (max(10, (int)$s[$k]) / 100);
            } else {
                $parts[] = $css . ':' . (int)$s[$k] . 'px';
            }
        }
        foreach (self::colorKeys() as $k) {
            $parts[] = '--id-' . str_replace('_', '-', $k) . ':' . $s[$k];
        }
        // Aliases the existing CSS already understands
        $parts[] = '--id-title-size:' . (int)$s['title_size'] . 'px';
        $parts[] = '--id-parish-size:' . (int)$s['parish_size'] . 'px';
        $parts[] = '--id-sig-head:' . (int)$s['sig_head_size'] . 'px';
        $parts[] = '--id-sig-head-op:' . (max(10, (int)$s['sig_head_opacity']) / 100);
        $parts[] = '--id-sig-admin:' . (int)$s['sig_admin_size'] . 'px';
        $parts[] = '--id-sig-admin-op:' . (max(10, (int)$s['sig_admin_opacity']) / 100);
        return implode(';', $parts);
    }

    /** @return array{elements:list<array<string,mixed>>,defaults:array<string,int|string>} */
    public static function designerSchema(): array
    {
        return [
            'elements' => self::elements(),
            'defaults' => self::defaults(),
        ];
    }
}
