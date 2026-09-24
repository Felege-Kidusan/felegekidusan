import 'package:flutter/material.dart';

import '../../services/session_models.dart';
import '../../services/session_service.dart';
import '../../utils/theme.dart';

class SessionRecoveryScreen extends StatefulWidget {
  const SessionRecoveryScreen({super.key});

  @override
  State<SessionRecoveryScreen> createState() => _SessionRecoveryScreenState();
}

class _SessionRecoveryScreenState extends State<SessionRecoveryScreen> {
  final _username = TextEditingController();
  final _password = TextEditingController();
  bool _submitting = false;
  bool _obscure = true;
  String? _message;

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _reauthenticate() async {
    if (_username.text.trim().isEmpty || _password.text.isEmpty) return;
    setState(() {
      _submitting = true;
      _message = null;
    });
    final result = await SessionCoordinator().login(
      _username.text.trim(),
      _password.text,
    );
    if (!mounted) return;
    setState(() {
      _submitting = false;
      _message = result.activated ? null : result.message;
    });
  }

  Future<void> _discard() async {
    final session = SessionCoordinator();
    final inventory = await session.refreshInventory();
    if (!mounted) return;
    final confirmed = await showDialog<bool>(
          context: context,
          barrierDismissible: false,
          builder: (context) => AlertDialog(
            title: const Text('Permanently discard local private data?'),
            content: Text(
              _destructiveWarning(inventory),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                style: FilledButton.styleFrom(backgroundColor: Colors.red),
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Discard permanently'),
              ),
            ],
          ),
        ) ??
        false;
    if (confirmed) {
      await session.destructiveSignOut(reason: 'reauth_recovery_discard');
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionCoordinator();
    final owner = session.record.ownerDisplayName ??
        session.record.ownerUsername ??
        (session.record.ownerUserId == null
            ? 'the previous account'
            : 'account ${session.record.ownerUserId}');
    final inventory = session.inventory;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(28),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(Icons.cloud_off_rounded,
                      size: 60, color: AppTheme.primary),
                  const SizedBox(height: 18),
                  const Text(
                    'Sign in again to recover your work',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'The session for $owner ended, but private work and your '
                    'app passcode are still on this phone. Only the same '
                    'account can resume it.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 14),
                  _InventoryCard(inventory: inventory),
                  const SizedBox(height: 22),
                  TextField(
                    controller: _username,
                    enabled: !_submitting,
                    textInputAction: TextInputAction.next,
                    decoration: const InputDecoration(
                      labelText: 'Username',
                      prefixIcon: Icon(Icons.person_outline),
                    ),
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _password,
                    enabled: !_submitting,
                    obscureText: _obscure,
                    onSubmitted: (_) => _reauthenticate(),
                    decoration: InputDecoration(
                      labelText: 'Password',
                      prefixIcon: const Icon(Icons.lock_outline),
                      suffixIcon: IconButton(
                        onPressed: () => setState(() => _obscure = !_obscure),
                        icon: Icon(
                          _obscure ? Icons.visibility : Icons.visibility_off,
                        ),
                      ),
                    ),
                  ),
                  if (_message != null) ...[
                    const SizedBox(height: 12),
                    Text(_message!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Colors.red)),
                  ],
                  const SizedBox(height: 18),
                  FilledButton(
                    onPressed: _submitting ? null : _reauthenticate,
                    child: Text(_submitting ? 'Checking…' : 'Recover work'),
                  ),
                  const SizedBox(height: 10),
                  TextButton(
                    onPressed: _submitting ? null : _discard,
                    child: const Text('Discard private data and use another account'),
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

class OrphanedDataRecoveryScreen extends StatelessWidget {
  const OrphanedDataRecoveryScreen({super.key});

  Future<void> _discard(BuildContext context) async {
    final session = SessionCoordinator();
    final inventory = await session.refreshInventory();
    if (!context.mounted) return;
    final confirmed = await showDialog<bool>(
          context: context,
          barrierDismissible: false,
          builder: (context) => AlertDialog(
            title: const Text('Discard unowned private data?'),
            content: Text(_destructiveWarning(inventory)),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                style: FilledButton.styleFrom(backgroundColor: Colors.red),
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Discard permanently'),
              ),
            ],
          ),
        ) ??
        false;
    if (confirmed) await session.discardOrphanedData();
  }

  @override
  Widget build(BuildContext context) {
    final inventory = SessionCoordinator().inventory;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(30),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.shield_outlined,
                      size: 62, color: Colors.orange),
                  const SizedBox(height: 18),
                  const Text(
                    'Private offline data needs recovery',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'The app cannot prove which account owns this private '
                    'offline data. No login will be attached to it. Ask the '
                    'school administrator for help, or explicitly discard it.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  _InventoryCard(inventory: inventory),
                  const SizedBox(height: 22),
                  OutlinedButton(
                    onPressed: () => _discard(context),
                    child: const Text('Discard private data permanently'),
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

class ScopeReconciliationScreen extends StatelessWidget {
  const ScopeReconciliationScreen({super.key});

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(),
              SizedBox(height: 18),
              Text('Updating access and rebuilding your workspace…'),
            ],
          ),
        ),
      );
}

class PurgingSessionScreen extends StatelessWidget {
  const PurgingSessionScreen({super.key});

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(),
              SizedBox(height: 18),
              Text('Finishing secure local cleanup…'),
            ],
          ),
        ),
      );
}

class SessionProtectionFailureScreen extends StatefulWidget {
  const SessionProtectionFailureScreen({super.key});

  @override
  State<SessionProtectionFailureScreen> createState() =>
      _SessionProtectionFailureScreenState();
}

class _SessionProtectionFailureScreenState
    extends State<SessionProtectionFailureScreen> {
  bool _retrying = false;

  Future<void> _retry() async {
    setState(() => _retrying = true);
    await SessionCoordinator().bootstrap();
    if (mounted) setState(() => _retrying = false);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(30),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.phonelink_lock_rounded,
                        size: 62, color: Colors.red),
                    const SizedBox(height: 18),
                    const Text(
                      'Protected session storage is unavailable',
                      textAlign: TextAlign.center,
                      style:
                          TextStyle(fontSize: 21, fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'The app did not treat this as a logout and did not '
                      'attach local work to another account. Unlock the phone '
                      'or restore protected storage, then retry.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 20),
                    FilledButton.icon(
                      onPressed: _retrying ? null : _retry,
                      icon: const Icon(Icons.refresh),
                      label: Text(_retrying ? 'Retrying…' : 'Retry'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
}

class _InventoryCard extends StatelessWidget {
  const _InventoryCard({required this.inventory});

  final LocalDataInventory inventory;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.orange.withValues(alpha: .08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: Colors.orange.withValues(alpha: .35)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Private durable work: ${inventory.workSummary}'),
            if (inventory.attentionOperations > 0)
              Text('${inventory.attentionOperations} item(s) need attention'),
            if (inventory.pausedOperations > 0)
              Text('${inventory.pausedOperations} item(s) paused for sign-in'),
            if (inventory.sharedHymnOperations > 0) ...[
              const SizedBox(height: 6),
              Text(
                '${inventory.sharedHymnOperations} shared hymn operation(s) '
                'are separate and will be kept.',
              ),
            ],
          ],
        ),
      );
}

String _destructiveWarning(LocalDataInventory inventory) {
  final shared = inventory.sharedHymnOperations > 0
      ? '\n\n${inventory.sharedHymnOperations} shared hymn operation(s) are '
          'separate and will be kept.'
      : '';
  return 'This permanently removes private local caches, ${inventory.workSummary}, '
      'the local app passcode, and saved credentials. This '
      'cannot be undone.$shared';
}
