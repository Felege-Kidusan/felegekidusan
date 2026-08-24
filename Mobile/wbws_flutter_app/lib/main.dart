import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'services/api_service.dart';
import 'services/catalog_service.dart';
import 'services/local_db.dart';
import 'services/sync_service.dart';
import 'services/connectivity_service.dart';
import 'services/app_update_service.dart';
import 'services/warm_store.dart';
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

  try {
    await ApiService().init();
    await LocalDb().database;
    if (ApiService().discardedInvalidSession) {
      await LocalDb().clearAllUserData();
    }
    await CatalogService().hydrate();
  } catch (_) {
    // Never reset or expose encrypted offline student work after a key/storage
    // failure. A generic recovery screen is safer than a blank crash or data loss.
    runApp(const OfflineDataProtectionFailureApp());
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

class OfflineDataProtectionFailureApp extends StatelessWidget {
  const OfflineDataProtectionFailureApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      home: const Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.security_rounded,
                    size: 56,
                    color: AppTheme.primary,
                  ),
                  SizedBox(height: 20),
                  Text(
                    'Offline data is protected',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  SizedBox(height: 12),
                  Text(
                    'The secure data store could not be opened. Restart the app after unlocking the device. If this continues, contact the school administrator before reinstalling because reinstalling removes unsynced work.',
                    textAlign: TextAlign.center,
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
      home: home,
    );
  }
}
