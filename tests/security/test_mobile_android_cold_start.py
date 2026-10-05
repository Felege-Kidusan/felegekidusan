"""A.12 Android cold-start source-contract evidence.

These tests do not execute Android, Kotlin, AlarmManager or a Flutter engine.
They prove that the cold path is wired to the existing A.8 contract and that
its lifecycle is explicit. Android runtime evidence is reported separately and
remains pending unless an emulator/device actually runs the APK.
"""

import os
import re
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, "Mobile", "wbws_flutter_app")
ANDROID = os.path.join(APP, "android", "app")
KOTLIN = os.path.join(
    ANDROID, "src", "main", "kotlin", "com", "arkeonethiopia", "fkss"
)
LIB = os.path.join(APP, "lib")
SERVICES = os.path.join(LIB, "services")


def read(*parts):
    with open(os.path.join(*parts), "r", encoding="utf-8") as handle:
        return handle.read()


def strip_comments(source):
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return "\n".join(re.sub(r"//.*$", "", line) for line in source.splitlines())


def strip_xml_comments(source):
    return re.sub(r"<!--.*?-->", "", source, flags=re.S)


class ColdStartDartContract(unittest.TestCase):
    def setUp(self):
        self.main = strip_comments(read(LIB, "main.dart"))
        self.bridge = strip_comments(read(SERVICES, "background_sync_bridge.dart"))
        self.channel = strip_comments(
            read(SERVICES, "android_background_sync_scheduler.dart")
        )
        self.local_db = strip_comments(read(SERVICES, "local_db.dart"))

    def test_release_safe_entry_point_is_preserved(self):
        self.assertIn("@pragma('vm:entry-point')", self.main)
        self.assertIn("Future<void> backgroundSyncMain()", self.main)

    def test_entry_point_initializes_only_headless_services(self):
        block = self.main.split("Future<void> backgroundSyncMain()", 1)[1].split(
            "class FKSSApp", 1
        )[0]
        self.assertIn("WidgetsFlutterBinding.ensureInitialized()", block)
        self.assertIn("BackgroundSyncBridge(channel: channel).install()", block)
        self.assertIn("SessionCoordinator().bootstrap()", block)
        self.assertNotIn("runApp(", block)
        self.assertNotIn("installAndroidBackgroundSyncProducer", block)

    def test_entry_point_uses_the_existing_channel_and_ready_handshake(self):
        self.assertIn("BackgroundSyncChannel.name", self.main)
        self.assertIn("BackgroundSyncChannel.methodBackgroundReady", self.main)
        self.assertIn("'ready': ready", self.main)
        self.assertIn("methodBackgroundReady = 'backgroundReady'", self.channel)

    def test_bootstrap_failure_is_not_success(self):
        block = self.main.split("Future<void> backgroundSyncMain()", 1)[1].split(
            "class FKSSApp", 1
        )[0]
        self.assertIn("ready = false", block)
        self.assertIn("catch (_) ", block)
        self.assertIn("ready = SessionCoordinator().isActive", block)

    def test_existing_bridge_remains_the_one_dart_execution_route(self):
        self.assertIn("SyncService().runSyncNow(source: source)", self.bridge)
        self.assertNotIn("LocalDb().claim", self.bridge)
        self.assertNotIn("_drain(", self.bridge)
        self.assertNotIn("syncAll(", self.bridge)

    def test_background_source_is_still_strict(self):
        self.assertIn("if (source != SyncExecutionSource.background) return null;", self.bridge)
        self.assertIn("methodRunBackgroundSync = 'runBackgroundSync'", self.channel)

    def test_production_local_db_allows_independent_engine_connections(self):
        self.assertIn("singleInstance: false", self.local_db)
        self.assertIn("openDatabase(", self.local_db)


class ColdStartAndroidContract(unittest.TestCase):
    def setUp(self):
        self.producer = strip_comments(read(KOTLIN, "BackgroundSyncProducer.kt"))
        self.receiver = strip_comments(read(KOTLIN, "BackgroundSyncReceiver.kt"))
        self.activity = strip_comments(read(KOTLIN, "MainActivity.kt"))
        self.manifest = strip_xml_comments(
            read(ANDROID, "src", "main", "AndroidManifest.xml")
        )
        self.pubspec = read(APP, "pubspec.yaml")
        self.lock = read(APP, "pubspec.lock")

    def test_warm_path_is_preserved(self):
        self.assertIn("val target = channel", self.producer)
        self.assertIn("if (target != null)", self.producer)
        self.assertIn("invokeBackground(target, onFinished)", self.producer)
        self.assertIn("BackgroundSyncProducer.attach(syncChannel)", self.activity)

    def test_cold_engine_is_created_only_when_warm_channel_is_absent(self):
        self.assertIn("if (coldEngine != null)", self.producer)
        self.assertIn("synchronized(coldLock)", self.producer)
        self.assertIn("FlutterEngine(appContext, emptyArray(), true)", self.producer)

    def test_flutter_plugins_are_automatically_registered(self):
        # FlutterEngine's three-argument constructor's final boolean is the
        # Flutter-provided automatic plugin registration switch.
        self.assertIn("FlutterEngine(appContext, emptyArray(), true)", self.producer)
        self.assertIn("import io.flutter.embedding.engine.FlutterEngine", self.producer)

    def test_dart_entry_point_is_invoked_from_the_bundled_app(self):
        self.assertIn("DartExecutor.DartEntrypoint", self.producer)
        self.assertIn("loader.findAppBundlePath()", self.producer)
        self.assertIn('"backgroundSyncMain"', self.producer)
        self.assertIn("executeDartEntrypoint(entrypoint)", self.producer)

    def test_cold_channel_is_ready_before_run_is_invoked(self):
        self.assertIn("cold.setMethodCallHandler", self.producer)
        self.assertIn('METHOD_BACKGROUND_READY = "backgroundReady"', self.producer)
        self.assertIn("handleCold(appContext, call, result)", self.producer)
        self.assertIn("mainHandler.post { invokeCold() }", self.producer)
        self.assertIn("invokeMethod(METHOD_RUN", self.producer)

    def test_cold_and_warm_payloads_share_the_same_background_provenance(self):
        self.assertEqual(1, self.producer.count("KEY_SOURCE to SOURCE_BACKGROUND"))
        self.assertGreaterEqual(self.producer.count("invokeBackground("), 3)
        self.assertIn('SOURCE_BACKGROUND = "background"', self.producer)
        self.assertNotIn('SOURCE_BACKGROUND = "foreground"', self.producer)

    def test_only_one_temporary_engine_can_exist(self):
        self.assertIn("private val coldLock", self.producer)
        self.assertIn("if (coldEngine != null)", self.producer)
        self.assertEqual(1, self.producer.count("FlutterEngine(appContext, emptyArray(), true)"))

    def test_engine_cleanup_removes_handlers_and_destroys_engine(self):
        self.assertIn("cold?.setMethodCallHandler(null)", self.producer)
        self.assertIn("engine?.destroy()", self.producer)
        self.assertIn("coldEngine = null", self.producer)
        self.assertIn("coldChannel = null", self.producer)

    def test_success_failure_and_timeout_all_finish_the_cold_engine(self):
        for marker in (
            'finishCold("dart_callback")',
            'finishCold("engine_creation_failed")',
            'finishCold("bootstrap_not_ready")',
            'finishCold("timeout")',
        ):
            self.assertIn(marker, self.producer)
        self.assertIn("AtomicBoolean", self.producer)
        self.assertIn("compareAndSet(false, true)", self.producer)

    def test_receiver_awaits_the_delivery_boundary(self):
        self.assertIn("goAsync()", self.receiver)
        self.assertIn("BackgroundSyncProducer.deliver(context)", self.receiver)
        self.assertEqual(2, self.receiver.count("pendingResult.finish()"))
        self.assertIn("catch (t: Throwable)", self.receiver)

    def test_receiver_remains_private(self):
        block = re.search(
            r"<receiver[^>]*BackgroundSyncReceiver.*?</receiver>",
            self.manifest,
            re.S,
        )
        self.assertIsNotNone(block)
        self.assertIn('android:exported="false"', block.group(0))
        self.assertIn("com.arkeonethiopia.fkss.action.BACKGROUND_SYNC", block.group(0))

    def test_no_second_scheduler_or_runtime_dependency_was_added(self):
        for forbidden in (
            "workmanager",
            "android_alarm_manager_plus",
            "flutter_background_service",
            "RECEIVE_BOOT_COMPLETED",
            "BOOT_COMPLETED",
        ):
            self.assertNotIn(forbidden, self.pubspec)
            self.assertNotIn(forbidden, self.lock)
            self.assertNotIn(forbidden, self.producer)

    def test_native_side_has_no_database_or_sync_business_logic(self):
        native = self.producer + self.receiver
        for forbidden in (
            "SQLiteDatabase",
            "sqflite",
            "sync_attempts",
            "pending_hymn_ops",
            "client_op_id",
            "attempt_count",
            "owner_user_id",
            "authorizationVersion",
            "HTTP",
            "OkHttp",
        ):
            self.assertNotIn(forbidden, native, forbidden)

    def test_main_activity_warm_cleanup_remains_intact(self):
        self.assertIn("override fun cleanUpFlutterEngine", self.activity)
        self.assertIn("BackgroundSyncProducer.detach(it)", self.activity)


if __name__ == "__main__":
    unittest.main()
