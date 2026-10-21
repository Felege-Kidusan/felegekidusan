<?php
/**
 * GuardianService — Family Link CRUD, linking, merging, family reads and
 * picker search. All mutating operations audit to activity_logs.
 * Companion: GuardianMatchService (scoring/blocking).
 */

namespace App\Services;

require_once __DIR__ . '/GuardianMatchService.php';

class GuardianService
{
    public static function audit(\mysqli $conn, string $action, string $details): void
    {
        try {
            $uid = (int)($_SESSION['admin_id'] ?? 0);
            $uname = (string)($_SESSION['admin_username'] ?? 'admin');
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $stmt = $conn->prepare('INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            if ($stmt) {
                $stmt->bind_param('issss', $uid, $uname, $action, $details, $ip);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // auditing must never break the managing action
        }
    }

    public static function create(\mysqli $conn, array $data, string $source = 'hr'): int
    {
        $name = trim((string)($data['display_name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Guardian name required.');
        }
        $p1 = trim((string)($data['phone_primary'] ?? ''));
        $p2 = trim((string)($data['phone_alt'] ?? ''));
        $n91 = GuardianMatchService::phoneN9($p1);
        $n92 = GuardianMatchService::phoneN9($p2);
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $stmt = $conn->prepare('INSERT INTO guardians (display_name, phone_primary, phone_alt, phone_primary_n9, phone_alt_n9, city, sub_city, woreda, mender, block_number, house, source, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$stmt) {
            throw new \RuntimeException('Guardian insert prepare failed: ' . $conn->error);
        }
        $city = (string)($data['city'] ?? '');
        $sub = (string)($data['sub_city'] ?? '');
        $wor = (string)($data['woreda'] ?? '');
        $men = (string)($data['mender'] ?? '');
        $blk = (string)($data['block_number'] ?? '');
        $hou = (string)($data['house'] ?? '');
        $stmt->bind_param('ssssssssssssi', $name, $p1, $p2, $n91, $n92, $city, $sub, $wor, $men, $blk, $hou, $source, $uid);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Guardian insert failed: ' . $stmt->error);
        }
        $id = (int)$conn->insert_id;
        $stmt->close();
        foreach ((array)($data['aliases'] ?? []) as $alias) {
            self::addAlias($conn, $id, (string)$alias, $source);
        }
        self::audit($conn, 'Guardian Created', "Guardian '{$name}' (id {$id}) created via {$source}.");
        return $id;
    }

    public static function addAlias(\mysqli $conn, int $guardianId, string $alias, string $source = 'hr'): void
    {
        $alias = trim($alias);
        if ($alias === '') {
            return;
        }
        $stmt = $conn->prepare('INSERT IGNORE INTO guardian_aliases (guardian_id, alias_name, source) VALUES (?,?,?)');
        if ($stmt) {
            $stmt->bind_param('iss', $guardianId, $alias, $source);
            $stmt->execute();
            $stmt->close();
        }
    }

    public static function update(\mysqli $conn, int $id, array $data): void
    {
        $row = self::get($conn, $id);
        if (!$row) {
            throw new \InvalidArgumentException('Guardian not found.');
        }
        $name = trim((string)($data['display_name'] ?? $row['display_name']));
        if ($name === '') {
            throw new \InvalidArgumentException('Guardian name cannot be empty.');
        }
        $p1 = trim((string)($data['phone_primary'] ?? $row['phone_primary']));
        $p2 = trim((string)($data['phone_alt'] ?? $row['phone_alt']));
        $n91 = GuardianMatchService::phoneN9($p1);
        $n92 = GuardianMatchService::phoneN9($p2);
        $stmt = $conn->prepare('UPDATE guardians SET display_name=?, phone_primary=?, phone_alt=?, phone_primary_n9=?, phone_alt_n9=?, city=?, sub_city=?, woreda=?, mender=?, block_number=?, house=? WHERE id=?');
        if (!$stmt) {
            throw new \RuntimeException('Guardian update prepare failed: ' . $conn->error);
        }
        $city = (string)($data['city'] ?? $row['city']);
        $sub = (string)($data['sub_city'] ?? $row['sub_city']);
        $wor = (string)($data['woreda'] ?? $row['woreda']);
        $men = (string)($data['mender'] ?? $row['mender']);
        $blk = (string)($data['block_number'] ?? $row['block_number']);
        $hou = (string)($data['house'] ?? $row['house']);
        $stmt->bind_param('sssssssssssi', $name, $p1, $p2, $n91, $n92, $city, $sub, $wor, $men, $blk, $hou, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Guardian update failed.');
        }
        // Propagate contact details to the legacy denormalized member
        // columns so existing screens/exports stay consistent until the
        // guardian tables fully replace them (primary links only: a
        // second guardian must never overwrite the primary's columns).
        $synced = self::syncLegacy($conn, $id);
        self::audit($conn, 'Guardian Updated', "Guardian id {$id} updated (single source of truth: all linked children see the new values; {$synced} member row(s) synced).");
    }

    /**
     * Copy the guardian's name/phones into members.guardian_* for every
     * member whose PRIMARY guardian this is (or one member when given).
     * @return int rows updated
     */
    public static function syncLegacy(\mysqli $conn, int $guardianId, int $onlyMemberId = 0): int
    {
        $sql = 'UPDATE members m JOIN member_guardians mg ON mg.member_id = m.id JOIN guardians g ON g.id = mg.guardian_id
                SET m.guardian_name = g.display_name, m.guardian_phone1 = g.phone_primary, m.guardian_phone2 = g.phone_alt
                WHERE mg.guardian_id = ? AND mg.is_primary = 1';
        if ($onlyMemberId > 0) {
            $sql .= ' AND mg.member_id = ?';
        }
        $sync = $conn->prepare($sql);
        if (!$sync) {
            return 0;
        }
        if ($onlyMemberId > 0) {
            $sync->bind_param('ii', $guardianId, $onlyMemberId);
        } else {
            $sync->bind_param('i', $guardianId);
        }
        $n = 0;
        if ($sync->execute()) {
            $n = max(0, (int)$sync->affected_rows);
        }
        $sync->close();
        return $n;
    }

    /**
     * Guarantee the member has exactly one primary link when it has any
     * link at all (lowest contact_priority, then oldest link wins).
     */
    public static function ensurePrimary(\mysqli $conn, int $memberId): void
    {
        $memberId = (int)$memberId;
        $r = $conn->query("SELECT guardian_id, is_primary FROM member_guardians WHERE member_id = {$memberId} ORDER BY is_primary DESC, contact_priority ASC, created_at ASC, guardian_id ASC");
        if (!$r) {
            return;
        }
        $rows = [];
        while ($x = $r->fetch_assoc()) {
            $rows[] = $x;
        }
        if (!$rows) {
            return;
        }
        $primaries = array_filter($rows, fn($x) => (int)$x['is_primary'] === 1);
        if (count($primaries) === 1) {
            return;
        }
        $keep = (int)$rows[0]['guardian_id'];
        $conn->query("UPDATE member_guardians SET is_primary = 0 WHERE member_id = {$memberId}");
        $conn->query("UPDATE member_guardians SET is_primary = 1 WHERE member_id = {$memberId} AND guardian_id = {$keep}");
        self::syncLegacy($conn, $keep, $memberId);
    }

    /** True when the member has at least one primary guardian link. */
    public static function primaryGuardianId(\mysqli $conn, int $memberId): int
    {
        $stmt = $conn->prepare('SELECT guardian_id FROM member_guardians WHERE member_id = ? AND is_primary = 1 LIMIT 1');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['guardian_id'] : 0;
    }

    /**
     * Attach a member to a guardian using its legacy guardian_* columns:
     * explicit pick -> typo-tolerant auto-match -> create new guardian.
     * Review-band near-misses are filed as pending suggestions. Shared by
     * HR registration, web member edit and the mobile PUT route.
     *
     * @return array{guardian_id:int, mode:string}  mode: picked|auto|created|none
     */
    public static function attachFromLegacy(\mysqli $conn, int $memberId, int $pickedGuardianId = 0, string $source = 'hr'): array
    {
        $stmt = $conn->prepare('SELECT guardian_name, guardian_phone1, guardian_phone2, guardian_city, guardian_sub_city, guardian_woreda, guardian_mender, guardian_block_number, guardian_house FROM members WHERE id = ?');
        if (!$stmt) {
            return ['guardian_id' => 0, 'mode' => 'none'];
        }
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$m) {
            return ['guardian_id' => 0, 'mode' => 'none'];
        }
        if ($pickedGuardianId > 0) {
            self::linkMember($conn, $pickedGuardianId, $memberId, 'other', 1, true);
            self::audit($conn, 'Guardian Linked', "Member {$memberId} linked to picked guardian {$pickedGuardianId}.");
            return ['guardian_id' => $pickedGuardianId, 'mode' => 'picked'];
        }
        $name = trim((string)($m['guardian_name'] ?? ''));
        if ($name === '') {
            return ['guardian_id' => 0, 'mode' => 'none'];
        }
        $p1 = trim((string)($m['guardian_phone1'] ?? ''));
        $p2 = trim((string)($m['guardian_phone2'] ?? ''));
        // Already linked (e.g. registration *upgrade* of an existing member):
        // this is an edit of the primary guardian, not a new attachment.
        $existing = self::primaryGuardianId($conn, $memberId);
        if ($existing > 0) {
            self::update($conn, $existing, ['display_name' => $name, 'phone_primary' => $p1, 'phone_alt' => $p2]);
            return ['guardian_id' => $existing, 'mode' => 'synced'];
        }
        // D5 (owner decision 2026-10-21): siblings share a guardian when the
        // mother's full name matches exactly AND the members' father +
        // grandfather names match — even when no phone was recorded.
        // A phone match alone stays a scored (review) suggestion below.
        $familyGid = self::familyNameGuardianMatch($conn, $memberId, $name);
        if ($familyGid > 0) {
            self::linkMember($conn, $familyGid, $memberId, 'other', 1, true);
            self::audit($conn, 'Guardian Linked', "Member {$memberId} auto-linked to guardian {$familyGid} (same guardian name and same father/grandfather names).");
            return ['guardian_id' => $familyGid, 'mode' => 'auto_family'];
        }
        $probe = [
            'display_name' => $name,
            'phone_primary' => $p1,
            'phone_alt' => $p2,
            'phone_primary_n9' => GuardianMatchService::phoneN9($p1),
            'phone_alt_n9' => GuardianMatchService::phoneN9($p2),
            'aliases' => [],
            'city' => (string)($m['guardian_city'] ?? ''),
            'sub_city' => (string)($m['guardian_sub_city'] ?? ''),
            'woreda' => (string)($m['guardian_woreda'] ?? ''),
            'mender' => (string)($m['guardian_mender'] ?? ''),
            'block_number' => (string)($m['guardian_block_number'] ?? ''),
            'house' => (string)($m['guardian_house'] ?? ''),
        ];
        $cands = GuardianMatchService::findCandidates($conn, $name, $p1, $probe);
        if ($p2 !== '') {
            foreach (GuardianMatchService::findCandidates($conn, $name, $p2, $probe) as $c) {
                $cands[] = $c;
            }
        }
        $bestScore = 0.0;
        $bestRow = null;
        $best = null;
        $seen = [];
        foreach ($cands as $c) {
            if (isset($seen[(int)$c['id']])) {
                continue;
            }
            $seen[(int)$c['id']] = true;
            $r = GuardianMatchService::score($probe, $c);
            if ($r['score'] > $bestScore) {
                $bestScore = $r['score'];
                $bestRow = $c;
                $best = $r;
            }
        }
        if ($best !== null && $best['band'] === 'auto') {
            $gid = (int)$bestRow['id'];
            self::linkMember($conn, $gid, $memberId, 'other', 1, true);
            self::audit($conn, 'Guardian Linked', "Member {$memberId} auto-linked to guardian {$gid} (score {$best['score']}).");
            return ['guardian_id' => $gid, 'mode' => 'auto'];
        }
        $gid = self::create($conn, [
            'display_name' => $name,
            'phone_primary' => $p1,
            'phone_alt' => $p2,
            'city' => $probe['city'],
            'sub_city' => $probe['sub_city'],
            'woreda' => $probe['woreda'],
            'mender' => $probe['mender'],
            'block_number' => $probe['block_number'],
            'house' => $probe['house'],
        ], $source);
        self::linkMember($conn, $gid, $memberId, 'other', 1, true);
        if ($best !== null && $best['band'] === 'review') {
            $sg = $conn->prepare("INSERT INTO guardian_link_suggestions (guardian_a, guardian_b, member_id, score, band, evidence, status) VALUES (?,?,?,?, 'review', ?, 'pending')");
            if ($sg) {
                $a = (int)$bestRow['id'];
                $ev = json_encode($best['evidence'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $sg->bind_param('iiids', $a, $gid, $memberId, $bestScore, $ev);
                $sg->execute();
                $sg->close();
            }
        }
        return ['guardian_id' => $gid, 'mode' => 'created'];
    }

    /**
     * D5 sibling rule: the ONE active guardian whose name (display name or
     * alias, normalized) equals $guardianName and who is already linked to
     * another non-archived member with the same normalized father_name AND
     * grandfather_name. Returns 0 when there is no such guardian or when
     * more than one distinct guardian qualifies (never guessed).
     */
    private static function familyNameGuardianMatch(\mysqli $conn, int $memberId, string $guardianName): int
    {
        $stmt = $conn->prepare('SELECT father_name, grandfather_name FROM members WHERE id = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $me = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $father = GuardianMatchService::normalizeName((string)($me['father_name'] ?? ''));
        $grandfather = GuardianMatchService::normalizeName((string)($me['grandfather_name'] ?? ''));
        $wanted = GuardianMatchService::normalizeName($guardianName);
        if ($father === '' || $grandfather === '' || $wanted === '') {
            return 0;
        }
        $stmt = $conn->prepare(
            "SELECT g.id, g.display_name, m2.father_name, m2.grandfather_name
               FROM member_guardians mg
               JOIN guardians g ON g.id = mg.guardian_id
               JOIN members m2 ON m2.id = mg.member_id
              WHERE mg.member_id <> ?
                AND g.status = 'active'
                AND m2.status <> 'archived'
                AND TRIM(m2.father_name) = ?
              LIMIT 200"
        );
        if (!$stmt) {
            return 0;
        }
        $rawFather = trim((string)($me['father_name'] ?? ''));
        $stmt->bind_param('is', $memberId, $rawFather);
        $stmt->execute();
        $res = $stmt->get_result();
        $hits = [];
        while ($res && ($row = $res->fetch_assoc())) {
            if (GuardianMatchService::normalizeName((string)$row['father_name']) !== $father
                || GuardianMatchService::normalizeName((string)$row['grandfather_name']) !== $grandfather) {
                continue;
            }
            $gid = (int)$row['id'];
            if (isset($hits[$gid])) {
                continue;
            }
            $names = [GuardianMatchService::normalizeName((string)$row['display_name'])];
            $al = $conn->prepare('SELECT alias_name FROM guardian_aliases WHERE guardian_id = ?');
            if ($al) {
                $al->bind_param('i', $gid);
                $al->execute();
                $ar = $al->get_result();
                while ($ar && ($a = $ar->fetch_assoc())) {
                    $names[] = GuardianMatchService::normalizeName((string)$a['alias_name']);
                }
                $al->close();
            }
            if (in_array($wanted, $names, true)) {
                $hits[$gid] = true;
            }
        }
        $stmt->close();
        return count($hits) === 1 ? (int)array_key_first($hits) : 0;
    }

    public static function get(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM guardians WHERE id = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $row['aliases'] = self::aliases($conn, $id);
        return $row;
    }

    public static function aliases(\mysqli $conn, int $id): array
    {
        $out = [];
        $stmt = $conn->prepare('SELECT alias_name FROM guardian_aliases WHERE guardian_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($a = $r->fetch_assoc()) {
                $out[] = (string)$a['alias_name'];
            }
            $stmt->close();
        }
        return $out;
    }

    public static function linkMember(\mysqli $conn, int $guardianId, int $memberId, string $relation = 'other', int $priority = 1, bool $isPrimary = false, bool $audit = true): void
    {
        if ($guardianId <= 0 || $memberId <= 0) {
            throw new \InvalidArgumentException('Guardian and member are required.');
        }
        $g = self::get($conn, $guardianId);
        if (!$g) {
            throw new \InvalidArgumentException('Guardian not found.');
        }
        if ($g['status'] === 'merged') {
            throw new \InvalidArgumentException('That guardian was merged into #' . (int)$g['merged_into'] . ' — link the surviving record instead.');
        }
        $mc = $conn->prepare('SELECT id FROM members WHERE id = ?');
        if ($mc) {
            $mc->bind_param('i', $memberId);
            $mc->execute();
            $exists = $mc->get_result()->fetch_assoc();
            $mc->close();
            if (!$exists) {
                throw new \InvalidArgumentException('Member not found.');
            }
        }
        if (!in_array($relation, ['father', 'mother', 'other'], true)) {
            $relation = 'other';
        }
        $priority = max(1, $priority);
        // First link of a member is always its primary contact.
        if (!$isPrimary && self::primaryGuardianId($conn, $memberId) === 0) {
            $isPrimary = true;
        }
        $pri = $isPrimary ? 1 : 0;
        if ($pri === 1) {
            $conn->query("UPDATE member_guardians SET is_primary = 0 WHERE member_id = " . (int)$memberId);
        }
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $stmt = $conn->prepare('INSERT INTO member_guardians (member_id, guardian_id, relation, contact_priority, is_primary, created_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE relation=VALUES(relation), contact_priority=VALUES(contact_priority), is_primary=VALUES(is_primary)');
        if (!$stmt) {
            throw new \RuntimeException('Link prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('iisiii', $memberId, $guardianId, $relation, $priority, $pri, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Link failed.');
        }
        if ($pri === 1) {
            self::syncLegacy($conn, $guardianId, $memberId);
        }
        if ($audit) {
            self::audit($conn, 'Guardian Linked', "Member {$memberId} linked to guardian {$guardianId} ({$relation}, priority {$priority}" . ($pri ? ', primary' : '') . ').');
        }
    }

    public static function unlinkMember(\mysqli $conn, int $guardianId, int $memberId): void
    {
        $stmt = $conn->prepare('DELETE FROM member_guardians WHERE member_id = ? AND guardian_id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Unlink prepare failed.');
        }
        $stmt->bind_param('ii', $memberId, $guardianId);
        $stmt->execute();
        $stmt->close();
        self::ensurePrimary($conn, $memberId);
        self::audit($conn, 'Guardian Unlinked', "Member {$memberId} unlinked from guardian {$guardianId}.");
    }

    /** Merge $from into $to: move links, mark merged, audit. Reversible via merged_into. */
    public static function merge(\mysqli $conn, int $from, int $to, int $resolvedBy = 0): void
    {
        if ($from === $to) {
            throw new \InvalidArgumentException('Cannot merge a guardian into itself.');
        }
        $fromRow = self::get($conn, $from);
        $toRow = self::get($conn, $to);
        if (!$fromRow || !$toRow) {
            throw new \InvalidArgumentException('Both guardians must exist to merge.');
        }
        if ($fromRow['status'] === 'merged' || $toRow['status'] === 'merged') {
            throw new \InvalidArgumentException('Cannot merge an already-merged guardian.');
        }
        $conn->begin_transaction();
        try {
            // members touched by this merge (for primary repair + legacy sync)
            $touched = [];
            $tr = $conn->query("SELECT DISTINCT member_id FROM member_guardians WHERE guardian_id IN ({$from}, {$to})");
            if ($tr) {
                while ($x = $tr->fetch_assoc()) {
                    $touched[] = (int)$x['member_id'];
                }
            }
            // a colliding link (member linked to both) keeps the survivor's
            // row but inherits "primary" if the loser's link held it
            $conn->query("UPDATE member_guardians ex
                JOIN member_guardians mg ON mg.member_id = ex.member_id AND mg.guardian_id = {$from}
                SET ex.is_primary = 1
                WHERE ex.guardian_id = {$to} AND mg.is_primary = 1");
            $conn->query("DELETE mg FROM member_guardians mg
                JOIN member_guardians ex ON ex.member_id = mg.member_id AND ex.guardian_id = {$to}
                WHERE mg.guardian_id = {$from}");
            $conn->query("UPDATE member_guardians SET guardian_id = {$to} WHERE guardian_id = {$from}");
            // survivorship: keep the survivor's values, fill its blanks from the loser
            $sv = [];
            foreach (['city', 'sub_city', 'woreda', 'mender', 'block_number', 'house', 'photo_path'] as $k) {
                $sv[$k] = trim((string)($toRow[$k] ?? '')) !== '' ? (string)$toRow[$k] : (string)($fromRow[$k] ?? '');
            }
            $tP1 = trim((string)($toRow['phone_primary'] ?? ''));
            $tP2 = trim((string)($toRow['phone_alt'] ?? ''));
            $loserPhones = array_values(array_filter([trim((string)($fromRow['phone_primary'] ?? '')), trim((string)($fromRow['phone_alt'] ?? ''))], fn($x) => $x !== ''));
            foreach ($loserPhones as $lp) {
                $ln9 = GuardianMatchService::phoneN9($lp);
                if ($tP1 === '') {
                    $tP1 = $lp;
                } elseif ($tP2 === '' && $ln9 !== GuardianMatchService::phoneN9($tP1)) {
                    $tP2 = $lp;
                }
            }
            $n91 = GuardianMatchService::phoneN9($tP1);
            $n92 = GuardianMatchService::phoneN9($tP2);
            $up = $conn->prepare('UPDATE guardians SET phone_primary=?, phone_alt=?, phone_primary_n9=?, phone_alt_n9=?, city=?, sub_city=?, woreda=?, mender=?, block_number=?, house=?, photo_path=? WHERE id=?');
            if ($up) {
                $up->bind_param('sssssssssssi', $tP1, $tP2, $n91, $n92, $sv['city'], $sv['sub_city'], $sv['woreda'], $sv['mender'], $sv['block_number'], $sv['house'], $sv['photo_path'], $to);
                $up->execute();
                $up->close();
            }
            // the loser's spelling survives as an alias (searchable, scored)
            $conn->query("INSERT IGNORE INTO guardian_aliases (guardian_id, alias_name, source)
                SELECT {$to}, alias_name, source FROM guardian_aliases WHERE guardian_id = {$from}");
            $conn->query("DELETE FROM guardian_aliases WHERE guardian_id = {$from}");
            if (GuardianMatchService::normalizeName((string)$fromRow['display_name']) !== GuardianMatchService::normalizeName((string)$toRow['display_name'])) {
                self::addAlias($conn, $to, (string)$fromRow['display_name'], 'hr');
            }
            $stmt = $conn->prepare("UPDATE guardians SET status='merged', merged_into=? WHERE id=?");
            $stmt->bind_param('ii', $to, $from);
            $stmt->execute();
            $stmt->close();
            // other pending suggestions about the loser now concern the survivor;
            // self-pairs are closed
            $conn->query("UPDATE guardian_link_suggestions SET guardian_a = {$to} WHERE guardian_a = {$from} AND status = 'pending'");
            $conn->query("UPDATE guardian_link_suggestions SET guardian_b = {$to} WHERE guardian_b = {$from} AND status = 'pending'");
            $conn->query("UPDATE guardian_link_suggestions SET status = 'expired', resolved_at = NOW() WHERE status = 'pending' AND guardian_a = guardian_b");
            foreach ($touched as $mid) {
                self::ensurePrimary($conn, $mid);
            }
            self::syncLegacy($conn, $to);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        self::audit($conn, 'Guardian Merged', "Guardian {$from} merged into {$to} by actor {$resolvedBy}; links moved, old row kept with status=merged for reversal.");
    }

    public static function familyOfMember(\mysqli $conn, int $memberId): array
    {
        $guardians = [];
        $stmt = $conn->prepare('SELECT mg.relation, mg.contact_priority, mg.is_primary, g.* FROM member_guardians mg JOIN guardians g ON g.id = mg.guardian_id WHERE mg.member_id = ? ORDER BY mg.contact_priority ASC');
        if ($stmt) {
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($g = $r->fetch_assoc()) {
                $g['aliases'] = self::aliases($conn, (int)$g['id']);
                $guardians[] = $g;
            }
            $stmt->close();
        }
        return $guardians;
    }

    public static function siblingsOf(\mysqli $conn, int $memberId): array
    {
        $out = [];
        $stmt = $conn->prepare('SELECT DISTINCT m.id, m.member_code, m.student_name, m.full_name_am, m.current_section FROM member_guardians mg JOIN member_guardians sg ON sg.guardian_id = mg.guardian_id AND sg.member_id != mg.member_id JOIN members m ON m.id = sg.member_id WHERE mg.member_id = ? AND m.status != \'archived\' ORDER BY m.student_name');
        if ($stmt) {
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($s = $r->fetch_assoc()) {
                $out[] = $s;
            }
            $stmt->close();
        }
        return $out;
    }

    /** Typeahead for the HR picker: name prefix or phone-suffix match. */
    public static function searchForPicker(\mysqli $conn, string $q, int $limit = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return [];
        }
        // Partial phone search: any query with 4+ digits matches phone
        // substrings (HR often types the last digits they remember).
        $digits = preg_replace('/\D+/', '', $q);
        $digits = is_string($digits) ? $digits : '';
        // Typed prefixes carry the national 0 / +251 that the stored
        // 9-digit key drops; normalize the same way before substring match.
        if (strpos($digits, '251') === 0 && strlen($digits) > 5) {
            $digits = substr($digits, 3);
        } elseif (strpos($digits, '0') === 0) {
            $digits = ltrim($digits, '0');
        }
        if (strlen($digits) > 9) {
            $digits = substr($digits, -9);
        }
        $like = GuardianMatchService::normalizeName($q) . '%';
        $rows = [];
        if (strlen($digits) >= 4) {
            $s = $conn->prepare('SELECT * FROM guardians WHERE status != \'merged\' AND (phone_primary_n9 LIKE ? OR phone_alt_n9 LIKE ?) LIMIT ' . (int)$limit);
            if ($s) {
                $n = '%' . $digits . '%';
                $s->bind_param('ss', $n, $n);
                $s->execute();
                $r = $s->get_result();
                while ($g = $r->fetch_assoc()) {
                    $rows[(int)$g['id']] = $g;
                }
                $s->close();
            }
        }
        if (count($rows) < $limit) {
            $s = $conn->prepare('SELECT * FROM guardians WHERE status != \'merged\' AND display_name LIKE ? LIMIT ' . (int)$limit);
            if ($s) {
                $s->bind_param('s', $like);
                $s->execute();
                $r = $s->get_result();
                while ($g = $r->fetch_assoc()) {
                    $rows[(int)$g['id']] = $g;
                }
                $s->close();
            }
        }
        $out = [];
        foreach ($rows as $id => $g) {
            $g['children'] = self::childrenOf($conn, $id, 6);
            $g['aliases'] = self::aliases($conn, $id);
            $out[] = $g;
        }
        return $out;
    }

    /** Linked members of a guardian (id, name, code, section, relation, primary). */
    public static function childrenOf(\mysqli $conn, int $guardianId, int $limit = 50): array
    {
        $kids = [];
        $s = $conn->prepare('SELECT m.id, m.member_code, m.student_name, m.full_name_am, m.current_section, m.status, m.gender, mg.relation, mg.contact_priority, mg.is_primary FROM member_guardians mg JOIN members m ON m.id = mg.member_id WHERE mg.guardian_id = ? ORDER BY m.student_name LIMIT ' . max(1, (int)$limit));
        if ($s) {
            $s->bind_param('i', $guardianId);
            $s->execute();
            $r = $s->get_result();
            while ($k = $r->fetch_assoc()) {
                $kids[] = $k;
            }
            $s->close();
        }
        return $kids;
    }

    // ── Family Link page data ────────────────────────────────────────
    public static function stats(\mysqli $conn): array
    {
        $q = function (string $sql) use ($conn) {
            $r = $conn->query($sql);
            $row = $r ? $r->fetch_row() : null;
            return $row ? (string)$row[0] : '0';
        };
        return [
            'guardians' => (int)$q("SELECT COUNT(*) FROM guardians WHERE status = 'active'"),
            'families_multi' => (int)$q("SELECT COUNT(*) FROM (SELECT guardian_id FROM member_guardians GROUP BY guardian_id HAVING COUNT(*) >= 2) t"),
            'members_linked' => (int)$q("SELECT COUNT(DISTINCT member_id) FROM member_guardians"),
            'members_unlinked' => (int)$q("SELECT COUNT(*) FROM members m WHERE m.status != 'archived' AND TRIM(COALESCE(m.guardian_name,'')) != '' AND NOT EXISTS (SELECT 1 FROM member_guardians mg WHERE mg.member_id = m.id)"),
            'members_no_guardian' => (int)$q("SELECT COUNT(*) FROM members m WHERE m.status != 'archived' AND TRIM(COALESCE(m.guardian_name,'')) = '' AND NOT EXISTS (SELECT 1 FROM member_guardians mg WHERE mg.member_id = m.id)"),
            'pending_reviews' => (int)$q("SELECT COUNT(*) FROM guardian_link_suggestions WHERE status = 'pending'"),
            'merged' => (int)$q("SELECT COUNT(*) FROM guardians WHERE status = 'merged'"),
            'last_rematch' => $q("SELECT COALESCE(MAX(created_at), '') FROM activity_logs WHERE action = 'Guardian Rematch'"),
        ];
    }

    /**
     * Paged guardian browser. $filter: all | multi | nophone | recent | single
     * @return array{rows: array, total: int, page: int, pages: int}
     */
    public static function listGuardians(\mysqli $conn, string $q = '', string $filter = 'all', int $page = 1, int $per = 20): array
    {
        $q = trim($q);
        $per = max(5, min(50, $per));
        $page = max(1, $page);
        $where = ["g.status != 'merged'"];
        $types = '';
        $vals = [];
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $digits = is_string($digits) ? $digits : '';
            if (strlen($digits) >= 4) {
                if (strpos($digits, '251') === 0 && strlen($digits) > 5) {
                    $digits = substr($digits, 3);
                } elseif (strpos($digits, '0') === 0) {
                    $digits = ltrim($digits, '0');
                }
                $like = '%' . substr($digits, -9) . '%';
                $where[] = '(g.phone_primary_n9 LIKE ? OR g.phone_alt_n9 LIKE ?)';
                $types .= 'ss';
                $vals[] = $like;
                $vals[] = $like;
            } else {
                $like = '%' . GuardianMatchService::normalizeName($q) . '%';
                $where[] = '(g.display_name LIKE ? OR EXISTS (SELECT 1 FROM guardian_aliases al WHERE al.guardian_id = g.id AND al.alias_name LIKE ?))';
                $types .= 'ss';
                $vals[] = $like;
                $vals[] = $like;
            }
        }
        $having = '';
        switch ($filter) {
            case 'multi':
                $having = 'HAVING children_count >= 2';
                break;
            case 'single':
                $having = 'HAVING children_count = 1';
                break;
            case 'orphan':
                $having = 'HAVING children_count = 0';
                break;
            case 'nophone':
                $where[] = "(g.phone_primary IS NULL OR g.phone_primary = '')";
                break;
            case 'recent':
                $where[] = 'g.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                break;
        }
        $w = implode(' AND ', $where);
        $base = "FROM guardians g WHERE {$w}";
        $countSql = "SELECT COUNT(*) FROM (SELECT g.id, (SELECT COUNT(*) FROM member_guardians mg WHERE mg.guardian_id = g.id) AS children_count {$base} {$having}) t";
        $st = $conn->prepare($countSql);
        if (!$st) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        if ($types !== '') {
            $st->bind_param($types, ...$vals);
        }
        $st->execute();
        $total = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
        $pages = max(1, (int)ceil($total / $per));
        $page = min($page, $pages);
        $off = ($page - 1) * $per;
        $sql = "SELECT g.id, g.display_name, g.phone_primary, g.phone_alt, g.city, g.sub_city, g.status, g.source, g.updated_at,
                (SELECT COUNT(*) FROM member_guardians mg WHERE mg.guardian_id = g.id) AS children_count,
                (SELECT GROUP_CONCAT(COALESCE(NULLIF(m.student_name,''), m.full_name_am) ORDER BY m.student_name SEPARATOR ', ') FROM member_guardians mg JOIN members m ON m.id = mg.member_id WHERE mg.guardian_id = g.id) AS children_names
                {$base} {$having} ORDER BY g.updated_at DESC, g.id DESC LIMIT {$per} OFFSET {$off}";
        $st = $conn->prepare($sql);
        $rows = [];
        if ($st) {
            if ($types !== '') {
                $st->bind_param($types, ...$vals);
            }
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) {
                $rows[] = $x;
            }
            $st->close();
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Members that carry a legacy guardian name but have no guardian link yet. */
    public static function unlinkedMembers(\mysqli $conn, int $page = 1, int $per = 25, int $afterId = 0, bool $ascending = false): array
    {
        $per = max(5, min(100, $per));
        $page = max(1, $page);
        $cond = "m.status != 'archived' AND TRIM(COALESCE(m.guardian_name,'')) != '' AND NOT EXISTS (SELECT 1 FROM member_guardians mg WHERE mg.member_id = m.id)";
        $r = $conn->query("SELECT COUNT(*) FROM members m WHERE {$cond}");
        $total = $r ? (int)$r->fetch_row()[0] : 0;
        $pages = max(1, (int)ceil($total / $per));
        $page = min($page, $pages);
        $off = ($page - 1) * $per;
        $extra = $afterId > 0 ? " AND m.id > " . (int)$afterId : '';
        // Batch walks (attachBatch) go oldest → newest by id so the cursor
        // can resume; the list view shows newest first with page offsets.
        $asc = $afterId > 0 || $ascending;
        $order = $asc ? 'ORDER BY m.id ASC' : 'ORDER BY m.id DESC';
        $limit = $asc ? "LIMIT {$per}" : "LIMIT {$per} OFFSET {$off}";
        $rows = [];
        $res = $conn->query("SELECT m.id, m.member_code, m.student_name, m.full_name_am, m.current_section, m.guardian_name, m.guardian_phone1, m.guardian_phone2 FROM members m WHERE {$cond}{$extra} {$order} {$limit}");
        if ($res) {
            while ($x = $res->fetch_assoc()) {
                $rows[] = $x;
            }
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Bounded in-browser backfill: attach up to $limit unlinked members
     * (match-or-create) within $budget seconds. Cursor-resumable by member id.
     * @return array{processed:int, created:int, matched:int, failed:int, next_cursor:int, done:bool, remaining:int}
     */
    public static function attachBatch(\mysqli $conn, int $cursor = 0, int $limit = 40, float $budget = 15.0): array
    {
        $t0 = microtime(true);
        // Ascending from the cursor. (Before 2026-10-21 the first step read
        // the NEWEST members, moved the cursor past every older one and
        // reported done — "Link all" stopped after one step of 40.)
        $batch = self::unlinkedMembers($conn, 1, $limit, $cursor, true);
        $processed = $created = $matched = $failed = 0;
        $last = $cursor;
        foreach ($batch['rows'] as $m) {
            if ((microtime(true) - $t0) > $budget) {
                break;
            }
            $last = (int)$m['id'];
            try {
                $r = self::attachFromLegacy($conn, (int)$m['id'], 0, 'backfill');
                $processed++;
                if ($r['mode'] === 'created') {
                    $created++;
                } elseif ($r['mode'] === 'auto' || $r['mode'] === 'auto_family' || $r['mode'] === 'synced') {
                    $matched++;
                }
            } catch (\Throwable $e) {
                $failed++;
                error_log('attachBatch member ' . $m['id'] . ': ' . $e->getMessage());
            }
        }
        $remaining = self::unlinkedMembers($conn, 1, 5, $last, true)['rows'];
        $done = count($remaining) === 0;
        $after = self::stats($conn)['members_unlinked'];
        return ['processed' => $processed, 'created' => $created, 'matched' => $matched, 'failed' => $failed, 'next_cursor' => $last, 'done' => $done, 'remaining' => $after, 'elapsed' => round(microtime(true) - $t0, 2)];
    }

    /** Recent Family Link audit entries. */
    public static function activity(\mysqli $conn, int $limit = 40): array
    {
        $out = [];
        $limit = max(5, min(200, $limit));
        $r = $conn->query("SELECT id, username, action, details, created_at FROM activity_logs WHERE action LIKE 'Guardian%' OR action LIKE 'Suggestion%' ORDER BY id DESC LIMIT {$limit}");
        if ($r) {
            while ($x = $r->fetch_assoc()) {
                $out[] = $x;
            }
        }
        return $out;
    }

    /** Change relation / primary flag of an existing link. */
    public static function setRelation(\mysqli $conn, int $guardianId, int $memberId, string $relation, ?bool $isPrimary): void
    {
        $st = $conn->prepare('SELECT relation, contact_priority, is_primary FROM member_guardians WHERE member_id = ? AND guardian_id = ?');
        if (!$st) {
            throw new \RuntimeException('prepare failed');
        }
        $st->bind_param('ii', $memberId, $guardianId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            throw new \InvalidArgumentException('That member is not linked to this guardian.');
        }
        if (!in_array($relation, ['father', 'mother', 'other'], true)) {
            $relation = (string)$row['relation'];
        }
        $primary = $isPrimary === null ? ((int)$row['is_primary'] === 1) : $isPrimary;
        if (!$primary && (int)$row['is_primary'] === 1) {
            // demoting the only primary is not allowed; pick another explicitly
            $cnt = (int)$conn->query('SELECT COUNT(*) FROM member_guardians WHERE member_id = ' . (int)$memberId)->fetch_row()[0];
            if ($cnt <= 1) {
                throw new \InvalidArgumentException('A member must keep one primary guardian. Mark another guardian as primary instead.');
            }
        }
        self::linkMember($conn, $guardianId, $memberId, $relation, (int)$row['contact_priority'], $primary, false);
        self::ensurePrimary($conn, $memberId);
        self::audit($conn, 'Guardian Linked', "Member {$memberId} ↔ guardian {$guardianId}: relation {$relation}" . ($primary ? ', primary' : '') . '.');
    }
}
