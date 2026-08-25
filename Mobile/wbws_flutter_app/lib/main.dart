import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart' show getDatabasesPath;
import 'services/api_service.dart';
import 'services/catalog_service.dart';
import 'services/local_db.dart';
import 'services/sync_service.dart';
import 'services/connectivity_service.dart';
import 'services/app_update_service.dart';
import 'services/warm_store.dart';
import 'utils/scrolling.dart';
import 'utils/theme.dart';
import 'screens/auth/login_screen.dart';
import 'screens/shell/app_shell.dart';
import 'screens/update/update_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

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
    await ApiService().init();
    await LocalDb().database;
    if (ApiService().discardedInvalidSession) {
      await LocalDb().clearAllUserData();
    }
    await CatalogService().hydrate();
  } catch (error, stack) {
    // Local offline storage is the only genuinely blocking part. Never
    // reset or expose it; write the real error for diagnosis and offer a
    // retry. With OS-protected storage this screen is rare (full/corrupt
    // phone storage) rather than a key/migration failure.
    final detail = await _writeBootstrapLog(error, stack);
    runApp(OfflineDataProtectionFailureApp(detail: detail));
    return;
  }

  runApp(const FKSSApp());

  WidgetsBinding.instance.addPostFrameCallback((_) {
    // OS radio only — no HTTP ping. Warm and sync wait so Home can use 4G first.
    ConnectivityService().startMonitoring();
    if (ApiService().isLoggedIn) {
      Future<void>.delayed(const Duration(seconds: 2), () {
        WarmStore().afterLogin();
      });
      SyncService().startAutoSync();
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
  @override
  void initState() {
    super.initState();
    AppUpdateService().check().then((_) {
      if (mounted) setState(() {});
    });
  }

  @override
  Widget build(BuildContext context) {
    final api = ApiService();
    final update = AppUpdateService();

    Widget home;
    if (update.decision.force) {
      home = const UpdateScreen(blocking: true);
    } else {
      home = api.isLoggedIn ? const AppShell() : const LoginScreen();
    }

    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: ThemeMode.light,
      scrollBehavior: const SmoothScrollBehavior(),
      home: home,
    );
  }
}
