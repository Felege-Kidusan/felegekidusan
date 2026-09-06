package com.arkeonethiopia.fkss

import android.app.Application
import java.io.File

/**
 * P65 — the native-side crash trap.
 *
 * The app is distributed outside Play (self-updated APK), so there is no
 * Play Console to collect crashes; field reports were arriving as "it
 * opens and closes" with no stack trace. This handler appends every
 * uncaught Java/Kotlin exception (the class of failure behind the
 * 32-bit-device launch crashes, e.g. UnsatisfiedLinkError) to the same
 * `fkss_bootstrap_error.log` the Dart bootstrap writer uses, so one file
 * holds the app's whole failure history and the in-app Diagnostics screen
 * (Profile → App → Diagnostics) can show and copy it.
 *
 * Native renderer/engine crashes (SIGSEGV inside libflutter.so) are NOT
 * catchable here — those still need `adb logcat` on the device; see
 * docs/RELEASE.md.
 *
 * Hard rules:
 *  - ALWAYS delegate to the platform's previous handler afterwards;
 *    swallowing the exception would leave the process alive-but-dead.
 *  - The trap itself must never throw.
 *  - Log content is stack traces only — no user data, no tokens.
 */
class FkssApplication : Application() {

    override fun onCreate() {
        super.onCreate()
        val systemHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                appendCrash(throwable)
            } catch (_: Throwable) {
                // The trap must never break crash delivery.
            }
            // Delegate: the platform handler performs the actual process
            // teardown (crash dialog / kill).
            systemHandler?.uncaughtException(thread, throwable)
        }
    }

    private fun appendCrash(t: Throwable) {
        val log = getDatabasePath(LOG_NAME)
        log.parentFile?.mkdirs()
        // Bounded: same cap the Dart reader assumes; rotate when full so
        // a crash loop cannot fill the sandbox.
        if (log.exists() && log.length() > MAX_LOG_BYTES) {
            log.delete()
        }
        // Header format is the contract the Dart parser knows:
        // `=== CRASH <epochMillis> ===`
        val line = System.lineSeparator()
        log.appendText(
            "=== CRASH ${System.currentTimeMillis()} ===$line" +
                "${t.stackTraceToString()}$line$line"
        )
    }

    companion object {
        const val LOG_NAME = "fkss_bootstrap_error.log"
        const val MAX_LOG_BYTES = 1L shl 20 // 1 MiB
    }
}
