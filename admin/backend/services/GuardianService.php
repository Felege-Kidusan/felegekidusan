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
        // guardian tables fully replace them.
        $synced = 0;
        $sync = $conn->prepare('UPDATE members m JOIN member_guardians mg ON mg.member_id = m.id SET m.guardian_name=?, m.guardian_phone1=?, m.guardian_phone2=? WHERE mg.guardian_id=?');
        if ($sync) {
            $sync->bind_param('sssi', $name, $p1, $p2, $id);
            if ($sync->execute()) {
                $synced = $sync->affected_rows;
            }
            $sync->close();
        }
        self::audit($conn, 'Guardian Updated', "Guardian id {$id} updated (single source of truth: all linked children see the new values; {$synced} member row(s) synced).");
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
            // move links that do not collide
            $conn->query("DELETE mg FROM member_guardians mg
                JOIN member_guardians ex ON ex.member_id = mg.member_id AND ex.guardian_id = {$to}
                WHERE mg.guardian_id = {$from}");
            $conn->query("UPDATE member_guardians SET guardian_id = {$to} WHERE guardian_id = {$from}");
            $conn->query("INSERT IGNORE INTO guardian_aliases (guardian_id, alias_name, source)
                SELECT {$to}, alias_name, source FROM guardian_aliases WHERE guardian_id = {$from}");
            $conn->query("DELETE FROM guardian_aliases WHERE guardian_id = {$from}");
            $stmt = $conn->prepare("UPDATE guardians SET status='merged', merged_into=? WHERE id=?");
            $stmt->bind_param('ii', $to, $from);
            $stmt->execute();
            $stmt->close();
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
            $kids = [];
            $s = $conn->prepare('SELECT m.student_name, m.current_section FROM member_guardians mg JOIN members m ON m.id = mg.member_id WHERE mg.guardian_id = ? ORDER BY m.student_name LIMIT 6');
            if ($s) {
                $s->bind_param('i', $id);
                $s->execute();
                $r = $s->get_result();
                while ($k = $r->fetch_assoc()) {
                    $kids[] = $k;
                }
                $s->close();
            }
            $g['children'] = $kids;
            $g['aliases'] = self::aliases($conn, $id);
            $out[] = $g;
        }
        return $out;
    }
}
