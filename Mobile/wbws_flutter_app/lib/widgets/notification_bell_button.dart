import 'package:flutter/material.dart';

import '../services/notification_service.dart';
import '../screens/notifications/notification_center_screen.dart';
import '../utils/theme.dart';

/// P72 — the notification bell for every role home's app bar.
///
/// Shows the shared unread badge (NotificationService) and opens the
/// Notification Center. Same pattern on every screen: one widget,
/// one badge source, one center.
class NotificationBellButton extends StatelessWidget {
  const NotificationBellButton({super.key, this.color});

  /// Icon color — pass `Colors.white` on colored app bars.
  final Color? color;

  @override
  Widget build(BuildContext context) {
    // Ensure polling is running wherever a bell is visible.
    NotificationService.instance.start();
    return ValueListenableBuilder<int>(
      valueListenable: NotificationService.instance.badge,
      builder: (context, total, _) {
        return IconButton(
          icon: _BellIcon(total: total, color: color),
          tooltip: 'Notifications',
          onPressed: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => const NotificationCenterScreen())),
        );
      },
    );
  }
}

class _BellIcon extends StatelessWidget {
  const _BellIcon({required this.total, this.color});

  final int total;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Icon(Icons.notifications_none_rounded, size: 23, color: color),
        if (total > 0)
          Positioned(
            top: -4,
            right: -6,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
              constraints: const BoxConstraints(minWidth: 16),
              decoration: BoxDecoration(
                color: AppTheme.danger,
                borderRadius: BorderRadius.circular(9),
                border: Border.all(
                    color: Theme.of(context).scaffoldBackgroundColor,
                    width: 1.5),
              ),
              alignment: Alignment.center,
              child: Text(
                total > 99 ? '99+' : '$total',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  height: 1.2,
                ),
              ),
            ),
          ),
      ],
    );
  }
}
