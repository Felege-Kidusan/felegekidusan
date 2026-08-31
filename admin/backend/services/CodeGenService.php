<?php
/**
 * CodeGenService — deterministic, script-safe short codes.
 *
 * Why this exists (audit patch 8): the legacy subject-code generator kept
 * only [a-zA-Z0-9] from the name, so a non-Latin (e.g. Amharic) name became
 * pure underscores, collided with the previous one, and the de-duplication
 * suffix overflowed the column -> "Data too long" / "Server error".
 *
 * Rules (Google-Classroom-style opaque ids for non-Latin scripts):
 *  - an explicit code from a trusted caller is normalised, never invented;
 *  - Latin-script names slug to readable codes ("Holy Bible" -> holy_bible);
 *  - non-Latin names (Amharic, …) hash to a compact, deterministic code
 *    (subj_<base36>) — never underscores;
 *  - collisions are resolved with a short numeric suffix;
 *  - the returned code ALWAYS fits the column (hard cap), making the old
 *    overflow class of bug impossible.
 */
declare(strict_types=1);

namespace App\Services;

final class CodeGenService
{
    /** Hard database cap for subjects.subject_code (migration 029). */
    public const SUBJECT_CODE_MAX = 50;

    /** Keep room for a collision suffix behind the hard cap. */
    private const SUBJECT_CODE_BASE_MAX = 38;

    private function __construct()
    {
    }

    /**
     * Generate a unique subject code that fits the column for any script.
     *
     * @param mysqli $conn    live DB handle (uniqueness lookups)
     * @param string $name    subject name (any script), e.g. "ቅዱስ ቁርባን"
     * @param string $nameEn  optional Latin name, preferred for readable codes
     * @param string $explicit optional caller-supplied code; normalised if usable
     * @return string unique code, length <= self::SUBJECT_CODE_MAX
     */
    public static function subjectCode(\mysqli $conn, string $name, string $nameEn = '', string $explicit = ''): string
    {
        $base = self::slugify($explicit);
        if ($base === '') {
            $base = self::slugify($nameEn);
        }
        if ($base === '') {
            $base = self::opaqueSlug('subj', $name !== '' ? $name : $nameEn);
        }
        $base = substr($base, 0, self::SUBJECT_CODE_BASE_MAX);

        $candidate = $base;
        $attempt = 1;
        while (self::codeExists($conn, $candidate)) {
            $attempt++;
            if ($attempt > 20) {
                // Astronomically unlikely; guarantee uniqueness + fit anyway.
                $candidate = substr($base, 0, 30) . '_' . base_convert((string)time(), 10, 36);
                if (self::codeExists($conn, $candidate)) {
                    $candidate = substr($candidate, 0, self::SUBJECT_CODE_MAX - 4)
                        . '_' . random_int(100, 999);
                }
                break;
            }
            $candidate = $base . '_' . $attempt;
        }
        return substr($candidate, 0, self::SUBJECT_CODE_MAX);
    }

    /** @var array<string,bool> memoised existence checks per call chain */
    private static function codeExists(\mysqli $conn, string $code): bool
    {
        $stmt = $conn->prepare('SELECT id FROM subjects WHERE subject_code = ? LIMIT 1');
        if (!$stmt) {
            return false; // let the UNIQUE key be the final guard
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Lowercase ASCII slug: keeps [a-z0-9], collapses the rest to single
     * underscores, trims separators. Returns '' when nothing ASCII remains
     * (e.g. Amharic input) — callers then use opaqueSlug().
     */
    public static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $slug = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $slug = trim($slug, '_');
        return $slug;
    }

    /**
     * Deterministic short code for any script: prefix + base36 hash.
     * "subj_" + 8-10 chars — readable, stable, script-independent.
     */
    public static function opaqueSlug(string $prefix, string $value): string
    {
        $hash = base_convert(substr(sha1($value), 0, 12), 16, 36); // ~9 chars
        return $prefix . '_' . $hash;
    }
}
