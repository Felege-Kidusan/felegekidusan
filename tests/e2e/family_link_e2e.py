#!/usr/bin/env python3
"""
Family Link end-to-end audit (run against a local php -S test server).

    php -S 0.0.0.0:8080 -t /path/to/SSMS   (DB per admin/config.php)
    python3 tests/e2e/family_link_e2e.py [base_url]

Drives every Family Link workflow over HTTP exactly like the browser / mobile
app: HR registration (typed / picker / auto-match / review), web edit
reverse-sync, mobile PUT reverse-sync, HR guardian API (get / link / unlink /
update / merge / suggestions / rematch), role + CSRF gates, backfill & rematch
CLIs. Asserts DB state through mysql CLI (sudo mysql ssms_test).
"""
import json
import re
import subprocess
import sys
import uuid

import requests

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
DB = "ssms_test"
ROOT = subprocess.check_output(["git", "rev-parse", "--show-toplevel"], text=True).strip()

PASS = 0
FAIL = 0


def check(cond, label, extra=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print(f"  ok   {label}")
    else:
        FAIL += 1
        print(f"  FAIL {label} {extra}")


def sql(q):
    out = subprocess.run(["sudo", "mysql", "-N", "-B", DB, "-e", q], capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return [line.split("\t") for line in out.stdout.splitlines() if line.strip()]


def one(q):
    r = sql(q)
    return r[0][0] if r and r[0] else None


def login(username, password):
    s = requests.Session()
    page = s.get(f"{BASE}/admin/index.php").text
    tok = re.search(r'csrf_token" value="([0-9a-f]{64})', page).group(1)
    r = s.post(f"{BASE}/admin/backend/login.php", data={"username": username, "password": password, "csrf_token": tok}, allow_redirects=False)
    assert r.status_code in (302, 200), r.status_code
    dash = s.get(f"{BASE}/admin/dashboards/hr-dept.php").text
    m = re.search(r"CSRF_TOKEN\s*=\s*'([0-9a-f]{64})'", dash) or re.search(r'csrf_token" value="([0-9a-f]{64})', dash)
    s.csrf = m.group(1) if m else tok
    return s


def api(s, action, **data):
    data = dict(data)
    data["action"] = action
    data["csrf_token"] = s.csrf
    r = s.post(f"{BASE}/admin/api_guardians.php", data=data)
    try:
        return r.status_code, r.json()
    except ValueError:
        return r.status_code, {"raw": r.text[:300]}


def api_get(s, action, **params):
    params["action"] = action
    r = s.get(f"{BASE}/admin/api_guardians.php", params=params)
    try:
        return r.status_code, r.json()
    except ValueError:
        return r.status_code, {"raw": r.text[:300]}


def register(s, full_name, **fields):
    data = {
        "full_name_am": full_name,
        "gender": "male",
        "registration_type": "waiting",
        "registration_request_id": uuid.uuid4().hex,
        "csrf_token": s.csrf,
    }
    data.update(fields)
    r = s.post(f"{BASE}/admin/hr_register_member.php", data=data, headers={"X-Requested-With": "XMLHttpRequest"})
    try:
        j = r.json()
    except ValueError:
        raise RuntimeError(f"register non-JSON {r.status_code}: {r.text[:300]}")
    assert j.get("status") == "success", j
    return int(one(f"SELECT MAX(id) FROM members"))


def web_edit(s, member_id, **fields):
    cur = sql(f"SELECT student_name, guardian_name, guardian_phone1, guardian_phone2 FROM members WHERE id={member_id}")[0]
    data = {"id": member_id, "csrf_token": s.csrf, "student_name": cur[0],
            "guardian_name": cur[1] if cur[1] != "NULL" else "", "guardian_phone1": cur[2] if cur[2] != "NULL" else "",
            "guardian_phone2": cur[3] if cur[3] != "NULL" else ""}
    data.update(fields)
    r = s.post(f"{BASE}/admin/info_manage_member.php", data=data, headers={"X-Requested-With": "XMLHttpRequest"}, allow_redirects=False)
    return r.status_code


def member_legacy(mid):
    r = sql(f"SELECT COALESCE(guardian_name,''), COALESCE(guardian_phone1,''), COALESCE(guardian_phone2,'') FROM members WHERE id={mid}")[0]
    return tuple(r)


def primary_of(mid):
    v = one(f"SELECT guardian_id FROM member_guardians WHERE member_id={mid} AND is_primary=1")
    return int(v) if v else 0


def links_of(mid):
    return sorted(int(x[0]) for x in sql(f"SELECT guardian_id FROM member_guardians WHERE member_id={mid}"))


def guardian(gid):
    r = sql(f"SELECT display_name, COALESCE(phone_primary,''), COALESCE(phone_alt,''), status, COALESCE(merged_into,0) FROM guardians WHERE id={gid}")
    return tuple(r[0]) if r else None


def mobile_token():
    r = requests.post(f"{BASE}/api/v1/auth/login", json={"username": "superadmin", "password": "Test@12345"})
    d = r.json()["data"]
    return d.get("access_token") or d.get("token")


def mobile_put(tok, mid, body):
    return requests.put(f"{BASE}/api/v1/members/{mid}", json=body, headers={"Authorization": f"Bearer {tok}"})


def cli(*args):
    return subprocess.run(["php", *args], capture_output=True, text=True, cwd=ROOT)


# ─────────────────────────────────────────────────────────────────────────
print("== 0. reset + migration idempotency")
sql("SET FOREIGN_KEY_CHECKS=0; TRUNCATE member_guardians; TRUNCATE guardian_aliases; TRUNCATE guardian_link_suggestions; TRUNCATE guardians; DELETE FROM members; SET FOREIGN_KEY_CHECKS=1;")
mig = open(f"{ROOT}/sql/069_guardian_linkage.sql").read()
for _ in range(2):
    subprocess.run(["sudo", "mysql", DB], input=mig, text=True, check=True)
check(one("SHOW TABLES LIKE 'guardians'") == "guardians", "069 applied twice without error (idempotent)")

sa = login("superadmin", "Test@12345")
# a plain info_dept user for role gates
sql("DELETE FROM users WHERE username='infouser'")
sql("INSERT INTO users (username, email, full_name, role, password_hash, is_active) VALUES ('infouser','info@test.local','Info User','info_dept','" + subprocess.check_output(["php", "-r", "echo password_hash('Test@12345', PASSWORD_DEFAULT);"], text=True) + "',1)")
info = login("infouser", "Test@12345")

print("== 1. HR registration paths")
m1 = register(sa, "Abel Tesfaye Kebede", guardian_name="Aster Bekele", guardian_phone1="0911234567", guardian_city="Addis Ababa", guardian_sub_city="Bole")
g1 = primary_of(m1)
check(g1 > 0, "typed guardian -> guardian created + primary link", str(g1))
check(member_legacy(m1) == ("Aster Bekele", "0911234567", ""), "legacy columns mirror guardian", str(member_legacy(m1)))

m2 = register(sa, "Beza Tesfaye Kebede", guardian_name="Astr Bekele", guardian_phone1="+251 911 234 567")
check(primary_of(m2) == g1, "typo + international format -> auto-linked to same guardian", str(primary_of(m2)))
check(member_legacy(m2) == ("Aster Bekele", "0911234567", ""), "auto-link rewrites legacy columns from the guardian (single source of truth)", str(member_legacy(m2)))
check(int(one("SELECT COUNT(*) FROM guardians")) == 1, "no duplicate guardian created")

m3 = register(sa, "Caleb Alemu Worku", guardian_name="Kebede Alemu", guardian_phone1="0922000001")
g2 = primary_of(m3)
# Different grandfather → not siblings under D5, so only the scored path applies.
m4 = register(sa, "Dawit Alemu Gebre", guardian_name="Kebede Alemu", guardian_phone1="0933000002")
g3 = primary_of(m4)
check(g2 and g3 and g2 != g3, "same name / different phone -> separate guardians (review band)")
sug = sql(f"SELECT id, band, status FROM guardian_link_suggestions WHERE (guardian_a={g2} AND guardian_b={g3}) OR (guardian_a={g3} AND guardian_b={g2})")
check(len(sug) == 1 and sug[0][1] == "review" and sug[0][2] == "pending", "review suggestion filed", str(sug))

m5 = register(sa, "Eden Tesfaye Kebede", guardian_link_id=str(g1))  # picker, typed fields blank
check(primary_of(m5) == g1, "picker-selected guardian linked")
check(member_legacy(m5) == ("Aster Bekele", "0911234567", ""), "picker: blank typed fields replaced by guardian values (G5)", str(member_legacy(m5)))

m6 = register(sa, "Feven Girma Tadesse")
check(links_of(m6) == [], "no guardian typed -> no link, no empty guardian")
check(int(one("SELECT COUNT(*) FROM guardians WHERE display_name=''")) == 0, "never an empty-name guardian")

m7 = register(sa, "ሄኖክ ተስፋዬ", guardian_name="አስቴር በቀለ", guardian_phone1="0944000003")
g4 = primary_of(m7)
check(g4 > 0 and guardian(g4)[0] == "አስቴር በቀለ", "Amharic guardian name round-trips", str(guardian(g4)))

# D5 (owner decision 2026-10-21): same guardian name AND same father +
# grandfather names as a linked member → auto-link, even with no phone.
m8 = register(sa, "Hana Tesfaye Kebede", guardian_name="Aster Bekele", guardian_phone1="")  # sibling of m1, no phone
check(primary_of(m8) == g1, "D5: same guardian name + same father/grandfather, NO phone -> auto-linked", str(primary_of(m8)))
m8b = register(sa, "Lidya Mekonnen Abebe", guardian_name="Aster Bekele", guardian_phone1="")  # other family, no phone
check(primary_of(m8b) not in (0, g1), "identical guardian name, other family, NO phone -> not auto-linked (review at most)", str(primary_of(m8b)))

print("== 2. family reads")
st, fam = api_get(sa, "get_family", member_id=m1)
sib = sorted(int(x["id"]) for x in fam["siblings"])
check(st == 200 and sib == sorted([m2, m5, m8]), "get_family lists siblings via shared guardian", str(sib))
st, g = api_get(sa, "get", guardian_id=g1)
check(st == 200 and len(g["guardian"]["children"]) == 4 and all(int(k["is_primary"]) == 1 for k in g["guardian"]["children"]), "get guardian -> children with primary flags (G1)")
st, _ = api_get(sa, "get", guardian_id=999999)
check(st == 404, "get unknown guardian -> 404")
st, pick = api_get(sa, "search", q="0911 23")
check(st == 200 and [x for x in pick["guardians"] if int(x["id"]) == g1], "picker partial-phone search finds guardian")
st, pick = api_get(sa, "search", q="Ast")
check(st == 200 and [x for x in pick["guardians"] if int(x["id"]) == g1], "picker name-prefix search finds guardian")

print("== 3. web edit reverse-sync (info_manage_member.php)")
code = web_edit(sa, m2, guardian_phone1="0911999999")
check(code in (200, 302), f"web edit accepted ({code})")
check(guardian(g1)[1] == "0911999999", "guardian phone updated from child edit", str(guardian(g1)))
check(member_legacy(m1)[1] == "0911999999" and member_legacy(m5)[1] == "0911999999", "siblings' legacy columns follow", f"{member_legacy(m1)} {member_legacy(m5)}")
web_edit(sa, m6, guardian_name="Marta Tesfaye", guardian_phone1="0955000005")
g5 = primary_of(m6)
check(g5 > 0 and guardian(g5)[0] == "Marta Tesfaye", "guardian entered for the first time via web edit -> created + linked (G6)", str(g5))

print("== 4. mobile PUT reverse-sync")
tok = mobile_token()
r = requests.post(f"{BASE}/api/v1/members", json={"full_name_am": "X"}, headers={"Authorization": f"Bearer {tok}"})
check(r.status_code == 404, "POST /api/v1/members removed (404)", str(r.status_code))
r = mobile_put(tok, m6, {"guardian_phone1": "0955111111"})
check(r.status_code == 200 and guardian(g5)[1] == "0955111111", "phone-only PUT updates guardian", str(guardian(g5)))
r = mobile_put(tok, m6, {"guardian_name": "Marta T. Haile"})
check(guardian(g5) [0] == "Marta T. Haile" and guardian(g5)[1] == "0955111111", "name-only PUT keeps phone", str(guardian(g5)))
m9 = register(sa, "Yared Solomon Bekele")
r = mobile_put(tok, m9, {"guardian_name": "Solomon Bekele", "guardian_phone1": "0966000006"})
check(primary_of(m9) > 0, "mobile PUT on unlinked member attaches a guardian (G6)")
r = mobile_put(tok, m9, {"guardian_name": "Marta T. Haile", "guardian_phone1": "0955111111"})
check(guardian(primary_of(m9))[0] == "Marta T. Haile", "mobile PUT edits existing primary (no duplicate guardian)")

print("== 5. HR guardian API: link / unlink / update / primaries")
st, j = api(sa, "link", guardian_id=g1, member_id=m3, relation="mother", contact_priority=2)
check(st == 200, "link second guardian (mother) to Caleb", str(j))
check(primary_of(m3) == g2 and member_legacy(m3)[0] == "Kebede Alemu", "second link does not steal primary / legacy columns (G4)", str(member_legacy(m3)))
api(sa, "update", guardian_id=g1, display_name="Aster Bekele Haile", phone_primary="0911999999", phone_alt="")
check(member_legacy(m3)[0] == "Kebede Alemu", "updating the non-primary guardian leaves child's legacy columns alone (G4)", str(member_legacy(m3)))
check(member_legacy(m1)[0] == "Aster Bekele Haile", "updating primary guardian propagates to its children")
st, j = api(sa, "unlink", guardian_id=g2, member_id=m3)
check(st == 200 and primary_of(m3) == g1 and member_legacy(m3)[0] == "Aster Bekele Haile", "unlinking the primary promotes the remaining guardian + resyncs legacy (G4)", f"{primary_of(m3)} {member_legacy(m3)}")
api(sa, "unlink", guardian_id=g1, member_id=m3)
api(sa, "link", guardian_id=g2, member_id=m3, relation="father", contact_priority=1)
check(primary_of(m3) == g2, "first link of an unlinked member becomes primary automatically")
st, j = api(sa, "update", guardian_id=g1, display_name="   ", phone_primary="0911999999")
check(st == 422 and "empty" in j.get("message", ""), "empty guardian name rejected (G3)", str(j))
st, j = api(sa, "link", guardian_id=g1, member_id=987654)
check(st == 422, "link to unknown member rejected (G3)", str(j))
st, j = api(sa, "link", guardian_id=987654, member_id=m1)
check(st == 422, "link to unknown guardian rejected (G3)", str(j))
st, j = api(sa, "add_alias", guardian_id=g1, alias_name="Aster B.")
check(st == 200 and "Aster B." in api_get(sa, "get", guardian_id=g1)[1]["guardian"]["aliases"], "alias added and visible")
st, j = api(sa, "add_alias", guardian_id=g1, alias_name="")
check(st == 422, "empty alias rejected")

print("== 6. review queue + merge")
st, q = api_get(sa, "list_suggestions")
pend = [x for x in q["suggestions"] if {int(x["guardian_a"]), int(x["guardian_b"])} == {g2, g3}]
check(len(pend) == 1, "review suggestion visible in queue")
sid = int(pend[0]["id"])
st, j = api(sa, "merge_suggestion", suggestion_id=sid)
check(st == 200, "merge via suggestion", str(j))
check(guardian(g3)[3] == "merged" and int(guardian(g3)[4]) == g2, "loser marked merged -> survivor")
check(links_of(m4) == [g2] and primary_of(m4) == g2, "loser's child moved and primary intact")
check(member_legacy(m4)[0] == "Kebede Alemu" and member_legacy(m4)[1] == "0922000001", "moved child's legacy columns now show survivor's contact", str(member_legacy(m4)))
check(guardian(g2)[2] == "0933000002", "survivorship: loser's phone kept as survivor's alt phone (G7)", str(guardian(g2)))
st, j = api(sa, "merge_suggestion", suggestion_id=sid)
check(st == 409, "re-merging a resolved suggestion -> 409")
st, j = api(sa, "link", guardian_id=g3, member_id=m1)
check(st == 422 and "merged" in j.get("message", ""), "linking to a merged guardian refused with guidance (G3)", str(j))
st, j = api(sa, "merge", from_id=g2, into_id=g2)
check(st == 422, "self-merge refused")

print("== 7. re-match sweep (API + CLI) + stale suggestion handling")
st, j = api(sa, "create", display_name="Marta Tesfay", phone_primary="0955111111")  # dup of g5 (phone equal, name typo)
g6 = int(j["id"])
st, j = api(sa, "create", display_name="Kebede Alemu", phone_primary="0977000007")   # name-only dup of g2
g7 = int(j["id"])
cursor, filed, steps = 0, 0, 0
while True:
    st, j = api(sa, "run_rematch", cursor=cursor)
    assert st == 200 and j["status"] == "success", j
    filed += j["filed"]; steps += 1
    if j["done"] or j["next_cursor"] <= cursor:
        break
    cursor = j["next_cursor"]
pairs = {frozenset((int(a), int(b))) for a, b in sql("SELECT guardian_a, guardian_b FROM guardian_link_suggestions WHERE status='pending'")}
check(frozenset((g5, g6)) in pairs, "rematch files phone-equal duplicate (auto band as pending suggestion)")
check(frozenset((g2, g7)) in pairs, "rematch files same-name duplicate (review band)")
check(int(one(f"SELECT COUNT(*) FROM guardian_link_suggestions WHERE status='pending' AND (guardian_a={g3} OR guardian_b={g3})")) == 0, "merged guardians are never re-suggested")
st, j = api(sa, "run_rematch", cursor=0)
check(j["filed"] == 0, "second sweep files nothing new (idempotent)", str(j))
sid2 = int(one(f"SELECT id FROM guardian_link_suggestions WHERE status='pending' AND ((guardian_a={g2} AND guardian_b={g7}) OR (guardian_a={g7} AND guardian_b={g2}))"))
api(sa, "reject_suggestion", suggestion_id=sid2)
st, j = api(sa, "run_rematch", cursor=0)
check(j["filed"] == 0 and one(f"SELECT status FROM guardian_link_suggestions WHERE id={sid2}") == "rejected", "rejected pair stays rejected after re-sweep")
# stale suggestion: merge g6 into g5 directly, then the pending (g5,g6) suggestion must self-close
st, j = api(sa, "merge", from_id=g6, into_id=g5)
check(st == 200, "direct merge")
st, q = api_get(sa, "list_suggestions")
check(not [x for x in q["suggestions"] if {int(x["guardian_a"]), int(x["guardian_b"])} == {g5, g6}], "stale suggestion auto-expired after direct merge (G2)")
check("Marta Tesfay" in api_get(sa, "get", guardian_id=g5)[1]["guardian"]["aliases"], "loser's spelling kept as alias on survivor (G7)")
out = cli("admin/tools/guardian_rematch.php")
check(out.returncode == 0 and "step 1" in out.stdout, "CLI rematch runs", out.stdout[-200:] + out.stderr[-200:])

print("== 8. security gates")
st, j = api(info, "update", guardian_id=g1, display_name="Hacker")
check(st == 403, "info_dept cannot write (403)")
st, j = api_get(info, "get_family", member_id=m1)
check(st == 200, "info_dept can read family")
r = sa.post(f"{BASE}/admin/api_guardians.php", data={"action": "update", "guardian_id": g1, "display_name": "NoCsrf"})
check(r.status_code == 403, "missing CSRF -> 403")
r = requests.get(f"{BASE}/admin/api_guardians.php?action=search&q=Ast")
check(r.status_code == 401, "anonymous -> 401")
st, j = api(sa, "nonsense")
check(st == 400, "unknown action -> 400")
st, j = api_get(sa, "search", q="' OR 1=1 -- ")
check(st == 200 and j["guardians"] == [], "SQL-ish input harmless")
check(guardian(g1)[0] == "Aster Bekele Haile", "guardian untouched by denied requests")

print("== 9. backfill tool on legacy-only members")
sql(f"INSERT INTO members (full_name_am, student_name, father_name, gender, registration_type, member_type, status, membership_tier, guardian_name, guardian_phone1) VALUES ('Legacy One','Legacy','One','male','waiting','regular','active','permanent','Tigist Hailu','0988000008'),('Legacy Two','Legacy','Two','female','waiting','regular','active','permanent','Tigist  Hailu','0988000008')")
dry = cli("admin/tools/guardian_backfill.php", "--dry-run")
check(dry.returncode == 0, "backfill dry-run ok", dry.stderr[-200:])
before = int(one("SELECT COUNT(*) FROM guardians"))
ex = cli("admin/tools/guardian_backfill.php", "--execute")
after = int(one("SELECT COUNT(*) FROM guardians"))
ids = [int(x[0]) for x in sql("SELECT id FROM members WHERE full_name_am LIKE 'Legacy %'")]
check(ex.returncode == 0 and after == before + 1 and primary_of(ids[0]) == primary_of(ids[1]) > 0, "backfill clusters same-phone legacy members under ONE guardian", f"{before}->{after} {ex.stdout[-200:]}")
ex2 = cli("admin/tools/guardian_backfill.php", "--execute")
check(int(one("SELECT COUNT(*) FROM guardians")) == after, "backfill re-run is a no-op")

print("== 10. Family Link workspace API (UI overhaul)")
st, j = api_get(sa, "stats")
j = j.get("stats", j)
check(st == 200 and {"guardians", "families_multi", "members_linked", "members_unlinked", "pending_reviews", "merged", "last_rematch"} <= set(j.keys()), "stats keys", str(j))
check(int(j["guardians"]) == int(one("SELECT COUNT(*) FROM guardians WHERE status='active'")), "stats.guardians = active guardians", str(j["guardians"]))
check(int(j["members_linked"]) == int(one("SELECT COUNT(DISTINCT member_id) FROM member_guardians")), "stats.members_linked = distinct linked members")
check(j["last_rematch"] is not None, "stats.last_rematch stamped after sweeps")
st, j = api_get(sa, "list_guardians", q="", filter="all", page=1)
check(st == 200 and j["total"] >= 5 and all({"children_count", "children_names", "display_name"} <= set(r) for r in j["rows"]), "list_guardians rows carry children summary", str(j.get("total")))
check(all(int(r["status"] == "merged") == 0 for r in j["rows"]), "list_guardians hides merged records")
st, j = api_get(sa, "list_guardians", q="", filter="multi")
check(st == 200 and j["rows"] and all(int(r["children_count"]) >= 2 for r in j["rows"]), "filter=multi -> only 2+ children")
st, j = api_get(sa, "list_guardians", q="0911999", filter="all")
check(st == 200 and [r for r in j["rows"] if int(r["id"]) == g1], "digit search matches by phone prefix")
st, j = api_get(sa, "list_guardians", q="አስቴር", filter="all")
check(st == 200 and [r for r in j["rows"] if int(r["id"]) == g4], "Amharic name search")
st, j = api_get(sa, "list_guardians", q="Aster B.", filter="all")
check(st == 200 and [r for r in j["rows"] if int(r["id"]) == g1], "alias search finds the guardian")
st, j = api_get(sa, "list_guardians", q="", filter="nophone")
check(st == 200 and all(not r["phone_primary"] for r in j["rows"]), "filter=nophone")

# not linked yet + per-row attach + bounded batch
sql("INSERT INTO members (full_name_am, student_name, father_name, gender, registration_type, member_type, status, membership_tier, guardian_name, guardian_phone1) VALUES ('Unlinked One','Unlinked','One','male','waiting','regular','active','permanent','Solomon Girma','0966000001'), ('Unlinked Two','Unlinked','Two','female','waiting','regular','active','permanent','Solomon Girma','0966000001'), ('Unlinked Three','Unlinked','Three','male','waiting','regular','active','permanent','Aster Bekele Haile','0911999999')")
u1, u2, u3 = [int(x[0]) for x in sql("SELECT id FROM members WHERE full_name_am LIKE 'Unlinked %' ORDER BY id")]
st, j = api_get(sa, "unlinked_members")
ids_un = {int(r["id"]) for r in j["rows"]}
check(st == 200 and {u1, u2, u3} <= ids_un and j["total"] >= 3, "unlinked_members lists legacy-only members", str(j.get("total")))
st, j = api(sa, "attach_member", member_id=u3)
check(st == 200 and j.get("mode") in ("auto", "synced") and primary_of(u3) == g1, "attach_member: same phone -> linked to the existing guardian", str(j))
st, j = api(sa, "attach_member", member_id=u1, guardian_id=g2)
check(st == 200 and primary_of(u1) == g2, "attach_member with explicit guardian_id links there", str(j))
sql("INSERT INTO members (full_name_am, student_name, father_name, gender, registration_type, member_type, status, membership_tier, guardian_name, guardian_phone1) VALUES ('Orphan Record','Orphan','Record','male','waiting','regular','active','permanent','','')")
u0 = int(one("SELECT id FROM members WHERE full_name_am='Orphan Record'"))
st, j = api(sa, "attach_member", member_id=u0)
check(st == 422 and links_of(u0) == [], "attach_member on a member with no guardian data -> 422, nothing created", str(j))
before_g = int(one("SELECT COUNT(*) FROM guardians"))
cursor, done, steps, created = 0, False, 0, 0
while not done and steps < 20:
    st, j = api(sa, "attach_batch", cursor=cursor)
    assert st == 200, j
    done, cursor, steps, created = j["done"], j["next_cursor"], steps + 1, created + j["created"]
check(done and primary_of(u2) > 0, "attach_batch (in-browser backfill) links the remaining legacy member", str(j))
check(int(one("SELECT COUNT(*) FROM guardians")) == before_g + 1 and created == 1, "attach_batch created exactly one guardian (Solomon Girma)")
st, j = api_get(sa, "unlinked_members")
check(st == 200 and not ({u1, u2, u3} & {int(r["id"]) for r in j["rows"]}), "unlinked list empties after linking")
st, j = api(sa, "attach_batch", cursor=0)
check(st == 200 and j["done"] and j["created"] == 0 and j["matched"] == 0, "attach_batch re-run is a no-op")

# relation / primary
st, j = api(sa, "set_relation", guardian_id=g1, member_id=u3, relation="mother")
check(st == 200 and one(f"SELECT relation FROM member_guardians WHERE guardian_id={g1} AND member_id={u3}") == "mother", "set_relation updates relation")
st, j = api(sa, "set_relation", guardian_id=g1, member_id=u3, is_primary=0)
check(st == 422, "demoting the only primary guardian is refused (422)", str(j))
st, j = api(sa, "link", guardian_id=g2, member_id=u3, relation="father")
st, j = api(sa, "set_relation", guardian_id=g2, member_id=u3, is_primary=1)
check(st == 200 and primary_of(u3) == g2 and member_legacy(u3)[0] == "Kebede Alemu", "set_relation is_primary=1 moves primary + legacy mirror follows", str(member_legacy(u3)))
st, j = api(sa, "set_relation", guardian_id=g2, member_id=u3, relation="uncle")
check(one(f"SELECT relation FROM member_guardians WHERE guardian_id={g2} AND member_id={u3}") == "father", "unknown relation value ignored (ENUM stays valid)", str(j))

# alias removal, keep=b merge, activity
st, j = api(sa, "add_alias", guardian_id=g2, alias_name="K. Alemu")
st, j = api(sa, "remove_alias", guardian_id=g2, alias_name="K. Alemu")
check(st == 200 and "K. Alemu" not in api_get(sa, "get", guardian_id=g2)[1]["guardian"]["aliases"], "remove_alias", str(j))
st, j = api(sa, "create", display_name="Solomon Girma T.", phone_primary="0966000001")
g8 = int(j["id"])
api(sa, "run_rematch", cursor=0)
sid3 = one(f"SELECT id FROM guardian_link_suggestions WHERE status='pending' AND (guardian_a={g8} OR guardian_b={g8}) ORDER BY id DESC LIMIT 1")
check(sid3 not in (None, "", "NULL"), "re-match files the new phone-equal duplicate")
st, q = api_get(sa, "list_suggestions")
row = [x for x in q["suggestions"] if int(x["id"]) == int(sid3)][0]
check(isinstance(row.get("guardian_a_row", {}).get("children"), list) and isinstance(row.get("guardian_b_row", {}).get("children"), list), "list_suggestions carries children for side-by-side compare")
st, j = api(sa, "merge_suggestion", suggestion_id=int(sid3), keep="b")
kept = int(row["guardian_b"]); lost = int(row["guardian_a"])
check(st == 200 and guardian(lost)[3] == "merged" and int(guardian(lost)[4]) == kept, "merge_suggestion keep=b keeps record B", str(j))
st, j = api_get(sa, "activity")
check(st == 200 and j["rows"] and any("Guardian" in (r["action"] or "") for r in j["rows"]) and {"username", "action", "created_at"} <= set(j["rows"][0]), "activity feed lists guardian audit entries")
check(any("Backfill" in (r["action"] or "") for r in j["rows"]), "attach_batch is audited as Guardian Backfill")
for a in ("attach_member", "attach_batch", "set_relation", "remove_alias"):
    st, j = api(info, a, member_id=u3, guardian_id=g2, cursor=0, alias_name="x")
    check(st == 403, f"info_dept cannot {a} (403)")
for a in ("stats", "list_guardians", "unlinked_members", "activity"):
    st, j = api_get(info, a)
    check(st == 200, f"info_dept can read {a}")

print(f"\n{PASS} passed, {FAIL} failed")
sys.exit(1 if FAIL else 0)
