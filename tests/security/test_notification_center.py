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
        # P73 Phase 1: styling/behaviour moved to cacheable static assets.
        cls.commCss = (ROOT / "admin/css/comm.css").read_text(encoding="utf-8")
        cls.commJs = (ROOT / "admin/js/comm.js").read_text(encoding="utf-8")
        # P73 Phase 2: the shared Communication section.
        cls.section = ROOT / "admin/components/comm/comm_section.php"
        cls.sectionSrc = cls.section.read_text(encoding="utf-8")
        cls.COMM_DASH_FILES = [
            "super-admin.php", "school_admin.php", "edu_dept.php",
            "material_department.php", "attendance_taker.php", "teacher.php",
            "hr-dept.php", "info-dept.php", "dept_taker.php", "content_editor.php",
        ]
        cls.COMM_BOTTOM_NAV_FILES = [
            "super-admin.php", "school_admin.php", "edu_dept.php",
            "material_department.php", "attendance_taker.php", "teacher.php",
        ]

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

    # ── 4. the bell component (root-cause fixes; P73 Phase 1 contract) ─
    # Phase 1 moved styling to admin/css/comm.css and behaviour to
    # admin/js/comm.js (cacheable static assets). The pins below therefore
    # read the component AND the runtime files together (loaded in setUpClass).

    def test_bell_ships_own_csrf(self):
        self.assertIn("generateCsrfToken", self.bell)
        self.assertIn("data-csrf", self.bell)            # token rides on the panel
        self.assertIn("csrf_token", self.commJs)          # runtime sends it with every POST

    def test_bell_is_multi_instance_safe(self):
        """Sidebar dashboards render the bell twice (sidebar + mobile
        header) — no duplicate DOM ids allowed; ONE shared panel."""
        self.assertNotIn('id="nc', self.bell)
        self.assertNotIn("getElementById('nc", self.commJs)
        self.assertIn("querySelectorAll('.nc-root')", self.commJs)
        # assets emitted exactly once per page
        self.assertIn("static $emitted = false", self.bell)

    def test_bell_polls_smartly(self):
        self.assertIn("POLL_MS = 30000", self.commJs)
        self.assertIn("visibilitychange", self.commJs)   # paused when hidden
        # P73: backoff on failure + request de-duplication (shared hosting)
        self.assertIn("Math.pow(2", self.commJs)          # exponential backoff
        self.assertIn("inflight", self.commJs)            # GET de-duplication

    # ── 4b. P73 Phase 1 architecture pins ─────────────────────
    def test_phase1_assets_are_static_and_linked_once(self):
        """No ~30KB inline style/script blob per page — cacheable files."""
        self.assertNotIn("<style>", self.bell)
        self.assertNotIn("<script>", self.bell)
        self.assertIn('src="/admin/js/comm.js?v=', self.bell)
        self.assertIn('href="/admin/css/comm.css?v=', self.bell)
        self.assertIn("static $emitted = false", self.bell)  # linked exactly once

    def test_phase1_elevation_uses_tokens_only(self):
        """Design OS rule: no magic z-index numbers anywhere in comm.css.
        Toast sits at tooltip level so feedback stays visible above the
        open sheet/scrim (z-toast 1100 < overlay 1200 would hide it)."""
        self.assertNotRegex(self.commCss, r"z-index:\s*\d")
        self.assertIn("var(--z-overlay", self.commCss)
        self.assertIn("var(--z-tooltip", self.commCss)

    def test_phase1_panel_is_fixed_and_js_anchored(self):
        """position:fixed + measured coordinates — escapes every ancestor
        overflow/stacking-context trap (the desktop off-screen bug)."""
        self.assertIn("position: fixed", self.commCss)
        self.assertIn("getBoundingClientRect", self.commJs)
        self.assertIn("Math.max(M, Math.min", self.commJs)  # viewport clamping
        self.assertIn("r.bottom + 10", self.commJs)          # flip-over fallback

    def test_phase1_mobile_is_compact_not_full_screen(self):
        """≤768px = scrimmed sheet capped at 60vh — never an 82vh takeover."""
        self.assertIn("max-height: min(60vh", self.commCss)
        self.assertNotIn("82vh", self.commCss)
        self.assertIn(".nc-scrim", self.commCss)
        self.assertIn("var(--nav-safe-bottom", self.commCss)  # safe-area aware

    def test_phase1_error_states_have_working_retry(self):
        """Every dead-end error screen must offer a Retry that re-runs the
        failed request (the old 'Tap to retry' had no handler)."""
        self.assertIn("nc-retry", self.commJs)
        self.assertIn("errorState", self.commJs)
        self.assertIn("retryFn()", self.commJs)               # the button re-invokes the loader

    def test_phase1_writes_have_busy_and_feedback_states(self):
        self.assertIn("is-busy", self.commJs)
        self.assertIn("is-busy", self.commCss)
        self.assertIn("function toast(", self.commJs)          # success/error feedback

    def test_phase1_bell_not_buried_in_sidebar_bottom(self):
        """edu/material bells live in the always-visible brand row, not the
        cramped sidebar-bottom slot (the 'cannot access it' complaint)."""
        for rel in ("admin/dashboards/edu_dept.php",
                    "admin/dashboards/material_department.php"):
            src = (ROOT / rel).read_text(encoding="utf-8")
            marker = '<div style="flex:1"></div><?php include __DIR__ . \'/../components/notification_center.php\'; ?>'
            self.assertIn(marker, src, f"{rel}: bell must sit in the brand row")
            self.assertNotIn('justify-content:flex-end;padding:.2rem .4rem', src,
                             f"{rel}: sidebar-bottom bell slot must be gone")

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

    # ── 9. P73 Phase 2 — the Communication section ──────────────
    def test_phase2_section_partial_is_the_one_shared_surface(self):
        """The section partial is single-source: renders from the same
        service/component assets, is once-guarded, and exposes the
        data-nc-section hook + page/drawer modes."""
        self.assertTrue(self.section.exists())
        self.assertIn("data-nc-section", self.sectionSrc)
        self.assertIn("NC_COMM_SECTION_LOADED", self.sectionSrc)
        self.assertIn("notification_center.php", self.sectionSrc)   # asset guarantee
        self.assertIn("data-nc-viewpane=\"inbox\"", self.sectionSrc)
        self.assertIn("data-nc-viewpane=\"messages\"", self.sectionSrc)
        self.assertIn("data-nc-composer", self.sectionSrc)          # ported composer
        self.assertIn("data-nc-newsheet", self.sectionSrc)          # new conversation
        self.assertNotIn("$conn->", self.sectionSrc)                # markup only
        self.assertNotIn("<script>", self.sectionSrc)               # behaviour in comm.js
        self.assertNotIn("<style>", self.sectionSrc)                # styling in comm.css

    def test_phase2_every_dashboard_integrates_the_section(self):
        """All 10 dashboards: one include + at least one data-comm-open
        opener (sidebar button, header button and/or bottom-nav entry)."""
        for dash in self.COMM_DASH_FILES:
            src = (ROOT / "admin/dashboards" / dash).read_text(encoding="utf-8")
            self.assertIn(
                "comm_section.php", src,
                f"{dash} must include the shared section partial")
            self.assertIn(
                'data-comm-open="inbox"', src,
                f"{dash} must expose a Communication opener")
            self.assertEqual(
                src.count("comm_section.php"), 1,
                f"{dash} must render the section exactly once")

    def test_phase2_bottom_nav_entries_open_the_section(self):
        """Dashboards with $navItems get a bottom-nav entry carrying
        data-comm-open (the nav stays visible while the section is open)."""
        for dash in self.COMM_BOTTOM_NAV_FILES:
            src = (ROOT / "admin/dashboards" / dash).read_text(encoding="utf-8")
            self.assertRegex(
                src, r"\[\s*'icon'\s*=>\s*'fa-solid fa-comments',\s*'label'\s*=>\s*'Comms',\s*'attrs'\s*=>\s*'data-comm-open=\"inbox\"'",
                f"{dash} must add a Comms bottom-nav item")

    def test_phase2_pages_are_thin_shells(self):
        """notifications.php / messages.php contain ZERO logic and ZERO
        page-specific JS/CSS: permissions via the service, markup via the
        shared partial, behaviour via comm.js."""
        for page, view in [("admin/notifications.php", "inbox"),
                           ("admin/messages.php", "messages")]:
            src = (ROOT / page).read_text(encoding="utf-8")
            self.assertIn("comm_section.php", src, f"{page} renders the shared partial")
            self.assertIn("$NC_COMM_PAGE = true", src, f"{page} runs the partial in page mode")
            self.assertIn(f"$NC_COMM_VIEW = '{view}'", src)
            self.assertIn("NotificationCenterService::canAnnounce", src)
            self.assertIn("NotificationCenterService::canMessage", src)
            self.assertNotIn("<script>", src, f"{page} must hold no page JS")
            self.assertNotIn("<style>", src, f"{page} must hold no page CSS")
            self.assertNotIn("$conn->prepare", src)

    def test_phase2_bell_footer_links_open_the_section(self):
        """Bell popover footer links open the section when present and
        still navigate to the pages as the no-JS/section-less fallback."""
        self.assertIn('data-comm-open="inbox"', self.bell)
        self.assertIn('data-comm-open="messages"', self.bell)
        self.assertIn('href="/admin/notifications.php"', self.bell)
        self.assertIn('href="/admin/messages.php"', self.bell)

    def test_phase2_recipient_pickers_are_checkbox_lists(self):
        """The Ctrl-click native multi-select is dead: every recipient
        picker (announcement targets, new-conversation partners) is a
        checkbox pick-list — mobile-friendly per the P73 goals."""
        self.assertNotRegex(self.sectionSrc, r"<select[^>]*multiple")
        self.assertNotIn("multiple", self.sectionSrc)
        self.assertIn('class="nc-picklist" data-nc-partners', self.sectionSrc)
        self.assertIn('class="nc-picklist" data-nc-targetusers', self.sectionSrc)
        self.assertIn("nc-pickrow", self.commJs)   # rows render as checkbox labels
        self.assertIn('type="checkbox"', self.commJs)
        self.assertIn("input:checked", self.commJs)  # JS reads checkbox selections

    def test_phase2_section_mobile_never_covers_the_bottom_nav(self):
        """Mobile: the section docks above the bottom nav (never a
        full-screen takeover); without a bottom nav it respects the
        safe area only."""
        self.assertRegex(self.commCss, r"\.nc-sec\s*\{[^}]*bottom:\s*var\(--nav-total")
        self.assertRegex(self.commCss, r"\.nc-sec--nonav\s*\{[^}]*--nav-safe-bottom")
        mobile = self.commCss[self.commCss.rfind("@media (max-width: 768px)"):]  # Phase-2 block is last
        m = re.search(r"\.nc-sec\s*\{[^}]*\}", mobile)
        self.assertTrue(m, "mobile .nc-sec block must exist")
        self.assertNotRegex(m.group(0), r"bottom:\s*0\s*;")  # never a takeover
        self.assertIn(".nc-sec--page", self.commCss)  # page mode for thin shells

    def test_phase2_runtime_shares_one_list_factory(self):
        """comm.js: ONE list controller powers bell + section inbox;
        the section wires openers, deep links, close-on-navigation and
        gated message polling."""
        self.assertIn("makeListController", self.commJs)
        self.assertIn("makeListController(panel)", self.commJs)          # bell
        self.assertIn("makeListController(inboxPane)", self.commJs)      # section
        self.assertIn("[data-comm-open]", self.commJs)
        self.assertIn("wbws-bnav-btn", self.commJs)                      # close-on-nav
        self.assertIn("h === 'messages'", self.commJs)                 # deep links
        self.assertIn("h === 'inbox'", self.commJs)
        self.assertIn("h === 'compose'", self.commJs)
        self.assertIn("pollStart('msgs'", self.commJs)                   # messages-only poll
        self.assertIn("pollStop('msgs')", self.commJs)
        for action in ("'threads'", "'thread'", "thread_start", "send_message", "'partners'"):
            self.assertIn(action, self.commJs, "messaging actions ported")
        self.assertIn("'compose'", self.commJs)                          # announcements ported
        self.assertIn("'targets'", self.commJs)

    def test_phase2_composer_permissions_are_dual_gated(self):
        """Announce/New buttons: hidden server-side via $NC_COMM_CTX on
        the pages, revealed client-side from the summary on dashboards —
        the API itself always re-checks (test above pins that)."""
        self.assertIn('data-can-announce', self.sectionSrc)
        self.assertIn('data-can-message', self.sectionSrc)
        self.assertIn("can_announce", self.commJs)
        self.assertIn("can_message", self.commJs)
        for page in ("admin/notifications.php", "admin/messages.php"):
            src = (ROOT / page).read_text(encoding="utf-8")
            self.assertIn("$NC_COMM_CTX", src)

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
