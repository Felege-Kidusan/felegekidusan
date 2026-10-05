"""S2 Goal A.1 — mutation harness for the background sync core.

Each entry breaks ONE invariant the architecture tests claim to protect, runs
tests/security/test_mobile_background_sync_core.py, then restores the file
byte-for-byte. A mutation that SURVIVES means the test suite is asserting
something weaker than it appears to.

Two findings came out of building this, both recorded in the S2 document:

  * M5/M6 originally survived. The isolation assertion searched a wide window
    in which the same SQL predicate occurs several times, so deleting it from
    the candidates SELECT went unnoticed. The test now pins that SELECT
    exactly.
  * M5 then still "survived" for a different reason: its anchor string occurs
    8 times in local_db.dart, so the mutation was editing an unrelated query.
    A mis-targeted mutation proves nothing; it is now anchored to text unique
    to the claim.

Run:  python3 tests/mutation/background_sync_core_mutations.py
"""
import subprocess, shutil, os, sys
ROOT="/home/user/SSMS"
LIB=os.path.join(ROOT,"Mobile/wbws_flutter_app/lib/services")
TEST="tests/security/test_mobile_background_sync_core.py"

MUT=[
 ("M1 remove execution-source propagation into the claim",
  f"{LIB}/sync_service.dart","        executionSource: source,\n",""),
 ("M2 drop the source before the ledger write",
  f"{LIB}/local_db.dart","          executionSource: executionSource,\n",""),
 ("M3 stop writing the durable column",
  f"{LIB}/local_db.dart","        'execution_source': executionSource.storageValue,\n",""),
 ("M4 ignore next_retry_at eligibility",
  f"{LIB}/local_db.dart",
  "'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '","''"),
 ("M5 bypass the owner isolation predicate",
  f"{LIB}/local_db.dart",
  "        'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '\n        'AND owner_user_id = ? '\n",
  "        'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '\n"),
 ("M6 bypass the authorization-version guard",
  f"{LIB}/local_db.dart","        'AND created_authorization_version = ? '\n",""),
 ("M7 route background through a second claim site",
  f"{LIB}/hymn_store.dart","final claim = await _db.claimNextHymnOperation(",
  "final claim = await _db.claimNextLegacyOperation("),
 ("M8 make the execution core depend on the platform",
  f"{LIB}/sync_execution.dart","library;","library;\nimport 'dart:io';"),
 ("M9 let a scheduler request carry a payload",
  f"{LIB}/sync_execution.dart","  final DateTime? notBefore;",
  "  final DateTime? notBefore;\n  final List<String> payload = const [];"),
 ("M10 give the coordinator its own queue",
  f"{LIB}/sync_execution.dart","  bool _opportunityPending = false;",
  "  bool _opportunityPending = false;\n  final List<String> _pendingOps = [];"),
 ("M11 un-guard the v37 migration (double ALTER)",
  f"{LIB}/local_db.dart","if (!columns.contains('execution_source')) {","if (true) {"),
 ("M12 revert the schema version",
  f"{LIB}/local_schema_v34.dart","const localDatabaseSchemaVersion = 37;",
  "const localDatabaseSchemaVersion = 36;"),
 ("M13 syncAll stops delegating to the unified entry",
  f"{LIB}/sync_service.dart",
  "  Future<SyncResult> syncAll({bool force = false}) =>\n      runSyncNow(source: SyncExecutionSource.foreground, force: force);",
  "  Future<SyncResult> syncAll({bool force = false}) =>\n      _syncAllForGeneration(sessionGenerationProvider?.call() ?? 0, force: force);"),
]
caught=survived=skipped=0
for name,path,old,new in MUT:
    src=open(path).read()
    if old not in src:
        print(f"SKIP   {name} :: anchor absent"); skipped+=1; continue
    shutil.copy(path,path+".bak")
    open(path,"w").write(src.replace(old,new,1))
    r=subprocess.run(["python3","-m","pytest",TEST,"-q","-p","no:cacheprovider"],
                     cwd=ROOT,capture_output=True,text=True)
    shutil.move(path+".bak",path)
    if r.returncode!=0:
        print(f"CAUGHT {name}"); caught+=1
    else:
        print(f"SURVIVED {name}"); survived+=1
print(f"\nMUTATION: {len(MUT)} attempted / {caught} caught / {survived} survived / {skipped} skipped")
import subprocess, shutil, os, sys
ROOT="/home/user/SSMS"
LIB=os.path.join(ROOT,"Mobile/wbws_flutter_app/lib/services")
TEST="tests/security/test_mobile_background_sync_core.py"

MUT=[
 ("M1 remove execution-source propagation into the claim",
  f"{LIB}/sync_service.dart","        executionSource: source,\n",""),
 ("M2 drop the source before the ledger write",
  f"{LIB}/local_db.dart","          executionSource: executionSource,\n",""),
 ("M3 stop writing the durable column",
  f"{LIB}/local_db.dart","        'execution_source': executionSource.storageValue,\n",""),
 ("M4 ignore next_retry_at eligibility",
  f"{LIB}/local_db.dart",
  "'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '","''"),
 ("M5 bypass the owner isolation predicate",
  f"{LIB}/local_db.dart",
  "        'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '\n        'AND owner_user_id = ? '\n",
  "        'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '\n"),
 ("M6 bypass the authorization-version guard",
  f"{LIB}/local_db.dart","        'AND created_authorization_version = ? '\n",""),
 ("M7 route background through a second claim site",
  f"{LIB}/hymn_store.dart","final claim = await _db.claimNextHymnOperation(",
  "final claim = await _db.claimNextLegacyOperation("),
 ("M8 make the execution core depend on the platform",
  f"{LIB}/sync_execution.dart","library;","library;\nimport 'dart:io';"),
 ("M9 let a scheduler request carry a payload",
  f"{LIB}/sync_execution.dart","  final DateTime? notBefore;",
  "  final DateTime? notBefore;\n  final List<String> payload = const [];"),
 ("M10 give the coordinator its own queue",
  f"{LIB}/sync_execution.dart","  bool _opportunityPending = false;",
  "  bool _opportunityPending = false;\n  final List<String> _pendingOps = [];"),
 ("M11 un-guard the v37 migration (double ALTER)",
  f"{LIB}/local_db.dart","if (!columns.contains('execution_source')) {","if (true) {"),
 ("M12 revert the schema version",
  f"{LIB}/local_schema_v34.dart","const localDatabaseSchemaVersion = 37;",
  "const localDatabaseSchemaVersion = 36;"),
 ("M13 syncAll stops delegating to the unified entry",
  f"{LIB}/sync_service.dart",
  "  Future<SyncResult> syncAll({bool force = false}) =>\n      runSyncNow(source: SyncExecutionSource.foreground, force: force);",
  "  Future<SyncResult> syncAll({bool force = false}) =>\n      _syncAllForGeneration(sessionGenerationProvider?.call() ?? 0, force: force);"),
]
caught=survived=skipped=0
for name,path,old,new in MUT:
    src=open(path).read()
    if old not in src:
        print(f"SKIP   {name} :: anchor absent"); skipped+=1; continue
    shutil.copy(path,path+".bak")
    open(path,"w").write(src.replace(old,new,1))
    r=subprocess.run(["python3","-m","pytest",TEST,"-q","-p","no:cacheprovider"],
                     cwd=ROOT,capture_output=True,text=True)
    shutil.move(path+".bak",path)
    if r.returncode!=0:
        print(f"CAUGHT {name}"); caught+=1
    else:
        print(f"SURVIVED {name}"); survived+=1
print(f"\nMUTATION: {len(MUT)} attempted / {caught} caught / {survived} survived / {skipped} skipped")
