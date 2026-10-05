"""S2 Goal A.1 + A.2 + A.4 + A.5 + A.6 — mutation harness for the background sync core.

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
REQUEST_BLOCK=(
  "      unawaited(\n"
  "        _coordinator\n"
  "            .requestOpportunity(notBefore: nextAttempt)\n"
  "            .catchError((Object _) {}),\n"
  "      );\n")

TEST=["tests/security/test_mobile_background_sync_core.py",
      "tests/security/test_mobile_session_coordinator.py",
      # A.6: the v36->v37 upgrade suite. Registered here so M36-M41 are run
      # against it; without this the migration mutations would report CAUGHT
      # or SURVIVED on a suite that never looks at the migration.
      "tests/security/test_mobile_v36_to_v37_upgrade.py"]

MUT=[
 ("M1 remove execution-source propagation into the claim",
  f"{LIB}/sync_service.dart","        executionSource: source,\n",""),
 ("M2 drop the source before the ledger write",
  f"{LIB}/local_db.dart","          executionSource: executionSource,\n",""),
 ("M3 stop writing the durable column",
  f"{LIB}/local_db.dart","        'execution_source': executionSource.storageValue,\n",""),
 # A.3 finding M-1: this anchor used to be the bare predicate, which occurs
 # TWICE in local_db.dart (the claim query and hasDueLegacyOutbox). It hit the
 # right one only because the claim happens to appear first in the file. It is
 # now anchored to the claim's own preceding line at the claim's indentation,
 # which is unique, so reordering the file can no longer silently re-target it.
 ("M4 ignore next_retry_at eligibility in the claim",
  f"{LIB}/local_db.dart",
  "        \"AND sync_state IN ('pending', 'retry_wait') \"\n"
  "        'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '\n",
  "        \"AND sync_state IN ('pending', 'retry_wait') \"\n"),
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
 ("M14 nudge bypasses the coordinated boundary",
  f"{LIB}/sync_service.dart",
  "      runSyncNow(\n        source: SyncExecutionSource.foreground,\n        generation: generation,\n      );",
  "      _syncAllForGeneration(generation);"),
 ("M15 nudge re-reads the generation at fire time instead of carrying it",
  f"{LIB}/sync_service.dart","        generation: generation,\n",""),
 ("M16 runSyncNow skips the coordinator",
  f"{LIB}/sync_service.dart","    return _coordinator.execute(source);",
  "    return _executeCoordinatedDrain(source);"),
 ("M17 logout stops cancelling the background wake-up",
  f"{LIB}/sync_service.dart","    unawaited(_coordinator.cancelOpportunity());",""),
 ("M18 the coordinator gains a drop-if-busy guard (swallows mid-drain work)",
  f"{LIB}/sync_execution.dart",
  "    _opportunityPending = false;\n    return _runDrain(source);",
  "    _opportunityPending = false;\n    if (_running) return _runDrain(source);\n    return _runDrain(source);"),
 ("M19 a foreground trigger mislabels itself as background",
  f"{LIB}/sync_service.dart",
  "  Future<SyncResult> syncAll({bool force = false}) =>\n      runSyncNow(source: SyncExecutionSource.foreground, force: force);",
  "  Future<SyncResult> syncAll({bool force = false}) =>\n      runSyncNow(source: SyncExecutionSource.background, force: force);"),
 ("M20 startAutoSync reaches around nudge straight into the drain",
  f"{LIB}/sync_service.dart","    nudge(delay: const Duration(milliseconds: 800));",
  "    _syncAllForGeneration(sessionGenerationProvider?.call() ?? 0);"),
 ("M21 the executor drops its ownership guard",
  f"{LIB}/sync_service.dart",
  "    if (!_ownsGeneration(generation) || !_api.isLoggedIn) {\n      return SyncResult(synced: 0, failed: 0, message: 'Not logged in');",
  "    if (!_api.isLoggedIn) {\n      return SyncResult(synced: 0, failed: 0, message: 'Not logged in');"),
 ("M22 logout destroys durable work instead of just the wake-up",
  f"{LIB}/sync_service.dart","    unawaited(_coordinator.cancelOpportunity());",
  "    unawaited(_coordinator.cancelOpportunity());\n    unawaited(_db.deleteAllPendingOperations());"),
 # ── S2 Goal A.4 — the opportunity scheduler is reachable (finding B-1) ──
 ("M23 the drain stops requesting a background opportunity",
  f"{LIB}/sync_service.dart", REQUEST_BLOCK, ""),
 ("M24 the trigger drains directly instead of requesting an opportunity",
  f"{LIB}/sync_service.dart", REQUEST_BLOCK,
  "      unawaited(_drain(generation: generation, force: force, source: source));\n"),
 ("M25 the trigger claims directly instead of requesting an opportunity",
  f"{LIB}/sync_service.dart", REQUEST_BLOCK,
  "      unawaited(_db.claimNextLegacyOperation(\n"
  "        kind: LegacyOperationKind.attendance,\n"
  "        ownerUserId: _api.userId,\n"
  "        authorizationVersion: _api.authorizationVersion,\n"
  "        runtimeGeneration: generation,\n"
  "      ));\n"),
 ("M26 the trigger bypasses the coordinator and calls the scheduler itself",
  f"{LIB}/sync_service.dart", REQUEST_BLOCK,
  "      unawaited(_backgroundScheduler\n"
  "          .ensureScheduled(BackgroundSyncRequest(notBefore: nextAttempt)));\n"),
 ("M27 a second scheduling mechanism replaces the Noop seam",
  f"{LIB}/sync_service.dart",
  "  BackgroundSyncScheduler _backgroundScheduler =\n"
  "      const NoopBackgroundSyncScheduler();",
  "  BackgroundSyncScheduler _backgroundScheduler = _TimerBackedScheduler();"),
 ("M28 the opportunity is gated on connectivity (loses the offline wake-up)",
  f"{LIB}/sync_service.dart",
  REQUEST_BLOCK + "      if (force || ConnectivityService().hasLink) {\n",
  "      if (force || ConnectivityService().hasLink) {\n" + REQUEST_BLOCK),
 # ── S2 Goal A.5 — the constructor-injection seam ───────────────────────
 ("M29 the API collaborator stops being injectable",
  f"{LIB}/sync_service.dart","_api = api ?? ApiService()","_api = ApiService()"),
 ("M30 the DB collaborator stops being injectable",
  f"{LIB}/sync_service.dart","_db = db ?? LocalDb()","_db = LocalDb()"),
 ("M31 a method rebuilds the API instead of using the injected field",
  f"{LIB}/sync_service.dart",
  "    if (activeSessionGate?.call() == false || !_drainsAllowed) return;\n"
  "    if (!_api.isLoggedIn) return;",
  "    if (activeSessionGate?.call() == false || !_drainsAllowed) return;\n"
  "    if (!ApiService().isLoggedIn) return;"),
 ("M32 a method rebuilds the DB instead of using the injected field",
  f"{LIB}/sync_service.dart",
  "    final nextAttempt = await _db.nextOutboxAttemptAt(",
  "    final nextAttempt = await LocalDb().nextOutboxAttemptAt("),
 ("M33 a due backlog stops queueing another pass",
  f"{LIB}/sync_service.dart","    if (hasMoreDueLegacy) _queued = true;\n",""),
 ("M34 a request arriving mid-drain is swallowed instead of queued",
  f"{LIB}/sync_service.dart","      if (sameGeneration) _queued = true;\n",""),
 ("M35 the injecting constructor becomes a factory returning the singleton",
  f"{LIB}/sync_service.dart",
  "  SyncService.withCollaborators({ApiService? api, LocalDb? db})\n"
  "      : _api = api ?? ApiService(),\n"
  "        _db = db ?? LocalDb();",
  "  factory SyncService.withCollaborators({ApiService? api, LocalDb? db}) =>\n"
  "      _instance;"),
 # ── S2 Goal A.6 — the v36->v37 upgrade path ────────────────────────────
 ("M36 the v37 migration operation is removed entirely",
  f"{LIB}/local_db.dart",
  "                await db.execute(\n"
  "                  'ALTER TABLE sync_attempts ADD COLUMN execution_source '\n"
  "                  \"TEXT NOT NULL DEFAULT 'foreground'\",\n"
  "                );\n", ""),
 ("M37 the migration keys off the wrong source version",
  f"{LIB}/local_db.dart",
  "          if (oldVersion < 37) {","          if (oldVersion < 36) {"),
 ("M38 the added column loses NOT NULL and its backfill default",
  f"{LIB}/local_db.dart",
  "\"TEXT NOT NULL DEFAULT 'foreground'\",","\"TEXT\","),
 ("M39 the migration adds the wrong column",
  f"{LIB}/local_db.dart",
  "'ALTER TABLE sync_attempts ADD COLUMN execution_source '",
  "'ALTER TABLE sync_attempts ADD COLUMN execution_src '"),
 ("M40 the column probe is inverted so the branch silently skips",
  f"{LIB}/local_db.dart",
  "if (!columns.contains('execution_source')) {",
  "if (columns.contains('execution_source')) {"),
 ("M41 the table probe is dropped from the v37 branch",
  f"{LIB}/local_db.dart",
  "if (await _tableExists(db, 'sync_attempts')) {",
  "if (true) {"),
]
# Anchor-uniqueness gate (the A.3 lesson, finding M-1, made permanent).
# str.replace(old, new, 1) edits the FIRST match, so an anchor that occurs more
# than once silently mutates whichever site happens to come first in the file.
# That is not a caught mutation, it is a mis-targeted one. Refuse to run.
_ambiguous=[]
for _name,_path,_old,_new in MUT:
    _n=open(_path).read().count(_old)
    if _n!=1: _ambiguous.append(f"{_name}: anchor occurs {_n} times in {os.path.basename(_path)}")
if _ambiguous:
    print("AMBIGUOUS ANCHORS -- results would be untrustworthy:")
    for _m in _ambiguous: print("  "+_m)
    sys.exit(1)
print(f"anchors unique: {len(MUT)}/{len(MUT)}")

# Baseline gate. Without this a test that fails on CLEAN source reports every
# mutation as "caught" while actually proving nothing -- which happened once
# here (M21), so the harness now refuses to run on a red baseline.
_b=subprocess.run(["python3","-m","pytest",*TEST,"-q","-p","no:cacheprovider"],
                  cwd=ROOT,capture_output=True,text=True)
if _b.returncode!=0:
    print("BASELINE RED -- results would be meaningless\n"+_b.stdout[-2000:]); sys.exit(1)
print("baseline green\n")

caught=survived=skipped=0
for name,path,old,new in MUT:
    src=open(path).read()
    if old not in src:
        print(f"SKIP   {name} :: anchor absent"); skipped+=1; continue
    shutil.copy(path,path+".bak")
    open(path,"w").write(src.replace(old,new,1))
    r=subprocess.run(["python3","-m","pytest",*TEST,"-q","-p","no:cacheprovider"],
                     cwd=ROOT,capture_output=True,text=True)
    shutil.move(path+".bak",path)
    if r.returncode!=0:
        print(f"CAUGHT {name}"); caught+=1
    else:
        print(f"SURVIVED {name}"); survived+=1
print(f"\nMUTATION: {len(MUT)} attempted / {caught} caught / {survived} survived / {skipped} skipped")
