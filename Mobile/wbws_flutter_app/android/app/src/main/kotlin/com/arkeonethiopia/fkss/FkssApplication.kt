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
        // Bounded: same cap the Dart reader assumes. When full, keep the
        // newest half instead of wiping — a crash loop must not erase the
        // identities of prior crashes that never got a launch to report them.
        if (log.exists() && log.length() > MAX_LOG_BYTES) {
            trimLogTail(log)
        }
        // Header format is the contract the Dart parser knows:
        // `=== CRASH <epochMillis> ===`
        val line = System.lineSeparator()
        log.appendText(
            "=== CRASH ${System.currentTimeMillis()} ===$line" +
                "${t.stackTraceToString()}$line$line"
        )
    }

    /**
     * Keeps the newest half of the log, cut at a section boundary so the
     * Dart parser never sees a truncated header line. The trap must never
     * throw; on any failure this falls back to the legacy bounded full reset
     * (still capped, still crash-delivery-safe).
     */
    private fun trimLogTail(log: File) {
        try {
            val bytes = log.readBytes()
            val keep = (MAX_LOG_BYTES / 2).toInt()
            if (bytes.size <= keep) return
            val window = bytes.copyOfRange(bytes.size - keep, bytes.size)
            // Align the cut to the next section header (a line starting with
            // `=== `), the boundary crash_log_service.dart parses on.
            var cut = -1
            var i = 0
            while (i + 5 <= window.size) {
                if (window[i] == '\n'.code.toByte() &&
                    window[i + 1] == '='.code.toByte() &&
                    window[i + 2] == '='.code.toByte() &&
                    window[i + 3] == '='.code.toByte() &&
                    window[i + 4] == ' '.code.toByte()
                ) {
                    cut = i + 1
                    break
                }
                i++
            }
            log.writeBytes(if (cut >= 0) window.copyOfRange(cut, window.size) else window)
        } catch (_: Throwable) {
            log.delete()
        }
    }

    companion object {
        const val LOG_NAME = "fkss_bootstrap_error.log"
        const val MAX_LOG_BYTES = 1L shl 20 // 1 MiB
    }
}
