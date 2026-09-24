import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:just_audio_background/just_audio_background.dart';
import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart' show getDatabasesPath;
import 'services/app_lock_service.dart';
import 'services/local_db.dart';
import 'services/session_models.dart';
import 'services/session_service.dart';
import 'screens/lock/lock_screen.dart';
import 'services/connectivity_service.dart';
import 'services/app_update_service.dart';
import 'services/device_tier_service.dart';
import 'services/app_navigator.dart';
import 'services/mezmur_download_manager.dart';
import 'services/lyrics_reader_settings.dart';
import 'utils/scrolling.dart';
import 'utils/theme.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/session_recovery_screen.dart';
import 'screens/shell/app_shell.dart';
import 'screens/update/update_screen.dart';
import 'screens/mezmur/mezmur_mini_player_host.dart';
import 'screens/profile/sync_center_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // P0 background mezmur playback: register the single auto-managed
  // player channel BEFORE runApp so audio_service can connect. Kept
  // tolerant — on a platform without the plugin the app still starts.
  try {
    await JustAudioBackground.init(
      androidNotificationChannelId: 'com.arkeonethiopia.fkss.channel.audio',
      androidNotificationChannelName: 'መዝሙር · FKSS Mezmur',
      androidNotificationOngoing: true,
    );
  } catch (_) {}

  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      systemNavigationBarColor: AppTheme.bgLight,
      systemNavigationBarIconBrightness: Brightness.dark,
    ),
  );

  await runBootstrap();
}

/// Runs the startup sequence. Extracted so the recovery screen can retry
/// without restarting the process.
Future<void> runBootstrap() async {
  try {
    // SQLite opens/migrates before credentials are interpreted. The session
    // coordinator then reconciles the durable owner marker, protected
    // credentials and a single-snapshot local inventory.
    await LocalDb().database;
    await SessionCoordinator().bootstrap();
    // App Lock protects active AND recoverable private state. The PIN is not
    // cleared by auth expiry or preserve-for-reauth logout.
    await AppLockService().lockAtColdStartIfConfigured();
  } catch (error, stack) {
    final detail = await _writeBootstrapLog(error, stack);
    runApp(OfflineDataProtectionFailureApp(detail: detail));
    return;
  }

  runApp(const FKSSApp());

  WidgetsBinding.instance.addPostFrameCallback((_) {
    // Device-global services may start independently. Account-scoped workers
    // are started only by SessionCoordinator after active owner reconciliation.
    ConnectivityService().startMonitoring();
    LyricsReaderSettings.instance.boot();
    DeviceTierService.instance.boot();
    if (SessionCoordinator().isActive) {
      Future<void>.delayed(const Duration(seconds: 2), () {
        if (SessionCoordinator().isActive) {
          MezmurDownloadManager.instance.boot();
        }
      });
    }
  });
}

/// Appends the real error to a log inside the app sandbox and returns a
/// short, screen-safe summary the user can send to the school administrator.
Future<String> _writeBootstrapLog(Object error, StackTrace stack) async {
  var summary = '$error';
  try {
    final dbPath = await getDatabasesPath();
    final logFile = File(join(dbPath, 'fkss_bootstrap_error.log'));
    await logFile.writeAsString(
      '=== ${DateTime.now().toIso8601String()} ===\n$error\n$stack\n',
      mode: FileMode.append,
      flush: true,
    );
  } catch (_) {}
  summary = summary.replaceAll('\n', ' ').trim();
  if (summary.length > 160) summary = '${summary.substring(0, 160)}…';
  return summary;
}

class OfflineDataProtectionFailureApp extends StatefulWidget {
  final String detail;
  const OfflineDataProtectionFailureApp({super.key, required this.detail});

  @override
  State<OfflineDataProtectionFailureApp> createState() =>
      _OfflineDataProtectionFailureAppState();
}

class _OfflineDataProtectionFailureAppState
    extends State<OfflineDataProtectionFailureApp> {
  bool _retrying = false;

  Future<void> _retry() async {
    setState(() => _retrying = true);
    await runBootstrap(); // replaces this app on success
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      scrollBehavior: const SmoothScrollBehavior(),
      home: Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.security_rounded,
                    size: 56,
                    color: AppTheme.primary,
                  ),
                  const SizedBox(height: 20),
                  Text(
                    'Offline storage could not be opened',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    'The app could not open its offline storage on this phone. '
                    'Your data has NOT been deleted. Free up some phone storage '
                    'and tap Retry. If it continues, send the detail line below '
                    'to the school administrator.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _retrying ? null : _retry,
                    icon: const Icon(Icons.refresh),
                    label: Text(_retrying ? 'Retrying…' : 'Retry'),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    widget.detail,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class FKSSApp extends StatefulWidget {
  const FKSSApp({super.key});

  @override
  State<FKSSApp> createState() => _FKSSAppState();
}

class _FKSSAppState extends State<FKSSApp> {
  final _appLock = AppLockService();
  final _session = SessionCoordinator();

  @override
  void initState() {
    super.initState();
    _appLock.addListener(_onRootStateChanged);
    _session.addListener(_onRootStateChanged);
    AppUpdateService().check().then((_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _appLock.removeListener(_onRootStateChanged);
    _session.removeListener(_onRootStateChanged);
    super.dispose();
  }

  void _onRootStateChanged() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final update = AppUpdateService();

    Widget home;
    if (_appLock.isLocked && _session.protectsPrivateState) {
      // Passcode gate sits in front of active and recoverable private data.
      home = const LockScreen();
    } else if (update.decision.force) {
      home = const UpdateScreen(blocking: true);
    } else {
      switch (_session.root) {
        case SessionRoot.active:
          home = AppShell(key: ValueKey(_session.generation));
          break;
        case SessionRoot.reauthentication:
          home = const SessionRecoveryScreen();
          break;
        case SessionRoot.orphanRecovery:
          home = const OrphanedDataRecoveryScreen();
          break;
        case SessionRoot.scopeReconciling:
          home = const ScopeReconciliationScreen();
          break;
        case SessionRoot.purging:
          home = const PurgingSessionScreen();
          break;
        case SessionRoot.protectionFailure:
          home = const SessionProtectionFailureScreen();
          break;
        case SessionRoot.cleanLogin:
          home = const LoginScreen();
          break;
      }
    }

    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: ThemeMode.light,
      scrollBehavior: const SmoothScrollBehavior(),
      // P35: the mini player is mounted above the Navigator, so
      // Navigator.of(its context) finds no ancestor. This key lets it
      // push the full player on the root navigator.
      navigatorKey: AppNavigator.key,
      // P34: the now-playing bar is mounted ABOVE the Navigator so it
      // follows the user across every pushed route and consumes real
      // layout height (never covers FABs or save bars).
      builder: (context, navigator) =>
          MezmurMiniPlayerHost(child: navigator ?? const SizedBox.shrink()),
      routes: {
        '/sync-center': (_) => const SyncCenterScreen(),
      },
      home: home,
    );
  }
}
