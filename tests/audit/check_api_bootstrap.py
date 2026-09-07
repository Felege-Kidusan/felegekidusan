#!/usr/bin/env python3
"""Opt-in local CGI test: a repeated API marker must never corrupt JSON."""
import json
import os
from pathlib import Path
import shutil
import subprocess

ROOT=Path(__file__).resolve().parents[2]
if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    raise SystemExit('Set SSMS_AUDIT_TESTING=1 only in the local synthetic environment.')
# Verify the same fixture's loopback/database-name safety guard first.
subprocess.run(['php',str(ROOT/'tests/audit/db_fixture.php'),json.dumps({'op':'sql','sql':'SELECT 1'})],cwd=ROOT,check=True,capture_output=True)
cgi=os.environ.get('SSMS_PHP_CGI') or shutil.which('php-cgi')
if not cgi: raise SystemExit('PHP CGI is required.')
env=dict(os.environ,REDIRECT_STATUS='1',REQUEST_METHOD='GET',SCRIPT_FILENAME=str(ROOT/'api/v1/index.php'),
    SCRIPT_NAME='/api/v1/index.php',QUERY_STRING='_route=auth/verify',
    REQUEST_URI='/api/v1/index.php?_route=auth/verify',DOCUMENT_ROOT=str(ROOT),
    SERVER_PROTOCOL='HTTP/1.1',SERVER_NAME='localhost',SERVER_PORT='80',
    REMOTE_ADDR='127.0.0.1',HTTP_ACCEPT='application/json')
env.pop('HTTP_COOKIE',None);env.pop('HTTP_AUTHORIZATION',None)
cmd=[cgi]
if os.environ.get('SSMS_PHP_INI'):cmd+=['-c',os.environ['SSMS_PHP_INI']]
cmd+=['-d','display_errors=1']
r=subprocess.run(cmd,cwd=ROOT,env=env,capture_output=True,text=True,timeout=10,check=True)
headers,separator,body=r.stdout.partition('\n\n')
assert separator and 'Status: 401' in headers,r.stdout[:300]
assert json.loads(body)['status']=='error',body[:300]
assert 'already defined' not in r.stdout+r.stderr,r.stderr
print('PASS: API bootstrap returns clean JSON 401 with initial display_errors=1; no duplicate marker warning.')
