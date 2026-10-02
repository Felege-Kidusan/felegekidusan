<?php
/**
 * ════════════════════════════════════════════════════════════
 * ReviewTransitionPolicy — the ONE submission-packet review
 * state machine, shared by Education, HR and Mezmur.
 *
 * Why this exists (audit 2026-10-02, findings A/B):
 * Education guarded reviewer decisions with
 * SubmissionService::reviewTransitionError(); HR and Mezmur did
 * not, so an already-approved packet could be flipped to
 * rejected (and vice versa) by replaying a review request.
 *
 * This class is deliberately DEPARTMENT-NEUTRAL: it knows no
 * table, no role and no connection. Each module therefore shares
 * the rule without depending on another department's service
 * (HR must not require Education's SubmissionService — see
 * tests/security/test_hr_attendance_domain.py, which locks HR
 * isolation). SubmissionService::reviewTransitionError() now
 * delegates here, so there is exactly one implementation of the
 * rule rather than three copies.
 *
 * The rule: a reviewer decision is valid ONLY on a packet that is
 * awaiting review (status = 'submitted'). Everything else is a
 * conflict, reported as a user-safe sentence.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

final class ReviewTransitionPolicy
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVISION = 'revision_needed';
    public const STATUS_DRAFT = 'draft';

    /** Decisions a reviewer is allowed to record. */
    public const REVIEW_TARGETS = [
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_REVISION,
    ];

    /**
     * Unknown/empty input normalises to 'incomplete' — identical to the
     * per-module normalizeStatus() helpers this class consolidates.
     */
    public static function normalize(?string $status): string
    {
        $status = strtolower(trim((string)$status));
        $ok = [
            self::STATUS_INCOMPLETE,
            self::STATUS_SUBMITTED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_REVISION,
            self::STATUS_DRAFT,
        ];
        return in_array($status, $ok, true) ? $status : self::STATUS_INCOMPLETE;
    }

    /** Still editable by the taker/teacher (not yet locked by review). */
    public static function isOpen(?string $status): bool
    {
        $status = self::normalize($status);
        return in_array($status, [self::STATUS_DRAFT, self::STATUS_INCOMPLETE, self::STATUS_REVISION], true)
            || $status === '';
    }

    /** True when the packet is awaiting a reviewer decision. */
    public static function isAwaitingReview(?string $status): bool
    {
        return self::normalize($status) === self::STATUS_SUBMITTED;
    }

    /** True when $target is a decision a reviewer may record. */
    public static function isReviewTarget(string $target): bool
    {
        return in_array($target, self::REVIEW_TARGETS, true);
    }

    /**
     * Returns null when the transition is allowed, or a user-safe reason
     * explaining why the packet cannot be decided in its current state
     * (reviewing a draft, approving a rejected packet, rejecting an
     * approved one, …).
     *
     * $noun / $actor let each module speak its own vocabulary — Education
     * reviews a "list" submitted by a "teacher"; HR and Mezmur review a
     * "packet" submitted by a "taker" — without forking the rule.
     *
     * $target is accepted for call-site clarity and future tightening; as
     * today, every REVIEW_TARGETS decision is valid from 'submitted'.
     */
    public static function error(
        ?string $current,
        string $target,
        string $noun = 'list',
        string $actor = 'teacher'
    ): ?string {
        $cur = self::normalize($current);
        if ($cur === self::STATUS_SUBMITTED) {
            return null; // awaiting review — approve / return / reject are all valid
        }
        if ($cur === '' || self::isOpen($cur)) {
            return 'This ' . $noun . ' has not been submitted for review yet. The '
                . $actor . ' must submit it first.';
        }
        switch ($cur) {
            case self::STATUS_APPROVED:
                return 'This ' . $noun . ' is already approved.';
            case self::STATUS_REJECTED:
                return 'This ' . $noun . ' was rejected. Ask the ' . $actor
                    . ' to submit a corrected ' . $noun . '.';
            case self::STATUS_REVISION:
                return 'This ' . $noun . ' was returned to the ' . $actor
                    . ' and has not been resubmitted yet.';
        }
        return 'This ' . $noun . ' cannot be reviewed in its current state.';
    }

    /**
     * The message shown when the guarded UPDATE matched zero rows — i.e.
     * another reviewer decided the packet between our SELECT and our
     * UPDATE. Callers surface this as a 409-style conflict.
     */
    public static function raceLostMessage(string $noun = 'list'): string
    {
        return 'This ' . $noun . ' is no longer awaiting review. Refresh and try again.';
    }
}
