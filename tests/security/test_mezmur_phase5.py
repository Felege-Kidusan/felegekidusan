"""Phase-5 tests: mezmur attendance becomes the VERBATIM clone of
the teachers ↔ education workflow, scoped by (date, section):

  - sql/024: mezmur_submissions packet table (UNIQUE date+section),
    excused status + notes on mezmur_attendance (additive, guarded)
  - MezmurSubmissionService mirrors SubmissionService vocabulary
  - mobile API: section-scoped sheet + draft/submitted packets,
    409 lock semantics identical to teachers
  - web API: batched overview, review inbox, reason-mandatory review
  - web dashboard rescue: lazy tabs + bounded GETs (no more
    skeleton-forever), program types removed from every UI
  - mobile v2: [Section ▾] + P/A/L/E + notes + outbox keyed by
    (date, section), offline/background-sync parity

Static analysis only (mirrors the rest of the suite).
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MezmurPhase5Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql24 = (ROOT / "sql/024_mezmur_submissions.sql").read_text(encoding="utf-8")
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.css = (ROOT / "themes/components.css").read_text(encoding="utf-8")
        cls.screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart"
        ).read_text(encoding="utf-8")
        cls.analytics = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_analytics.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.sync = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/sync_service.dart"
        ).read_text(encoding="utf-8")
        cls.dart_api = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")

    # ── sql/024: additive, idempotent, guarded ────────────────
    def test_sql024_packet_table_contract(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_submissions`", self.sql24)
        self.assertIn(
            "UNIQUE KEY `uq_mezmur_submissions_date_section` (`attendance_date`, `section`)",
            self.sql24,
        )
        # grade_submissions vocabulary parity
        for col in [
            "taker_id", "status", "member_count", "present_count",
            "late_count", "absent_count", "excused_count",
            "submitted_at", "reviewed_by", "reviewed_at", "review_notes",
            "client_op_id",
        ]:
            self.assertIn(f"`{col}`", self.sql24)
        # FKs set-null (never destroy history when a user is removed)
        self.assertIn("ON DELETE SET NULL", self.sql24)

    def test_sql024_is_guarded_and_additive(self):
        # excused widening + notes column + nullable session_id are
        # guarded ALTERs; nothing dropped
        self.assertIn("information_schema.columns", self.sql24)
        self.assertIn("COLUMN_TYPE LIKE '%excused%'", self.sql24)
        self.assertIn("IS_NULLABLE = 'YES'", self.sql24)
        self.assertIn("MODIFY COLUMN `session_id` BIGINT UNSIGNED DEFAULT NULL", self.sql24)
        self.assertNotIn("DROP TABLE", self.sql24)
        self.assertNotIn("DROP COLUMN", self.sql24)
        # mezmur_days untouched (legacy labels preserved)
        self.assertNotIn("ALTER TABLE `mezmur_days`", self.sql24)

    # ── MezmurSubmissionService: edu-workflow clone ───────────
    def test_submission_service_mirrors_edu_vocabulary(self):
        for token in [
            "STATUS_INCOMPLETE = 'incomplete'",
            "STATUS_SUBMITTED = 'submitted'",
            "STATUS_APPROVED = 'approved'",
            "STATUS_REJECTED = 'rejected'",
            "STATUS_REVISION = 'revision_needed'",
            "STATUS_DRAFT = 'draft'",
            "public static function statusIsOpen(",
            "public static function normalizeStatus(",
            "public static function staffCanOverride(",
            "public static function isLockedForTaker(",
            "public static function countsFromRecords(",
        ]:
            self.assertIn(token, self.sub_service)
        # review authority: mezmur dept + admins ONLY
        self.assertIn(
            "private const REVIEW_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.sub_service,
        )

    def test_review_requires_reason_for_returns(self):
        self.assertIn("mb_strlen($notes) < 3", self.sub_service)
        self.assertIn("Write a short reason so the taker knows what to fix.", self.sub_service)
        self.assertIn("mb_substr($notes, 0, 500)", self.sub_service)
        # immutable audit trail on every decision
        self.assertIn("Mezmur Submission Reviewed", self.sub_service)
        self.assertIn("'mezmur_submission'", self.sub_service)
        self.assertIn("previous_status", self.sub_service)

    def test_upsert_lock_semantics_match_teachers(self):
        self.assertIn(
            "This attendance is already submitted. Only administrators can change it.",
            self.sub_service,
        )
        # open states: draft / incomplete / revision_needed
        self.assertIn("self::STATUS_REVISION], true)", self.sub_service)

    # ── MezmurAttendanceService: section-scoped sheets ────────
    def test_attendance_service_section_surface(self):
        for token in [
            "public static function sectionRoster(",
            "public static function sectionListWithCounts(",
            "public static function fetchSectionSheet(",
            "public static function saveSectionSheet(",
            "bool $ownTransaction = true",
        ]:
            self.assertIn(token, self.att_service)
        # teacher parity statuses incl. excused + notes column used
        self.assertIn(
            "public const STATUSES = ['present', 'late', 'absent', 'excused'];",
            self.att_service,
        )
        self.assertIn("notesByMember", self.att_service)

    def test_no_nested_transactions(self):
        # saveSectionSheet only commits/rolls back when it owns the tx
        self.assertIn("if ($ownTransaction) {", self.att_service)

    # ── mobile API routes: teacher-contract parity ────────────
    def test_mobile_route_section_endpoints(self):
        self.assertIn("$action === 'sections'", self.route)
        self.assertIn("sectionListWithCounts", self.route)
        self.assertIn("fetchSectionSheet", self.route)
        self.assertIn("saveSectionSheet", self.route)
        self.assertIn("MezmurSubmissionService::takerMayWrite", self.route)
        # 409 lock + idempotency + rate limiting intact
        self.assertIn("err('This attendance is already submitted. Only administrators can change it.', 409);", self.route)
        self.assertIn("apiIdempotencyBegin(", self.route)
        self.assertIn("isApiRateLimited('mezmur_sheet_save'", self.route)

    def test_mobile_route_atomic_rows_plus_packet(self):
        # rows and packet commit or roll back as ONE unit
        self.assertIn("$conn->begin_transaction();", self.route)
        self.assertIn("$conn->commit();", self.route)
        self.assertIn("$conn->rollback();", self.route)
        self.assertIn("false);", self.route)  # ownTransaction=false
        # legacy date-only save kept for older clients (break nothing)
        self.assertIn("MezmurAttendanceService::saveSheet($conn, $date, $records", self.route)

    # ── web API: overview batch + dept review ─────────────────
    def test_web_api_new_actions(self):
        for token in [
            "case 'overview':",
            "case 'sections':",
            "case 'submissions_list':",
            "case 'submission_detail':",
            "case 'submission_review':",
        ]:
            self.assertIn(token, self.api)
        # review is POST-only + role-checked + rate-limited as a write
        self.assertIn("'zemarian_status'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST'", self.api)
        self.assertIn("'zemarian_status'], true)\n    ? 'mezmur_write'", self.api)
        # schema-drift killer endpoints
        self.assertIn("case 'schema'", self.api)
        self.assertIn("case 'migrate'", self.api)
        self.assertIn("MezmurSubmissionService::canReview(", self.api)
        # schema probe tells admins exactly which migration to run
        self.assertIn("sql/024_mezmur_submissions.sql", self.api)

    def test_overview_is_one_round_trip(self):
        ov = self.api.split("case 'overview':")[1].split("case 'submissions_list'")[0]
        self.assertIn("mezmur_respond([", ov)
        self.assertIn("'recent_packets'", ov)
        self.assertIn("'prev_month'", ov)

    # ── web dashboard rescue ──────────────────────────────────
    def test_js_no_undefined_api_global(self):
        # ROOT CAUSE of the skeleton-forever bug: SSMS.api never existed
        self.assertNotIn("SSMS.api", self.js)
        self.assertIn("window.api.get('mezmur.php?' + q)", self.js)
        self.assertIn("window.api.post('mezmur.php', data)", self.js)

    def test_js_bounded_gets(self):
        self.assertIn("GET_TIMEOUT = 12000", self.js)
        self.assertIn("clearTimeout(timer)", self.js)
        # every timeout lands in the error+Retry state, never a spinner
        self.assertIn("The server took too long to answer", self.js)

    def test_js_lazy_tabs(self):
        self.assertIn("var tabLoaded = { overview: false, library: false, attendance: false, analytics: false, takers: false };", self.js)
        self.assertIn("window.switchSection = function (name) {", self.js)
        self.assertIn("_origSwitch(name);", self.js)
        # DOMContentLoaded loads ONLY the active tab (wiring block)
        dom = self.js.split("document.addEventListener('DOMContentLoaded'")[1]
        dom = dom.split("// Public API")[0]
        self.assertIn("loadTab(name);", dom)
        self.assertNotIn("loadOverview();", dom)
        self.assertNotIn("loadTakers();", dom)
        self.assertNotIn("loadSubmissions();", dom)

    def test_web_attendance_is_readonly_review_console(self):
        # The department INSPECTS and REVIEWS; taking attendance lives
        # exclusively in the mobile app (product decision 2026-08-28).
        for token in [
            "action=sections",
            "mzViewSection",
            "viewSheet",
            "quickReview",
            "mzRvDecision",
            "action: 'submission_review'",
            "unmarkedCount()",
        ]:
            self.assertIn(token, self.js)
        # no editing surface left on the web
        self.assertNotIn("mzAttSection", self.js)
        self.assertNotIn("mzAttDate", self.js)
        self.assertNotIn("Mezmur.setMark(", self.js)
        self.assertNotIn("saveSheet(kind)", self.js)
        self.assertNotIn("seg-btn", self.js)
        # shell shows the read-only contract (record on mobile)
        self.assertIn("Read-only — sheets are recorded and submitted from the mobile app", self.shell)

    def test_attendance_section_is_edu_submissions_clone(self):
        # 2026-08-28: the mezmur console mirrors the edu Submissions
        # workflow — Drafts/Submitted/Insights tabs, insight strip,
        # Excel export, detail + review modals.
        for token in [
            'id="mzSubTabDraft"', 'id="mzSubTabSubmitted"', 'id="mzSubTabInsights"',
            'id="mzSubStatsRow"', 'id="mzSubInsights"', 'id="mzSubSection"',
            'id="mzSubmissionsList"',
        ]:
            self.assertIn(token, self.shell)
        for fn in [
            "function switchSubTab(", "function loadSubmissions()",
            "function renderSubStats(", "function exportSubmissions()",
            "function loadSubInsights()", "function quickDecision(",
        ]:
            self.assertIn(fn, self.js)
        # insight strip data comes from the governed stats block
        self.assertIn("packetStats", self.sub_service)
        self.assertIn("$out['stats'] = MezmurSubmissionService::packetStats($conn);", self.api)
        # shared submission-tab styling lives in the component theme
        self.assertIn(".sub-tab", self.css)
        # Excel parity (SheetJS, same CDN as edu_dept)
        self.assertIn("xlsx.full.min.js", self.shell)
        self.assertIn("Mezmur.exportSubmissions()", self.shell)
        self.assertIn("Read-only — sheets are recorded and submitted from the mobile app.", self.shell)
        self.assertIn("Review Queue", self.shell)
        self.assertNotIn("Take Attendance", self.shell)
        self.assertIn('id="mzViewSection"', self.shell)

    def test_program_types_removed_from_web_ui(self):
        self.assertNotIn("mzAttProgram", self.shell)
        self.assertNotIn("mzAnProgram", self.shell)
        self.assertNotIn("mzAttProgram", self.js)
        self.assertNotIn("PROGRAM_LABELS", self.js)
        # server keeps legacy program filter (older rows) but UI drops it
        self.assertNotIn("program_type=' +", self.js)

    def test_shell_review_inbox_and_status_banner(self):
        for token in [
            'id="mzSubTbody"',
            'id="mzSheetStatus"',
            'id="mzReviewModal"',
            'id="mzPacketModal"',
            'id="mzOvQueue"',
        ]:
            self.assertIn(token, self.shell)
        self.assertIn('onclick="Mezmur.viewSheet()"', self.shell)

    def test_css_supports_excused_and_banners(self):
        self.assertIn(".seg-btn[aria-pressed=\"true\"].seg-excused", self.css)
        self.assertIn(".mz-banner", self.css)

    # ── mobile v2: teacher clone ──────────────────────────────
    def test_mobile_screen_teacher_clone(self):
        for token in [
            "_loadSections()",
            "getMezmurSections()",
            "cacheMezmurSections(",
            "'present',\n    'absent',\n    'late',\n    'excused',",
            "_statusBtn('E', 'excused'",
            "_editNote(",
            "packetKind: 'submitted'",
            "showUndoToast(",
            "TeacherActionBar(",
            "SubmittedBar()",
            "showEthiopianDatePicker(",
            "formatGregorianAsEthiopian(_selectedDate)",
        ]:
            self.assertIn(token, self.screen)

    def test_mobile_screen_packet_semantics(self):
        # server packet status drives the lock, like teachers
        self.assertIn("submission_status", self.screen)
        self.assertIn("PacketLock.isLocked(", self.screen)
        self.assertIn("dropPendingMezmur(_selectedDate, section)", self.screen)
        # department wording (not "Education") on the return banner
        self.assertIn("Returned by the Mezmur department", self.screen)
        self.assertIn("_mezmurReturnNote(", self.screen)
        self.assertNotIn("Only Education can change this", self.screen)

    def test_mobile_program_types_gone(self):
        self.assertNotIn("_program", self.screen)
        self.assertNotIn("Rehearsal", self.screen)
        self.assertNotIn("_program", self.analytics)
        self.assertNotIn("program_type", self.analytics)

    def test_mobile_outbox_is_section_scoped(self):
        self.assertIn("saveMezmurLocal(_selectedDate, section, _records()", self.screen)
        self.assertIn("getPendingMezmurRecords(_selectedDate, section)", self.screen)
        self.assertIn("cacheMezmurSheet(_selectedDate, section", self.screen)
        self.assertIn("getMezmurSheet(_selectedDate, section: section)", self.screen)

    # ── php -l (when a PHP CLI is available) ──────────────────
    def test_php_lint_new_backend_files(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        for rel in [
            "admin/backend/services/MezmurSubmissionService.php",
            "admin/backend/services/MezmurAttendanceService.php",
            "admin/api_mezmur.php",
            "api/v1/routes/mezmur.php",
        ]:
            r = subprocess.run(
                ["php", "-l", str(ROOT / rel)],
                capture_output=True, text=True, timeout=60,
            )
            self.assertEqual(r.returncode, 0, f"php -l failed for {rel}: {r.stdout}{r.stderr}")


class TypoTolerantSearchTests(unittest.TestCase):
    """Patch 22 (2026-09-01): Telegram-style typo-tolerant search.

    Root cause fixed: the fuzzy (Levenshtein) ranking tier could never
    fire because the strict pass (FULLTEXT / LIKE) must MATCH first — a
    misspelled query matched zero rows, so there was nothing to rank.
    Two-stage retrieval (bounded candidate pool under the STRUCTURAL
    filters, then fuzzy rescue + rerank) on every surface:

    - service (MezmurHymnService::listHymns): strict LIKE pass, then a
      fuzzy-rescue pool under the same filters minus the text condition
    - web (admin/api_mezmur.php list): the same rescue around the
      FULLTEXT/LIKE pass — first page only, honest totals, page-size cap
    - web JS: 160ms debounce + 2-char minimum + bounded query cache
      (every successful mutation drops it, via the apiPost choke point)
    - mobile (hymn_store.dart): structural filters in SQL, text match
      fully in memory so the fuzzy tier actually fires; 2-char clamp

    Keystroke hygiene: 1-char queries are dropped server-side (service
    AND web clamp) and client-side (web JS + Dart store) — a '%x%' scan
    cannot use an index.
    """

    @classmethod
    def setUpClass(cls):
        cls.svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")

    def test_service_two_stage_with_fuzzy_rescue(self):
        # structural filters are built separately from the text condition
        self.assertIn("$filterSql = $where ? implode(' AND ', $where) : '1=1'", self.svc)
        # stage 1: strict LIKE candidates, bounded
        self.assertIn(
            "$filterSql AND (title LIKE ? OR title_am LIKE ? OR reference LIKE ?)",
            self.svc,
        )
        # stage 2: rescue pool = same filters WITHOUT the text condition
        self.assertIn(
            '$conn->prepare($selectSearch . "$filterSql ORDER BY updated_at DESC, id DESC LIMIT 500")',
            self.svc,
        )
        # 1-char queries never reach SQL
        self.assertIn("if (mb_strlen($search) < 2) {", self.svc)

    def test_web_two_stage_with_fuzzy_rescue(self):
        self.assertIn("if (mb_strlen($search) < 2) $search = '';", self.api)
        self.assertIn("$filterSql = $where ? ('WHERE ' . implode(' AND ', $where)) : ''", self.api)
        self.assertIn("$rescued = 0;", self.api)
        self.assertIn("FROM mezmur_hymns $filterSql", self.api)
        # rescue only fills page 1 (honest totals), then respects page size
        self.assertIn("$search !== '' && $page === 1 && count($items) < $perPage", self.api)
        self.assertIn("$items = array_slice($items, 0, $perPage);", self.api)

    def test_web_client_min_length_and_query_cache(self):
        # a single character waits for the second keystroke
        self.assertIn("if (t.length === 1) return;", self.js)
        # identical queries are served from memory, not the server
        self.assertIn("if (listCache[q]) {", self.js)
        self.assertIn("cachePut(q, d);", self.js)
        self.assertIn("keys.length >= 10", self.js)  # bounded
        # every successful mutation invalidates the cache (apiPost = the
        # single choke point all mutations travel through)
        post = self.js.split("POST_TIMEOUT);")[1][:800]
        self.assertIn("if (d && d.status === 'success') listCache = {};", post)

    def test_mobile_in_memory_text_match(self):
        # 1-char clamp mirrors the server
        self.assertIn("search.trim().length < 2", self.store)
        # the text match happens in memory (LIKE would drop typos before
        # the fuzzy tier could fire) — the store no longer pushes the
        # search string into SQL
        self.assertIn("if (score <= 0) continue;", self.store)
        self.assertIn("h['similarity'] = score;", self.store)
        self.assertNotIn("search: search,", self.store)

    def test_ranked_best_first_on_all_surfaces(self):
        # every surface sorts by similarity, descending
        self.assertIn("usort($items", self.api)
        self.assertIn("scored.sort(", self.store)


class TaxonomySyncTests(unittest.TestCase):
    """Patch 23 (2026-09-01): seamless bidirectional taxonomy sync.

    Deep-analysis findings fixed (web <-> mobile categories/singers):
    - S1 normalizeIds silently dropped negative placeholder ids, so a
      hymn saved offline with a just-created category synced WITHOUT it.
      Fix: placeholder refs travel as {id, name} and the server resolves
      by natural key (name) INSIDE the hymn save, creating when absent.
    - S2 create was not idempotent: a second device creating the same
      name got a 422, dropped its op, kept the placeholder -> duplicate
      rows after the next pull. Fix: id <= 0 + existing name links to
      the existing row (natural-key convergence).
    - S3 renames echoed a hardcoded is_active=1, un-hiding hidden
      rows on the renaming device. Fix: echo the real value.
    - M1 placeholder replacement orphaned on-device join rows; they are
      now repointed at the real server id before the placeholder drops.
    - M2 queued hymn payloads still referenced placeholder ids; they are
      rewritten to synced twin ids at push time.
    - M3 the editor reloaded selections through a >0 filter, silently
      forgetting placeholder picks (re-save erased the links).
    - M4 hide/show ops on never-synced placeholders resolved by name.
    - Visibility: the web picker labels hidden entries "(hidden)"
      instead of offering them unlabeled (mobile hides them from
      picking; both preserve existing links).
    """

    @classmethod
    def setUpClass(cls):
        cls.svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_hymn_save_resolves_offline_refs_by_name(self):
        self.assertIn("parseTaxonomyRefs", self.svc)
        self.assertIn("'pendingNames'", self.svc)
        # resolve-then-create, inside the save transaction (MZ-10 pattern)
        self.assertIn("resolveNameToId($conn, 'mezmur_categories', $pname)", self.svc)
        self.assertIn("createNamedTaxonomy($conn, 'mezmur_zemarians', $pname, $actorId)", self.svc)
        self.assertIn("Mezmur Category Created (offline sync)", self.svc)

    def test_taxonomy_creates_are_idempotent(self):
        self.assertIn("if ($dup && $id <= 0) {", self.svc)
        self.assertIn("Category already exists — linked.", self.svc)
        self.assertIn("Singer already exists — linked.", self.svc)
        # rename collisions (id > 0) stay honest errors
        self.assertIn("'A category with this name already exists.'", self.svc)

    def test_rename_echoes_real_is_active(self):
        self.assertIn("SELECT name, sort_order, is_active FROM mezmur_categories", self.svc)
        self.assertIn("SELECT name, name_am, is_active FROM mezmur_zemarians", self.svc)
        self.assertIn("'is_active' => (int)$old['is_active']", self.svc)

    def test_mobile_placeholder_refs_travel_with_names(self):
        self.assertIn("_taxonomyRefPayload", self.store)
        self.assertIn("out.add({'id': id, 'name': name});", self.store)
        # queued payloads are rewritten to synced twin ids at push time
        self.assertIn("await _rewritePlaceholderRefs(payload);", self.store)

    def test_mobile_joins_repointed_before_placeholder_drop(self):
        self.assertIn("Future<void> _repointJoin(", self.store)
        self.assertIn(
            "_repointJoin('cached_hymn_categories', 'category_id',", self.store
        )
        self.assertIn(
            "_repointJoin('cached_hymn_zemarians', 'zemarian_id',", self.store
        )
        # duplicate (hymn_id, real_id) pairs removed first — PK is that pair
        self.assertIn("DELETE FROM $table WHERE $col = ? AND hymn_id IN", self.store)

    def test_mobile_preserves_placeholder_selections(self):
        self.assertIn(".where((e) => e != 0).toList()", self.db)
        # hide/show ops on placeholders resolve by name at push time
        self.assertIn("'name': row['name']", self.store)
        self.assertIn("_localIdByName", self.store)

    def test_web_picker_labels_hidden_entries(self):
        self.assertIn("function catLabel(i)", self.js)
        self.assertIn("(hidden)", self.js)


class LyricsStylingBrowseTests(unittest.TestCase):
    """Patch 24 (2026-09-01): modern lyrics styling + Spotify-like
    browsing (user item 6).

    Genius/Spotify standard (research 2026-08-31): [Section] square-
    bracket headers, **bold** / *italic* emphasis, PLAIN TEXT stored —
    parsing happens at render time only, so old data and old clients
    keep working (no migration, no schema change).

    - web: mezmur.js renderLyrics() — escape FIRST then transform
      (XSS-safe by construction); view modal renders through it; the
      editor shows a markup hint.
    - mobile: _LyricsView widget (section headers + bold/italic spans
      + stanza spacing); tappable category/singer chips open the
      filtered hymn list; the library is a self-standing screen with a
      bottom nav (Hymns | Categories | Singers) and Spotify-style
      gradient tiles carrying on-device hymn counts.
    """

    @classmethod
    def setUpClass(cls):
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.page = (
            ROOT / "frontend/pages/mezmur_dept.php"
        ).read_text(encoding="utf-8")
        cls.detail = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_detail.dart"
        ).read_text(encoding="utf-8")
        cls.lib = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart"
        ).read_text(encoding="utf-8")
        cls.editor = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_editor.dart"
        ).read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")

    def test_web_renderer_escapes_before_transform(self):
        self.assertIn("function renderLyrics(", self.js)
        # escape FIRST — everything after only adds our own safe tags
        # the escape happens inside the function head, before any
        # transform can emit markup
        body = self.js.split("function renderLyrics(")[1][:400]
        self.assertIn("var txt = esc(", body)
        self.assertIn("<strong>$1</strong>", self.js)
        self.assertIn("<em>$1</em>", self.js)

    def test_web_view_renders_through_parser(self):
        self.assertIn("$('mzViewLyrics').innerHTML = renderLyrics(", self.js)

    def test_markup_hints_present(self):
        self.assertIn("**bold**", self.page)      # web editor hint
        self.assertIn("**bold**", self.editor)    # mobile editor hint

    def test_mobile_lyrics_widget(self):
        self.assertIn("class _LyricsView", self.detail)
        self.assertIn("_sectionRe", self.detail)   # [Section] headers
        self.assertIn("_inlineRe", self.detail)    # **bold** / *italic*
        self.assertIn("TextSpan", self.detail)     # rich spans, not flat text

    def test_mobile_taxonomy_chips_open_filtered_list(self):
        self.assertIn("categoryNamesFor", self.detail)
        self.assertIn("zemarianNamesFor", self.detail)
        self.assertIn("ActionChip", self.detail)
        self.assertIn("initialCategoryId: singer ? null : id", self.detail)

    def test_library_self_standing_with_top_tabs(self):
        # P26 replaced the nested bottom navigation (Material violation)
        # with AppBar tabs — one navigation plane per screen.
        self.assertIn("TabBar(", self.lib)
        self.assertIn("TabBarView(", self.lib)
        for label in ("'Hymns'", "'Categories'", "'Singers'", "'Add'"):
            self.assertIn(label, self.lib)
        self.assertIn("initialCategoryId", self.lib)
        self.assertIn("initialZemarianId", self.lib)
        self.assertIn("_browseGrid", self.lib)
        self.assertNotIn("BottomNavigationBar", self.lib)

    def test_browse_tiles_carry_on_device_counts(self):
        self.assertIn("getCategoryHymnCounts", self.db)
        self.assertIn("getZemarianHymnCounts", self.db)
        # counts consider ACTIVE hymns only
        self.assertIn("h.status = 'active'", self.db)
        self.assertIn("Future<Map<int, int>> categoryHymnCounts()", self.store)

    def test_active_filter_is_clearable(self):
        self.assertIn("InputChip", self.lib)
        self.assertIn("onDeleted: () {", self.lib)
        # the legacy name-chip filter is gone (id-based browse instead)
        self.assertNotIn("_chip('All', '')", self.lib)


class WordIndexSearchTests(unittest.TestCase):
    """Patch 25 (2026-09-01): lyrics search + script-agnostic engine.

    User finding: searching a word that IS in the lyrics returned "no
    match". Four stacked causes, all fixed:
    - InnoDB FULLTEXT cannot tokenize Ge'ez script (verified live:
      'ሰላም' -> 0 hits on a HEALTHY index) — the engine is now the
      mezmur_hymn_words inverted index (sql/032), script-agnostic and
      prefix-scannable at scale.
    - A CREATE FULLTEXT INDEX build was observed returning 0 for
      everything; the reconciler no longer creates FT indexes at all.
    - The mobile service API's strict LIKE never included lyrics.
    - The on-device _similarity haystack excluded lyrics.

    Scoring (server + store parity): title tiers (exact 100 > prefix 90
    > substring 70 > fuzzy 40x) PLUS a lyrics tier (50 per matched
    term), match_in marker ('title' | 'lyrics') and a ±60-char snippet.
    #7 fix rides along: hymn save echoes carry categories/zemarians as
    OBJECT lists — upsertHymns now normalizes both shapes so joins are
    written at push time (they were wiped until the next delta pull).
    """

    @classmethod
    def setUpClass(cls):
        cls.svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.sql = (ROOT / "sql/032_mezmur_hymn_words.sql").read_text(encoding="utf-8")

    def test_word_table_schema(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS mezmur_hymn_words", self.sql)
        self.assertIn("PRIMARY KEY (word, hymn_id)", self.sql)  # prefix scans
        self.assertIn("VARBINARY(80)", self.sql)

    def test_service_maintains_index_on_save(self):
        self.assertIn("public static function reindexHymnWords", self.svc)
        # both write paths reindex INSIDE the save transaction, plus the
        # backfill path
        self.assertEqual(self.svc.count("self::reindexHymnWords($conn,"), 3)
        self.assertIn("DELETE FROM mezmur_hymn_words WHERE hymn_id = ?", self.svc)
        self.assertIn("public static function backfillHymnWords", self.svc)

    def test_service_search_uses_word_candidates_with_like_fallback(self):
        self.assertIn("self::searchWordCandidates($conn, $search)", self.svc)
        self.assertIn("id IN ($in) AND $filterSql", self.svc)
        # zero-guard fallback keeps the title LIKE path
        self.assertIn("title LIKE ? OR title_am LIKE ? OR reference LIKE ?", self.svc)

    def test_lyrics_tier_scoring_and_payload_hygiene(self):
        # 50/term lyrics tier, below title substring (70), above fuzzy
        self.assertIn("$score += 50.0;", self.svc)
        self.assertIn("$r['match_in'] = $titleScore > 0.0 ? 'title' : 'lyrics';", self.svc)
        self.assertIn("lyricSnippet", self.svc)
        # lyrics never travel in list payloads
        self.assertIn("unset($it['lyrics']);", self.svc)

    def test_web_uses_word_mode_with_like_zero_guard(self):
        self.assertIn("MezmurHymnService::searchWordCandidates($conn, $raw)", self.api)
        self.assertIn("$searchMode = 'word';", self.api)
        self.assertIn("$searchMode = 'like';", self.api)
        self.assertIn("OR lyrics LIKE ?", self.api)
        # scoring happens where lyrics are still loaded, ranking is sort-only
        self.assertIn("searchScore($search, (string)$r['title'], $r['title_am'], $r['reference'], (string)($r['lyrics'] ?? ''))", self.api)

    def test_reconciler_drops_fulltext_creates(self):
        self.assertIn("public const INDEXES = [];", self.rec)
        self.assertNotIn("ADD FULLTEXT INDEX", self.rec)

    def test_mobile_store_searches_lyrics(self):
        self.assertIn("score += 50;", self.store)
        self.assertIn("h['match_in'] = titleScore > 0 ? 'title' : 'lyrics';", self.store)
        self.assertIn("_lyricSnippet", self.store)

    def test_mobile_collection_search_for_unified_tabs(self):
        self.assertIn("Future<List<Map<String, dynamic>>> searchCategories(", self.store)
        self.assertIn("Future<List<Map<String, dynamic>>> searchZemarians(", self.store)

    def test_upsert_accepts_both_taxonomy_shapes(self):
        # delta pulls send *_ids; save echoes send object lists
        self.assertIn("_idListOfMaps(h['categories'])", self.db)
        self.assertIn("_idListOfMaps(h['zemarians'])", self.db)


class LibraryTopTabsTests(unittest.TestCase):
    """Patch 26 (2026-09-01): library rebuilt around Material top tabs
    and Telegram's unified search (user items 2/3/4/6/8).

    - #2 the nested BottomNavigationBar (a second nav plane inside a
      pushed screen) is GONE — Hymns/Categories/Singers/Add live as
      AppBar tabs, the Material-recommended single-plane pattern.
    - #3 ONE search field serves all three browse tabs.
    - #4 the tabs act as Telegram result-type filters over the SAME
      query: hymns rank with lyrics matches (tag + snippet), while the
      category/singer tabs show ranked catalog results; tapping one
      opens the Hymns tab filtered by it (query cleared).
    - #6 Add Hymn is a real tab (curators only) — the editor renders
      embedded (no inner Scaffold), saving resets the form and jumps
      to the Hymns tab; the FAB is gone.
    - #8 no horizontal category strip under the search bar (the tabs
      own category browsing); quick filters are length/language only.
    """

    @classmethod
    def setUpClass(cls):
        cls.lib = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart"
        ).read_text(encoding="utf-8")
        cls.editor = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_editor.dart"
        ).read_text(encoding="utf-8")

    def test_top_tabs_replace_bottom_nav(self):
        self.assertIn("TabController(", self.lib)
        self.assertIn("SingleTickerProviderStateMixin", self.lib)
        self.assertNotIn("BottomNavigationBar", self.lib)
        self.assertNotIn("floatingActionButton:", self.lib)

    def test_shared_search_field_all_browse_tabs(self):
        # one field, per-tab hint, hidden on the Add tab
        self.assertIn("if (_tab != _addTab)", self.lib)
        self.assertIn("Search hymns — title, Amharic or lyrics…", self.lib)
        self.assertIn("Search categories…", self.lib)
        self.assertIn("Search singers…", self.lib)
        # 1-char queries never search (client parity)
        self.assertIn("length >= 2", self.lib)

    def test_telegram_result_type_tabs(self):
        # same query ranks the catalogs for their tabs
        self.assertIn("_store.searchCategories(query)", self.lib)
        self.assertIn("_store.searchZemarians(query)", self.lib)
        self.assertIn("_taxonomyTab(categories: true)", self.lib)
        self.assertIn("_taxonomyTab(categories: false)", self.lib)
        self.assertIn("Widget _resultList({required bool categories})", self.lib)
        # tapping a result opens the filtered hymns list
        self.assertIn("_browseTaxonomy(id, singer: !categories)", self.lib)
        self.assertIn("_tabCtrl.animateTo(_hymnsTab)", self.lib)

    def test_hymn_rows_show_lyrics_match_context(self):
        self.assertIn("'LYRICS'", self.lib)
        self.assertIn("h['snippet']", self.lib)
        self.assertIn("match_in", self.lib)

    def test_editor_embeds_as_add_tab(self):
        self.assertIn("this.embedded = false", self.editor)
        self.assertIn("widget.embedded", self.editor)
        self.assertIn("widget.onSaved?.call();", self.editor)
        self.assertIn("MezmurHymnEditorScreen(", self.lib)
        self.assertIn("embedded: true", self.lib)

    def test_no_category_strip_under_search(self):
        # quick filters are length/language/archived only
        self.assertIn("_flagChip('Long'", self.lib)
        self.assertNotIn("_chip('All', '')", self.lib)


class TokenizerRegressionTests(unittest.TestCase):
    """P27c: guards against the tokenizer regression shipped in the
    on-device word-index commit ([\\\\p{L}...] with QUADRUPLE backslashes
    matched only the literal characters p/{/L/M/N, so every real query
    produced zero tokens and local search silently died — Amharic and
    English alike). Pins the corrected regex + the surrounding search
    plumbing that must never regress again."""

    @classmethod
    def setUpClass(cls):
        cls.db = (ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.store = (ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart").read_text(encoding="utf-8")

    def test_unicode_tokenizer_regex_single_backslash(self):
        # Raw string needs exactly ONE backslash for a unicode class.
        self.assertIn("[\\p{L}\\p{M}\\p{N}]+", self.db)

    def test_quadruple_backslash_regression_absent(self):
        # The broken form that matched only literal p/{/L/M/N characters.
        self.assertNotIn("[\\\\p{L}", self.db)

    def test_tokenizer_drops_single_char_tokens(self):
        # Server parity: WORD_MIN_CHARS = 2 (a 1-char prefix LIKE would
        # return half the library).
        self.assertRegex(self.db, r"length >= 2")

    def test_mezmur_root_payload_parsing(self):
        # Mezmur endpoints return items at the root (no data envelope);
        # without the ?? json fallback every list parsed as null.
        self.assertIn("json['data'] ?? json", self.api)

    def test_sparse_index_full_scan_fallback(self):
        # The word index only finds PREFIX hits — a typo can never match
        # it, so sparse index results must fall back to the bounded full
        # scan under the same structural filters (server two-stage parity).
        self.assertIn("candidates.length < 25", self.store)

    def test_flutter_tokenizer_unit_test_exists(self):
        # Real `flutter test` guard (runs on dev machines / CI).
        self.assertTrue(
            (ROOT / "Mobile/wbws_flutter_app/test/search_tokenizer_test.dart").exists())


class UnifiedSearchFixTests(unittest.TestCase):
    """Patch 27 (2026-09-01): the search chain actually reaches the user.

    File-by-file line-by-line audit findings, both root causes of the
    user's "search still not working":
    - mezmur_hymns.dart:127 searched ONLY the on-device SQLite; lyrics
      blobs download lazily (15/sync cycle), so most cached rows had no
      lyrics locally -> searching a lyrics word returned nothing even
      ONLINE (the P25 server engine was never asked).
    - layouts/base.php:203 loaded the page JS with NO cache-buster
      (the CSS lines above had one) -> browsers could run a stale
      mezmur.js forever, keeping pre-P22 keystroke-dropping behavior.

    Fix (Telegram/Spotify model): searchHymnsUnified merges the instant
    local index with the server word index while online (dedupe by id,
    pending-edit rows authoritative, server-discovered rows upserted),
    with a stale-response guard in the screen; the page JS include now
    carries ?v=filemtime like the CSS.
    """

    @classmethod
    def setUpClass(cls):
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.api = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")
        cls.lib = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart"
        ).read_text(encoding="utf-8")
        cls.layout = (ROOT / "frontend/layouts/base.php").read_text(encoding="utf-8")

    def test_store_merges_local_and_server_search(self):
        self.assertIn("Future<List<Map<String, dynamic>>> searchHymnsUnified(", self.store)
        self.assertIn("ConnectivityService().hasLink", self.store)
        self.assertIn("await _api.getMezmurHymns(", self.store)
        # queued local edits stay authoritative (same rule as delta pulls)
        self.assertIn("await _db.upsertHymns(serverItems, protectIds: protect);", self.store)
        # 1-char queries never search
        self.assertIn("if (q.length < 2) return local;", self.store)

    def test_screen_routes_search_through_unified_store(self):
        self.assertIn("await _store.searchHymnsUnified(", self.lib)
        # stale guard: a slower server response can't clobber a newer query
        self.assertIn("if (searching && _searchCtrl.text.trim() != query.trim()) return;", self.lib)

    def test_api_client_carries_the_filters(self):
        self.assertIn("'per_page': '$perPage'", self.api)
        self.assertIn("params['category_id'] = '$categoryId';", self.api)
        self.assertIn("params['zemarian_id'] = '$zemarianId';", self.api)

    def test_page_js_is_cache_busted(self):
        self.assertIn(".js?v=<?= filemtime(ROOT_PATH", self.layout)


if __name__ == "__main__":
    unittest.main()


class MezmurDeploymentResilienceTests(unittest.TestCase):
    """Root-cause fixes for the recurring production failures:

    1. A server whose migrations were never run (e.g. sql/024) must
       DEGRADE, never HTTP-500: PHP 8.1 mysqli throws on a missing
       table, so every mezmur_submissions read is wrapped in
       try/catch Throwable.
    2. Clients detect a stale backend via the server_meta version
       marker and show an actionable "update the server" message
       instead of a dead-end error.
    3. POSTs are bounded so the Save button can never hang forever.
    4. action=ping gives administrators a one-request deployment
       health check (code version + every migration + session_id).
    """

    @classmethod
    def setUpClass(cls):
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.response = (ROOT / "api/v1/core/response.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart"
        ).read_text(encoding="utf-8")

    # ── 1. missing-migration resilience (reads degrade, no 500) ──
    def test_submission_reads_are_exception_safe(self):
        # PHP >= 8.1 throws mysqli_sql_exception from prepare() on a
        # missing table; every packet-table READ must catch Throwable.
        self.assertGreaterEqual(self.sub_service.count("catch (\\Throwable $e)"), 8)
        # the classic read entry points all return a safe fallback
        self.assertIn("ORDER BY id DESC LIMIT 1", self.sub_service)

    def test_missing_table_gives_actionable_save_message(self):
        self.assertIn(
            "sql/024_mezmur_submissions.sql",
            self.sub_service,
            "upsert/review failures must tell admins which migration to run",
        )

    def test_section_sheet_marks_query_is_exception_safe(self):
        # fetchSectionSheet: marks read wrapped so a legacy DB degrades
        # to an unmarked sheet instead of WBSS-U01.
        chunk = self.att_service.split("fetchSectionSheet")[1][:2200]
        self.assertIn("try {", chunk)
        self.assertIn("catch (\\Throwable $e)", chunk)
        self.assertIn("$marks = [];", chunk)

    def test_overview_degrades_to_zeros(self):
        ov = self.api.split("case 'overview'")[1].split("case 'submissions_list'")[0]
        self.assertIn("catch (\\Throwable $e)", ov)
        self.assertIn("'days' => 0, 'marked' => 0, 'attended' => 0", ov)

    def test_schema_probes_handle_php7_false_return(self):
        # PHP 7 returns false from query() instead of throwing.
        self.assertIn("if ($probe === false)", self.api)

    # ── 2. version handshake (detect stale deployments) ─────────
    def test_admin_api_stamps_every_response(self):
        self.assertIn("MEZMUR_API_VERSION", self.api)
        self.assertIn("server_meta", self.api)
        self.assertIn("MEZMUR_SCHEMA_MIN", self.api)

    def test_mobile_route_stamps_responses(self):
        self.assertIn("define('MEZMUR_API_VERSION'", self.route)
        self.assertIn("server_meta", self.response)
        self.assertIn("defined('MEZMUR_API_VERSION')", self.response)

    def test_web_js_explains_generic_server_errors(self):
        self.assertIn("staleHint", self.js)
        self.assertIn("sql/024_mezmur_submissions.sql", self.js)

    def test_app_detects_and_warns_about_stale_server(self):
        # 2026-08-28 UX decision: the ALWAYS-visible "older backend"
        # banner was removed (it covered roster data and went stale);
        # the outdated-server hint now lives only in the error path,
        # and the warning banner family is still used for returns/lock.
        self.assertNotIn("_staleServer", self.screen)
        self.assertIn("sql/024_mezmur_submissions.sql", self.screen)
        self.assertIn("StatusBanner.warning", self.screen)

    # ── 3. bounded POSTs (no more Saving… forever) ──────────────
    def test_post_requests_are_bounded(self):
        self.assertIn("POST_TIMEOUT", self.js)
        self.assertIn("var POST_TIMEOUT = 20000", self.js)
        # apiPost now races against its own timeout
        post_fn = self.js.split("function apiPost")[1][:900]
        self.assertIn("setTimeout", post_fn)

    # ── 4. deployment health endpoint ───────────────────────────
    def test_ping_action_exists_and_checks_all_migrations(self):
        self.assertIn("case 'ping'", self.api)
        ping = self.api.split("case 'ping'")[1].split("case 'stats'")[0]
        for tbl in [
            "mezmur_hymns",
            "mezmur_days",
            "mezmur_attendance",
            "mezmur_attendance_audit",
            "mezmur_submissions",
        ]:
            self.assertIn(tbl, ping)
        self.assertIn("code_version", ping)
        self.assertIn("missing_tables", ping)
        self.assertIn("session_id_nullable", ping)


class MezmurProdDiagTests(unittest.TestCase):
    """Production incident #2 (2026-08-28, evening): the host runs a
    handler that masks any failure as
    {"status":"error","message":"Server error. Please try again.","ref":"#N"}
    — it hijacked even action=ping. Two structural defenses:

    1. backend/api/mezmur.php?diag=1 — dependency-free diagnostic that
       always answers HTTP 200 (unmaskable), reports PHP version, OPcache
       state, parse-checks every mezmur file under the server's own PHP,
       probes every table and the feature constant.
    2. The mezmur controller answers EVERY operational outcome with
       HTTP 200 + a status field, because the host demonstrably mangles
       non-2xx responses (401 came back as a 302 with a plain-text body).
    """

    @classmethod
    def setUpClass(cls):
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")

    def test_diag_endpoint_exists_and_is_unmaskable(self):
        self.assertIn("isset($_GET['diag'])", self.shim)
        self.assertIn("TOKEN_PARSE", self.shim)
        self.assertIn("opcache_get_status", self.shim)
        self.assertIn("MEZMUR_API_VERSION", self.shim)  # disk-version probe
        self.assertIn("FEATURE_MEZMUR", self.shim)
        # diag runs BEFORE the real controller is required
        self.assertLess(
            self.shim.index("isset($_GET['diag'])"),
            self.shim.index("admin/api_mezmur.php"),
        )

    def test_controller_is_200_only(self):
        import re
        bad = re.findall(r"mezmur_respond\([^;]*?,\s*[1-5]\d\d\);", self.api, re.S)
        self.assertEqual(bad, [], "mezmur API must never emit non-2xx (host mangles them)")

    def test_controller_requires_rate_limiter_class(self):
        # The web-failure root cause: the admin bootstrap never loaded
        # SecurityRateLimiter (only api/v1 middleware did), so every
        # request fatals with class-not-found before the try/catch.
        self.assertIn(
            "require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';",
            self.api,
        )

    def test_controller_owns_exception_handler(self):
        self.assertIn("set_exception_handler", self.api)

    def test_diag_checks_class_wiring(self):
        self.assertIn("class_wiring", self.shim)
        self.assertIn("controller_requires_rate_limiter", self.shim)

    def test_shim_php_lint(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        r = subprocess.run(
            ["php", "-l", str(ROOT / "backend/api/mezmur.php")],
            capture_output=True, text=True, timeout=60,
        )
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class MezmurSchemaReconcilerTests(unittest.TestCase):
    """Schema-drift killer: legacy tables (created before the repo)
    are never upgraded by CREATE TABLE IF NOT EXISTS, and migrations
    lag the cron code pull. The reconciler reports and closes drift
    with idempotent guarded DDL; admins trigger it with one click.
    """

    @classmethod
    def setUpClass(cls):
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")

    def test_reconciler_covers_every_mezmur_table(self):
        for tbl in ["mezmur_hymns", "mezmur_days", "mezmur_attendance",
                    "mezmur_attendance_audit", "mezmur_submissions"]:
            self.assertIn("'" + tbl + "'", self.rec)

    def test_reconciler_is_guarded_and_idempotent(self):
        self.assertIn("SHOW COLUMNS FROM", self.rec)
        self.assertIn("ALTER TABLE", self.rec)
        self.assertIn("catch (\\Throwable", self.rec)

    def test_reconciler_extends_legacy_enum_and_nullability(self):
        self.assertIn("excused", self.rec)
        self.assertIn("MODIFY COLUMN session_id BIGINT UNSIGNED DEFAULT NULL", self.rec)

    def test_api_exposes_report_and_guarded_apply(self):
        self.assertIn("case 'schema'", self.api)
        self.assertIn("case 'migrate'", self.api)
        # migrate is POST-enforced and write-rate-limited
        mig = self.api.split("in_array($action, [")[1]
        self.assertIn("'migrate'", mig)

    def test_one_click_ui_exists(self):
        self.assertIn("migrateSchema", self.js)
        self.assertIn("action: 'migrate'", self.js)
        self.assertIn("Sync DB schema", self.shell)

    def test_diag_reports_drift(self):
        self.assertIn("schema_drift", self.shim)
        self.assertIn("MezmurSchemaReconciler", self.shim)


class MezmurAdvancedSearchTests(unittest.TestCase):
    """Telegram-grade hymn search (research 2026-08-28): Telegram keeps a
    local full-text index for instant as-you-type results; we mirror that
    with InnoDB FULLTEXT boolean mode (prefix wildcards), title-weighted
    ranking, lyrics snippets, highlight marks, debounce + stale-response
    guard, and LIKE fallback. Lyrics are searchable; lists never carry
    full lyrics (snippet only).
    """

    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_server_word_index_ranked_search(self):
        # P25: the inverted word index replaced FULLTEXT (which cannot
        # tokenize Ge'ez and once built dead — 0 matches silently).
        self.assertIn("searchWordCandidates", self.api)
        self.assertIn("$searchMode = 'word';", self.api)
        self.assertIn("id IN ($in)", self.api)
        self.assertIn("mb_stripos", self.api)  # snippet around first match
        self.assertIn("'snippet'", self.api)
        self.assertIn("'match_in'", self.api)  # title vs lyrics match marker
        # boolean operators still stripped from user input
        self.assertIn('-><()~*', self.api)

    def test_like_fallback_and_token_minimum(self):
        self.assertIn("searchMode = 'like'", self.api)
        self.assertIn("OR lyrics LIKE", self.api)

    def test_lists_never_carry_full_lyrics(self):
        self.assertIn("unset($r['lyrics'])", self.api)

    def test_reconciler_ensures_word_index(self):
        # P25: the word table replaces FULLTEXT ensures (Ge'ez cannot be
        # tokenized by InnoDB FTS; dead index builds matched nothing).
        self.assertIn("'mezmur_hymn_words'", self.rec)
        self.assertIn("backfillHymnWords", self.rec)
        self.assertIn("public const INDEXES = [];", self.rec)
        self.assertIn("missing_indexes", self.rec)

    def test_client_instant_search_ux(self):
        self.assertIn("}, 160);", self.js)          # tight debounce
        self.assertIn("var seq = ++lib.seq", self.js)  # stale-response guard
        self.assertIn("<mark>$1</mark>", self.js)   # Telegram-style highlight
        self.assertIn("h.snippet", self.js)


class MezmurAuditHardeningTests(unittest.TestCase):
    """End-to-end department audit (2026-08-28): industry-standard
    hardening applied after the full feature-by-feature review:
    complete audit trail, least-privilege lock overrides, clamped
    aggregates, calendar-real dates, and a scale-safe paginated inbox.
    """

    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")

    # ── audit trail: every decision-grade mutation is logged ──
    def test_controller_loads_audit_service(self):
        self.assertIn(
            "require_once __DIR__ . '/backend/services/SecurityAuditService.php';",
            self.api,
        )

    def test_every_entry_point_loads_audit_service(self):
        # MZ-1 regression guard (2026-09-01): the mobile route and both
        # auditing services must declare the audit dependency themselves —
        # a missing require made every mobile mezmur write silently
        # unaudited (and made mobile submission review fail AFTER the
        # packet update). Source-level guard; behaviour is covered live by
        # smoke block 3p.
        self.assertIn("SecurityAuditService.php", self.route)
        self.assertIn("SecurityAuditService.php", self.hymn_svc)
        self.assertIn("SecurityAuditService.php", self.sub_service)

    def test_hymn_library_writes_are_audited(self):
        # The canonical writer (MezmurHymnService) audits every mutation;
        # the web controller delegates to it (see api_mezmur.php 'save').
        for action in (
            "Mezmur Hymn Created",
            "Mezmur Hymn Updated",
            "Mezmur Hymn Archived",
            "Mezmur Hymn Restored",
        ):
            self.assertIn(action, self.hymn_svc)
        self.assertIn("'mezmur_hymn'", self.hymn_svc)

    def test_catalog_writes_are_audited(self):
        # MZ-7 regression guard: singer RENAME used to leave no audit
        # entry; category renames must carry before/after state.
        for action in (
            "Mezmur Singer Created",
            "Mezmur Singer Renamed",
            "Mezmur Singer Activated",
            "Mezmur Singer Deactivated",
            "Mezmur Category Renamed",
        ):
            self.assertIn(action, self.hymn_svc)
        self.assertIn("'from'", self.hymn_svc)
        self.assertIn("'to'", self.hymn_svc)

    def test_schema_reconcile_is_audited(self):
        self.assertIn("Mezmur Schema Reconciled", self.api)

    def test_review_decisions_are_audited(self):
        self.assertIn("Mezmur Submission Reviewed", self.sub_service)
        self.assertIn("previous_status", self.sub_service)

    def test_packet_lifecycle_is_audited(self):
        self.assertIn("packet_upsert", self.sub_service)
        self.assertIn("auditPacket", self.sub_service)
        # both write paths (update + insert) record the trail
        self.assertEqual(self.sub_service.count("self::auditPacket("), 2)

    # ── least privilege: lock override is an admin power ──────
    def test_write_override_is_admin_only(self):
        self.assertIn(
            "private const WRITE_OVERRIDE_ROLES = ['school_admin', 'super_admin'];",
            self.sub_service,
        )
        override_fn = self.sub_service.split("function staffCanOverride")[1].split("}")[0]
        self.assertIn("WRITE_OVERRIDE_ROLES", override_fn)
        # review stays open to the department...
        self.assertIn(
            "private const REVIEW_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.sub_service,
        )

    def test_web_save_sheet_force_gated_to_admins(self):
        self.assertNotIn("'force' => true", self.api)
        self.assertIn(
            "in_array($mezmurRole, ['super_admin', 'school_admin'], true)",
            self.api,
        )

    def test_mobile_route_never_force_overrides(self):
        self.assertNotIn("'force'", self.route)

    # ── integrity: clamps + calendar-real dates ───────────────
    def test_packet_counts_are_clamped(self):
        self.assertIn("max(0, min(1000000, (int)($opts['member_count'] ?? 0)))", self.sub_service)
        self.assertIn("max(0, min(1000000, (int)($opts['absent'] ?? 0)))", self.sub_service)

    def test_dates_are_calendar_real(self):
        self.assertIn("checkdate($m, $d, $y)", self.att_service)
        self.assertIn("checkdate($m, $d, $y)", self.sub_service)
        # every date guard in the attendance service goes through the
        # calendar-real helper (regex lives only inside validDate itself)
        self.assertGreaterEqual(self.att_service.count("self::validDate($date)"), 4)
        self.assertEqual(
            self.att_service.count("preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)"), 1
        )

    # ── scale: inbox is paginated, bounded, total-aware ───────
    def test_inbox_is_paginated(self):
        self.assertIn("LIMIT ? OFFSET ?", self.sub_service)
        self.assertIn("COUNT(*) c FROM mezmur_submissions", self.sub_service)
        self.assertIn("if ($perPage > 100) $perPage = 100;", self.sub_service)
        self.assertNotIn("LIMIT 200", self.sub_service)
        self.assertIn("'total_pages'", self.sub_service)

    def test_submissions_endpoint_exposes_pagination(self):
        block = self.api.split("case 'submissions_list':")[1].split("case 'submission_detail':")[0]
        self.assertIn("'page' =>", block)
        self.assertIn("'per_page' =>", block)
        self.assertIn("mezmur_respond(['status' => 'success'] + $out);", block)

    def test_overview_uses_bounded_recent_packets(self):
        self.assertIn("'per_page' => 5", self.api)

    # ── deployment visibility ─────────────────────────────────
    def test_version_marker_bumped_both_surfaces(self):
        import re
        m = re.search(r"MEZMUR_API_VERSION', '([^']+)'", self.api)
        self.assertIsNotNone(m)
        # web + mobile surfaces advertise the SAME marker
        self.assertIn(m.group(1), self.route)
        self.assertNotIn("'phase5-schema24'", self.api)

    # ── prepared statements remain the only query path ────────
    def test_no_string_interpolated_user_data_in_sql(self):
        for src in (self.sub_service, self.att_service):
            # no $_GET/$_POST/$_REQUEST reaching into SQL strings
            self.assertNotIn("$_GET", src)
            self.assertNotIn("$_POST", src)
            self.assertNotIn("$_REQUEST", src)


class MezmurOfflineHymnTests(unittest.TestCase):
    """Offline-first hymn library (2026-08-28): Telegram/Drive
    local-first model — device SQLite is the read path, mutations go
    through an idempotent outbox, pulls use a change-token delta.
    Static contract tests; functional server behavior is covered by
    the hymn offline probe (revisions, conflicts, deltas, categories).
    """

    @classmethod
    def setUpClass(cls):
        cls.sql25 = (ROOT / "sql/025_mezmur_hymn_offline.sql").read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        M = ROOT / "Mobile/wbws_flutter_app/lib"
        cls.store = (M / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.sync = (M / "services/sync_service.dart").read_text(encoding="utf-8")
        cls.db = (M / "services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (M / "services/api_service.dart").read_text(encoding="utf-8")
        cls.screen = (M / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.editor = (M / "screens/mezmur/mezmur_hymn_editor.dart").read_text(encoding="utf-8")
        cls.detail = (M / "screens/mezmur/mezmur_hymn_detail.dart").read_text(encoding="utf-8")
        cls.cats = (M / "screens/mezmur/mezmur_categories.dart").read_text(encoding="utf-8")

    # ── migration 025: additive, guarded, idempotent ──────────
    def test_sql025_contract(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_categories`", self.sql25)
        self.assertIn("UNIQUE KEY `uq_mezmur_categories_name`", self.sql25)
        self.assertIn("INSERT IGNORE INTO `mezmur_categories`", self.sql25)
        # guarded ALTER via information_schema probe (MySQL-safe)
        self.assertIn("information_schema.columns", self.sql25)
        self.assertIn("`revision` INT UNSIGNED NOT NULL DEFAULT 1", self.sql25)
        self.assertIn("idx_mezmur_hymns_updated", self.sql25)
        self.assertIn("(`updated_at`, `id`)", self.sql25)

    def test_reconciler_covers_new_objects(self):
        self.assertIn("'mezmur_categories' =>", self.rec)
        self.assertIn("'revision'   =>", self.rec)
        self.assertIn("INSERT IGNORE INTO mezmur_categories", self.rec)
        # legacy tables get UNIQUE(name) + dedupe before seeding,
        # and the delta BTREE index is ensured outside the migration
        self.assertIn("uq_mezmur_categories_name", self.rec)
        self.assertIn("DELETE c1 FROM mezmur_categories c1", self.rec)
        self.assertIn("idx_mezmur_hymns_updated", self.rec)

    # ── server service: writers + conflict + delta ────────────
    def test_save_hymn_validation_and_conflict(self):
        self.assertIn("Title is required.", self.hymn_svc)
        self.assertIn("mb_strlen($lyrics) > 200000", self.hymn_svc)
        self.assertIn("LOWER(title) = LOWER(?)", self.hymn_svc)
        # revision-based conflict returns the server copy
        self.assertIn("base_revision", self.hymn_svc)
        self.assertIn("'conflict' => true", self.hymn_svc)
        self.assertIn("revision = revision + 1", self.hymn_svc)
        self.assertIn("Mezmur Hymn Created", self.hymn_svc)
        self.assertIn("Mezmur Hymn Updated", self.hymn_svc)

    def test_status_change_is_audited_revision_bumped(self):
        self.assertIn("setStatusHymn", self.hymn_svc)
        self.assertIn("Mezmur Hymn Archived", self.hymn_svc)
        self.assertIn("Mezmur Hymn Restored", self.hymn_svc)

    def test_delta_pull_is_cursor_ordered_and_bounded(self):
        self.assertIn("ORDER BY updated_at ASC, id ASC", self.hymn_svc)
        self.assertIn("next_cursor", self.hymn_svc)
        self.assertIn("has_more", self.hymn_svc)
        # archived rows travel in deltas (deletes are never silent)
        self.assertNotIn("status = 'active'", self.hymn_svc.split("listChangedSince")[1].split("categories")[0])
        # lyrics blob opt-in only
        self.assertIn("includeLyrics", self.hymn_svc)
        self.assertIn("min($limit, $includeLyrics ? 100 : 500)", self.hymn_svc.replace("max(1, ", ""))

    def test_category_service_guards(self):
        self.assertIn("saveCategory", self.hymn_svc)
        self.assertIn("setCategoryStatus", self.hymn_svc)
        self.assertIn("LOWER(name) = LOWER(?)", self.hymn_svc)
        self.assertIn("categoriesReady", self.hymn_svc)

    # ── mobile routes: gated + idempotent + rate-limited ──────
    def test_routes_gate_writes_and_keep_reads_open(self):
        self.assertIn("$MEZMUR_LIBRARY_WRITE_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];", self.route)
        self.assertEqual(
            self.route.count("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)"), 6
        )
        # sheet + 4 library writers + submission review + category/singer mgmt
        self.assertEqual(self.route.count("apiIdempotencyBegin("), 8)
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_hymn_write'"), 4)

    def test_routes_delta_and_conflict_shapes(self):
        self.assertIn("($ROUTE['sub'] ?? '') === 'changes'", self.route)
        self.assertIn("listChangedSince(", self.route)
        # 409 conflict carries the server copy inside data.item
        self.assertIn("err($result['message'], 409, ['data' => ['item' => $result['item'] ?? null]]);", self.route)

    # ── client: local-first store ─────────────────────────────
    def test_store_is_local_first_and_role_gated(self):
        self.assertIn("mezmur_dept", self.store)
        self.assertIn("bool get canEdit => _writeRoles.contains(_api.userRole)", self.store)
        # optimistic local write precedes any network call
        save_block = self.store.split("Future<String?> saveHymn(")[1].split("Future<String?> setHymnStatus")[0]
        self.assertIn("_db.upsertHymns(", save_block)
        self.assertIn("_db.enqueueHymnOp('hymn_save'", save_block)

    def test_store_conflict_policy_server_copy_wins(self):
        self.assertIn("res.statusCode == 409", self.store)
        self.assertIn("conflict — server copy kept", self.store)

    def test_store_protects_pending_rows_from_deltas(self):
        self.assertIn("protectIds", self.store)
        self.assertIn("upsertHymns(items, protectIds: protect)", self.store)

    def test_store_coalesces_offline_edits_into_one_create(self):
        self.assertIn("getPendingHymnSavesForLocalId", self.store)
        self.assertIn("updateHymnOpPayload", self.store)

    def test_delta_cursor_persisted_locally(self):
        self.assertIn("getHymnSyncCursor", self.store)
        self.assertIn("setHymnSyncCursor(next)", self.store)
        self.assertIn("include_lyrics", self.api)

    # ── sync engine integration ───────────────────────────────
    def test_sync_engine_drains_hymn_outbox_and_pulls(self):
        self.assertIn("HymnStore()", self.sync)
        self.assertIn("pushPending()", self.sync)
        self.assertIn("pullChanges()", self.sync)
        self.assertIn("pendingHymns", self.sync)
        self.assertIn("hymn change", self.sync)

    # ── local DB contract ─────────────────────────────────────
    def test_localdb_v11_hymn_tables(self):
        self.assertIn("version: 15,", self.db)  # 15 = on-device word index (P27c lineage)
        for t in ("cached_hymns", "pending_hymn_ops", "hymn_sync_meta",
                  "cached_mezmur_categories"):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {t}", self.db)
        self.assertIn("idx_cached_hymns_title", self.db)
        # Logout boundary (product decision 2026-08-28): member data is
        # wiped, the SHARED hymn library + queued hymn edits persist.
        wipe = self.db.split("clearAllUserData")[1]
        self.assertIn("'pending_mezmur',", wipe)
        # HR attendance is member data too — wiped on logout like the rest.
        self.assertIn("'pending_hr',", wipe)
        self.assertNotIn("'cached_hymns',", wipe)
        self.assertNotIn("'pending_hymn_ops',", wipe)
        self.assertNotIn("'hymn_sync_meta',", wipe)
        # Queued hymn edits push only for curators (identity-safe).
        self.assertIn("if (!canEdit) return 0;", self.store)

    # ── UI: instant search, curation actions, offline notes ──
    def test_library_screen_local_first(self):
        self.assertIn("_store.hymns(", self.screen)
        self.assertIn("Duration(milliseconds: 150)", self.screen)  # debounce
        self.assertIn("OfflineBanner", self.screen)
        self.assertIn("RefreshIndicator", self.screen)
        self.assertIn("_store.canEdit", self.screen)
        self.assertIn("MezmurHymnEditorScreen", self.screen)
        self.assertIn("cloud_upload_outlined", self.screen)  # pending badge

    def test_editor_offline_contract(self):
        self.assertIn("Will sync automatically", self.editor.replace("will sync automatically", "Will sync automatically"))
        self.assertIn("_store.saveHymn(hymn, baseRevision: baseRevision)", self.editor)
        self.assertIn("maxLength: 255", self.editor)

    def test_detail_reader_is_local_first_with_lazy_lyrics(self):
        self.assertIn("_store.hymn(widget.id)", self.detail)
        self.assertIn("Lyrics not downloaded yet", self.detail)
        self.assertIn("_db.upsertHymns([item])", self.detail)

    def test_categories_screen_offline_crud(self):
        self.assertIn("_store.saveCategory", self.cats)
        self.assertIn("_store.setCategoryStatus", self.cats)
        self.assertIn("maxLength: 50", self.cats)


class MezmurRenamePropagationTests(unittest.TestCase):
    """MZ-3/MZ-4 regression guards (Patch 17, 2026-09-01).

    Live behaviour is covered by smoke block 3q; these source guards keep
    the contracts from being quietly removed later:

    - A category rename must relabel every hymn that carries the label and
      touch updated_at so the delta cursor propagates it — WITHOUT bumping
      revision (a relabel is not a content change; bumping would force
      offline editors into server-wins conflicts and drop their edits).
    - The category-name filter must be join-aware in BOTH list paths so a
      multi-category hymn is findable by every label it carries.
    """

    @classmethod
    def setUpClass(cls):
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")

    def test_rename_relabels_hymns_and_touches_sync_cursor(self):
        self.assertIn(
            "UPDATE mezmur_hymns SET category=?, updated_at=NOW() WHERE category=?",
            self.hymn_svc,
        )
        # no revision bump on a relabel (see class docstring)
        self.assertNotIn(
            "UPDATE mezmur_hymns SET category=?, updated_at=NOW(), revision",
            self.hymn_svc,
        )

    def test_rename_mirror_only_runs_when_the_name_changed(self):
        # sort-order-only edits must not emit phantom hymn deltas
        self.assertIn("old['name'] !== $name", self.hymn_svc)

    def test_rename_is_transactional_and_audited_with_relabel_count(self):
        self.assertIn("hymns_relabelled", self.hymn_svc)

    def test_category_name_filter_is_join_aware_everywhere(self):
        for src in (self.hymn_svc, self.api):
            self.assertIn("category = ? OR EXISTS", src)
            self.assertIn("mc.name = ?", src)


class MezmurReadRateLimitTests(unittest.TestCase):
    """MZ-5 (Patch 18): mobile READ endpoints are rate limited.

    Writes were already bounded; reads (list, single, delta, catalogues,
    sheets) were not — any mezmur role could hammer them without limit,
    including heavy include_lyrics delta pulls. Buckets are IP-scoped by
    the shared middleware (Retry-After + X-RateLimit-Limit headers);
    budgets are generous for real humans behind NAT but stop runaway
    clients. Live behaviour: smoke block 3r.
    """

    @classmethod
    def setUpClass(cls):
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")

    def test_lightweight_reads_share_a_bounded_bucket(self):
        # days, sheet, sections, hymns list, hymn, categories, zemarians,
        # submission detail — every lightweight GET goes through the bucket.
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_api_read'"), 8)

    def test_delta_sync_is_bounded_and_lyrics_aware(self):
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_api_sync'"), 1)
        self.assertIn("include_lyrics", self.route)

    def test_analytics_reads_are_bounded(self):
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_api_analytics'"), 1)


class MezmurConcurrencyGuardTests(unittest.TestCase):
    """MZ-6 (Patch 19): optimistic concurrency + storage-level uniqueness.

    The old SELECT-then-UPDATE / SELECT-then-INSERT pairs were
    check-then-act races: concurrent writers both passed the check and
    the loser silently won (revision check) or duplicated (title check).
    Now the guard lives in the statement itself, and sql/031's UNIQUE
    index settles title races at the storage layer. Live races are
    covered by smoke block 3s (parallel writers + parallel creators).
    """

    @classmethod
    def setUpClass(cls):
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.reconciler = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/031_mezmur_hymn_title_unique.sql"
        ).read_text(encoding="utf-8")

    def test_update_is_revision_guarded(self):
        self.assertIn("WHERE id=? AND revision = ?", self.hymn_svc)

    def test_conflict_detected_from_affected_rows(self):
        self.assertIn("$baseRevision !== null && $affected === 0", self.hymn_svc)

    def test_duplicate_key_races_map_to_friendly_error(self):
        # both mysqli modes: flagged return value (pre-rollback) and
        # mysqli_sql_exception (PHP 8.1+ default; errno is reset by the
        # rollback, so the exception itself must carry the verdict)
        self.assertIn("isDuplicateKeyValue($e)", self.hymn_svc)
        self.assertIn("isDuplicateKeyError($conn)", self.hymn_svc)

    def test_unique_index_reaches_all_deployment_paths(self):
        # sql/ file for CLI deployments AND the reconciler for the admin
        # Sync button; both refuse to run over unresolved duplicates.
        for src in (self.reconciler, self.migration):
            self.assertIn("uq_mezmur_hymns_title", src)
        self.assertIn("HAVING COUNT(*) > 1", self.migration)


class MezmurMopUpTests(unittest.TestCase):
    """Patch 20 mop-up (2026-09-01): MZ-12/13/15 (web UX + privilege),
    MZ-9 (whitelist taxonomy ids), MZ-10 (orphan-free legacy create).
    Live behaviour: smoke block 3t.
    """

    @classmethod
    def setUpClass(cls):
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_migrate_is_admin_only(self):
        self.assertIn("Only administrators can reconcile the database schema.", self.api)
        self.assertIn("super_admin', 'school_admin", self.api)

    def test_unknown_taxonomy_ids_are_rejected_upfront(self):
        self.assertIn("unknownTaxonomyIds", self.hymn_svc)
        self.assertIn("no longer exists", self.hymn_svc)

    def test_legacy_category_rows_are_created_inside_the_transaction(self):
        self.assertIn("resolveLegacyCategoryId($conn, $category, false)", self.hymn_svc)
        self.assertIn("resolveLegacyCategoryId($conn, $category, true)", self.hymn_svc)
        self.assertIn("$pendingLegacyCategory", self.hymn_svc)

    def test_web_empty_state_covers_every_filter_and_offers_recovery(self):
        self.assertIn("lib.categoryId || lib.zemarianId", self.js)
        self.assertIn("Clear filters", self.js)
        self.assertIn("clearFilters: clearFilters", self.js)

    def test_web_rows_render_multi_category_badges(self):
        self.assertIn("catBadges(h)", self.js)
        self.assertIn("h.categories || []", self.js)


class MobileDartParityTests(unittest.TestCase):
    """Patch 21 (2026-09-01): honest teacher empty-state + MZ-11 cleanup.

    - teacher_grades.dart surfaces the server's bootstrap 'notice' (the
      H1 honest guidance: class-teacher-without-subjects vs plain
      no-assignment) instead of a generic hardcoded line; falls back to
      the generic line offline (verified live: /grades/bootstrap returns
      the notice for the H1 fixture).
    - hymn_store.dart placeholder cleanup also drops the taxonomy join
      rows so synced offline-created hymns never orphan joins on-device.
    """

    @classmethod
    def setUpClass(cls):
        cls.grades = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/teacher/teacher_grades.dart"
        ).read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")

    def test_teacher_subjects_empty_state_uses_server_notice(self):
        self.assertIn("_subjectsNotice", self.grades)
        self.assertIn("res.data['notice'] as String?", self.grades)
        self.assertIn("_subjectsNotice ?? 'No subjects assigned", self.grades)

    def test_placeholder_cleanup_drops_join_rows(self):
        self.assertIn("cached_hymn_categories", self.store)
        self.assertIn("cached_hymn_zemarians", self.store)
        # the join deletes live inside the placeholder cleanup
        cleanup = self.store.split("Future<void> _dropLocalPlaceholder")[1][:900]
        self.assertIn("cached_hymn_categories", cleanup)
        self.assertIn("cached_hymn_zemarians", cleanup)
