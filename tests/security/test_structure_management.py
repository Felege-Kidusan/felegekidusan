"""Contract pins — edu-dept structure management (delete / restore / purge).

Guards the soft/hard-delete hybrid for subjects, class deactivation, and the
audit trail, so a future refactor cannot silently re-introduce the old
behaviour (disappearing entities with no restore path).
"""

from pathlib import Path

REPO = Path(__file__).resolve().parents[2]


def _read(rel: str) -> str:
    return (REPO / rel).read_text(encoding="utf-8")


class TestSubjectDeleteRestore:
    def test_api_pins(self):
        src = _read("admin/api_subjects.php")
        # restore_subject exists and is CSRF-protected + role-gated
        assert "case 'restore_subject':" in src
        assert "'delete_subject', 'restore_subject'" in src  # requirePostActions
        manage_block = src.split("$__manageActions = [", 1)[1].split("];", 1)[0]
        assert "'restore_subject'" in manage_block
        # grade_count exposed so the UI can state the outcome before deleting
        assert "as grade_count" in src
        # purge is transactional and cleans every link table
        assert "function subjects_purge" in src
        for table in ["class_subjects", "teacher_assignments", "grade_submissions",
                      "timetable_entries", "assessments"]:
            assert f"DELETE FROM {table} WHERE subject_id = ?" in src
        assert "$conn->begin_transaction();" in src
        assert "$conn->rollback();" in src
        # audit trail for all three outcomes
        for action in ["'Subject Deactivated'", "'Subject Restored'", "'Subject Deleted'"]:
            assert action in src

    def test_dashboard_ui_pins(self):
        src = _read("admin/dashboards/edu_dept.php")
        # subjects query must include inactive rows (no more vanishing)
        assert "FROM subjects WHERE is_active = 1" not in src
        assert "ORDER BY s.is_active DESC" in src
        assert "grade_count" in src
        # row actions
        assert "function deleteSubject(" in src
        assert "function restoreSubject(" in src
        assert "action', 'delete_subject" in src
        assert "action', 'restore_subject" in src
        # confirm dialog states soft vs hard outcome
        assert "DEACTIVATED (hidden, history preserved)" in src
        assert "removed for good" in src
        # status column renders
        assert "Deactivated" in src


class TestClassToggle:
    def test_api_pins(self):
        src = _read("admin/api_education.php")
        assert "case 'toggle_class':" in src
        manage_block = src.split("$__manageActions = [", 1)[1].split("];", 1)[0]
        assert "'toggle_class'" in manage_block
        assert "'Class Restored'" in src
        assert "'Class Deactivated'" in src
        assert "UPDATE classes SET is_active = ? WHERE id = ?" in src

    def test_dashboard_ui_pins(self):
        src = _read("admin/dashboards/edu_dept.php")
        assert "function toggleClass(" in src
        assert "action', 'toggle_class" in src
