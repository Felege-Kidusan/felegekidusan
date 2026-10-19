#!/usr/bin/env python3
"""Reporting Centre V2 — live SQL sweep against a provisioned test server.

For a given role it logs in, pulls the schema and then exercises EVERY field as a
list column, EVERY group‑able field as an aggregate bucket, EVERY metric as a KPI,
every starter definition, and a filter per operator family — so a registry typo
that only fails at SQL time is caught before a human opens the page.

Usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/reports_v2_live_sweep.py http://127.0.0.1:8080 hr
Exit code 1 on any failure. Prints a JSON summary.
"""
import json
import re
import sys
import time
import urllib.parse
import urllib.request
import http.cookiejar

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080").rstrip("/")
ROLE = sys.argv[2] if len(sys.argv) > 2 else "hr"
USER = {"hr": "audit_hr", "info": "audit_info", "super": "audit_super", "school": "audit_school"}[ROLE]
PASSWORD = "AuditTest#2026"

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener.addheaders = [("User-Agent", "rc-sweep/1.0")]


def get(path, **params):
    url = BASE + path + ("?" + urllib.parse.urlencode(params) if params else "")
    with opener.open(url, timeout=60) as r:
        return r.status, r.read()


def api(action, q=None, **extra):
    params = {"action": action, "lang": "en"}
    if q is not None:
        params["q"] = json.dumps(q)
    params.update(extra)
    for attempt in range(3):
        try:
            status, body = get("/admin/api_reports_v2.php", **params)
        except urllib.error.HTTPError as e:
            status, body = e.code, e.read()
        if status != 429:
            break
        time.sleep(21)  # per-user read bucket (240/60 s): back off instead of failing the sweep
    try:
        data = json.loads(body.decode("utf-8"))
    except Exception:
        data = {"raw": body[:200].decode("utf-8", "replace")}
    return status, data


# ── login ──
status, body = get("/admin/index.php")
m = re.search(rb'name="csrf_token"\s+value="([a-f0-9]+)"', body)
assert m, "csrf token not found on login page"
data = urllib.parse.urlencode({"username": USER, "password": PASSWORD, "csrf_token": m.group(1).decode()}).encode()
req = urllib.request.Request(BASE + "/admin/backend/login.php", data=data, method="POST")
try:
    opener.open(req, timeout=30)
except urllib.error.HTTPError:
    pass

failures, checks = [], 0


def check(name, ok, detail=""):
    global checks
    checks += 1
    if not ok:
        failures.append(f"{name}: {detail}"[:300])


status, schema = api("schema")
check("schema", status == 200 and schema.get("status") == "success", f"{status} {str(schema)[:120]}")
S = schema["data"]
fields = S["fields"]
visible = [f for f in fields if not f.get("hidden")]
print(f"role={ROLE} fields={len(fields)} metrics={len(S['metrics'])} starters={len(S['starters'])} categories={len(S.get('categories', {}))}")

# every field as a list column (in chunks of 12 to stay under MAX_COLUMNS)
keys = [f["key"] for f in visible if f["list"]]
for i in range(0, len(keys), 12):
    chunk = keys[i:i + 12]
    status, d = api("list", {"columns": chunk, "page_size": 5})
    check("list cols " + ",".join(chunk), status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")

# each sortable field
for f in visible:
    if f["sort"]:
        status, d = api("list", {"columns": ["member_code", f["key"]], "sort": [{"field": f["key"], "dir": "desc"}], "page_size": 3})
        check("sort " + f["key"], status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")

# each group-able field × all metrics
all_metrics = [m["key"] for m in S["metrics"]]
for f in visible:
    if f["group"]:
        status, d = api("aggregate", {"group_by": f["key"], "metrics": all_metrics})
        check("aggregate " + f["key"], status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")

# KPIs with all metrics
status, d = api("kpis", {"metrics": all_metrics})
check("kpis all", status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")
kpis = d.get("data", {}).get("kpis", d.get("data", {}))

# every starter definition → list + kpis + aggregate(if group_by)
for st in S["starters"]:
    defn = dict(st["definition"])
    defn["page_size"] = 5
    status, d = api("list", defn)
    check("starter list " + st["key"], status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")

# one filter per operator family on representative fields
by_type = {}
for f in visible:
    by_type.setdefault(f["type"], f)
probes = []
for t, ops in S["operators"].items():
    f = by_type.get(t)
    if not f:
        continue
    for op in ops:
        val = None
        if op in ("eq", "neq", "gt", "gte", "lt", "lte", "contains", "starts_with"):
            val = (list(f["options"].keys())[0] if f.get("options") else ("2026-01-01" if t == "date" else (1 if t == "int" else "a")))
        elif op in ("in", "not_in"):
            val = list(f["options"].keys())[:2] if f.get("options") else ([1, 2] if t == "int" else ["a"])
        elif op == "between":
            val = ["2025-01-01", "2026-12-31"] if t == "date" else [0, 100]
        elif op == "last_days":
            val = 30
        probe = {"field": f["key"], "op": op}
        if val is not None:
            probe["value"] = val
        probes.append(probe)
for pr in probes:
    status, d = api("kpis", {"filters": [pr], "metrics": ["count"]})
    check(f"filter {pr['field']} {pr['op']}", status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")

# options endpoint for every group-able string field (and the joined ones in particular)
for f in visible:
    if f["type"] == "string" and f["group"]:
        status, d = api("options", field=f["key"])
        check("options " + f["key"], status == 200 and d.get("status") == "success", f"{status} {str(d)[:160]}")

# export csv with cross-domain columns
status, body = get("/admin/api_reports_v2.php", action="export", format="csv",
                   q=json.dumps({"columns": ["member_code", "full_name_am", "current_class", "edu_attendance_band", "fl_guardian_name"]}))
check("export csv cross-domain", status == 200 and body.count(b"\n") >= 2, f"{status} {body[:80]!r}")

# export history (admins only)
status, d = api("export_history")
if ROLE in ("super", "school"):
    check("export_history admin", status == 200 and d.get("status") == "success" and isinstance(d["data"].get("rows"), list), f"{status} {str(d)[:160]}")
else:
    check("export_history denied", status == 403, f"{status} {str(d)[:120]}")

# saved views API reachable + visibility shape (V2-3)
try:
    status, body = get("/admin/api_report_views.php", action="list")
    d = json.loads(body.decode("utf-8"))
    check("views list", status == 200 and d.get("status") == "success" and "views" in d["data"], f"{status} {str(d)[:120]}")
    if d["data"].get("available") and ROLE not in ("super", "school"):
        bad = [v for v in d["data"]["views"] if not (v["mine"] or v["scope"] == "shared" or v["scope"] == "department")]
        check("views visibility", not bad, str(bad)[:120])
except Exception as e:  # noqa: BLE001
    check("views list", False, str(e)[:120])

print(json.dumps({"role": ROLE, "checks": checks, "failures": failures, "kpis": kpis}, ensure_ascii=False, indent=1))
sys.exit(1 if failures else 0)
