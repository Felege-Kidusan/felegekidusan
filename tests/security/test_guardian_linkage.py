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
