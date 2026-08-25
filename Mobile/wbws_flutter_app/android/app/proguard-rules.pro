# WBSS Flutter App — ProGuard Rules

# ── Flutter Engine ──
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

# ── Flutter Secure Storage ──
-keep class com.it_nomads.fluttersecurestorage.** { *; }

# ── HTTP client ──
-keep class org.apache.http.** { *; }
-dontwarn org.apache.http.**

# ── SQLite (sandboxed local cache; at-rest protected by OS device encryption) ──
-keep class io.flutter.plugins.sqflite.** { *; }

# ── Google Play Core (Flutter references these for deferred components) ──
-dontwarn com.google.android.play.core.splitinstall.**
-dontwarn com.google.android.play.core.tasks.**
-dontwarn com.google.android.play.core.**

# ── Kotlin ──
-dontwarn kotlin.**
-dontwarn kotlinx.**

# ── Missing annotations (common R8 warnings) ──
-dontwarn javax.annotation.**
-dontwarn org.checkerframework.**
-dontwarn com.google.errorprone.**
