import 'dart:async';
import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// Error codes — professional debugging without exposing internals
class AppError {
  final String code;
  final String title;
  final String detail;
  final IconData icon;
  final Color color;
  final bool canRetry;

  const AppError({
    required this.code,
    required this.title,
    required this.detail,
    this.icon = Icons.error_outline_rounded,
    this.color = AppTheme.danger,
    this.canRetry = true,
  });

  // ── Error factory methods ──
  static AppError network([String? msg]) => AppError(
    code: 'E-NET',
    title: 'Waiting for network',
    detail: msg ?? 'Turn on mobile data or Wi‑Fi, then try again',
    icon: Icons.cloud_off_rounded,
    color: AppTheme.warning,
  );

  static AppError server([String? msg]) => AppError(
    code: 'E-SRV',
    title: 'Server Error',
    detail: msg ?? 'Something went wrong on our end',
    icon: Icons.dns_rounded,
    color: AppTheme.danger,
  );

  static AppError timeout([String? msg]) => AppError(
    code: 'E-TMO',
    title: 'Request Timeout',
    detail: msg ?? 'Server is taking too long. Try again',
    icon: Icons.timer_off_rounded,
    color: AppTheme.warning,
  );

  static AppError auth([String? msg]) => AppError(
    code: 'E-AUTH',
    title: 'Session Expired',
    detail: msg ?? 'Please login again',
    icon: Icons.lock_outline_rounded,
    color: AppTheme.danger,
    canRetry: false,
  );

  static AppError empty(String what) => AppError(
    code: 'E-NDF',
    title: 'No $what Found',
    detail: 'There\'s nothing here yet',
    icon: Icons.inbox_rounded,
    color: AppTheme.info,
    canRetry: false,
  );

  static AppError parse([String? msg]) => AppError(
    code: 'E-PRS',
    title: 'Data Error',
    detail: msg ?? 'Failed to read server response',
    icon: Icons.code_off_rounded,
    color: AppTheme.danger,
  );

  /// Auto-detect error type from API response message
  static AppError fromMessage(String? msg, {bool isNetwork = false}) {
    final m = (msg ?? '').toLowerCase();
    if (isNetwork || m.contains('socket') || m.contains('no internet')) {
      return network(msg);
    }
    if (m.contains('timeout') || m.contains('taking too long')) {
      return timeout(msg);
    }
    if (m.contains('401') || m.contains('session') || m.contains('expired') || m.contains('unauthorized')) {
      return auth(msg);
    }
    if (m.contains('parse') || m.contains('format')) {
      return parse(msg);
    }
    return server(msg);
  }
}

/// Professional error card with auto-retry countdown
class ErrorCard extends StatefulWidget {
  final String message;
  final VoidCallback? onRetry;
  final bool autoRetry;
  final int autoRetrySeconds;

  const ErrorCard({
    super.key,
    required this.message,
    this.onRetry,
    this.autoRetry = true,
    this.autoRetrySeconds = 10,
  });

  /// Create from AppError
  factory ErrorCard.fromError(AppError error, {VoidCallback? onRetry}) {
    return ErrorCard(
      message: error.detail,
      onRetry: error.canRetry ? onRetry : null,
      autoRetry: error.canRetry,
    );
  }

  @override
  State<ErrorCard> createState() => _ErrorCardState();
}

class _ErrorCardState extends State<ErrorCard> {
  Timer? _retryTimer;
  int _countdown = 0;

  @override
  void initState() {
    super.initState();
    if (widget.autoRetry && widget.onRetry != null) {
      _startCountdown();
    }
  }

  void _startCountdown() {
    _countdown = widget.autoRetrySeconds;
    _retryTimer?.cancel();
    _retryTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) { t.cancel(); return; }
      setState(() => _countdown--);
      if (_countdown <= 0) {
        t.cancel();
        widget.onRetry?.call();
      }
    });
  }

  @override
  void dispose() {
    _retryTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final error = AppError.fromMessage(widget.message);

    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Icon
            Container(
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                color: error.color.withOpacity(0.1),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Icon(error.icon, size: 28, color: error.color),
            ),
            const SizedBox(height: 14),

            // Title
            Text(
              error.title,
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w700,
                color: error.color,
              ),
            ),
            const SizedBox(height: 6),

            // Detail
            Text(
              error.detail,
              style: TextStyle(fontSize: 13, color: AppTheme.textSecondary),
              textAlign: TextAlign.center,
            ),

            // Error code (subtle, for debugging)
            const SizedBox(height: 8),
            Text(
              error.code,
              style: TextStyle(
                fontSize: 10,
                color: Colors.grey.shade700,
                fontFamily: 'monospace',
              ),
            ),

            // Retry button with countdown
            if (widget.onRetry != null) ...[
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: () {
                    _retryTimer?.cancel();
                    widget.onRetry!();
                  },
                  icon: const Icon(Icons.refresh_rounded, size: 18),
                  label: Text(
                    _countdown > 0
                        ? 'Retry (auto in ${_countdown}s)'
                        : 'Retry',
                  ),
                  style: ElevatedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

