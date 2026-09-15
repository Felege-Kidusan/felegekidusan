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
        // A6: the tooltip doubles as the accessibility label, so the
        // unread count reaches TalkBack/VoiceOver instead of a bare
        // 'Notifications'.
        return IconButton(
          icon: _BellIcon(total: total, color: color),
          tooltip: total > 0 ? 'Notifications, $total unread' : 'Notifications',
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
              // A6: 10.5px on an 18px minimum pill (was 9px/16px).
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
              constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
              decoration: BoxDecoration(
                color: AppTheme.danger,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                    color: Theme.of(context).scaffoldBackgroundColor,
                    width: 1.5),
              ),
              alignment: Alignment.center,
              child: Text(
                total > 99 ? '99+' : '$total',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 10.5,
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
