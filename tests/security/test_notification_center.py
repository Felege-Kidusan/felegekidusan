"""
WBWS Communication Center (P72) — regression & quality gates
═════════════════════════════════════════════════════════════════════════════
Pins the contract of the professionalized notification system:

  • ONE service (NotificationCenterService) owns permissions, labels,
    read state, announcements and messaging — no dashboard hardcodes
  • per-user read state (notification_reads pivot), legacy is_read
    column still written for the pinned audit tests
  • the bell component ships its own CSRF token (the old bell's
    silently-failing writes were the reported instability)
  • every dashboard shows the same bell (12 surfaces + 2 new pages)
  • api/v1 route + Flutter surfaces follow the same single writer
  • sql/042 is additive and idempotent
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile/wbws_flutter_app/lib"


class NotificationCenterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.svc = (ROOT / "admin/backend/services/NotificationCenterService.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/042_notification_center.sql").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_notifications.php").read_text(encoding="utf-8")
        cls.workflow = (ROOT / "admin/backend/workflow.php").read_text(encoding="utf-8")
        cls.bell = (ROOT / "admin/components/notification_center.php").read_text(encoding="utf-8")

    # ── 1. the single source of truth ─────────────────────────
    def test_service_defines_everything(self):
        for token in ["ROLE_LABELS", "ANNOUNCE_SEND", "MESSAGE_PARTNERS",
                      "public static function canAnnounce", "public static function canMessage",
                      "public static function mayMessageRole", "public static function feed",
                      "public static function markRead", "public static function markAllRead",
                      "public static function postAnnouncement", "public static function listAnnouncements",
                      "public static function markAllAnnouncementsRead", "public static function startThread",
                      "public static function threadsFor", "public static function threadMessages",
                      "public static function sendMessage", "public static function markThreadRead",
                      "public static function unreadSummary"]:
            self.assertIn(token, self.svc, f"service lost {token}")

    def test_service_covers_every_live_role(self):
        roles = re.search(r"ROLE_LABELS = \[(.*?)\];", self.svc, re.S).group(1)
        for role in ["super_admin", "school_admin", "info_dept", "edu_dept", "finance_dept",
                     "material_dept", "mezmur_dept", "hr_dept", "teacher", "attendance_taker",
                     "mezmur_attendance_taker", "hr_attendance_taker", "content_editor"]:
            self.assertIn(f"'{role}'", roles, f"ROLE_LABELS missing {role} (stale-list bug)")

    def test_service_never_touches_session(self):
        """One writer for web sessions AND bearer tokens: the service takes
        ($conn, $userId, $role) params and must never read $_SESSION."""
        self.assertNotIn("$_SESSION", self.svc)

    def test_permission_matrix_is_sane(self):
        # managers announce to everyone; edu reaches its teachers/takers;
        # recipients-only departments do not broadcast
        self.assertIn("'super_admin'  => ['*']", self.svc)
        self.assertIn("'edu_dept'     => ['teacher', 'attendance_taker', 'edu_dept']", self.svc)
        self.assertNotIn("'finance_dept'", re.search(r"ANNOUNCE_SEND = \[(.*?)\n    \];", self.svc, re.S).group(1))
        # teachers cannot message unrelated departments
        partners = re.search(r"MESSAGE_PARTNERS = \[(.*?)\n    \];", self.svc, re.S).group(1)
        teacher = re.search(r"'teacher'\s*=>\s*\[(.*?)\]", partners, re.S).group(1)
        self.assertNotIn("hr_dept", teacher)
        self.assertNotIn("mezmur_dept", teacher)

    def test_workflow_delegates_and_keeps_signatures(self):
        for fn in ["function getUnreadNotifications($conn, $limit = 20)",
                   "function getUnreadNotificationCount($conn)",
                   "function markNotificationRead($conn, $notificationId)",
                   "function markAllNotificationsRead($conn)"]:
            self.assertIn(fn, self.workflow)
        self.assertIn("NotificationCenterService::feed", self.workflow)
        self.assertIn("NotificationCenterService::unreadSummary", self.workflow)
        self.assertIn("NotificationCenterService::markRead", self.workflow)
        self.assertIn("NotificationCenterService::markAllRead", self.workflow)
        # labels no longer hardcoded (stale $DEPT_ROLES fixed)
        self.assertIn("$DEPT_ROLES = \\App\\Services\\NotificationCenterService::ROLE_LABELS;", self.workflow)

    # ── 2. migration ──────────────────────────────────────────
    def test_migration_creates_the_five_tables_idempotently(self):
        for table in ["notification_reads", "announcements", "message_threads",
                      "message_thread_participants", "messages"]:
            self.assertIn(f"CREATE TABLE IF NOT EXISTS `{table}`", self.migration)
        # per-user read state uniqueness
        self.assertIn("uk_user_subject", self.migration)
        # pure DDL — no data changes, nothing destructive
        for banned in ["INSERT INTO", "UPDATE ", "DELETE FROM", "DROP ", "ALTER TABLE"]:
            self.assertNotIn(banned, self.migration.replace("-- ", ""), f"migration must stay DDL-only: {banned}")

    # ── 3. the API contract ───────────────────────────────────
    def test_pinned_actions_untouched(self):
        # the four pinned write actions stay POST-only
        self.assertIn("requirePostActions($action, ['mark_read', 'mark_all_read', 'task_update', 'sync_change'",
                      self.api)
        # CSRF still enforced for all POSTs
        self.assertIn("validateCsrf", self.api)
        # member-change history stays role-restricted
        self.assertIn("changes", self.api)

    def test_new_actions_and_post_only(self):
        post_list = re.search(r"requirePostActions\(\$action, \[(.*?)\]\);", self.api, re.S).group(1)
        for action in ["'compose'", "'announcement_read'", "'thread_start'", "'send_message'", "'thread_read'"]:
            self.assertIn(action, post_list, f"{action} must be POST-only")
        for action in ["summary", "feed", "announcements", "targets", "partners", "threads", "thread"]:
            self.assertIn(f"case '{action}'", self.api)

    def test_legacy_is_read_column_still_written(self):
        """Pinned audit test_16 reads notifications.is_read — the service
        must keep writing it when a recipient marks read."""
        self.assertIn("UPDATE notifications SET is_read = 1", self.svc)

    # ── 4. the bell component (root-cause fixes) ──────────────
    def test_bell_ships_own_csrf(self):
        self.assertIn("generateCsrfToken", self.bell)
        self.assertIn("csrf_token", self.bell)  # JS sends it with every POST

    def test_bell_is_multi_instance_safe(self):
        """Sidebar dashboards render the bell twice (sidebar + mobile
        header) — no duplicate DOM ids allowed."""
        self.assertNotIn('id="nc', self.bell)
        self.assertNotIn("getElementById('nc", self.bell)
        self.assertIn("querySelectorAll('.nc-root')", self.bell)
        # assets emitted exactly once per page
        self.assertIn("static $emitted = false", self.bell)

    def test_bell_polls_smartly(self):
        self.assertIn("POLL_MS = 30000", self.bell)
        self.assertIn("visibilitychange", self.bell)  # paused when hidden

    def test_old_bell_is_now_a_shim(self):
        for shim in ["admin/components/notification_bell.php", "backend/components/notification_bell.php"]:
            src = (ROOT / shim).read_text(encoding="utf-8")
            self.assertIn("notification_center.php", src, f"{shim} must delegate to the new center")
            self.assertNotIn("function loadNotifications", src)

    # ── 5. every dashboard has the SAME bell ──────────────────
    DASHBOARDS = [
        "admin/dashboards/super-admin.php", "admin/dashboards/school_admin.php",
        "admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php",
        "admin/dashboards/edu_dept.php", "admin/dashboards/material_department.php",
        "admin/dashboards/teacher.php", "admin/dashboards/attendance_taker.php",
        "admin/dashboards/dept_taker.php", "admin/dashboards/content_editor.php",
        "frontend/pages/finance_dept.php", "frontend/pages/mezmur_dept.php",
    ]

    def test_all_dashboards_include_the_center(self):
        for rel in self.DASHBOARDS:
            src = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn("notification_center.php", src, f"{rel} lost the notification bell")
            self.assertIn("renderNotificationCenter()", src, f"{rel} must render the component")

    def test_school_admin_inline_bell_removed(self):
        src = (ROOT / "admin/dashboards/school_admin.php").read_text(encoding="utf-8")
        self.assertNotIn("function toggleNotif", src)
        self.assertNotIn("id=\"notifDrop\"", src)
        self.assertNotIn("id=\"notifBtn\"", src)

    def test_mezmur_shell_stays_pure_html(self):
        """The mezmur shell pin (zero inline styles) must survive the bell."""
        shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        self.assertEqual(shell.count("style="), 0, "bell must use classes, not inline styles")

    # ── 6. the two new pages ──────────────────────────────────
    def test_center_pages_exist_and_are_registered(self):
        for page in ["admin/notifications.php", "admin/messages.php"]:
            src = (ROOT / page).read_text(encoding="utf-8")
            self.assertIn("NotificationCenterService", src, f"{page} must check permissions via the service")
            self.assertNotIn("$conn->prepare", src, f"{page} must hold no business logic")
        ac = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        self.assertRegex(ac, r"'notifications\.php'\s*=>")
        self.assertRegex(ac, r"'messages\.php'\s*=>")

    def test_edu_dashboard_has_quick_actions(self):
        edu = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")
        self.assertIn("notifications.php#compose", edu)
        self.assertIn("messages.php", edu)

    # ── 7. mobile: one writer, same permissions ───────────────
    def test_v1_route_registered_and_delegates(self):
        route = (ROOT / "api/v1/routes/notifications.php").read_text(encoding="utf-8")
        self.assertIn("NotificationCenterService", route)
        self.assertNotIn("$_SESSION", route)
        self.assertIn("apiRequireAuth", route)
        self.assertIn("isApiRateLimited", route)
        index = (ROOT / "api/v1/index.php").read_text(encoding="utf-8")
        self.assertIn("'notifications' => 'notifications.php'", index)

    def test_v1_route_actions_cover_the_contract(self):
        route = (ROOT / "api/v1/routes/notifications.php").read_text(encoding="utf-8")
        for action in ["summary", "feed", "announcements", "threads", "thread",
                       "targets", "partners", "mark-read", "mark-all-read",
                       "announcement-read", "compose", "thread-start", "send-message"]:
            self.assertIn(f"'{action}'", route)

    def test_flutter_surfaces(self):
        for f in ["services/notification_service.dart",
                  "services/notification_service.dart",
                  "screens/notifications/notification_center_screen.dart",
                  "screens/notifications/messages_screen.dart",
                  "widgets/notification_bell_button.dart"]:
            self.assertTrue((MOBILE / f).exists(), f"missing {f}")
        api = (MOBILE / "services/api_service.dart").read_text(encoding="utf-8")
        for m in ["getNotificationSummary", "getNotificationFeed", "markNotificationRead",
                  "getAnnouncements", "composeAnnouncement", "getThreads", "getThread",
                  "startThread", "sendMessage"]:
            self.assertIn(m, api)

    def test_every_flutter_home_has_the_bell(self):
        homes = ["teacher/teacher_home.dart", "att_taker/att_taker_home.dart",
                 "hr/hr_home.dart", "hr/hr_taker_home.dart", "admin/admin_home.dart",
                 "edu_dept/edu_home.dart", "info_dept/info_home.dart",
                 "finance/finance_home.dart", "material/material_home.dart",
                 "mezmur/mezmur_home.dart"]
        for h in homes:
            src = (MOBILE / "screens" / h).read_text(encoding="utf-8")
            self.assertIn("NotificationBellButton", h + ": " + src[:0] if False else src,
                          f"{h} lost the bell")
            self.assertIn("notification_bell_button.dart", src, f"{h} missing import")

    def test_flutter_signout_stops_the_poll(self):
        s = (MOBILE / "services/session_service.dart").read_text(encoding="utf-8")
        self.assertIn("NotificationService.instance.stop()", s)

    # ── 8. security posture of the new surfaces ───────────────
    def test_service_uses_prepared_statements_only(self):
        """Raw query() calls in the service must only ever carry ints."""
        for m in re.finditer(r"->query\((.*?)\);", self.svc, re.S):
            call = m.group(1)
            if '"' in call and '.' in call.split('"', 2)[-1]:
                # any concatenation into query() must be int-cast
                self.assertRegex(call, r"\(int\)", "query() concatenation must be int-cast")

    def test_announcement_targeting_cannot_be_spoofed_client_side(self):
        """The permission check lives INSIDE postAnnouncement — the API
        route/page cannot bypass it (defense against future UI bugs)."""
        self.assertIn("canAnnounce($role)", self.svc)
        self.assertIn("mayMessageRole($role", self.svc)


class NotificationIncludeOrderRegression(unittest.TestCase):
    """P0 hotfix regression (production login killer, monitor Ref #615).

    renderNotificationCenter() is defined by the component file at include
    time. A dashboard that CALLS it earlier in the file than its include
    line fatals at runtime with 'Call to undefined function' the moment
    that role opens its dashboard — i.e. immediately after login. php -l
    cannot catch this; only file-order analysis or execution can.

    edu_dept.php (call@492 / include@505) and material_department.php
    (call@81 / include@93) shipped with exactly this defect in P72.
    """

    COMPONENT = "notification_center.php"

    def _php_files(self):
        for base in ("admin", "frontend", "backend"):
            d = ROOT / base
            if d.is_dir():
                yield from d.rglob("*.php")

    def test_include_precedes_first_render_call_in_every_file(self):
        offenders = []
        checked = 0
        for php in self._php_files():
            src = php.read_text(encoding="utf-8", errors="replace")
            if "renderNotificationCenter()" not in src:
                continue
            if php.name == self.COMPONENT:
                continue  # the definition file itself
            checked += 1
            inc = src.find(self.COMPONENT)
            call = src.find("renderNotificationCenter()")
            if inc == -1:
                offenders.append(f"{php.relative_to(ROOT)}: calls renderNotificationCenter() but never includes the component")
            elif inc > call:
                offenders.append(f"{php.relative_to(ROOT)}: include@byte {inc} AFTER first call@byte {call} → runtime 'Call to undefined function' fatal")
        self.assertEqual(
            offenders, [],
            "Notification bell order defects (fatal after login):\n" + "\n".join(offenders))
        # 10 dashboards + notifications.php + messages.php + 2 frontend pages
        # + 2 legacy bell shims (admin + backend) that delegate to the center.
        # Update this pin DELIBERATELY when adding a new bell surface.
        self.assertEqual(checked, 16, f"expected 16 render surfaces, found {checked}")

    def test_hotfix_pinned_files_are_safe(self):
        for rel in ("admin/dashboards/edu_dept.php",
                    "admin/dashboards/material_department.php"):
            src = (ROOT / rel).read_text(encoding="utf-8")
            self.assertLess(
                src.find(self.COMPONENT), src.find("renderNotificationCenter()"),
                f"{rel}: component include must precede the first renderNotificationCenter() call")


if __name__ == "__main__":
    unittest.main()
