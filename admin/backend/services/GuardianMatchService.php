<?php
/**
 * GuardianMatchService — Family Link matching engine.
 *
 * Fellegi-Sunter style weighted scoring with three decision bands
 * (auto / review / new), blocking keys to keep candidate pairs cheap, and
 * typo tolerance via Jaro-Winkler (edgaras/strsim, vendored; internal
 * polyfill if the autoloader is unavailable on a partial deploy).
 *
 * Shared-hosting safe: pure PHP, no extensions beyond mbstring, no
 * full-table pairwise scans (blocking first), bounded candidate lists.
 *
 * Bands (tunable after the production profiling pack):
 *   score >= AUTO_THRESHOLD   -> auto-link  (requires phone n9 equality OR
 *                                name>=0.96 with address agreement, enforced
 *                                by the weighting: name-only can never reach
 *                                the auto band)
 *   score >= REVIEW_THRESHOLD -> steward review queue
 *   below                     -> treat as new guardian
 */

namespace App\Services;

if (!class_exists('\\Edgaras\\StrSim\\JaroWinkler')) {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

class GuardianMatchService
{
    public const AUTO_THRESHOLD = 0.80;
    public const REVIEW_THRESHOLD = 0.55;

    public const W_PHONE = 0.45;   // last-9 phone digits exact
    public const W_NAME = 0.50;    // scaled by Jaro-Winkler similarity
    public const W_PHONETIC = 0.05; // metaphone token equality (ASCII names)
    public const W_ADDRESS = 0.15;  // address token Jaccard >= 0.5

    /** Lower band floor when phones match: a phone hit is never "new". */
    public const PHONE_FLOOR = 0.60;

    /** Max candidates evaluated per blocking query (shared-hosting bound). */
    public const CANDIDATE_LIMIT = 60;

    // ── Normalization ────────────────────────────────────────────────
    public static function normalizeName(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{Nd}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string)$s);
    }

    /** Last 9 digits of a phone number (Ethiopian mobile significant part). */
    public static function phoneN9(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return (is_string($digits) && strlen($digits) >= 9) ? substr($digits, -9) : '';
    }

    public static function addressTokens(array $row): array
    {
        $tokens = [];
        foreach (['city', 'sub_city', 'woreda', 'mender', 'block_number', 'house'] as $k) {
            $v = trim((string)($row[$k] ?? ''));
            if ($v !== '') {
                $tokens[] = mb_strtolower($v, 'UTF-8');
            }
        }
        return $tokens;
    }

    // ── Comparators ──────────────────────────────────────────────────
    public static function jaroWinkler(string $a, string $b): float
    {
        if (class_exists('\\Edgaras\\StrSim\\JaroWinkler')) {
            return (float)\Edgaras\StrSim\JaroWinkler::similarity($a, $b);
        }
        return self::jaroWinklerPolyfill($a, $b);
    }

    /** Deterministic JW polyfill (fallback when vendor/ is incomplete). */
    public static function jaroWinklerPolyfill(string $a, string $b): float
    {
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $la = count($a);
        $lb = count($b);
        if ($la === 0 || $lb === 0) {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $window = max(0, (int)(max($la, $lb) / 2) - 1);
        $bUsed = array_fill(0, $lb, false);
        $matches = 0;
        $transpositions = 0;
        $matchedA = [];
        for ($i = 0; $i < $la; $i++) {
            $lo = max(0, $i - $window);
            $hi = min($lb - 1, $i + $window);
            for ($j = $lo; $j <= $hi; $j++) {
                if (!$bUsed[$j] && $a[$i] === $b[$j]) {
                    $bUsed[$j] = true;
                    $matchedA[] = [$i, $a[$i]];
                    $matches++;
                    break;
                }
            }
        }
        if ($matches === 0) {
            return 0.0;
        }
        $k = 0;
        foreach ($matchedA as $idx => $pair) {
            while ($k < $lb && !$bUsed[$k]) {
                $k++;
            }
            $k++;
        }
        // transpositions: compare matched sequences
        $seqA = [];
        $seqB = [];
        foreach ($matchedA as $pair) {
            $seqA[] = $pair[1];
        }
        $bi = 0;
        for ($j = 0; $j < $lb; $j++) {
            if ($bUsed[$j]) {
                $seqB[] = $b[$j];
            }
        }
        $cmp = min(count($seqA), count($seqB));
        for ($i = 0; $i < $cmp; $i++) {
            if ($seqA[$i] !== $seqB[$i]) {
                $transpositions++;
            }
        }
        $transpositions = (int)($transpositions / 2);
        $jaro = (($matches / $la) + ($matches / $lb) + (($matches - $transpositions) / $matches)) / 3.0;
        // Winkler prefix boost (max 4 chars)
        $prefix = 0;
        for ($i = 0; $i < min(4, min($la, $lb)); $i++) {
            if ($a[$i] === $b[$i]) {
                $prefix++;
            } else {
                break;
            }
        }
        return $jaro + ($prefix * 0.1 * (1 - $jaro));
    }

    public static function trigrams(string $s): array
    {
        $s = preg_replace('/\s+/u', '', $s);
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        if ($n < 3) {
            return $n ? [implode('', $chars)] : [];
        }
        $out = [];
        for ($i = 0; $i <= $n - 3; $i++) {
            $out[$chars[$i] . $chars[$i + 1] . $chars[$i + 2]] = 1;
        }
        return $out;
    }

    public static function jaccard(array $setA, array $setB): float
    {
        if (!$setA || !$setB) {
            return 0.0;
        }
        $inter = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    /** Metaphone key for ASCII names; '' for non-ASCII (Amharic) inputs. */
    public static function phoneticKey(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return '';
        }
        $parts = [];
        foreach (explode(' ', $name) as $token) {
            if ($token !== '') {
                $parts[] = metaphone($token);
            }
        }
        return implode(' ', $parts);
    }

    // ── Scoring ──────────────────────────────────────────────────────
    /**
     * @param array $a candidate guardian row (+ 'aliases' array of names)
     * @param array $b same shape
     * @return array{score: float, band: string, evidence: array}
     */
    public static function score(array $a, array $b): array
    {
        $evidence = [];
        $score = 0.0;

        $n9a = [$a['phone_primary_n9'] ?? '', $a['phone_alt_n9'] ?? ''];
        $n9b = [$b['phone_primary_n9'] ?? '', $b['phone_alt_n9'] ?? ''];
        $phoneEq = false;
        foreach ($n9a as $xa) {
            foreach ($n9b as $xb) {
                if ($xa !== '' && $xa === $xb) {
                    $phoneEq = true;
                    break 2;
                }
            }
        }
        if ($phoneEq) {
            $score += self::W_PHONE;
            $evidence['phone'] = 'last-9 equal';
        }

        $namesA = array_merge([self::normalizeName((string)($a['display_name'] ?? ''))], array_map([self::class, 'normalizeName'], (array)($a['aliases'] ?? [])));
        $namesB = array_merge([self::normalizeName((string)($b['display_name'] ?? ''))], array_map([self::class, 'normalizeName'], (array)($b['aliases'] ?? [])));
        $best = 0.0;
        foreach ($namesA as $na) {
            foreach ($namesB as $nb) {
                if ($na === '' || $nb === '') {
                    continue;
                }
                $best = max($best, self::jaroWinkler($na, $nb));
            }
        }
        $score += self::W_NAME * $best;
        $evidence['name'] = round($best, 3);

        $pa = self::phoneticKey(self::normalizeName((string)($a['display_name'] ?? '')));
        $pb = self::phoneticKey(self::normalizeName((string)($b['display_name'] ?? '')));
        if ($pa !== '' && $pa === $pb) {
            $score += self::W_PHONETIC;
            $evidence['phonetic'] = 'metaphone equal';
        }

        $addrJ = self::jaccard(self::addressTokens($a), self::addressTokens($b));
        if ($addrJ >= 0.5) {
            $score += self::W_ADDRESS;
            $evidence['address'] = round($addrJ, 2);
        }

        if ($phoneEq && $score < self::PHONE_FLOOR) {
            $score = self::PHONE_FLOOR;
        }
        $score = min(1.0, $score);
        $band = $score >= self::AUTO_THRESHOLD ? 'auto' : ($score >= self::REVIEW_THRESHOLD ? 'review' : 'new');
        return ['score' => round($score, 4), 'band' => $band, 'evidence' => $evidence];
    }

    /**
     * Re-match sweep: re-score active guardians against each other and file
     * review-band pairs into guardian_link_suggestions. Never auto-merges.
     *
     * Bounded for shared hosting: at most $limit guardians per call and a
     * wall-clock budget; resumable through the returned cursor. Pairs that
     * are pending or were previously rejected are skipped (HR decisions are
     * final until the data changes).
     *
     * @return array{scanned:int, filed:int, next_cursor:int, done:bool, elapsed:float}
     */
    public static function rematchBatch(\mysqli $conn, int $cursor = 0, int $limit = 300, float $budgetSeconds = 20.0): array
    {
        $t0 = microtime(true);
        $scanned = 0;
        $filed = 0;
        $last = $cursor;
        $done = true;

        $stmt = $conn->prepare("SELECT * FROM guardians WHERE status = 'active' AND id > ? ORDER BY id ASC LIMIT " . max(1, $limit));
        if (!$stmt) {
            return ['scanned' => 0, 'filed' => 0, 'next_cursor' => $cursor, 'done' => true, 'elapsed' => 0.0];
        }
        $stmt->bind_param('i', $cursor);
        $stmt->execute();
        $res = $stmt->get_result();
        $batch = [];
        while ($g = $res->fetch_assoc()) {
            $batch[] = $g;
        }
        $stmt->close();
        if (count($batch) === $limit) {
            $done = false;
        }

        foreach ($batch as $g) {
            if ((microtime(true) - $t0) > $budgetSeconds) {
                $done = false;
                break;
            }
            $scanned++;
            $last = (int)$g['id'];
            $g['aliases'] = [];
            $al = $conn->prepare('SELECT alias_name FROM guardian_aliases WHERE guardian_id = ?');
            if ($al) {
                $al->bind_param('i', $g['id']);
                $al->execute();
                $ar = $al->get_result();
                while ($a = $ar->fetch_assoc()) {
                    $g['aliases'][] = (string)$a['alias_name'];
                }
                $al->close();
            }
            $cands = self::findCandidates($conn, (string)$g['display_name'], (string)$g['phone_primary'], $g);
            foreach ($cands as $c) {
                $cid = (int)$c['id'];
                if ($cid === (int)$g['id'] || $cid < (int)$g['id']) {
                    continue; // each unordered pair once (lower id scans higher)
                }
                $r = self::score($g, $c);
                if ($r['band'] === 'new') {
                    continue;
                }
                $a = (int)$g['id'];
                $b = $cid;
                $chk = $conn->prepare("SELECT status FROM guardian_link_suggestions WHERE ((guardian_a = ? AND guardian_b = ?) OR (guardian_a = ? AND guardian_b = ?)) AND status IN ('pending','rejected') LIMIT 1");
                if ($chk) {
                    $chk->bind_param('iiii', $a, $b, $b, $a);
                    $chk->execute();
                    $exists = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($exists) {
                        continue;
                    }
                }
                $band = $r['band'] === 'auto' ? 'auto' : 'review';
                $ev = json_encode($r['evidence'] + ['source' => 'rematch'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $ins = $conn->prepare("INSERT INTO guardian_link_suggestions (guardian_a, guardian_b, member_id, score, band, evidence, status) VALUES (?, ?, NULL, ?, ?, ?, 'pending')");
                if ($ins) {
                    $sc = (float)$r['score'];
                    $ins->bind_param('iidss', $a, $b, $sc, $band, $ev);
                    if ($ins->execute()) {
                        $filed++;
                    }
                    $ins->close();
                }
            }
        }
        return ['scanned' => $scanned, 'filed' => $filed, 'next_cursor' => $last, 'done' => $done, 'elapsed' => round(microtime(true) - $t0, 2)];
    }

    /**
     * Blocking: cheap SQL prefilter (phone n9, name prefix, alias prefix),
     * then trigram gate in PHP. Bounded by CANDIDATE_LIMIT.
     *
     * @param \mysqli $conn
     * @return array[] guardian rows with aliases loaded
     */
    public static function findCandidates(\mysqli $conn, string $name, string $phone, array $addressRow = []): array
    {
        $n9 = self::phoneN9($phone);
        $norm = self::normalizeName($name);
        $prefix = mb_substr($norm, 0, 4, 'UTF-8');
        $rows = [];
        $seen = [];

        $addFromResult = function ($res) use (&$rows, &$seen) {
            if (!$res) {
                return;
            }
            while ($r = $res->fetch_assoc()) {
                $id = (int)$r['id'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $r['aliases'] = [];
                    $rows[$id] = $r;
                }
            }
        };

        if ($n9 !== '') {
            $stmt = $conn->prepare('SELECT * FROM guardians WHERE status != \'merged\' AND (phone_primary_n9 = ? OR phone_alt_n9 = ?) LIMIT ' . self::CANDIDATE_LIMIT);
            if ($stmt) {
                $stmt->bind_param('ss', $n9, $n9);
                $stmt->execute();
                $addFromResult($stmt->get_result());
                $stmt->close();
            }
        }
        if ($prefix !== '' && count($rows) < self::CANDIDATE_LIMIT) {
            $like = $prefix . '%';
            $stmt = $conn->prepare('SELECT * FROM guardians WHERE status != \'merged\' AND display_name LIKE ? LIMIT ' . self::CANDIDATE_LIMIT);
            if ($stmt) {
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $addFromResult($stmt->get_result());
                $stmt->close();
            }
            $stmt = $conn->prepare('SELECT g.* FROM guardians g JOIN guardian_aliases al ON al.guardian_id = g.id WHERE g.status != \'merged\' AND al.alias_name LIKE ? LIMIT ' . self::CANDIDATE_LIMIT);
            if ($stmt) {
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $addFromResult($stmt->get_result());
                $stmt->close();
            }
        }

        // trigram gate + alias loading
        $qTri = self::trigrams($norm);
        $out = [];
        foreach ($rows as $id => $r) {
            $res = $conn->prepare('SELECT alias_name FROM guardian_aliases WHERE guardian_id = ?');
            if ($res) {
                $res->bind_param('i', $id);
                $res->execute();
                $ar = $res->get_result();
                while ($a = $ar->fetch_assoc()) {
                    $r['aliases'][] = (string)$a['alias_name'];
                }
                $res->close();
            }
            $names = array_merge([self::normalizeName((string)$r['display_name'])], array_map([self::class, 'normalizeName'], $r['aliases']));
            $gate = $n9 !== '' && (($r['phone_primary_n9'] ?? '') === $n9 || ($r['phone_alt_n9'] ?? '') === $n9);
            foreach ($names as $cn) {
                if ($cn === '') {
                    continue;
                }
                if (self::jaccard($qTri, self::trigrams($cn)) >= 0.20) {
                    $gate = true;
                    break;
                }
            }
            if ($gate) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
