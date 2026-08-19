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

  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.dark,
    systemNavigationBarColor: AppTheme.bgLight,
    systemNavigationBarIconBrightness: Brightness.dark,
  ));

  await ApiService().init();
  await LocalDb().database;
  await CatalogService().hydrate();

  runApp(const FKSSApp());

  WidgetsBinding.instance.addPostFrameCallback((_) {
    ConnectivityService().startMonitoring();
    if (ApiService().isLoggedIn) {
      SyncService().startAutoSync();
      WarmStore().afterLogin();
    }
  });
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
