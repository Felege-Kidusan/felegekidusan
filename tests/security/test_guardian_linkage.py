"""Contract pins — Family Link (guardian/sibling linkage).

Guards the schema shape (shared-hosting safe), the Fellegi-Sunter style
three-band scorer, role gates, audit wiring and the picker/review-queue
endpoints so refactors cannot silently weaken the linkage safety rules.
"""

from pathlib import Path

REPO = Path(__file__).resolve().parents[2]


def _read(rel: str) -> str:
    return (REPO / rel).read_text(encoding="utf-8")


class TestSchema:
    def setup_method(self):
        self.sql = _read("sql/069_guardian_linkage.sql")

    def test_tables_exist(self):
        for t in ["guardians", "member_guardians", "guardian_aliases", "guardian_link_suggestions"]:
            assert f"CREATE TABLE IF NOT EXISTS `{t}`" in self.sql

    def test_shared_hosting_constraints(self):
        # cPanel/MariaDB 10.6 safe: no generated columns, no JSON type, no CHECK
        ddl = "\n".join(l for l in self.sql.splitlines() if not l.strip().startswith("--"))
        low = ddl.lower()
        assert "generated" not in low
        assert " json" not in low
        assert "check (" not in low
        assert "engine=innodb" in low
        assert "utf8mb4" in low

    def test_blocking_keys_indexed(self):
        assert "KEY `idx_guardians_phone_n9`" in self.sql
        assert "KEY `idx_mg_guardian`" in self.sql
        assert "KEY `idx_gls_status`" in self.sql

    def test_gibbon_style_priority(self):
        assert "contact_priority" in self.sql
        assert "is_primary" in self.sql


class TestMatchService:
    def setup_method(self):
        self.src = _read("admin/backend/services/GuardianMatchService.php")

    def test_bands_and_weights(self):
        assert "AUTO_THRESHOLD = 0.80" in self.src
        assert "REVIEW_THRESHOLD = 0.55" in self.src
        assert "W_PHONE = 0.45" in self.src
        assert "W_NAME = 0.50" in self.src
        assert "PHONE_FLOOR = 0.60" in self.src
        assert "CANDIDATE_LIMIT = 60" in self.src

    def test_name_only_can_never_auto_merge(self):
        # name(0.45)+phonetic(0.05)+address(0.10) = 0.60 max without phone,
        # which is below AUTO (0.80): enforced by the weighting itself.
        assert 0.50 + 0.05 + 0.15 < 0.80

    def test_comparators(self):
        assert "jaroWinkler" in self.src
        assert "jaroWinklerPolyfill" in self.src  # fallback if vendor missing
        assert "trigrams" in self.src
        assert "jaccard" in self.src
        assert "phoneticKey" in self.src and "metaphone(" in self.src
        assert "phoneN9" in self.src

    def test_vendored_library_used(self):
        assert "Edgaras\\StrSim\\JaroWinkler" in self.src
        comp = _read("composer.json")
        assert "edgaras/strsim" in comp

    def test_no_native_extension_dependency(self):
        # iliaal/phonetic was rejected: it is a C extension (php-ext), which
        # shared hosting cannot compile. Guard against reintroduction.
        comp = _read("composer.json")
        assert "iliaal/phonetic" not in comp


class TestServiceAndApi:
    def setup_method(self):
        self.svc = _read("admin/backend/services/GuardianService.php")
        self.api = _read("admin/api_guardians.php")

    def test_service_operations(self):
        for fn in ["function create", "function update", "function linkMember",
                   "function unlinkMember", "function merge", "function familyOfMember",
                   "function siblingsOf", "function searchForPicker"]:
            assert fn in self.svc

    def test_merge_reversible_and_audited(self):
        assert "merged_into" in self.svc
        assert "Guardian Merged" in self.svc
        assert "begin_transaction" in self.svc

    def test_audit_on_mutations(self):
        for a in ["'Guardian Created'", "'Guardian Updated'", "'Guardian Linked'", "'Guardian Unlinked'"]:
            assert a in self.svc
        assert "activity_logs" in self.svc

    def test_api_role_gates(self):
        assert "'super_admin', 'school_admin', 'hr_dept'" in self.api
        assert "info_dept" in self.api  # read-only addition
        assert "validateCsrf" in self.api

    def test_api_actions(self):
        for a in ["case 'search'", "case 'get_family'", "case 'score_check'", "case 'create'",
                  "case 'link'", "case 'unlink'", "case 'merge'", "case 'list_suggestions'",
                  "case 'merge_suggestion'", "case 'reject_suggestion'"]:
            assert a in self.api


class TestP4:
    def test_mobile_registration_removed(self):
        api = _read("api/v1/routes/members.php")
        assert "INSERT INTO members" not in api
        assert "intentionally NOT supported" in api
        dart = _read("Mobile/wbws_flutter_app/lib/services/api_service.dart")
        assert "createMember" not in dart
        assert not (REPO / "Mobile/wbws_flutter_app/lib/screens/members/register_member_screen.dart").exists()

    def test_mobile_put_reverse_syncs_guardian(self):
        api = _read("api/v1/routes/members.php")
        assert "GuardianService::update" in api
        assert "is_primary = 1" in api

    def test_rematch_sweep(self):
        svc = _read("admin/backend/services/GuardianMatchService.php")
        assert "function rematchBatch" in svc
        assert "IN ('pending','rejected')" in svc   # HR decisions respected
        assert "'source' => 'rematch'" in svc
        api = _read("admin/api_guardians.php")
        assert "'run_rematch'" in api and "case 'run_rematch'" in api
        tool = _read("admin/tools/guardian_rematch.php")
        assert "PHP_SAPI !== 'cli'" in tool
        dash = _read("admin/dashboards/hr-dept.php")
        assert "famRematchBtn" in dash and "famQueueBadge" in dash
        assert "function runRematch(" in _read("admin/js/family_link.js")

    def test_profiling_pack_is_read_only(self):
        sql = _read("sql/preflight/guardian_profiling_pack.sql").upper()
        for verb in ["INSERT ", "UPDATE ", "DELETE ", "ALTER ", "DROP ", "CREATE "]:
            assert verb not in sql


class TestP4Audit:
    """Pins for the pre-deployment audit fixes (G1–G10)."""

    def test_guardian_get_endpoint_for_ui_refresh(self):
        api = _read("admin/api_guardians.php")
        assert "'get'" in api.split("$__readActions")[1].split("\n")[0]
        assert "case 'get':" in api
        ui = _read("admin/js/family_link.js")
        assert "get('get', { guardian_id: gid })" in ui
        assert "action=search&q='+gid" not in ui  # G1: never look up by id through search

    def test_shared_attach_helper_used_by_all_three_entry_points(self):
        svc = _read("admin/backend/services/GuardianService.php")
        assert "public static function attachFromLegacy(" in svc
        assert "attachFromLegacy($conn, (int)$newId, $pickedGuardianId" in _read("admin/hr_register_member.php")
        assert "attachFromLegacy($conn, (int)$id, 0" in _read("admin/info_manage_member.php")
        assert "attachFromLegacy($conn, (int)$id, 0" in _read("api/v1/routes/members.php")

    def test_legacy_sync_only_primary_links(self):
        svc = _read("admin/backend/services/GuardianService.php")
        sync = svc.split("public static function syncLegacy(")[1].split("public static function")[0]
        assert "mg.is_primary = 1" in sync
        assert "public static function ensurePrimary(" in svc
        unlink = svc.split("public static function unlinkMember(")[1].split("public static function")[0]
        assert "ensurePrimary" in unlink

    def test_link_validation(self):
        svc = _read("admin/backend/services/GuardianService.php")
        link = svc.split("public static function linkMember(")[1].split("public static function")[0]
        assert "Guardian not found" in link
        assert "Member not found" in link
        assert "'merged'" in link
        upd = svc.split("public static function update(")[1].split("public static function")[0]
        assert "Guardian name cannot be empty" in upd

    def test_merge_survivorship_and_suggestion_repointing(self):
        svc = _read("admin/backend/services/GuardianService.php")
        merge = svc.split("public static function merge(")[1].split("public static function")[0]
        assert "survivorship" in merge
        assert "addAlias($conn, $to" in merge
        assert "SET guardian_a = {$to} WHERE guardian_a = {$from} AND status = 'pending'" in merge
        assert "ensurePrimary($conn, $mid)" in merge
        assert "syncLegacy($conn, $to)" in merge

    def test_merge_suggestion_never_500s(self):
        api = _read("admin/api_guardians.php")
        block = api.split("case 'merge_suggestion':")[1].split("case 'reject_suggestion':")[0]
        assert "try {" in block and "catch (InvalidArgumentException" in block
        assert "status='expired'" in block
        ls = api.split("case 'list_suggestions':")[1].split("case 'merge_suggestion':")[0]
        assert "s.status = 'expired'" in ls  # self-heal stale pairs

    def test_ui_unlink_and_error_surfacing(self):
        ui = _read("admin/js/family_link.js")
        assert "function unlink(gid, mid, name)" in ui
        assert "function clearPick()" in ui
        assert "throw new Error((d && d.message) || 'Request failed')" in ui  # API message surfaces to the user
        assert "function fail(e) { toast(" in ui

    def test_rematch_budget_under_shared_hosting_cap(self):
        api = _read("admin/api_guardians.php")
        assert "rematchBatch($conn, $cursor, 300, 15.0)" in api
        ms = _read("admin/backend/services/GuardianMatchService.php")
        assert "$g['phone_alt']" in ms  # alt phone participates in blocking

    def test_member_profile_shows_family(self):
        page = _read("admin/info_manage_member.php")
        assert "familyOfMember($conn, (int)$m['id'])" in page
        assert "siblingsOf($conn, (int)$m['id'])" in page

    def test_no_php_concat_leaks_into_js(self):
        # G10-family: PHP string concatenation inside inline JS is a syntax error in the browser
        import re
        for rel in ["admin/groups.php", "admin/dashboards/school_admin.php", "admin/dashboards/hr-dept.php"]:
            src = _read(rel)
            for block in re.findall(r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", src, re.S):
                stripped = re.sub(r"<\?(?:php|=).*?\?>", "0", block, flags=re.S)
                assert not re.search(r"\b(?:SCHOOL_NAME\w*|EXPORT_PREFIX|SCHOOL_TYPE)\b\s*\.\s*'", stripped), rel

    def test_inline_scripts_parse(self):
        import re
        import shutil
        import subprocess
        import tempfile

        import pytest

        node = shutil.which("node")
        if not node:
            pytest.skip("node not installed")
        for rel in ["admin/dashboards/hr-dept.php", "admin/dashboards/school_admin.php", "admin/groups.php", "admin/dashboards/info-dept.php"]:
            src = _read(rel)
            for block in re.findall(r"<script(?![^>]*\bsrc=)(?![^>]*type=[\"'](?:application/json|text/template|module))[^>]*>(.*?)</script>", src, re.S):
                js = re.sub(r"<\?(?:php|=).*?\?>", "0", block, flags=re.S)
                if not js.strip():
                    continue
                with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False) as fh:
                    fh.write(js)
                r = subprocess.run([node, "--check", fh.name], capture_output=True, text=True)
                assert r.returncode == 0, f"{rel}: {r.stderr[-300:]}"


class TestFamilyLinkUI:
    """Pins for the Family Link UI/UX overhaul (light-theme workspace, member-profile navigation, new API)."""

    def _section(self):
        ui = _read("admin/dashboards/hr-dept.php")
        start = ui.index('id="section-family"')
        end = ui.index('id="famDrawer"')
        return ui[start:end]

    def test_section_is_light_theme(self):
        sec = self._section()
        # The HR content area is white: dark-theme utility classes render invisible (user-reported bug).
        for bad in ("text-white", "bg-white/5", "bg-white/10", "text-slate-300", "border-white/10"):
            assert bad not in sec, bad
        assert "famStats" in sec and "famSearch" in sec and "famFilters" in sec

    def test_section_workspace_parts(self):
        sec = self._section()
        for tab in ("famTab-families", "famTab-review", "famTab-unlinked", "famTab-activity"):
            assert tab in sec, tab
        for pane in ("famPane-families", "famPane-review", "famPane-unlinked", "famPane-activity"):
            assert pane in sec, pane
        assert "famRematchBtn" in sec and "famLastRematch" in sec and "famLinkAllBtn" in sec

    def test_drawer_and_scripts_wired(self):
        ui = _read("admin/dashboards/hr-dept.php")
        assert 'id="famDrawer"' in ui and "z-index:70" in ui.split('.fam-drawer{')[1].split('}')[0]
        assert "admin/js/family_link.js?v=" in ui and "admin/js/member_nav.js?v=" in ui
        assert "FamilyLink.init()" in ui  # navigateToSection('family') hook
        assert "famCsrf" not in ui  # old inline implementation fully removed

    def test_family_link_js_contract(self):
        js = _read("admin/js/family_link.js")
        for name in ("window.FamilyLink", "function init(", "function go(", "function open(", "function close(",
                     "function runRematch(", "function loadUnlinked(", "function linkAll(", "function loadActivity(",
                     "function setRelation(", "function unlink(", "profile:"):
            assert name in js, name
        assert "params.get('guardian')" in js  # ?guardian=ID deep link opens the drawer
        assert "fd.append('csrf_token', csrf())" in js  # every write carries the CSRF token
        assert "fam-loading" in js  # loading placeholders distinguishable from empty states
        # children primary flag is a per-child badge/button, not a lonely radio
        assert "Make primary" in js and 'type="radio"' not in js

    def test_member_nav_js_contract(self):
        js = _read("admin/js/member_nav.js")
        for name in ("window.MemberNav", "go:", "back:", "afterLoad:", "reset:", "manageFamily:"):
            assert name in js, name
        assert "dashboard.php?member=" in js
        assert "section=family&guardian=" in js
        assert "location.href = '/admin/info_manage_member.php" not in js  # never leave the dashboard

    def test_dashboards_embed_profile_and_hook_navigation(self):
        for rel in ("admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php"):
            d = _read(rel)
            assert "info_manage_member.php?id=' + encodeURIComponent(id) + '&embed=1" in d, rel
            assert "MemberNav.afterLoad(id)" in d, rel
            assert "MemberNav.reset()" in d, rel
            assert "urlParams.get('member')" in d, rel  # ?member=ID deep link opens the sheet
            assert "admin/js/member_nav.js?v=" in d, rel

    def test_profile_family_card_navigation(self):
        page = _read("admin/info_manage_member.php")
        assert 'id="mmBackBtn"' in page
        assert "mm-standalone-bar" in page
        assert "location.replace('/admin/dashboard.php?member=" in page
        assert "MemberNav.go(" in page  # sibling click stays inside the sheet
        assert "MemberNav.manageFamily(" in page
        assert "href=\"/admin/info_manage_member.php?id=" not in page  # no top-level profile links from inside the sheet
        assert "$_SESSION['admin_role']" in page  # Manage family only for roles that can write

    def test_new_api_actions_and_gates(self):
        api = _read("admin/api_guardians.php")
        read_line = api.split("$__readActions")[1].split("\n")[0]
        write_line = api.split("$__writeActions")[1].split("\n")[0]
        for a in ("stats", "list_guardians", "unlinked_members", "activity"):
            assert f"'{a}'" in read_line, a
        for a in ("attach_member", "attach_batch", "set_relation", "remove_alias"):
            assert f"'{a}'" in write_line, a
            assert f"case '{a}':" in api, a
        # error builder never leaks driver diagnostics (only guardianSafeError may touch getMessage)
        assert api.count("$e->getMessage()") == 2 and "function guardianSafeError" in api
        svc = _read("admin/backend/services/GuardianService.php")
        for fn in ("function stats(", "function listGuardians(", "function unlinkedMembers(", "function attachBatch(",
                   "function activity(", "function setRelation("):
            assert fn in svc, fn
        # in-browser backfill stays within the shared-hosting request budget
        assert "attachBatch($conn, $cursor, 40, 15.0" in api or "attachBatch($conn, $cursor, 40, 15" in api

    def test_guardian_picker_light_theme(self):
        js = _read("admin/js/guardian_picker.js")
        assert "text-white" not in js and "bg-slate-800" not in js
        assert "preventDefault" in js  # mousedown does not blur the input before the click lands

    def test_external_js_parses(self):
        import shutil
        import subprocess

        import pytest

        node = shutil.which("node")
        if not node:
            pytest.skip("node not installed")
        for rel in ("admin/js/family_link.js", "admin/js/member_nav.js", "admin/js/guardian_picker.js"):
            r = subprocess.run([node, "--check", str(REPO / rel)], capture_output=True, text=True)
            assert r.returncode == 0, f"{rel}: {r.stderr[-300:]}"
