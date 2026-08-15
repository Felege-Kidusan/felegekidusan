import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'services/api_service.dart';
import 'services/local_db.dart';
import 'services/sync_service.dart';
import 'services/connectivity_service.dart';
import 'utils/theme.dart';
import 'screens/auth/login_screen.dart';
import 'screens/shell/app_shell.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Lock orientation — fast, non-blocking
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  // Set status bar style to match light theme immediately
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.dark,
    systemNavigationBarColor: AppTheme.bgLight,
    systemNavigationBarIconBrightness: Brightness.dark,
  ));

  // Only await the fast local reads — NO network calls before first frame
  await ApiService().init();  // ~50ms — reads encrypted storage
  await LocalDb().database;  // ~100ms — opens SQLite

  // Show UI IMMEDIATELY — don't wait for network
  runApp(const FKSSApp());

  // Start network services AFTER first frame is on screen
  WidgetsBinding.instance.addPostFrameCallback((_) {
    ConnectivityService().startMonitoring();
    if (ApiService().isLoggedIn) {
      SyncService().startAutoSync();
      SyncService().cacheForOffline();
    }
  });
}

class FKSSApp extends StatelessWidget {
  const FKSSApp({super.key});

  @override
  Widget build(BuildContext context) {
    final api = ApiService();

    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: ThemeMode.light,
      home: api.isLoggedIn ? const AppShell() : const LoginScreen(),
    );
  }
}
