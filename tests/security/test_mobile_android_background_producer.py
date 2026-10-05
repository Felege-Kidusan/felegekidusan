"""S3 Goal A.8 — the Android background sync producer.

WHAT THIS SUITE IS, AND WHAT IT IS NOT
======================================

These are SOURCE-CONTRACT tests. They read the Kotlin, the AndroidManifest,
the ProGuard rules and the Dart bridge, strip comments so that prose can never
satisfy an assertion, and pin the scheduling contract and the architectural
rules A.8 imposed.

They are NOT Android runtime tests and must never be described as such:

    Android runtime/build verification unavailable in current CI

No Kotlin is compiled here or in CI. The repository has no Gradle wrapper,
no android/local.properties (settings.gradle asserts flutter.sdk) and no
Android SDK is installed locally or in the GitHub workflow, whose only mobile
job is `flutter test`. Nothing below proves that an alarm fires, that a
broadcast is delivered, that Doze behaves as documented, or that the APK
builds.

What they DO prove is everything that is decidable from the source: that the
native layer schedules and cancels one replaceable alarm, that it honours
notBefore, that it carries explicit provenance and nothing else, that it
contains no sync business logic, that it requests no new permission, and that
a duplicate or engine-less callback is safe.

The behavioural half lives in Dart:
  Mobile/wbws_flutter_app/test/android_background_sync_test.dart
  Mobile/wbws_flutter_app/test/background_sync_bridge_test.dart
"""

import os
import re
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, "Mobile", "wbws_flutter_app")
LIB = os.path.join(APP, "lib", "services")
ANDROID = os.path.join(APP, "android", "app")
KOTLIN = os.path.join(ANDROID, "src", "main", "kotlin", "com", "arkeonethiopia", "fkss")


def read(*parts):
    with open(os.path.join(*parts), "r", encoding="utf-8") as handle:
        return handle.read()


def strip_comments(source):
    """Remove // and /* */ comments so documentation can never pass a test."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return "\n".join(re.sub(r"//.*$", "", line) for line in source.splitlines())


def strip_xml_comments(source):
    return re.sub(r"<!--.*?-->", "", source, flags=re.S)


class AndroidBackgroundProducerSource(unittest.TestCase):
    def setUp(self):
        self.producer = strip_comments(read(KOTLIN, "BackgroundSyncProducer.kt"))
        self.receiver = strip_comments(read(KOTLIN, "BackgroundSyncReceiver.kt"))
        self.activity = strip_comments(read(KOTLIN, "MainActivity.kt"))
        self.manifest = strip_xml_comments(read(ANDROID, "src", "main", "AndroidManifest.xml"))
        self.proguard = read(ANDROID, "proguard-rules.pro")
        self.scheduler = strip_comments(read(LIB, "android_background_sync_scheduler.dart"))
        self.bridge = strip_comments(read(LIB, "background_sync_bridge.dart"))
        self.main = strip_comments(read(APP, "lib", "main.dart"))

    # ── the two sides agree ─────────────────────────────────────────────

    def test_channel_name_is_identical_on_both_sides(self):
        self.assertIn('const String name = \'fkss.app/background_sync\'', self.scheduler)
        self.assertIn('const val CHANNEL = "fkss.app/background_sync"', self.producer)

    def test_every_method_name_matches_across_the_boundary(self):
        for dart_const, kotlin_const in (
            ("methodEnsureScheduled = 'ensureScheduled'", 'METHOD_ENSURE_SCHEDULED = "ensureScheduled"'),
            ("methodCancel = 'cancel'", 'METHOD_CANCEL = "cancel"'),
            ("methodRunBackgroundSync = 'runBackgroundSync'", 'METHOD_RUN = "runBackgroundSync"'),
        ):
            self.assertIn(dart_const, self.scheduler, dart_const)
            self.assertIn(kotlin_const, self.producer, kotlin_const)

    def test_every_argument_key_matches_across_the_boundary(self):
        for dart_key, kotlin_key in (
            ("keyUniqueWorkName = 'uniqueWorkName'", 'KEY_UNIQUE_WORK_NAME = "uniqueWorkName"'),
            ("keyNotBeforeEpochMs = 'notBeforeEpochMs'", 'KEY_NOT_BEFORE = "notBeforeEpochMs"'),
            ("keyRequiresNetwork = 'requiresNetwork'", 'KEY_REQUIRES_NETWORK = "requiresNetwork"'),
            ("keySource = 'source'", 'KEY_SOURCE = "source"'),
            ("keyInvocationId = 'invocationId'", 'KEY_INVOCATION_ID = "invocationId"'),
        ):
            self.assertIn(dart_key, self.scheduler, dart_key)
            self.assertIn(kotlin_key, self.producer, kotlin_key)

    # ── scheduling ──────────────────────────────────────────────────────

    def test_it_schedules_through_alarm_manager(self):
        self.assertIn("AlarmManager.RTC_WAKEUP", self.producer)
        self.assertIn("setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP", self.producer)

    def test_inexact_alarm_so_no_exact_alarm_permission_is_needed(self):
        self.assertNotIn("setExactAndAllowWhileIdle", self.producer)
        self.assertNotIn("setExact(", self.producer)

    def test_a_pre_marshmallow_fallback_exists(self):
        # setAndAllowWhileIdle is API 23+. minSdk is not pinned in this
        # repository (build.gradle delegates to flutter.minSdkVersion), so the
        # guarded fallback is what makes the call safe whatever the Flutter
        # SDK resolves it to.
        self.assertIn("Build.VERSION_CODES.M", self.producer)
        self.assertIn("alarms.set(AlarmManager.RTC_WAKEUP", self.producer)

    def test_one_request_code_means_one_replaceable_opportunity(self):
        self.assertIn("ALARM_REQUEST_CODE = 0x5F55", self.producer)
        self.assertIn("PendingIntent.FLAG_UPDATE_CURRENT", self.producer)
        # Exactly one request code constant: a second slot would be a second
        # logical unit of background work, which the architecture does not have.
        self.assertEqual(1, len(re.findall(r"ALARM_REQUEST_CODE\s*=", self.producer)))
        self.assertEqual(
            1,
            len(re.findall(r"PendingIntent\.getBroadcast\(", self.producer)),
            "every alarm must come from the same single PendingIntent factory",
        )

    def test_the_pending_intent_is_immutable_where_supported(self):
        self.assertIn("PendingIntent.FLAG_IMMUTABLE", self.producer)

    def test_not_before_is_honoured_and_only_clamped_forward(self):
        self.assertIn("notBeforeEpochMs == null || notBeforeEpochMs < now", self.producer)
        self.assertIn("else notBeforeEpochMs", self.producer)

    def test_no_fixed_periodic_wake_loop(self):
        for forbidden in ("setRepeating", "setInexactRepeating", "INTERVAL_", "setPeriodic"):
            self.assertNotIn(forbidden, self.producer, forbidden)

    def test_invalid_scheduling_data_degrades_instead_of_crashing(self):
        # A codec may narrow a Long to an Int; anything else becomes "as soon
        # as allowed" rather than a crash or a wrong far-future alarm.
        self.assertIn("is Long -> raw", self.producer)
        self.assertIn("is Int -> raw.toLong()", self.producer)
        self.assertIn("else -> null", self.producer)

    def test_scheduling_failure_is_reported_so_dart_can_re_arm(self):
        self.assertIn('result.error("schedule_failed"', self.producer)
        self.assertIn('result.error("cancel_failed"', self.producer)

    # ── cancellation ────────────────────────────────────────────────────

    def test_cancel_really_cancels(self):
        self.assertIn("alarms.cancel(pending)", self.producer)
        self.assertIn("pending.cancel()", self.producer)

    def test_cancel_is_reachable_from_the_channel(self):
        self.assertIn("METHOD_CANCEL -> {", self.producer)

    # ── the network constraint, honestly ────────────────────────────────

    def test_requires_network_is_forwarded_but_never_interpreted_natively(self):
        self.assertIn("KEY_REQUIRES_NETWORK", self.producer)
        self.assertIn("requiresNetwork", self.scheduler)
        # A.8: "Do not duplicate connectivity logic in Kotlin."
        for forbidden in (
            "ConnectivityManager",
            "NetworkCapabilities",
            "isConnected",
            "activeNetwork",
            "NetworkRequest",
        ):
            self.assertNotIn(forbidden, self.producer, forbidden)
            self.assertNotIn(forbidden, self.receiver, forbidden)

    # ── callback invocation and provenance ──────────────────────────────

    def test_the_receiver_only_acts_on_its_own_action(self):
        self.assertIn("if (intent.action != BackgroundSyncProducer.ACTION_WAKE) return", self.receiver)

    def test_the_callback_invokes_the_dart_entry_point(self):
        self.assertIn("target.invokeMethod(METHOD_RUN", self.producer)

    def test_provenance_is_an_explicit_field_with_exactly_one_value(self):
        self.assertIn('SOURCE_BACKGROUND = "background"', self.producer)
        self.assertIn("KEY_SOURCE to SOURCE_BACKGROUND", self.producer)
        self.assertNotIn('"foreground"', self.producer)

    def test_the_callback_message_carries_nothing_but_provenance_and_an_id(self):
        block = re.search(r"val arguments = mapOf\((.*?)\)\n", self.producer, re.S)
        self.assertIsNotNone(block, "the invocation payload must be a single literal")
        body = block.group(1)
        self.assertEqual(
            2,
            body.count(" to "),
            "exactly two entries: the source and the invocation id",
        )
        for forbidden in ("token", "userId", "owner", "authorization", "password", "generation"):
            self.assertNotIn(forbidden, body, forbidden)

    def test_dart_refuses_any_provenance_but_background(self):
        self.assertIn("if (source != SyncExecutionSource.background) return null;", self.bridge)
        self.assertIn("errorInvalidInvocation", self.bridge)

    def test_dart_does_not_use_the_tolerant_storage_reader_for_a_live_call(self):
        # fromStorage() falls back to foreground for unknown input, which is
        # right for old rows and wrong for a live invocation.
        self.assertNotIn("fromStorage", self.bridge)

    # ── duplicate and engine-less callbacks ─────────────────────────────

    def test_an_engine_less_wake_does_nothing_and_does_not_re_arm(self):
        self.assertIn("if (target == null)", self.producer)
        null_branch = self.producer.split("if (target == null)", 1)[1].split("}", 1)[0]
        self.assertIn("onFinished()", null_branch)
        self.assertNotIn("schedule(", null_branch)

    def test_the_broadcast_completion_runs_exactly_once(self):
        self.assertIn("AtomicBoolean(false)", self.producer)
        self.assertIn("compareAndSet(false, true)", self.producer)

    def test_the_receiver_holds_the_wakelock_asynchronously_and_always_finishes(self):
        self.assertIn("goAsync()", self.receiver)
        self.assertEqual(2, self.receiver.count("pendingResult.finish()"),
                         "finished on both the success and the throw path")

    def test_the_broadcast_hold_is_bounded_under_the_anr_limit(self):
        match = re.search(r"BROADCAST_HOLD_MS\s*=\s*([\d_]+)L", self.producer)
        self.assertIsNotNone(match)
        self.assertLess(int(match.group(1).replace("_", "")), 10_000)

    def test_duplicates_are_not_deduplicated_natively(self):
        # A.8: route duplicates into the EXISTING coordinator; do not add a
        # second guard. The native side therefore keeps no seen-id set.
        for forbidden in ("HashSet", "mutableSetOf", "seenIds", "lastInvocation"):
            self.assertNotIn(forbidden, self.producer, forbidden)

    # ── no sync business logic in Kotlin ────────────────────────────────

    def test_no_database_or_outbox_knowledge_in_native_code(self):
        native = self.producer + self.receiver + self.activity
        for forbidden in (
            "pending_hymn_ops",
            "sync_attempts",
            "SQLite",
            "SQLiteDatabase",
            "sqflite",
            "next_attempt_at",
            "owner_user_id",
            "attempt_count",
            "client_op_id",
            ".db",
            "SELECT",
            "UPDATE ",
            "INSERT",
        ):
            self.assertNotIn(forbidden, native, forbidden)

    def test_no_retry_lease_or_idempotency_logic_in_native_code(self):
        native = (self.producer + self.receiver).lower()
        for forbidden in ("backoff", "retrycount", "retry-after", "lease", "idempot",
                          "attemptnumber", "nextattempt"):
            self.assertNotIn(forbidden, native, forbidden)

    def test_no_http_from_native_code(self):
        native = self.producer + self.receiver
        for forbidden in ("HttpURLConnection", "OkHttp", "URL(", "https://"):
            self.assertNotIn(forbidden, native, forbidden)

    # ── permissions and components ──────────────────────────────────────

    def test_the_receiver_is_declared_and_not_exported(self):
        self.assertIn('android:name=".BackgroundSyncReceiver"', self.manifest)
        block = re.search(r"<receiver[^>]*BackgroundSyncReceiver.*?</receiver>", self.manifest, re.S)
        self.assertIsNotNone(block)
        self.assertIn('android:exported="false"', block.group(0))
        self.assertIn("com.arkeonethiopia.fkss.action.BACKGROUND_SYNC", block.group(0))

    def test_the_action_string_matches_kotlin(self):
        self.assertIn('ACTION_WAKE = "com.arkeonethiopia.fkss.action.BACKGROUND_SYNC"', self.producer)

    def test_no_new_permission_was_requested(self):
        granted = set(re.findall(r'uses-permission android:name="android\.permission\.([A-Z_]+)"', self.manifest))
        self.assertEqual(
            granted,
            {
                "INTERNET",
                "WAKE_LOCK",
                "FOREGROUND_SERVICE",
                "FOREGROUND_SERVICE_MEDIA_PLAYBACK",
                "POST_NOTIFICATIONS",
                "CAMERA",
                "USE_BIOMETRIC",
                "ACCESS_NETWORK_STATE",
                "REQUEST_INSTALL_PACKAGES",
            },
            "A.8 adds background sync with ZERO new permissions; in particular "
            "SCHEDULE_EXACT_ALARM, USE_EXACT_ALARM and RECEIVE_BOOT_COMPLETED "
            "must stay absent",
        )

    def test_no_foreground_service_and_no_native_ui_was_added(self):
        native = self.producer + self.receiver
        for forbidden in ("startForeground", "NotificationCompat", "NotificationChannel", "startActivity"):
            self.assertNotIn(forbidden, native, forbidden)

    def test_r8_keeps_both_native_classes(self):
        self.assertIn("-keep class com.arkeonethiopia.fkss.BackgroundSyncReceiver", self.proguard)
        self.assertIn("-keep class com.arkeonethiopia.fkss.BackgroundSyncProducer", self.proguard)

    # ── engine binding ──────────────────────────────────────────────────

    def test_the_activity_binds_and_unbinds_the_channel(self):
        self.assertIn("BackgroundSyncProducer.attach(syncChannel)", self.activity)
        self.assertIn("BackgroundSyncProducer.detach(it)", self.activity)
        self.assertIn("override fun cleanUpFlutterEngine", self.activity)

    def test_detach_compares_identity_so_an_old_engine_cannot_unbind_a_new_one(self):
        self.assertIn("if (this.channel === channel) this.channel = null", self.producer)

    def test_the_alarm_uses_the_application_context_not_the_activity(self):
        self.assertIn("BackgroundSyncProducer.handle(applicationContext", self.activity)

    def test_the_producer_is_installed_once_at_bootstrap(self):
        self.assertIn("installAndroidBackgroundSyncProducer()", self.main)
        self.assertEqual(1, self.main.count("installAndroidBackgroundSyncProducer()"))
        self.assertIn("Platform.isAndroid", self.bridge)


class NoSecondEngineOrDrain(unittest.TestCase):
    """A.8's central negative: the native layer added no parallel machinery."""

    def setUp(self):
        self.bridge = strip_comments(read(LIB, "background_sync_bridge.dart"))
        self.scheduler_dart = strip_comments(read(LIB, "android_background_sync_scheduler.dart"))
        self.sync_service = strip_comments(read(LIB, "sync_service.dart"))
        self.producer = strip_comments(read(KOTLIN, "BackgroundSyncProducer.kt"))

    def test_the_bridge_calls_run_sync_now_and_nothing_else(self):
        self.assertIn("SyncService().runSyncNow(source: source)", self.bridge)
        self.assertEqual(1, self.bridge.count("runSyncNow"))
        for forbidden in ("_drain", "pushPending", "claimNext", "LocalDb(", "HymnStore"):
            self.assertNotIn(forbidden, self.bridge, forbidden)

    def test_the_bridge_adds_no_second_session_or_auth_gate(self):
        for forbidden in ("isLoggedIn", "activeSessionGate", "sessionGenerationProvider",
                          "authorizationVersion", "ownerUserId", "SessionService"):
            self.assertNotIn(forbidden, self.bridge, forbidden)

    def test_the_bridge_adds_no_second_coalescing_guard(self):
        for forbidden in ("_inflight", "_queued", "Completer"):
            self.assertNotIn(forbidden, self.bridge, forbidden)

    def test_there_is_still_exactly_one_scheduler_abstraction(self):
        self.assertIn("implements BackgroundSyncScheduler", self.scheduler_dart)
        self.assertNotIn("abstract class", self.scheduler_dart)
        self.assertNotIn("abstract class", self.bridge)

    def test_the_coordinator_stays_android_agnostic(self):
        execution = strip_comments(read(LIB, "sync_execution.dart"))
        for forbidden in ("import ", "MethodChannel", "Android", "AlarmManager", "Platform"):
            self.assertNotIn(forbidden, execution, forbidden)

    def test_no_new_dependency_was_added(self):
        lock = read(APP, "pubspec.lock")
        spec = read(APP, "pubspec.yaml")
        for forbidden in ("workmanager", "android_alarm_manager_plus", "flutter_background_service"):
            self.assertNotIn(forbidden, lock, forbidden)
            self.assertNotIn(forbidden, spec, forbidden)

    def test_no_headless_engine_or_second_isolate_was_introduced(self):
        # The A.8 stop condition: a second Dart isolate would mean a second
        # LocalDb singleton and a parallel sqflite connection.
        for forbidden in ("FlutterEngine(", "DartExecutor", "FlutterCallbackInformation",
                          "GeneratedPluginRegistrant", "FlutterMain"):
            self.assertNotIn(forbidden, self.producer, forbidden)
        for forbidden in ("PluginUtilities", "getCallbackHandle", "vm:entry-point", "Isolate.spawn"):
            self.assertNotIn(forbidden, self.bridge, forbidden)


if __name__ == "__main__":
    unittest.main()
