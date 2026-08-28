import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/app_lock_service.dart';
import '../../services/session_service.dart';
import '../../utils/theme.dart';

/// Full-screen passcode gate (Telegram-style). Shown on cold start when
/// a passcode is set, after auto-lock, and on manual lock. The rest of
/// the app is not mounted behind it — there is nothing to peek at.
class LockScreen extends StatefulWidget {
  const LockScreen({super.key});
  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen>
    with SingleTickerProviderStateMixin {
  final _lock = AppLockService();
  String _entered = '';
  bool _checking = false;
  bool _biometricAvailable = false;
  String? _error;
  late AnimationController _shakeCtrl;
  late Animation<double> _shakeAnim;

  @override
  void initState() {
    super.initState();
    _shakeCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 380),
    );
    _shakeAnim = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _shakeCtrl, curve: Curves.elasticIn),
    );
    _initBiometric();
  }

  Future<void> _initBiometric() async {
    final enabled = await _lock.biometricEnabled();
    if (!enabled || !mounted) return;
    setState(() => _biometricAvailable = true);
    // Offer the fingerprint immediately, like Telegram.
    await _tryBiometric();
  }

  Future<void> _tryBiometric() async {
    if (_checking) return;
    setState(() => _checking = true);
    final ok = await _lock.authenticateWithBiometrics();
    if (!mounted) return;
    setState(() => _checking = false);
    if (ok) _onUnlocked();
  }

  @override
  void dispose() {
    _shakeCtrl.dispose();
    super.dispose();
  }

  void _onUnlocked() {
    HapticFeedback.selectionClick();
    // No navigation needed: markUnlocked() notifies the app gate in
    // main.dart, which swaps this screen for the shell.
  }

  Future<void> _press(String key) async {
    if (_checking) return;
    HapticFeedback.selectionClick();
    if (key == 'back') {
      setState(() {
        if (_entered.isNotEmpty) _entered = _entered.substring(0, _entered.length - 1);
        _error = null;
      });
      return;
    }
    if (_entered.length >= 8) return;
    setState(() {
      _entered += key;
      _error = null;
    });
    if (_entered.length >= 4) {
      await _verify();
    }
  }

  Future<void> _verify() async {
    setState(() => _checking = true);
    final ok = await _lock.verifyPin(_entered);
    if (!mounted) return;
    if (ok) {
      _onUnlocked();
      return;
    }
    setState(() {
      _checking = false;
      _entered = '';
      _error = 'Wrong passcode. Try again.';
    });
    _shakeCtrl.forward(from: 0);
    HapticFeedback.mediumImpact();
  }

  void _showForgotHelp() {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Forgot passcode?', style: TextStyle(fontSize: 15)),
        content: const Text(
          'The passcode is stored only on this phone and cannot be '
          'recovered. Sign out and sign back in to reset it — your data '
          'on the server is not affected.',
          style: TextStyle(fontSize: 12.5, height: 1.5),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Cancel')),
          TextButton(
            onPressed: () async {
              Navigator.of(ctx).pop();
              try {
                await SessionService.signOut();
              } catch (_) {
                // Tokens + member data are already erased locally; the
                // app gate will flip to the login screen either way.
              }
            },
            child: const Text('Sign out & reset'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.lock_outline_rounded,
                    size: 44, color: AppTheme.primary),
                const SizedBox(height: 14),
                const Text('Felege Kidusan is locked',
                    style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text('Enter your passcode to continue',
                    style:
                        TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                const SizedBox(height: 26),
                AnimatedBuilder(
                  animation: _shakeAnim,
                  builder: (context, child) {
                    final offset = sin(_shakeAnim.value * pi * 4) * 8;
                    return Transform.translate(
                        offset: Offset(offset, 0), child: child);
                  },
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: List.generate(
                        max(4, _entered.length),
                        (i) => Container(
                              width: 14,
                              height: 14,
                              margin: const EdgeInsets.symmetric(horizontal: 6),
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: i < _entered.length
                                    ? AppTheme.primary
                                    : Colors.transparent,
                                border: Border.all(
                                    color: AppTheme.primary, width: 1.6),
                              ),
                            )),
                  ),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  height: 18,
                  child: _error != null
                      ? Text(_error!,
                          style: const TextStyle(
                              fontSize: 11.5, color: Colors.red))
                      : null,
                ),
                const SizedBox(height: 6),
                _keypad(),
                const SizedBox(height: 10),
                if (_biometricAvailable)
                  TextButton.icon(
                    onPressed: _checking ? null : _tryBiometric,
                    icon: const Icon(Icons.fingerprint, size: 18),
                    label: const Text('Use fingerprint',
                        style: TextStyle(fontSize: 12.5)),
                  ),
                TextButton(
                  onPressed: _showForgotHelp,
                  child: Text('Forgot passcode?',
                      style: TextStyle(
                          fontSize: 11.5, color: AppTheme.textSecondary)),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _keypad() {
    final rows = [
      ['1', '2', '3'],
      ['4', '5', '6'],
      ['7', '8', '9'],
      ['', '0', 'back'],
    ];
    return Column(
      children: [
        for (final row in rows)
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (final key in row)
                key.isEmpty
                    ? const SizedBox(width: 76, height: 62)
                    : _key(key),
            ],
          ),
      ],
    );
  }

  Widget _key(String key) {
    return Padding(
      padding: const EdgeInsets.all(6),
      child: Material(
        color: AppTheme.primary.withOpacity(0.06),
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: () => _press(key),
          child: SizedBox(
            width: 64,
            height: 50,
            child: Center(
              child: key == 'back'
                  ? Icon(Icons.backspace_outlined,
                      size: 18, color: AppTheme.primary)
                  : Text(key,
                      style: TextStyle(
                          fontSize: 19,
                          fontWeight: FontWeight.w600,
                          color: AppTheme.primary)),
            ),
          ),
        ),
      ),
    );
  }
}
