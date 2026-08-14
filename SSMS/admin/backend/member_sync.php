<?php
/**
 * ════════════════════════════════════════════════════════════════
 * School Member Sync Engine
 * ════════════════════════════════════════════════════════════════
 * 
 * Cross-department sync logic for member_type, role flags, and
 * enrollment/teacher status. Called by Education, Teacher, and
 * Information department APIs to keep data consistent.
 *
 * MEMBER TYPE RULES:
 *   regular         → Default. A standard student/member.
 *   special_regular → A member who has additional roles (teacher, staff, etc.)
 *                     OR is both enrolled as student AND serves as teacher.
 *   honorary        → Manually set. Never auto-changed.
 *
 * SYNC TRIGGERS:
 *   1. Member assigned as teacher     → is_teacher=1, member_type→special_regular
 *   2. Member enrolled as student     → normal (stays current type)
 *   3. Member is teacher+student      → is_teacher=1, member_type→special_regular
 *   4. Teacher role removed           → check if any other roles remain
 *                                        if no roles → revert to regular
 *   5. Any role flag set              → member_type→special_regular
 *   6. All role flags cleared         → member_type→regular (unless honorary)
 *
 * ════════════════════════════════════════════════════════════════
 */

/**
 * Sync a member's member_type based on their current role flags.
 * Call this after any operation that changes is_teacher, is_staff,
 * is_committee, is_volunteer, or teacher_assignments.
 *
 * @param mysqli $conn    Database connection
 * @param int    $memberId  The member ID to sync
 * @param array  $overrides Optional: ['is_teacher' => 1] to apply before checking
 * @return array ['changed' => bool, 'old_type' => string, 'new_type' => string]
 */
function syncMemberType(mysqli $conn, int $memberId, array $overrides = []): array {
    if ($memberId <= 0) return ['changed' => false, 'old_type' => '', 'new_type' => ''];

    // Get current member state
    $stmt = $conn->prepare("SELECT member_type, is_teacher, is_staff, is_committee, is_volunteer FROM members WHERE id = ?");
    if (!$stmt) return ['changed' => false, 'old_type' => '', 'new_type' => ''];
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$member) return ['changed' => false, 'old_type' => '', 'new_type' => ''];
    
    $oldType = $member['member_type'] ?? 'regular';
    
    // Honorary members are NEVER auto-changed
    if ($oldType === 'honorary') {
        return ['changed' => false, 'old_type' => 'honorary', 'new_type' => 'honorary'];
    }
    
    // Apply overrides (e.g. setting is_teacher=1 that hasn't been written to DB yet)
    $isTeacher   = (int)($overrides['is_teacher']   ?? $member['is_teacher']   ?? 0);
    $isStaff     = (int)($overrides['is_staff']     ?? $member['is_staff']     ?? 0);
    $isCommittee = (int)($overrides['is_committee'] ?? $member['is_committee'] ?? 0);
    $isVolunteer = (int)($overrides['is_volunteer'] ?? $member['is_volunteer'] ?? 0);
    
    // Also check if member is linked as teacher in users table
    $hasTeacherAccount = false;
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE member_id = ? AND role = 'teacher' AND is_active = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $memberId);
            $stmt->execute();
            $hasTeacherAccount = ($stmt->get_result()->num_rows > 0);
            $stmt->close();
        }
    } catch (Exception $e) {}
    
    // Also check active teacher_assignments via users table
    $hasActiveAssignment = false;
    try {
        $stmt = $conn->prepare("
            SELECT ta.id FROM teacher_assignments ta
            JOIN users u ON ta.teacher_id = u.id
            WHERE u.member_id = ? AND ta.is_active = 1 LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("i", $memberId);
            $stmt->execute();
            $hasActiveAssignment = ($stmt->get_result()->num_rows > 0);
            $stmt->close();
        }
    } catch (Exception $e) {}
    
    // Determine if this member has any special roles
    $hasAnyRole = ($isTeacher || $isStaff || $isCommittee || $isVolunteer || $hasTeacherAccount || $hasActiveAssignment);
    
    // Determine new type
    $newType = $hasAnyRole ? 'special_regular' : 'regular';
    
    // Update if changed
    if ($newType !== $oldType) {
        $stmt = $conn->prepare("UPDATE members SET member_type = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $newType, $memberId);
            $stmt->execute();
            $stmt->close();
        }
        return ['changed' => true, 'old_type' => $oldType, 'new_type' => $newType];
    }
    
    return ['changed' => false, 'old_type' => $oldType, 'new_type' => $newType];
}

/**
 * Sync is_teacher flag on a member when a teacher account is created/deleted.
 * Also triggers member_type sync.
 *
 * @param mysqli $conn
 * @param int    $memberId
 * @param bool   $isNowTeacher  true if teacher account was created, false if removed
 * @return array sync result
 */
function syncMemberTeacherFlag(mysqli $conn, int $memberId, bool $isNowTeacher): array {
    if ($memberId <= 0) return ['changed' => false];
    
    $flag = $isNowTeacher ? 1 : 0;
    
    // If removing teacher flag, check if there are OTHER teacher accounts pointing to this member
    if (!$isNowTeacher) {
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE member_id = ? AND role = 'teacher' AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param("i", $memberId);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($count > 0) {
                // Still has active teacher accounts — keep the flag
                $flag = 1;
            }
        }
    }
    
    // Update the flag
    $stmt = $conn->prepare("UPDATE members SET is_teacher = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $flag, $memberId);
        $stmt->execute();
        $stmt->close();
    }
    
    // Now sync member_type
    return syncMemberType($conn, $memberId, ['is_teacher' => $flag]);
}

/**
 * Batch sync: check ALL members and fix any mismatched member_types.
 * Useful for one-time repair or scheduled maintenance.
 *
 * @param mysqli $conn
 * @return array ['checked' => int, 'fixed' => int, 'details' => array]
 */
function batchSyncMemberTypes(mysqli $conn): array {
    $result = ['checked' => 0, 'fixed' => 0, 'details' => []];
    
    $members = $conn->query("SELECT id, member_type, is_teacher, is_staff, is_committee, is_volunteer FROM members WHERE status != 'archived'");
    if (!$members) return $result;
    
    while ($m = $members->fetch_assoc()) {
        $result['checked']++;
        $sync = syncMemberType($conn, (int)$m['id']);
        if ($sync['changed']) {
            $result['fixed']++;
            $result['details'][] = [
                'member_id' => $m['id'],
                'old_type' => $sync['old_type'],
                'new_type' => $sync['new_type']
            ];
        }
    }
    
    return $result;
}

/**
 * Get member role summary for display.
 * Returns a human-readable array of active roles.
 *
 * @param array $member  Member row from database
 * @return array ['roles' => ['Teacher', 'Staff'], 'is_special' => bool]
 */
function getMemberRoleSummary(array $member): array {
    $roles = [];
    if (!empty($member['is_teacher']))   $roles[] = 'Teacher';
    if (!empty($member['is_staff']))     $roles[] = 'Staff';
    if (!empty($member['is_committee'])) $roles[] = 'Committee';
    if (!empty($member['is_volunteer'])) $roles[] = 'Volunteer';
    
    return [
        'roles' => $roles,
        'is_special' => !empty($roles) || ($member['member_type'] ?? '') === 'special_regular',
        'type_label' => match($member['member_type'] ?? 'regular') {
            'special_regular' => 'Special Regular',
            'honorary' => 'Honorary',
            default => 'Regular'
        }
    ];
}
