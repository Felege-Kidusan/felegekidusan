"""Live API test for danger-confirmation DC-3 (Super Admin + HR + Information).
Runs against a LOCAL test server only (default http://127.0.0.1:8080, seeded by
tests/e2e/provision_env.sh). Never point this at production.

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/danger_confirm_dc3_api.py [base_url]

Covers the server-side typed-phrase checks added in DC-3:
  users.delete (form post + CSRF, F9), app_release.force_update (only when the
  force flag turns on or the minimum changes), app_release.delete_apk,
  members.import (live import only; "Check file" dry run stays free),
  guardians.link_all (every attach_batch step), identity.renumber (execute
  only; the preview stays free).
Identity renumber and APK delete are only checked for the refusal; they are
never executed here.
"""
import io, os, re, sys, uuid, time, subprocess
import requests

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
PW = 'AuditTest#2026'
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
PASS = FAIL = 0


def ck(name, cond, info=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS', name)
    else:
        FAIL += 1; print('FAIL', name, '::', str(info)[:300])


def sql(q):
    out = subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '--default-character-set=utf8mb4', '-N', '-B', '-e', q],
                         capture_output=True, text=True)
    return out.stdout.strip()


def login(user, page):
    s = requests.Session()
    h = s.get(BASE + '/admin/index.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    s.post(BASE + '/admin/backend/login.php', data={'username': user, 'password': PW, 'csrf_token': tok}, allow_redirects=False)
    h = s.get(BASE + page).text
    m = re.search(r"CSRF(?:_TOKEN)?\s*=\s*'([a-f0-9]{20,})'", h) or re.search(r'name="csrf-token" content="([a-f0-9]{20,})"', h) \
        or re.search(r'name="csrf_token" value="([a-f0-9]{20,})"', h)
    s.csrf = m.group(1) if m else ''
    return s


def post(s, url, files=None, **data):
    data.setdefault('csrf_token', s.csrf)
    r = s.post(BASE + url, data=data, files=files, allow_redirects=False)
    try:
        return r.status_code, r.json(), r
    except Exception:
        return r.status_code, {'raw': r.text[:200]}, r


def refused(code, d):
    return code == 409 and d.get('code') == 'confirm_phrase_required' and d.get('success') is False


sa = login('audit_super', '/admin/dashboards/super-admin.php')
ck('super admin login + csrf', bool(sa.csrf))

# ── users.delete (F9: the form now carries CSRF; server needs DELETE USER) ──
name = 'dc3_del_' + str(int(time.time()))
sql(f"INSERT INTO users (username, full_name, role, password_hash, is_active) VALUES ('{name}', 'DC3 delete target', 'teacher', 'x', 1)")
uid = sql(f"SELECT id FROM users WHERE username='{name}'")
ck('throwaway user created', uid.isdigit(), uid)
code, d, r = post(sa, '/admin/backend/user-delete.php', delete_user_id=uid, superadmin_password=PW)
ck('delete user without phrase -> redirect with error, user kept',
   code == 302 and 'DELETE+USER' in r.headers.get('Location', '') and sql(f"SELECT COUNT(*) FROM users WHERE id={uid}") == '1', (code, r.headers.get('Location')))
code, d, r = post(sa, '/admin/backend/user-delete.php', delete_user_id=uid, superadmin_password=PW, confirm_phrase='delete')
ck('delete user with wrong phrase -> user kept', sql(f"SELECT COUNT(*) FROM users WHERE id={uid}") == '1', r.headers.get('Location'))
code, d, r = post(sa, '/admin/backend/user-delete.php', delete_user_id=uid, superadmin_password=PW, confirm_phrase='DELETE USER', csrf_token='bad')
ck('delete user with bad CSRF -> refused, user kept', code in (302, 403) and sql(f"SELECT COUNT(*) FROM users WHERE id={uid}") == '1', code)
code, d, r = post(sa, '/admin/backend/user-delete.php', delete_user_id=uid, superadmin_password=PW, confirm_phrase='  delete   user ')
ck('delete user with phrase (case/space tolerant) + CSRF + password -> deleted',
   code == 302 and 'success=' in r.headers.get('Location', '') and sql(f"SELECT COUNT(*) FROM users WHERE id={uid}") == '0', r.headers.get('Location'))

# ── app_release.force_update / delete_apk ──
REL = '/admin/api_app_release.php'
cfg_file = os.path.join(ROOT, 'api', 'v1', 'app_release.local.php')
cfg_backup = open(cfg_file, 'rb').read() if os.path.exists(cfg_file) else None
try:
    rr = sa.get(BASE + REL + '?action=get_release').json()
    cur = rr.get('data') or {}
    ck('get_release works', rr.get('status') == 'success', rr)
    base = dict(action='save_config', latest_version=cur.get('latest_version', '1.0.0'), latest_build=cur.get('latest_build', 1),
                min_version=cur.get('min_version', '1.0.0'), min_build=cur.get('min_build', 1),
                background_drains_enabled=1, release_notes=cur.get('release_notes', '') or '', banner_text='DC3 banner ' + str(int(time.time())),
                banner_kind='info')
    if cur.get('force_update'):
        base['force_update'] = 1
    code, d, _ = post(sa, REL, **base)
    ck('save_config with only a banner change -> no phrase needed', code == 200 and d.get('status') == 'success', (code, d))
    rr = sa.get(BASE + REL + '?action=get_release').json(); cur = rr.get('data') or {}
    bumped = dict(base, min_build=int(cur.get('min_build') or 1) + 1)
    code, d, _ = post(sa, REL, **bumped)
    ck('save_config raising min_build without phrase -> 409', refused(code, d), (code, d))
    if not cur.get('force_update'):
        code, d, _ = post(sa, REL, **dict(base, force_update=1))
        ck('save_config turning force update ON without phrase -> 409', refused(code, d), (code, d))
    code, d, _ = post(sa, REL, **dict(bumped, confirm_phrase='FORCE UPDATE'))
    ck('save_config raising min_build with FORCE UPDATE -> saved', code == 200 and d.get('status') == 'success', (code, d))
    code, d, _ = post(sa, REL, action='delete_apk', abi='universal')
    ck('delete_apk without phrase -> 409', refused(code, d), (code, d))
    code, d, _ = post(sa, REL, action='delete_apk', abi='universal', confirm_phrase='DELETE')
    ck('delete_apk with wrong phrase -> 409', refused(code, d), (code, d))
finally:
    if cfg_backup is not None:
        open(cfg_file, 'wb').write(cfg_backup)
    elif os.path.exists(cfg_file):
        os.remove(cfg_file)

# ── identity.renumber (execute only) ──
code, d, _ = post(sa, '/admin/api_identity_migration.php', mode='execute')
ck('identity renumber execute without phrase -> 409', refused(code, d), (code, d))
code, d, _ = post(sa, '/admin/api_identity_migration.php', mode='execute', confirm_phrase='RENUMBER')
ck('identity renumber execute with wrong phrase -> 409', refused(code, d), (code, d))
code, d, _ = post(sa, '/admin/api_identity_migration.php', mode='dry_run')
ck('identity renumber preview (dry run) -> not blocked by the phrase', code != 409, (code, d))

# ── members.import (HR) ──
hr = login('audit_hr', '/admin/dashboards/hr-dept.php')
ck('hr login + csrf', bool(hr.csrf))
import openpyxl
wb = openpyxl.Workbook(); ws = wb.active
ws.append(['N_o', 'Full Name']); ws.append([1, 'DC3 Test'])
buf = io.BytesIO(); wb.save(buf); xlsx = buf.getvalue()
before = sql('SELECT COUNT(*) FROM members')


def imp(**extra):
    return post(hr, '/admin/api_import_members.php', files={'import_file': ('m.xlsx', xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')},
                tier='permanent', **extra)


code, d, _ = imp(dry_run='1')
ck('import "Check file" (dry run) -> not blocked by the phrase', code != 409, (code, d))
code, d, _ = imp(dry_run='0')
ck('live import without phrase -> 409', refused(code, d), (code, d))
code, d, _ = imp(dry_run='0', confirm_phrase='IMPORT')
ck('live import with wrong phrase -> 409', refused(code, d), (code, d))
code, d, _ = imp(dry_run='0', confirm_phrase='IMPORT MEMBERS')
ck('live import with IMPORT MEMBERS -> passes the phrase gate (file then validated)', code != 409, (code, d))
ck('no members written by refused imports', sql('SELECT COUNT(*) FROM members') == before or code != 409, (before, sql('SELECT COUNT(*) FROM members')))

# ── guardians.link_all (attach_batch, every step) ──
code, d, _ = post(hr, '/admin/api_guardians.php', action='attach_batch', cursor=0)
ck('attach_batch without phrase -> 409', refused(code, d), (code, d))
code, d, _ = post(hr, '/admin/api_guardians.php', action='attach_batch', cursor=5)
ck('attach_batch later step without phrase -> 409', refused(code, d), (code, d))
code, d, _ = post(hr, '/admin/api_guardians.php', action='attach_batch', cursor=0, confirm_phrase='LINK ALL')
ck('attach_batch with LINK ALL -> runs', code == 200 and d.get('success', d.get('status') == 'success') not in (False,), (code, d))
info = login('audit_info', '/admin/dashboards/info-dept.php')
code, d, _ = post(info, '/admin/api_guardians.php', action='attach_batch', cursor=0, confirm_phrase='LINK ALL')
ck('Information cannot Link all even with the phrase (403)', code == 403, (code, d))

print(f'\nDC-3 API: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
