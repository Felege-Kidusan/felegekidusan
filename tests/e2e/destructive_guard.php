<?php
/**
 * ════════════════════════════════════════════════════════════════════
 * destructive_guard.php — interlock for harnesses that DROP/TRUNCATE
 * ════════════════════════════════════════════════════════════════════
 *
 * Why this exists
 * ---------------
 * tests/e2e/comm_lifecycle.php and comm_v1_lifecycle.php DROP TABLE and
 * TRUNCATE users/notifications/department_tasks in whatever database
 * .fkss_env.php names. That is the SAME filename a production deployment
 * uses, and config.php explicitly supports a repo-root copy as a fallback
 * layout. Without a gate, running the test suite on such a host destroys
 * live data. This was demonstrated during the 2026-10-02 audit: pointing a
 * runner at a production-shaped database dropped four tables (messages,
 * message_threads, message_thread_participants, notification_reads) and
 * truncated users.
 *
 * Design: DEFAULT DENY (allowlist, not denylist)
 * ----------------------------------------------
 * A denylist of "known production names" is unsafe -- it only blocks the
 * names somebody remembered to list, and the real production database
 * (arkeonet_felegekidusan) looks like an ordinary identifier. So the rule is
 * inverted: a database is refused unless it positively identifies itself as
 * disposable. Two independent conditions must BOTH hold:
 *
 *   1. The operator asserts intent:  SSMS_AUDIT_TESTING === '1'
 *      Strict string identity. '0', 'true', 'yes', 'TRUE', ' 1', '1 ',
 *      empty and unset all fail closed. A typo must never read as consent.
 *
 *   2. The target database name is recognisably disposable: it matches
 *      SAFE_NAME_PATTERN (contains test / e2e / smoke / sandbox / scratch /
 *      ci / tmp), or it is named explicitly in the SSMS_DISPOSABLE_DB
 *      allowlist (comma-separated) for teams whose test database does not
 *      follow that convention.
 *
 * Condition 1 alone is not enough: an operator who exports the variable in
 * their shell and then runs the suite on a production checkout would still
 * destroy data. Condition 2 is what actually protects the database.
 */

// Exit code used for every refusal, so callers and tests can assert on it.
const SSMS_GUARD_REFUSED = 2;

const SSMS_SAFE_NAME_PATTERN = '/(?:^|[_-])(?:test|tests|e2e|smoke|sandbox|scratch|ci|tmp)(?:[_-]|$)|(?:test|e2e|smoke|sandbox|scratch)/i';

/**
 * Refuse to continue unless the target database is provably disposable.
 *
 * @param string      $dbName   database the caller is about to mutate
 * @param string|null $context  short label for the error message
 */
function ssms_require_disposable_database(string $dbName, ?string $context = null): void
{
    $label = $context !== null ? "[$context] " : '';

    // ── 1. explicit, exact operator intent ────────────────────────────────
    $marker = getenv('SSMS_AUDIT_TESTING');
    if ($marker === false || $marker !== '1') {
        $shown = $marker === false ? '(unset)' : "'" . $marker . "'";
        fwrite(STDERR,
            "{$label}REFUSED: this harness DROPs and TRUNCATEs tables.\n"
            . "  SSMS_AUDIT_TESTING must be exactly '1'; got {$shown}.\n"
            . "  Set it only when the target database is disposable.\n");
        exit(SSMS_GUARD_REFUSED);
    }

    // ── 2. the database must identify itself as disposable ────────────────
    $dbName = trim($dbName);
    if ($dbName === '') {
        fwrite(STDERR, "{$label}REFUSED: no database name resolved from the environment.\n");
        exit(SSMS_GUARD_REFUSED);
    }

    $allowlist = array_filter(array_map('trim',
        explode(',', (string)(getenv('SSMS_DISPOSABLE_DB') ?: ''))), 'strlen');

    $explicitlyAllowed = in_array($dbName, $allowlist, true);
    $looksDisposable   = (bool)preg_match(SSMS_SAFE_NAME_PATTERN, $dbName);

    if (!$explicitlyAllowed && !$looksDisposable) {
        fwrite(STDERR,
            "{$label}REFUSED: '{$dbName}' is not recognisable as a disposable test database.\n"
            . "  This harness DROPs and TRUNCATEs tables; it will not run against a\n"
            . "  database whose name does not say it is throwaway.\n"
            . "  Either use a name containing test/e2e/smoke/sandbox/scratch, or set\n"
            . "  SSMS_DISPOSABLE_DB='{$dbName}' to assert that it is disposable.\n");
        exit(SSMS_GUARD_REFUSED);
    }
}
