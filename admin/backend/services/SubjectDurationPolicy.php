<?php
/**
 * Subject duration policy — how long a subject runs, and how its final
 * score out of 100 is produced.
 *
 * Two kinds of subject exist in this school:
 *
 *   SEMESTER_ONLY  completed inside one semester. Its final score IS that
 *                  semester's score. It does not continue, and the semester
 *                  it did not run in is ABSENT, never zero.
 *   FULL_YEAR      runs across both semesters. Its final annual score is the
 *                  plain AVERAGE of the two semester totals, (S1 + S2) / 2 —
 *                  the school's semester-based model (2026-10-08 decision):
 *                  each semester closes from 100% and the annual figure is
 *                  just the average of the two closed semesters. (Until
 *                  1.6.7 this was a weighted combination; the per-year
 *                  s1/s2 weights are retained in the schema and the year
 *                  admin for history but are no longer applied here.) Until
 *                  both semesters exist the annual score is PENDING — a
 *                  Semester 1 score is never the annual result.
 *
 * Weights are stored per academic year (academic_years.s1_weight_pct /
 * s2_weight_pct, migration 056) so a closed year keeps the distribution it
 * was actually graded under. They are never hard-coded here: the only
 * constants below are the fallback used when a year row cannot be read at
 * all, and the 100% total that every distribution must satisfy.
 *
 * This class does arithmetic and classification only. It performs no writes.
 */

namespace App\Services;

class SubjectDurationPolicy
{
    public const SEMESTER_ONLY = 'SEMESTER_ONLY';
    public const FULL_YEAR     = 'FULL_YEAR';

    /** Offering not yet classified by a human. Behaves as it did before 056. */
    public const UNCLASSIFIED = null;

    /** Subject finished; the score shown is final. */
    public const STATUS_CLOSED = 'CLOSED';
    /** Full-year subject that has Semester 1 but is still running. */
    public const STATUS_CONTINUING = 'CONTINUING';
    /** Full-year subject whose annual score cannot be computed yet. */
    public const STATUS_PENDING = 'PENDING';

    /** Every valid weight distribution must total exactly this. */
    public const WEIGHT_TOTAL = 100.0;

    /**
     * Used only when the academic_years row is unreadable (year deleted, or a
     * pre-056 database). Mirrors the column default so behaviour is identical.
     */
    private const FALLBACK_WEIGHTS = ['s1' => 50.0, 's2' => 50.0];

    /** Tolerance for decimal(5,2) round-tripping when checking the 100% rule. */
    private const EPSILON = 0.001;

    /**
     * Normalise a stored duration value. Unknown or empty values are
     * UNCLASSIFIED rather than silently becoming FULL_YEAR.
     */
    public static function normalize($value): ?string
    {
        if ($value === null || $value === '') {
            return self::UNCLASSIFIED;
        }
        $v = strtoupper(trim((string)$value));
        return ($v === self::SEMESTER_ONLY || $v === self::FULL_YEAR) ? $v : self::UNCLASSIFIED;
    }

    /**
     * True when the distribution is usable. Rejects negatives, non-numerics
     * and anything that does not total 100%.
     */
    public static function weightsAreValid($s1, $s2): bool
    {
        if (!is_numeric($s1) || !is_numeric($s2)) {
            return false;
        }
        $a = (float)$s1;
        $b = (float)$s2;
        if ($a < 0 || $b < 0) {
            return false;
        }
        return abs(($a + $b) - self::WEIGHT_TOTAL) < self::EPSILON;
    }

    /**
     * @throws \InvalidArgumentException when the distribution does not total 100%.
     * @return array{s1:float,s2:float}
     */
    public static function assertWeights($s1, $s2): array
    {
        if (!self::weightsAreValid($s1, $s2)) {
            throw new \InvalidArgumentException(
                'Semester weights must be non-negative and total 100%; got '
                . var_export($s1, true) . ' + ' . var_export($s2, true)
            );
        }
        return ['s1' => (float)$s1, 's2' => (float)$s2];
    }

    /**
     * Whether migration 056's `class_subjects.duration_type` / `.term_id`
     * columns exist on the database behind this connection.
     *
     * Callers previously detected a pre-056 database with
     * `$stmt = @$conn->prepare($sql); if (!$stmt) { ...fallback... }`.
     * That never worked. `@` suppresses diagnostics but NOT exceptions, and
     * since PHP 8.1 mysqli's default report mode is
     * MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT, so `prepare()` on an unknown
     * column throws instead of returning false. Every such fallback branch
     * was unreachable and the request failed outright. This probe asks
     * information_schema, which cannot throw for a missing column.
     *
     * Cached per connection: the schema cannot change inside one request.
     */
    public static function supportsOfferingDuration(\mysqli $conn): bool
    {
        static $cache = [];
        $key = spl_object_id($conn);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $found = 0;
        try {
            $res = $conn->query(
                "SELECT COUNT(*) AS n FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND table_name = 'class_subjects'
                    AND column_name IN ('duration_type', 'term_id')"
            );
            if ($res) {
                $row = $res->fetch_assoc();
                $found = (int)($row['n'] ?? 0);
                $res->free();
            }
        } catch (\Throwable $e) {
            $found = 0;
        }
        // Both columns, or treat the database as pre-056. A half-applied
        // migration is not a state we guess our way through.
        return $cache[$key] = ($found === 2);
    }

    /**
     * Weights for one academic year, read from the year row.
     *
     * @return array{s1:float,s2:float}
     */
    public static function weightsForYear(\mysqli $conn, int $yearId): array
    {
        if ($yearId <= 0) {
            return self::FALLBACK_WEIGHTS;
        }
        try {
            $stmt = @$conn->prepare(
                'SELECT s1_weight_pct, s2_weight_pct FROM academic_years WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                // Pre-056 database: the columns do not exist yet.
                return self::FALLBACK_WEIGHTS;
            }
            $stmt->bind_param('i', $yearId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                return self::FALLBACK_WEIGHTS;
            }
            $s1 = $row['s1_weight_pct'];
            $s2 = $row['s2_weight_pct'];
            if (!self::weightsAreValid($s1, $s2)) {
                // Stored distribution is unusable. Refuse it rather than
                // producing a silently wrong annual score.
                return self::FALLBACK_WEIGHTS;
            }
            return ['s1' => (float)$s1, 's2' => (float)$s2];
        } catch (\Throwable $e) {
            return self::FALLBACK_WEIGHTS;
        }
    }

    /**
     * Final score out of 100 for one subject offering.
     *
     * $s1 / $s2 are that subject's already-computed semester percentages, or
     * null when the semester has no marks. A missing semester is ABSENT; it is
     * never substituted with zero.
     *
     * @param array{s1:float,s2:float} $weights
     * @return array{final:?float,status:string,reason:string}
     */
    public static function finalScore(
        ?string $duration,
        ?float $s1,
        ?float $s2,
        array $weights,
        int $offeringTermNumber = 0
    ): array {
        $duration = self::normalize($duration);

        if ($duration === self::SEMESTER_ONLY) {
            // Closed in whichever semester it ran. If the offering declares a
            // semester, honour it; otherwise use the only one that has marks.
            if ($offeringTermNumber === 1) {
                $score = $s1;
            } elseif ($offeringTermNumber === 2) {
                $score = $s2;
            } else {
                $score = $s1 ?? $s2;
            }
            if ($score === null) {
                return [
                    'final'  => null,
                    'status' => self::STATUS_PENDING,
                    'reason' => 'semester-only subject has no marks in its semester',
                ];
            }
            return [
                'final'  => round($score, 2),
                'status' => self::STATUS_CLOSED,
                'reason' => 'semester-only subject closed at its own semester score',
            ];
        }

        if ($duration === self::FULL_YEAR) {
            // Both semesters are REQUIRED. One semester is never the annual
            // result, and the missing one is never treated as zero.
            if ($s1 === null || $s2 === null) {
                return [
                    'final'  => null,
                    'status' => $s1 !== null ? self::STATUS_CONTINUING : self::STATUS_PENDING,
                    'reason' => 'full-year subject needs both semesters before an annual score exists',
                ];
            }
            // 1.6.7: the annual score is the plain average of the two
            // semester totals (semester-based model). The stored per-year
            // weights are deliberately NOT applied any more.
            unset($weights, $offeringTermNumber);
            $annual = ($s1 + $s2) / 2.0;
            return [
                'final'  => round($annual, 2),
                'status' => self::STATUS_CLOSED,
                'reason' => 'full-year subject annual score = average of the two semester totals',
            ];
        }

        // UNCLASSIFIED — nobody has said how long this subject runs. Preserve
        // the pre-056 behaviour: use whatever marks exist, averaged evenly,
        // and say plainly that the result is not a classified final score.
        $present = array_values(array_filter([$s1, $s2], static fn($v) => $v !== null));
        if (!$present) {
            return [
                'final'  => null,
                'status' => self::STATUS_PENDING,
                'reason' => 'unclassified subject has no marks',
            ];
        }
        return [
            'final'  => round(array_sum($present) / count($present), 2),
            'status' => self::STATUS_PENDING,
            'reason' => 'subject duration is not classified; classify it to get a final score',
        ];
    }

    /**
     * Status shown on a SEMESTER report (not the annual one).
     */
    public static function semesterStatus(?string $duration, int $reportTermNumber, bool $hasMarks): string
    {
        $duration = self::normalize($duration);
        if ($duration === self::SEMESTER_ONLY) {
            return $hasMarks ? self::STATUS_CLOSED : self::STATUS_PENDING;
        }
        if ($duration === self::FULL_YEAR) {
            // Semester 1 of a full-year subject is explicitly still running.
            if ($reportTermNumber === 1) {
                return self::STATUS_CONTINUING;
            }
            return $hasMarks ? self::STATUS_CLOSED : self::STATUS_PENDING;
        }
        return self::STATUS_PENDING;
    }

    /**
     * Does this offering belong to the semester being reported on?
     *
     * A SEMESTER_ONLY offering that declares a semester appears only in that
     * semester. Everything else appears in every semester of its year.
     */
    public static function appearsInTerm(?string $duration, int $offeringTermNumber, int $reportTermNumber): bool
    {
        if ($reportTermNumber <= 0 || $offeringTermNumber <= 0) {
            return true;
        }
        if (self::normalize($duration) === self::SEMESTER_ONLY) {
            return $offeringTermNumber === $reportTermNumber;
        }
        return true;
    }

    /**
     * Annual average across already-final subject scores.
     *
     * Only subjects with a real final score count. A PENDING full-year subject
     * is excluded rather than counted as zero, and each offering contributes
     * at most once.
     *
     * @param array<int,?float> $finalScoresByOfferingId
     * @return array{average:?float,counted:int,pending:int}
     */
    public static function annualAverage(array $finalScoresByOfferingId): array
    {
        $counted = 0;
        $pending = 0;
        $sum = 0.0;
        foreach ($finalScoresByOfferingId as $score) {
            if ($score === null) {
                $pending++;
                continue;
            }
            $sum += (float)$score;
            $counted++;
        }
        return [
            'average' => $counted > 0 ? round($sum / $counted, 2) : null,
            'counted' => $counted,
            'pending' => $pending,
        ];
    }
}
