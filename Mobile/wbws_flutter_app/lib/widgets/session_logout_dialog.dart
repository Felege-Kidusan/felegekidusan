import 'package:flutter/material.dart';

import '../services/session_models.dart';
import '../services/session_service.dart';

/// One logout policy surface for every screen. It uses the coordinator's
/// SQLite snapshot, including communication sends/drafts and paused/attention
/// work, instead of each caller maintaining a partial counter.
Future<void> showSessionLogoutDialog(BuildContext context) async {
  final session = SessionCoordinator();
  final inventory = await session.refreshInventory();
  if (!context.mounted) return;

  LogoutChoice? choice;
  if (inventory.hasPrivateDurableWork) {
    choice = await showDialog<LogoutChoice>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Text('Keep your offline work?'),
        content: Text(
          'This phone has ${inventory.workSummary}.\n\n'
          'Keep work signs out and allows only the same account to recover it. '
          'Discard permanently removes that work, private caches, saved '
          'credentials, and the app passcode.'
          '${_sharedHymnNote(inventory)}',
        ),
        actions: [
          TextButton(
            onPressed: () =>
                Navigator.pop(context, LogoutChoice.cancel),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(
              context,
              LogoutChoice.preserveForReauthentication,
            ),
            child: const Text('Keep work & sign out'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.pop(
              context,
              LogoutChoice.discardPrivateData,
            ),
            child: const Text('Discard permanently'),
          ),
        ],
      ),
    );
  } else {
    choice = await showDialog<LogoutChoice>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text(
          'Private read caches, saved credentials, and the app passcode will '
          'be removed from this phone.${_sharedHymnNote(inventory)}',
        ),
        actions: [
          TextButton(
            onPressed: () =>
                Navigator.pop(context, LogoutChoice.cancel),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(
              context,
              LogoutChoice.discardPrivateData,
            ),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );
  }
  await session.applyLogoutChoice(choice ?? LogoutChoice.cancel);
}

String _sharedHymnNote(LocalDataInventory inventory) {
  if (inventory.sharedHymnOperations <= 0) return '';
  return '\n\n${inventory.sharedHymnOperations} shared hymn operation(s) are '
      'not private-account work and will be kept.';
}
