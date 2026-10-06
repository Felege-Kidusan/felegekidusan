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

/**
 * Thrown when an attendance sheet fails validation. Carries a stable
 * machine-readable code so sync monitoring can distinguish an expected
 * workflow state (INCOMPLETE_SHEET on a submit) from a genuine contract
 * violation (ROSTER_MISMATCH) without parsing human message text.
 */
class AttendanceSheetInvalid extends \DomainException
{
    public const CODE_INCOMPLETE_SHEET = 'INCOMPLETE_SHEET';
    public const CODE_ROSTER_MISMATCH = 'ROSTER_MISMATCH';

    /** @var string */
    private $errorCode;

    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

final class AttendanceRecordService
{
    public const MAX_RECORDS = 2000;
    public const MAX_NOTE_LENGTH = 500;
    public const VALID_STATUSES = ['present', 'absent', 'late', 'excused'];

    /**
     * Complete-sheet contract (submits and full-sheet editors). Delegates to
     * normalizeSheet() with the completeness requirement enforced.
     *
     * @param array<int,mixed> $records
     * @param array<int,mixed> $roster
     * @return array<int,array{member_id:int,status:string,note:string}>
     * @throws AttendanceSheetInvalid|\DomainException
     */
    public static function normalizeCompleteSheet(array $records, array $roster): array
    {
        return self::normalizeSheet($records, $roster, true);
    }

    /**
     * Validate a sheet against the current server roster. Every submitted
     * record must be explicit, valid, duplicate-free, and on the roster.
     * When $requireComplete is false (draft saves), a PARTIAL sheet is
     * accepted — the mobile app's instant autosave deliberately persists
     * partial drafts, and a teacher pausing mid-marking is a normal
     * workflow state, not a sync error. Submissions still require the
     * complete roster.
     *
     * @param array<int,mixed> $records
     * @param array<int,mixed> $roster
     * @return array<int,array{member_id:int,status:string,note:string}>
     * @throws AttendanceSheetInvalid|\DomainException
     */
    public static function normalizeSheet(array $records, array $roster, bool $requireComplete): array
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

        // A submitted student who is not on the roster is always a mismatch,
        // for drafts and submits alike — never write marks for other classes.
        if (array_diff_key($submittedIds, $rosterIds) !== []) {
            throw new AttendanceSheetInvalid(
                'Some students are not on this class roster. Refresh the class and try again.',
                AttendanceSheetInvalid::CODE_ROSTER_MISMATCH
            );
        }

        if ($requireComplete
            && array_diff_key($rosterIds, $submittedIds) !== []) {
            throw new AttendanceSheetInvalid(
                'Some students are unmarked. Refresh and mark every student before submitting.',
                AttendanceSheetInvalid::CODE_INCOMPLETE_SHEET
            );
        }

        return $normalized;
    }

    /**
     * Merge one (possibly partial) class/day sheet — draft semantics. Upserts
     * only the submitted rows on the existing unique key
     * (member_id, class_id, attendance_date) and NEVER deletes rows the
     * payload did not mention, so a partial autosave draft can never erase
     * previously saved marks. The caller MUST own the database transaction
     * so attendance and its workflow packet can commit together.
     *
     * @param array<int,array{member_id:int,status:string,note:string}> $records
     */
    public static function mergeSheet(
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

        $upsert = $conn->prepare(
            'INSERT INTO attendance
                (member_id, class_id, academic_year_id, attendance_date, status, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                notes = VALUES(notes),
                recorded_by = VALUES(recorded_by),
                academic_year_id = VALUES(academic_year_id)'
        );
        if (!$upsert) {
            throw new \RuntimeException('Could not prepare attendance merge.');
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
                $upsert->bind_param(
                    'iiisssi',
                    $memberId,
                    $classId,
                    $academicYearId,
                    $date,
                    $status,
                    $note,
                    $recordedBy
                );
                if (!$upsert->execute()) {
                    throw new \RuntimeException('Could not save an attendance record.');
                }
                $saved++;
            }
        } finally {
            $upsert->close();
        }

        return $saved;
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
