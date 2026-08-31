<?php
/**
 * Stored-mojibake detector (STAGING FIRST — review before applying).
 *
 * The pre-patch Section dropdown and year-name template wrote corrupted
 * (double-encoded) values into the database. This tool FINDS them and prints
 * the exact UPDATE statements that would repair them. It never writes.
 *
 * Usage:
 *   php tools/find_stored_mojibake.php             # print SQL for review
 *   php tools/find_stored_mojibake.php --apply     # execute the printed SQL
 *
 * Extend $targets if new columns turn out to be affected.
 */
require_once __DIR__ . '/../config.php';
while (ob_get_level() > 0) { @ob_end_clean(); }

$targets = [
    // table => [pk, [column, ...]]
    'classes'        => ['id', ['class_name', 'class_name_en', 'section', 'description']],
    'subjects'       => ['id', ['subject_name', 'subject_name_en', 'description']],
    'academic_years' => ['id', ['year_name']],
    'academic_terms' => ['id', ['term_name']],
    'members'        => ['id', ['student_name', 'father_name', 'grandfather_name', 'full_name_am', 'baptismal_name', 'current_section']],
    // CMS content (2026-08-31): gallery sample rows were applied through a
    // mis-charset channel once — keep the public-facing captions covered.
    'cms_gallery_categories' => ['id', ['name', 'name_am', 'description']],
    'cms_gallery_photos'     => ['id', ['caption', 'caption_am']],
];

/**
 * Byte-level repair using the same algorithm as tools/fix_mojibake.py:
 * map each UTF-8 character back to its cp1252 byte (identity for the 5
 * hole bytes), then decode the byte string as UTF-8. Returns null when
 * the value is not recoverable mojibake.
 */
function repair_value(string $v): ?string
{
    $mapped = '';
    foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $cp = /* codepoint */ mb_ord($ch, 'UTF-8');
        if ($cp === false) {
            return null;
        }
        if (in_array($cp, [0x81, 0x8D, 0x8f, 0x90, 0x9d], true)) {
            $mapped .= chr($cp);
            continue;
        }
        if ($cp >= 0x80 && $cp <= 0x9f) {
            // C1 control form of the corruption (byte stored as latin-1 char)
            $mapped .= chr($cp);
            continue;
        }
        if ($cp < 0x80) {
            $mapped .= chr($cp);
            continue;
        }
        $byte = @iconv('UTF-8', 'CP1252', $ch);
        if ($byte === false || $byte === '') {
            return null; // char not in cp1252 -> not our mojibake
        }
        $mapped .= $byte;
    }
    $decoded = @iconv('UTF-8', 'UTF-8', $mapped); // STRICT: false on invalid
    if ($decoded === false || $decoded === '') {
        return null;
    }
    // accept only if the result contains Ethiopic or typographic payload
    $hasEthiopic = preg_match('/[\x{1200}-\x{137F}]/u', $decoded) === 1;
    $hasTypo = preg_match('/[—–’‘“”…·♂♀]/u', $decoded) === 1;
    if (!$hasEthiopic && !$hasTypo) {
        return null;
    }
    // and the original must look like mojibake (upper-Latin/accented or C1)
    $looksMojibake = preg_match('/[áâàãÃ]/u', $v) === 1
        || preg_match('/â€/u', $v) === 1
        || preg_match('/[\x{0080}-\x{009f}]/u', $v) === 1;
    if (!$looksMojibake) {
        return null;
    }
    return $decoded;
}

$apply = in_array('--apply', $argv ?? [], true);
$sqls = [];
foreach ($targets as $table => [$pk, $cols]) {
    $colList = implode(',', array_map(static fn($c) => "`$c`", $cols));
    $res = $conn->query("SELECT `$pk`, $colList FROM `$table`");
    if (!$res) {
        continue;
    }
    while ($row = $res->fetch_assoc()) {
        $sets = [];
        foreach ($cols as $c) {
            $val = (string)($row[$c] ?? '');
            if ($val === '') {
                continue;
            }
            $fixed = repair_value($val);
            if ($fixed !== null && $fixed !== $val) {
                $esc = $conn->real_escape_string($fixed);
                $sets[] = "`$c` = '$esc'";
            }
        }
        if ($sets) {
            $sqls[] = "UPDATE `$table` SET " . implode(', ', $sets)
                    . " WHERE `$pk` = " . (int)$row[$pk] . ";  -- was mojibake";
        }
    }
}

if (!$sqls) {
    echo "No stored mojibake found in scanned columns.\n";
    exit(0);
}
echo "Found " . count($sqls) . " row(s) with stored mojibake:\n\n";
foreach ($sqls as $sql) {
    echo $sql, "\n";
}
if ($apply) {
    foreach ($sqls as $sql) {
        $stmt = explode(';  --', $sql)[0];
        $conn->query($stmt);
    }
    echo "\nApplied.\n";
} else {
    echo "\nDry run — re-run with --apply to fix.\n";
}
