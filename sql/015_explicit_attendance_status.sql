-- Attendance must always be an explicit user or integration decision.
-- Existing rows already contain a non-null ENUM value; this only removes the
-- legacy database-level implicit "present" fallback for future inserts.

ALTER TABLE `attendance`
    MODIFY COLUMN `status`
        ENUM('present','absent','late','excused','holiday') NOT NULL;
