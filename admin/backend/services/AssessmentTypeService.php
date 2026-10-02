<?php
/**
 * Dynamic Assessment Types Service
 *
 * Allows Education Department and administrators to dynamically create, edit,
 * activate/deactivate, and configure custom assessment types (e.g. Test, Oral Exam,
 * Project, Quiz, Hymn Memorization) instead of relying on hardcoded system defaults.
 */

namespace App\Services;

class AssessmentTypeService
{
    /**
     * Ensure table exists and default types are seeded (idempotent runtime setup).
     */
    public static function ensureTable(\mysqli $conn): void
    {
        // Seed initial baseline records if table is empty
        $check = @$conn->query("SELECT COUNT(*) as c FROM `assessment_types`");
        if ($check) {
            $row = $check->fetch_assoc();
            if ((int)($row['c'] ?? 0) === 0) {
                $conn->query("
                    INSERT IGNORE INTO `assessment_types` (`type_key`, `type_name`, `type_name_en`, `default_weight`, `default_max_score`, `sort_order`) VALUES
                    ('test', 'ፈተና', 'Class Test', 10.00, 10.00, 1),
                    ('midterm', 'አጋማሽ ፈተና', 'Midterm Exam', 40.00, 40.00, 2),
                    ('final', 'የማጠቃለያ ፈተና', 'Final Exam', 50.00, 50.00, 3),
                    ('quiz', 'አጭር ፈተና', 'Quiz', 10.00, 10.00, 4),
                    ('assignment', 'የቤት ስራ', 'Assignment / Homework', 10.00, 10.00, 5),
                    ('project', 'ተግባራዊ ስራ', 'Project', 20.00, 20.00, 6),
                    ('participation', 'ተሳትፎ', 'Participation', 10.00, 10.00, 7),
                    ('oral_exam', 'የቃል ፈተና', 'Oral Exam', 15.00, 15.00, 8),
                    ('memorization', 'የቃል ጥናት / ዜማ', 'Hymn / Memorization', 15.00, 15.00, 9);
                ");
            }
        }
    }

    /**
     * Get all assessment types.
     *
     * @return list<array<string,mixed>>
     */
    public static function getAll(\mysqli $conn, bool $activeOnly = false): array
    {
        self::ensureTable($conn);

        $sql = "SELECT id, type_key, type_name, type_name_en, default_weight, default_max_score, 
                       description, is_active, sort_order, created_at, updated_at
                FROM assessment_types";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        $res = $conn->query($sql);
        $types = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $types[] = [
                    'id' => (int)$row['id'],
                    'type_key' => (string)$row['type_key'],
                    'type_name' => (string)$row['type_name'],
                    'type_name_en' => (string)($row['type_name_en'] ?? ''),
                    'default_weight' => $row['default_weight'] !== null ? (float)$row['default_weight'] : null,
                    'default_max_score' => (float)($row['default_max_score'] ?? 100),
                    'description' => (string)($row['description'] ?? ''),
                    'is_active' => (bool)$row['is_active'],
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                ];
            }
        }
        return $types;
    }

    /**
     * Get single assessment type by ID.
     */
    public static function getById(\mysqli $conn, int $id): ?array
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare("SELECT * FROM assessment_types WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Generate a slug/key from text.
     */
    public static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9_]+/i', '_', $text);
        $text = trim($text, '_');
        return $text ?: 'type_' . substr(md5(uniqid('', true)), 0, 6);
    }

    /**
     * Create or update an assessment type.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function save(\mysqli $conn, array $data, int $userId = 0): array
    {
        self::ensureTable($conn);

        $id = (int)($data['id'] ?? 0);
        $typeName = trim((string)($data['type_name'] ?? ''));
        $typeNameEn = trim((string)($data['type_name_en'] ?? ''));
        $typeKey = trim((string)($data['type_key'] ?? ''));
        $defaultWeight = (isset($data['default_weight']) && $data['default_weight'] !== '') ? (float)$data['default_weight'] : null;
        $defaultMax = (isset($data['default_max_score']) && $data['default_max_score'] !== '') ? (float)$data['default_max_score'] : 100.00;
        $desc = trim((string)($data['description'] ?? ''));
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
        $sortOrder = (int)($data['sort_order'] ?? 0);

        if ($typeName === '') {
            return ['status' => 'error', 'message' => 'Assessment Type Name is required.'];
        }

        if ($typeKey === '') {
            $base = $typeNameEn !== '' ? $typeNameEn : $typeName;
            $typeKey = self::slugify($base);
        } else {
            $typeKey = self::slugify($typeKey);
        }

        // Validate key uniqueness
        $stmt = $conn->prepare("SELECT id FROM assessment_types WHERE type_key = ? AND id != ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $typeKey, $id);
            $stmt->execute();
            if ($stmt->get_result()->fetch_row()) {
                $stmt->close();
                return ['status' => 'error', 'message' => "An assessment type with code '{$typeKey}' already exists. Please choose a different key."];
            }
            $stmt->close();
        }

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE assessment_types
                SET type_key = ?, type_name = ?, type_name_en = ?, default_weight = ?, default_max_score = ?,
                    description = ?, is_active = ?, sort_order = ?
                WHERE id = ?
            ");
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Database error preparing update.'];
            }
            $stmt->bind_param('sssddsiii', $typeKey, $typeName, $typeNameEn, $defaultWeight, $defaultMax, $desc, $isActive, $sortOrder, $id);
            $stmt->execute();
            $stmt->close();
            return ['status' => 'success', 'message' => 'Assessment type updated successfully.', 'id' => $id, 'type_key' => $typeKey];
        }

        $stmt = $conn->prepare("
            INSERT INTO assessment_types
            (type_key, type_name, type_name_en, default_weight, default_max_score, description, is_active, sort_order, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Database error preparing insert.'];
        }
        $stmt->bind_param('sssddsiii', $typeKey, $typeName, $typeNameEn, $defaultWeight, $defaultMax, $desc, $isActive, $sortOrder, $userId);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        return ['status' => 'success', 'message' => 'Assessment type created successfully.', 'id' => $newId, 'type_key' => $typeKey];
    }

    /**
     * Toggle active state.
     */
    public static function toggleActive(\mysqli $conn, int $id): array
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare("UPDATE assessment_types SET is_active = 1 - is_active WHERE id = ?");
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Failed to toggle status.'];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return ['status' => 'success', 'message' => 'Assessment type status updated.'];
    }

    /**
     * Delete assessment type or deactivate if currently attached to assessments.
     */
    public static function delete(\mysqli $conn, int $id): array
    {
        self::ensureTable($conn);
        $type = self::getById($conn, $id);
        if (!$type) {
            return ['status' => 'error', 'message' => 'Assessment type not found.'];
        }

        $key = (string)$type['type_key'];
        
        // Check if any existing assessments use this key
        $inUse = 0;
        $chk = $conn->prepare("SELECT COUNT(*) FROM assessments WHERE assessment_type = ?");
        if ($chk) {
            $chk->bind_param('s', $key);
            $chk->execute();
            $res = $chk->get_result()->fetch_row();
            $inUse = (int)($res[0] ?? 0);
            $chk->close();
        }

        if ($inUse > 0) {
            // Cannot hard-delete if referenced; deactivate instead to protect historical grade records
            $stmt = $conn->prepare("UPDATE assessment_types SET is_active = 0 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }
            return [
                'status' => 'success',
                'message' => "Assessment type is currently used by {$inUse} assessment(s). It has been deactivated instead of deleted to protect historical records."
            ];
        }

        $stmt = $conn->prepare("DELETE FROM assessment_types WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        return ['status' => 'success', 'message' => 'Assessment type deleted successfully.'];
    }
}
