"""No-database checks for the audited shared bootstrap and HTTP tool guards."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class StaticSafetyTests(unittest.TestCase):
    def test_tool_guards_run_before_config_even_without_htaccess(self):
        cgi = shutil.which('php-cgi')
        if not cgi:
            self.skipTest('PHP CGI is required to execute HTTP-denial guards independently of Apache.')
        scripts = [*ROOT.glob('tests/smoke/*.php'), ROOT/'tests/e2e/seed.php',
                   ROOT/'tests/audit/db_fixture.php', ROOT/'tools/find_stored_mojibake.php']
        for script in scripts:
            with self.subTest(file=str(script.relative_to(ROOT))):
                env = dict(os.environ, REDIRECT_STATUS='1', REQUEST_METHOD='GET', SCRIPT_FILENAME=str(script))
                result = subprocess.run([cgi], env=env, capture_output=True, text=True, timeout=5, cwd=ROOT)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertTrue(result.stdout.startswith('Status: 404'), result.stdout[:200])
                self.assertNotIn('Database', result.stdout)

    def test_auth_redirects_are_request_origin_independent_and_shim_safe(self):
        program = '''
require 'backend/core/browser.php';
$results=[];
foreach (['/admin/logout.php','/backend/auth/logout.php','/frontend/pages/login.php'] as $route) {
 foreach (['','/school'] as $prefix) {
  $_SERVER['SCRIPT_FILENAME']=getcwd().$route;
  $_SERVER['SCRIPT_NAME']=$prefix.$route;
  $_SERVER['HTTP_HOST']='attacker.invalid';
  $results[]=ssms_app_url('admin/index.php');
 }
}
echo json_encode($results);
'''
        result = subprocess.run(['php','-r',program],cwd=ROOT,capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(result.stdout),['/admin/index.php','/school/admin/index.php']*3)

    def test_ledger_validator_executes_with_no_database(self):
        program = '''
require 'admin/backend/services/LedgerValidation.php';
use App\\Services\\LedgerValidation as Input;
$bad=0;
foreach (['NaN','1e20','-2','0','1.123',[],true] as $value) {
 try { Input::money($value); }
 catch (App\\Services\\LedgerInputException $e) { $bad++; }
}
try { Input::date('2026-02-30','Date'); }
catch (App\\Services\\LedgerInputException $e) { $bad++; }
echo json_encode(['bad'=>$bad,'money'=>Input::money('123.45'),
 'whole'=>Input::integer('005','Quantity'),
 'leap'=>Input::date('2024-02-29','Date'),
 'name'=>mb_strlen(Input::text(str_repeat('ሰ',150),'Name',150,true),'UTF-8')]);
'''
        result=subprocess.run(['php','-r',program],cwd=ROOT,capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(result.stdout),{'bad':8,'money':123.45,'whole':5,'leap':'2024-02-29','name':150})

    def test_member_age_handles_birthdays_and_ethiopian_new_year(self):
        program = """
require 'admin/backend/services/MemberAge.php';
use App\\Services\\MemberAge;
$tz=new DateTimeZone('Africa/Addis_Ababa');
$a=MemberAge::years(['date_of_birth'=>'2010-09-08'],new DateTimeImmutable('2026-09-07',$tz));
$b=MemberAge::years(['date_of_birth'=>'2010-09-08'],new DateTimeImmutable('2026-09-08',$tz));
$c=MemberAge::years(['dob_ec_year'=>2010],new DateTimeImmutable('2026-09-10',$tz));
$d=MemberAge::years(['dob_ec_year'=>2010],new DateTimeImmutable('2026-09-11',$tz));
$e=MemberAge::years(['date_of_birth'=>'2030-01-01'],new DateTimeImmutable('2026-09-11',$tz));
echo json_encode([$a,$b,$c,$d,$e]);
"""
        result=subprocess.run(['php','-r',program],cwd=ROOT,capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(result.stdout),[15,16,8,9,None])


if __name__ == '__main__':
    unittest.main(verbosity=2)
