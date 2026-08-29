import 'package:flutter/material.dart';
import '../../utils/transitions.dart';
import '../../services/api_service.dart';
import '../../services/sync_service.dart';
import '../../services/warm_store.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../shell/app_shell.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _loading = false;
  bool _obscurePassword = true;
  String? _error;

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    final username = _usernameController.text.trim();
    final password = _passwordController.text;

    if (username.isEmpty || password.isEmpty) {
      setState(() => _error = 'Please enter username and password');
      return;
    }

    setState(() {
      _loading = true;
      _error = null;
    });

    final api = ApiService();
    final res = await api.login(username, password);

    if (!mounted) return;

    if (res.success) {
      // Start background services
      SyncService().startAutoSync();
      WarmStore().afterLogin();

      Navigator.of(context).pushAndRemoveUntil(
        SmoothPageRoute(page: const AppShell()),
        (route) => false,
      );
    } else {
      String errorMsg = res.message ?? 'Login failed. Please try again.';

      // Give a clearer message for network errors
      if (res.isNetworkError) {
        errorMsg =
            'Cannot reach the server. Check your internet connection and try again.';
      }

      setState(() {
        _loading = false;
        _error = errorMsg;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // Logo — the Sunday School emblem (bundled asset).
                Container(
                  width: 120,
                  height: 120,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(24),
                    boxShadow: [
                      BoxShadow(
                        color: AppTheme.primary.withOpacity(0.1),
                        blurRadius: 20,
                        offset: const Offset(0, 10),
                      ),
                    ],
                    border: Border.all(color: AppTheme.accent, width: 2),
                  ),
                  child: Center(
                    child: Padding(
                      padding: const EdgeInsets.all(8),
                      child: Image.asset('assets/logo_school.png',
                          width: 100,
                          height: 100,
                          fit: BoxFit.contain,
                          semanticLabel: 'Spring of Saints Sunday School'),
                    ),
                  ),
                ),

                const SizedBox(height: 20),

                // Title
                const Text(
                  AppConfig.appNameAmharic,
                  style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: AppTheme.primary,
                      fontFamily: 'NotoSansEthiopic'),
                ),
                const SizedBox(height: 6),
                Text(
                  'Spring of Saints Sunday School',
                  style: TextStyle(fontSize: 14, color: AppTheme.textSecondary, fontWeight: FontWeight.w500),
                ),

                const SizedBox(height: 40),

                // Username
                TextField(
                  controller: _usernameController,
                  decoration: const InputDecoration(
                    hintText: 'Username',
                    prefixIcon: Icon(Icons.person_outline, size: 20),
                  ),
                  textInputAction: TextInputAction.next,
                  autocorrect: false,
                ),

                const SizedBox(height: 14),

                // Password
                TextField(
                  controller: _passwordController,
                  obscureText: _obscurePassword,
                  decoration: InputDecoration(
                    hintText: 'Password',
                    prefixIcon: const Icon(Icons.lock_outline, size: 20),
                    suffixIcon: IconButton(
                      icon: Icon(
                          _obscurePassword
                              ? Icons.visibility_off
                              : Icons.visibility,
                          size: 20),
                      onPressed: () =>
                          setState(() => _obscurePassword = !_obscurePassword),
                    ),
                  ),
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _login(),
                ),

                const SizedBox(height: 10),

                // Error message
                if (_error != null)
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: AppTheme.danger.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(10),
                      border:
                          Border.all(color: AppTheme.danger.withOpacity(0.3)),
                    ),
                    child: Row(
                      children: [
                        Icon(
                          _error!.contains('server') || _error!.contains('internet')
                              ? Icons.cloud_off
                              : Icons.error_outline,
                          size: 18,
                          color: AppTheme.danger,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(_error!,
                              style: const TextStyle(
                                  color: AppTheme.danger, fontSize: 13)),
                        ),
                      ],
                    ),
                  ),

                const SizedBox(height: 20),

                // Login button
                SizedBox(
                  width: double.infinity,
                  height: 50,
                  child: ElevatedButton(
                    onPressed: _loading ? null : _login,
                    child: _loading
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(
                                strokeWidth: 2.5, color: AppTheme.primaryDark))
                        : const Text('Login'),
                  ),
                ),

                const SizedBox(height: 30),

                Text(
                  'v${AppConfig.appVersion}',
                  style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

