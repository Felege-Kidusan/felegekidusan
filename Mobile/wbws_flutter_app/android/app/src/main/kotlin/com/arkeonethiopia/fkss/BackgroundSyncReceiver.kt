package com.arkeonethiopia.fkss

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * S3 — A.8. Receives the AlarmManager wake-up and hands it to Dart.
 *
 * Declared `android:exported="false"` in the manifest: nothing outside this
 * app may trigger a sync. The action is checked anyway, so a broadcast that
 * somehow reached us with the wrong intent is ignored rather than acted on.
 *
 * `goAsync()` keeps the broadcast's wakelock held while Dart starts the
 * drain, which matters because the device may be in Doze when the alarm
 * fires. It is released as soon as Dart acknowledges, or after
 * BROADCAST_HOLD_MS, whichever comes first. The DRAIN is not bounded by it:
 * once invoked, the drain continues on the live engine's event loop. This
 * receiver never waits for sync to finish, and never learns whether it
 * succeeded.
 *
 * This class contains no sync logic of any kind by design — no database, no
 * claim, no retry, no session, no connectivity check.
 */
class BackgroundSyncReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != BackgroundSyncProducer.ACTION_WAKE) return

        val pendingResult = goAsync()
        try {
            BackgroundSyncProducer.deliver(context) { pendingResult.finish() }
        } catch (t: Throwable) {
            // A receiver that throws takes the process down with it, and the
            // P65 crash trap would record it as an app crash. A wake-up that
            // fails is simply a missed opportunity: the work stays durable
            // and the next drain reschedules.
            pendingResult.finish()
        }
    }
}
