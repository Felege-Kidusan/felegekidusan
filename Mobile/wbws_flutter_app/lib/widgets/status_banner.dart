import 'package:flutter/material.dart';
import '../utils/theme.dart';

enum StatusKind { success, error, warning, info }

/// Slim in-page banner for success / error / warning / info.
class StatusBanner extends StatelessWidget {
  final String message;
  final StatusKind kind;
  final VoidCallback? onRetry;
  final VoidCallback? onDismiss;

  const StatusBanner({
    super.key,
    required this.message,
    this.kind = StatusKind.info,
    this.onRetry,
    this.onDismiss,
  });

  factory StatusBanner.success(String message, {VoidCallback? onDismiss}) =>
      StatusBanner(message: message, kind: StatusKind.success, onDismiss: onDismiss);

  factory StatusBanner.error(String message, {VoidCallback? onRetry}) =>
      StatusBanner(message: message, kind: StatusKind.error, onRetry: onRetry);

  factory StatusBanner.warning(String message) =>
      StatusBanner(message: message, kind: StatusKind.warning);

  factory StatusBanner.info(String message) =>
      StatusBanner(message: message, kind: StatusKind.info);

  @override
  Widget build(BuildContext context) {
    final Color color;
    final IconData icon;
    switch (kind) {
      case StatusKind.success:
        color = AppTheme.success;
        icon = Icons.check_circle_rounded;
        break;
      case StatusKind.error:
        color = AppTheme.danger;
        icon = Icons.error_rounded;
        break;
      case StatusKind.warning:
        color = AppTheme.warning;
        icon = Icons.info_outline_rounded;
        break;
      case StatusKind.info:
        color = AppTheme.info;
        icon = Icons.info_outline_rounded;
        break;
    }

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w500, height: 1.35),
            ),
          ),
          if (onRetry != null)
            TextButton(
              onPressed: onRetry,
              style: TextButton.styleFrom(
                visualDensity: VisualDensity.compact,
                padding: const EdgeInsets.symmetric(horizontal: 8),
                minimumSize: const Size(0, 28),
              ),
              child: const Text('Retry', style: TextStyle(fontSize: 12)),
            ),
          if (onDismiss != null)
            InkWell(
              onTap: onDismiss,
              child: Icon(Icons.close, size: 16, color: color),
            ),
        ],
      ),
    );
  }
}
