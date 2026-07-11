<?php
/**
 * ============================================================
 * ACADEMIC YEAR ROLLOVER TOOL   (Super Admin only)
 * ============================================================
 * URL: /admin/tools/year_rollover.php
 *
 * WHAT THIS SOLVES
 * ----------------
 * Before this tool, starting a new school year left every class roster
 * EMPTY, because enrolments are tied to a specific academic year and
 * nothing carried them forward. Staff would have had to re-enrol ~5,000
 * students by hand. This tool does it in one safe, all-or-nothing step.
 *
 * WHAT IT DOES
 * ------------
 *   1. Makes the year you choose the new "current" year (atomically —
 *      the system can never be left with zero current years).
 *   2. Moves every ACTIVE, non-archived student from the year you are
 *      CLOSING into the new year, using the mode you pick:
 *        • "Carry forward" (default, safest): same class, new year.
 *          Rosters fill immediately; staff promote individuals later.
 *        • "Promote one level": each student moves to the class one
 *          level higher; students in the top class are marked graduated.
 *   3. Marks the closed year's enrolments as "completed".
 *   4. Keeps members' current-class fields in step.
 *
 * Everything runs inside ONE database transaction: it all succeeds or
 * nothing changes. There is a preview screen first, and you must type a
 * confirmation word before anything is written.
 *
 * ⚠️ ALWAYS take a full database backup before running this.
 * ============================================================
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';

// ── School Admin (owner) + Super Admin (break-glass) ONLY ──
if (empty($_SESSION['admin_logged_in']) || !in_array($_SESSION['admin_role'] ?? '', ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem">Only a School Admin or Super Admin can run the year rollover.</p>');
}

// Guarantee the lifecycle `status` column exists before we touch it below.
if (function_exists('ay_ensure_schema')) { ay_ensure_schema($conn); }

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$isPost  = ($_SERVER['REQUEST_METHOD'] === 'POST');
$report  = [];
$errors  = [];
$done    = false;

// Load the list of academic years for the dropdowns.
$years = [];
$res = $conn->query("SELECT id, year_name, ec_year, is_current FROM academic_years ORDER BY ec_year DESC, id DESC");
if ($res) { while ($r = $res->fetch_assoc()) { $years[] = $r; } }

// Find the current year (the natural "year to close").
$currentYear = null;
foreach ($years as $y) { if ((int)$y['is_current'] === 1) { $currentYear = $y; break; } }

/**
 * Count active, non-archived enrolments in a given year (for the preview).
 */
function countActiveEnrolments($conn, $yearId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM class_enrollments ce
        JOIN members m ON m.id = ce.member_id
        WHERE ce.academic_year_id = ?
          AND ce.status = 'active'
          AND m.status <> 'archived'
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $yearId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

// ============================================================
// RUN THE ROLLOVER (POST)
// ============================================================
if ($isPost && ($_POST['action'] ?? '') === 'run') {

    // CSRF
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token expired. Please reload the page and try again.';
    }

    $oldYearId  = (int)($_POST['old_year_id'] ?? 0);
    $newYearId  = (int)($_POST['new_year_id'] ?? 0);
    $mode       = ($_POST['mode'] ?? 'carry_forward') === 'promote' ? 'promote' : 'carry_forward';
    $confirm    = trim($_POST['confirm'] ?? '');

    if (!$errors && $confirm !== 'ROLLOVER') {
        $errors[] = 'You must type the word ROLLOVER exactly to confirm.';
    }
    if (!$errors && (!$oldYearId || !$newYearId)) {
        $errors[] = 'Please choose both the year to close and the new year.';
    }
    if (!$errors && $oldYearId === $newYearId) {
        $errors[] = 'The year to close and the new year must be different.';
    }

    if (!$errors) {
        $conn->begin_transaction();
        try {
            // 1. Make the new year the current one — atomically AND
            //    lifecycle-correct (status + is_current together). Any currently
            //    active year is CLOSED; the chosen new year becomes active. Done
            //    inline (not via ay_switch_active) because that helper opens its
            //    own transaction, which cannot nest inside this one.
            $st = $conn->prepare("UPDATE academic_years SET is_current = 0, status = CASE WHEN status = 'active' THEN 'closed' ELSE status END WHERE id <> ?");
            $st->bind_param("i", $newYearId);
            $st->execute();
            $st->close();
            $st = $conn->prepare("UPDATE academic_years SET is_current = 1, status = 'active' WHERE id = ?");
            $st->bind_param("i", $newYearId);
            $st->execute();
            $st->close();

            $carried = 0; $promoted = 0; $graduated = 0; $skipped = 0;

            if ($mode === 'carry_forward') {
                // 2a. Copy every active, non-archived enrolment into the new
                //     year, SAME class. INSERT IGNORE skips anyone already
                //     enrolled there, so re-running is safe.
                $st = $conn->prepare("
                    INSERT IGNORE INTO class_enrollments
                        (member_id, class_id, academic_year_id, enrolled_at, status, enrolled_by)
                    SELECT ce.member_id, ce.class_id, ?, CURDATE(), 'active', ?
                    FROM class_enrollments ce
                    JOIN members m ON m.id = ce.member_id
                    WHERE ce.academic_year_id = ?
                      AND ce.status = 'active'
                      AND m.status <> 'archived'
                ");
                $st->bind_param("iii", $newYearId, $adminId, $oldYearId);
                $st->execute();
                $carried = $st->affected_rows;
                $st->close();

            } else {
                // 2b. Promote one level. For each active enrolment, find the
                //     class exactly one level higher. If none exists, the
                //     student is at the top → mark graduated. If more than one
                //     class shares that level, skip (ambiguous → do by hand).
                $sel = $conn->prepare("
                    SELECT ce.member_id, ce.class_id, c.level_order
                    FROM class_enrollments ce
                    JOIN members m ON m.id = ce.member_id
                    JOIN classes c ON c.id = ce.class_id
                    WHERE ce.academic_year_id = ?
                      AND ce.status = 'active'
                      AND m.status <> 'archived'
                ");
                $sel->bind_param("i", $oldYearId);
                $sel->execute();
                $rows = $sel->get_result();
                $sel->close();

                $insEnr = $conn->prepare("
                    INSERT IGNORE INTO class_enrollments
                        (member_id, class_id, academic_year_id, enrolled_at, status, promoted_from, enrolled_by)
                    VALUES (?, ?, ?, CURDATE(), 'active', ?, ?)
                ");
                $updMember = $conn->prepare("UPDATE members SET current_class_id = ?, promoted_at = CURDATE() WHERE id = ?");
                $gradMember = $conn->prepare("UPDATE members SET academic_status = 'graduated' WHERE id = ?");

                while ($row = $rows->fetch_assoc()) {
                    $memberId = (int)$row['member_id'];
                    $fromClass = (int)$row['class_id'];
                    $level = (int)$row['level_order'];

                    // Next level class(es)
                    $nx = $conn->prepare("SELECT id FROM classes WHERE level_order = ? AND is_active = 1");
                    $nextLevel = $level + 1;
                    $nx->bind_param("i", $nextLevel);
                    $nx->execute();
                    $nres = $nx->get_result();
                    $targets = [];
                    while ($tr = $nres->fetch_assoc()) { $targets[] = (int)$tr['id']; }
                    $nx->close();

                    if (count($targets) === 1) {
                        $toClass = $targets[0];
                        $insEnr->bind_param("iiiii", $memberId, $toClass, $newYearId, $fromClass, $adminId);
                        $insEnr->execute();
                        $updMember->bind_param("ii", $toClass, $memberId);
                        $updMember->execute();
                        $promoted++;
                    } elseif (count($targets) === 0) {
                        // No higher class → graduate.
                        $gradMember->bind_param("i", $memberId);
                        $gradMember->execute();
                        $graduated++;
                    } else {
                        // Ambiguous (several classes at the next level) → leave for staff.
                        $skipped++;
                    }
                }
                $insEnr->close();
                $updMember->close();
                $gradMember->close();
            }

            // 3. Mark the closed year's enrolments as completed.
            $st = $conn->prepare("UPDATE class_enrollments SET status = 'completed' WHERE academic_year_id = ? AND status = 'active'");
            $st->bind_param("i", $oldYearId);
            $st->execute();
            $closedCount = $st->affected_rows;
            $st->close();

            $conn->commit();
            $done = true;
            $report = [
                'mode'      => $mode,
                'carried'   => $carried,
                'promoted'  => $promoted,
                'graduated' => $graduated,
                'skipped'   => $skipped,
                'closed'    => $closedCount,
            ];

            // Optional: log it (non-fatal).
            try {
                $lg = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, created_at) VALUES (?, ?, 'Year Rollover', ?, NOW())");
                if ($lg) {
                    $uname = $_SESSION['admin_username'] ?? 'admin';
                    $details = "Mode=$mode closed_year=$oldYearId new_year=$newYearId carried=$carried promoted=$promoted graduated=$graduated skipped=$skipped";
                    $lg->bind_param("iss", $adminId, $uname, $details);
                    $lg->execute();
                    $lg->close();
                }
            } catch (Exception $e) { /* logging is optional */ }

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Year rollover failed: " . $e->getMessage());
            $errors[] = 'The rollover failed and NOTHING was changed. Details were written to the error log. ' . $e->getMessage();
        }
    }
}

// Preview counts
$previewOldId = $currentYear ? (int)$currentYear['id'] : ($years[0]['id'] ?? 0);
$previewCount = $previewOldId ? countActiveEnrolments($conn, $previewOldId) : 0;
$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Academic Year Rollover</title>
<style>
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;max-width:760px;margin:0 auto;padding:2rem;line-height:1.5}
  h1{color:#f8fafc}
  .card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1.25rem 1.5rem;margin:1rem 0}
  label{display:block;font-weight:600;margin:.75rem 0 .25rem}
  select,input[type=text]{width:100%;padding:.5rem;border-radius:8px;border:1px solid #475569;background:#0f172a;color:#e2e8f0}
  .warn{background:#7f1d1d;border:1px solid #b91c1c;color:#fecaca;padding:1rem;border-radius:8px}
  .ok{background:#064e3b;border:1px solid #059669;color:#a7f3d0;padding:1rem;border-radius:8px}
  .err{background:#7f1d1d;border:1px solid #b91c1c;color:#fecaca;padding:1rem;border-radius:8px}
  button{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:.7rem 1.4rem;font-weight:700;font-size:1rem;cursor:pointer;margin-top:1rem}
  button.safe{background:#2563eb}
  code{background:#0f172a;padding:.1rem .4rem;border-radius:4px;color:#fbbf24}
  a{color:#60a5fa}
  .radio{margin:.35rem 0}
</style>
</head>
<body>
<h1>🔄 Academic Year Rollover</h1>

<?php if ($done): ?>
    <div class="ok">
        <strong>✅ Rollover complete.</strong>
        <ul>
            <?php if ($report['mode'] === 'carry_forward'): ?>
                <li>Students carried into the new year: <strong><?= (int)$report['carried'] ?></strong></li>
            <?php else: ?>
                <li>Students promoted one level: <strong><?= (int)$report['promoted'] ?></strong></li>
                <li>Students graduated (top level): <strong><?= (int)$report['graduated'] ?></strong></li>
                <li>Skipped (needs manual placement): <strong><?= (int)$report['skipped'] ?></strong></li>
            <?php endif; ?>
            <li>Old-year enrolments closed: <strong><?= (int)$report['closed'] ?></strong></li>
        </ul>
    </div>
    <p><a href="/admin/dashboard.php">← Back to dashboard</a></p>
<?php else: ?>

    <?php foreach ($errors as $e): ?>
        <div class="err">⚠️ <?= e($e) ?></div>
    <?php endforeach; ?>

    <div class="warn">
        <strong>Before you run this:</strong>
        <ol>
            <li>Take a <strong>full database backup</strong> (Super Admin → Backup).</li>
            <li>Make sure the <strong>new academic year already exists</strong> (Education → Academic Years). Create it first if needed.</li>
            <li>Run this <strong>once</strong>, ideally when no one else is using the system.</li>
        </ol>
        This is safe to re-run if it fails, but do not run it repeatedly "just in case".
    </div>

    <form method="post" class="card" onsubmit="return confirm('Run the year rollover now? Make sure you have a backup.');">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <label for="old_year_id">Year to CLOSE (students move OUT of this year)</label>
        <select name="old_year_id" id="old_year_id">
            <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= ($currentYear && $y['id'] == $currentYear['id']) ? 'selected' : '' ?>>
                    <?= e($y['year_name']) ?><?= $y['is_current'] ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="new_year_id">New year to OPEN and make current</label>
        <select name="new_year_id" id="new_year_id">
            <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= ($currentYear && $y['id'] == $currentYear['id']) ? '' : 'selected' ?>>
                    <?= e($y['year_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>How should students move?</label>
        <div class="radio"><label style="font-weight:400"><input type="radio" name="mode" value="carry_forward" checked>
            <strong>Carry forward (recommended)</strong> — everyone stays in the same class, in the new year. Staff promote individuals afterwards.</label></div>
        <div class="radio"><label style="font-weight:400"><input type="radio" name="mode" value="promote">
            <strong>Promote one level</strong> — everyone moves up one class level; the top class is marked graduated. (Only use this if each level has exactly one class.)</label></div>

        <label for="confirm">Type <code>ROLLOVER</code> to confirm</label>
        <input type="text" name="confirm" id="confirm" autocomplete="off" placeholder="ROLLOVER">

        <p style="color:#94a3b8;font-size:.9rem;margin-top:.75rem">
            About <strong><?= (int)$previewCount ?></strong> active students are currently enrolled in the year selected to close.
        </p>

        <button type="submit">Run Year Rollover</button>
        <a href="/admin/dashboard.php" style="margin-left:1rem">Cancel</a>
    </form>
<?php endif; ?>

</body>
</html>
