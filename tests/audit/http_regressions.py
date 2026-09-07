#!/usr/bin/env python3
"""Local-only integration regressions for the production audit patch.

Requires Apache/PHP, the synthetic `ssms` database, and SSMS_AUDIT_TESTING=1.
Never points at a production host. Tests intentionally write synthetic records
and inject local database failures to verify rollback. See README.md.
"""
import concurrent.futures
import http.cookiejar
import json
import os
import re
import subprocess
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get('SSMS_AUDIT_BASE', 'http://127.0.0.1:8081').rstrip('/')
ROLES = ['super_admin','school_admin','info_dept','hr_dept','edu_dept','finance_dept',
         'material_dept','mezmur_dept','teacher','attendance_taker','mezmur_attendance_taker','hr_attendance_taker','content_editor']


def ensure_local():
    if os.environ.get('SSMS_AUDIT_TESTING') != '1' or urllib.parse.urlsplit(BASE).hostname not in ('127.0.0.1', 'localhost'):
        raise RuntimeError('Only opt-in localhost synthetic testing is allowed.')


def fixture(payload):
    ensure_local()
    result = subprocess.run(['php', str(ROOT / 'tests/audit/db_fixture.php'), json.dumps(payload)],
                            cwd=ROOT, capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


def sql(query, params=None):
    return fixture({'op': 'sql', 'sql': query, 'params': params or []})


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.csrf = ''

    def request(self, path, data=None, method=None, headers=None, follow=False):
        if path.startswith(BASE):
            path = path[len(BASE):]
        if not path.startswith('/') or path.startswith('//'):
            raise RuntimeError('Refusing non-local URL: ' + path)
        if data is not None and (headers or {}).get('Content-Type') == 'application/json':
            body = json.dumps(data).encode()
        else:
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        req = urllib.request.Request(BASE + path, data=body, method=method,
            headers=headers or {'Accept': 'application/json'})
        try:
            response = self.opener.open(req, timeout=8)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read()
        result = {'status': response.code, 'headers': dict(response.headers.items()),
                  'body': raw.decode('utf-8', errors='replace'), 'raw':raw, 'path': path}
        try:
            result['json'] = json.loads(result['body'])
        except (ValueError, UnicodeDecodeError):
            result['json'] = None
        if follow and response.code in (301,302,303,307,308):
            target = urllib.parse.urljoin(BASE + path, response.headers['Location'])
            if not target.startswith(BASE + '/'):
                raise RuntimeError('External redirect blocked: ' + target)
            return self.request(target, headers=headers, follow=True)
        return result

    def login(self, role, endpoint='/admin/backend/login.php'):
        page = self.request('/admin/index.php', headers={'Accept':'text/html'})
        token = re.search(r'name="csrf_token"\s+value="([a-f0-9]+)"', page['body'])
        if not token:
            raise RuntimeError('Login CSRF missing: ' + page['body'][:180])
        response = self.request(endpoint, {'username':'audit_' + role, 'password':'AuditTest#2026', 'csrf_token':token[1]})
        if response['status'] != 200 or (response['json'] or {}).get('status') != 'success':
            raise RuntimeError('Login failed: ' + str(response))
        page = self.request('/admin/dashboard.php', headers={'Accept':'text/html'}, follow=True)
        patterns = [r'name="csrf-token"\s+content="([a-f0-9]+)"',
                    r'name="csrf_token"\s+value="([a-f0-9]+)"',
                    r'(?:CSRF_TOKEN|csrf_token|csrfToken|csrf)[\'\"]?\s*[:=]\s*[\'\"]([a-f0-9]{64})']
        for pattern in patterns:
            match = re.search(pattern, page['body'])
            if match:
                self.csrf = match[1]
                break
        if not self.csrf:
            # Read-only department-taker landing pages do not need a browser CSRF field.
            self.csrf = fixture({'op':'session','id':self.session_id(),'values':{}})['csrf']
        return page

    def post(self, path, data, csrf=True):
        return self.request(path, dict(data, **({'csrf_token':self.csrf} if csrf else {})))

    def session_id(self):
        return urllib.parse.unquote(next(cookie.value for cookie in self.jar if cookie.name == 'PHPSESSID'))


class AuditHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        ensure_local()
        fixture({'op':'seed_roles'})
        sql('DELETE FROM security_rate_limits')
        cls.clients = {}
        cls.pages = {}
        for role in ROLES:
            client = Client()
            cls.pages[role] = client.login(role)
            cls.clients[role] = client
        cls.class_one = int(sql("SELECT id FROM classes WHERE class_code='grade_1' AND is_active=1")['rows'][0]['id'])
        cls.class_two = int(sql("SELECT id FROM classes WHERE class_code='grade_2' AND is_active=1")['rows'][0]['id'])
        cls.income = int(sql("SELECT id FROM finance_categories WHERE type='income' ORDER BY id LIMIT 1")['rows'][0]['id'])
        cls.expense = int(sql("SELECT id FROM finance_categories WHERE type='expense' ORDER BY id LIMIT 1")['rows'][0]['id'])
        cls.material_category = int(sql('SELECT id FROM material_categories ORDER BY id LIMIT 1')['rows'][0]['id'])

    def good(self, response):
        self.assertEqual(response['status'], 200, response['body'][:500])
        self.assertEqual((response['json'] or {}).get('status'), 'success', response['body'][:500])
        return response['json']

    def test_01_all_roles_render(self):
        for role, page in self.pages.items():
            with self.subTest(role=role):
                self.assertEqual(page['status'], 200, page['body'][:400])
                self.assertIn('<html', page['body'].lower())
                self.assertNotRegex(page['body'], r'(?i)(<b>Fatal error</b>|<b>Warning</b>|PHP Fatal error:|PHP Warning:)')

    def test_02_anonymous_routes_fail_closed(self):
        client = Client()
        for path in ['/admin/api_finance.php?action=dashboard','/backend/api/finance.php?action=dashboard',
                     '/admin/api_material.php?action=items','/backend/api/mezmur.php?action=stats',
                     '/admin/api_identity.php?action=list_positions','/admin/api_notifications.php?action=changes']:
            with self.subTest(path=path):
                response = client.request(path)
                self.assertEqual(response['status'], 401, response['body'][:300])
                self.assertIsInstance(response['json'], dict)

    def test_03_cross_department_matrix(self):
        endpoints = {
            '/admin/api_finance.php?action=dashboard': {'finance_dept','super_admin','school_admin'},
            '/backend/api/finance.php?action=dashboard': {'finance_dept','super_admin','school_admin'},
            '/admin/api_material.php?action=items': {'material_dept','super_admin','school_admin'},
            '/backend/api/mezmur.php?action=stats': {'mezmur_dept','super_admin','school_admin'},
            '/admin/api_identity.php?action=list_positions': {'super_admin'},
            '/admin/api_info_analytics.php?action=kpi': {'info_dept','super_admin','school_admin'},
        }
        for path, allowed in endpoints.items():
            for role, client in self.clients.items():
                with self.subTest(role=role,path=path):
                    response = client.request(path)
                    if role in allowed:
                        self.good(response)
                    else:
                        self.assertEqual(response['status'], 403, response['body'][:300])

    def test_04_write_actions_reject_get(self):
        endpoints = {
            '/admin/api_finance.php': ['add_transaction','update_transaction','delete_transaction','save_category','save_fee'],
            '/admin/api_material.php': ['save_item','delete_item','add_transaction','save_category','save_request','update_request'],
            '/admin/api_notifications.php': ['mark_read','mark_all_read','task_update','sync_change'],
            '/admin/api_year_context.php': ['set','clear'],
            '/admin/api_education.php': ['sync_member_types','save_class','delete_class','set_current_year'],
            '/admin/api_subjects.php': ['create_subject','delete_subject','save_grades'],
            '/admin/api_teachers.php': ['toggle_status','delete_teacher','save_teacher_bundle'],
        }
        for path, actions in endpoints.items():
            for action in actions:
                with self.subTest(path=path,action=action):
                    response = self.clients['super_admin'].request(path + '?action=' + action)
                    self.assertEqual(response['status'], 405, response['body'][:300])
                    self.assertEqual(response['headers'].get('Allow'), 'POST')

    def test_05_csrf_missing_and_array_are_denied_not_fatal(self):
        for path in ['/admin/api_finance.php','/admin/api_material.php','/admin/api_notifications.php','/admin/api_year_context.php']:
            for data in ({'action':'save_category'}, {'action':'save_category','csrf_token[]':'bad'}):
                if path.endswith('api_year_context.php'):
                    data['action'] = 'clear'
                with self.subTest(path=path,data=data):
                    response = self.clients['super_admin'].post(path,data,csrf=False)
                    self.assertEqual(response['status'],403,response['body'][:300])
                    self.assertIsInstance(response['json'],dict)

    def test_06_finance_date_and_money_roundtrip(self):
        client = self.clients['finance_dept']
        response = client.post('/backend/api/finance.php', {'action':'add_transaction','type':'income',
            'category_id':self.income,'amount':'123.45','description':'audit-date-roundtrip',
            'transaction_date':'2026-08-22','payment_method':'cash','ec_month':12,'ec_year':2018})
        result = self.good(response)
        row = sql('SELECT amount,transaction_date,ec_month,ec_year FROM finance_transactions WHERE id=?',[result['id']])['rows'][0]
        self.assertEqual(str(row['amount']),'123.45')
        self.assertEqual(row['transaction_date'],'2026-08-22')
        self.assertEqual(int(row['ec_month']),12)
        self.good(client.post('/admin/api_finance.php', {'action':'delete_transaction','id':result['id']}))

    def test_07_finance_invalid_values_and_reference(self):
        base = {'action':'add_transaction','type':'income','category_id':self.income,'amount':'10.00','transaction_date':'2026-08-22'}
        for change in [{'amount':'-1'}, {'amount':'NaN'}, {'amount':'1.234'}, {'amount':'1e99'},
                       {'transaction_date':'2026-02-30'}, {'category_id':self.expense}, {'member_id':2147483647}]:
            with self.subTest(change=change):
                response = self.clients['finance_dept'].post('/admin/api_finance.php',dict(base,**change))
                self.assertEqual(response['status'],422,response['body'][:300])

    def test_08_fee_posts_matching_historical_income(self):
        before = sql('SELECT COALESCE(MAX(id),0) id FROM finance_transactions')['rows'][0]['id']
        result = self.good(self.clients['finance_dept'].post('/admin/api_finance.php',
            {'action':'save_fee','member_id':900000,'amount':'25.75','ec_month':12,'ec_year':2018,
             'paid_date':'2026-08-18','status':'paid','fee_type':'monthly'}))
        rows = sql('SELECT amount,transaction_date,member_id,type FROM finance_transactions WHERE id>?',[before])['rows']
        self.assertEqual(len(rows),1)
        self.assertEqual(rows[0]['transaction_date'],'2026-08-18')
        self.assertEqual(str(rows[0]['amount']),'25.75')
        fee = sql('SELECT ec_year,ec_month,paid_date FROM finance_member_fees WHERE id=?',[result['id']])['rows'][0]
        self.assertEqual(int(fee['ec_year']),2018)
        self.assertEqual(fee['paid_date'],'2026-08-18')

    def test_09_fee_rolls_back_when_income_write_fails(self):
        before = sql('SELECT COUNT(*) n FROM finance_member_fees')['rows'][0]['n']
        sql("CREATE TRIGGER audit_fail_finance BEFORE INSERT ON finance_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AUDIT injected ledger failure'")
        try:
            response = self.clients['finance_dept'].post('/admin/api_finance.php',
                {'action':'save_fee','member_id':900000,'amount':'31.00','ec_month':12,'ec_year':2018,'status':'paid'})
            self.assertEqual(response['status'],500,response['body'][:300])
            self.assertNotIn('AUDIT injected',response['body'])
            self.assertEqual(sql('SELECT COUNT(*) n FROM finance_member_fees')['rows'][0]['n'],before)
        finally:
            sql('DROP TRIGGER IF EXISTS audit_fail_finance')

    def material_item(self, quantity=5):
        result = self.good(self.clients['material_dept'].post('/admin/api_material.php',
            {'action':'save_item','name':'Audit inventory ' + str(time.time_ns()),'category_id':self.material_category,
             'quantity':quantity,'min_quantity':2,'unit':'piece','condition_status':'good','purchase_date':'2026-08-22','purchase_price':'10.50'}))
        return int(result['id'])

    def test_10_material_unit_and_edit_roundtrip(self):
        item = self.material_item()
        row = sql('SELECT unit,quantity,min_quantity,purchase_date,purchase_price FROM material_items WHERE id=?',[item])['rows'][0]
        self.assertEqual(row['unit'],'piece')
        self.assertEqual(int(row['quantity']),5)
        self.assertEqual(row['purchase_date'],'2026-08-22')
        self.good(self.clients['material_dept'].post('/admin/api_material.php',
            {'action':'save_item','id':item,'name':'Audit edited','quantity':6,'min_quantity':2,'unit':'box','condition_status':'fair'}))
        self.assertEqual(sql('SELECT unit FROM material_items WHERE id=?',[item])['rows'][0]['unit'],'box')
        self.good(self.clients['material_dept'].post('/admin/api_material.php',{'action':'delete_item','id':item}))

    def test_11_outgoing_never_overdraws_or_writes_phantom_history(self):
        item = self.material_item()
        client = self.clients['material_dept']
        response = client.post('/admin/api_material.php',{'action':'add_transaction','item_id':item,'type':'outgoing','quantity':6})
        self.assertEqual(response['status'],409,response['body'][:300])
        self.assertEqual(int(sql('SELECT quantity FROM material_items WHERE id=?',[item])['rows'][0]['quantity']),5)
        self.assertEqual(int(sql('SELECT COUNT(*) n FROM material_transactions WHERE item_id=?',[item])['rows'][0]['n']),0)
        self.good(client.post('/admin/api_material.php',{'action':'add_transaction','item_id':item,'type':'outgoing','quantity':3}))
        row=sql('SELECT quantity,status FROM material_items WHERE id=?',[item])['rows'][0]
        self.assertEqual(int(row['quantity']),2)
        self.assertEqual(row['status'],'low_stock')
        response=client.post('/admin/api_material.php',{'action':'delete_item','id':item})
        self.assertEqual(response['status'],409)

    def test_12_material_concurrent_spending_serializes(self):
        item = self.material_item()
        def spend(role):
            return self.clients[role].post('/admin/api_material.php',
                {'action':'add_transaction','item_id':item,'type':'outgoing','quantity':4})['status']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            statuses = sorted(pool.map(spend,['material_dept','school_admin']))
        self.assertEqual(statuses,[200,409])
        self.assertEqual(int(sql('SELECT quantity FROM material_items WHERE id=?',[item])['rows'][0]['quantity']),1)
        self.assertEqual(int(sql('SELECT COUNT(*) n FROM material_transactions WHERE item_id=?',[item])['rows'][0]['n']),1)

    def test_13_adjustment_sets_zero_balance(self):
        item = self.material_item()
        self.good(self.clients['material_dept'].post('/admin/api_material.php',
            {'action':'add_transaction','item_id':item,'type':'adjustment','quantity':0,'reason':'Audit counted stock'}))
        row = sql('SELECT quantity,status FROM material_items WHERE id=?',[item])['rows'][0]
        self.assertEqual(int(row['quantity']),0)
        self.assertEqual(row['status'],'out_of_stock')

    def test_14_stock_rollback_on_log_failure(self):
        item = self.material_item()
        sql("CREATE TRIGGER audit_fail_stock BEFORE INSERT ON material_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AUDIT injected movement failure'")
        try:
            response = self.clients['material_dept'].post('/admin/api_material.php',
                {'action':'add_transaction','item_id':item,'type':'incoming','quantity':9})
            self.assertEqual(response['status'],500,response['body'][:300])
            self.assertEqual(int(sql('SELECT quantity FROM material_items WHERE id=?',[item])['rows'][0]['quantity']),5)
        finally:
            sql('DROP TRIGGER IF EXISTS audit_fail_stock')

    def test_15_identity_position_create_and_edit_preserve_flag(self):
        role_code='ZQ'
        # A free code not reserved by the identity scheme.
        existing = sql('SELECT id FROM staff_positions WHERE role_code=? AND department_id IS NULL',[role_code])['rows']
        if existing:
            sql('DELETE FROM staff_positions WHERE id=?',[existing[0]['id']])
        data={'action':'save_position','role_code':role_code,'title_am':'የሙከራ ኃላፊ','title_en':'Audit position','legacy_flag':'is_staff','is_active':'1'}
        response=self.clients['super_admin'].post('/admin/api_identity.php',data)
        self.good(response)
        row=sql('SELECT id,legacy_flag FROM staff_positions WHERE role_code=? AND department_id IS NULL',[role_code])['rows'][0]
        self.assertEqual(row['legacy_flag'],'is_staff')
        self.good(self.clients['super_admin'].post('/admin/api_identity.php',dict(data,id=row['id'],title_en='Audit updated',legacy_flag='is_volunteer')))
        row2=sql('SELECT title_en,legacy_flag FROM staff_positions WHERE id=?',[row['id']])['rows'][0]
        self.assertEqual(row2['title_en'],'Audit updated')
        self.assertEqual(row2['legacy_flag'],'is_volunteer')
        sql('DELETE FROM staff_positions WHERE id=?',[row['id']])

    def test_16_notification_and_task_updates_are_recipient_scoped(self):
        note = sql("INSERT INTO notifications (type,title,message,target_roles) VALUES ('general','Audit private alert','Synthetic only','finance_dept')")['id']
        response = self.clients['teacher'].post('/admin/api_notifications.php',{'action':'mark_read','id':note})
        self.assertNotEqual((response['json'] or {}).get('status'),'success')
        self.assertEqual(int(sql('SELECT is_read FROM notifications WHERE id=?',[note])['rows'][0]['is_read']),0)
        self.good(self.clients['finance_dept'].post('/admin/api_notifications.php',{'action':'mark_read','id':note}))
        task = sql("INSERT INTO department_tasks (task_type,title,to_dept,from_dept) VALUES ('general','Audit private task','finance_dept','school_admin')")['id']
        response = self.clients['teacher'].post('/admin/api_notifications.php',{'action':'task_update','task_id':task,'task_status':'completed'})
        self.assertNotEqual((response['json'] or {}).get('status'),'success')
        self.assertEqual(sql('SELECT status FROM department_tasks WHERE id=?',[task])['rows'][0]['status'],'pending')
        self.good(self.clients['finance_dept'].post('/admin/api_notifications.php',{'action':'task_update','task_id':task,'task_status':'completed'}))

    def test_17_member_change_pii_is_not_a_teacher_alert(self):
        for role in ['teacher','attendance_taker','finance_dept','material_dept','mezmur_dept','content_editor']:
            with self.subTest(role=role):
                response=self.clients[role].request('/admin/api_notifications.php?action=changes')
                self.assertEqual(response['status'],403,response['body'][:300])

    def test_18_frontend_bootstrap_survives_script_delimiter(self):
        client=self.clients['finance_dept']
        evil="O'Neil\n</script><script>window.__auditXss=1</script>"
        fixture({'op':'session','id':client.session_id(),'values':{'admin_full_name':evil}})
        try:
            response=client.request('/frontend/pages/finance_dept.php',headers={'Accept':'text/html'})
            self.assertEqual(response['status'],200)
            self.assertNotIn('</script><script>window.__auditXss',response['body'])
            match=re.search(r'window.APP\s*=\s*(\{.*?\});\s*</script>',response['body'],re.S)
            self.assertIsNotNone(match)
            self.assertEqual(json.loads(match[1])['user']['name'],evil)
        finally:
            fixture({'op':'session','id':client.session_id(),'values':{'admin_full_name':'Audit finance_dept'}})

    def test_19_database_revalidation_covers_backend_shim(self):
        client=self.clients['finance_dept']
        fixture({'op':'session','id':client.session_id(),'values':{'AUTH_REVALIDATED_AT':0}})
        sql("UPDATE users SET is_active=0 WHERE username='audit_finance_dept'")
        try:
            response=client.request('/backend/api/finance.php?action=dashboard')
            self.assertEqual(response['status'],401,response['body'][:300])
            self.assertIsInstance(response['json'],dict)
        finally:
            sql("UPDATE users SET is_active=1 WHERE username='audit_finance_dept'")

    def test_20_idle_frontend_timeout_returns_quick_same_origin_redirect(self):
        client=self.clients['mezmur_dept']
        fixture({'op':'session','id':client.session_id(),'values':{'LAST_ACTIVITY':int(time.time())-1900}})
        start=time.monotonic()
        response=client.request('/frontend/pages/mezmur_dept.php',headers={'Accept':'text/html'})
        self.assertLess(time.monotonic()-start,3)
        self.assertEqual(response['status'],302,response['body'][:300])
        self.assertEqual(response['headers'].get('Location'),'/admin/index.php?timeout=1')
        page=client.request(response['headers']['Location'],headers={'Accept':'text/html'})
        self.assertEqual(page['status'],200)
        self.assertRegex(page['body'],r'name="csrf_token"\s+value="[a-f0-9]+"')

    def test_21_both_logout_paths_clear_session_and_resolve(self):
        for role,path in [('teacher','/admin/logout.php'),('material_dept','/backend/auth/logout.php')]:
            with self.subTest(path=path):
                client=self.clients[role]
                response=client.request(path,headers={'Accept':'text/html'})
                self.assertEqual(response['status'],302)
                self.assertTrue(response['headers']['Location'].startswith('/admin/index.php?success='))
                self.assertEqual(client.request(response['headers']['Location'],headers={'Accept':'text/html'})['status'],200)
                self.assertEqual(client.request('/admin/api_settings.php?action=profile_get')['status'],401)

    def test_22_apache_denies_non_application_paths(self):
        for path in ['/.git/HEAD','/.git/config','/tests/e2e/seed.php','/tests/smoke/hr_phase6_smoke.php',
                     '/tests/audit/db_fixture.php','/tools/find_stored_mojibake.php','/vendor/composer/installed.json',
                     '/database_schema.sql','/env.example.php','/config.php','/admin/migrations/004_add_finance_material_tables.php']:
            with self.subTest(path=path):
                response=Client().request(path)
                self.assertIn(response['status'],[403,404])

    def test_23_impersonation_restores_from_every_supported_department(self):
        client=self.clients['super_admin']
        try:
            for role in ['school_admin','hr_dept','info_dept','edu_dept','finance_dept','material_dept','mezmur_dept','teacher','attendance_taker']:
                with self.subTest(role=role):
                    self.good(client.post('/admin/api_impersonate.php',{'action':'switch','role':role}))
                    status=self.good(client.request('/admin/api_impersonate.php?action=status'))
                    self.assertEqual(status['current_role'],role)
                    self.good(client.post('/backend/api/impersonate.php',{'action':'restore'}))
                    status=self.good(client.request('/admin/api_impersonate.php?action=status'))
                    self.assertEqual(status['current_role'],'super_admin')
        finally:
            fixture({'op':'session','id':client.session_id(),'values':{'admin_role':'super_admin','original_admin_role':None}})

    def test_24_user_update_cannot_self_lockout(self):
        actor=sql("SELECT id,username,full_name FROM users WHERE username='audit_super_admin'")['rows'][0]
        for fields in [{'role':'school_admin','is_active':1},{'role':'super_admin','is_active':0}]:
            data=dict(action='save',user_id=actor['id'],username=actor['username'],full_name=actor['full_name'],**fields)
            response=self.clients['super_admin'].post('/backend/users/user-save.php',data)
            self.assertEqual((response['json'] or {}).get('status'),'error',response['body'][:300])
        row=sql('SELECT role,is_active FROM users WHERE id=?',[actor['id']])['rows'][0]
        self.assertEqual(row['role'],'super_admin')
        self.assertEqual(int(row['is_active']),1)

    def test_25_enrollment_moves_atomically_and_reuses_existing_target_id(self):
        member=fixture({'op':'clone_member'})['id']
        client=self.clients['edu_dept']
        ids=[]
        try:
            for target in [self.class_one,self.class_two,self.class_one]:
                result=self.good(client.post('/admin/api_education.php',{'action':'enroll','member_id':member,'class_id':target}))
                rows=sql("SELECT id,class_id FROM class_enrollments WHERE member_id=? AND status='active'",[member])['rows']
                self.assertEqual(len(rows),1)
                self.assertEqual(int(rows[0]['class_id']),target)
                ids.append(int(rows[0]['id']))
            self.assertEqual(ids[0],ids[2])
        finally:
            sql('DELETE FROM class_enrollments WHERE member_id=?',[member])
            sql('DELETE FROM members WHERE id=?',[member])

    def test_26_enrollment_preserves_outer_transaction_on_success_and_failure(self):
        member=fixture({'op':'clone_member'})['id']
        client=self.clients['edu_dept']
        self.good(client.post('/admin/api_education.php',{'action':'enroll','member_id':member,'class_id':self.class_one}))
        year=int(sql('SELECT academic_year_id FROM class_enrollments WHERE member_id=?',[member])['rows'][0]['academic_year_id'])
        before=sql('SELECT father_name FROM members WHERE id=?',[member])['rows'][0]['father_name']
        try:
            result=fixture({'op':'enrollment_scope','member':member,'class':self.class_two,'year':year})
            self.assertEqual(result['result']['status'],'success',result)
            self.assertEqual(result['pending'],'AUDIT OUTER PENDING')
            self.assertEqual(result['persisted'],before)
            self.assertEqual(int(sql("SELECT class_id FROM class_enrollments WHERE member_id=? AND status='active'",[member])['rows'][0]['class_id']),self.class_one)
            sql("CREATE TRIGGER audit_fail_enrollment BEFORE INSERT ON class_enrollments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AUDIT injected enrollment failure'")
            result=fixture({'op':'enrollment_scope','member':member,'class':self.class_two,'year':year})
            self.assertEqual(result['result']['status'],'error')
            self.assertEqual(result['pending'],'AUDIT OUTER PENDING')
            self.assertEqual(result['persisted'],before)
            self.assertNotIn('AUDIT injected',result['result']['message'])
            self.assertEqual(int(sql("SELECT class_id FROM class_enrollments WHERE member_id=? AND status='active'",[member])['rows'][0]['class_id']),self.class_one)
        finally:
            sql('DROP TRIGGER IF EXISTS audit_fail_enrollment')
            sql('DELETE FROM class_enrollments WHERE member_id=?',[member])
            sql('DELETE FROM members WHERE id=?',[member])

    def test_27_concurrent_first_enrollments_leave_one_active_class(self):
        member=fixture({'op':'clone_member'})['id']
        try:
            def enroll(pair):
                role,target=pair
                return self.clients[role].post('/admin/api_education.php',{'action':'enroll','member_id':member,'class_id':target})
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                results=list(pool.map(enroll,[('edu_dept',self.class_one),('school_admin',self.class_two)]))
            for response in results:self.good(response)
            self.assertEqual(int(sql("SELECT COUNT(*) n FROM class_enrollments WHERE member_id=? AND status='active'",[member])['rows'][0]['n']),1)
        finally:
            sql('DELETE FROM class_enrollments WHERE member_id=?',[member])
            sql('DELETE FROM members WHERE id=?',[member])

    def test_28_public_lead_validation_and_atomic_rate_limit(self):
        client=Client()
        self.assertEqual(client.request('/register_submit.php')['status'],405)
        base={'full_name':'Audit Public Child','phone':'0912345678','age':'10'}
        for change in [{'full_name[]':'bad','full_name':None},{'age':'99'},{'phone':'invalid'}, {'email':'x'*121+'@x.test'}, {'message':'x'*5001}]:
            data=dict(base,**change);data={k:v for k,v in data.items() if v is not None}
            response=client.request('/register_submit.php',data)
            self.assertEqual(response['status'],422,response['body'][:200])
        before=int(sql('SELECT COUNT(*) n FROM cms_registration_submissions')['rows'][0]['n'])
        self.good(client.request('/register_submit.php',dict(base,website='bot.example')))
        self.assertEqual(int(sql('SELECT COUNT(*) n FROM cms_registration_submissions')['rows'][0]['n']),before)
        sql('DELETE FROM security_rate_limits')
        def submit(i):
            return Client().request('/register_submit.php',dict(base,full_name='ሰ'*100+str(i)))
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:responses=list(pool.map(submit,range(8)))
        statuses=sorted(r['status'] for r in responses)
        self.assertEqual(statuses,[200]*5+[429]*3,statuses)
        self.assertEqual(int(sql('SELECT COUNT(*) n FROM cms_registration_submissions')['rows'][0]['n'])-before,5)
        self.assertTrue(all('Retry-After' in r['headers'] for r in responses if r['status']==429))

    def test_29_upload_executables_and_library_tools_are_not_http_endpoints(self):
        for rel in ['uploads/audit-deny.php8','uploads/audit-deny.php.jpg','admin/uploads/audit-deny.phar']:
            path=ROOT/rel
            self.assertFalse(path.exists())
            try:
                path.write_text('AUDIT: must not be served')
                self.assertEqual(Client().request('/'+rel)['status'],403,rel)
            finally:path.unlink(missing_ok=True)
        for route in ['/admin/backend/pdf/tcpdf/tools/tcpdf_addfont.php','/admin/id_cards/libs/phpqrcode/index.php']:
            self.assertEqual(Client().request(route)['status'],403)

    def test_30_rest_api_role_boundaries_and_malformed_route(self):
        sql('DELETE FROM security_rate_limits')
        for role in ['super_admin','school_admin','info_dept','edu_dept','finance_dept','material_dept','mezmur_dept','teacher','attendance_taker','mezmur_attendance_taker','hr_attendance_taker','content_editor','hr_dept']:
            with self.subTest(role=role):
                # This matrix tests permissions, not the ten-logins/minute throttle.
                sql("DELETE FROM security_rate_limits WHERE action_name='api:auth_login'")
                client=Client()
                login=client.request('/api/v1/auth/login',{'username':'audit_'+role,'password':'AuditTest#2026'},headers={'Accept':'application/json','Content-Type':'application/json'})
                result=self.good(login)
                token=result['data']['token']
                headers={'Authorization':'Bearer '+token,'Accept':'application/json'}
                members=client.request('/api/v1/members?limit=2',headers=headers)
                if role in ['super_admin','school_admin','info_dept','edu_dept']:
                    self.good(members)
                    if role=='edu_dept':
                        for member in members['json']['data']['items']:
                            self.assertNotIn('phone_number',member)
                            self.assertNotIn('guardian_name',member)
                else:self.assertEqual(members['status'],403,members['body'][:250])
                profile=client.request('/api/v1/auth/verify',headers=headers)
                self.good(profile)
                self.assertNotIn('password_hash',profile['body'])
        response=Client().request('/api/v1/index.php?_route[]=members')
        self.assertEqual(response['status'],400,response['body'][:200])
        self.assertIsInstance(response['json'],dict)

    def test_31_private_member_documents_are_scoped_and_cannot_traverse(self):
        column='doc_school_records_path'
        before=sql('SELECT '+column+' value FROM members WHERE id=900000')['rows'][0]['value']
        name='audit-private-'+str(time.time_ns())+'.pdf'
        doc=ROOT/'admin/uploads/members/docs'/name
        doc.parent.mkdir(parents=True,exist_ok=True)
        payload=b'%PDF-1.4\n% synthetic private document\n%%EOF\n'
        doc.write_bytes(payload)
        route='/admin/member_file.php?member_id=900000&field='+column
        try:
            sql('UPDATE members SET '+column+'=? WHERE id=900000',['uploads/members/docs/'+name])
            response=self.clients['hr_dept'].request(route)
            self.assertEqual(response['status'],200,response['body'][:180])
            self.assertEqual(response['raw'],payload)
            self.assertIn('no-store',response['headers']['Cache-Control'])
            self.assertEqual(self.clients['edu_dept'].request(route)['status'],403)
            self.assertIn(Client().request('/admin/uploads/members/docs/'+name)['status'],[403,404])
            sql('UPDATE members SET '+column+'=? WHERE id=900000',['../../config.php'])
            response=self.clients['hr_dept'].request(route)
            self.assertEqual(response['status'],404)
            self.assertNotIn('DB_PASS',response['body'])
        finally:
            sql('UPDATE members SET '+column+'=? WHERE id=900000',[before])
            doc.unlink(missing_ok=True)

    def test_32_deleting_recipient_never_publishes_private_notification(self):
        name='audit_delete_'+str(time.time_ns())
        target=int(sql("INSERT INTO users (username,full_name,role,password_hash,is_active) VALUES (?,?,'teacher','not-a-login-hash',1)",[name,'Audit deletion target'])['id'])
        private=int(sql("INSERT INTO notifications (type,title,message,target_user_id,target_roles) VALUES ('general','Audit private deletion','PRIVATE AUDIT',?,NULL)",[target])['id'])
        shared=int(sql("INSERT INTO notifications (type,title,message,target_user_id,target_roles) VALUES ('general','Audit shared deletion','SHARED AUDIT',?,'finance_dept')",[target])['id'])
        try:
            response=self.clients['super_admin'].post('/admin/backend/user-delete.php',
                {'delete_user_id':target,'superadmin_password':'AuditTest#2026'})
            self.assertEqual(response['status'],302,response['body'][:180])
            self.assertIn('/admin/users.php?success=',response['headers']['Location'])
            self.assertEqual(sql('SELECT id FROM users WHERE id=?',[target])['rows'],[])
            self.assertEqual(sql('SELECT id FROM notifications WHERE id=?',[private])['rows'],[])
            row=sql('SELECT target_roles,target_user_id FROM notifications WHERE id=?',[shared])['rows'][0]
            self.assertEqual(row['target_roles'],'finance_dept')
            self.assertIsNone(row['target_user_id'])
        finally:
            sql('DELETE FROM notifications WHERE id IN (?,?)',[private,shared])
            sql('DELETE FROM users WHERE id=?',[target])

    def test_33_backup_create_download_and_decrypt_remain_available_to_super_admin(self):
        client=self.clients['super_admin']
        self.assertEqual(client.request('/admin/tools/backup.php')['status'],405)
        self.assertEqual(self.clients['school_admin'].request('/admin/tools/backup.php')['status'],403)
        before={f['name'] for f in fixture({'op':'backup_list'})['files']}
        files=[]
        restored=ROOT.parent/('audit-restore-'+str(time.time_ns())+'.sql')
        try:
            response=client.post('/admin/tools/backup.php',{})
            self.assertEqual(response['status'],303,response['body'][:180])
            self.assertIn('backup_status=created',response['headers']['Location'])
            files=[f for f in fixture({'op':'backup_list'})['files'] if f['name'] not in before]
            self.assertEqual(len(files),1)
            backup=files[0]
            self.assertTrue(backup['encrypted'])
            route='/admin/tools/download_backup.php?file='+urllib.parse.quote(backup['name'])
            downloaded=client.request(route)
            self.assertEqual(downloaded['status'],200)
            self.assertEqual(downloaded['raw'],Path(backup['path']).read_bytes())
            self.assertNotIn(b'CREATE TABLE',downloaded['raw'])
            self.assertEqual(self.clients['school_admin'].request(route)['status'],403)
            completed=subprocess.run(['php',str(ROOT/'admin/tools/backup.php'),'--decrypt='+backup['name'],'--output='+str(restored)],cwd=ROOT,capture_output=True,text=True)
            self.assertEqual(completed.returncode,0,completed.stderr)
            content=restored.read_text()
            self.assertIn('-- SSMS database backup',content)
            self.assertIn('CREATE TABLE `members`',content)
            self.assertIn('CREATE TABLE `finance_transactions`',content)
        finally:
            restored.unlink(missing_ok=True)
            for file in files:Path(file['path']).unlink(missing_ok=True)


if __name__ == '__main__':
    unittest.main(verbosity=2)
