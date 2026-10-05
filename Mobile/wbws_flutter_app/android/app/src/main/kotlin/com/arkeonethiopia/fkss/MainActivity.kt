package com.arkeonethiopia.fkss

import android.app.ActivityManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.view.WindowManager
import androidx.core.content.FileProvider
// FlutterFragmentActivity (not FlutterActivity): required by
// local_auth so the App Lock can use BiometricPrompt / fingerprint.
// AudioServiceFragmentActivity extends FlutterFragmentActivity and adds
// the audio_service bindings that keep P0 background mezmur playback
// alive while the app is in the background.
import com.ryanheise.audioservice.AudioServiceFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

class MainActivity : AudioServiceFragmentActivity() {
    private val channelName = "fkss.app/updater"
    private val lockChannelName = "fkss.app/app_lock"
    private val deviceChannelName = "fkss.app/device"

    /**
     * S3 A.8 — kept so the engine's background-sync channel can be unbound
     * when the engine goes away. A stale channel must never be invoked by a
     * later alarm.
     */
    private var backgroundSyncChannel: MethodChannel? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // S3 A.8 — the background sync producer. Both directions of the
        // contract live on this one channel: Dart asks AlarmManager to
        // schedule or cancel an opportunity, and when the alarm fires
        // BackgroundSyncReceiver invokes the Dart entry point back through
        // the same channel. No sync logic is implemented natively; see
        // BackgroundSyncProducer for the architectural rules it obeys.
        //
        // applicationContext, not `this`: the PendingIntent and the alarm
        // must outlive this Activity.
        val syncChannel =
            MethodChannel(flutterEngine.dartExecutor.binaryMessenger, BackgroundSyncProducer.CHANNEL)
        syncChannel.setMethodCallHandler { call, result ->
            BackgroundSyncProducer.handle(applicationContext, call, result)
        }
        BackgroundSyncProducer.attach(syncChannel)
        backgroundSyncChannel = syncChannel

        // P65 — device capability snapshot (ABI, RAM class, OS): drives
        // the low-end device tier (image-cache budgets) and the ABI-aware
        // update download, and surfaces in Profile → Diagnostics. Read
        // only; nothing here is sensitive.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, deviceChannelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "info" -> {
                        try {
                            val am = getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
                            val mi = ActivityManager.MemoryInfo()
                            am.getMemoryInfo(mi)
                            result.success(
                                mapOf(
                                    "abi" to (Build.SUPPORTED_ABIS.firstOrNull() ?: ""),
                                    "abis" to Build.SUPPORTED_ABIS.toList(),
                                    "totalRamMb" to (mi.totalMem / (1024L * 1024L)).toInt(),
                                    "isLowRam" to am.isLowRamDevice,
                                    "sdkInt" to Build.VERSION.SDK_INT,
                                    "release" to (Build.VERSION.RELEASE ?: ""),
                                    "model" to (Build.MODEL ?: ""),
                                    "manufacturer" to (Build.MANUFACTURER ?: ""),
                                )
                            )
                        } catch (e: Exception) {
                            result.error("device_info_failed", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }

        // Telegram-style privacy: while a passcode is configured the app
        // content must not appear in the OS recent-apps preview.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, lockChannelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "setSecureFlag" -> {
                        val on = call.argument<Boolean>("on") ?: false
                        if (on) {
                            window.setFlags(
                                WindowManager.LayoutParams.FLAG_SECURE,
                                WindowManager.LayoutParams.FLAG_SECURE
                            )
                        } else {
                            window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                        }
                        result.success(true)
                    }
                    else -> result.notImplemented()
                }
            }
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "updateDir" -> {
                        val dir = File(cacheDir, "updates")
                        if (!dir.exists()) dir.mkdirs()
                        result.success(dir.absolutePath)
                    }
                    "canRequestPackageInstalls" -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            result.success(packageManager.canRequestPackageInstalls())
                        } else {
                            result.success(true)
                        }
                    }
                    "openInstallPermissionSettings" -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            try {
                                val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES).apply {
                                    data = Uri.parse("package:$packageName")
                                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                }
                                startActivity(intent)
                                result.success(true)
                            } catch (e: Exception) {
                                val fallbackIntent = Intent(Settings.ACTION_SECURITY_SETTINGS).apply {
                                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                }
                                startActivity(fallbackIntent)
                                result.success(true)
                            }
                        } else {
                            result.success(true)
                        }
                    }
                    "installApk" -> {
                        val path = call.argument<String>("path")
                        if (path.isNullOrBlank()) {
                            result.error("bad_path", "Missing path", null)
                            return@setMethodCallHandler
                        }
                        try {
                            val file = File(path)
                            if (!file.exists()) {
                                result.error("missing", "APK not found", null)
                                return@setMethodCallHandler
                            }
                            val uri = FileProvider.getUriForFile(
                                this,
                                "$packageName.fileprovider",
                                file
                            )
                            val intent = Intent(Intent.ACTION_VIEW).apply {
                                setDataAndType(uri, "application/vnd.android.package-archive")
                                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            }
                            startActivity(intent)
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("install_failed", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }

    /**
     * S3 A.8 — unbind before the engine is destroyed, so an alarm that fires
     * after the app's UI is gone finds no channel and does nothing, instead
     * of invoking a dead isolate.
     */
    override fun cleanUpFlutterEngine(flutterEngine: FlutterEngine) {
        backgroundSyncChannel?.let {
            it.setMethodCallHandler(null)
            BackgroundSyncProducer.detach(it)
        }
        backgroundSyncChannel = null
        super.cleanUpFlutterEngine(flutterEngine)
    }
}
