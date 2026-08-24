<?php
/**
 * Attendance record validation and persistence.
 *
 * Controllers remain responsible for authorization, transaction boundaries,
 * submission workflow, and HTTP responses. This service owns the invariant
 * that a saved sheet is explicit, valid, duplicate-free, and complete for the
 * roster the server resolves at save time.
 */
namespace App\Services;

final class AttendanceRecordService
{
    public const MAX_RECORDS = 2000;
    public const MAX_NOTE_LENGTH = 500;
    public const VALID_STATUSES = ['present', 'absent', 'late', 'excused'];

    /**
     * @param array<int,mixed> $records
     * @param array<int,mixed> $roster
     * @return array<int,array{member_id:int,status:string,note:string}>
     * @throws \DomainException when a sheet is invalid or incomplete
     */
    public static function normalizeCompleteSheet(array $records, array $roster): array
    {
        if ($records === []) {
            throw new \DomainException('Attendance records are required.');
        }
        if (count($records) > self::MAX_RECORDS) {
            throw new \DomainException(
                'Too many attendance records in one save (maximum ' . self::MAX_RECORDS . ').'
            );
        }

        $normalized = [];
        $submittedIds = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new \DomainException('Every attendance record must be an object.');
            }

            $memberValue = $record['member_id'] ?? null;
            if (!is_int($memberValue) && !is_string($memberValue)) {
                throw new \DomainException('Every attendance record must identify a student.');
            }
            $memberId = filter_var($memberValue, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($memberId === false) {
                throw new \DomainException('Every attendance record must identify a student.');
            }
            $memberId = (int)$memberId;
            if (isset($submittedIds[$memberId])) {
                throw new \DomainException('The attendance sheet contains a duplicate student.');
            }

            $statusValue = $record['status'] ?? null;
            if (!is_string($statusValue)) {
                throw new \DomainException('Choose an attendance status for every student.');
            }
            $status = strtolower(trim($statusValue));
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new \DomainException('Choose an attendance status for every student.');
            }

            $noteValue = $record['note'] ?? $record['notes'] ?? '';
            if (!is_scalar($noteValue) && $noteValue !== null) {
                throw new \DomainException('Attendance notes must be text.');
            }
            $note = trim((string)$noteValue);
            $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
            if ($noteLength > self::MAX_NOTE_LENGTH) {
                throw new \DomainException(
                    'Attendance notes may not exceed ' . self::MAX_NOTE_LENGTH . ' characters.'
                );
            }

            $submittedIds[$memberId] = true;
            $normalized[] = [
                'member_id' => $memberId,
                'status' => $status,
                'note' => $note,
            ];
        }

        $rosterIds = [];
        foreach ($roster as $student) {
            if (!is_array($student)) {
                continue;
            }
            $memberId = (int)($student['member_id'] ?? $student['id'] ?? 0);
            if ($memberId > 0) {
                $rosterIds[$memberId] = true;
            }
        }

        if ($rosterIds === []) {
            throw new \DomainException('This class has no active roster to record.');
        }
        if (count($rosterIds) > self::MAX_RECORDS) {
            throw new \DomainException(
                'This class exceeds the supported attendance sheet size. Contact Education.'
            );
        }

        if (array_diff_key($rosterIds, $submittedIds) !== []
            || array_diff_key($submittedIds, $rosterIds) !== []) {
            throw new \DomainException(
                'The class roster changed or some students are unmarked. Refresh and mark every student.'
            );
        }

        return $normalized;
    }

    /**
     * Replace one complete class/day sheet. The caller MUST own the database
     * transaction so attendance and its workflow packet can commit together.
     *
     * @param array<int,array{member_id:int,status:string,note:string}> $records
     */
    public static function replaceSheet(
        \mysqli $conn,
        int $classId,
        string $date,
        ?int $academicYearId,
        int $recordedBy,
        array $records
    ): int {
        if ($classId <= 0 || $recordedBy <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Invalid attendance persistence context.');
        }

        $delete = $conn->prepare(
            'DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?'
        );
        if (!$delete) {
            throw new \RuntimeException('Could not prepare attendance replacement.');
        }
        $delete->bind_param('is', $classId, $date);
        if (!$delete->execute()) {
            $delete->close();
            throw new \RuntimeException('Could not replace the attendance sheet.');
        }
        $delete->close();

        $insert = $conn->prepare(
            'INSERT INTO attendance
                (member_id, class_id, academic_year_id, attendance_date, status, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert) {
            throw new \RuntimeException('Could not prepare attendance records.');
        }

        $saved = 0;
        try {
            foreach ($records as $record) {
                $memberId = (int)$record['member_id'];
                $status = (string)$record['status'];
                $note = (string)$record['note'];
                if (!in_array($status, self::VALID_STATUSES, true)) {
                    throw new \InvalidArgumentException('Unvalidated attendance status.');
                }
                $insert->bind_param(
                    'iiisssi',
                    $memberId,
                    $classId,
                    $academicYearId,
                    $date,
                    $status,
                    $note,
                    $recordedBy
                );
                if (!$insert->execute()) {
                    throw new \RuntimeException('Could not save an attendance record.');
                }
                $saved++;
            }
        } finally {
            $insert->close();
        }

        return $saved;
    }
}
