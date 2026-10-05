package com.arkeonethiopia.fkss

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import java.util.UUID

/**
 * S3 — A.8. The native producer of `SyncExecutionSource.background`.
 *
 * WHAT THIS IS
 * ------------
 * A wake-up mechanism. It asks AlarmManager to deliver one broadcast at a
 * time the Dart outbox chose, and when that broadcast arrives it invokes the
 * Dart entry point. That is the whole job.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO — these are architectural rules, not
 * omissions:
 *  - It never opens, reads or writes the database, and does not know that
 *    `pending_hymn_ops` or any other table exists.
 *  - It never claims an operation, never retries, never leases, never
 *    implements idempotency, never checks an owner or an authorization
 *    version, and never inspects a session. Every one of those lives in the
 *    existing Dart `SyncService`/`LocalDb` and is reached unchanged.
 *  - It never evaluates connectivity. `requiresNetwork` is recorded and
 *    forwarded, never interpreted; the final network decision stays in Dart.
 *  - It holds no credentials and no operation payload. The only things that
 *    cross this boundary are a timestamp, a boolean, a work name and an
 *    opaque invocation id.
 *
 * WHY AlarmManager AND NOT WorkManager / JobScheduler
 * ---------------------------------------------------
 * Recorded in full in
 * docs/MOBILE_OFFLINE_SYNC_S3_A8_PHASE1_ANDROID_BACKGROUND_PRODUCER_ADR.md.
 * In short: WorkManager and android_alarm_manager_plus are pub packages, and
 * the dependency graph is broken by F-19 (url_launcher is declared in
 * pubspec.yaml and absent from pubspec.lock, so --enforce-lockfile cannot
 * succeed) with no Flutter SDK available to regenerate the lock. Every
 * mechanism that survives process death — those two, plus a hand-written
 * headless FlutterEngine or JobScheduler — additionally requires a SECOND
 * Dart isolate, which means a second LocalDb singleton and a parallel sqflite
 * connection whose cross-isolate behaviour no test in this repository has
 * ever exercised. A.8 names that a stop condition.
 *
 * `setAndAllowWhileIdle` needs NO permission (unlike `setExactAndAllowWhileIdle`,
 * which needs SCHEDULE_EXACT_ALARM from API 31), fires during Doze, and needs
 * no foreground service and no notification. So this costs zero new
 * dependencies and zero new permissions.
 *
 * SCOPE, STATED HONESTLY — read this before claiming any guarantee:
 *  - app in the FOREGROUND or BACKGROUNDED with the process alive: the
 *    broadcast reaches the live FlutterEngine and the drain runs. This is the
 *    case this class serves, and it is a real gap today because Dart `Timer`s
 *    are frozen while the app is idle/Doze-suspended whereas this alarm is not.
 *  - PROCESS KILLED (low memory, swipe-away on some OEMs): the manifest-declared
 *    receiver still starts the process, but there is no FlutterEngine, so
 *    [deliver] does nothing and deliberately does not re-arm — re-arming would
 *    be a wake loop that never makes progress. The durable outbox is untouched
 *    and drains on next launch.
 *  - FORCE-STOPPED by the user: Android cancels the app's alarms and will not
 *    deliver broadcasts until the user launches the app again. That is
 *    platform behaviour; there is no supported workaround and none is
 *    attempted here.
 *  - DEVICE REBOOTED: AlarmManager alarms do not survive reboot. RECEIVE_BOOT_COMPLETED
 *    is deliberately NOT requested, because re-arming at boot would schedule a
 *    wake-up into a process that is not running, which this mechanism cannot
 *    serve anyway — it would be a permission that buys nothing. Scheduling is
 *    instead reconstructed on the next app launch by the existing A.4 request,
 *    which reads the outbox and asks again.
 *  - DOZE: `setAndAllowWhileIdle` is rate-limited by the OS (historically to
 *    roughly one delivery per app per 9 minutes while idle) and alarms are
 *    batched, so delivery is best-effort and may be LATE. That is safe by
 *    construction: `notBefore` is advisory and the Dart claim query admits
 *    only rows whose `next_attempt_at` has already passed.
 */
object BackgroundSyncProducer {

    private const val TAG = "FkssBackgroundSync"

    /** Must equal `BackgroundSyncChannel.name` in Dart. */
    const val CHANNEL = "fkss.app/background_sync"

    /** Dart -> native. */
    private const val METHOD_ENSURE_SCHEDULED = "ensureScheduled"
    private const val METHOD_CANCEL = "cancel"

    /** Native -> Dart. */
    private const val METHOD_RUN = "runBackgroundSync"

    private const val KEY_UNIQUE_WORK_NAME = "uniqueWorkName"
    private const val KEY_NOT_BEFORE = "notBeforeEpochMs"
    private const val KEY_REQUIRES_NETWORK = "requiresNetwork"
    private const val KEY_SOURCE = "source"
    private const val KEY_INVOCATION_ID = "invocationId"

    /**
     * The one and only provenance value this producer may send. It is an
     * explicit field in the message, never inferred by Dart from the thread,
     * the component, the lifecycle or the timing. Dart rejects any other value.
     */
    private const val SOURCE_BACKGROUND = "background"

    /** Explicit action, so the PendingIntent can never match anything else. */
    const val ACTION_WAKE = "com.arkeonethiopia.fkss.action.BACKGROUND_SYNC"

    /**
     * ONE request code means ONE alarm slot. Combined with FLAG_UPDATE_CURRENT
     * this is what makes [schedule] idempotent and self-replacing: calling it
     * ten times leaves exactly one pending alarm, and a newer `notBefore`
     * replaces an older one rather than queuing beside it. This is the
     * OS-level counterpart of `BackgroundSyncRequest.uniqueWorkName`.
     */
    private const val ALARM_REQUEST_CODE = 0x5F55

    /**
     * How long the receiver may hold the broadcast open. Kept under the ~10s
     * soft limit Android gives a BroadcastReceiver. The Dart drain is NOT
     * bounded by this: once invoked it continues on the live engine's event
     * loop. Finishing early only releases the broadcast's wakelock.
     */
    private const val BROADCAST_HOLD_MS = 8_000L

    /**
     * The live engine's channel, or null when no FlutterEngine exists.
     * Set by MainActivity.configureFlutterEngine and cleared when the engine
     * goes away, so a stale channel can never be invoked.
     */
    @Volatile
    private var channel: MethodChannel? = null

    fun attach(channel: MethodChannel) {
        this.channel = channel
    }

    fun detach(channel: MethodChannel) {
        // Compare identity so a late teardown of an old engine cannot unbind
        // a newer one.
        if (this.channel === channel) this.channel = null
    }

    /** True when a Dart entry point is reachable right now. */
    fun hasLiveEngine(): Boolean = channel != null

    // ── Dart -> native ──────────────────────────────────────────────────

    fun handle(context: Context, call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            METHOD_ENSURE_SCHEDULED -> {
                try {
                    // `uniqueWorkName` is accepted and logged rather than used
                    // as a key: this mechanism has exactly one slot, and
                    // honouring an arbitrary name would invite a second
                    // logical unit of background work that the architecture
                    // does not have.
                    val workName = call.argument<String>(KEY_UNIQUE_WORK_NAME)
                    val notBefore = readEpochMillis(call.argument<Any>(KEY_NOT_BEFORE))
                    val requiresNetwork = call.argument<Boolean>(KEY_REQUIRES_NETWORK) ?: true
                    schedule(context, notBefore, requiresNetwork, workName)
                    result.success(null)
                } catch (e: Exception) {
                    // Reported, not swallowed: the Dart coordinator resets its
                    // pending flag on a PlatformException so the next drain
                    // re-requests instead of believing in an opportunity the
                    // OS never took.
                    result.error("schedule_failed", e.message, null)
                }
            }
            METHOD_CANCEL -> {
                try {
                    cancel(context)
                    result.success(null)
                } catch (e: Exception) {
                    result.error("cancel_failed", e.message, null)
                }
            }
            else -> result.notImplemented()
        }
    }

    /**
     * Accepts Int or Long, because the platform-channel codec narrows small
     * numbers to Int. Returning null for anything else is deliberate: an
     * unparseable time becomes "as soon as allowed", never a crash and never a
     * silently wrong far-future alarm.
     */
    private fun readEpochMillis(raw: Any?): Long? = when (raw) {
        is Long -> raw
        is Int -> raw.toLong()
        else -> null
    }

    private fun wakeIntent(context: Context): PendingIntent {
        val intent = Intent(context, BackgroundSyncReceiver::class.java).apply {
            action = ACTION_WAKE
            setPackage(context.packageName)
        }
        var flags = PendingIntent.FLAG_UPDATE_CURRENT
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags = flags or PendingIntent.FLAG_IMMUTABLE
        }
        return PendingIntent.getBroadcast(context, ALARM_REQUEST_CODE, intent, flags)
    }

    fun schedule(
        context: Context,
        notBeforeEpochMs: Long?,
        requiresNetwork: Boolean,
        uniqueWorkName: String? = null,
    ) {
        val alarms = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        val now = System.currentTimeMillis()

        // A past or missing `notBefore` means "as soon as the OS allows". The
        // clamp is the only arithmetic this class performs on time, and it
        // cannot move a wake-up later than Dart asked for.
        val triggerAt = if (notBeforeEpochMs == null || notBeforeEpochMs < now) now
        else notBeforeEpochMs

        val pending = wakeIntent(context)

        // setAndAllowWhileIdle, not setExactAndAllowWhileIdle: inexact is
        // sufficient because `notBefore` is advisory and the claim query
        // decides eligibility, and inexact needs no SCHEDULE_EXACT_ALARM
        // permission. RTC_WAKEUP because the outbox's next attempt time is
        // wall-clock, not uptime.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            alarms.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, pending)
        } else {
            alarms.set(AlarmManager.RTC_WAKEUP, triggerAt, pending)
        }

        Log.i(
            TAG,
            "scheduled work=$uniqueWorkName in=${triggerAt - now}ms " +
                "requiresNetwork=$requiresNetwork (advisory: AlarmManager " +
                "cannot enforce a network constraint; Dart performs the " +
                "final network and session checks)"
        )
    }

    fun cancel(context: Context) {
        val alarms = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        val pending = wakeIntent(context)
        alarms.cancel(pending)
        // Also drop the PendingIntent itself, so a later cancel/schedule pair
        // cannot resurrect a stale one.
        pending.cancel()
        Log.i(TAG, "cancelled pending opportunity")
    }

    // ── native -> Dart ──────────────────────────────────────────────────

    /**
     * Invoked by [BackgroundSyncReceiver]. Hands the opportunity to the Dart
     * entry point and returns.
     *
     * [onFinished] is the broadcast's own completion, not the drain's. It runs
     * when Dart acknowledges or when [BROADCAST_HOLD_MS] elapses, whichever is
     * first, and is guaranteed to run exactly once.
     */
    fun deliver(onFinished: () -> Unit) {
        val target = channel
        if (target == null) {
            // Process was started by this broadcast and no FlutterEngine
            // exists. Do nothing, and deliberately do NOT re-arm: that would
            // be a wake loop with no progress. The work is durable and drains
            // on next launch.
            Log.i(TAG, "woke with no live FlutterEngine; nothing to do")
            onFinished()
            return
        }

        val finished = java.util.concurrent.atomic.AtomicBoolean(false)
        val finishOnce = {
            if (finished.compareAndSet(false, true)) onFinished()
        }
        val watchdog = Handler(Looper.getMainLooper())
        watchdog.postDelayed({ finishOnce() }, BROADCAST_HOLD_MS)

        val arguments = mapOf(
            // EXPLICIT provenance. This single field is the entire contract;
            // Dart refuses the call if it says anything but "background".
            KEY_SOURCE to SOURCE_BACKGROUND,
            KEY_INVOCATION_ID to UUID.randomUUID().toString(),
        )

        target.invokeMethod(METHOD_RUN, arguments, object : MethodChannel.Result {
            override fun success(result: Any?) {
                Log.i(TAG, "background drain acknowledged: $result")
                watchdog.removeCallbacksAndMessages(null)
                finishOnce()
            }

            override fun error(code: String, message: String?, details: Any?) {
                Log.w(TAG, "background drain rejected: $code $message")
                watchdog.removeCallbacksAndMessages(null)
                finishOnce()
            }

            override fun notImplemented() {
                // Dart side of the bridge is not installed in this build.
                Log.w(TAG, "background entry point not implemented")
                watchdog.removeCallbacksAndMessages(null)
                finishOnce()
            }
        })
    }
}
